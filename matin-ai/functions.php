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
	wp_enqueue_style( 'matin-ai-responsive', get_theme_file_uri( 'assets/responsive.css' ), [ 'matin-ai-pages' ], $version );
	wp_enqueue_script( 'matin-ai', get_theme_file_uri( 'assets/app.js' ), [], $version, true );
	if ( is_front_page() ) {
		wp_enqueue_script( 'matin-ai-nav', get_theme_file_uri( 'assets/nav.js' ), [], $version, true );
		wp_localize_script( 'matin-ai-nav', 'matinNav', [ 'homePath' => (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/' ] );
	}
	if ( class_exists( 'Matin_AI_PWA' ) ) {
		wp_enqueue_script( 'matin-ai-pwa', get_theme_file_uri( 'assets/pwa.js' ), [], $version, true );
		wp_localize_script( 'matin-ai-pwa', 'matinPWA', [ 'sw' => Matin_AI_PWA::sw_url() ] );
	}
	wp_localize_script( 'matin-ai', 'matinAI', [ 'root' => esc_url_raw( rest_url( 'matin-ai/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'loggedIn' => is_user_logged_in(), 'language' => ( isset($_GET['lang']) && in_array(sanitize_key(wp_unslash($_GET['lang'])), ['fa','tr','en'], true) ? sanitize_key(wp_unslash($_GET['lang'])) : 'tr' ), 'ticketButtons' => (array) ( ( (array) get_option('matin_ai_options', []) )['ticket_buttons'] ?? [ 'download', 'print', 'whatsapp' ] ), 'accountUrl' => esc_url_raw( home_url( '/my-account/' ) ), 'strings' => [ 'loading' => __( 'در حال جست‌وجو…', 'matin-ai' ), 'error' => __( 'اتصال برقرار نشد. دوباره تلاش کنید.', 'matin-ai' ) ] ] );
} );
add_filter( 'language_attributes', static function ( string $output ) : string {
    $lang = isset($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : 'fa';
    if ( ! in_array($lang, ['fa','tr','en'], true) ) $lang = 'fa';
    $dir = $lang === 'fa' ? 'rtl' : 'ltr';
    // WordPress' attribute string may start with lang=, so the leading space is optional.
    $output = preg_replace('/(^|\s)(dir|lang)=(["\'])[^"\']*\3/i', '', $output);
    $output = trim(preg_replace('/\s{2,}/', ' ', $output));
    return $output . ' lang="' . esc_attr($lang) . '" dir="' . esc_attr($dir) . '"';
} );

add_action( 'init', static function () : void {
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
} );
// The chat workspace is the app: no WordPress admin bar on the front end.
add_filter( 'show_admin_bar', '__return_false' );
// Direct visits to app pages open inside the chat shell (keeps the user on the main page).
add_action( 'template_redirect', static function () : void {
	if ( is_admin() || wp_doing_ajax() || is_front_page() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! empty( $_SERVER['HTTP_X_MATIN_VIEW'] ) ) { return; }
	$app_page = is_page( [ 'about', 'complaints', 'my-account' ] ) || is_post_type_archive( [ 'matin_cafe', 'matin_event' ] ) || is_singular( [ 'matin_cafe', 'matin_event' ] );
	if ( ! $app_page ) { return; }
	$path = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
	if ( '' === $path || '/' !== $path[0] || str_starts_with( $path, '//' ) ) { return; }
	wp_safe_redirect( add_query_arg( 'matin_view', $path, home_url( '/' ) ), 302 );
	exit;
} );

// 1.6.8 hardening: baseline security headers and XML-RPC disabled (not used by the app).
add_action( 'send_headers', static function () : void {
	if ( is_admin() ) { return; }
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( "Permissions-Policy: camera=(), microphone=(), geolocation=(self)" );
} );
add_filter( 'xmlrpc_enabled', '__return_false' );
// 1.6.8: do not let anonymous visitors list account names through the REST users endpoint.
add_filter( 'rest_endpoints', static function ( array $endpoints ) : array {
	if ( ! current_user_can( 'list_users' ) ) { unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ); }
	return $endpoints;
} );
