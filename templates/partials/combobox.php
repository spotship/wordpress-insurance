<?php
/**
 * An accessible autocomplete combobox.
 *
 * THE CLASSIC FAILURE IS MOVING DOM FOCUS INTO THE OPTION LIST, which kills the
 * screen reader's echo of what the user typed. Focus stays on the input and
 * `aria-activedescendant` moves instead.
 *
 * Expects: $sswi_uid, $sswi_combo_name, $sswi_combo_label, $sswi_combo_kind,
 * $sswi_combo_required.
 *
 * NO STATIC HINT TEXT. Every combobox used to carry "Type at least two
 * characters, then choose from the list." as a permanent paragraph. It restated
 * what the control already announces: `role="combobox"` with
 * `aria-autocomplete="list"` tells a screen reader the interaction model,
 * `aria-expanded` tracks the list, and the live region below announces the
 * result count on every search. Sighted users get the same from the list
 * appearing as they type. text-field.php keeps its own hint mechanism, because
 * a plain text input announces nothing comparable.
 *
 * THE MINIMUM IS STILL STATED, TWICE, BECAUSE THE TWO MOMENTS ARE DIFFERENT.
 *
 * Deleting the paragraph on the "the live region covers it" reasoning was not
 * true: the live region is written when a search returns, and a term shorter
 * than the minimum never searches. The script now writes the minimum into it on
 * exactly those keystrokes — see minCharsMessage() in
 * assets/js/war-insurance.js.
 *
 * That still leaves the user who focuses the field and types nothing. A live
 * region only speaks when it changes, and `input` is what changes it, so
 * arriving at the control said nothing at all about a threshold — and neither
 * did anything in the accessibility tree, once the hint left
 * `aria-describedby`. The same silence the deletion was meant to fix, moved one
 * keystroke earlier.
 *
 * So the threshold is back in `aria-describedby`, where a screen reader reads
 * it on focus and on every re-read of the control, but VISUALLY HIDDEN. What
 * was redundant about the old paragraph was "then choose from the list" sitting
 * permanently on screen, not the statement that a minimum exists.
 *
 * REQUIRED IS `aria-required`, NOT THE NATIVE `required` text-field.php uses.
 * The control that gets submitted is the hidden input below, written only when
 * the user picks a row; the visible input is a search box. Native validation
 * reads the visible one, so it would pass on typed-but-never-chosen text and
 * block on a chosen value whose search box had been cleared — in both cases
 * disagreeing with what is actually sent. `aria-required` states the
 * requirement without claiming the browser will enforce it; the server does.
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
 * @var string $sswi_uid
 * @var string $sswi_combo_name
 * @var string $sswi_combo_label
 * @var string $sswi_combo_kind
 * @var bool $sswi_combo_required
 */

/*
 * `$sswi_hint_id`, NOT `$sswi_hint`. THESE PARTIALS SHARE ONE SCOPE.
 *
 * hull.php and cargo.php `require` this file and text-field.php into the same
 * variable scope, in sequence. text-field.php READS `$sswi_hint` as its visible
 * hint TEXT without assigning it first — it is a documented input of that
 * template — so an id left behind in that name by this one is a DOM id rendered
 * to the visitor as a sentence, silently, instead of the undefined-variable
 * notice that an unset input would raise.
 *
 * It does not bite today only because every text-field.php call site assigns
 * `$sswi_hint` immediately above its require. The id belongs in the name
 * text-field.php uses for the same thing, which also assigns it on every pass.
 * tests/js/contract.test.js holds the two apart.
 */
$sswi_id      = $sswi_uid . '-' . $sswi_combo_name;
$sswi_list    = $sswi_id . '-list';
$sswi_err     = $sswi_id . '-error';
$sswi_live    = $sswi_id . '-live';
$sswi_hint_id = $sswi_id . '-hint';
?>
<div class="sswi-field sswi-combo" data-sswi-combo="<?php echo esc_attr( $sswi_combo_kind ); ?>" data-sswi-field="<?php echo esc_attr( $sswi_combo_name ); ?>">
	<label for="<?php echo esc_attr( $sswi_id ); ?>">
		<?php echo esc_html( $sswi_combo_label ); ?>
		<?php if ( ! empty( $sswi_combo_required ) ) : ?>
			<span class="sswi-required" aria-hidden="true">*</span>
			<span class="sswi-sr"><?php esc_html_e( '(required)', 'spotship-war-insurance' ); ?></span>
		<?php endif; ?>
	</label>

	<input
		type="text"
		id="<?php echo esc_attr( $sswi_id ); ?>"
		role="combobox"
		aria-expanded="false"
		aria-controls="<?php echo esc_attr( $sswi_list ); ?>"
		aria-autocomplete="list"
		<?php if ( ! empty( $sswi_combo_required ) ) : ?>
			aria-required="true"
		<?php endif; ?>
		aria-describedby="<?php echo esc_attr( $sswi_hint_id . ' ' . $sswi_err ); ?>"
		autocomplete="off"
		disabled
	>

	<p class="sswi-sr" id="<?php echo esc_attr( $sswi_hint_id ); ?>"><?php echo esc_html( Spotship_WI_Renderer::min_chars_hint() ); ?></p>

	<ul class="sswi-listbox" id="<?php echo esc_attr( $sswi_list ); ?>" role="listbox" hidden></ul>

	<?php /* The chosen id. Written in exactly one place by the script. */ ?>
	<input type="hidden" name="<?php echo esc_attr( $sswi_combo_name ); ?>" value="" data-sswi-value>

	<p class="sswi-sr" id="<?php echo esc_attr( $sswi_live ); ?>" role="status" aria-live="polite"></p>
	<p class="sswi-error" id="<?php echo esc_attr( $sswi_err ); ?>" data-sswi-error hidden></p>
</div>
