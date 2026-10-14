<?php
/**
 * Form rendering — ONE markup path, shared by the shortcode and the block.
 *
 * THE FORM RENDERS INERT AND JAVASCRIPT ENABLES IT. If initialisation never
 * happens the visitor is told so, rather than being shown a form that looks
 * fine and silently is not.
 *
 * This is not primarily about cookie banners. ANY JS failure produces the same
 * dead form — a theme conflict, another plugin's uncaught error, an ad-blocker,
 * a CDN hiccup. We ship into WordPress installs we have never seen, and a form
 * that lies about working is the same class of failure as a false success.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Shared renderer.
 */
final class Spotship_WI_Renderer {

	/**
	 * How many forms this request has already rendered.
	 *
	 * Element ids must be unique within a DOCUMENT, and a page may legitimately
	 * carry more than one form: hull and cargo on one "Insurance" page, or the
	 * same shortcode twice. Every id below is therefore namespaced with this
	 * counter. Without it the second form's labels resolve to the first form's
	 * inputs -- clicking a label focuses the wrong field -- which is a WCAG
	 * 1.3.1 and 4.1.2 failure, not a cosmetic one.
	 *
	 * A counter rather than the form uuid because ids only need to be unique
	 * within one render pass, and short readable ids keep the markup legible.
	 *
	 * @var int
	 */
	private static int $instances = 0;

	/**
	 * Characters required before an autocomplete will search.
	 *
	 * ONE DEFINITION, because two consumers now read it: the script uses it as
	 * the threshold, and the sentence announced below the threshold states it.
	 * A literal in each is how a control ends up telling a screen reader to type
	 * two characters while waiting for three.
	 *
	 * @var int
	 */
	public const MIN_QUERY_LENGTH = 2;

	/**
	 * Render a product form.
	 *
	 * @param string $product 'hull' or 'cargo'.
	 */
	public static function render( string $product ): string {
		$product = 'cargo' === $product ? 'cargo' : 'hull';

		/*
		 * Enqueued HERE rather than on wp_enqueue_scripts, so a page without a form
		 * loads neither asset. WordPress permits a late enqueue for footer scripts,
		 * which is why the script is registered with in_footer.
		 */
		wp_enqueue_script( 'spotship-wi' );
		wp_enqueue_style( 'spotship-wi' );

		$timestamp = time();
		$form_id   = wp_generate_uuid4();

		++self::$instances;

		$data = array(
			'product'   => $product,
			'uid'       => 'sswi-' . self::$instances,
			'form_id'   => $form_id,
			'timestamp' => $timestamp,
			'signature' => Spotship_WI_Request_Guard::sign( $timestamp, $form_id ),
			'config'    => self::config( $product ),
			'serving'   => Spotship_WI_Rates_Cache::get(),
		);

		ob_start();

		$template = SPOTSHIP_WI_PATH . 'templates/' . $product . '.php';

		if ( is_readable( $template ) ) {
			include $template;
		}

		return (string) ob_get_clean();
	}

	/**
	 * The configuration the browser is given.
	 *
	 * EVERY bound `validate()` enforces is published here. That matters beyond
	 * convenience: it is what makes hoisting bounds validation above the
	 * anti-bot gates leak nothing, because a bot can already read every limit
	 * off the page source. Removing this mirror as a "simplification" would
	 * silently falsify that reasoning and make MAX_DESTINATIONS — which does not
	 * appear in the upstream schema — probeable.
	 *
	 * @param string $product 'hull' or 'cargo'.
	 * @return array<string, mixed>
	 */
	public static function config( string $product ): array {
		return array(
			'root'            => esc_url_raw( rest_url( Spotship_WI_Rest_Proxy::NAMESPACE_V1 ) ),
			'product'         => $product,

			/*
			 * `minAgeSeconds` USED TO BE PUBLISHED HERE. It was never read.
			 *
			 * No code in assets/js/war-insurance.js consumed it, so it bought
			 * nothing — and it published the decoy's exact threshold into the
			 * page source, which is the one parameter a bot author needs: sleep
			 * for that long and the timing trap never fires. A guard that tells
			 * you how to get past it is not a guard.
			 *
			 * The same reasoning is already written down two paragraphs up for
			 * MAX_DESTINATIONS. This value simply predated it.
			 */
			'minQueryLength'  => self::MIN_QUERY_LENGTH,
			'maxDestinations' => Spotship_WI_Payloads::MAX_DESTINATIONS,
			'bounds'          => array(
				'userName'         => Spotship_WI_Payloads::MAX_NAME,
				'userEmail'        => Spotship_WI_Payloads::MAX_EMAIL,
				'userPhone'        => Spotship_WI_Payloads::MAX_PHONE,
				'notes'            => Spotship_WI_Payloads::MAX_FREE_TEXT,
				'handmValue'       => Spotship_WI_Payloads::MAX_HANDM_VALUE,
				'increasedValue'   => Spotship_WI_Payloads::MAX_INCREASED_VALUE,
				'cargoDescription' => Spotship_WI_Payloads::MAX_FREE_TEXT,
				'cargoWeight'      => Spotship_WI_Payloads::MAX_CARGO_WEIGHT,
				'cargoCurrency'    => Spotship_WI_Payloads::MAX_CURRENCY,
				'riskZone'         => Spotship_WI_Payloads::MAX_RISK_ZONE,
				'insuredParty'     => Spotship_WI_Payloads::MAX_INSURED_PARTY,
			),
			'i18n'            => array(
				'unavailable'  => __( 'This form is temporarily unavailable. Please try again shortly.', 'spotship-war-insurance' ),
				'sending'      => __( 'Sending your request…', 'spotship-war-insurance' ),
				'noResults'    => __( 'No matches found.', 'spotship-war-insurance' ),
				'lookupFailed' => __( 'Search is unavailable, so this form cannot be submitted right now.', 'spotship-war-insurance' ),
				'errorSummary' => __( 'Please correct the following:', 'spotship-war-insurance' ),
				/* translators: %s: the name of the destination being removed. */
				'removeChip'   => __( 'Remove %s', 'spotship-war-insurance' ),
				/* translators: %d: number of autocomplete results. */
				'resultCount'  => __( '%d results available.', 'spotship-war-insurance' ),

				/*
				 * "%d or more", NOT "at least %d characters", because the number
				 * is substituted and the noun would then have to agree with it.
				 * bin/extract-strings.js matches single-string gettext calls and
				 * the catalogue it writes has no plural entries, so an `_n()`
				 * here would be invisible to the i18n job — a missing string in
				 * the one place built to notice missing strings.
				 */
				/* translators: %d: minimum number of characters before an autocomplete searches. */
				'minChars'     => sprintf( __( 'Type %d or more characters to search.', 'spotship-war-insurance' ), self::MIN_QUERY_LENGTH ),
				'expired'      => __( 'This form has been open too long. Please reload the page and try again.', 'spotship-war-insurance' ),
				/* translators: %d: number of seconds to wait before retrying. */
				'throttled'    => __( 'Too many requests. Please wait %d seconds and try again.', 'spotship-war-insurance' ),
			),
		);
	}

	/**
	 * The consent sentence, recorded verbatim with every submission.
	 *
	 * Read from ONE place so the text stored as `consentText` is provably the
	 * text the visitor was shown. A second copy in a template would let the two
	 * drift, and the stored consent record would then be evidence of wording
	 * nobody ever saw.
	 */
	public static function consent_text(): string {
		return __(
			'I consent to Spot Ship storing and processing the details above in order to respond to this enquiry.',
			'spotship-war-insurance'
		);
	}

	/**
	 * Render an upstream rate as the percentage a visitor reads.
	 *
	 * THE ENDPOINT SENDS A NUMBER, NOT A STRING. `rates_payload()` puts the raw
	 * `Decimal` in the body and DRF renders it as a JSON number, so `hmRate` for
	 * the Hormuz Passage arrives as `0.085` — not `"8.50%"`. Echoing it straight
	 * into a cell publishes "0.085" as a war-risk rate on a customer's public
	 * website, which is wrong by two orders of magnitude and commercially
	 * dangerous rather than merely untidy.
	 *
	 * The rule is the backend's `format_rate_percent()` and serica's
	 * `Intl.NumberFormat("en-US", {minimumFractionDigits: 2,
	 * maximumFractionDigits: 3})`, which are already the same rule: multiply by
	 * 100, round half-up to three decimals, then pad to at least two. The three
	 * surfaces publishing these figures must agree to the digit.
	 *
	 * `null` is a real value here — cargo's Hormuz row has no net rate — and is
	 * NOT the same as a key the payload never carried. Both render as an em
	 * dash, but only because that is what an absent figure looks like.
	 *
	 * @param mixed $rate Upstream rate value.
	 * @return string Formatted percentage, or an em dash when there is no figure.
	 */
	public static function rate_percent( $rate ): string {
		if ( null === $rate || '' === $rate || ! is_numeric( $rate ) ) {
			return '—';
		}

		$percent = round( (float) $rate * 100, 3 );

		/*
		 * `number_format` rounds half-up on the string, matching Python's
		 * ROUND_HALF_UP, then the trailing zeros beyond the second decimal come
		 * off — 8.500 reads as 8.50, 0.250 as 0.25, and 0.2375 keeps all three.
		 */
		$formatted = number_format( $percent, 3, '.', '' );

		if ( str_ends_with( $formatted, '0' ) ) {
			$formatted = substr( $formatted, 0, -1 );
		}

		return $formatted . '%';
	}

	/**
	 * The honeypot field.
	 *
	 * Visually hidden but LABELLED and marked `aria-hidden="false"` with an
	 * explicit instruction, so a screen-reader user who reaches it is told to
	 * leave it blank rather than silently failing the check. `autocomplete=off`
	 * and a name a password manager will not recognise keep browsers from
	 * filling it on a real visitor's behalf.
	 *
	 * @param string $uid Per-instance id prefix from `$data['uid']`.
	 */
	public static function honeypot( string $uid ): string {
		$field_id = $uid . '-website';

		ob_start();
		?>
		<div class="sswi-hp" aria-hidden="false">
			<label for="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Leave this field empty', 'spotship-war-insurance' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="sswi_website" value="" autocomplete="off" tabindex="-1">
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
