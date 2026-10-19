<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pure business rules (no WordPress calls) so they can be unit-tested without a database.
 */
final class Matin_AI_Rules {
	/** Reservations can be cancelled by the guest only while at least this many seconds remain before the start. */
	public const CANCEL_WINDOW_SECONDS = 21600; // 6 hours

	public const STATUSES = [ 'pending', 'confirmed', 'rejected', 'cancelled' ];

	/** Allowed status transitions. Cancellation never auto-confirms and terminal states stay terminal. */
	private const TRANSITIONS = [
		'pending'   => [ 'confirmed', 'rejected', 'cancelled' ],
		'confirmed' => [ 'cancelled' ],
		'rejected'  => [],
		'cancelled' => [],
	];

	/**
	 * Parses a stored event time. Accepts both the datetime-local form (2026-10-19T20:00)
	 * and the MySQL form (2026-10-19 20:00:00). Returns a Unix timestamp or null.
	 */
	public static function parse_datetime( string $value, DateTimeZone $timezone ) : ?int {
		$value = trim( str_replace( 'T', ' ', $value ) );
		if ( '' === $value ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $timezone )
			?: DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $value, $timezone );
		return $date ? $date->getTimestamp() : null;
	}

	/** True when the guest may cancel: status is cancellable and at least six hours remain (boundary inclusive). */
	public static function can_cancel( string $status, ?int $starts_at, int $now ) : bool {
		if ( ! in_array( $status, [ 'pending', 'confirmed' ], true ) || null === $starts_at ) {
			return false;
		}
		return ( $starts_at - $now ) >= self::CANCEL_WINDOW_SECONDS;
	}

	public static function can_transition( string $from, string $to ) : bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
	}

	/** Persian label for a reservation status, shared by account, admin and receipts. */
	public static function status_label( string $status ) : string {
		$labels = [
			'pending'   => 'در انتظار تأیید کافه',
			'confirmed' => 'تأییدشده',
			'rejected'  => 'ردشده',
			'cancelled' => 'لغوشده',
		];
		return $labels[ $status ] ?? 'در حال بررسی';
	}
}
