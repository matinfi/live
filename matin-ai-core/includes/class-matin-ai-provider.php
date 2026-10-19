<?php
defined( 'ABSPATH' ) || exit;

/**
 * Server-side AI adapters. Keys come from constants or environment first, then from the admin
 * settings (stored encrypted). They are never sent to the browser and never written to logs.
 *
 * Visitors only ever see a generic message. The real reason (HTTP status, provider message,
 * transport error) is logged to the "ai" channel and shown to administrators by the connection test.
 */
final class Matin_AI_Provider {
	public static function reply( string $instruction ) : string|WP_Error {
		if ( class_exists( 'Matin_AI_Admin' ) && empty( Matin_AI_Admin::get_options()['ai_enabled'] ) ) { return new WP_Error( 'matin_provider_off', __( 'دستیار هوش مصنوعی در تنظیمات غیرفعال است.', 'matin-ai' ) ); }
		$provider = strtolower( (string) self::config( 'MATIN_AI_PROVIDER', 'none' ) );
		$result = match ( $provider ) {
			'gemini'     => self::gemini( $instruction ),
			'openai'     => self::openai( $instruction ),
			'compatible' => self::compatible( $instruction ),
			default      => new WP_Error( 'matin_provider_off', __( 'ارائه‌دهندهٔ هوش مصنوعی پیکربندی نشده است.', 'matin-ai' ) ),
		};
		if ( is_wp_error( $result ) && 'matin_provider_off' !== $result->get_error_code() ) {
			$detail = (string) ( $result->get_error_data()['detail'] ?? $result->get_error_code() );
			Matin_AI_Logger::log( 'error', 'ai', 'پاسخ هوش مصنوعی ناموفق بود: ' . $detail, [ 'provider' => $provider, 'code' => $result->get_error_code() ] );
		}
		return $result;
	}

	/**
	 * Administrator connection test. Returns the real outcome, including the provider's own message.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function test() : array {
		if ( class_exists( 'Matin_AI_Admin' ) && empty( Matin_AI_Admin::get_options()['ai_enabled'] ) ) {
			return [ 'ok' => false, 'message' => 'دستیار هوش مصنوعی غیرفعال است. گزینه «فعال بودن» را روشن و ذخیره کنید.' ];
		}
		$provider = strtolower( (string) self::config( 'MATIN_AI_PROVIDER', 'none' ) );
		$result = match ( $provider ) {
			'gemini'     => self::gemini( 'فقط کلمه «آماده» را پاسخ بده.' ),
			'openai'     => self::openai( 'فقط کلمه «آماده» را پاسخ بده.' ),
			'compatible' => self::compatible( 'فقط کلمه «آماده» را پاسخ بده.' ),
			default      => new WP_Error( 'matin_provider_off', 'ارائه‌دهنده انتخاب نشده است.' ),
		};
		if ( is_wp_error( $result ) ) {
			return [ 'ok' => false, 'message' => (string) ( $result->get_error_data()['detail'] ?? $result->get_error_message() ) ];
		}
		return [ 'ok' => true, 'message' => 'اتصال برقرار است. پاسخ سرویس: ' . mb_substr( $result, 0, 80, 'UTF-8' ) ];
	}

	private static function config( string $name, string $default = '' ) : string {
		if ( defined( $name ) ) { return (string) constant( $name ); }
		$value = getenv( $name ); if ( false !== $value && '' !== (string) $value ) { return (string) $value; }
		$options = class_exists( 'Matin_AI_Admin' ) ? Matin_AI_Admin::get_options() : [];
		$map = [ 'MATIN_AI_PROVIDER' => 'ai_provider', 'MATIN_GEMINI_MODEL' => 'ai_model', 'MATIN_OPENAI_MODEL' => 'ai_model', 'MATIN_COMPATIBLE_MODEL' => 'ai_model', 'MATIN_COMPATIBLE_BASE_URL' => 'compatible_base_url' ];
		if ( isset( $map[ $name ], $options[ $map[ $name ] ] ) && '' !== (string) $options[ $map[ $name ] ] ) { return (string) $options[ $map[ $name ] ]; }
		return $default;
	}

	/** API key lookup: constant, then environment, then encrypted admin setting. */
	private static function key( string $constant ) : string {
		if ( defined( $constant ) ) { return (string) constant( $constant ); }
		$env = getenv( $constant ); if ( false !== $env && '' !== (string) $env ) { return (string) $env; }
		$stored = (string) ( Matin_AI_Admin::get_options()['ai_api_key'] ?? '' );
		return Matin_AI_Secrets::decrypt( $stored, Matin_AI_Messaging::secret_material() );
	}

	/** Builds a WP_Error with a public message and an administrator-only detail (redacted). */
	private static function failure( string $code, string $public, string $detail ) : WP_Error {
		return new WP_Error( $code, $public, [ 'detail' => Matin_AI_Logger::redact_text( $detail ) ] );
	}

	/** Explains an HTTP failure from a provider, using the provider's own error message when present. */
	private static function http_failure( string $name, array|WP_Error $response, string $public ) : WP_Error {
		if ( is_wp_error( $response ) ) {
			return self::failure( 'matin_ai_unavailable', $public, $name . ': اتصال از سرور وردپرس برقرار نشد (' . $response->get_error_message() . '). احتمالاً سرور به اینترنت یا آدرس API دسترسی ندارد.' );
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message = is_array( $body ) ? (string) ( $body['error']['message'] ?? '' ) : '';
		$hint    = match ( true ) {
			401 === $code || 403 === $code => 'کلید API نامعتبر است، یا API در حساب شما فعال نیست، یا منطقه سرور مجاز نیست.',
			404 === $code                  => 'نام مدل اشتباه است یا در دسترس نیست.',
			429 === $code                  => 'سهمیه یا محدودیت نرخ درخواست پر شده است.',
			default                        => 'خطای سرویس.',
		};
		return self::failure( 'matin_ai_unavailable', $public, $name . ' HTTP ' . $code . ': ' . $hint . ( '' !== $message ? ' پیام سرویس: ' . $message : '' ) );
	}

	private static function gemini( string $instruction ) : string|WP_Error {
		$key = self::key( 'MATIN_GEMINI_API_KEY' ); if ( '' === $key ) { return self::failure( 'matin_key', __( 'Gemini پیکربندی نشده است.', 'matin-ai' ), 'کلید Gemini در تنظیمات ذخیره نشده است.' ); }
		$model = rawurlencode( self::config( 'MATIN_GEMINI_MODEL', 'gemini-2.5-flash' ) );
		// The key travels in a header, not in the URL, so it cannot end up in server or proxy access logs.
		$response = wp_remote_post( "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json', 'x-goog-api-key' => $key ], 'body' => wp_json_encode( [ 'contents' => [ [ 'parts' => [ [ 'text' => $instruction ] ] ] ], 'generationConfig' => [ 'temperature' => 0.2, 'maxOutputTokens' => 700 ] ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return self::http_failure( 'Gemini', $response, __( 'Gemini موقتاً در دسترس نیست.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$candidate = $body['candidates'][0] ?? [];
		if ( 'MAX_TOKENS' === ( $candidate['finishReason'] ?? '' ) ) { return new WP_Error( 'matin_ai_truncated', __( 'پاسخ هوش مصنوعی کامل تولید نشد. لطفاً سؤال را کوتاه‌تر کنید یا دوباره تلاش کنید.', 'matin-ai' ) ); }
		$parts = $candidate['content']['parts'] ?? [];
		$text = implode( "\n", array_filter( array_map( static fn( $part ) => is_array( $part ) ? (string) ( $part['text'] ?? '' ) : '', $parts ) ) );
		return self::clean( $text, 'Gemini' );
	}

	private static function openai( string $instruction ) : string|WP_Error {
		$key = self::key( 'MATIN_OPENAI_API_KEY' ); if ( '' === $key ) { return self::failure( 'matin_key', __( 'اتصال OpenAI پیکربندی نشده است.', 'matin-ai' ), 'کلید OpenAI در تنظیمات ذخیره نشده است.' ); }
		$response = wp_remote_post( 'https://api.openai.com/v1/responses', [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ], 'body' => wp_json_encode( [ 'model' => self::config( 'MATIN_OPENAI_MODEL', 'gpt-4.1-mini' ), 'input' => $instruction, 'max_output_tokens' => 700 ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return self::http_failure( 'OpenAI', $response, __( 'سرویس OpenAI موقتاً در دسترس نیست.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['incomplete_details']['reason'] ) ) { return new WP_Error( 'matin_ai_truncated', __( 'پاسخ هوش مصنوعی کامل تولید نشد. لطفاً دوباره تلاش کنید.', 'matin-ai' ) ); }
		$texts = []; foreach ( (array) ( $body['output'] ?? [] ) as $output ) { foreach ( (array) ( $output['content'] ?? [] ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $texts[] = $content['text']; } } }
		return self::clean( implode( "\n", $texts ), 'OpenAI' );
	}

	private static function compatible( string $instruction ) : string|WP_Error {
		$base = rtrim( self::config( 'MATIN_COMPATIBLE_BASE_URL' ), '/' ); $key = self::key( 'MATIN_COMPATIBLE_API_KEY' );
		if ( '' === $base || '' === $key ) { return self::failure( 'matin_key', __( 'ارائه‌دهنده هوش مصنوعی سازگار پیکربندی نشده است.', 'matin-ai' ), 'آدرس پایه یا کلید API برای ارائه‌دهنده سازگار خالی است.' ); }
		$response = wp_remote_post( $base . '/chat/completions', [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ], 'body' => wp_json_encode( [ 'model' => self::config( 'MATIN_COMPATIBLE_MODEL' ), 'messages' => [ [ 'role' => 'user', 'content' => $instruction ] ], 'temperature' => 0.2, 'max_tokens' => 700 ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return self::http_failure( 'سازگار', $response, __( 'سرویس هوش مصنوعی موقتاً در دسترس نیست.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true ); return self::clean( $body['choices'][0]['message']['content'] ?? '', 'سازگار' );
	}

	private static function clean( mixed $text, string $name = '' ) : string|WP_Error {
		$text = sanitize_textarea_field( is_string( $text ) ? $text : '' );
		return '' === $text ? self::failure( 'matin_ai_empty', __( 'پاسخ قابل استفاده‌ای از سرویس هوش مصنوعی دریافت نشد.', 'matin-ai' ), $name . ': پاسخ خالی یا بدون متن بود (ممکن است مدل پاسخی نداده یا محتوا فیلتر شده باشد).' ) : $text;
	}
}
