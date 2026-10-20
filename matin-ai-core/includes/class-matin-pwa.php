<?php
defined( 'ABSPATH' ) || exit;

/**
 * Makes the site installable as an app (manifest, service worker, icons).
 * The manifest and service worker are served through ?matin_asset=... so they live at the site root scope.
 * The service worker caches the home page and static theme files only; account and admin pages are never cached.
 */
final class Matin_AI_PWA {
	public static function init() : void {
		add_action( 'send_headers', static function () : void { if ( is_user_logged_in() && ! is_admin() ) { header( 'Cache-Control: private, no-store' ); } } );
		add_action( 'template_redirect', [ __CLASS__, 'serve_asset' ], 0 );
		add_action( 'wp_head', [ __CLASS__, 'head_tags' ], 2 );
	}

	public static function manifest_url() : string { return home_url( '/?matin_asset=manifest' ); }
	public static function sw_url() : string { return home_url( '/?matin_asset=sw' ); }

	public static function head_tags() : void {
		$icon = self::icon_url( 180 );
		echo '<link rel="manifest" href="' . esc_url( self::manifest_url() ) . '">' . "\n"
			. '<meta name="theme-color" content="#10131a">' . "\n"
			. '<meta name="mobile-web-app-capable" content="yes">' . "\n"
			. '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
			. '<meta name="apple-mobile-web-app-title" content="Matin AI">' . "\n"
			. '<link rel="apple-touch-icon" href="' . esc_url( $icon ) . '">' . "\n";
	}

	/** App name follows the site title, so changing the site name changes the installed app name. */
	public static function app_name() : string { $name = trim( (string) get_bloginfo( 'name' ) ); return '' !== $name ? $name : 'Matin AI'; }
	public static function app_short_name() : string { return mb_substr( self::app_name(), 0, 12 ); }

	/**
	 * Icon generated from the site logo (custom logo, else site icon). Regenerated automatically when the
	 * logo file changes (cache key = file path + modified time). Falls back to the theme icons when no logo exists.
	 */
	public static function icon_url( int $size ) : string {
		$fallback = get_theme_file_uri( 'assets/icons/icon-' . ( $size >= 512 ? 512 : 192 ) . '.png' );
		$logo_id  = (int) get_theme_mod( 'custom_logo' ) ?: (int) get_option( 'site_icon' );
		$source   = $logo_id ? (string) get_attached_file( $logo_id ) : '';
		if ( '' === $source || ! is_readable( $source ) ) { return $fallback; }
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'matin-ai-icons';
		$hash    = substr( md5( $source . '|' . (string) filemtime( $source ) ), 0, 10 );
		$name    = 'icon-' . $size . '-' . $hash . '.png';
		$file    = trailingslashit( $dir ) . $name;
		if ( ! file_exists( $file ) ) {
			if ( ! wp_mkdir_p( $dir ) ) { return $fallback; }
			$editor = wp_get_image_editor( $source );
			if ( is_wp_error( $editor ) || is_wp_error( $editor->resize( $size, $size, true ) ) || is_wp_error( $editor->save( $file, 'image/png' ) ) ) { return $fallback; }
			foreach ( (array) glob( trailingslashit( $dir ) . 'icon-' . $size . '-*.png' ) as $old ) { if ( $old !== $file ) { @unlink( $old ); } }
		}
		return trailingslashit( $uploads['baseurl'] ) . 'matin-ai-icons/' . $name;
	}

	public static function serve_asset() : void {
		$asset = sanitize_key( wp_unslash( $_GET['matin_asset'] ?? '' ) );
		if ( ! in_array( $asset, [ 'manifest', 'sw' ], true ) ) { return; }
		nocache_headers();
		if ( 'manifest' === $asset ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( [
				'name'             => self::app_name(),
				'short_name'       => self::app_short_name(),
				'lang'             => 'fa',
				'dir'              => 'rtl',
				'start_url'        => home_url( '/' ),
				'scope'            => home_url( '/' ),
				'display'          => 'standalone',
				'background_color' => '#10131a',
				'theme_color'      => '#10131a',
				'icons'            => [
					[ 'src' => self::icon_url( 192 ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ],
					[ 'src' => self::icon_url( 512 ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ],
					[ 'src' => self::icon_url( 512 ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ],
				],
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			exit;
		}
		$shell = [ home_url( '/' ), get_theme_file_uri( 'style.css' ), get_theme_file_uri( 'assets/app.css' ), get_theme_file_uri( 'assets/app.js' ), get_theme_file_uri( 'assets/icons/icon-192.png' ) ];
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		echo "const CACHE = 'matin-ai-" . esc_js( MATIN_AI_CORE_VERSION ) . "';\n"
			. 'const SHELL = ' . wp_json_encode( $shell, JSON_UNESCAPED_SLASHES ) . ";\n"
			. <<<'JS'
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => Promise.allSettled(SHELL.map((url) => cache.add(url)))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') { return; }
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) { return; }
  if (url.pathname.startsWith('/wp-admin') || url.pathname.startsWith('/wp-json') || url.pathname.startsWith('/wp-login') || url.searchParams.has('matin_asset')) { return; }
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).then((response) => {
      // Only the public home page is cached for offline use; pages with query strings (account, tickets) are never stored.
      if (response.ok && url.pathname === '/' && !url.search && !/private|no-store/i.test(response.headers.get('Cache-Control') || '')) { caches.open(CACHE).then((cache) => cache.put('/', response.clone())); }
      return response;
    }).catch(() => caches.match('/').then((cached) => cached || new Response('<!doctype html><meta charset="utf-8"><p dir="rtl">اتصال اینترنت برقرار نیست.</p>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }))));
    return;
  }
  if (url.pathname.includes('/wp-content/themes/matin-ai/')) {
    event.respondWith(caches.match(request).then((cached) => {
      const network = fetch(request).then((response) => {
        if (response.ok) { caches.open(CACHE).then((cache) => cache.put(request, response.clone())); }
        return response;
      }).catch(() => cached);
      return cached || network;
    }));
  }
});
JS;
		exit;
	}
}
