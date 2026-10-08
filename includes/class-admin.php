<?php
/**
 * The wp-admin surface: settings, connection test, diagnostics, re-binding.
 *
 * NONCES ARE USED HERE, AND THAT IS NOT A CONTRADICTION. The public proxy
 * routes deliberately avoid them (§2: a nonce baked into cached HTML goes stale
 * and every submit 403s, and a logged-out visitor has no session to ride). These
 * screens are the opposite case — an authenticated administrator with a
 * session, where CSRF is a real risk and caching is not a concern.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and actions.
 */
final class Spotship_WI_Admin {

	private const PAGE       = 'spotship-war-insurance';
	private const CAPABILITY = 'manage_options';

	public const ACTION_REBIND = 'spotship_wi_rebind';
	public const ACTION_TEST   = 'spotship_wi_test';

	/**
	 * Hook everything.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_notices', array( self::class, 'binding_notice' ) );
		add_action( 'admin_post_' . self::ACTION_REBIND, array( self::class, 'handle_rebind' ) );
		add_action( 'admin_post_' . self::ACTION_TEST, array( self::class, 'handle_test' ) );
	}

	/**
	 * Add the settings page.
	 */
	public static function menu(): void {
		add_options_page(
			__( 'Spot Ship War Insurance', 'spotship-war-insurance' ),
			__( 'Spot Ship War Insurance', 'spotship-war-insurance' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * The origin-mismatch notice.
	 *
	 * Unmissable and on every admin screen, not just ours: an administrator who
	 * has cloned a site will not think to visit a plugin settings page to find
	 * out why a form stopped working. It names BOTH urls, because "binding
	 * mismatch" without them is not actionable.
	 */
	public static function binding_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Spotship_WI_Settings::binding_mismatch() ) {
			return;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_REBIND ),
			self::ACTION_REBIND
		);
		?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'Spot Ship War Insurance is not accepting submissions.', 'spotship-war-insurance' ); ?></strong></p>
			<p>
				<?php
				printf(
					/* translators: 1: bound site URL, 2: current site URL. */
					esc_html__( 'This installation is bound to %1$s but is running on %2$s. Submissions are blocked so that a copy of this site cannot send quote requests using your API key.', 'spotship-war-insurance' ),
					'<code>' . esc_html( Spotship_WI_Settings::bound_site_url() ) . '</code>',
					'<code>' . esc_html( Spotship_WI_Settings::current_site_url() ) . '</code>'
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $url ); ?>">
					<?php esc_html_e( 'Re-bind to this site', 'spotship-war-insurance' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * One-click re-bind.
	 *
	 * Deliberately does NOT ask for the API key again. An administrator fixing a
	 * URL should not have to go and find a credential, and making them do so is
	 * how a broken form stays broken for a week.
	 */
	public static function handle_rebind(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'spotship-war-insurance' ) );
		}

		check_admin_referer( self::ACTION_REBIND );

		Spotship_WI_Settings::bind_to_current();
		Spotship_WI_Rates_Cache::purge();

		wp_safe_redirect( add_query_arg( 'spotship_wi_rebound', '1', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * "Test connection" — a real request to the rates endpoint.
	 */
	public static function handle_test(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'spotship-war-insurance' ) );
		}

		check_admin_referer( self::ACTION_TEST );

		$result = Spotship_WI_Api_Client::request( 'GET', 'insurance/rates' );

		wp_safe_redirect(
			add_query_arg(
				'spotship_wi_test',
				rawurlencode( $result['ok'] ? 'ok' : $result['code'] ),
				admin_url( 'options-general.php?page=' . self::PAGE )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = Spotship_WI_Settings::all();
		$constant = Spotship_WI_Settings::key_is_constant();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spot Ship War Insurance', 'spotship-war-insurance' ); ?></h1>

			<?php self::flash(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'spotship_wi' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sswi-api-base"><?php esc_html_e( 'API base URL', 'spotship-war-insurance' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="sswi-api-base"
								name="<?php echo esc_attr( Spotship_WI_Settings::OPTION ); ?>[api_base_url]"
								value="<?php echo esc_attr( (string) $settings['api_base_url'] ); ?>" required>
							<p class="description"><?php esc_html_e( 'Must be https.', 'spotship-war-insurance' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="sswi-api-key"><?php esc_html_e( 'API key', 'spotship-war-insurance' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="sswi-api-key" autocomplete="off"
								name="<?php echo esc_attr( Spotship_WI_Settings::OPTION ); ?>[api_key]"
								value="" placeholder="<?php echo esc_attr( $constant ? __( 'Set in wp-config.php', 'spotship-war-insurance' ) : __( 'Unchanged', 'spotship-war-insurance' ) ); ?>"
								<?php disabled( $constant ); ?>>
							<p class="description">
								<?php
								echo $constant
									? esc_html__( 'Defined by SPOTSHIP_WI_API_KEY in wp-config.php, which takes precedence over this field.', 'spotship-war-insurance' )
									: esc_html__( 'Leave blank to keep the current key. Defining SPOTSHIP_WI_API_KEY in wp-config.php keeps it out of the database entirely and is recommended.', 'spotship-war-insurance' );
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="sswi-privacy"><?php esc_html_e( 'Privacy notice URL', 'spotship-war-insurance' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="sswi-privacy"
								name="<?php echo esc_attr( Spotship_WI_Settings::OPTION ); ?>[privacy_notice_url]"
								value="<?php echo esc_attr( (string) $settings['privacy_notice_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Linked beside the consent checkbox and recorded with every submission.', 'spotship-war-insurance' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="sswi-support"><?php esc_html_e( 'Support email', 'spotship-war-insurance' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="sswi-support"
								name="<?php echo esc_attr( Spotship_WI_Settings::OPTION ); ?>[support_email]"
								value="<?php echo esc_attr( (string) $settings['support_email'] ); ?>">
							<p class="description"><?php esc_html_e( 'Shown to visitors when the form cannot be served.', 'spotship-war-insurance' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="sswi-ttl"><?php esc_html_e( 'Rates cache (seconds)', 'spotship-war-insurance' ); ?></label></th>
						<td>
							<input type="number" id="sswi-ttl"
								min="<?php echo esc_attr( (string) Spotship_WI_Settings::TTL_MIN ); ?>"
								max="<?php echo esc_attr( (string) Spotship_WI_Settings::TTL_MAX ); ?>"
								name="<?php echo esc_attr( Spotship_WI_Settings::OPTION ); ?>[rates_cache_ttl]"
								value="<?php echo esc_attr( (string) $settings['rates_cache_ttl'] ); ?>">
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connection', 'spotship-war-insurance' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEST ); ?>">
				<?php wp_nonce_field( self::ACTION_TEST ); ?>
				<?php submit_button( __( 'Test connection', 'spotship-war-insurance' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php self::diagnostics_panel(); ?>
		</div>
		<?php
	}

	/**
	 * The copyable diagnostics blob.
	 *
	 * We cannot see the customer's server. A textarea an administrator can
	 * select and paste is worth more than a pretty table, because what we need
	 * is the whole thing in a support ticket rather than a description of it.
	 */
	private static function diagnostics_panel(): void {
		$blob = Spotship_WI_Diagnostics::blob();
		?>
		<h2><?php esc_html_e( 'Diagnostics', 'spotship-war-insurance' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Copy this and include it when contacting Spot Ship support. It contains no credentials — the API key is masked.', 'spotship-war-insurance' ); ?>
		</p>
		<?php // Deliberately one line: a newline inside <textarea> becomes part of its value. ?>
		<textarea id="sswi-diagnostics" class="large-text code" rows="18" readonly onclick="this.select()"><?php echo esc_textarea( (string) wp_json_encode( $blob, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
		<p>
			<button type="button" class="button" id="sswi-copy-diagnostics">
				<?php esc_html_e( 'Copy diagnostics', 'spotship-war-insurance' ); ?>
			</button>
			<span id="sswi-copy-status" role="status" aria-live="polite"></span>
		</p>
		<script>
		( function () {
			var button = document.getElementById( 'sswi-copy-diagnostics' );
			var field = document.getElementById( 'sswi-diagnostics' );
			var status = document.getElementById( 'sswi-copy-status' );

			if ( ! button || ! field ) {
				return;
			}

			var ok = <?php echo wp_json_encode( __( 'Copied.', 'spotship-war-insurance' ) ); ?>;
			var bad = <?php echo wp_json_encode( __( 'Copy failed. Select the text and copy manually.', 'spotship-war-insurance' ) ); ?>;

			button.addEventListener( 'click', function () {
				/*
				 * The status is ANNOUNCED, not just shown: an administrator needs to
				 * know it worked before they paste into a support ticket.
				 *
				 * navigator.clipboard is unavailable on plain-HTTP admin, which plenty
				 * of self-hosted installs still are. execCommand is the fallback
				 * rather than the primary because it is deprecated — but dropping it
				 * would break copy on exactly the hosts most likely to need support.
				 */
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( field.value ).then(
						function () {
							status.textContent = ok;
						},
						function () {
							status.textContent = bad;
						}
					);

					return;
				}

				field.select();

				try {
					status.textContent = document.execCommand( 'copy' ) ? ok : bad;
				} catch ( e ) {
					status.textContent = bad;
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Post-redirect messages.
	 */
	private static function flash(): void {
		// Read-only display of our own redirect markers; no state changes here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['spotship_wi_rebound'] ) ) {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html__( 'Re-bound to this site. Submissions are enabled again.', 'spotship-war-insurance' )
			);
		}

		if ( isset( $_GET['spotship_wi_test'] ) ) {
			$code = sanitize_text_field( wp_unslash( (string) $_GET['spotship_wi_test'] ) );

			if ( 'ok' === $code ) {
				printf(
					'<div class="notice notice-success"><p>%s</p></div>',
					esc_html__( 'Connected. The rates endpoint answered successfully.', 'spotship-war-insurance' )
				);
			} else {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html( self::test_message( $code ) )
				);
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Explain a failed connection test in terms an administrator can act on.
	 *
	 * @param string $code Normalised client code.
	 */
	private static function test_message( string $code ): string {
		switch ( $code ) {
			case Spotship_WI_Api_Client::UNAUTHORISED:
				return __( 'The API key was rejected. Check it has been copied in full and has not been rotated.', 'spotship-war-insurance' );

			case Spotship_WI_Api_Client::MISCONFIGURED:
				return __( 'No API base URL or key is configured yet.', 'spotship-war-insurance' );

			case Spotship_WI_Api_Client::THROTTLED:
				return __( 'Spot Ship is rate limiting this site. Wait a minute and try again.', 'spotship-war-insurance' );

			case Spotship_WI_Api_Client::TRANSPORT:
				return __( 'Could not reach Spot Ship at all. Some hosts block outbound HTTPS — check the diagnostics below.', 'spotship-war-insurance' );

			default:
				return __( 'Spot Ship answered with an error. Copy the diagnostics below and contact support.', 'spotship-war-insurance' );
		}
	}
}
