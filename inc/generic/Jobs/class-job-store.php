<?php
/**
 * Transient-backed job state for the generic background importer.
 *
 * Ported from `blocksify-design-importer/includes/Jobs/JobStore.php` — same
 * shape (per-job transient + a `_latest` pointer in wp_options + an
 * append-only set of imported template IDs) with the prefix rebranded
 * to `ft_demo_importer_*` so this plugin's state doesn't collide with
 * blocksify-design-importer's when both are active on the same install.
 *
 * Lifecycle:
 *   queued → fetching → installing_plugins → extracting
 *          → importing_content → applying_options → completed
 *                                                ↘ failed / cancelled
 *
 * TTL is 12 hours — long enough for a stuck job to be inspected, short
 * enough that an abandoned install eventually self-cleans.
 */

namespace FT_Demo_Importer\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Job_Store {

	public const STATUS_QUEUED             = 'queued';
	public const STATUS_FETCHING           = 'fetching';
	public const STATUS_INSTALLING_PLUGINS = 'installing_plugins';
	public const STATUS_EXTRACTING         = 'extracting';
	public const STATUS_IMPORTING_CONTENT  = 'importing_content';
	public const STATUS_APPLYING_OPTIONS   = 'applying_options';
	public const STATUS_COMPLETED          = 'completed';
	public const STATUS_FAILED             = 'failed';
	public const STATUS_CANCELLED          = 'cancelled';

	public const TRANSIENT_PREFIX = 'ft_demo_importer_job_';
	public const OPTION_LATEST    = 'ft_demo_importer_job_latest';
	/** Append-only list of template IDs that have ever completed an import.
	 *  Library UI reads this to render the "✓ Imported" badge. */
	public const OPTION_IMPORTED_TEMPLATES = 'ft_demo_importer_imported_templates';
	public const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * @param array<string,mixed> $config
	 */
	public function create( array $config ): string {
		$id  = $this->generate_id();
		$now = current_time( 'mysql', true );
		$job = [
			'id'        => $id,
			'status'    => self::STATUS_QUEUED,
			'config'    => $config,
			'progress'  => [
				'phase'   => self::STATUS_QUEUED,
				'percent' => 0,
				'current' => 0,
				'total'   => 0,
				'message' => __( 'Queued', 'famethemes-demo-importer' ),
			],
			'log'       => [],
			'warnings'  => [],
			'error'     => null,
			'summary'   => null,
			'created_at'  => $now,
			'updated_at'  => $now,
			'started_at'  => null,
			'finished_at' => null,
			'cancel_requested' => false,
		];
		set_transient( self::TRANSIENT_PREFIX . $id, $job, self::TTL );
		update_option( self::OPTION_LATEST, $id, false );
		return $id;
	}

	public function get( string $id ): ?array {
		$job = get_transient( self::TRANSIENT_PREFIX . $id );
		return is_array( $job ) ? $job : null;
	}

	public function latest(): ?array {
		$id = (string) get_option( self::OPTION_LATEST, '' );
		return '' === $id ? null : $this->get( $id );
	}

	public function update( string $id, callable $mutator ): ?array {
		$job = $this->get( $id );
		if ( null === $job ) {
			return null;
		}
		$job               = $mutator( $job );
		$job['updated_at'] = current_time( 'mysql', true );
		set_transient( self::TRANSIENT_PREFIX . $id, $job, self::TTL );
		return $job;
	}

	public function set_status( string $id, string $status, string $message = '' ): void {
		$this->update( $id, static function ( array $job ) use ( $status, $message ): array {
			$job['status']            = $status;
			$job['progress']['phase'] = $status;
			if ( '' !== $message ) {
				$job['progress']['message'] = $message;
			}
			if ( null === $job['started_at'] && self::STATUS_QUEUED !== $status ) {
				$job['started_at'] = current_time( 'mysql', true );
			}
			if ( in_array( $status, [ self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ], true ) ) {
				$job['finished_at'] = current_time( 'mysql', true );
			}
			return $job;
		} );
	}

	public function set_progress( string $id, int $percent, int $current = 0, int $total = 0, string $message = '' ): void {
		$this->update( $id, static function ( array $job ) use ( $percent, $current, $total, $message ): array {
			$job['progress']['percent'] = max( 0, min( 100, $percent ) );
			if ( $total > 0 ) {
				$job['progress']['current'] = $current;
				$job['progress']['total']   = $total;
			}
			if ( '' !== $message ) {
				$job['progress']['message'] = $message;
			}
			return $job;
		} );
	}

	public function log( string $id, string $message ): void {
		$this->update( $id, static function ( array $job ) use ( $message ): array {
			$job['log'][] = [ 'at' => current_time( 'mysql', true ), 'message' => $message ];
			return $job;
		} );
	}

	public function warn( string $id, string $message ): void {
		$this->update( $id, static function ( array $job ) use ( $message ): array {
			$job['warnings'][] = $message;
			return $job;
		} );
	}

	public function fail( string $id, string $error ): void {
		$this->update( $id, static function ( array $job ) use ( $error ): array {
			$job['status']      = self::STATUS_FAILED;
			$job['error']       = $error;
			$job['finished_at'] = current_time( 'mysql', true );
			return $job;
		} );
	}

	public function complete( string $id, array $summary ): void {
		$this->update( $id, static function ( array $job ) use ( $summary ): array {
			$job['status']              = self::STATUS_COMPLETED;
			$job['summary']             = $summary;
			$job['progress']['phase']   = self::STATUS_COMPLETED;
			$job['progress']['percent'] = 100;
			$job['progress']['message'] = __( 'Import complete.', 'famethemes-demo-importer' );
			$job['finished_at']         = current_time( 'mysql', true );
			return $job;
		} );

		$template_id = (int) ( $summary['template_id'] ?? 0 );
		if ( $template_id > 0 ) {
			$set = (array) get_option( self::OPTION_IMPORTED_TEMPLATES, [] );
			if ( ! in_array( $template_id, $set, true ) ) {
				$set[] = $template_id;
				update_option( self::OPTION_IMPORTED_TEMPLATES, array_values( $set ), true );
			}
		}
	}

	/** @return int[] */
	public static function imported_template_ids(): array {
		$set = (array) get_option( self::OPTION_IMPORTED_TEMPLATES, [] );
		return array_values( array_filter( array_map( 'intval', $set ) ) );
	}

	public function request_cancel( string $id ): void {
		$this->update( $id, static function ( array $job ): array {
			$job['cancel_requested'] = true;
			return $job;
		} );
	}

	public function is_cancel_requested( string $id ): bool {
		$job = $this->get( $id );
		return is_array( $job ) && ! empty( $job['cancel_requested'] );
	}

	/**
	 * Wipe leftover state from a previous import — wired into create_job
	 * so each new run starts clean. Two things happen:
	 *   1. Every pending `ft_demo_importer_run_job` cron event is
	 *      unscheduled (on dev sites where spawn_cron loopback fails
	 *      silently, the old job would otherwise sit `queued` forever).
	 *   2. The latest job, if non-terminal, is force-cancelled. If a
	 *      runner is mid-flight on it, the `cancel_requested` flag
	 *      makes it bail at the next safe boundary.
	 *
	 * @return string[] IDs of discarded jobs.
	 */
	public function discard_pending(): array {
		$discarded = [];

		$crons = _get_cron_array();
		if ( is_array( $crons ) ) {
			$hook = Importer_Runner::CRON_HOOK;
			foreach ( $crons as $timestamp => $hooks ) {
				if ( ! is_array( $hooks ) || ! isset( $hooks[ $hook ] ) ) {
					continue;
				}
				foreach ( $hooks[ $hook ] as $event ) {
					$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : [];
					wp_unschedule_event( $timestamp, $hook, $args );
				}
			}
		}

		$job = $this->latest();
		if ( null !== $job ) {
			$terminal = [ self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ];
			if ( ! in_array( $job['status'], $terminal, true ) ) {
				$this->update( $job['id'], static function ( array $j ): array {
					$j['status']              = self::STATUS_CANCELLED;
					$j['progress']['phase']   = self::STATUS_CANCELLED;
					$j['progress']['message'] = __( 'Cancelled — superseded by a new import.', 'famethemes-demo-importer' );
					$j['cancel_requested']    = true;
					$j['finished_at']         = current_time( 'mysql', true );
					return $j;
				} );
				$discarded[] = (string) $job['id'];
			}
		}

		return $discarded;
	}

	private function generate_id(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			return substr( md5( uniqid( '', true ) ), 0, 16 );
		}
	}
}
