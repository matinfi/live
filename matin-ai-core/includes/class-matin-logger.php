<?php
defined( 'ABSPATH' ) || exit;

/**
 * Operational log stored in a custom table. Sensitive values are redacted before anything is written.
 */
final class Matin_AI_Logger {
	public const TABLE = 'matin_ai_logs';
	private const SENSITIVE_KEYS = '/(api[_-]?key|token|secret|password|passwd|nonce|authorization|otp|cookie)/i';

	public static function table() : string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() : void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE " . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(12) NOT NULL,
			channel varchar(32) NOT NULL,
			message text NOT NULL,
			context longtext NULL,
			reservation_id bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level (level)
		) $charset;" );
	}

	public static function log( string $level, string $channel, string $message, array $context = [], ?int $reservation_id = null ) : void {
		global $wpdb;
		if ( ! $wpdb ) {
			return;
		}
		$wpdb->insert( self::table(), [
			'created_at'     => current_time( 'mysql' ),
			'level'          => substr( sanitize_key( $level ), 0, 12 ),
			'channel'        => substr( sanitize_key( $channel ), 0, 32 ),
			'message'        => self::redact_text( $message ),
			'context'        => wp_json_encode( self::redact( $context ), JSON_UNESCAPED_UNICODE ),
			'reservation_id' => $reservation_id ?: null,
		] );
	}

	/** Recursively replaces values of sensitive keys; nested depth is capped to keep payloads small. */
	public static function redact( mixed $data, int $depth = 0 ) : mixed {
		if ( $depth > 5 ) {
			return '[depth]';
		}
		if ( is_array( $data ) ) {
			$out = [];
			foreach ( $data as $key => $value ) {
				$out[ $key ] = is_string( $key ) && preg_match( self::SENSITIVE_KEYS, $key ) ? '[redacted]' : self::redact( $value, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $data ) ) {
			return self::redact_text( $data );
		}
		return $data;
	}

	/** Removes key-like query parameters and bearer tokens from free text. */
	public static function redact_text( string $text ) : string {
		$text = preg_replace( '/([?&](?:key|api_key|token|access_token|code)=)[^&\s"\']+/i', '$1[redacted]', $text ) ?? $text;
		$text = preg_replace( '/(bearer\s+)[A-Za-z0-9._\-]+/i', '$1[redacted]', $text ) ?? $text;
		return mb_substr( $text, 0, 2000, 'UTF-8' );
	}

	public static function prune( int $days ) : int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff ) );
	}

	/** @return array<int, object> */
	public static function query( string $level = '', string $channel = '', int $limit = 100 ) : array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE 1=1';
		$args = [];
		if ( '' !== $level ) {
			$sql .= ' AND level = %s';
			$args[] = $level;
		}
		if ( '' !== $channel ) {
			$sql .= ' AND channel = %s';
			$args[] = $channel;
		}
		$sql .= ' ORDER BY id DESC LIMIT %d';
		$args[] = max( 1, min( 500, $limit ) );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
	}
}
