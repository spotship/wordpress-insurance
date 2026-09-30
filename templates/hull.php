<?php
/**
 * War hull quote form.
 *
 * Expects $data from Spotship_WI_Renderer::render().
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

/**
 * What the including scope hands this template.
 *
 * Declared because PHPStan cannot follow variables through include(), and
 * because writing the shape down beats inferring it by reading the renderer.
 *
 * @var array{
 *     product: string,
 *     uid: string,
 *     form_id: string,
 *     timestamp: int,
 *     signature: string,
 *     config: array<string, mixed>,
 *     serving: array{tier: string, rates: array<string, mixed>}
 * } $data
 */

$sswi_serving = $data['serving'];
$sswi_rates   = $sswi_serving['rates'];

/*
 * Every element id below is namespaced with this. A page may carry more than
 * one form, and duplicate ids silently re-point each label at the first form's
 * input. See the $instances counter in class-renderer.php.
 */
$sswi_uid = $data['uid'];

$sswi_notes_id  = $sswi_uid . '-notes';
$sswi_notes_err = $sswi_notes_id . '-error';
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
		$sswi_name         = 'userName';
		$sswi_label        = __( 'Your name', 'spotship-war-insurance' );
		$sswi_type         = 'text';
		$sswi_maxlength    = 255;
		$sswi_required     = true;
		$sswi_autocomplete = 'name';
		$sswi_hint         = '';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';

		$sswi_name         = 'userEmail';
		$sswi_label        = __( 'Email', 'spotship-war-insurance' );
		$sswi_type         = 'email';
		$sswi_maxlength    = 254;
		$sswi_required     = true;
		$sswi_autocomplete = 'email';
		$sswi_hint         = '';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';

		$sswi_name         = 'userPhone';
		$sswi_label        = __( 'Phone', 'spotship-war-insurance' );
		$sswi_type         = 'tel';
		$sswi_maxlength    = 20;
		$sswi_required     = false;
		$sswi_autocomplete = 'tel';
		$sswi_hint         = '';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';
		?>

		<?php
		$sswi_combo_name  = 'vessel';
		$sswi_combo_label = __( 'Vessel', 'spotship-war-insurance' );
		$sswi_combo_kind  = 'vessel';
		$sswi_combo_hint  = __( 'Type at least two characters, then choose from the list.', 'spotship-war-insurance' );
		require SPOTSHIP_WI_PATH . 'templates/partials/combobox.php';

		$sswi_combo_name  = 'fromPort';
		$sswi_combo_label = __( 'From location', 'spotship-war-insurance' );
		$sswi_combo_kind  = 'location';
		$sswi_combo_hint  = __( 'Type at least two characters, then choose from the list.', 'spotship-war-insurance' );
		require SPOTSHIP_WI_PATH . 'templates/partials/combobox.php';

		$sswi_combo_name  = 'toPort';
		$sswi_combo_label = __( 'To location', 'spotship-war-insurance' );
		$sswi_combo_kind  = 'location';
		$sswi_combo_hint  = __( 'Type at least two characters, then choose from the list.', 'spotship-war-insurance' );
		require SPOTSHIP_WI_PATH . 'templates/partials/combobox.php';
		?>

		<?php
		$sswi_name         = 'handmValue';
		$sswi_label        = __( 'Hull & machinery value', 'spotship-war-insurance' );
		$sswi_type         = 'text';
		$sswi_maxlength    = 40;
		$sswi_required     = false;
		$sswi_autocomplete = '';
		$sswi_hint         = '';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';

		$sswi_name         = 'increasedValue';
		$sswi_label        = __( 'Increased value', 'spotship-war-insurance' );
		$sswi_type         = 'text';
		$sswi_maxlength    = 40;
		$sswi_required     = false;
		$sswi_autocomplete = '';
		$sswi_hint         = '';
		require SPOTSHIP_WI_PATH . 'templates/partials/text-field.php';
		?>

		<?php
		/*
		 * `data-sswi-field` IS HOW AN ERROR FINDS ITS FIELD. showFieldError()
		 * resolves `[data-sswi-field="<name>"]`, and this wrapper is hand-written
		 * rather than produced by text-field.php, which sets the attribute for
		 * every other input. Without it a notes error — the local over-length
		 * check or an upstream `notes` field error — cannot attach to the
		 * textarea and degrades to the generic "unavailable" message, telling a
		 * visitor the service is down when their note was simply too long.
		 *
		 * cargo.php hand-writes the same block and had the same omission.
		 */
		?>
		<div class="sswi-field" data-sswi-field="notes">
			<label for="<?php echo esc_attr( $sswi_notes_id ); ?>"><?php esc_html_e( 'Notes', 'spotship-war-insurance' ); ?></label>
			<textarea id="<?php echo esc_attr( $sswi_notes_id ); ?>" name="notes" maxlength="5000" rows="4" aria-describedby="<?php echo esc_attr( $sswi_notes_err ); ?>" disabled></textarea>
			<p class="sswi-error" id="<?php echo esc_attr( $sswi_notes_err ); ?>" data-sswi-error hidden></p>
		</div>

		<?php require SPOTSHIP_WI_PATH . 'templates/partials/consent.php'; ?>

		<?php echo Spotship_WI_Renderer::honeypot( $sswi_uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built and escaped in the renderer. ?>

		<input type="hidden" name="_sswi_ts" value="<?php echo esc_attr( (string) $data['timestamp'] ); ?>">
		<input type="hidden" name="_sswi_form_id" value="<?php echo esc_attr( $data['form_id'] ); ?>">
		<input type="hidden" name="_sswi_sig" value="<?php echo esc_attr( $data['signature'] ); ?>">

		<button type="submit" class="sswi-submit" disabled>
			<?php esc_html_e( 'Request a quote', 'spotship-war-insurance' ); ?>
		</button>

		<p class="sswi-result" data-sswi-result role="status" aria-live="polite"></p>
	</form>
</div>
