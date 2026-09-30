<?php
/**
 * War hull quote form.
 *
 * Expects $data from Spotship_WI_Renderer::render().
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

$sswi_serving = $data['serving'];
$sswi_rates   = $sswi_serving['rates'];
?>
<div class="spotship-wi" data-sswi-root data-sswi-config="<?php echo esc_attr( (string) wp_json_encode( $data['config'] ) ); ?>">

	<?php /* Shown until JS enables the form. See class-renderer.php. */ ?>
	<p class="sswi-noscript" data-sswi-noscript>
		<?php esc_html_e( 'This form needs JavaScript. Please enable it, or contact our insurance desk directly.', 'spotship-war-insurance' ); ?>
	</p>

	<?php if ( array() !== $sswi_rates && isset( $sswi_rates['hull'] ) ) : ?>
		<?php require SPOTSHIP_WI_PATH . 'templates/partials/rates-table.php'; ?>
	<?php endif; ?>

	<form class="sswi-form" data-sswi-form="hull" novalidate>
		<div class="sswi-summary" data-sswi-summary tabindex="-1" hidden></div>

		<?php
		$name         = 'userName';
		$label        = __( 'Your name', 'spotship-war-insurance' );
		$type         = 'text';
		$maxlength    = 255;
		$required     = true;
		$autocomplete = 'name';
		$hint         = '';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';

		$name         = 'userEmail';
		$label        = __( 'Email', 'spotship-war-insurance' );
		$type         = 'email';
		$maxlength    = 254;
		$required     = true;
		$autocomplete = 'email';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';

		$name         = 'userPhone';
		$label        = __( 'Phone', 'spotship-war-insurance' );
		$type         = 'tel';
		$maxlength    = 20;
		$required     = false;
		$autocomplete = 'tel';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';
		?>

		<?php
		$combo_name  = 'vessel';
		$combo_label = __( 'Vessel', 'spotship-war-insurance' );
		$combo_kind  = 'vessel';
		$combo_hint  = __( 'Type at least two characters, then choose from the list.', 'spotship-war-insurance' );
		require SPOTSHIP_WI_PATH . 'templates/partials/combobox.php';

		$combo_name  = 'fromPort';
		$combo_label = __( 'From location', 'spotship-war-insurance' );
		$combo_kind  = 'location';
		require SPOTSHIP_WI_PATH . 'templates/partials/combobox.php';

		$combo_name  = 'toPort';
		$combo_label = __( 'To location', 'spotship-war-insurance' );
		$combo_kind  = 'location';
		require SPOTSHIP_WI_PATH . 'templates/partials/combobox.php';
		?>

		<?php
		$name         = 'handmValue';
		$label        = __( 'Hull & machinery value', 'spotship-war-insurance' );
		$type         = 'text';
		$maxlength    = 40;
		$required     = false;
		$autocomplete = '';
		$hint         = '';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';

		$name      = 'increasedValue';
		$label     = __( 'Increased value', 'spotship-war-insurance' );
		$maxlength = 40;
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';
		?>

		<div class="sswi-field">
			<label for="sswi-notes"><?php esc_html_e( 'Notes', 'spotship-war-insurance' ); ?></label>
			<textarea id="sswi-notes" name="notes" maxlength="5000" rows="4" aria-describedby="sswi-notes-error" disabled></textarea>
			<p class="sswi-error" id="sswi-notes-error" data-sswi-error hidden></p>
		</div>

		<?php require SPOTSHIP_WI_PATH . 'templates/partials/consent.php'; ?>

		<?php echo Spotship_WI_Renderer::honeypot(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built and escaped in the renderer. ?>

		<input type="hidden" name="_sswi_ts" value="<?php echo esc_attr( (string) $data['timestamp'] ); ?>">
		<input type="hidden" name="_sswi_form_id" value="<?php echo esc_attr( $data['form_id'] ); ?>">
		<input type="hidden" name="_sswi_sig" value="<?php echo esc_attr( $data['signature'] ); ?>">

		<button type="submit" class="sswi-submit" disabled>
			<?php esc_html_e( 'Request a quote', 'spotship-war-insurance' ); ?>
		</button>

		<p class="sswi-result" data-sswi-result role="status" aria-live="polite"></p>
	</form>
</div>
