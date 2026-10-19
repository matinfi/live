<?php
defined( 'ABSPATH' ) || exit;

/**
 * Server-side AI adapters. Keys come from constants or environment first, then from the admin
 * settings (stored encrypted). They are never sent to the browser and never written to logs.
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
			Matin_AI_Logger::log( 'error', 'ai', 'پاسخ هوش مصنوعی ناموفق بود: ' . $result->get_error_code(), [ 'provider' => $provider ] );
		}
		return $result;
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

	private static function gemini( string $instruction ) : string|WP_Error {
		$key = self::key( 'MATIN_GEMINI_API_KEY' ); if ( '' === $key ) { return new WP_Error( 'matin_key', __( 'Gemini پیکربندی نشده است.', 'matin-ai' ) ); }
		$model = rawurlencode( self::config( 'MATIN_GEMINI_MODEL', 'gemini-2.5-flash' ) );
		// The key travels in a header, not in the URL, so it cannot end up in server or proxy access logs.
		$response = wp_remote_post( "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json', 'x-goog-api-key' => $key ], 'body' => wp_json_encode( [ 'contents' => [ [ 'parts' => [ [ 'text' => $instruction ] ] ] ], 'generationConfig' => [ 'temperature' => 0.2, 'maxOutputTokens' => 700 ] ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'matin_ai_unavailable', __( 'Gemini موقتاً در دسترس نیست.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$candidate = $body['candidates'][0] ?? [];
		if ( 'MAX_TOKENS' === ( $candidate['finishReason'] ?? '' ) ) { return new WP_Error( 'matin_ai_truncated', __( 'پاسخ هوش مصنوعی کامل تولید نشد. لطفاً سؤال را کوتاه‌تر کنید یا دوباره تلاش کنید.', 'matin-ai' ) ); }
		$parts = $candidate['content']['parts'] ?? [];
		$text = implode( "\n", array_filter( array_map( static fn( $part ) => is_array( $part ) ? (string) ( $part['text'] ?? '' ) : '', $parts ) ) );
		return self::clean( $text );
	}

	private static function openai( string $instruction ) : string|WP_Error {
		$key = self::key( 'MATIN_OPENAI_API_KEY' ); if ( '' === $key ) { return new WP_Error( 'matin_key', __( 'اتصال OpenAI پیکربندی نشده است.', 'matin-ai' ) ); }
		$response = wp_remote_post( 'https://api.openai.com/v1/responses', [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ], 'body' => wp_json_encode( [ 'model' => self::config( 'MATIN_OPENAI_MODEL', 'gpt-4.1-mini' ), 'input' => $instruction, 'max_output_tokens' => 700 ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'matin_ai_unavailable', __( 'سرویس OpenAI موقتاً در دسترس نیست.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['incomplete_details']['reason'] ) ) { return new WP_Error( 'matin_ai_truncated', __( 'پاسخ هوش مصنوعی کامل تولید نشد. لطفاً دوباره تلاش کنید.', 'matin-ai' ) ); }
		$texts = []; foreach ( (array) ( $body['output'] ?? [] ) as $output ) { foreach ( (array) ( $output['content'] ?? [] ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $texts[] = $content['text']; } } }
		return self::clean( implode( "\n", $texts ) );
	}

	private static function compatible( string $instruction ) : string|WP_Error {
		$base = rtrim( self::config( 'MATIN_COMPATIBLE_BASE_URL' ), '/' ); $key = self::key( 'MATIN_COMPATIBLE_API_KEY' );
		if ( '' === $base || '' === $key ) { return new WP_Error( 'matin_key', __( 'ارائه‌دهنده هوش مصنوعی سازگار پیکربندی نشده است.', 'matin-ai' ) ); }
		$response = wp_remote_post( $base . '/chat/completions', [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ], 'body' => wp_json_encode( [ 'model' => self::config( 'MATIN_COMPATIBLE_MODEL' ), 'messages' => [ [ 'role' => 'user', 'content' => $instruction ] ], 'temperature' => 0.2, 'max_tokens' => 700 ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'matin_ai_unavailable', __( 'سرویس هوش مصنوعی موقتاً در دسترس نیست.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true ); return self::clean( $body['choices'][0]['message']['content'] ?? '' );
	}

	private static function clean( mixed $text ) : string|WP_Error { $text = sanitize_textarea_field( is_string( $text ) ? $text : '' ); return '' === $text ? new WP_Error( 'matin_ai_empty', __( 'پاسخ قابل استفاده‌ای از سرویس هوش مصنوعی دریافت نشد.', 'matin-ai' ) ) : $text; }
}
