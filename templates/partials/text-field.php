<?php
/**
 * A labelled text input with accessible error wiring.
 *
 * Expects: $name, $label, $type, $maxlength, $required, $autocomplete, $hint.
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

$sswi_id   = 'sswi-' . $name;
$sswi_hint = isset( $hint ) && '' !== $hint ? $sswi_id . '-hint' : '';
$sswi_err  = $sswi_id . '-error';
?>
<div class="sswi-field" data-sswi-field="<?php echo esc_attr( $name ); ?>">
	<label for="<?php echo esc_attr( $sswi_id ); ?>">
		<?php echo esc_html( $label ); ?>
		<?php if ( ! empty( $required ) ) : ?>
			<span class="sswi-required" aria-hidden="true">*</span>
			<span class="sswi-sr"><?php esc_html_e( '(required)', 'spotship-war-insurance' ); ?></span>
		<?php endif; ?>
	</label>

	<?php if ( '' !== $sswi_hint ) : ?>
		<p class="sswi-hint" id="<?php echo esc_attr( $sswi_hint ); ?>"><?php echo esc_html( $hint ); ?></p>
	<?php endif; ?>

	<input
		type="<?php echo esc_attr( $type ?? 'text' ); ?>"
		id="<?php echo esc_attr( $sswi_id ); ?>"
		name="<?php echo esc_attr( $name ); ?>"
		<?php if ( ! empty( $maxlength ) ) : ?>maxlength="<?php echo esc_attr( (string) $maxlength ); ?>"<?php endif; ?>
		<?php if ( ! empty( $autocomplete ) ) : ?>autocomplete="<?php echo esc_attr( $autocomplete ); ?>"<?php endif; ?>
		<?php if ( ! empty( $required ) ) : ?>required<?php endif; ?>
		aria-describedby="<?php echo esc_attr( trim( $sswi_hint . ' ' . $sswi_err ) ); ?>"
		disabled
	>

	<?php /* Error text is conveyed by more than colour: an icon glyph and words. */ ?>
	<p class="sswi-error" id="<?php echo esc_attr( $sswi_err ); ?>" data-sswi-error hidden></p>
</div>
