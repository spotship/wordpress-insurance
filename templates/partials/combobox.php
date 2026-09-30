<?php
/**
 * An accessible autocomplete combobox.
 *
 * THE CLASSIC FAILURE IS MOVING DOM FOCUS INTO THE OPTION LIST, which kills the
 * screen reader's echo of what the user typed. Focus stays on the input and
 * `aria-activedescendant` moves instead.
 *
 * Expects: $sswi_combo_name, $sswi_combo_label, $sswi_combo_kind, optional $sswi_combo_hint.
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

// Declared for static analysis: these are set by the including scope, which
// PHPStan cannot follow through include().
/** @var string $sswi_combo_name */
/** @var string $sswi_combo_label */
/** @var string $sswi_combo_kind */
/** @var string $sswi_combo_hint */

$sswi_id      = 'sswi-' . $sswi_combo_name;
$sswi_list    = $sswi_id . '-list';
$sswi_err     = $sswi_id . '-error';
$sswi_live    = $sswi_id . '-live';
$sswi_hint_id = isset( $sswi_combo_hint ) && '' !== $sswi_combo_hint ? $sswi_id . '-hint' : '';
?>
<div class="sswi-field sswi-combo" data-sswi-combo="<?php echo esc_attr( $sswi_combo_kind ); ?>" data-sswi-field="<?php echo esc_attr( $sswi_combo_name ); ?>">
	<label for="<?php echo esc_attr( $sswi_id ); ?>"><?php echo esc_html( $sswi_combo_label ); ?></label>

	<?php if ( '' !== $sswi_hint_id ) : ?>
		<p class="sswi-hint" id="<?php echo esc_attr( $sswi_hint_id ); ?>"><?php echo esc_html( $sswi_combo_hint ); ?></p>
	<?php endif; ?>

	<input
		type="text"
		id="<?php echo esc_attr( $sswi_id ); ?>"
		role="combobox"
		aria-expanded="false"
		aria-controls="<?php echo esc_attr( $sswi_list ); ?>"
		aria-autocomplete="list"
		aria-describedby="<?php echo esc_attr( trim( $sswi_hint_id . ' ' . $sswi_err ) ); ?>"
		autocomplete="off"
		disabled
	>

	<ul class="sswi-listbox" id="<?php echo esc_attr( $sswi_list ); ?>" role="listbox" hidden></ul>

	<?php /* The chosen id. Written in exactly one place by the script. */ ?>
	<input type="hidden" name="<?php echo esc_attr( $sswi_combo_name ); ?>" value="" data-sswi-value>

	<p class="sswi-sr" id="<?php echo esc_attr( $sswi_live ); ?>" role="status" aria-live="polite"></p>
	<p class="sswi-error" id="<?php echo esc_attr( $sswi_err ); ?>" data-sswi-error hidden></p>
</div>
