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

	<?php if ( array() !== $sswi_rates && isset( $sswi_rates['cargo'] ) ) : ?>
		<?php require SPOTSHIP_WI_PATH . 'templates/partials/rates-table.php'; ?>
	<?php endif; ?>

	<form class="sswi-form" data-sswi-form="cargo" novalidate>
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
		$combo_label = __( 'Vessel (optional)', 'spotship-war-insurance' );
		$combo_kind  = 'vessel';
		$combo_hint  = __( 'Type at least two characters, then choose from the list.', 'spotship-war-insurance' );
		require SPOTSHIP_WI_PATH . 'templates/partials/combobox.php';

		$combo_name  = 'fromPort';
		$combo_label = __( 'From location', 'spotship-war-insurance' );
		$combo_kind  = 'location';
		require SPOTSHIP_WI_PATH . 'templates/partials/combobox.php';
		?>

		<?php /* Ordered, multi-select. Each chip carries its own labelled remove button. */ ?>
		<div class="sswi-field sswi-destinations" data-sswi-destinations data-sswi-field="destinations">
			<label for="sswi-destinations"><?php esc_html_e( 'Destinations', 'spotship-war-insurance' ); ?></label>
			<ul class="sswi-chips" data-sswi-chips></ul>
			<input type="text" id="sswi-destinations" role="combobox" aria-expanded="false"
				aria-controls="sswi-destinations-list" aria-autocomplete="list"
				aria-describedby="sswi-destinations-error" autocomplete="off" disabled>
			<ul class="sswi-listbox" id="sswi-destinations-list" role="listbox" hidden></ul>
			<p class="sswi-sr" role="status" aria-live="polite" data-sswi-chip-live></p>
			<p class="sswi-error" id="sswi-destinations-error" data-sswi-error hidden></p>
		</div>

		<?php
		$name         = 'cargoDescription';
		$label        = __( 'Cargo description', 'spotship-war-insurance' );
		$type         = 'text';
		$maxlength    = 5000;
		$required     = true;
		$autocomplete = '';
		$hint         = '';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';

		$name      = 'cargoWeight';
		$label     = __( 'Cargo weight', 'spotship-war-insurance' );
		$maxlength = 100;
		$hint      = __( 'For example, 50,000 MT.', 'spotship-war-insurance' );
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';

		$name      = 'cargoValue';
		$label     = __( 'Cargo value', 'spotship-war-insurance' );
		$maxlength = 20;
		$hint      = '';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';

		$name      = 'insuredParty';
		$label     = __( 'Insured party', 'spotship-war-insurance' );
		$maxlength = 255;
		$required  = false;
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';
		?>

		<?php /* Selects, not text inputs — maxlength is meaningless on a select. */ ?>
		<div class="sswi-field" data-sswi-field="cargoCurrency">
			<label for="sswi-cargoCurrency"><?php esc_html_e( 'Currency', 'spotship-war-insurance' ); ?></label>
			<select id="sswi-cargoCurrency" name="cargoCurrency" aria-describedby="sswi-cargoCurrency-error" required disabled>
				<option value=""><?php esc_html_e( 'Choose a currency', 'spotship-war-insurance' ); ?></option>
				<option value="USD">USD</option>
				<option value="GBP">GBP</option>
				<option value="EUR">EUR</option>
			</select>
			<p class="sswi-error" id="sswi-cargoCurrency-error" data-sswi-error hidden></p>
		</div>

		<div class="sswi-field" data-sswi-field="riskZone">
			<label for="sswi-riskZone"><?php esc_html_e( 'Risk zone', 'spotship-war-insurance' ); ?></label>
			<select id="sswi-riskZone" name="riskZone" aria-describedby="sswi-riskZone-error" data-sswi-risk-zone required disabled>
				<option value=""><?php esc_html_e( 'Choose a risk zone', 'spotship-war-insurance' ); ?></option>
			</select>
			<p class="sswi-error" id="sswi-riskZone-error" data-sswi-error hidden></p>
		</div>

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
