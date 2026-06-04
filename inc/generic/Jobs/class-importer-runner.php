<?php
/**
 * Cron-spawned orchestrator that walks a job through the 5-phase
 * pipeline. Ported from `blocksify-design-importer/includes/Jobs/ImporterRunner.php`.
 *
 *   queued → fetching → installing_plugins → extracting
 *          → importing_content → applying_options → completed
 *
 * Phase ordering matters:
 *   - Plugins install BEFORE content so plugin-registered CPTs exist
 *     when Content_Importer walks posts (it skips unknown post_types
 *     with a warning otherwise).
 *   - Uploads extract before content so attachments resolve to local
 *     files instead of remote URLs.
 *   - Options apply LAST so any settings the user has already tweaked
 *     don't get clobbered until everything else is in place.
 *
 * Each step checks {@see Job_Store::is_cancel_requested} before doing
 * work — a mid-flight cancel from the UI stops the runner at the next
 * safe boundary instead of mid-step.
 *
 * The 5 step classes (Asset_Fetcher, Plugin_Installer, Uploads_Extractor,
 * Content_Importer, Options_Importer) are stubs at B4 — they exist with
 * the expected signatures and return well-shaped empty results so this
 * runner can be smoke-tested end-to-end. B5 fills in the real logic.
 */

namespace FT_Demo_Importer\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FT_Demo_Importer\Studio\Remote_Client;
use FT_Demo_Importer\Steps\Asset_Fetcher;
use FT_Demo_Importer\Steps\Content_Importer;
use FT_Demo_Importer\Steps\Options_Importer;
use FT_Demo_Importer\Steps\Plugin_Installer;
use FT_Demo_Importer\Steps\Uploads_Extractor;

class Importer_Runner {

	public const CRON_HOOK = 'ft_demo_importer_run_job';

	private Remote_Client $client;
	private Job_Store $jobs;

	public function __construct( Remote_Client $client, Job_Store $jobs ) {
		$this->client = $client;
		$this->jobs   = $jobs;
	}

	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run' ], 10, 1 );
	}

	public function enqueue( string $job_id ): void {
		// Schedule one tick in the past so spawn_cron() picks it up on
		// the loopback hit fired next — without the `-1` the job is
		// "not due yet" and the loopback request returns without doing
		// anything.
		wp_schedule_single_event( time() - 1, self::CRON_HOOK, [ $job_id ] );

		// Fire the loopback so the worker runs immediately, not whenever
		// the next natural cron tick happens (60s+, sometimes never on
		// dev sites with WP_CRON disabled).
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		// Last-resort fallback for sites with DISABLE_WP_CRON — runs the
		// import on this request thread. Blocking, but better than
		// "job sits queued until someone manually triggers cron".
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$this->run( $job_id );
		}
	}

	public function run( string $job_id ): void {
		$job = $this->jobs->get( $job_id );
		if ( null === $job ) {
			return;
		}
		$config = (array) ( $job['config'] ?? [] );

		try {
			$fetcher   = new Asset_Fetcher( $this->client );
			$installer = new Plugin_Installer();
			$extractor = new Uploads_Extractor();
			$content   = new Content_Importer();
			$options   = new Options_Importer();
			$adapter   = apply_filters( 'ft_demo_importer_active_adapter', null );

			$summary = [
				'template_id' => 0,
				'plugins'     => [ 'installed' => [], 'activated' => [] ],
			];

			// (1) Fetch. 0 → 10 %
			if ( $this->cancelled( $job_id ) ) { return; }
			$this->jobs->set_status( $job_id, Job_Store::STATUS_FETCHING, __( 'Downloading template assets…', 'famethemes-demo-importer' ) );
			$this->jobs->set_progress( $job_id, 1 );
			$template_id = (int) ( $config['template_id'] ?? 0 );
			$summary['template_id'] = $template_id;
			$paths       = $fetcher->download( $template_id, $job_id );
			$this->jobs->set_progress( $job_id, 10 );
			$this->jobs->log( $job_id, 'Assets downloaded.' );
			if ( $adapter ) { $adapter->after_phase( Job_Store::STATUS_FETCHING, (array) $this->jobs->get( $job_id ), $this ); }

			// (2) Install + activate required plugins. 10 → 30 %
			if ( $this->cancelled( $job_id ) ) { $fetcher->cleanup( $job_id ); return; }
			$this->jobs->set_status( $job_id, Job_Store::STATUS_INSTALLING_PLUGINS, __( 'Installing required plugins…', 'famethemes-demo-importer' ) );
			$plugins_skip = (array) ( $config['plugins_skip'] ?? [] );
			// Adapter-declared plugins layered onto whatever options.json
			// requirements specify — Plugin_Installer dedupes by slug.
			$adapter_plugins = $adapter ? $adapter->required_plugins() : [];
			$plugin_result = $installer->install_and_activate(
				$paths['options'] ?? '',
				$plugins_skip,
				$adapter_plugins
			);
			foreach ( $plugin_result['warnings'] as $w ) {
				$this->jobs->warn( $job_id, $w );
			}
			$summary['plugins'] = [
				'installed' => $plugin_result['installed'],
				'activated' => $plugin_result['activated'],
			];
			$this->jobs->log( $job_id, sprintf(
				'Plugins: %d installed, %d activated.',
				count( $plugin_result['installed'] ),
				count( $plugin_result['activated'] )
			) );
			$this->jobs->set_progress( $job_id, 30 );
			if ( $adapter ) { $adapter->after_phase( Job_Store::STATUS_INSTALLING_PLUGINS, (array) $this->jobs->get( $job_id ), $this ); }

			// (3) Extract uploads. 30 → 45 %
			//
			// Two ways the step gets skipped without warning:
			//   - User unchecked "import uploads" in the wizard (config flag false).
			//   - Template's manifest didn't ship an `uploads.zip` at all
			//     (Asset_Fetcher returns an empty path for that kind, since
			//     it's optional — many pattern-only templates have no zip).
			if ( $this->cancelled( $job_id ) ) { $fetcher->cleanup( $job_id ); return; }
			$uploads_path = (string) ( $paths['uploads'] ?? '' );
			if ( ! empty( $config['import_uploads'] ) && '' !== $uploads_path ) {
				$this->jobs->set_status( $job_id, Job_Store::STATUS_EXTRACTING, __( 'Extracting media files…', 'famethemes-demo-importer' ) );
				$ext_result = $extractor->extract( $uploads_path, ! empty( $config['overwrite_existing'] ) );
				foreach ( $ext_result['warnings'] as $w ) {
					$this->jobs->warn( $job_id, $w );
				}
				$this->jobs->log( $job_id, sprintf(
					'Uploads extracted: %d files (%d skipped).',
					$ext_result['written'],
					$ext_result['skipped']
				) );
			} elseif ( empty( $config['import_uploads'] ) ) {
				$this->jobs->log( $job_id, 'Uploads step skipped by request.' );
			} else {
				$this->jobs->log( $job_id, 'Uploads step skipped — template has no uploads.zip.' );
			}
			$this->jobs->set_progress( $job_id, 45 );
			if ( $adapter ) { $adapter->after_phase( Job_Store::STATUS_EXTRACTING, (array) $this->jobs->get( $job_id ), $this ); }

			// (4) Content. 45 → 90 %
			if ( $this->cancelled( $job_id ) ) { $fetcher->cleanup( $job_id ); return; }
			$ref_map = [];
			if ( ! empty( $config['import_content'] ) ) {
				$this->jobs->set_status( $job_id, Job_Store::STATUS_IMPORTING_CONTENT, __( 'Importing posts, terms, menus…', 'famethemes-demo-importer' ) );
				$content_result = $content->import(
					$paths['content'] ?? '',
					[ 'overwrite_existing' => ! empty( $config['overwrite_existing'] ) ]
				);
				$ref_map = $content_result['ref_map'];
				foreach ( $content_result['warnings'] as $w ) {
					$this->jobs->warn( $job_id, $w );
				}
				$this->jobs->log( $job_id, sprintf(
					'Content imported: %d terms, %d posts, %d attachments, %d menus (%d items).',
					$content_result['counts']['terms'],
					$content_result['counts']['posts'],
					$content_result['counts']['attachments'],
					$content_result['counts']['menus'],
					$content_result['counts']['menu_items']
				) );
			}
			$this->jobs->set_progress( $job_id, 90 );
			if ( $adapter ) { $adapter->after_phase( Job_Store::STATUS_IMPORTING_CONTENT, (array) $this->jobs->get( $job_id ), $this ); }

			// (5) Options (gated by replace_settings). 90 → 100 %
			if ( $this->cancelled( $job_id ) ) { $fetcher->cleanup( $job_id ); return; }
			$this->jobs->set_status( $job_id, Job_Store::STATUS_APPLYING_OPTIONS, __( 'Applying settings…', 'famethemes-demo-importer' ) );
			$options_result = $options->apply(
				$paths['options'] ?? '',
				$ref_map,
				[
					'replace_settings'  => ! empty( $config['replace_settings'] ),
					'content_json_path' => $paths['content'] ?? '',
					'uploads_zip_path'  => $paths['uploads'] ?? '',
					// Adapter customizer-key overlay merged on top of
					// options.json's own customizer_keys block.
					'adapter_customizer_keys' => $adapter ? $adapter->customizer_keys() : [],
				]
			);
			foreach ( $options_result['warnings'] as $w ) {
				$this->jobs->warn( $job_id, $w );
			}
			$this->jobs->log( $job_id, 'Options applied: ' . implode(
				', ',
				array_keys( array_filter( $options_result['applied'] ) )
			) );
			if ( $adapter ) { $adapter->after_phase( Job_Store::STATUS_APPLYING_OPTIONS, (array) $this->jobs->get( $job_id ), $this ); }

			$this->jobs->set_progress( $job_id, 100 );

			// (6) Cleanup + complete.
			$fetcher->cleanup( $job_id );
			$this->jobs->complete( $job_id, $summary );
			if ( $adapter ) { $adapter->after_phase( Job_Store::STATUS_COMPLETED, (array) $this->jobs->get( $job_id ), $this ); }

		} catch ( \Throwable $e ) {
			$this->jobs->fail( $job_id, $e->getMessage() );
			( new Asset_Fetcher( $this->client ) )->cleanup( $job_id );
		}
	}

	/**
	 * Public so steps that take a while (e.g. content import looping
	 * over hundreds of posts) can call back and report sub-progress.
	 */
	public function job_store(): Job_Store {
		return $this->jobs;
	}

	private function cancelled( string $job_id ): bool {
		if ( ! $this->jobs->is_cancel_requested( $job_id ) ) {
			return false;
		}
		$this->jobs->set_status( $job_id, Job_Store::STATUS_CANCELLED, __( 'Import cancelled by user.', 'famethemes-demo-importer' ) );
		return true;
	}
}
