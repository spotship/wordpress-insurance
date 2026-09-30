<?php
/**
 * The indicative rate table.
 *
 * `updatedAt` is rendered PROMINENTLY, not as a subtle badge: war-risk rates
 * move fast and a stale table reads cheap.
 *
 * Expects $data (for the product) and $sswi_rates.
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

// Keyed off the product being rendered. A literal here renders the hull
// table under the cargo form, which is wrong AND commercially misleading:
// cargo and hull rates are different numbers for the same zone.
$sswi_table   = $sswi_rates[ $data['config']['product'] ] ?? array();
$sswi_columns = $sswi_rates['columns'] ?? array();
$sswi_updated = isset( $sswi_rates['updatedAt'] ) ? (string) $sswi_rates['updatedAt'] : '';
$sswi_tier    = (string) ( $sswi_serving['tier'] ?? '' );

/*
 * A table served from the backup or bundled tier is NOT current, and the
 * visitor is the person deciding whether to act on it. Showing a publication
 * date without saying the figures could not be refreshed invites them to read
 * a cached number as today's — and war-risk rates move fast enough that the
 * difference is commercially real.
 */
$sswi_stale = in_array( $sswi_tier, array( 'backup', 'bundled' ), true );
?>
<div class="sswi-rates">
	<?php if ( '' !== $sswi_updated ) : ?>
		<p class="sswi-rates-updated">
			<?php
			printf(
				/* translators: %s: date the rates were published. */
				esc_html__( 'Rates published %s', 'spotship-war-insurance' ),
				esc_html( $sswi_updated )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $sswi_stale ) : ?>
		<p class="sswi-rates-stale" role="status">
			<strong><?php esc_html_e( 'These rates could not be refreshed and may be out of date.', 'spotship-war-insurance' ); ?></strong>
			<?php esc_html_e( 'Contact our insurance desk to confirm current figures before relying on them.', 'spotship-war-insurance' ); ?>
		</p>
	<?php endif; ?>

	<table>
		<caption><?php esc_html_e( 'Indicative war risk rates', 'spotship-war-insurance' ); ?></caption>
		<thead>
			<tr>
				<?php /* serica's table has an empty first header cell; give it an accessible name. */ ?>
				<th scope="col"><span class="sswi-sr"><?php esc_html_e( 'Risk zone', 'spotship-war-insurance' ); ?></span></th>
				<?php foreach ( $sswi_columns as $sswi_col ) : ?>
					<th scope="col"><?php echo esc_html( is_scalar( $sswi_col ) ? (string) $sswi_col : '' ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $sswi_table as $sswi_row ) : ?>
				<?php if ( ! is_array( $sswi_row ) ) { continue; } ?>
				<tr>
					<th scope="row"><?php echo esc_html( (string) ( $sswi_row['zone'] ?? '' ) ); ?></th>
					<?php foreach ( $sswi_columns as $sswi_col ) : ?>
						<td><?php echo esc_html( (string) ( $sswi_row[ $sswi_col ] ?? '—' ) ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	/*
	 * THE DISCLAIMERS ARE SERVED FROM THE ENDPOINT, AND RENDERING THEM IS THE
	 * POINT OF THAT.
	 *
	 * They are not decoration. They state that the figures are indicative only,
	 * not an offer or a quote, and subject to underwriting; they name Spot Ship's
	 * introducer role and the insurance partner; and they scope the audience to
	 * commercial shipping businesses rather than consumers. This table is
	 * published on a third party's public website, so omitting them is a
	 * compliance failure, not a missing flourish.
	 *
	 * Serving them from the rates endpoint rather than baking them into the
	 * plugin is also what makes deferring partner sign-off tolerable: if the
	 * partner wants different wording it is a backend string change, not a
	 * re-release to every customer install. That argument only holds while the
	 * plugin actually renders whatever it is sent.
	 */
	$sswi_disclaimers = isset( $sswi_rates['disclaimers'] ) && is_array( $sswi_rates['disclaimers'] )
		? $sswi_rates['disclaimers']
		: array();
	?>

	<?php if ( array() !== $sswi_disclaimers ) : ?>
		<div class="sswi-disclaimers">
			<?php foreach ( $sswi_disclaimers as $sswi_disclaimer ) : ?>
				<?php if ( is_scalar( $sswi_disclaimer ) ) : ?>
					<p><?php echo esc_html( (string) $sswi_disclaimer ); ?></p>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

</div>
