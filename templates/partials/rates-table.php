<?php
/**
 * The indicative rate table.
 *
 * `updatedAt` is rendered PROMINENTLY, not as a subtle badge: war-risk rates
 * move fast and a stale table reads cheap.
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

$sswi_table   = $sswi_rates['hull'] ?? array();
$sswi_columns = $sswi_rates['columns'] ?? array();
$sswi_updated = isset( $sswi_rates['updatedAt'] ) ? (string) $sswi_rates['updatedAt'] : '';
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
</div>
