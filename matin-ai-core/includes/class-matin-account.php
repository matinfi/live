<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer account area ([matin_ai_account] on the "my-account" page): sign-up, sign-in,
 * reservations and tickets, cancellation and profile settings.
 *
 * Form submissions are handled on template_redirect (post/redirect/get) so redirects never run
 * after output has started.
 */
final class Matin_AI_Account {
	private const TABS = [ 'tickets', 'pending', 'confirmed', 'cancelled', 'upcoming', 'past', 'settings' ];

	public static function init() : void {
		add_shortcode( 'matin_ai_account', [ __CLASS__, 'render' ] );
		// Older pages may still contain these tags; they render nothing instead of printing raw text.
		foreach ( [ 'mtn_my_bookings', 'mtn_cafe_invite_accept', 'mtn_sms_login', 'mtn_account_settings' ] as $legacy ) {
			add_shortcode( $legacy, static fn() => '' );
		}
		add_action( 'init', [ __CLASS__, 'maybe_create_page' ], 25 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'styles' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_post' ] );
	}

	public static function maybe_create_page() : void {
		if ( get_option( 'matin_ai_account_page_ok' ) ) { return; }
		$page = get_page_by_path( 'my-account', OBJECT, 'page' );
		if ( ! $page ) {
			$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'حساب کاربری من', 'post_name' => 'my-account', 'post_content' => '[matin_ai_account]' ], true );
			if ( is_wp_error( $id ) || ! $id ) { return; }
		} elseif ( ! has_shortcode( (string) $page->post_content, 'matin_ai_account' ) ) {
			$content = trim( (string) $page->post_content );
			wp_update_post( [ 'ID' => (int) $page->ID, 'post_content' => ( '' !== $content ? $content . "\n\n" : '' ) . '[matin_ai_account]' ] );
		}
		update_option( 'matin_ai_account_page_ok', 1, false );
	}

	public static function styles() : void {
		if ( ! is_page( 'my-account' ) && ! ( is_singular() && has_shortcode( (string) get_post()?->post_content, 'matin_ai_account' ) ) ) { return; }
		wp_register_style( 'matin-account', false, [], MATIN_AI_CORE_VERSION );
		wp_enqueue_style( 'matin-account' );
		wp_add_inline_style( 'matin-account', '.matin-account{max-width:1040px;margin:30px auto;padding:0 18px;direction:rtl;color:#172033}.matin-account-hero{padding:26px;border-radius:22px;background:linear-gradient(125deg,#111827,#293e5b);color:#fff;margin-bottom:18px}.matin-account-hero h1{color:#fff;margin:0 0 6px}.matin-account-hero p{margin:0;opacity:.9}.matin-account-layout{display:grid;grid-template-columns:220px 1fr;gap:18px}.matin-account-nav{display:grid;gap:8px;align-content:start}.matin-account-nav a{display:block;padding:10px 12px;border-radius:12px;background:#fff;border:1px solid #e5eaf1;color:#172033;text-decoration:none}.matin-account-nav a.is-active{background:#172033;color:#fff}.matin-account-panel{background:#fff;border:1px solid #e5eaf1;border-radius:18px;padding:22px}.matin-account input:not([type=checkbox]){width:100%;max-width:420px;padding:9px 11px;border:1px solid #ccd5e1;border-radius:9px}.matin-account button,.matin-account .matin-btn{background:#172033;color:#fff;border:0;border-radius:10px;padding:9px 16px;cursor:pointer;text-decoration:none;display:inline-block}.matin-account .matin-danger{background:#b42318}.matin-ticket{border:1px solid #e5eaf1;border-radius:16px;padding:16px;margin:12px 0}.matin-ticket h3{margin:0 0 6px}.matin-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:13px;background:#eef2f7}.matin-muted{color:#64748b;font-size:14px}.matin-notice{padding:10px 12px;border-radius:10px;margin:0 0 14px}.matin-notice.ok{background:#edf5e9;color:#294021}.matin-notice.err{background:#fdecea;color:#7a1f16}@media(max-width:780px){.matin-account-layout{grid-template-columns:1fr}}' );
	}

	/* ---------- flash messages (post/redirect/get) ---------- */

	private static function flash( string $message, bool $error = false ) : void {
		set_transient( 'matin_flash_' . self::flash_key(), [ 'message' => $message, 'error' => $error ], 60 );
	}

	private static function take_flash() : ?array {
		$key = 'matin_flash_' . self::flash_key();
		$flash = get_transient( $key );
		if ( $flash ) { delete_transient( $key ); }
		return is_array( $flash ) ? $flash : null;
	}

	private static function flash_key() : string {
		return is_user_logged_in() ? 'u' . get_current_user_id() : 'g' . md5( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
	}

	private static function notice_html( ?array $flash ) : string {
		if ( ! $flash ) { return ''; }
		return '<p class="matin-notice ' . ( $flash['error'] ? 'err' : 'ok' ) . '" role="' . ( $flash['error'] ? 'alert' : 'status' ) . '">' . esc_html( $flash['message'] ) . '</p>';
	}

	private static function account_url( array $args = [] ) : string {
		return add_query_arg( $args, home_url( '/my-account/' ) );
	}

	/* ---------- form handling ---------- */

	public static function handle_post() : void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['matin_account_action'] ) ) { return; }
		if ( ! is_page( 'my-account' ) && ! ( is_singular() && has_shortcode( (string) get_post()?->post_content, 'matin_ai_account' ) ) ) { return; }
		$action = sanitize_key( wp_unslash( $_POST['matin_account_action'] ) );
		$nonce  = sanitize_text_field( wp_unslash( $_POST['matin_account_nonce'] ?? '' ) );
		$target = self::account_url( [ 'matin_tab' => 'tickets' ] );
		if ( ! wp_verify_nonce( $nonce, 'matin_account_action' ) ) {
			self::flash( 'درخواست منقضی شده است؛ صفحه را تازه‌سازی کنید.', true );
			wp_safe_redirect( $target ); exit;
		}
		if ( 'register' === $action && ! is_user_logged_in() ) { self::do_register(); }
		elseif ( 'settings' === $action && is_user_logged_in() ) { self::do_settings(); }
		elseif ( 'cancel_reservation' === $action && is_user_logged_in() ) { $target = self::do_cancel(); }
		wp_safe_redirect( $target ); exit;
	}

	private static function do_register() : void {
		$name     = sanitize_text_field( wp_unslash( $_POST['matin_full_name'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['matin_email'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['matin_phone'] ?? '' ) );
		$password = (string) ( $_POST['matin_password'] ?? '' );
		if ( mb_strlen( $name ) < 4 || ! is_email( $email ) || strlen( preg_replace( '/\D/', '', $phone ) ) < 8 || strlen( $password ) < 10 ) {
			self::flash( 'نام کامل، ایمیل معتبر، شماره موبایل و رمز عبور حداقل ۱۰ کاراکتری وارد کنید.', true );
			return;
		}
		if ( email_exists( $email ) ) {
			self::flash( 'با این ایمیل قبلاً حساب ساخته شده است. از فرم ورود استفاده کنید.', true );
			return;
		}
		$login = sanitize_user( current( explode( '@', $email ) ), true ) ?: 'user';
		if ( username_exists( $login ) ) { $login .= wp_generate_password( 4, false, false ); }
		$user_id = wp_create_user( $login, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			self::flash( 'ساخت حساب انجام نشد. دوباره تلاش کنید.', true );
			return;
		}
		wp_update_user( [ 'ID' => $user_id, 'display_name' => $name, 'first_name' => $name ] );
		update_user_meta( $user_id, 'matin_phone', $phone );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		Matin_AI_Operations::log( 'account_created', 0, 'self_registration' );
	}

	private static function do_settings() : void {
		$name  = sanitize_text_field( wp_unslash( $_POST['matin_full_name'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['matin_phone'] ?? '' ) );
		if ( mb_strlen( $name ) < 4 || strlen( preg_replace( '/\D/', '', $phone ) ) < 8 ) {
			self::flash( 'نام و شماره موبایل معتبر وارد کنید.', true );
			return;
		}
		wp_update_user( [ 'ID' => get_current_user_id(), 'display_name' => $name, 'first_name' => $name ] );
		update_user_meta( get_current_user_id(), 'matin_phone', $phone );
		self::flash( 'اطلاعات حساب به‌روزرسانی شد.' );
	}

	/** Returns the page to redirect to after a cancellation attempt. */
	private static function do_cancel() : string {
		$id     = absint( wp_unslash( $_POST['matin_reservation_id'] ?? 0 ) );
		$result = Matin_AI_Operations::cancel( $id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::flash( $result->get_error_message(), true );
			return self::account_url( [ 'matin_tab' => 'tickets' ] );
		}
		self::flash( 'رزرو لغو شد. رسید لغو را می‌توانید از همین صفحه دریافت کنید.' );
		return self::account_url( [ 'matin_tab' => 'cancelled' ] );
	}

	/* ---------- rendering ---------- */

	public static function render() : string {
		$flash = self::take_flash();
		if ( ! is_user_logged_in() ) {
			return self::render_guest( $flash );
		}
		return self::render_member( $flash );
	}

	private static function render_guest( ?array $flash ) : string {
		$html  = '<div class="matin-account"><div class="matin-account-hero"><h1>حساب کاربری Matin AI</h1><p>برای نگهداری بلیت‌ها و مدیریت رزروها حساب بسازید یا وارد شوید.</p></div>';
		$html .= self::notice_html( $flash ) . '<div class="matin-account-layout" style="grid-template-columns:1fr 1fr">';
		$html .= '<section class="matin-account-panel" id="register"><h2>ساخت حساب</h2><form method="post">' . wp_nonce_field( 'matin_account_action', 'matin_account_nonce', true, false )
			. '<input type="hidden" name="matin_account_action" value="register">'
			. '<p><label>نام و نام خانوادگی<br><input name="matin_full_name" autocomplete="name" required minlength="4"></label></p>'
			. '<p><label>ایمیل<br><input type="email" name="matin_email" autocomplete="email" required></label></p>'
			. '<p><label>شماره موبایل<br><input name="matin_phone" autocomplete="tel" inputmode="tel" required minlength="8"></label></p>'
			. '<p><label>رمز عبور (حداقل ۱۰ کاراکتر)<br><input type="password" name="matin_password" autocomplete="new-password" required minlength="10"></label></p>'
			. '<button type="submit">ساخت حساب</button></form></section>';
		$html .= '<section class="matin-account-panel"><h2>ورود</h2>' . wp_login_form( [
			'echo' => false, 'redirect' => self::account_url(), 'remember' => true, 'form_id' => 'matin-loginform',
			'label_username' => 'ایمیل یا نام کاربری', 'label_password' => 'رمز عبور', 'label_remember' => 'مرا به خاطر بسپار', 'label_log_in' => 'ورود به حساب',
		] ) . '</section></div></div>';
		return $html;
	}

	private static function render_member( ?array $flash ) : string {
		$user = wp_get_current_user();
		$tab  = sanitize_key( wp_unslash( $_GET['matin_tab'] ?? 'tickets' ) );
		if ( ! in_array( $tab, self::TABS, true ) ) { $tab = 'tickets'; }
		$labels = [ 'tickets' => 'همه بلیت‌ها', 'pending' => 'در انتظار تأیید', 'confirmed' => 'تأییدشده', 'cancelled' => 'لغوشده', 'upcoming' => 'برنامه‌های پیش‌رو', 'past' => 'برنامه‌های گذشته', 'settings' => 'تنظیمات حساب' ];
		$nav = '<nav class="matin-account-nav" aria-label="بخش‌های حساب">';
		foreach ( $labels as $key => $label ) {
			$nav .= '<a class="' . ( $key === $tab ? 'is-active' : '' ) . '" href="' . esc_url( self::account_url( [ 'matin_tab' => $key ] ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$nav .= '<a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">خروج</a></nav>';
		$body = 'settings' === $tab ? self::render_settings( $user ) : self::render_reservations( $user, $tab );
		return '<div class="matin-account"><div class="matin-account-hero"><h1>سلام، ' . esc_html( $user->display_name ?: $user->user_login ) . '</h1><p>رزروها، بلیت‌ها و اطلاعات حساب شما در یک جا.</p></div>'
			. self::notice_html( $flash ) . '<div class="matin-account-layout">' . $nav . '<section class="matin-account-panel">' . $body . '</section></div></div>';
	}

	private static function render_settings( WP_User $user ) : string {
		return '<h2>تنظیمات حساب</h2><form method="post">' . wp_nonce_field( 'matin_account_action', 'matin_account_nonce', true, false )
			. '<input type="hidden" name="matin_account_action" value="settings">'
			. '<p><label>نام و نام خانوادگی<br><input name="matin_full_name" value="' . esc_attr( $user->display_name ) . '" required minlength="4"></label></p>'
			. '<p><label>ایمیل (تغییر آن از پشتیبانی انجام می‌شود)<br><input value="' . esc_attr( $user->user_email ) . '" disabled></label></p>'
			. '<p><label>شماره موبایل<br><input name="matin_phone" value="' . esc_attr( (string) get_user_meta( $user->ID, 'matin_phone', true ) ) . '" required minlength="8" inputmode="tel"></label></p>'
			. '<button type="submit">ذخیره اطلاعات</button></form>';
	}

	private static function render_reservations( WP_User $user, string $tab ) : string {
		$ids = get_posts( [
			'post_type' => 'matin_reservation', 'post_status' => 'private', 'numberposts' => 200, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC',
			'meta_query' => [ [ 'key' => '_matin_user_id', 'value' => (string) $user->ID ] ],
		] );
		$now  = time();
		$html = '<h2>' . esc_html( [ 'tickets' => 'بلیت‌های من', 'pending' => 'رزروهای در انتظار تأیید', 'confirmed' => 'رزروهای تأییدشده', 'cancelled' => 'رزروهای لغوشده', 'upcoming' => 'برنامه‌های پیش‌رو', 'past' => 'برنامه‌های گذشته' ][ $tab ] ?? 'بلیت‌ها' ) . '</h2>';
		$shown = 0;
		foreach ( $ids as $id ) {
			$status   = (string) get_post_meta( $id, '_matin_status', true );
			$event_id = (int) get_post_meta( $id, '_matin_event_id', true );
			$starts   = Matin_AI_Operations::event_timestamp( $event_id );
			$future   = null !== $starts && $starts > $now;
			$match = match ( $tab ) {
				'pending'   => 'pending' === $status,
				'confirmed' => 'confirmed' === $status,
				'cancelled' => 'cancelled' === $status,
				'upcoming'  => in_array( $status, [ 'pending', 'confirmed' ], true ) && $future,
				'past'      => null !== $starts && ! $future,
				default     => true,
			};
			if ( ! $match ) { continue; }
			$shown++;
			$html .= self::ticket_card( (int) $id, $status, $event_id, $starts, $future );
		}
		if ( ! $shown ) {
			$html .= '<p class="matin-muted">در این بخش رزروی پیدا نشد.</p>';
		}
		return $html;
	}

	private static function ticket_card( int $id, string $status, int $event_id, ?int $starts, bool $future ) : string {
		$event_title = get_the_title( $event_id ) ?: 'برنامه';
		$cafe_title  = get_the_title( (int) get_post_meta( $event_id, '_matin_cafe_id', true ) );
		$when        = null === $starts ? '—' : wp_date( 'Y-m-d H:i', $starts );
		$ticket_url  = add_query_arg( [ 'action' => 'matin_ticket', 'id' => $id, '_wpnonce' => wp_create_nonce( 'matin_ticket_' . $id ) ], admin_url( 'admin-post.php' ) );
		$html  = '<article class="matin-ticket"><h3>' . esc_html( $event_title ) . '</h3>';
		$html .= '<p class="matin-muted">' . esc_html( $cafe_title ) . ' · ' . esc_html( $when ) . ' · کد: <strong>' . esc_html( (string) get_post_meta( $id, '_matin_ticket_code', true ) ) . '</strong></p>';
		$html .= '<p><span class="matin-badge">' . esc_html( self::status_label( $status ) ) . '</span> · نفرات: ' . (int) get_post_meta( $id, '_matin_guests', true ) . '</p>';
		$html .= '<p><a class="matin-btn" href="' . esc_url( $ticket_url ) . '">دانلود بلیت / رسید (SVG)</a></p>';
		if ( in_array( $status, [ 'pending', 'confirmed' ], true ) && Matin_AI_Operations::cancellation_allowed( $id ) ) {
			$html .= '<form method="post" onsubmit="return confirm(\'رزرو لغو شود؟\');">' . wp_nonce_field( 'matin_account_action', 'matin_account_nonce', true, false )
				. '<input type="hidden" name="matin_account_action" value="cancel_reservation"><input type="hidden" name="matin_reservation_id" value="' . (int) $id . '">'
				. '<button class="matin-danger" type="submit">لغو رزرو</button></form>';
		}
		return $html . '</article>';
	}

	public static function status_label( string $status ) : string {
		return [ 'pending' => 'در انتظار تأیید کافه', 'confirmed' => 'تأییدشده', 'rejected' => 'ردشده', 'cancelled' => 'لغوشده' ][ $status ] ?? 'در حال بررسی';
	}
}
