<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ticket signing and SVG rendering. Tickets are produced by the site, never by the AI.
 * Pure helpers (sign/verify/render) are separated from WordPress glue so they can be tested.
 */
final class Matin_AI_Tickets {

	public static function init() : void {
		add_action( 'template_redirect', [ __CLASS__, 'handle_download' ] );
	}

	/** Site-wide signing secret, generated once and never autoloaded. */
	public static function secret() : string {
		$secret = (string) get_option( 'matin_ai_secret', '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			add_option( 'matin_ai_secret', $secret, '', false );
		}
		return $secret;
	}

	/** Unescaped URL: callers escape it for their own context (esc_url in HTML, JSON otherwise). */
	public static function download_url( int $reservation_id, string $kind ) : string {
		return add_query_arg(
			[ 'matin_download' => $kind, 'reservation' => $reservation_id, '_wpnonce' => wp_create_nonce( 'matin_download_' . $reservation_id ) ],
			home_url( '/' )
		);
	}

	/**
	 * Serves a ticket or cancellation SVG. Only the reservation owner or a site admin may download,
	 * and only with a valid per-reservation nonce.
	 */
	public static function handle_download() : void {
		if ( empty( $_GET['matin_download'] ) ) {
			return;
		}
		$kind = sanitize_key( wp_unslash( $_GET['matin_download'] ) );
		$id   = absint( $_GET['reservation'] ?? 0 );
		if ( ! in_array( $kind, [ 'ticket', 'cancellation' ], true ) || ! $id || 'matin_reservation' !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'درخواست نامعتبر است.', 'matin-ai' ), '', [ 'response' => 404 ] );
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/my-account/' ) ) );
			exit;
		}
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'matin_download_' . $id ) ) {
			wp_die( esc_html__( 'لینک منقضی شده است. صفحه را دوباره باز کنید.', 'matin-ai' ), '', [ 'response' => 403 ] );
		}
		$owner = (int) get_post_meta( $id, '_matin_user_id', true );
		if ( get_current_user_id() !== $owner && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی به این بلیت مجاز نیست.', 'matin-ai' ), '', [ 'response' => 403 ] );
		}
		$status = (string) get_post_meta( $id, '_matin_status', true );
		if ( 'ticket' === $kind && ! in_array( $status, [ 'pending', 'confirmed' ], true ) ) {
			wp_die( esc_html__( 'این بلیت نامعتبر است.', 'matin-ai' ), '', [ 'response' => 410 ] );
		}
		if ( 'cancellation' === $kind && 'cancelled' !== $status ) {
			wp_die( esc_html__( 'تأییدیه لغو فقط برای رزروهای لغوشده صادر می‌شود.', 'matin-ai' ), '', [ 'response' => 409 ] );
		}

		$event_id = (int) get_post_meta( $id, '_matin_event_id', true );
		$cafe_id  = (int) get_post_meta( $event_id, '_matin_cafe_id', true );
		$code     = (string) get_post_meta( $id, '_matin_ticket_code', true );
		$options  = Matin_AI_Admin::get_options();
		if ( 'cancellation' === $kind ) {
			$notice = 'این رزرو لغو شده و بلیت قبلی نامعتبر است.';
		} elseif ( 'confirmed' === $status ) {
			$notice = 'بلیت قطعی است. کد اعتبارسنجی را هنگام ورود نشان دهید.';
		} else {
			$notice = (string) $options['ticket_note'];
		}
		$svg = self::render_svg( [
			'kind'           => $kind,
			'brand'          => 'Matin AI',
			'cafe'           => get_the_title( $cafe_id ),
			'event'          => get_the_title( $event_id ),
			'starts'         => (string) get_post_meta( $event_id, '_matin_starts_at', true ),
			'reserved_at'    => (string) get_post_meta( $id, '_matin_created_at', true ),
			'guest'          => self::mask_name( (string) get_post_meta( $id, '_matin_name', true ) ),
			'guests'         => (string) get_post_meta( $id, '_matin_guests', true ),
			'reservation_id' => (string) $id,
			'ticket_code'    => $code,
			'status_label'   => Matin_AI_Rules::status_label( $status ),
			'validation'     => self::validation_code( $id, $code, self::secret() ),
			'notice'         => $notice,
		] );
		nocache_headers();
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="matin-' . $kind . '-' . $id . '.svg"' );
		echo $svg; // Output is fully escaped in render_svg().
		exit;
	}

	/** HMAC-based validation code, grouped for reading aloud: XXXX-XXXX-XXXX-XXXX. */
	public static function validation_code( int $reservation_id, string $ticket_code, string $secret ) : string {
		$raw = strtoupper( substr( hash_hmac( 'sha256', $reservation_id . '|' . $ticket_code, $secret ), 0, 16 ) );
		return implode( '-', str_split( $raw, 4 ) );
	}

	public static function verify_validation_code( string $given, int $reservation_id, string $ticket_code, string $secret ) : bool {
		$expected   = self::validation_code( $reservation_id, $ticket_code, $secret );
		$normalized = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $given ) );
		return hash_equals( $expected, implode( '-', str_split( $normalized, 4 ) ) );
	}

	/** Privacy: show the first name and an initial only, e.g. "علی ا." */
	public static function mask_name( string $name ) : string {
		$parts = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( ! $parts ) {
			return '';
		}
		if ( count( $parts ) === 1 ) {
			return $parts[0];
		}
		return $parts[0] . ' ' . mb_substr( end( $parts ), 0, 1, 'UTF-8' ) . '.';
	}

	/**
	 * Renders a ticket or cancellation confirmation as a standalone SVG image.
	 * All values are XML-escaped; no remote resources are referenced.
	 *
	 * @param array<string,string|int> $t Keys: kind, brand, cafe, event, starts, reserved_at, guest, guests,
	 *                                    reservation_id, ticket_code, status_label, validation, notice.
	 */
	public static function render_svg( array $t ) : string {
		$e = static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$is_cancel = ( $t['kind'] ?? '' ) === 'cancellation';
		$title     = $is_cancel ? 'تأییدیه لغو رزرو' : 'بلیت رزرو';
		$accent    = $is_cancel ? '#a84235' : '#314a2d';
		$rows = [
			[ 'کافه', $t['cafe'] ?? '' ],
			[ 'برنامه', $t['event'] ?? '' ],
			[ 'زمان برنامه', $t['starts'] ?? '' ],
			[ 'تاریخ ثبت رزرو', $t['reserved_at'] ?? '' ],
			[ 'نام مهمان', $t['guest'] ?? '' ],
			[ 'تعداد نفرات', $t['guests'] ?? '' ],
			[ 'شناسه رزرو', $t['reservation_id'] ?? '' ],
			[ 'کد بلیت', $t['ticket_code'] ?? '' ],
			[ 'وضعیت', $t['status_label'] ?? '' ],
		];
		$body = '';
		$y = 150;
		foreach ( $rows as [ $label, $value ] ) {
			$body .= '<text x="660" y="' . $y . '" direction="rtl" text-anchor="end" class="l">' . $e( $label ) . '</text>';
			$body .= '<text x="40" y="' . $y . '" direction="rtl" text-anchor="start" class="v">' . $e( mb_substr( (string) $value, 0, 60, 'UTF-8' ) ) . '</text>';
			$y += 36;
		}
		$notice = $e( $t['notice'] ?? '' );
		$validation = $e( $t['validation'] ?? '' );
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="720" height="560" viewBox="0 0 720 560" role="img" aria-label="' . $e( $title ) . '">'
			. '<style>text{font-family:Vazirmatn,Tahoma,Arial,sans-serif}.l{font-size:15px;fill:#687068}.v{font-size:17px;font-weight:700;fill:#202521}.h{font-size:24px;font-weight:800;fill:#fff}.s{font-size:13px;fill:#4a5248}</style>'
			. '<rect width="720" height="560" rx="28" fill="#fcfbf8"/>'
			. '<rect width="720" height="92" rx="28" fill="' . $accent . '"/><rect y="60" width="720" height="32" fill="' . $accent . '"/>'
			. '<text x="660" y="56" direction="rtl" text-anchor="end" class="h">' . $e( $title ) . '</text>'
			. '<text x="40" y="56" text-anchor="start" class="h">Matin AI</text>'
			. '<line x1="40" y1="128" x2="680" y2="128" stroke="#dfe1da" stroke-width="1"/>'
			. $body
			. '<line x1="40" y1="470" x2="680" y2="470" stroke="#dfe1da" stroke-width="1" stroke-dasharray="6 6"/>'
			. '<text x="660" y="500" direction="rtl" text-anchor="end" class="l">کد اعتبارسنجی</text>'
			. '<text x="40" y="500" text-anchor="start" class="v" letter-spacing="2">' . $validation . '</text>'
			. '<text x="360" y="535" text-anchor="middle" class="s">' . $notice . '</text>'
			. '</svg>';
	}
}
