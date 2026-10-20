<?php
defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', static function () : void {
	add_theme_support( 'title-tag' ); add_theme_support( 'post-thumbnails' ); add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ] ); add_theme_support( 'responsive-embeds' );
} );
add_action( 'wp_enqueue_scripts', static function () : void {
	$version = wp_get_theme()->get( 'Version' );
	wp_enqueue_style( 'matin-ai-theme', get_stylesheet_uri(), [], $version );
	wp_enqueue_style( 'matin-ai', get_theme_file_uri( 'assets/app.css' ), [ 'matin-ai-theme' ], $version );
	wp_enqueue_style( 'matin-ai-reservation-chat', get_theme_file_uri( 'assets/reservation-chat.css' ), [ 'matin-ai' ], $version );
	wp_enqueue_style( 'matin-ai-workspace', get_theme_file_uri( 'assets/workspace.css' ), [ 'matin-ai-reservation-chat' ], $version );
	wp_enqueue_style( 'matin-ai-pages', get_theme_file_uri( 'assets/pages.css' ), [ 'matin-ai' ], $version );
	wp_enqueue_script( 'matin-ai', get_theme_file_uri( 'assets/app.js' ), [], $version, true );
	if ( class_exists( 'Matin_AI_PWA' ) ) {
		wp_enqueue_script( 'matin-ai-pwa', get_theme_file_uri( 'assets/pwa.js' ), [], $version, true );
		wp_localize_script( 'matin-ai-pwa', 'matinPWA', [ 'sw' => Matin_AI_PWA::sw_url() ] );
	}
	wp_localize_script( 'matin-ai', 'matinAI', [ 'root' => esc_url_raw( rest_url( 'matin-ai/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'loggedIn' => is_user_logged_in(), 'language' => ( isset($_GET['lang']) && in_array(sanitize_key(wp_unslash($_GET['lang'])), ['fa','tr','en'], true) ? sanitize_key(wp_unslash($_GET['lang'])) : 'tr' ), 'ticketButtons' => (array) ( ( (array) get_option('matin_ai_options', []) )['ticket_buttons'] ?? [ 'download', 'print', 'whatsapp' ] ), 'accountUrl' => esc_url_raw( home_url( '/my-account/' ) ), 'strings' => [ 'loading' => __( 'در حال جست‌وجو…', 'matin-ai' ), 'error' => __( 'اتصال برقرار نشد. دوباره تلاش کنید.', 'matin-ai' ) ] ] );
} );
add_filter( 'language_attributes', static function ( string $output ) : string {
    $lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : 'tr';
    if ( ! in_array($lang, ['fa','tr','en'], true) ) $lang = 'tr';
    $dir = $lang === 'fa' ? 'rtl' : 'ltr';
    $output = preg_replace('/\sdir=(\"|\')[^\"\']*\1/i', '', $output);
    $output = preg_replace('/\slang=(\"|\')[^\"\']*\1/i', '', $output);
    return $output . ' lang="' . esc_attr($lang) . '" dir="' . esc_attr($dir) . '"';
} );

