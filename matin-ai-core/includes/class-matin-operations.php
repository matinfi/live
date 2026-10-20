<?php
defined( 'ABSPATH' ) || exit;

/** Secure ticket downloads, cancellation rules and minimal operational audit trail. */
final class Matin_AI_Operations {
	public static function init() : void {
		add_action( 'admin_post_matin_ticket', [ __CLASS__, 'download_ticket' ] );
		add_action( 'admin_post_nopriv_matin_ticket', [ __CLASS__, 'deny_ticket' ] );
		add_action( 'admin_post_matin_retry_whatsapp', [ __CLASS__, 'prepare_whatsapp' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'log_reservation_transition' ], 10, 3 );
		if ( get_option( 'matin_ai_audit_version' ) !== self::AUDIT_VERSION ) { add_action( 'init', [ __CLASS__, 'install_audit_table' ], 1 ); }
	}

	public const AUDIT_VERSION = '1';

	public static function audit_table() : string { global $wpdb; return $wpdb->prefix . 'matin_ai_audit'; }

	/** Creates the audit table (also on upgrade, when the stored version differs). */
	public static function install_audit_table() : void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::audit_table();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			event VARCHAR(64) NOT NULL,
			reservation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor BIGINT UNSIGNED NOT NULL DEFAULT 0,
			detail VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};" );
		update_option( 'matin_ai_audit_version', self::AUDIT_VERSION, false );
	}

	/** Single INSERT per event: no read-modify-write, so concurrent requests cannot drop entries. */
	public static function log( string $event, int $reservation_id = 0, string $detail = '' ) : void {
		global $wpdb;
		if ( get_option( 'matin_ai_audit_version' ) !== self::AUDIT_VERSION ) { self::install_audit_table(); }
		$ok = $wpdb->insert( self::audit_table(), [
			'created_at'     => current_time( 'mysql' ),
			'event'          => substr( sanitize_key( $event ), 0, 64 ),
			'reservation_id' => absint( $reservation_id ),
			'actor'          => get_current_user_id(),
			'detail'         => substr( sanitize_text_field( $detail ), 0, 255 ),
		] );
		if ( false === $ok ) { error_log( '[Matin AI] audit insert failed: ' . $event ); return; }
		// Retention: keep the newest 5000 rows; trimmed on about 1 in 100 writes.
		if ( 0 === wp_rand( 0, 99 ) ) {
			$cut = (int) $wpdb->get_var( "SELECT id FROM " . self::audit_table() . " ORDER BY id DESC LIMIT 1 OFFSET 5000" );
			if ( $cut > 0 ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::audit_table() . ' WHERE id <= %d', $cut ) ); }
		}
	}

	/** Newest audit rows first. */
	public static function recent( int $limit = 50 ) : array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT created_at AS time, event, reservation_id, actor, detail FROM ' . self::audit_table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 500, $limit ) ) ), ARRAY_A );
	}

	/** Event start as a Unix timestamp in the site time zone, or null when missing or invalid. */
	public static function event_timestamp( int $event_id ) : ?int {
		$value = (string) get_post_meta( $event_id, '_matin_starts_at', true );
		if ( '' === $value ) { return null; }
		try { return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp(); }
		catch ( Exception $e ) { return null; }
	}

	/** Human-readable event start in the site time zone (e.g. 2026-10-19 20:00), or an empty string. */
	public static function format_event_time( int $event_id ) : string {
		$ts = self::event_timestamp( $event_id );
		return null === $ts ? '' : wp_date( 'Y-m-d H:i', $ts );
	}

	/** Cancellation is allowed only for pending/confirmed reservations at least six hours before the start. */
	public static function cancellation_allowed( int $reservation_id ) : bool {
		if ( 'matin_reservation' !== get_post_type( $reservation_id ) ) { return false; }
		if ( ! in_array( (string) get_post_meta( $reservation_id, '_matin_status', true ), [ 'pending', 'confirmed' ], true ) ) { return false; }
		$start = self::event_timestamp( (int) get_post_meta( $reservation_id, '_matin_event_id', true ) );
		return null !== $start && ( $start - time() ) >= 6 * HOUR_IN_SECONDS;
	}

	public static function cancel( int $reservation_id, int $user_id ) : bool|WP_Error {
		if ( 'matin_reservation' !== get_post_type( $reservation_id ) ) { return new WP_Error( 'matin_reservation_missing', 'رزرو پیدا نشد.', [ 'status' => 404 ] ); }
		$owner = (int) get_post_meta( $reservation_id, '_matin_user_id', true );
		if ( $owner !== $user_id && ! user_can( $user_id, 'manage_options' ) ) { return new WP_Error( 'matin_forbidden', 'اجازه دسترسی به این رزرو را ندارید.', [ 'status' => 403 ] ); }
		if ( ! self::cancellation_allowed( $reservation_id ) ) {
			return new WP_Error( 'matin_cancel_window', 'لغو فقط تا زمانی مجاز است که حداقل ۶ ساعت تا شروع برنامه باقی مانده باشد؛ رزرو لغوشده نیز دوباره لغو نمی‌شود.', [ 'status' => 409 ] );
		}
		// Atomic state change: only one request can move the reservation from pending/confirmed to cancelled.
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = 'cancelled' WHERE post_id = %d AND meta_key = '_matin_status' AND meta_value IN ('pending','confirmed')",
			$reservation_id
		) );
		if ( 1 !== (int) $changed ) {
			return new WP_Error( 'matin_cancel_state', 'این رزرو قبلاً لغو شده یا وضعیت آن تغییر کرده است.', [ 'status' => 409 ] );
		}
		clean_post_cache( $reservation_id );
		update_post_meta( $reservation_id, '_matin_cancelled_at', current_time( 'mysql' ) );
		update_post_meta( $reservation_id, '_matin_ticket_invalidated', 1 );
		self::log( 'reservation_cancelled', $reservation_id, 'cancelled_by_user_or_admin' );
		$event_id = (int) get_post_meta( $reservation_id, '_matin_event_id', true );
		$email    = sanitize_email( (string) get_post_meta( $reservation_id, '_matin_email', true ) );
		if ( is_email( $email ) ) {
			$cancel_sent = wp_mail( $email, 'تأیید لغو رزرو Matin AI', sprintf( "رزرو شما برای «%s» لغو شد. کد رزرو: %s\nرسید لغو در پنل کاربری قابل دریافت است.", get_the_title( $event_id ), (string) get_post_meta( $reservation_id, '_matin_ticket_code', true ) ) );
			update_post_meta( $reservation_id, '_matin_cancel_email_status', $cancel_sent ? 'sent' : 'failed' );
			if ( ! $cancel_sent ) { self::log( 'cancel_email_failed', $reservation_id, 'wp_mail_returned_false' ); }
		}
		return true;
	}

	public static function download_ticket() : void {
		$reservation_id = absint( $_GET['id'] ?? 0 );
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'matin_ticket_' . $reservation_id ) ) { self::deny_ticket(); }
		$owner = (int) get_post_meta( $reservation_id, '_matin_user_id', true );
		if ( 'matin_reservation' !== get_post_type( $reservation_id ) || ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) { wp_die( esc_html__( 'بلیت پیدا نشد یا اجازه دسترسی ندارید.', 'matin-ai' ), '', [ 'response' => 403 ] ); }
		$event_id = (int) get_post_meta( $reservation_id, '_matin_event_id', true );
		$event_title = get_the_title( $event_id ) ?: 'برنامه Matin AI';
		$cafe_id = (int) get_post_meta( $event_id, '_matin_cafe_id', true );
		$cafe = get_the_title( $cafe_id ) ?: 'کافه';
		$status = (string) get_post_meta( $reservation_id, '_matin_status', true );
		$cancelled = 'cancelled' === $status || get_post_meta( $reservation_id, '_matin_ticket_invalidated', true );
		$label = $cancelled ? 'رسید لغو رزرو — بلیت ورود نامعتبر است' : ( 'confirmed' === $status ? 'بلیت تأییدشده' : 'رسید درخواست — در انتظار تأیید کافه' );
		$starts = (string) get_post_meta( $event_id, '_matin_starts_at', true );
		$code = (string) get_post_meta( $reservation_id, '_matin_ticket_code', true );
		$name = (string) get_post_meta( $reservation_id, '_matin_name', true );
		$guests = (int) get_post_meta( $reservation_id, '_matin_guests', true );
		$color = $cancelled ? '#b42318' : '#d4af37';
		$ticket_options = class_exists( 'Matin_AI_Admin' ) ? Matin_AI_Admin::get_options() : [];
		$ticket_title = (string) ( $ticket_options['ticket_title'] ?? 'بلیت رزرو Matin AI' );
		$ticket_note = (string) ( $ticket_options['ticket_note'] ?? '' );
		$ticket_footer = (string) ( $ticket_options['ticket_footer'] ?? '' );
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="700" viewBox="0 0 1200 700"><rect width="1200" height="700" rx="36" fill="#10131a"/><rect x="24" y="24" width="1152" height="652" rx="28" fill="none" stroke="' . $color . '" stroke-width="3"/><text x="600" y="100" font-family="Arial,sans-serif" font-size="42" fill="#d4af37" text-anchor="middle" font-weight="700">MATIN AI</text><text x="600" y="165" font-family="Arial,sans-serif" font-size="28" fill="#ffffff" text-anchor="middle">' . self::xml( $ticket_title ?: $label ) . '</text><path d="M80 205 H1120" stroke="#343946" stroke-width="2"/><g font-family="Arial,sans-serif" fill="#f3f4f6"><text x="1080" y="270" font-size="24" text-anchor="end">کافه: ' . self::xml( $cafe ) . '</text><text x="1080" y="330" font-size="24" text-anchor="end">برنامه: ' . self::xml( $event_title ) . '</text><text x="1080" y="390" font-size="24" text-anchor="end">زمان: ' . self::xml( self::format_event_time( $event_id ) ?: $starts ) . '</text><text x="1080" y="450" font-size="24" text-anchor="end">مهمان: ' . self::xml( $name ) . '</text><text x="1080" y="510" font-size="24" text-anchor="end">تعداد نفرات: ' . $guests . '</text></g><rect x="80" y="560" width="1040" height="70" rx="12" fill="#202530"/><text x="600" y="605" font-family="Arial,sans-serif" font-size="24" fill="#ffffff" text-anchor="middle">شناسه رزرو: ' . self::xml( $code ) . '</text><text x="600" y="655" font-size="18" fill="#d1d5db" text-anchor="middle">' . self::xml( $ticket_note . ' ' . $ticket_footer ) . '</text></svg>';
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="matin-' . ( $cancelled ? 'cancellation-' : 'ticket-' ) . absint( $reservation_id ) . '.svg"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $svg; // All interpolated strings are XML-escaped above.
		exit;
	}

	private static function xml( string $value ) : string { return htmlspecialchars( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_XML1, 'UTF-8' ); }
	public static function deny_ticket() : void { wp_die( esc_html__( 'برای دریافت بلیت وارد حساب کاربری شوید.', 'matin-ai' ), '', [ 'response' => 401 ] ); }

	public static function prepare_whatsapp() : void {
		$reservation_id = absint( $_GET['id'] ?? 0 );
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'matin_whatsapp_' . $reservation_id ) ) { wp_die( 'اجازه انجام این عملیات را ندارید.', '', [ 'response' => 403 ] ); }
		$reservation_status = (string) get_post_meta( $reservation_id, '_matin_status', true );
		if ( ! in_array( $reservation_status, [ 'confirmed', 'cancelled' ], true ) ) { wp_die( 'پیام واتساپ فقط برای رزرو تأییدشده یا لغوشده آماده می‌شود.', '', [ 'response' => 409 ] ); }
		$event_id = (int) get_post_meta( $reservation_id, '_matin_event_id', true ); $cafe_id = (int) get_post_meta( $event_id, '_matin_cafe_id', true );
		$target = sanitize_key( wp_unslash( $_GET['target'] ?? 'customer' ) );
		if ( ! in_array( $target, [ 'customer', 'cafe' ], true ) ) { $target = 'customer'; }
		$phone_source = 'cafe' === $target ? ( get_post_meta( $cafe_id, '_matin_cafe_whatsapp', true ) ?: get_post_meta( $cafe_id, '_matin_cafe_phone', true ) ) : get_post_meta( $reservation_id, '_matin_phone', true );
		$phone = preg_replace( '/\D+/', '', (string) $phone_source );
		$code = (string) get_post_meta( $reservation_id, '_matin_ticket_code', true );
		$status_text = 'cancelled' === $reservation_status ? 'رزرو لغو شد؛ بلیت قبلی نامعتبر است' : 'رزرو تأیید شد';
		if ( 'cafe' === $target ) {
			$message = sprintf( "Matin AI — %s\nکافه: %s\nبرنامه: %s\nزمان: %s\nمهمان: %s\nشماره تماس مهمان: %s\nتعداد نفرات: %d\nشناسه رزرو: %s", $status_text, get_the_title( $cafe_id ), get_the_title( $event_id ), ( self::format_event_time( $event_id ) ?: (string) get_post_meta( $event_id, '_matin_starts_at', true ) ), (string) get_post_meta( $reservation_id, '_matin_name', true ), (string) get_post_meta( $reservation_id, '_matin_phone', true ), (int) get_post_meta( $reservation_id, '_matin_guests', true ), $code );
		} else {
			$message = sprintf( "Matin AI — %s\nکافه: %s\nبرنامه: %s\nزمان: %s\nتعداد نفرات: %d\nشناسه رزرو: %s\nجزئیات و رسید را از پنل کاربری دریافت کنید.", $status_text, get_the_title( $cafe_id ), get_the_title( $event_id ), ( self::format_event_time( $event_id ) ?: (string) get_post_meta( $event_id, '_matin_starts_at', true ) ), (int) get_post_meta( $reservation_id, '_matin_guests', true ), $code );
		}
		if ( '' === $phone ) { self::log( 'whatsapp_prepare_failed', $reservation_id, $target . '_missing_phone' ); wp_die( 'شماره واتساپ معتبر برای این گیرنده ثبت نشده است؛ پیام ارسال نشد.', '', [ 'response' => 422 ] ); }
		update_post_meta( $reservation_id, '_matin_whatsapp_' . $target . '_status', 'manual_prepared_not_sent' );
		update_post_meta( $reservation_id, '_matin_whatsapp_' . $target . '_prepared_at', current_time( 'mysql' ) );
		self::log( 'whatsapp_manual_prepared', $reservation_id, $target . '_manual_not_sent' );
		$url = 'https://wa.me/' . $phone . '?text=' . rawurlencode( $message );
		wp_redirect( esc_url_raw( $url ) ); exit;
	}

	public static function log_reservation_transition( string $new_status, string $old_status, WP_Post $post ) : void {
		if ( 'matin_reservation' !== $post->post_type || $new_status === $old_status ) { return; }
		self::log( 'reservation_post_status_transition', (int) $post->ID, $old_status . '_to_' . $new_status );
	}
}
