<?php
defined( 'ABSPATH' ) || exit;

/** Server-side adapters. API keys are never sent to the browser; settings may be overridden by constants or environment variables. */
final class Matin_AI_Provider {
	public static function reply( string $instruction ) : string|WP_Error {
		if ( class_exists( 'Matin_AI_Admin' ) && empty( Matin_AI_Admin::get_options()['ai_enabled'] ) ) { return new WP_Error( 'matin_provider_off', __( 'دستیار هوش مصنوعی در تنظیمات غیرفعال است.', 'matin-ai' ) ); }
		$provider = strtolower( (string) self::config( 'MATIN_AI_PROVIDER', 'none' ) );
		$result = match ( $provider ) {
			'gemini' => self::gemini( $instruction ),
			'openai' => self::openai( $instruction ),
			'openrouter' => self::openrouter( $instruction ),
			'compatible' => self::compatible( $instruction ),
			default => new WP_Error( 'matin_provider_off', __( 'ارائه‌دهندهٔ هوش مصنوعی پیکربندی نشده است.', 'matin-ai' ) ),
		};
		// Fail over to a separately configured Gemini free-tier key when the primary provider fails.
		$options = class_exists( 'Matin_AI_Admin' ) ? Matin_AI_Admin::get_options() : [];
		if ( is_wp_error( $result ) && 'openrouter' === $provider && ! empty( $options['ai_fallback_enabled'] ) && '' !== self::config( 'MATIN_FALLBACK_GEMINI_API_KEY' ) ) {
			$fallback = self::gemini_fallback( $instruction );
			if ( ! is_wp_error( $fallback ) ) { if ( class_exists( 'Matin_AI_Operations' ) ) { Matin_AI_Operations::log( 'ai_fallback_recovered', 0, 'primary=' . $provider . ';fallback=gemini' ); } return $fallback; }
			if ( class_exists( 'Matin_AI_Operations' ) ) { Matin_AI_Operations::log( 'ai_provider_failure', 0, 'primary=' . $provider . ';error=' . $result->get_error_code() . ';fallback=' . $fallback->get_error_code() ); }
		}
		if ( is_wp_error( $result ) && class_exists( 'Matin_AI_Operations' ) ) { Matin_AI_Operations::log( 'ai_provider_failure', 0, 'provider=' . $provider . ';error=' . $result->get_error_code() ); }
		return $result;
	}
	private static function config( string $name, string $default = '' ) : string {
		if ( defined( $name ) ) { return (string) constant( $name ); }
		$value = getenv( $name ); if ( false !== $value && '' !== (string) $value ) { return (string) $value; }
		$options = class_exists( 'Matin_AI_Admin' ) ? Matin_AI_Admin::get_options() : [];
		$map = [ 'MATIN_AI_PROVIDER' => 'ai_provider', 'MATIN_GEMINI_API_KEY' => 'ai_api_key', 'MATIN_GEMINI_MODEL' => 'ai_model', 'MATIN_OPENAI_API_KEY' => 'ai_api_key', 'MATIN_OPENAI_MODEL' => 'ai_model', 'MATIN_COMPATIBLE_API_KEY' => 'ai_api_key', 'MATIN_COMPATIBLE_MODEL' => 'ai_model', 'MATIN_COMPATIBLE_BASE_URL' => 'compatible_base_url', 'MATIN_OPENROUTER_API_KEY' => 'ai_api_key', 'MATIN_OPENROUTER_MODEL' => 'ai_model', 'MATIN_FALLBACK_GEMINI_API_KEY' => 'ai_fallback_gemini_api_key', 'MATIN_FALLBACK_GEMINI_MODEL' => 'ai_fallback_gemini_model' ];
		if ( isset( $map[ $name ], $options[ $map[ $name ] ] ) && '' !== (string) $options[ $map[ $name ] ] ) { return (string) $options[ $map[ $name ] ]; }
		return $default;
	}
	private static function gemini( string $instruction ) : string|WP_Error {
		$key = self::config( 'MATIN_GEMINI_API_KEY' ); if ( '' === $key ) { return new WP_Error( 'matin_key', __( 'Gemini پیکربندی نشده است.', 'matin-ai' ) ); }
		$model = rawurlencode( self::config( 'MATIN_GEMINI_MODEL', 'gemini-2.5-flash' ) );
		$response = wp_remote_post( "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . rawurlencode( $key ), [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( [ 'contents' => [ [ 'parts' => [ [ 'text' => $instruction ] ] ] ], 'generationConfig' => [ 'temperature' => 0.2, 'maxOutputTokens' => 700 ] ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'matin_ai_unavailable', __( 'Gemini موقتاً در دسترس نیست.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$candidate = $body['candidates'][0] ?? [];
		if ( 'MAX_TOKENS' === ( $candidate['finishReason'] ?? '' ) ) { return new WP_Error( 'matin_ai_truncated', __( 'پاسخ هوش مصنوعی کامل تولید نشد. لطفاً سؤال را کوتاه‌تر کنید یا دوباره تلاش کنید.', 'matin-ai' ) ); }
		$parts = $candidate['content']['parts'] ?? [];
		$text = implode( "\n", array_filter( array_map( static fn( $part ) => is_array( $part ) ? (string) ( $part['text'] ?? '' ) : '', $parts ) ) );
		return self::clean( $text );
	}
	private static function openai( string $instruction ) : string|WP_Error {
		$key = self::config( 'MATIN_OPENAI_API_KEY' ); if ( '' === $key ) { return new WP_Error( 'matin_key', __( 'اتصال OpenAI پیکربندی نشده است.', 'matin-ai' ) ); }
		$response = wp_remote_post( 'https://api.openai.com/v1/responses', [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ], 'body' => wp_json_encode( [ 'model' => self::config( 'MATIN_OPENAI_MODEL', 'gpt-4.1-mini' ), 'input' => $instruction, 'max_output_tokens' => 700 ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'matin_ai_unavailable', __( 'OpenAI is temporarily unavailable.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['incomplete_details']['reason'] ) ) { return new WP_Error( 'matin_ai_truncated', __( 'پاسخ هوش مصنوعی کامل تولید نشد. لطفاً دوباره تلاش کنید.', 'matin-ai' ) ); }
		$texts = []; foreach ( (array) ( $body['output'] ?? [] ) as $output ) { foreach ( (array) ( $output['content'] ?? [] ) as $content ) { if ( isset( $content['text'] ) && is_string( $content['text'] ) ) { $texts[] = $content['text']; } } }
		return self::clean( implode( "\n", $texts ) );
	}
	/** OpenRouter uses the OpenAI-compatible chat completions endpoint. */
	private static function openrouter( string $instruction ) : string|WP_Error {
		$key = self::config( 'MATIN_OPENROUTER_API_KEY' );
		if ( '' === $key ) { return new WP_Error( 'matin_key', __( 'کلید API مربوط به OpenRouter وارد نشده است.', 'matin-ai' ) ); }
		$model = trim( self::config( 'MATIN_OPENROUTER_MODEL', 'openrouter/free' ) );
		if ( '' === $model ) { $model = 'openrouter/free'; }
		$headers = [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key, 'HTTP-Referer' => home_url( '/' ), 'X-Title' => 'Matin AI' ];
		$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
			'timeout' => 50,
			'headers' => $headers,
			'body' => wp_json_encode( [ 'model' => $model, 'messages' => [ [ 'role' => 'user', 'content' => $instruction ] ], 'temperature' => 0.2, 'max_tokens' => 1200 ] ),
		] );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'matin_ai_unavailable', __( 'ارتباط با OpenRouter برقرار نشد. اتصال سرور را بررسی و دوباره تلاش کنید.', 'matin-ai' ) ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 429 === $status ) { return new WP_Error( 'matin_ai_rate_limited', __( 'سهمیه یا محدودیت موقت OpenRouter پر شده است. کمی بعد دوباره تلاش کنید یا مدل رایگان دیگری انتخاب کنید.', 'matin-ai' ) ); }
		if ( 401 === $status || 403 === $status ) { return new WP_Error( 'matin_ai_auth', __( 'کلید OpenRouter معتبر نیست یا دسترسی لازم را ندارد. کلید را در تنظیمات بررسی کنید.', 'matin-ai' ) ); }
		if ( 200 !== $status ) { return new WP_Error( 'matin_ai_unavailable', __( 'OpenRouter در حال حاضر پاسخ موفقی نداد. مدل انتخابی ممکن است موقتاً در دسترس نباشد.', 'matin-ai' ) ); }
		$content = $body['choices'][0]['message']['content'] ?? '';
		if ( is_array( $content ) ) { $content = implode( "\n", array_filter( array_map( static fn( $part ) => is_array( $part ) ? (string) ( $part['text'] ?? '' ) : '', $content ) ) ); }
		$finish = $body['choices'][0]['finish_reason'] ?? '';
		if ( 'length' === $finish ) { return new WP_Error( 'matin_ai_truncated', __( 'پاسخ به سقف طول مدل رسید. دوباره سؤال کنید یا مدل دیگری انتخاب کنید.', 'matin-ai' ) ); }
		return self::clean( $content );
	}

	/** Gemini fallback uses its own key and model, independent of the primary OpenRouter key. */
	private static function gemini_fallback( string $instruction ) : string|WP_Error {
		$key = self::config( 'MATIN_FALLBACK_GEMINI_API_KEY' );
		$model = rawurlencode( self::config( 'MATIN_FALLBACK_GEMINI_MODEL', 'gemini-2.5-flash' ) );
		if ( '' === $key ) { return new WP_Error( 'matin_fallback_key', __( 'کلید جایگزین Gemini تنظیم نشده است.', 'matin-ai' ) ); }
		$response = wp_remote_post( "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . rawurlencode( $key ), [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( [ 'contents' => [ [ 'parts' => [ [ 'text' => $instruction ] ] ] ], 'generationConfig' => [ 'temperature' => 0.2, 'maxOutputTokens' => 1200 ] ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'matin_fallback_unavailable', __( 'ارائه‌دهندهٔ اصلی و Gemini جایگزین در دسترس نیستند.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$candidate = $body['candidates'][0] ?? [];
		if ( 'MAX_TOKENS' === ( $candidate['finishReason'] ?? '' ) ) { return new WP_Error( 'matin_fallback_truncated', __( 'پاسخ Gemini جایگزین کامل نبود.', 'matin-ai' ) ); }
		$parts = $candidate['content']['parts'] ?? [];
		$text = implode( "\n", array_filter( array_map( static fn( $part ) => is_array( $part ) ? (string) ( $part['text'] ?? '' ) : '', $parts ) ) );
		return self::clean( $text );
	}

	private static function compatible( string $instruction ) : string|WP_Error {
		$base = rtrim( self::config( 'MATIN_COMPATIBLE_BASE_URL' ), '/' ); $key = self::config( 'MATIN_COMPATIBLE_API_KEY' );
		if ( '' === $base || '' === $key ) { return new WP_Error( 'matin_key', __( 'ارائه‌دهنده هوش مصنوعی سازگار پیکربندی نشده است.', 'matin-ai' ) ); }
		$response = wp_remote_post( $base . '/chat/completions', [ 'timeout' => 35, 'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ], 'body' => wp_json_encode( [ 'model' => self::config( 'MATIN_COMPATIBLE_MODEL' ), 'messages' => [ [ 'role' => 'user', 'content' => $instruction ] ], 'temperature' => 0.2, 'max_tokens' => 700 ] ) ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'matin_ai_unavailable', __( 'سرویس هوش مصنوعی موقتاً در دسترس نیست.', 'matin-ai' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true ); return self::clean( $body['choices'][0]['message']['content'] ?? '' );
	}
	private static function clean( mixed $text ) : string|WP_Error { $text = sanitize_textarea_field( is_string( $text ) ? $text : '' ); return '' === $text ? new WP_Error( 'matin_ai_empty', __( 'پاسخ قابل استفاده‌ای از سرویس هوش مصنوعی دریافت نشد.', 'matin-ai' ) ) : $text; }
}
