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

/**
 * What the including scope hands this template.
 *
 * @var string $sswi_uid
 */

$sswi_privacy     = (string) Spotship_WI_Settings::get( 'privacy_notice_url', '' );
$sswi_consent_id  = $sswi_uid . '-consent';
$sswi_consent_err = $sswi_consent_id . '-error';
?>
<div class="sswi-field sswi-consent" data-sswi-field="dataConsent">
	<label for="<?php echo esc_attr( $sswi_consent_id ); ?>">
		<input type="checkbox" id="<?php echo esc_attr( $sswi_consent_id ); ?>" name="dataConsent" value="1" required aria-describedby="<?php echo esc_attr( $sswi_consent_err ); ?>" data-sswi-consent disabled>
		<span><?php echo esc_html( Spotship_WI_Renderer::consent_text() ); ?></span>
	</label>

	<?php if ( '' !== $sswi_privacy ) : ?>
		<p class="sswi-hint">
			<a href="<?php echo esc_url( $sswi_privacy ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Read our privacy notice', 'spotship-war-insurance' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<p class="sswi-error" id="<?php echo esc_attr( $sswi_consent_err ); ?>" data-sswi-error hidden></p>
</div>
