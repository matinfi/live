<?php
defined( 'ABSPATH' ) || exit;

/**
 * Creates the standard pages once. An existing page with the same slug is never duplicated or overwritten,
 * so site owners can edit the content freely. Cafe and event archives come from the custom post types.
 */
final class Matin_AI_Pages {
	private const VERSION = 2;

	public static function init() : void {
		add_action( 'init', [ __CLASS__, 'maybe_create_pages' ], 30 );
	}

	/** @return array<string,array{0:string,1:string}> slug => [title, content] */
	private static function definitions() : array {
		return [
			'about' => [
				'درباره ما',
				"<!-- wp:paragraph --><p>Matin AI یک راهنمای کشف برنامه‌های کافه‌هاست. با گفت‌وگو یا جست‌وجو، می‌توانید کافه‌ها و رویدادهای نزدیک خود را ببینید، زمان و ظرفیت برنامه را بررسی کنید و رزرو خود را ثبت کنید.</p><!-- /wp:paragraph -->"
				. "<!-- wp:paragraph --><p>هدف ما این است که پیدا کردن یک برنامه خوب در یک شب خوب، ساده و قابل اعتماد باشد. اطلاعات هر کافه و رویداد توسط خود کافه ثبت می‌شود و رزروها پس از ثبت، با رسید و کد رزرو همراه است.</p><!-- /wp:paragraph -->"
				. "<!-- wp:paragraph --><p>اگر پیشنهاد، نظر یا شکایتی دارید، از صفحهٔ <a href=\"/complaints/\">شکایت و بازخورد</a> برای ما بنویسید. پیام‌ها فقط برای مدیریت سایت قابل مشاهده است.</p><!-- /wp:paragraph -->",
			],
			'complaints' => [
				'شکایت و بازخورد',
				'[matin_ai_complaints]',
			],
		];
	}

	public static function maybe_create_pages() : void {
		if ( (int) get_option( 'matin_ai_pages_version' ) >= self::VERSION ) { return; }
		self::create_pages();
	}

	/** Creates missing pages. Safe to call repeatedly. */
	public static function create_pages( bool $force = false ) : void {
		if ( ! $force && (int) get_option( 'matin_ai_pages_version' ) >= self::VERSION ) { return; }
		foreach ( self::definitions() as $slug => [ $title, $content ] ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $existing ) { continue; }
			wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content ], true );
		}
		update_option( 'matin_ai_pages_version', self::VERSION, false );
	}
}
