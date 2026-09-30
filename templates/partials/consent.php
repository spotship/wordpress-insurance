<?php
/**
 * Consent checkbox and privacy link.
 *
 * The label text comes from Spotship_WI_Renderer::consent_text() rather than
 * being written here, so the wording stored as `consentText` with the
 * submission is provably the wording the visitor was shown.
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

$sswi_privacy = (string) Spotship_WI_Settings::get( 'privacy_notice_url', '' );
?>
<div class="sswi-field sswi-consent" data-sswi-field="dataConsent">
	<label for="sswi-consent">
		<input type="checkbox" id="sswi-consent" name="dataConsent" value="1" required aria-describedby="sswi-consent-error" disabled>
		<span><?php echo esc_html( Spotship_WI_Renderer::consent_text() ); ?></span>
	</label>

	<?php if ( '' !== $sswi_privacy ) : ?>
		<p class="sswi-hint">
			<a href="<?php echo esc_url( $sswi_privacy ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Read our privacy notice', 'spotship-war-insurance' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<p class="sswi-error" id="sswi-consent-error" data-sswi-error hidden></p>
</div>
