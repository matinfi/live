<?php
defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', static function () : void {
	add_theme_support( 'title-tag' ); add_theme_support( 'post-thumbnails' ); add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ] ); add_theme_support( 'responsive-embeds' );
} );
add_action( 'wp_enqueue_scripts', static function () : void {
	$version = wp_get_theme()->get( 'Version' );
	wp_enqueue_style( 'matin-ai', get_theme_file_uri( 'assets/app.css' ), [], $version );
	wp_enqueue_style( 'matin-ai-reservation-chat', get_theme_file_uri( 'assets/reservation-chat.css' ), [ 'matin-ai' ], $version );
	wp_enqueue_style( 'matin-ai-workspace', get_theme_file_uri( 'assets/workspace.css' ), [ 'matin-ai-reservation-chat' ], $version );
	wp_enqueue_script( 'matin-ai', get_theme_file_uri( 'assets/app.js' ), [], $version, true );
	wp_localize_script( 'matin-ai', 'matinAI', [ 'root' => esc_url_raw( rest_url( 'matin-ai/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'strings' => [ 'loading' => __( 'در حال جست‌وجو…', 'matin-ai' ), 'error' => __( 'اتصال برقرار نشد. دوباره تلاش کنید.', 'matin-ai' ) ] ] );
} );
add_filter( 'language_attributes', static function ( string $output ) : string { return $output . ' dir="rtl"'; } );

