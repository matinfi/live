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
		add_filter( 'authenticate', [ __CLASS__, 'block_locked_login' ], 100, 2 ); // runs after WordPress's own checks so it cannot be overwritten
		add_action( 'wp_login_failed', [ __CLASS__, 'count_failed_login' ] );
		// Older pages may still contain these tags; they render nothing instead of printing raw text.
		foreach ( [ 'mtn_my_bookings', 'mtn_cafe_invite_accept', 'mtn_sms_login', 'mtn_account_settings' ] as $legacy ) {
			add_shortcode( $legacy, static fn() => '' );
		}
		add_action( 'init', [ __CLASS__, 'maybe_create_page' ], 25 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'styles' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_post' ] );
	}

	/* ---------- brute-force protection: 10 failed sign-ins from one IP lock it for 15 minutes ---------- */

	private static function login_fail_key() : string {
		return 'matin_login_fail_' . md5( Matin_AI_REST::client_ip() );
	}

	public static function block_locked_login( $user, $username = '' ) {
		if ( (int) get_transient( self::login_fail_key() ) >= 10 ) {
			return new WP_Error( 'matin_login_locked', 'تعداد تلاش‌های ناموفق زیاد بوده است. ۱۵ دقیقه بعد دوباره تلاش کنید.' );
		}
		return $user;
	}

	public static function count_failed_login( string $username = '' ) : void {
		$key = self::login_fail_key();
		set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
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
		return is_user_logged_in() ? 'u' . get_current_user_id() : 'g' . md5( Matin_AI_REST::client_ip() );
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
		if ( 'login' === $action && ! is_user_logged_in() ) { $target = self::do_login(); }
		elseif ( 'register' === $action && ! is_user_logged_in() ) { self::do_register(); if ( is_user_logged_in() ) { $target = home_url( '/' ); } }
		elseif ( 'lost' === $action && ! is_user_logged_in() ) { self::do_lost_password(); }
		elseif ( 'reset' === $action && ! is_user_logged_in() ) { $target = self::do_reset_password(); }
		elseif ( 'settings' === $action && is_user_logged_in() ) { self::do_settings(); }
		elseif ( 'cancel_reservation' === $action && is_user_logged_in() ) { $target = self::do_cancel(); }
		wp_safe_redirect( $target ); exit;
	}

	/** Signs the visitor in with our own form (no wp-login.php screen). Returns where to redirect. */
	private static function do_login() : string {
		$login    = sanitize_text_field( wp_unslash( $_POST['matin_login'] ?? '' ) );
		$password = (string) ( $_POST['matin_password'] ?? '' );
		if ( ! self::verify_captcha() ) {
			self::flash( 'پاسخ کپچا درست نیست. دوباره تلاش کنید.', true );
			return self::account_url();
		}
		if ( '' === $login || '' === $password ) {
			self::flash( 'ایمیل یا نام کاربری و رمز عبور را وارد کنید.', true );
			return self::account_url();
		}
		$user = wp_signon( [ 'user_login' => $login, 'user_password' => $password, 'remember' => ! empty( $_POST['matin_remember'] ) ], is_ssl() );
		if ( is_wp_error( $user ) ) {
			self::flash( 'ایمیل، نام کاربری یا رمز عبور درست نیست.', true );
			return self::account_url();
		}
		return home_url( '/' );
	}

	private static function do_register() : void {
		$name     = sanitize_text_field( wp_unslash( $_POST['matin_full_name'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['matin_email'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['matin_phone'] ?? '' ) );
		$password = (string) ( $_POST['matin_password'] ?? '' );
		if ( ! self::verify_captcha() ) {
			self::flash( 'پاسخ کپچا درست نیست. دوباره تلاش کنید.', true );
			return;
		}
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


	/* ---------- Numeric captcha (single-use, expires in 10 minutes) ---------- */
	private static function fa_digits( string $text ) : string { return strtr( $text, [ '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ] ); }
	private static function latin_digits( string $text ) : string { return strtr( $text, [ '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ] ); }
	private static function captcha_field() : string {
		$id = wp_generate_password( 16, false, false );
		$a  = wp_rand( 1, 9 );
		$b  = wp_rand( 1, 9 );
		set_transient( 'matin_cap_' . $id, $a + $b, 10 * MINUTE_IN_SECONDS );
		return '<input type="hidden" name="matin_captcha_id" value="' . esc_attr( $id ) . '">'
			. '<label class="matin-field"><span>کپچا: حاصل ' . esc_html( self::fa_digits( (string) $a ) ) . ' + ' . esc_html( self::fa_digits( (string) $b ) ) . ' را بنویسید</span><input name="matin_captcha" inputmode="numeric" autocomplete="off" required maxlength="3"></label>';
	}
	/** Consumes the challenge whatever the outcome, so a captcha can never be replayed. */
	private static function verify_captcha() : bool {
		$id = sanitize_key( wp_unslash( $_POST['matin_captcha_id'] ?? '' ) );
		$answer = trim( self::latin_digits( sanitize_text_field( wp_unslash( $_POST['matin_captcha'] ?? '' ) ) ) );
		if ( '' === $id ) { return false; }
		$expected = get_transient( 'matin_cap_' . $id );
		delete_transient( 'matin_cap_' . $id );
		return false !== $expected && '' !== $answer && ctype_digit( $answer ) && (int) $answer === (int) $expected;
	}

	/* ---------- Password recovery on our own pages (no wp-login.php) ---------- */
	private static function do_lost_password() : void {
		if ( ! self::verify_captcha() ) { self::flash( 'پاسخ کپچا درست نیست. دوباره تلاش کنید.', true ); return; }
		$login = sanitize_text_field( wp_unslash( $_POST['matin_login'] ?? '' ) );
		$user  = '' !== $login ? ( is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login ) ) : false;
		if ( $user ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$url  = add_query_arg( [ 'matin_reset' => rawurlencode( $user->user_login ), 'key' => $key ], self::account_url() );
				$body = "سلام {$user->display_name}،\n\nبرای تعیین رمز جدید حساب Matin AI روی پیوند زیر کلیک کنید:\n{$url}\n\nاگر شما درخواست نکرده‌اید، این پیام را نادیده بگیرید.";
				$sent = wp_mail( $user->user_email, 'بازیابی رمز عبور Matin AI', $body );
				if ( ! $sent ) { Matin_AI_Operations::log( 'password_reset_email_failed', (int) $user->ID, 'wp_mail_returned_false' ); }
			}
		}
		// Same answer whether or not the account exists, so the form cannot be used to find accounts.
		self::flash( 'اگر حسابی با این مشخصات وجود داشته باشد، پیوند بازیابی به ایمیل آن ارسال شد.' );
	}

	private static function do_reset_password() : string {
		$login = sanitize_user( wp_unslash( $_POST['matin_reset_login'] ?? '' ), true );
		$key   = sanitize_text_field( wp_unslash( $_POST['matin_reset_key'] ?? '' ) );
		$pass  = (string) ( $_POST['matin_password'] ?? '' );
		$pass2 = (string) ( $_POST['matin_password2'] ?? '' );
		$user  = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) { self::flash( 'این پیوند منقضی یا نامعتبر است. دوباره درخواست بازیابی بدهید.', true ); return self::account_url(); }
		if ( strlen( $pass ) < 10 || $pass !== $pass2 ) { self::flash( 'رمز جدید باید حداقل ۱۰ نویسه باشد و دو بار یکسان وارد شود.', true ); return self::account_url( [ 'matin_reset' => $login, 'key' => $key ] ); }
		reset_password( $user, $pass );
		Matin_AI_Operations::log( 'password_reset_completed', (int) $user->ID, 'self_service' );
		self::flash( 'رمز شما تغییر کرد. اکنون وارد شوید.' );
		return self::account_url();
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
		$nonce = wp_nonce_field( 'matin_account_action', 'matin_account_nonce', true, false );
		$reset_login = sanitize_user( wp_unslash( $_GET['matin_reset'] ?? '' ), true );
		$reset_key   = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
		if ( '' !== $reset_login && '' !== $reset_key ) {
			$user = check_password_reset_key( $reset_key, $reset_login );
			$html = '<div class="matin-auth"><div class="matin-auth-intro"><span class="matin-auth-mark" aria-hidden="true">م</span><h1>تعیین رمز جدید</h1></div>' . self::notice_html( $flash );
			if ( is_wp_error( $user ) ) { return $html . '<p class="matin-auth-note">این پیوند منقضی یا نامعتبر است. <a href="' . esc_url( self::account_url( [ 'matin_form' => 'lost' ] ) ) . '">دوباره درخواست بدهید</a>.</p></div>'; }
			return $html . '<div class="matin-auth-card"><form class="matin-auth-form matin-auth-reset" method="post" action="' . esc_url( self::account_url() ) . '">' . $nonce
				. '<input type="hidden" name="matin_account_action" value="reset"><input type="hidden" name="matin_reset_login" value="' . esc_attr( $reset_login ) . '"><input type="hidden" name="matin_reset_key" value="' . esc_attr( $reset_key ) . '">'
				. '<label class="matin-field"><span>رمز جدید (حداقل ۱۰ نویسه)</span><input type="password" name="matin_password" autocomplete="new-password" required minlength="10"></label>'
				. '<label class="matin-field"><span>تکرار رمز جدید</span><input type="password" name="matin_password2" autocomplete="new-password" required minlength="10"></label>'
				. '<button type="submit" class="matin-auth-submit">ثبت رمز جدید</button></form></div></div>';
		}
		if ( 'lost' === sanitize_key( wp_unslash( $_GET['matin_form'] ?? '' ) ) ) {
			return '<div class="matin-auth"><div class="matin-auth-intro"><span class="matin-auth-mark" aria-hidden="true">م</span><h1>بازیابی رمز عبور</h1><p>ایمیل یا نام کاربری را وارد کنید تا پیوند بازیابی ارسال شود.</p></div>' . self::notice_html( $flash )
				. '<div class="matin-auth-card"><form class="matin-auth-form" method="post" action="' . esc_url( self::account_url() ) . '">' . $nonce
				. '<input type="hidden" name="matin_account_action" value="lost">'
				. '<label class="matin-field"><span>ایمیل یا نام کاربری</span><input name="matin_login" autocomplete="username" inputmode="email" required></label>'
				. self::captcha_field()
				. '<button type="submit" class="matin-auth-submit">ارسال پیوند بازیابی</button></form>'
				. '<p class="matin-auth-note"><a href="' . esc_url( self::account_url() ) . '">بازگشت به ورود</a></p></div></div>';
		}
		$html  = '<div class="matin-auth"><div class="matin-auth-intro"><span class="matin-auth-mark" aria-hidden="true">م</span><h1>ورود یا ساخت حساب</h1><p>برای ارسال پیام، نگهداری بلیت‌ها و مدیریت رزروها وارد شوید.</p></div>';
		$html .= self::notice_html( $flash );
		$html .= '<div class="matin-auth-card">';
		$html .= '<input type="radio" name="matin-auth-tab" id="matin-tab-login" class="matin-auth-radio" checked>';
		$html .= '<input type="radio" name="matin-auth-tab" id="matin-tab-register" class="matin-auth-radio">';
		$html .= '<div class="matin-auth-tabs" role="group" aria-label="انتخاب فرم"><label for="matin-tab-login" class="matin-auth-tab">ورود</label><label for="matin-tab-register" class="matin-auth-tab">ساخت حساب</label></div>';
		$html .= '<form id="login" class="matin-auth-form matin-auth-login" method="post" action="' . esc_url( self::account_url() ) . '">' . $nonce
			. '<input type="hidden" name="matin_account_action" value="login">'
			. '<label class="matin-field"><span>ایمیل یا نام کاربری</span><input name="matin_login" autocomplete="username" inputmode="email" required></label>'
			. '<label class="matin-field"><span>رمز عبور</span><input type="password" name="matin_password" autocomplete="current-password" required></label>'
			. '<label class="matin-check"><input type="checkbox" name="matin_remember" value="1" checked><span>مرا به خاطر بسپار</span></label>'
			. self::captcha_field()
			. '<button type="submit" class="matin-auth-submit">ورود به حساب</button></form>'
			. '<p class="matin-auth-note"><a href="' . esc_url( self::account_url( [ 'matin_form' => 'lost' ] ) ) . '">رمز عبور را فراموش کرده‌ام</a></p>';
		$html .= '<form id="register" class="matin-auth-form matin-auth-register" method="post" action="' . esc_url( self::account_url() ) . '">' . $nonce
			. '<input type="hidden" name="matin_account_action" value="register">'
			. '<label class="matin-field"><span>نام و نام خانوادگی</span><input name="matin_full_name" autocomplete="name" required minlength="4"></label>'
			. '<label class="matin-field"><span>ایمیل</span><input type="email" name="matin_email" autocomplete="email" inputmode="email" required></label>'
			. '<label class="matin-field"><span>شماره موبایل</span><input name="matin_phone" autocomplete="tel" inputmode="tel" required minlength="8"></label>'
			. '<label class="matin-field"><span>رمز عبور (حداقل ۱۰ نویسه)</span><input type="password" name="matin_password" autocomplete="new-password" required minlength="10"></label>'
			. self::captcha_field()
			. '<button type="submit" class="matin-auth-submit">ساخت حساب</button></form>';
		$html .= '</div></div>';
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
