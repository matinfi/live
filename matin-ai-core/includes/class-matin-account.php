<?php
defined( 'ABSPATH' ) || exit;

/**
 * Guest account page ([matin_ai_account]): registration, login, phone verification,
 * reservation list (pending / confirmed / cancelled), ticket download and guest cancellation.
 */
final class Matin_AI_Account {
	private const TABS      = [ 'reservations', 'settings' ];
	private const STATUSES  = [ 'all', 'pending', 'confirmed', 'cancelled' ];

	public static function init() : void {
		add_shortcode( 'matin_ai_account', [ __CLASS__, 'render' ] );
		add_action( 'init', [ __CLASS__, 'maybe_create_page' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'styles' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_handle_post' ] );
	}

	/** Form posts are handled before any output (post/redirect/get), so redirects always work. */
	public static function maybe_handle_post() : void {
		if ( empty( $_POST['matin_account_action'] ) || ! is_page( 'my-account' ) ) { return; }
		$redirect = self::handle_post();
		wp_safe_redirect( $redirect ?: home_url( '/my-account/' ) );
		exit;
	}

	public static function maybe_create_page() : void {
		if ( get_option( 'matin_ai_account_page_created' ) ) { return; }
		$page = get_page_by_path( 'my-account' );
		if ( ! $page ) {
			$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'حساب کاربری من', 'post_name' => 'my-account', 'post_content' => '[matin_ai_account]' ] );
			if ( ! is_wp_error( $id ) && $id ) { update_option( 'matin_ai_account_page_created', 1 ); }
		} else {
			update_option( 'matin_ai_account_page_created', 1 );
		}
	}

	public static function styles() : void {
		if ( ! is_page( 'my-account' ) ) { return; }
		wp_register_style( 'matin-account', false, [], MATIN_AI_CORE_VERSION );
		wp_enqueue_style( 'matin-account' );
		wp_add_inline_style( 'matin-account', '.matin-account{max-width:1040px;margin:30px auto;padding:0 18px;direction:rtl;color:#172033}.matin-account-hero{padding:26px;border-radius:22px;background:linear-gradient(125deg,#111827,#293e5b);color:#fff;margin-bottom:20px}.matin-account-hero h1{color:#fff}.matin-account-layout{display:grid;grid-template-columns:230px minmax(0,1fr);gap:18px}.matin-account-nav,.matin-account-panel{border:1px solid #dce3ec;border-radius:18px;padding:18px;background:#fff}.matin-account-nav a{display:block;padding:11px;border-radius:10px;text-decoration:none;color:inherit}.matin-account-nav a:hover,.matin-account-nav a.is-active{background:#eef3f9}.matin-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 14px}.matin-tabs a{padding:7px 12px;border-radius:999px;border:1px solid #cdd6e2;text-decoration:none;color:inherit;font-size:14px}.matin-tabs a.is-active{background:#172033;color:#fff;border-color:#172033}.matin-ticket{border:1px solid #dce3ec;border-radius:15px;padding:16px;margin:12px 0;background:#fff}.matin-ticket-row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}.matin-status{display:inline-block;padding:5px 9px;border-radius:20px;background:#eef2f7;font-size:12px}.matin-status-confirmed{background:#dcfce7;color:#166534}.matin-status-pending{background:#fef9c3;color:#713f12}.matin-status-rejected,.matin-status-cancelled{background:#fee2e2;color:#991b1b}.matin-account input{max-width:100%;padding:10px;border:1px solid #ccd5e1;border-radius:9px}.matin-account button,.matin-account .matin-btn{padding:10px 14px;border-radius:10px;border:1px solid #cdd6e2;cursor:pointer;background:#fff;text-decoration:none;color:inherit;display:inline-block}.matin-account .matin-btn-primary{background:#314a2d;color:#fff;border-color:#314a2d}.matin-account .matin-btn-danger{color:#991b1b;border-color:#fca5a5}.matin-flash{padding:12px 14px;border-radius:12px;background:#f1f5f9;margin-bottom:14px}.matin-flash.is-error{background:#fee2e2;color:#7f1d1d}.matin-flash.is-ok{background:#dcfce7;color:#14532d}.matin-section-title{margin:18px 0 4px;font-size:15px;color:#475569}.matin-muted{color:#64748b;font-size:14px}.matin-dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 14px}.matin-dl dt{color:#64748b}.matin-dl dd{margin:0;font-weight:600}@media(max-width:680px){.matin-account-layout{grid-template-columns:1fr}.matin-account-nav{display:flex;gap:5px;overflow:auto}.matin-account-nav a{white-space:nowrap}}' );
	}

	/* ---------- flash messages (post/redirect/get) ---------- */

	private static function flash_key() : string {
		$who = is_user_logged_in() ? (string) get_current_user_id() : md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return 'matin_flash_' . $who;
	}

	private static function flash( string $text, bool $error = true ) : void {
		set_transient( self::flash_key(), [ 'text' => $text, 'error' => $error ], 120 );
	}

	private static function take_flash() : ?array {
		$data = get_transient( self::flash_key() );
		if ( $data ) { delete_transient( self::flash_key() ); }
		return is_array( $data ) ? $data : null;
	}

	private static function notice_from_query() : ?array {
		$map = [
			'google_off'    => [ 'ورود با Google فعال نیست.', true ],
			'google_failed' => [ 'ورود با Google انجام نشد. دوباره تلاش کنید.', true ],
		];
		$key = sanitize_key( wp_unslash( $_GET['matin_notice'] ?? '' ) );
		return isset( $map[ $key ] ) ? [ 'text' => $map[ $key ][0], 'error' => $map[ $key ][1] ] : null;
	}

	/* ---------- rendering ---------- */

	public static function render() : string {
		$message = self::take_flash() ?? self::notice_from_query();
		if ( ! is_user_logged_in() ) {
			return self::render_guest( $message );
		}
		return self::render_member( $message );
	}

	private static function notice_html( ?array $message ) : string {
		if ( ! $message ) { return ''; }
		return '<p class="matin-flash ' . ( $message['error'] ? 'is-error' : 'is-ok' ) . '" role="' . ( $message['error'] ? 'alert' : 'status' ) . '">' . esc_html( $message['text'] ) . '</p>';
	}

	private static function nonce_field() : string {
		return wp_nonce_field( 'matin_account_action', 'matin_account_nonce', true, false );
	}

	private static function render_guest( ?array $message ) : string {
		$google = Matin_AI_Google::enabled()
			? '<p><a class="matin-btn" href="' . esc_url( Matin_AI_Google::start_url() ) . '">ورود با Google</a></p>'
			: '<p class="matin-muted">ورود با Google هنوز در تنظیمات فعال نشده است.</p>';
		return '<div class="matin-account"><div class="matin-account-hero"><h1>حساب کاربری Matin AI</h1><p>برای نگهداری بلیت‌ها و مدیریت رزروها حساب بسازید یا وارد شوید.</p></div>' . self::notice_html( $message )
			. '<div class="matin-account-layout"><section class="matin-account-panel"><h2>ساخت حساب</h2><form method="post">' . self::nonce_field()
			. '<input type="hidden" name="matin_account_action" value="register"><p><label>نام و نام خانوادگی<br><input name="matin_full_name" autocomplete="name" required minlength="4"></label></p>'
			. '<p><label>ایمیل<br><input type="email" name="matin_email" autocomplete="email" required></label></p>'
			. '<p><label>شماره موبایل<br><input name="matin_phone" autocomplete="tel" inputmode="tel" required minlength="8"></label></p>'
			. '<p><label>رمز عبور (حداقل ۱۰ کاراکتر)<br><input type="password" name="matin_password" autocomplete="new-password" required minlength="10"></label></p>'
			. '<button type="submit">ساخت حساب</button></form></section>'
			. '<section class="matin-account-panel"><h2>قبلاً حساب ساخته‌اید؟</h2>' . wp_login_form( [ 'echo' => false, 'redirect' => home_url( '/my-account/' ), 'remember' => true, 'form_id' => 'matin-loginform', 'label_username' => 'ایمیل یا نام کاربری', 'label_password' => 'رمز عبور', 'label_remember' => 'مرا به خاطر بسپار', 'label_log_in' => 'ورود به حساب' ] ) . $google . '</section></div></div>';
	}

	private static function render_member( ?array $message ) : string {
		$user = wp_get_current_user();
		$tab  = sanitize_key( wp_unslash( $_GET['matin_tab'] ?? 'reservations' ) );
		if ( 'tickets' === $tab ) { $tab = 'reservations'; }
		if ( ! in_array( $tab, self::TABS, true ) ) { $tab = 'reservations'; }

		$nav = '<nav class="matin-account-nav">'
			. '<a class="' . ( 'reservations' === $tab ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'matin_tab', 'reservations', home_url( '/my-account/' ) ) ) . '">🎟 بلیت‌ها و رزروها</a>'
			. '<a class="' . ( 'settings' === $tab ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'matin_tab', 'settings', home_url( '/my-account/' ) ) ) . '">⚙ تنظیمات حساب</a>'
			. '<a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">خروج</a></nav>';

		$body = 'settings' === $tab ? self::render_settings( $user ) : self::render_reservations( $user );
		return '<div class="matin-account"><div class="matin-account-hero"><h1>سلام، ' . esc_html( $user->display_name ?: $user->user_login ) . '</h1><p>رزروها، بلیت‌ها و اطلاعات حساب شما در یک جا.</p></div>'
			. self::notice_html( $message ) . '<div class="matin-account-layout">' . $nav . '<section class="matin-account-panel">' . $body . '</section></div></div>';
	}

	private static function render_settings( WP_User $user ) : string {
		$phone    = (string) get_user_meta( $user->ID, 'matin_phone', true );
		$verified = Matin_AI_Sms::is_verified( $user->ID );
		$html  = '<h2>تنظیمات حساب</h2><form method="post">' . self::nonce_field() . '<input type="hidden" name="matin_account_action" value="settings">';
		$html .= '<p><label>نام و نام خانوادگی<br><input name="matin_full_name" value="' . esc_attr( $user->display_name ) . '" required minlength="4"></label></p>';
		$html .= '<p><label>شماره موبایل<br><input name="matin_phone" value="' . esc_attr( $phone ) . '" required minlength="8" inputmode="tel"></label></p>';
		$html .= '<p class="matin-muted">' . ( $verified ? '✅ شماره موبایل تأیید شده است.' : '⚠️ شماره موبایل هنوز تأیید نشده است.' ) . ' تغییر شماره، تأیید آن را لغو می‌کند.</p><button type="submit">ذخیره اطلاعات</button></form>';

		if ( ! $verified ) {
			$html .= '<h3 class="matin-section-title">تأیید شماره موبایل</h3>';
			if ( Matin_AI_Sms::is_configured() ) {
				$html .= '<form method="post">' . self::nonce_field() . '<input type="hidden" name="matin_account_action" value="otp_send"><input type="hidden" name="matin_phone" value="' . esc_attr( $phone ) . '"><button type="submit">ارسال کد تأیید</button></form>';
				$html .= '<form method="post" style="margin-top:10px">' . self::nonce_field() . '<input type="hidden" name="matin_account_action" value="otp_verify"><label>کد ۶ رقمی<br><input name="matin_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" dir="ltr"></label> <button type="submit">تأیید</button></form>';
			} else {
				$html .= '<p class="matin-muted">ارسال پیامک تأیید هنوز فعال نشده است.</p>';
			}
		}
		return $html;
	}

	private static function render_reservations( WP_User $user ) : string {
		$status_filter = sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) );
		if ( ! in_array( $status_filter, self::STATUSES, true ) ) { $status_filter = 'all'; }
		$selected = absint( $_GET['id'] ?? 0 );
		if ( $selected && (int) get_post_meta( $selected, '_matin_user_id', true ) === $user->ID && 'matin_reservation' === get_post_type( $selected ) ) {
			return self::render_detail( $selected );
		}

		$ids = get_posts( [ 'post_type' => 'matin_reservation', 'post_status' => 'private', 'numberposts' => 200, 'fields' => 'ids', 'meta_query' => [ [ 'key' => '_matin_user_id', 'value' => $user->ID, 'compare' => '=' ] ], 'orderby' => 'date', 'order' => 'DESC' ] );
		$html = '<h2>رزروها و بلیت‌ها</h2><nav class="matin-tabs">';
		foreach ( [ 'all' => 'همه', 'pending' => 'در انتظار تأیید', 'confirmed' => 'تأییدشده', 'cancelled' => 'لغوشده' ] as $value => $label ) {
			$html .= '<a class="' . ( $status_filter === $value ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( [ 'matin_tab' => 'reservations', 'status' => $value ], home_url( '/my-account/' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</nav>';

		$upcoming = [];
		$past     = [];
		foreach ( $ids as $id ) {
			$status = (string) get_post_meta( $id, '_matin_status', true );
			if ( 'all' !== $status_filter && $status !== $status_filter ) { continue; }
			$starts = Matin_AI_Rules::parse_datetime( (string) get_post_meta( (int) get_post_meta( $id, '_matin_event_id', true ), '_matin_starts_at', true ), wp_timezone() );
			if ( null !== $starts && $starts > time() ) { $upcoming[] = $id; } else { $past[] = $id; }
		}
		if ( ! $upcoming && ! $past ) {
			return $html . '<p>رزروی با این فیلتر پیدا نشد. از صفحهٔ کافه‌ها یک برنامه را انتخاب کنید.</p>';
		}
		if ( $upcoming ) { $html .= '<h3 class="matin-section-title">رزروهای آینده</h3>' . implode( '', array_map( [ __CLASS__, 'card' ], $upcoming ) ); }
		if ( $past ) { $html .= '<h3 class="matin-section-title">برنامه‌های گذشته</h3>' . implode( '', array_map( [ __CLASS__, 'card' ], $past ) ); }
		return $html;
	}

	private static function card( int $id ) : string {
		$event_id = (int) get_post_meta( $id, '_matin_event_id', true );
		$status   = (string) get_post_meta( $id, '_matin_status', true );
		return '<article class="matin-ticket"><div class="matin-ticket-row"><strong>' . esc_html( get_the_title( $event_id ) ?: 'رزرو Matin AI' ) . '</strong><span class="matin-status matin-status-' . esc_attr( $status ) . '">' . esc_html( Matin_AI_Rules::status_label( $status ) ) . '</span></div>'
			. '<p class="matin-muted">کافه: ' . esc_html( get_the_title( (int) get_post_meta( $event_id, '_matin_cafe_id', true ) ) ?: '—' ) . ' · زمان: ' . esc_html( str_replace( 'T', ' ', (string) get_post_meta( $event_id, '_matin_starts_at', true ) ) ) . ' · نفرات: ' . esc_html( (string) get_post_meta( $id, '_matin_guests', true ) ) . '</p>'
			. '<p><a class="matin-btn" href="' . esc_url( add_query_arg( [ 'matin_tab' => 'reservations', 'id' => $id ], home_url( '/my-account/' ) ) ) . '">مشاهده جزئیات</a></p></article>';
	}

	private static function render_detail( int $id ) : string {
		$event_id = (int) get_post_meta( $id, '_matin_event_id', true );
		$status   = (string) get_post_meta( $id, '_matin_status', true );
		$starts_raw = (string) get_post_meta( $event_id, '_matin_starts_at', true );
		$starts   = Matin_AI_Rules::parse_datetime( $starts_raw, wp_timezone() );
		$cancellable = Matin_AI_Rules::can_cancel( $status, $starts, time() );

		$html = '<p><a href="' . esc_url( add_query_arg( 'matin_tab', 'reservations', home_url( '/my-account/' ) ) ) . '">← بازگشت به لیست</a></p>';
		$html .= '<h2>' . esc_html( get_the_title( $event_id ) ?: 'رزرو' ) . '</h2>';
		$html .= '<dl class="matin-dl"><dt>وضعیت</dt><dd>' . esc_html( Matin_AI_Rules::status_label( $status ) ) . '</dd>'
			. '<dt>کافه</dt><dd>' . esc_html( get_the_title( (int) get_post_meta( $event_id, '_matin_cafe_id', true ) ) ) . '</dd>'
			. '<dt>زمان برنامه</dt><dd>' . esc_html( str_replace( 'T', ' ', $starts_raw ) ) . '</dd>'
			. '<dt>تعداد نفرات</dt><dd>' . esc_html( (string) get_post_meta( $id, '_matin_guests', true ) ) . '</dd>'
			. '<dt>کد بلیت</dt><dd>' . esc_html( (string) get_post_meta( $id, '_matin_ticket_code', true ) ) . '</dd>'
			. '<dt>پیام به کافه</dt><dd>' . esc_html( Matin_AI_Messaging::status_for_reservation( $id, 'cafe' ) ) . '</dd>'
			. '<dt>پیام به شما</dt><dd>' . esc_html( Matin_AI_Messaging::status_for_reservation( $id, 'customer' ) ) . '</dd></dl>';

		$html .= '<p class="matin-tabs">';
		if ( in_array( $status, [ 'pending', 'confirmed' ], true ) ) {
			$html .= '<a class="matin-btn matin-btn-primary" href="' . esc_url( Matin_AI_Tickets::download_url( $id, 'ticket' ) ) . '">دانلود بلیت (تصویر)</a>';
		}
		if ( 'cancelled' === $status ) {
			$html .= '<a class="matin-btn" href="' . esc_url( Matin_AI_Tickets::download_url( $id, 'cancellation' ) ) . '">دانلود تأییدیه لغو</a>';
		}
		$html .= '</p>';
		if ( 'pending' === $status ) { $html .= '<p class="matin-muted">تا تأیید کافه، این رسید بلیت قطعی نیست.</p>'; }

		if ( $cancellable ) {
			$html .= '<form method="post" data-confirm="آیا از لغو این رزرو مطمئن هستید؟ بلیت قبلی نامعتبر می‌شود.">' . self::nonce_field() . '<input type="hidden" name="matin_account_action" value="cancel"><input type="hidden" name="reservation_id" value="' . (int) $id . '"><button class="matin-btn matin-btn-danger" type="submit">لغو رزرو</button></form>';
		} elseif ( in_array( $status, [ 'pending', 'confirmed' ], true ) ) {
			$html .= '<p class="matin-muted">لغو فقط تا ۶ ساعت قبل از شروع برنامه امکان‌پذیر است.</p>';
		}
		return $html;
	}

	/* ---------- actions (called from template_redirect; always end with a redirect) ---------- */

	/** Returns the URL to redirect to after handling the POST. Results are passed through flash messages. */
	private static function handle_post() : string {
		$back = add_query_arg( 'matin_tab', 'settings', home_url( '/my-account/' ) );
		$action = sanitize_key( wp_unslash( $_POST['matin_account_action'] ?? '' ) );
		if ( ! isset( $_POST['matin_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['matin_account_nonce'] ) ), 'matin_account_action' ) ) {
			self::flash( 'درخواست منقضی شده است؛ صفحه را تازه‌سازی کنید.' );
			return home_url( '/my-account/' );
		}
		if ( 'register' === $action && ! is_user_logged_in() ) { return self::register(); }
		if ( ! is_user_logged_in() ) {
			self::flash( 'ابتدا وارد حساب شوید.' );
			return home_url( '/my-account/' );
		}

		switch ( $action ) {
			case 'settings':
				$name  = sanitize_text_field( wp_unslash( $_POST['matin_full_name'] ?? '' ) );
				$phone = Matin_AI_Sms::normalize_phone( (string) wp_unslash( $_POST['matin_phone'] ?? '' ) );
				if ( strlen( $name ) < 4 || '' === $phone ) {
					self::flash( 'نام و شماره موبایل معتبر وارد کنید.' );
					return $back;
				}
				$user_id = get_current_user_id();
				wp_update_user( [ 'ID' => $user_id, 'display_name' => $name, 'first_name' => $name ] );
				if ( $phone !== (string) get_user_meta( $user_id, 'matin_phone', true ) ) {
					update_user_meta( $user_id, 'matin_phone', $phone );
					update_user_meta( $user_id, 'matin_phone_verified', 0 );
				}
				self::flash( 'اطلاعات حساب به‌روزرسانی شد.', false );
				return $back;

			case 'otp_send':
				$result = Matin_AI_Sms::request_code( get_current_user_id(), (string) wp_unslash( $_POST['matin_phone'] ?? '' ) );
				self::flash( $result['message'], ! $result['ok'] );
				return $back;

			case 'otp_verify':
				$result = Matin_AI_Sms::verify_code( get_current_user_id(), (string) wp_unslash( $_POST['matin_code'] ?? '' ) );
				self::flash( $result['message'], ! $result['ok'] );
				return $back;

			case 'cancel':
				$request = new WP_REST_Request( 'POST' );
				$request->set_param( 'id', absint( $_POST['reservation_id'] ?? 0 ) );
				$response = Matin_AI_REST::cancel( $request );
				if ( is_wp_error( $response ) ) {
					self::flash( $response->get_error_message() );
				} else {
					self::flash( (string) $response->get_data()['message'], false );
				}
				return add_query_arg( [ 'matin_tab' => 'reservations', 'status' => 'all' ], home_url( '/my-account/' ) );
		}
		return home_url( '/my-account/' );
	}

	private static function register() : string {
		$name     = sanitize_text_field( wp_unslash( $_POST['matin_full_name'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['matin_email'] ?? '' ) );
		$phone    = Matin_AI_Sms::normalize_phone( (string) wp_unslash( $_POST['matin_phone'] ?? '' ) );
		$password = (string) ( $_POST['matin_password'] ?? '' );
		if ( strlen( $name ) < 4 || ! is_email( $email ) || '' === $phone || strlen( $password ) < 10 || email_exists( $email ) ) {
			self::flash( 'نام کامل، ایمیل معتبر، شماره موبایل ایرانی و رمز عبور حداقل ۱۰ کاراکتری وارد کنید. ایمیل نباید قبلاً ثبت شده باشد.' );
			return home_url( '/my-account/' );
		}
		$username = sanitize_user( current( explode( '@', $email ) ), true ) ?: 'user';
		if ( username_exists( $username ) ) { $username .= wp_generate_password( 4, false, false ); }
		$user_id = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			self::flash( 'ساخت حساب انجام نشد. دوباره تلاش کنید.' );
			return home_url( '/my-account/' );
		}
		wp_update_user( [ 'ID' => $user_id, 'display_name' => $name, 'first_name' => $name ] );
		update_user_meta( $user_id, 'matin_phone', $phone );
		update_user_meta( $user_id, 'matin_phone_verified', 0 );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		self::flash( 'حساب ساخته شد. برای ثبت رزرو، شماره موبایل خود را تأیید کنید.', false );
		return add_query_arg( 'matin_tab', 'settings', home_url( '/my-account/' ) );
	}
}
