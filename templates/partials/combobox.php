<?php
/**
 * An accessible autocomplete combobox.
 *
 * THE CLASSIC FAILURE IS MOVING DOM FOCUS INTO THE OPTION LIST, which kills the
 * screen reader's echo of what the user typed. Focus stays on the input and
 * `aria-activedescendant` moves instead.
 *
 * Expects: $combo_name, $combo_label, $combo_kind, optional $combo_hint.
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

$sswi_id   = 'sswi-' . $combo_name;
$sswi_list = $sswi_id . '-list';
$sswi_err  = $sswi_id . '-error';
$sswi_hint = isset( $combo_hint ) && '' !== $combo_hint ? $sswi_id . '-hint' : '';
$sswi_live = $sswi_id . '-live';
?>
<div class="sswi-field sswi-combo" data-sswi-combo="<?php echo esc_attr( $combo_kind ); ?>" data-sswi-field="<?php echo esc_attr( $combo_name ); ?>">
	<label for="<?php echo esc_attr( $sswi_id ); ?>"><?php echo esc_html( $combo_label ); ?></label>

	<?php if ( '' !== $sswi_hint ) : ?>
		<p class="sswi-hint" id="<?php echo esc_attr( $sswi_hint ); ?>"><?php echo esc_html( $combo_hint ); ?></p>
	<?php endif; ?>

	<input
		type="text"
		id="<?php echo esc_attr( $sswi_id ); ?>"
		role="combobox"
		aria-expanded="false"
		aria-controls="<?php echo esc_attr( $sswi_list ); ?>"
		aria-autocomplete="list"
		aria-describedby="<?php echo esc_attr( trim( $sswi_hint . ' ' . $sswi_err ) ); ?>"
		autocomplete="off"
		disabled
	>

	<ul class="sswi-listbox" id="<?php echo esc_attr( $sswi_list ); ?>" role="listbox" hidden></ul>

	<?php /* The chosen id. Written in exactly one place by the script. */ ?>
	<input type="hidden" name="<?php echo esc_attr( $combo_name ); ?>" value="" data-sswi-value>

	<p class="sswi-sr" id="<?php echo esc_attr( $sswi_live ); ?>" role="status" aria-live="polite"></p>
	<p class="sswi-error" id="<?php echo esc_attr( $sswi_err ); ?>" data-sswi-error hidden></p>
</div>
