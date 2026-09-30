<?php
/**
 * A labelled text input with accessible error wiring.
 *
 * Expects: $sswi_name, $sswi_label, $sswi_type, $sswi_maxlength, $sswi_required, $sswi_autocomplete, $sswi_hint.
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

// Declared for static analysis: these are set by the including scope, which
// PHPStan cannot follow through include().
/** @var string $sswi_name */
/** @var string $sswi_label */
/** @var string $sswi_type */
/** @var int $sswi_maxlength */
/** @var bool $sswi_required */
/** @var string $sswi_autocomplete */
/** @var string $sswi_hint */

$sswi_id      = 'sswi-' . $sswi_name;
$sswi_err     = $sswi_id . '-error';
$sswi_hint_id = isset( $sswi_hint ) && '' !== $sswi_hint ? $sswi_id . '-hint' : '';
?>
<div class="sswi-field" data-sswi-field="<?php echo esc_attr( $sswi_name ); ?>">
	<label for="<?php echo esc_attr( $sswi_id ); ?>">
		<?php echo esc_html( $sswi_label ); ?>
		<?php if ( ! empty( $sswi_required ) ) : ?>
			<span class="sswi-required" aria-hidden="true">*</span>
			<span class="sswi-sr"><?php esc_html_e( '(required)', 'spotship-war-insurance' ); ?></span>
		<?php endif; ?>
	</label>

	<?php if ( '' !== $sswi_hint_id ) : ?>
		<p class="sswi-hint" id="<?php echo esc_attr( $sswi_hint_id ); ?>"><?php echo esc_html( $sswi_hint ); ?></p>
	<?php endif; ?>

	<input
		type="<?php echo esc_attr( $sswi_type ?? 'text' ); ?>"
		id="<?php echo esc_attr( $sswi_id ); ?>"
		name="<?php echo esc_attr( $sswi_name ); ?>"
		<?php if ( ! empty( $sswi_maxlength ) ) : ?>
			maxlength="<?php echo esc_attr( (string) $sswi_maxlength ); ?>"
		<?php endif; ?>
		<?php if ( ! empty( $sswi_autocomplete ) ) : ?>
			autocomplete="<?php echo esc_attr( $sswi_autocomplete ); ?>"
		<?php endif; ?>
		<?php if ( ! empty( $sswi_required ) ) : ?>
			required
		<?php endif; ?>
		aria-describedby="<?php echo esc_attr( trim( $sswi_hint_id . ' ' . $sswi_err ) ); ?>"
		disabled
	>

	<?php /* Error text is conveyed by more than colour: an icon glyph and words. */ ?>
	<p class="sswi-error" id="<?php echo esc_attr( $sswi_err ); ?>" data-sswi-error hidden></p>
</div>
