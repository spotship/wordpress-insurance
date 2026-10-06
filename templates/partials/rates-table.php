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

/**
 * What the including scope hands this template.
 *
 * Declared because PHPStan cannot follow variables through include(), and
 * because writing the shape down beats inferring it by reading the renderer.
 *
 * @var array{
 *     product: string,
 *     form_id: string,
 *     timestamp: int,
 *     signature: string,
 *     config: array<string, mixed>,
 *     serving: array{tier: string, rates: array<string, mixed>}
 * } $data
 * @var array<string, mixed> $sswi_rates
 * @var array{tier: string, rates: array<string, mixed>} $sswi_serving
 */

/*
 * EVERYTHING EXCEPT `updatedAt` IS NESTED UNDER THE PRODUCT.
 *
 * `GET /api/insurance/rates/` returns
 *   { updatedAt, hull: { columns, zones, disclaimers, footnotes }, cargo: {…} }
 * with each column an object — `{ key: "hmRate", label: "H&M" }` — each row
 * keyed `name` rather than `zone`, and each rate a JSON NUMBER.
 *
 * This file used to read a top-level `columns` list of plain strings and treat
 * `$sswi_rates[product]` as the row list. The API has never emitted that shape.
 * Against the real backend it produced a table with no header cells and four
 * empty body rows — one per key of the product object — and no disclaimers at
 * all, which the partial's own comment below calls a compliance failure.
 *
 * It survived because bin/e2e-upstream.js served the invented shape back, so
 * the browser suite asserted a contract that does not exist. The fixture is now
 * derived from the backend by bin/derive-backend-rates.sh, and
 * tests/js/contract.test.js fails if the two drift apart again.
 */
$sswi_product = (string) $data['config']['product'];
$sswi_section = isset( $sswi_rates[ $sswi_product ] ) && is_array( $sswi_rates[ $sswi_product ] )
	? $sswi_rates[ $sswi_product ]
	: array();

$sswi_table   = isset( $sswi_section['zones'] ) && is_array( $sswi_section['zones'] )
	? $sswi_section['zones']
	: array();
$sswi_columns = isset( $sswi_section['columns'] ) && is_array( $sswi_section['columns'] )
	? $sswi_section['columns']
	: array();
$sswi_updated = isset( $sswi_rates['updatedAt'] ) ? (string) $sswi_rates['updatedAt'] : '';
$sswi_tier    = $sswi_serving['tier'];

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

	<?php
	/*
	 * THE SCROLLING REGION IS FOCUSABLE, AND IT WRAPS THE TABLE ONLY.
	 *
	 * At 320px the rate table overflows horizontally. A scroll container that
	 * cannot be focused can only be scrolled by pointer, so a keyboard-only
	 * visitor cannot read the columns past the fold — axe flags it as
	 * scrollable-region-focusable, WCAG 2.1.1 and 2.1.3, and it is right.
	 *
	 * Previously `overflow-x: auto` sat on .sswi-rates, which also contains the
	 * publication date, the stale warning and the disclaimers. Those should not
	 * scroll sideways, and the region's accessible name should describe the
	 * table, so the container now wraps the table alone.
	 */
	?>
	<div class="sswi-rates-scroll" tabindex="0" role="region"
		aria-label="<?php esc_attr_e( 'Indicative war risk rates', 'spotship-war-insurance' ); ?>">
	<table>
		<caption><?php esc_html_e( 'Indicative war risk rates', 'spotship-war-insurance' ); ?></caption>
		<thead>
			<tr>
				<?php /* serica's table has an empty first header cell; give it an accessible name. */ ?>
				<th scope="col"><span class="sswi-sr"><?php esc_html_e( 'Risk zone', 'spotship-war-insurance' ); ?></span></th>
				<?php foreach ( $sswi_columns as $sswi_col ) : ?>
					<?php
					// The LABEL is what a visitor reads ("NCB Rate"); the key is
					// the field name it is looked up by. Rendering the key put
					// "ncbRate" in the header of a published rate table.
					$sswi_label = is_array( $sswi_col ) && isset( $sswi_col['label'] )
						? (string) $sswi_col['label']
						: '';
					?>
					<th scope="col"><?php echo esc_html( $sswi_label ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $sswi_table as $sswi_row ) : ?>
				<?php
				if ( ! is_array( $sswi_row ) ) {
					continue;
				}
				?>
				<tr>
					<?php // `name`, not `zone` — the upstream row key. ?>
					<th scope="row"><?php echo esc_html( (string) ( $sswi_row['name'] ?? '' ) ); ?></th>
					<?php foreach ( $sswi_columns as $sswi_col ) : ?>
						<?php
						$sswi_key  = is_array( $sswi_col ) && isset( $sswi_col['key'] ) ? (string) $sswi_col['key'] : '';
						$sswi_cell = '' !== $sswi_key && array_key_exists( $sswi_key, $sswi_row )
							? Spotship_WI_Renderer::rate_percent( $sswi_row[ $sswi_key ] )
							: '—';
						?>
						<td><?php echo esc_html( $sswi_cell ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<?php
	/*
	 * THE DISCLAIMERS ARE SERVED FROM THE ENDPOINT, AND RENDERING THEM IS THE
	 * POINT OF THAT.
	 *
	 * They are not decoration. They state that the figures are indicative only,
	 * not an offer or a quote, and subject to underwriting; they name Spot Ship's
	 * introducer role; and they scope the audience to commercial shipping
	 * businesses rather than consumers. This table is published on a third
	 * party's public website, so omitting them is a compliance failure, not a
	 * missing flourish.
	 *
	 * Serving them from the rates endpoint rather than baking them into the
	 * plugin is also what makes deferring partner sign-off tolerable: if the
	 * partner wants different wording it is a backend string change, not a
	 * re-release to every customer install. That argument only holds while the
	 * plugin actually renders whatever it is sent.
	 *
	 * Which, until this was fixed, it did not: the disclaimers are served under
	 * the PRODUCT, and this read them from the top level, so the block below was
	 * unreachable against the real backend and the compliance text never
	 * appeared on any customer page.
	 *
	 * Hull also carries `footnotes` — the Hormuz Passage row is an outlier and
	 * says so in one — which nothing rendered at all.
	 */
	$sswi_disclaimers = isset( $sswi_section['disclaimers'] ) && is_array( $sswi_section['disclaimers'] )
		? $sswi_section['disclaimers']
		: array();
	$sswi_footnotes   = isset( $sswi_section['footnotes'] ) && is_array( $sswi_section['footnotes'] )
		? $sswi_section['footnotes']
		: array();
	?>

	<?php if ( array() !== $sswi_footnotes ) : ?>
		<div class="sswi-footnotes">
			<?php foreach ( $sswi_footnotes as $sswi_footnote ) : ?>
				<?php if ( is_scalar( $sswi_footnote ) ) : ?>
					<p><?php echo esc_html( (string) $sswi_footnote ); ?></p>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

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
