<?php
defined( 'ABSPATH' ) || exit;

/**
 * Mobile number verification with a one-time code sent by SMS (Kavenegar REST API).
 * Codes are stored hashed, expire after 5 minutes, allow 5 attempts and can be requested once per minute.
 */
final class Matin_AI_Sms {
	public const CODE_TTL      = 300;
	public const MAX_ATTEMPTS  = 5;
	public const COOLDOWN      = 60;
	private const META_OTP     = 'matin_otp';
	private const META_SENT_AT = 'matin_otp_sent_at';

	public static function is_configured() : bool {
		$o = Matin_AI_Admin::get_options();
		return '' !== Matin_AI_Secrets::decrypt( (string) ( $o['sms_api_key'] ?? '' ), Matin_AI_Messaging::secret_material() ) && '' !== (string) ( $o['sms_sender'] ?? '' );
	}

	/** @return array{ok: bool, message: string} */
	public static function request_code( int $user_id, string $phone ) : array {
		$phone = self::normalize_phone( $phone );
		if ( ! $phone ) {
			return [ 'ok' => false, 'message' => 'شماره موبایل معتبر نیست.' ];
		}
		$last = (int) get_user_meta( $user_id, self::META_SENT_AT, true );
		if ( $last && time() - $last < self::COOLDOWN ) {
			return [ 'ok' => false, 'message' => 'کد قبلی هنوز معتبر است؛ کمی صبر کنید و دوباره تلاش کنید.' ];
		}
		if ( ! self::is_configured() ) {
			Matin_AI_Logger::log( 'warning', 'sms', 'ارسال پیامک تأیید فعال نیست؛ سرویس پیامک تنظیم نشده است.', [ 'user_id' => $user_id ] );
			return [ 'ok' => false, 'message' => 'ارسال پیامک فعال نشده است. لطفاً بعداً دوباره تلاش کنید.' ];
		}
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$sent = self::send( $phone, 'کد تأیید Matin AI: ' . $code );
		if ( ! $sent ) {
			return [ 'ok' => false, 'message' => 'ارسال پیامک انجام نشد. دوباره تلاش کنید.' ];
		}
		update_user_meta( $user_id, self::META_OTP, [
			'hash'     => wp_hash( $phone . '|' . $code ),
			'phone'    => $phone,
			'expires'  => time() + self::CODE_TTL,
			'attempts' => 0,
		] );
		update_user_meta( $user_id, self::META_SENT_AT, time() );
		return [ 'ok' => true, 'message' => 'کد تأیید برای شماره شما ارسال شد.' ];
	}

	/** @return array{ok: bool, message: string} */
	public static function verify_code( int $user_id, string $code ) : array {
		$otp = get_user_meta( $user_id, self::META_OTP, true );
		if ( ! is_array( $otp ) || empty( $otp['hash'] ) ) {
			return [ 'ok' => false, 'message' => 'ابتدا کد تأیید را درخواست کنید.' ];
		}
		if ( time() > (int) $otp['expires'] ) {
			delete_user_meta( $user_id, self::META_OTP );
			return [ 'ok' => false, 'message' => 'کد منقضی شده است. کد جدید بگیرید.' ];
		}
		if ( (int) $otp['attempts'] >= self::MAX_ATTEMPTS ) {
			delete_user_meta( $user_id, self::META_OTP );
			return [ 'ok' => false, 'message' => 'تعداد تلاش‌ها زیاد بود. کد جدید بگیرید.' ];
		}
		$candidate = preg_replace( '/\D/', '', $code );
		if ( ! hash_equals( (string) $otp['hash'], wp_hash( $otp['phone'] . '|' . $candidate ) ) ) {
			$otp['attempts'] = (int) $otp['attempts'] + 1;
			update_user_meta( $user_id, self::META_OTP, $otp );
			return [ 'ok' => false, 'message' => 'کد وارد شده درست نیست.' ];
		}
		update_user_meta( $user_id, 'matin_phone', $otp['phone'] );
		update_user_meta( $user_id, 'matin_phone_verified', 1 );
		delete_user_meta( $user_id, self::META_OTP );
		return [ 'ok' => true, 'message' => 'شماره موبایل شما تأیید شد.' ];
	}

	public static function is_verified( int $user_id ) : bool {
		return (bool) get_user_meta( $user_id, 'matin_phone_verified', true );
	}

	/** Accepts 09xxxxxxxxx, 9xxxxxxxxx, +98… and 0098…; returns 09xxxxxxxxx or '' when invalid. */
	public static function normalize_phone( string $phone ) : string {
		$digits = preg_replace( '/\D/', '', $phone );
		if ( 0 === strpos( $digits, '0098' ) ) {
			$digits = substr( $digits, 4 );
		} elseif ( 0 === strpos( $digits, '98' ) && strlen( $digits ) === 12 ) {
			$digits = substr( $digits, 2 );
		}
		if ( 10 === strlen( $digits ) && '9' === $digits[0] ) {
			$digits = '0' . $digits;
		}
		return preg_match( '/^09\d{9}$/', $digits ) ? $digits : '';
	}

	private static function send( string $receptor, string $message ) : bool {
		$o   = Matin_AI_Admin::get_options();
		$key = Matin_AI_Secrets::decrypt( (string) ( $o['sms_api_key'] ?? '' ), Matin_AI_Messaging::secret_material() );
		$url = add_query_arg( [ 'receptor' => $receptor, 'sender' => (string) $o['sms_sender'], 'message' => $message ], 'https://api.kavenegar.com/v1/' . rawurlencode( $key ) . '/sms/send.json' );
		$response = wp_remote_get( $url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) ) {
			Matin_AI_Logger::log( 'error', 'sms', 'اتصال به سرویس پیامک برقرار نشد.' );
			return false;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) ( $data['return']['status'] ?? 0 ) ) {
			Matin_AI_Logger::log( 'error', 'sms', 'سرویس پیامک درخواست را نپذیرفت.', [ 'status' => $data['return']['status'] ?? null ] );
			return false;
		}
		return true;
	}
}
