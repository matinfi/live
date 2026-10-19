<?php
defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp message queue for customer and cafe notifications.
 *
 * Each recipient gets its own row with its own status:
 *  - queued        waiting to be sent
 *  - sent          accepted by the WhatsApp Cloud API (provider message id stored)
 *  - failed        API error; retried by cron up to MAX_ATTEMPTS, or manually from the admin
 *  - manual        no API configured: a wa.me link is prepared for a person to send
 *  - manual_done   an admin confirmed that the manual send was made
 * "manual" is never reported as an automatic success.
 */
final class Matin_AI_Messaging {
	public const TABLE        = 'matin_ai_messages';
	public const MAX_ATTEMPTS = 5;
	private const CRON_HOOK   = 'matin_ai_retry_messages';

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function init() : void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'retry_failed' ] );
	}

	public static function install() : void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE " . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reservation_id bigint(20) unsigned NOT NULL,
			recipient varchar(16) NOT NULL,
			type varchar(32) NOT NULL,
			phone varchar(20) NOT NULL DEFAULT '',
			body text NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'queued',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			provider_id varchar(100) NOT NULL DEFAULT '',
			last_error varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY reservation_id (reservation_id),
			KEY status (status)
		) $charset;" );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'matin_ai_quarter_hour', self::CRON_HOOK );
		}
	}

	public static function uninstall_schedule() : void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Reservation notifications for the customer and the cafe. Called after confirmation or cancellation. */
	public static function notify_reservation( int $reservation_id, string $event ) : void {
		$r = self::reservation_context( $reservation_id );
		if ( ! $r ) {
			return;
		}
		$manage_url = home_url( '/my-account/?matin_tab=reservations&id=' . $reservation_id );
		if ( 'confirmed' === $event ) {
			$customer = "Matin AI — رزرو شما تأیید شد ✅\nبرنامه: {$r['event']}\nکافه: {$r['cafe']}\nزمان: {$r['starts']}\nنفرات: {$r['guests']}\nشناسه رزرو: {$reservation_id}\nکد بلیت: {$r['ticket_code']}\nبلیت: {$manage_url}";
			$cafe     = "Matin AI — رزرو جدید تأییدشده\nبرنامه: {$r['event']}\nزمان: {$r['starts']}\nنفرات: {$r['guests']}\nشناسه رزرو: {$reservation_id}\nکد بلیت: {$r['ticket_code']}\nمهمان: {$r['guest']}";
			$type = 'reservation_confirmed';
		} else {
			$customer = "Matin AI — رزرو شما لغو شد\nبرنامه: {$r['event']}\nزمان: {$r['starts']}\nشناسه رزرو: {$reservation_id}\nبلیت قبلی نامعتبر شد. تأییدیه لغو: {$manage_url}";
			$cafe     = "Matin AI — رزرو لغو شد\nبرنامه: {$r['event']}\nزمان: {$r['starts']}\nنفرات آزادشده: {$r['guests']}\nشناسه رزرو: {$reservation_id}";
			$type = 'reservation_cancelled';
		}
		$ids = [];
		$ids[] = self::enqueue( $reservation_id, 'customer', $type, $r['phone'], $customer );
		$ids[] = self::enqueue( $reservation_id, 'cafe', $type, $r['cafe_phone'], $cafe );
		foreach ( array_filter( $ids ) as $id ) {
			self::dispatch( (int) $id );
		}
	}

	public static function enqueue( int $reservation_id, string $recipient, string $type, string $phone, string $body ) : int {
		global $wpdb;
		$digits = preg_replace( '/\D/', '', $phone );
		$now    = current_time( 'mysql' );
		$wpdb->insert( self::table(), [
			'reservation_id' => $reservation_id,
			'recipient'      => 'cafe' === $recipient ? 'cafe' : 'customer',
			'type'           => sanitize_key( $type ),
			'phone'          => substr( (string) $digits, 0, 20 ),
			'body'           => $body,
			'status'         => 'queued',
			'created_at'     => $now,
			'updated_at'     => $now,
		] );
		return (int) $wpdb->insert_id;
	}

	/** Sends one queued message, or prepares a manual link when no API is configured. */
	public static function dispatch( int $message_id ) : void {
		global $wpdb;
		$m = self::get( $message_id );
		if ( ! $m || ! in_array( $m->status, [ 'queued', 'failed' ], true ) ) {
			return;
		}
		$now = current_time( 'mysql' );
		if ( '' === $m->phone ) {
			self::update( $message_id, [ 'status' => 'failed', 'last_error' => 'شماره مقصد ثبت نشده است.', 'updated_at' => $now, 'attempts' => (int) $m->attempts + 1 ] );
			return;
		}
		$api = self::api_settings();
		if ( ! $api['ready'] ) {
			self::update( $message_id, [ 'status' => 'manual', 'last_error' => '', 'updated_at' => $now ] );
			return;
		}
		$result = self::send_api( $m->phone, $m->body, $api );
		$attempts = (int) $m->attempts + 1;
		if ( $result['ok'] ) {
			self::update( $message_id, [ 'status' => 'sent', 'provider_id' => substr( $result['id'], 0, 100 ), 'last_error' => '', 'attempts' => $attempts, 'updated_at' => $now ] );
		} else {
			self::update( $message_id, [ 'status' => 'failed', 'last_error' => substr( $result['error'], 0, 500 ), 'attempts' => $attempts, 'updated_at' => $now ] );
			Matin_AI_Logger::log( 'error', 'whatsapp', 'ارسال پیام واتساپ ناموفق بود.', [ 'message_id' => $message_id, 'error' => $result['error'] ], (int) $m->reservation_id );
		}
	}

	public static function retry_failed() : void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE status = %s AND attempts < %d ORDER BY id ASC LIMIT 20', 'failed', self::MAX_ATTEMPTS ) );
		foreach ( $ids as $id ) {
			self::dispatch( (int) $id );
		}
	}

	/** Admin action: an operator sent the manual link by hand. Recorded as manual_done, not as an API success. */
	public static function mark_manual_done( int $message_id ) : bool {
		$m = self::get( $message_id );
		if ( ! $m || 'manual' !== $m->status ) {
			return false;
		}
		return (bool) self::update( $message_id, [ 'status' => 'manual_done', 'updated_at' => current_time( 'mysql' ) ] );
	}

	public static function reset_for_retry( int $message_id ) : bool {
		$m = self::get( $message_id );
		if ( ! $m || 'failed' !== $m->status ) {
			return false;
		}
		self::update( $message_id, [ 'attempts' => 0, 'updated_at' => current_time( 'mysql' ) ] );
		self::dispatch( $message_id );
		return true;
	}

	public static function wa_link( string $phone, string $body ) : string {
		return 'https://wa.me/' . preg_replace( '/\D/', '', $phone ) . '?text=' . rawurlencode( $body );
	}

	/** @return array<int, object> */
	public static function list( string $status = '', int $limit = 100 ) : array {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table() . ' WHERE 1=1';
		$args = [];
		if ( '' !== $status ) {
			$sql .= ' AND status = %s';
			$args[] = $status;
		}
		$sql .= ' ORDER BY id DESC LIMIT %d';
		$args[] = max( 1, min( 300, $limit ) );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
	}

	/** Latest delivery state (Persian label) for one recipient of a reservation. */
	public static function status_for_reservation( int $reservation_id, string $recipient = 'customer' ) : string {
		global $wpdb;
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::table() . ' WHERE reservation_id = %d AND recipient = %s ORDER BY id DESC LIMIT 1', $reservation_id, $recipient ) );
		$labels = [ 'queued' => 'در صف ارسال', 'sent' => 'ارسال شد', 'failed' => 'ناموفق (در حال تلاش مجدد)', 'manual' => 'آماده ارسال دستی', 'manual_done' => 'ارسال دستی ثبت شد' ];
		return $labels[ (string) $row ] ?? '—';
	}

	public static function get( int $id ) : ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return $row ?: null;
	}

	private static function update( int $id, array $data ) : int|false {
		global $wpdb;
		return $wpdb->update( self::table(), $data, [ 'id' => $id ] );
	}

	/** @return array{ready: bool, phone_id: string, token: string} */
	private static function api_settings() : array {
		$o     = Matin_AI_Admin::get_options();
		$token = Matin_AI_Secrets::decrypt( (string) ( $o['whatsapp_token'] ?? '' ), self::secret_material() );
		$phone = preg_replace( '/\D/', '', (string) ( $o['whatsapp_phone_id'] ?? '' ) );
		return [ 'ready' => ! empty( $o['whatsapp_enabled'] ) && '' !== $token && '' !== $phone, 'phone_id' => $phone, 'token' => $token ];
	}

	/** @return array{ok: bool, id: string, error: string} */
	private static function send_api( string $to, string $body, array $api ) : array {
		$response = wp_remote_post( 'https://graph.facebook.com/v21.0/' . $api['phone_id'] . '/messages', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . $api['token'], 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => [ 'body' => $body ] ] ),
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'id' => '', 'error' => 'اتصال به WhatsApp برقرار نشد.' ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && ! empty( $data['messages'][0]['id'] ) ) {
			return [ 'ok' => true, 'id' => (string) $data['messages'][0]['id'], 'error' => '' ];
		}
		$error = (string) ( $data['error']['message'] ?? 'خطای ناشناخته از WhatsApp (HTTP ' . $code . ')' );
		return [ 'ok' => false, 'id' => '', 'error' => $error ];
	}

	public static function secret_material() : string {
		return wp_salt( 'auth' );
	}

	/** @return array<string, string>|null */
	private static function reservation_context( int $reservation_id ) : ?array {
		$event_id = (int) get_post_meta( $reservation_id, '_matin_event_id', true );
		if ( ! $event_id ) {
			return null;
		}
		$cafe_id = (int) get_post_meta( $event_id, '_matin_cafe_id', true );
		return [
			'event'       => get_the_title( $event_id ),
			'cafe'        => get_the_title( $cafe_id ),
			'cafe_phone'  => (string) get_post_meta( $cafe_id, '_matin_cafe_phone', true ),
			'starts'      => (string) get_post_meta( $event_id, '_matin_starts_at', true ),
			'guests'      => (string) get_post_meta( $reservation_id, '_matin_guests', true ),
			'guest'       => Matin_AI_Tickets::mask_name( (string) get_post_meta( $reservation_id, '_matin_name', true ) ),
			'phone'       => (string) get_post_meta( $reservation_id, '_matin_phone', true ),
			'ticket_code' => (string) get_post_meta( $reservation_id, '_matin_ticket_code', true ),
		];
	}
}
