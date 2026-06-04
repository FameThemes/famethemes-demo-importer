<?php
/**
 * "Tools → Demo Contents → Settings" sub-tab.
 *
 * Renders a 2-field form (Studio URL + read key) plus a "Test connection"
 * button. Storage is delegated to {@see Options_Store}; transport for the
 * test ping is delegated to {@see Remote_Client} (passed in by Bootstrap
 * so this class stays test-friendly).
 *
 * The page is registered as a hidden submenu so it shares the same parent
 * menu as the main Demo Contents dashboard but doesn't double-list in the
 * sidebar — the dashboard's own tab strip links to it via `?tab=settings`.
 */

namespace FT_Demo_Importer\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Settings_Page {

	public const PAGE_SLUG    = 'famethemes-demo-importer';
	public const TAB_SETTINGS = 'settings';

	private Options_Store $options;
	/** @var \FT_Demo_Importer\Studio\Remote_Client|null Provided after B3 lands. */
	private $client;

	public function __construct( Options_Store $options, $client = null ) {
		$this->options = $options;
		$this->client  = $client;
	}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_ft_demo_importer_test', [ $this, 'handle_test_connection' ] );
	}

	public function register_settings(): void {
		register_setting( 'ft_demo_importer', Options_Store::OPT_STUDIO_URL, [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		] );
		register_setting( 'ft_demo_importer', Options_Store::OPT_STUDIO_KEY, [
			'type'              => 'string',
			'sanitize_callback' => static fn( $v ) => trim( (string) $v ),
			'default'           => '',
		] );
	}

	/**
	 * Render — called from Generic_Dashboard::dashboard() when the active
	 * tab is `settings`. NOT registered as its own admin page; the
	 * dashboard owns the page slug + tab strip.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = get_transient( 'ft_demo_importer_test_result' );
		if ( $result ) {
			delete_transient( 'ft_demo_importer_test_result' );
		}
		?>
		<?php if ( $result ) : ?>
			<div class="notice notice-<?php echo esc_attr( $result['ok'] ? 'success' : 'error' ); ?> is-dismissible">
				<p><?php echo esc_html( $result['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php" class="ft-demo-settings-form">
			<?php settings_fields( 'ft_demo_importer' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="ft_demo_importer_studio_url"><?php esc_html_e( 'Studio server URL', 'famethemes-demo-importer' ); ?></label>
					</th>
					<td>
						<?php $url_locked = $this->options->is_studio_url_locked(); ?>
						<input
							type="url"
							id="ft_demo_importer_studio_url"
							name="<?php echo esc_attr( Options_Store::OPT_STUDIO_URL ); ?>"
							value="<?php echo esc_attr( $this->options->studio_url() ); ?>"
							class="regular-text"
							placeholder="<?php echo esc_attr( Options_Store::DEFAULT_STUDIO_URL ); ?>"
							<?php disabled( $url_locked ); ?>
						/>
						<p class="description">
							<?php if ( $url_locked ) : ?>
								<?php
								printf(
									/* translators: %s: PHP constant name */
									esc_html__( 'Locked by wp-config — the %s constant overrides this field.', 'famethemes-demo-importer' ),
									'<code>' . esc_html( Options_Store::CONST_STUDIO_URL ) . '</code>'
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'Base URL of the Blocksify Design Studio that supplies template demos. Trailing slash optional. Leave empty to use the plugin default.', 'famethemes-demo-importer' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ft_demo_importer_studio_key"><?php esc_html_e( 'Read API key', 'famethemes-demo-importer' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="ft_demo_importer_studio_key"
							name="<?php echo esc_attr( Options_Store::OPT_STUDIO_KEY ); ?>"
							value="<?php echo esc_attr( $this->options->studio_key() ); ?>"
							class="regular-text"
							autocomplete="off"
							placeholder="pmbd_live_…"
						/>
						<p class="description">
							<?php esc_html_e( 'A `read`-scope key generated on the Studio. Stored in wp_options, never sent to the browser after save.', 'famethemes-demo-importer' ); ?>
							<?php if ( $this->options->studio_key() ) : ?>
								<br/><code><?php echo esc_html( $this->options->masked_key() ); ?></code>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr/>

		<h2><?php esc_html_e( 'Test connection', 'famethemes-demo-importer' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Sends a single GET /me request to the Studio with the saved key. Use this to verify the URL is reachable and the key is valid before running an import.', 'famethemes-demo-importer' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ft_demo_importer_test' ); ?>
			<input type="hidden" name="action" value="ft_demo_importer_test" />
			<p>
				<button type="submit" class="button button-secondary"<?php disabled( ! $this->options->has_credentials() ); ?>>
					<?php esc_html_e( 'Test connection to Studio', 'famethemes-demo-importer' ); ?>
				</button>
				<?php if ( ! $this->options->has_credentials() ) : ?>
					<span class="description"><?php esc_html_e( 'Save the URL + key first.', 'famethemes-demo-importer' ); ?></span>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Handle the test-connection POST. Stores result in a 60-second
	 * transient that {@see render()} reads + clears on next display.
	 *
	 * Uses Remote_Client::get('me') when wired (B3+); without it,
	 * returns a placeholder error so the user knows the importer hasn't
	 * been fully bootstrapped yet.
	 */
	public function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'ft_demo_importer_test' );

		if ( null === $this->client ) {
			$msg = __( 'Studio client not initialised — the generic background pipeline is still being wired (phase B3).', 'famethemes-demo-importer' );
			set_transient( 'ft_demo_importer_test_result', [ 'ok' => false, 'message' => $msg ], 60 );
		} else {
			$res = $this->client->get( 'me' );
			if ( ! empty( $res['error'] ) ) {
				$msg = sprintf(
					/* translators: %s: error message */
					__( 'Connection failed: %s', 'famethemes-demo-importer' ),
					$res['error']
				);
				set_transient( 'ft_demo_importer_test_result', [ 'ok' => false, 'message' => $msg ], 60 );
			} else {
				$body  = is_array( $res['body'] ) ? $res['body'] : [];
				$label = (string) ( $body['label'] ?? '?' );
				$scope = (string) ( $body['scope'] ?? '?' );
				$msg   = sprintf(
					/* translators: 1: key label, 2: scope */
					__( 'Connected. Key label: %1$s · scope: %2$s.', 'famethemes-demo-importer' ),
					$label,
					$scope
				);
				set_transient( 'ft_demo_importer_test_result', [ 'ok' => true, 'message' => $msg ], 60 );
			}
		}

		// Generic_Dashboard mounts the page as a top-level admin menu —
		// redirect back via admin.php so the user lands on the same
		// screen they were on before submitting the test form.
		wp_safe_redirect( add_query_arg(
			[ 'page' => self::PAGE_SLUG, 'tab' => self::TAB_SETTINGS ],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
