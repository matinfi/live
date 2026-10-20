<?php
defined( 'ABSPATH' ) || exit;

final class Matin_AI_Admin {
	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_head', [ __CLASS__, 'polish_admin_tabs' ] );
		add_action( 'admin_post_matin_admin_reservation_action', [ __CLASS__, 'reservation_action' ] );
		add_action( 'admin_post_matin_export_reservation_report', [ __CLASS__, 'export_reservation_report' ] );
		add_action( 'admin_init', [ __CLASS__, 'settings' ] );
		add_action( 'admin_notices', [ __CLASS__, 'secret_notice' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'styles' ] );
		add_action( 'wp_ajax_matin_ai_test_connection', [ __CLASS__, 'ajax_test_ai_connection' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'reservation_summary_box' ] );
	}
	public static function menu() : void {
		add_menu_page( 'Matin AI | مرکز مدیریت', 'Matin AI', 'manage_options', 'matin-ai-dashboard', [ __CLASS__, 'dashboard_page' ], 'dashicons-format-chat', 26 );
		add_submenu_page( 'matin-ai-dashboard', 'نمای کلی', 'نمای کلی', 'manage_options', 'matin-ai-dashboard', [ __CLASS__, 'dashboard_page' ] );
		add_submenu_page( 'matin-ai-dashboard', 'مدیریت رزروها', 'رزروها', 'manage_options', 'matin-ai-reservations', [ __CLASS__, 'reservations_page' ] );
		add_submenu_page( 'matin-ai-dashboard', 'گزارش رزروها', 'گزارش‌ها', 'manage_options', 'matin-ai-reports', [ __CLASS__, 'reports_page' ] );
		add_submenu_page( 'matin-ai-dashboard', 'مدیریت کاربران', 'کاربران', 'list_users', 'matin-ai-users', [ __CLASS__, 'users_page' ] );
		add_submenu_page( 'matin-ai-dashboard', 'گزارش رویدادهای سیستمی', 'لاگ‌ها', 'manage_options', 'matin-ai-logs', [ __CLASS__, 'logs_page' ] );
		add_submenu_page( 'matin-ai-dashboard', 'تنظیمات Matin AI', 'تنظیمات', 'manage_options', 'matin-ai-settings', [ __CLASS__, 'page' ] );
	}
	public static function settings() : void {
		register_setting( 'matin_ai_settings', 'matin_ai_options', [ 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
	}
	public static function sanitize( $input ) : array {
		$input = is_array( $input ) ? $input : [];
		$defaults = self::defaults(); $out = []; $old_options = (array) get_option( 'matin_ai_options', [] );
		foreach ( [ 'commission_enabled', 'cafe_fee_enabled', 'google_login_enabled', 'apple_login_enabled', 'whatsapp_enabled', 'ai_enabled', 'ai_fallback_enabled', 'sidebar_notice_enabled', 'sidebar_search_enabled', 'sidebar_new_chat_enabled' ] as $key ) { $out[ $key ] = empty( $input[ $key ] ) ? 0 : 1; }
		$out['commission_amount'] = max( 0, min( 1000000, absint( $input['commission_amount'] ?? $defaults['commission_amount'] ) ) );
		$out['cafe_fee_mode'] = in_array( $input['cafe_fee_mode'] ?? '', [ 'per_reservation', 'monthly', 'fixed' ], true ) ? $input['cafe_fee_mode'] : 'monthly';
		$out['cafe_fee_amount'] = max( 0, min( 100000000, absint( $input['cafe_fee_amount'] ?? 0 ) ) );
		$out['whatsapp_number'] = preg_replace( '/[^0-9]/', '', (string) ( $input['whatsapp_number'] ?? '' ) );
		$out['whatsapp_access_token'] = '' !== trim( (string) ( $input['whatsapp_access_token'] ?? '' ) ) ? self::encrypt_secret( sanitize_text_field( $input['whatsapp_access_token'] ), (string) ( $old_options['whatsapp_access_token'] ?? '' ) ) : (string) ( $old_options['whatsapp_access_token'] ?? '' );
		$out['whatsapp_phone_number_id'] = sanitize_text_field( $input['whatsapp_phone_number_id'] ?? ( $old_options['whatsapp_phone_number_id'] ?? '' ) );
		$out['whatsapp_customer_template'] = sanitize_key( $input['whatsapp_customer_template'] ?? ( $old_options['whatsapp_customer_template'] ?? 'matin_reservation_ticket' ) );
		$out['whatsapp_cafe_template'] = sanitize_key( $input['whatsapp_cafe_template'] ?? ( $old_options['whatsapp_cafe_template'] ?? 'matin_cafe_booking_alert' ) );
		$out['whatsapp_template_language'] = sanitize_text_field( $input['whatsapp_template_language'] ?? ( $old_options['whatsapp_template_language'] ?? 'tr' ) );
		$out['ai_provider'] = in_array( $input['ai_provider'] ?? '', [ 'gemini', 'openai', 'openrouter', 'compatible' ], true ) ? $input['ai_provider'] : 'gemini';
		$out['ai_model'] = sanitize_text_field( $input['ai_model'] ?? '' );
		$out['ai_api_key'] = '' !== trim( (string) ( $input['ai_api_key'] ?? '' ) ) ? self::encrypt_secret( sanitize_text_field( $input['ai_api_key'] ), (string) ( $old_options['ai_api_key'] ?? '' ) ) : (string) ( $old_options['ai_api_key'] ?? '' );
		$out['ai_fallback_gemini_api_key'] = '' !== trim( (string) ( $input['ai_fallback_gemini_api_key'] ?? '' ) ) ? self::encrypt_secret( sanitize_text_field( $input['ai_fallback_gemini_api_key'] ), (string) ( $old_options['ai_fallback_gemini_api_key'] ?? '' ) ) : (string) ( $old_options['ai_fallback_gemini_api_key'] ?? '' );
		$out['ai_fallback_gemini_model'] = sanitize_text_field( $input['ai_fallback_gemini_model'] ?? ( $old_options['ai_fallback_gemini_model'] ?? 'gemini-2.5-flash' ) );
		$out['compatible_base_url'] = esc_url_raw( $input['compatible_base_url'] ?? '' );
		$out['google_client_id'] = sanitize_text_field( $input['google_client_id'] ?? '' );
		$out['apple_client_id'] = sanitize_text_field( $input['apple_client_id'] ?? '' );
		$out['ticket_note'] = sanitize_textarea_field( $input['ticket_note'] ?? '' );
		$out['ticket_title'] = sanitize_text_field( $input['ticket_title'] ?? 'بلیت رزرو Matin AI' );
		$out['ticket_footer'] = sanitize_textarea_field( $input['ticket_footer'] ?? 'لطفاً در زمان شروع برنامه به کافه مراجعه کنید.' );
		$out['home_title'] = sanitize_text_field( $input['home_title'] ?? 'امشب دنبال چه تجربه‌ای هستی؟' );
		$out['home_intro'] = sanitize_textarea_field( $input['home_intro'] ?? '' );
		$out['sidebar_notice'] = sanitize_textarea_field( $input['sidebar_notice'] ?? '' );
		$out['sidebar_menu'] = sanitize_textarea_field( $input['sidebar_menu'] ?? '' );
		$out['ticket_buttons'] = array_values( array_intersect( (array) ( $input['ticket_buttons'] ?? [] ), [ 'download', 'print', 'whatsapp' ] ) );
		return $out;
	}
	public static function defaults() : array { return [ 'commission_enabled' => 1, 'commission_amount' => 100, 'cafe_fee_enabled' => 0, 'cafe_fee_mode' => 'monthly', 'cafe_fee_amount' => 0, 'google_login_enabled' => 0, 'apple_login_enabled' => 0, 'whatsapp_enabled' => 1, 'whatsapp_number' => '', 'whatsapp_access_token' => '', 'whatsapp_phone_number_id' => '', 'whatsapp_customer_template' => 'matin_reservation_ticket', 'whatsapp_cafe_template' => 'matin_cafe_booking_alert', 'whatsapp_template_language' => 'tr', 'ai_enabled' => 1, 'ai_fallback_enabled' => 1, 'ai_provider' => 'openrouter', 'ai_model' => '', 'ai_api_key' => '', 'ai_fallback_gemini_api_key' => '', 'ai_fallback_gemini_model' => 'gemini-2.5-flash', 'compatible_base_url' => '', 'google_client_id' => '', 'apple_client_id' => '', 'ticket_note' => 'بلیت پس از ثبت موفق رزرو صادر می‌شود؛ لطفاً این بلیت را در زمان شروع برنامه همراه داشته باشید.', 'ticket_title' => 'بلیت رزرو Matin AI', 'ticket_footer' => 'لطفاً در زمان شروع برنامه به کافه مراجعه کنید.', 'home_title' => 'امشب دنبال چه تجربه‌ای هستی؟', 'home_intro' => 'زمان، محله و تعداد نفرات را بنویسید؛ من فقط از میان کافه‌ها و ایونت‌های ثبت‌شده، گزینه‌های قابل‌درخواست را نشان می‌دهم.', 'sidebar_notice' => 'ماتین فقط کافه‌ها و ایونت‌های ثبت‌شده در همین سایت را پیشنهاد می‌دهد.', 'sidebar_menu' => "کشف کافه‌ها|کافه‌های مناسب برای قرار را پیشنهاد بده\nموسیقی زنده|امشب کجا موسیقی زنده دارد؟\nپخش فوتبال|کدام کافه امشب بازی فوتبال را پخش می‌کند؟\nرویدادهای پیش‌رو|چه برنامه‌هایی این هفته برگزار می‌شوند؟", 'sidebar_notice_enabled' => 1, 'sidebar_search_enabled' => 1, 'sidebar_new_chat_enabled' => 1, 'ticket_buttons' => [ 'download', 'print', 'whatsapp' ] ]; }
	public static function polish_admin_tabs() : void {
		$page_slug = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); $post_type = sanitize_key( wp_unslash( $_GET['post_type'] ?? '' ) ); if ( 0 !== strpos( $page_slug, 'matin-ai' ) && ! in_array( $post_type, [ 'matin_event', 'matin_cafe', 'matin_reservation' ], true ) && ! ( isset( $_GET['post'] ) && in_array( get_post_type( absint( $_GET['post'] ) ), [ 'matin_event', 'matin_cafe', 'matin_reservation' ], true ) ) ) { return; }
		echo '<style>.matin-admin .nav-tab-wrapper,.matin-admin .subsubsub,.wrap .nav-tab-wrapper{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin:16px 0 22px;padding:8px 0 12px;border-bottom:1px solid #dfe5dd}.matin-admin .nav-tab,.wrap .nav-tab-wrapper .nav-tab{float:none;margin:0!important;border:1px solid #dce4d8;border-radius:10px;background:#fff;padding:9px 15px;line-height:1.5}.matin-admin .nav-tab.nav-tab-active,.wrap .nav-tab-wrapper .nav-tab-active{background:#263d27;color:#fff;border-color:#263d27}.matin-admin .subsubsub li,.wrap .subsubsub li{float:none;margin:0}.wrap .subsubsub{display:flex;flex-wrap:wrap;gap:8px 10px;align-items:center;margin:14px 0 20px}.wrap .subsubsub a{display:inline-block;padding:7px 11px;border:1px solid #e0e6dc;border-radius:9px;background:#fff;text-decoration:none}.wrap .subsubsub .current{font-weight:800;color:#263d27}.matin-admin .subsubsub a{display:inline-block;padding:7px 11px;border-radius:8px;background:#f4f7f1;text-decoration:none}.matin-admin .subsubsub .current{font-weight:800;color:#263d27}.matin-admin .widefat{border-radius:12px;overflow:hidden}.matin-admin .button{border-radius:9px;padding-inline:13px}.matin-admin input,.matin-admin select,.matin-admin textarea{max-width:100%}</style>';
	}
	public static function get_options() : array {
		$options = wp_parse_args( (array) get_option( 'matin_ai_options', [] ), self::defaults() );
		foreach ( [ 'ai_api_key', 'ai_fallback_gemini_api_key', 'whatsapp_access_token' ] as $secret ) {
			if ( ! empty( $options[ $secret ] ) && class_exists( 'Matin_AI_Secrets' ) && Matin_AI_Secrets::is_encrypted( (string) $options[ $secret ] ) ) {
				$options[ $secret ] = Matin_AI_Secrets::decrypt( (string) $options[ $secret ], self::secret_material() );
			}
		}
		return $options;
	}

	/** Encrypts an API key before it is written to wp_options. */
	/** Returns the encrypted value. If encryption is impossible (no libsodium), the previous stored value is kept and the admin is warned. */
	private static function encrypt_secret( string $plain, string $previous = '' ) : string {
		try {
			if ( ! class_exists( 'Matin_AI_Secrets' ) ) { throw new \RuntimeException( 'secrets_missing' ); }
			return Matin_AI_Secrets::encrypt( $plain, self::secret_material() );
		} catch ( \Throwable $e ) {
			set_transient( 'matin_ai_secret_refused', 1, 300 );
			return $previous;
		}
	}
	public static function secret_notice() : void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) { echo '<div class="notice notice-error"><p>libsodium فعال نیست؛ کلیدهای API ذخیره نمی‌شوند تا به‌صورت متن ساده ذخیره نشوند. افزونهٔ sodium را روی سرور فعال کنید.</p></div>'; }
		if ( get_transient( 'matin_ai_secret_refused' ) ) { delete_transient( 'matin_ai_secret_refused' ); echo '<div class="notice notice-error"><p>کلید جدید ذخیره نشد (رمزنگاری ممکن نبود)؛ مقدار قبلی حفظ شد.</p></div>'; }
	}

	/** Key material comes from WordPress' AUTH salt, so a database dump alone does not reveal the keys. */
	private static function secret_material() : string { return wp_salt( 'auth' ); }
	public static function styles( string $hook ) : void {
		if ( ! str_contains( $hook, 'matin-ai-' ) ) { return; }
		wp_add_inline_style( 'wp-admin', '.matin-admin{width:calc(100% - 36px);max-width:1600px;margin:24px 18px 0 0;direction:rtl;color:#172033}.matin-admin-hero{padding:28px;border-radius:20px;background:linear-gradient(125deg,#101827,#273c59);color:#fff;margin-bottom:18px}.matin-admin-hero h1{color:#fff;font-size:26px}.matin-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.matin-admin-card{background:linear-gradient(180deg,#fff,#fbfcff);border:1px solid #e0e7f0;border-radius:18px;padding:24px;box-shadow:0 12px 32px #1720330b;transition:transform .18s ease,box-shadow .18s ease}.matin-admin-card:hover{box-shadow:0 16px 36px #17203312;transform:translateY(-1px)}.matin-admin-card h2{font-size:17px;letter-spacing:-.02em;padding-bottom:12px;border-bottom:1px solid #edf0f5}.matin-admin-card h2{margin-top:0}.matin-admin-field{margin:15px 0}.matin-admin-field label{display:block;font-weight:600;margin-bottom:7px}.matin-admin-field input[type=text],.matin-admin-field input[type=password],.matin-admin-field input[type=number],.matin-admin-field select,.matin-admin-field textarea{width:100%;max-width:100%;border-radius:9px;padding:9px 11px}.matin-admin-toggle{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #edf0f5}.matin-admin-hint{color:#64748b;font-size:12px}.matin-ai-test-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:18px 0}.matin-ai-test-success{color:#147a42;font-weight:700}.matin-ai-test-error{color:#b42318;font-weight:700}.matin-ai-test-pending{color:#64748b}.matin-admin-save{position:sticky;bottom:12px;padding:12px;background:#ffffffed;border:1px solid #e5eaf1;border-radius:12px;margin-top:16px;backdrop-filter:blur(10px)}@media(max-width:780px){.matin-admin-grid{grid-template-columns:1fr}.matin-admin{width:calc(100% - 20px);margin:16px 10px 0}.matin-admin-card{padding:14px}.matin-admin-hero{padding:20px}.matin-admin-hero h1{font-size:22px}}' );
	}
	private static function toggle( string $key, string $label, array $o, string $hint = '' ) : void { printf( '<div class="matin-admin-toggle"><div><strong>%s</strong>%s</div><label><input type="checkbox" name="matin_ai_options[%s]" value="1" %s> فعال</label></div>', esc_html( $label ), $hint ? '<div class="matin-admin-hint">' . esc_html( $hint ) . '</div>' : '', esc_attr( $key ), checked( ! empty( $o[ $key ] ), true, false ) ); }
	private static function field( string $key, string $label, array $o, string $type = 'text', string $hint = '' ) : void {
		// Secrets are never printed back into the page: an empty box keeps the stored value on save.
		$is_secret = 'password' === $type;
		$value = $is_secret ? '' : (string) ( $o[ $key ] ?? '' );
		$is_stored = $is_secret && ! empty( self::get_options()[ $key ] ); // callers pass no options for secrets, so read the stored value here
		$placeholder = $is_stored ? 'ذخیره شده — برای تغییر، کلید جدید را وارد کنید' : '';
		printf( '<div class="matin-admin-field"><label for="matin-%1$s">%2$s</label><input id="matin-%1$s" type="%3$s" name="matin_ai_options[%1$s]" value="%4$s" placeholder="%6$s" autocomplete="off">%5$s</div>', esc_attr( $key ), esc_html( $label ), esc_attr( $type ), esc_attr( $value ), $hint ? '<p class="matin-admin-hint">' . esc_html( $hint ) . '</p>' : '', esc_attr( $placeholder ) );
	}
	public static function page() : void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$o = self::get_options();
		echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>Matin AI | مرکز مدیریت</h1><p>تنظیمات هوش مصنوعی، درآمد، ورود کاربران و بلیت‌ها در یک پنل.</p></div><form method="post" action="options.php">';
		settings_fields( 'matin_ai_settings' );
		echo '<div class="matin-admin-grid"><section class="matin-admin-card"><h2>مدل درآمد</h2>';
		self::toggle( 'commission_enabled', 'کارمزد هر رزرو', $o, 'نمونه: ۱۰۰ لیر برای هر رزرو مشمول کارمزد.' ); self::field( 'commission_amount', 'مبلغ کارمزد (لیر)', $o, 'number' );
		self::toggle( 'cafe_fee_enabled', 'دریافت هزینه از کافه', $o );
		echo '<div class="matin-admin-field"><label for="matin-cafe_fee_mode">مدل هزینه کافه</label><select id="matin-cafe_fee_mode" name="matin_ai_options[cafe_fee_mode]"><option value="per_reservation" ' . selected( $o['cafe_fee_mode'], 'per_reservation', false ) . '>به‌ازای هر رزرو</option><option value="monthly" ' . selected( $o['cafe_fee_mode'], 'monthly', false ) . '>اشتراک ماهانه</option><option value="fixed" ' . selected( $o['cafe_fee_mode'], 'fixed', false ) . '>مبلغ ثابت توافق‌شده</option></select></div>';
		self::field( 'cafe_fee_amount', 'مبلغ هزینه کافه (لیر)', $o, 'number' ); echo '<p class="matin-admin-hint">درگاه پرداخت در این نسخه وجود ندارد؛ این تنظیمات برای محاسبه و گزارش‌گیری هستند، نه دریافت خودکار پول.</p></section>';
		echo '<section class="matin-admin-card"><h2>دستیار هوشمند</h2>'; self::toggle( 'ai_enabled', 'فعال بودن دستیار AI', $o );
		echo '<div class="matin-admin-field"><label for="matin-ai_provider">ارائه‌دهنده</label><select id="matin-ai_provider" name="matin_ai_options[ai_provider]"><option value="gemini" ' . selected( $o['ai_provider'], 'gemini', false ) . '>Google Gemini</option><option value="openai" ' . selected( $o['ai_provider'], 'openai', false ) . '>OpenAI</option><option value="openrouter" ' . selected( $o['ai_provider'], 'openrouter', false ) . '>OpenRouter (رایگان)</option><option value="compatible" ' . selected( $o['ai_provider'], 'compatible', false ) . '>سازگار با API</option></select></div>';
		self::field( 'ai_model', 'نام مدل', $o, 'text', 'برای OpenRouter این فیلد را خالی بگذارید تا openrouter/free استفاده شود؛ این مسیریاب فقط مدل‌های رایگانِ در دسترس را انتخاب می‌کند.' ); self::field( 'compatible_base_url', 'آدرس پایه API سازگار', $o, 'url', 'برای OpenRouter لازم نیست؛ آدرس ثابت OpenRouter به‌صورت خودکار استفاده می‌شود.' ); self::field( 'ai_api_key', 'کلید API اصلی (OpenRouter)', [], 'password', 'کلید اصلی OpenRouter؛ برای حفظ کلید فعلی این فیلد را خالی بگذارید.' ); self::toggle( 'ai_fallback_enabled', 'فعال‌سازی ارائه‌دهندهٔ جایگزین', $o, 'اگر OpenRouter خطا بدهد یا سهمیه‌اش پر شود، تلاش می‌کند با Gemini پاسخ دهد.' ); self::field( 'ai_fallback_gemini_api_key', 'کلید API جایگزین Gemini', [], 'password', 'از Google AI Studio کلید جداگانه بگیرید. این کلید جدا از کلید OpenRouter ذخیره می‌شود؛ برای حفظ آن خالی بگذارید.' ); self::field( 'ai_fallback_gemini_model', 'مدل جایگزین Gemini', $o, 'text', 'پیش‌فرض: gemini-2.5-flash. سهمیه و دسترسی به پلن رایگان گوگل وابسته به حساب و شرایط سرویس است.' ); echo '<p class="matin-admin-hint">سیستم ابتدا ارائه‌دهندهٔ اصلی را امتحان می‌کند و در صورت فعال بودن و تنظیم کلید مستقل، از Gemini جایگزین کمک می‌گیرد. سهمیهٔ رایگان تضمین‌شده یا نامحدود نیست.</p><div class="matin-ai-test-row"><button type="button" class="button button-secondary" id="matin-ai-test-button">آزمایش اتصال واقعی هوش مصنوعی</button><span id="matin-ai-test-result" role="status" aria-live="polite"></span></div><script>document.addEventListener("DOMContentLoaded",function(){var p=document.getElementById("matin-ai_provider"),m=document.getElementById("matin-ai_model"),b=document.getElementById("matin-compatible_base_url");if(!p)return;p.addEventListener("change",function(){if(p.value==="openrouter"){if(m)m.value="openrouter/free";if(b)b.value="";}});var test=document.getElementById("matin-ai-test-button"),out=document.getElementById("matin-ai-test-result");if(test)test.addEventListener("click",async function(){test.disabled=true;out.textContent="در حال بررسی اتصال از سمت سرور…";out.className="matin-ai-test-pending";try{var response=await fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:new URLSearchParams({action:"matin_ai_test_connection",nonce:"' . esc_js( wp_create_nonce( 'matin_ai_test_connection' ) ) . '"})});var data=await response.json();if(!response.ok||!data.success)throw new Error(data.data&&data.data.message?data.data.message:"آزمایش اتصال ناموفق بود.");out.textContent=data.data.message;out.className="matin-ai-test-success";}catch(error){out.textContent=error.message||"خطا در تست اتصال";out.className="matin-ai-test-error";}finally{test.disabled=false;}});});</script></section>';
		echo '<section class="matin-admin-card"><h2>ورود و ثبت‌نام</h2>'; self::toggle( 'google_login_enabled', 'ورود با Google', $o, 'فعال‌سازی واقعی نیازمند OAuth Client و پیاده‌سازی callback امن است.' ); self::field( 'google_client_id', 'Google Client ID', $o ); self::toggle( 'apple_login_enabled', 'ورود با Apple ID', $o, 'نیازمند Apple Developer Services و تنظیم دامنه است.' ); self::field( 'apple_client_id', 'Apple Service ID', $o ); echo '<p class="matin-admin-hint">نمایش کلید روشن/خاموش به‌تنهایی اتصال OAuth را فعال نمی‌کند؛ اتصال ارائه‌دهنده باید تکمیل و آزمایش شود.</p></section>';
		echo '<section class="matin-admin-card"><h2>واتساپ و بلیت</h2>'; self::toggle( 'whatsapp_enabled', 'نمایش ارسال بلیت با واتساپ', $o ); self::field( 'whatsapp_number', 'شماره واتساپ پشتیبانی (با کد کشور، فقط رقم)', $o, 'text', 'برای اعلان به مشتری، شماره مشتری از فرم رزرو خوانده می‌شود؛ این شماره برای پشتیبانی/پیام جایگزین است.' ); self::field( 'whatsapp_phone_number_id', 'WhatsApp Business Phone Number ID', $o, 'text', 'شناسه Phone Number از Meta WhatsApp Cloud API، نه شماره تلفن.' ); self::field( 'whatsapp_access_token', 'WhatsApp Cloud API Access Token', [], 'password', 'توکن دسترسی دائمی/سیستمی Meta؛ برای حفظ توکن ذخیره‌شده این فیلد را خالی بگذارید.' ); self::field( 'whatsapp_customer_template', 'نام قالب تأییدشده پیام مشتری', $o, 'text', 'قالب باید در WhatsApp Manager تأیید شده و ۶ متغیر بدنه داشته باشد.' ); self::field( 'whatsapp_cafe_template', 'نام قالب تأییدشده پیام کافه', $o, 'text', 'قالب باید در WhatsApp Manager تأیید شده و ۶ متغیر بدنه داشته باشد.' ); self::field( 'whatsapp_template_language', 'کد زبان قالب WhatsApp', $o, 'text', 'برای نمونه tr یا en؛ باید دقیقاً با زبان قالب تأییدشده یکسان باشد.' ); self::field( 'ticket_title', 'عنوان روی بلیت', $o );
		printf( '<div class="matin-admin-field"><label for="matin-ticket_note">متن داخل بلیت</label><textarea id="matin-ticket_note" name="matin_ai_options[ticket_note]" rows="3">%s</textarea></div>', esc_textarea( $o['ticket_note'] ) );
		printf( '<div class="matin-admin-field"><label for="matin-ticket_footer">متن پایین بلیت</label><textarea id="matin-ticket_footer" name="matin_ai_options[ticket_footer]" rows="3">%s</textarea></div>', esc_textarea( $o['ticket_footer'] ) );
        foreach ( [ 'download'=>'دکمه دریافت بلیت', 'print'=>'دکمه چاپ / ذخیره PDF', 'whatsapp'=>'دکمه واتساپ' ] as $k=>$label ) printf('<div class="matin-admin-toggle"><strong>%s</strong><label><input type="checkbox" name="matin_ai_options[ticket_buttons][]" value="%s" %s> فعال</label></div>', esc_html($label), esc_attr($k), checked(in_array($k,(array)$o['ticket_buttons'],true),true,false));
        echo '</section><section class="matin-admin-card"><h2>طراحی صفحه اول و منوی سایدبار</h2>';
        self::field('home_title','تیتر خوشامد صفحه چت',$o);
        printf('<div class="matin-admin-field"><label for="matin-home_intro">متن زیر تیتر</label><textarea id="matin-home_intro" name="matin_ai_options[home_intro]" rows="3">%s</textarea></div>',esc_textarea($o['home_intro']));
        self::toggle('sidebar_new_chat_enabled','نمایش دکمه گفت‌وگوی جدید',$o); self::toggle('sidebar_search_enabled','نمایش جست‌وجوی سایدبار',$o); self::toggle('sidebar_notice_enabled','نمایش پیام محدودیت پیشنهادها در پایین سایدبار',$o);
        printf('<div class="matin-admin-field"><label for="matin-sidebar_notice">متن پیام پایین سایدبار</label><textarea id="matin-sidebar_notice" name="matin_ai_options[sidebar_notice]" rows="2">%s</textarea></div>',esc_textarea($o['sidebar_notice']));
        printf('<div class="matin-admin-field"><label for="matin-sidebar_menu">منوهای سایدبار (هر خط: عنوان فارسی|پرسش فارسی|English prompt|Soru Türkçe)</label><textarea id="matin-sidebar_menu" name="matin_ai_options[sidebar_menu]" rows="7" dir="rtl">%s</textarea><p class="matin-admin-hint">برای افزودن میانبر، هر خط را با چهار بخش جداشده با | وارد کنید: عنوان نمایشی|پرسش فارسی|English prompt|Türkçe soru. نمونه: موسیقی زنده|امشب کجا موسیقی زنده دارد؟|Where is live music tonight?|Bu akşam canlı müzik nerede?</p></div>',esc_textarea($o['sidebar_menu']));
        echo '</section></div><div class="matin-admin-save">'; submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); echo '</div></form></div>';
	}

	public static function dashboard_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$counts = [];
		foreach ( [ 'pending'=>'در انتظار', 'confirmed'=>'تأییدشده', 'rejected'=>'ردشده', 'cancelled'=>'لغوشده' ] as $status=>$label ) {
			$q = new WP_Query( [ 'post_type'=>'matin_reservation', 'post_status'=>'private', 'posts_per_page'=>1, 'fields'=>'ids', 'meta_key'=>'_matin_status', 'meta_value'=>$status ] );
			$counts[$status] = (int) $q->found_posts;
		}
		echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>Matin AI | مرکز مدیریت</h1><p>مدیریت یکپارچهٔ رزروها، کاربران، گزارش‌ها، بلیت‌ها و تنظیمات.</p></div><div class="matin-admin-grid">';
		foreach ( $counts as $key=>$n ) { $labels = [ 'pending'=>'رزروهای در انتظار', 'confirmed'=>'رزروهای تأییدشده', 'rejected'=>'رزروهای ردشده', 'cancelled'=>'رزروهای لغوشده' ]; printf('<section class="matin-admin-card"><h2>%s</h2><p style="font-size:32px;font-weight:800;margin:8px 0">%s</p></section>', esc_html($labels[$key]), esc_html(number_format_i18n($n))); }
		echo '</div><div class="matin-admin-grid" style="margin-top:16px">';
		foreach ( [ 'matin-ai-reservations'=>'مدیریت رزروها','matin-ai-reports'=>'گزارش و خروجی PDF','matin-ai-users'=>'مدیریت کاربران','matin-ai-logs'=>'گزارش‌های سیستمی','matin-ai-settings'=>'تنظیمات و متن بلیت' ] as $slug=>$label ) printf('<a class="matin-admin-card" style="text-decoration:none;color:#172033" href="%s"><h2>%s</h2><p>ورود به بخش %s</p></a>', esc_url(admin_url('admin.php?page='.$slug)), esc_html($label), esc_html($label));
		echo '</div></div>';
	}
	private static function get_reservations_filtered() : array {
		$args = [ 'post_type'=>'matin_reservation', 'post_status'=>'private', 'posts_per_page'=>5000, 'orderby'=>'date', 'order'=>'DESC' ];
		$meta = [];
		$status = sanitize_key( wp_unslash($_GET['status'] ?? '') );
		if ( in_array($status,['pending','confirmed','rejected','cancelled'],true) ) $meta[] = [ 'key'=>'_matin_status','value'=>$status ];
		$from = sanitize_text_field(wp_unslash($_GET['from'] ?? '')); $to = sanitize_text_field(wp_unslash($_GET['to'] ?? ''));
		if ($from || $to) {
			$event_ids = get_posts([ 'post_type'=>'matin_event','post_status'=>'publish','numberposts'=>-1,'fields'=>'ids' ]);
			$matched=[];
			foreach($event_ids as $eid){$date=substr((string)get_post_meta($eid,'_matin_starts_at',true),0,10);if((!$from||$date>=$from)&&(!$to||$date<=$to))$matched[]=$eid;}
			if(!$matched) return [];
			$meta[] = [ 'key'=>'_matin_event_id','value'=>$matched,'compare'=>'IN','type'=>'NUMERIC' ];
		}
		if($meta)$args['meta_query']=$meta;
		$posts=get_posts($args); $cafe=absint($_GET['cafe']??0);
		if($cafe)$posts=array_values(array_filter($posts,static function($p)use($cafe){$e=(int)get_post_meta($p->ID,'_matin_event_id',true);return (int)get_post_meta($e,'_matin_cafe_id',true)===$cafe;}));
		return $posts;
	}
	public static function reservations_page() : void {
		if(!current_user_can('manage_options'))return;
		$posts=self::get_reservations_filtered();
		echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>مدیریت رزروها</h1><p>تأیید، لغو، مشاهدهٔ بلیت و آماده‌سازی پیام برای رزروکننده.</p></div>';
		self::reservation_filters();
		echo '<div class="matin-admin-card" style="overflow:auto"><table class="widefat striped"><thead><tr><th>کد</th><th>مشتری</th><th>کافه / برنامه</th><th>زمان</th><th>نفرات</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
		if(!$posts) echo '<tr><td colspan="7">رزروی مطابق فیلترها پیدا نشد.</td></tr>';
		foreach($posts as $p){$id=$p->ID;$eid=(int)get_post_meta($id,'_matin_event_id',true);$cid=(int)get_post_meta($eid,'_matin_cafe_id',true);$status=(string)get_post_meta($id,'_matin_status',true);$ticket=add_query_arg(['action'=>'matin_ticket','id'=>$id,'_wpnonce'=>wp_create_nonce('matin_ticket_'.$id)],admin_url('admin-post.php'));
			echo '<tr><td>'.esc_html((string)get_post_meta($id,'_matin_ticket_code',true)).'</td><td>'.esc_html((string)get_post_meta($id,'_matin_name',true)).'<br><small>'.esc_html((string)get_post_meta($id,'_matin_phone',true)).'</small></td><td>'.esc_html(get_the_title($cid).' / '.get_the_title($eid)).'</td><td>'.esc_html((string)get_post_meta($eid,'_matin_starts_at',true)).'</td><td>'.absint(get_post_meta($id,'_matin_guests',true)).'</td><td>'.esc_html(self::status_label($status)).'</td><td style="min-width:240px">';
			foreach(['confirm'=>'تأیید','cancel'=>'لغو','reject'=>'رد'] as $action=>$label){if(($action==='confirm'&&$status==='pending')||($action==='reject'&&$status==='pending')||($action==='cancel'&&in_array($status,['pending','confirmed'],true))){$url=wp_nonce_url(add_query_arg(['action'=>'matin_admin_reservation_action','id'=>$id,'do'=>$action],admin_url('admin-post.php')),'matin_admin_reservation_'.$id);printf('<a class="button %s" style="margin:2px" href="%s">%s</a>', $action==='confirm'?'button-primary':'',esc_url($url),esc_html($label));}}
			printf('<a class="button" style="margin:2px" href="%s">دانلود بلیت</a>',$ticket);
			if(in_array($status,['confirmed','cancelled'],true)){ $wa=wp_nonce_url(add_query_arg(['action'=>'matin_retry_whatsapp','id'=>$id,'target'=>'customer'],admin_url('admin-post.php')),'matin_whatsapp_'.$id);printf('<a class="button" style="margin:2px" href="%s">پیام واتساپ مشتری</a>',esc_url($wa));}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}
	private static function status_label(string $s): string { return ['pending'=>'در انتظار','confirmed'=>'تأییدشده','rejected'=>'ردشده','cancelled'=>'لغوشده'][$s]??'نامشخص'; }
	private static function reservation_filters(): void {
		$cafes=get_posts(['post_type'=>'matin_cafe','post_status'=>'publish','numberposts'=>-1]);
		echo '<form method="get" class="matin-admin-card" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:16px"><input type="hidden" name="page" value="'.esc_attr(sanitize_key(wp_unslash($_GET['page']??'matin-ai-reservations'))).'"><label>از تاریخ <input type="date" name="from" value="'.esc_attr(sanitize_text_field(wp_unslash($_GET['from']??''))).'"></label><label>تا تاریخ <input type="date" name="to" value="'.esc_attr(sanitize_text_field(wp_unslash($_GET['to']??''))).'"></label><label>وضعیت <select name="status"><option value="">همه</option>';
		foreach(['pending'=>'در انتظار','confirmed'=>'تأییدشده','rejected'=>'ردشده','cancelled'=>'لغوشده'] as $k=>$v)printf('<option value="%s" %s>%s</option>',esc_attr($k),selected(sanitize_key(wp_unslash($_GET['status']??'')),$k,false),esc_html($v));
		echo '</select></label><label>کافه <select name="cafe"><option value="0">همه کافه‌ها</option>';foreach($cafes as $c)printf('<option value="%d" %s>%s</option>',$c->ID,selected(absint($_GET['cafe']??0),$c->ID,false),esc_html($c->post_title));echo '</select></label><button class="button button-primary">اعمال فیلتر</button></form>';
	}
	public static function reservation_action(): void {
		$id=absint($_GET['id']??0);$do=sanitize_key(wp_unslash($_GET['do']??''));
		if(!current_user_can('manage_options')||!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce']??'')),'matin_admin_reservation_'.$id)||'matin_reservation'!==get_post_type($id))wp_die('درخواست نامعتبر است.', '', ['response'=>403]);
		$status=(string)get_post_meta($id,'_matin_status',true);
		if($do==='confirm'&&$status==='pending'){update_post_meta($id,'_matin_status','confirmed');update_post_meta($id,'_matin_confirmed_at',current_time('mysql'));Matin_AI_Operations::log('reservation_admin_confirmed',$id,'admin_confirmed');if(class_exists('Matin_AI_REST')){Matin_AI_REST::send_confirmation_notices($id);}}
		elseif($do==='reject'&&$status==='pending'){update_post_meta($id,'_matin_status','rejected');update_post_meta($id,'_matin_ticket_invalidated',1);Matin_AI_Operations::log('reservation_admin_rejected',$id,'admin_rejected');}
		elseif($do==='cancel'&&in_array($status,['pending','confirmed'],true)){update_post_meta($id,'_matin_status','cancelled');update_post_meta($id,'_matin_cancelled_at',current_time('mysql'));update_post_meta($id,'_matin_ticket_invalidated',1);Matin_AI_Operations::log('reservation_admin_cancelled',$id,'admin_cancelled');}
		wp_safe_redirect(admin_url('admin.php?page=matin-ai-reservations&notice=updated'));exit;
	}
	public static function reports_page(): void {
		if(!current_user_can('manage_options'))return;
		$posts=self::get_reservations_filtered();
		echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>گزارش رزروها</h1><p>فیلتر بر اساس بازهٔ تاریخ، کافه و وضعیت؛ خروجی قابل چاپ و ذخیره به PDF.</p></div>';
		self::reservation_filters();
		$groups=[];
		foreach($posts as $p){$id=$p->ID;$eid=(int)get_post_meta($id,'_matin_event_id',true);$cid=(int)get_post_meta($eid,'_matin_cafe_id',true);$date=substr((string)get_post_meta($eid,'_matin_starts_at',true),0,10)?:'بدون تاریخ';$key=$cid.'|'.$date;if(!isset($groups[$key]))$groups[$key]=['cafe'=>get_the_title($cid),'date'=>$date,'total'=>0,'pending'=>0,'confirmed'=>0,'rejected'=>0,'cancelled'=>0,'fee'=>0];$groups[$key]['total']++;$st=(string)get_post_meta($id,'_matin_status',true);if(isset($groups[$key][$st]))$groups[$key][$st]++;if(in_array($st,['pending','confirmed'],true))$groups[$key]['fee']+=(int)get_post_meta($id,'_matin_commission_amount',true);}
		echo '<div class="matin-admin-card"><p><button class="button button-primary" onclick="window.print()">چاپ / ذخیره به PDF</button> <a class="button" href="'.esc_url(wp_nonce_url(add_query_arg(array_merge(['action'=>'matin_export_reservation_report'],array_map('sanitize_text_field',$_GET)),admin_url('admin-post.php')),'matin_export_reservation_report')).'">خروجی CSV اکسل</a></p><div style="overflow:auto"><table class="widefat striped"><thead><tr><th>کافه</th><th>تاریخ برنامه</th><th>کل</th><th>در انتظار</th><th>تأییدشده</th><th>ردشده</th><th>لغوشده</th><th>کارمزد برآوردی (لیر)</th></tr></thead><tbody>';
		foreach($groups as $g)printf('<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',esc_html($g['cafe']?:'—'),esc_html($g['date']),$g['total'],$g['pending'],$g['confirmed'],$g['rejected'],$g['cancelled'],esc_html(number_format_i18n($g['fee'])));
		echo '</tbody></table><p class="description">کارمزد برآوردی است و به‌معنی دریافت وجه نیست.</p></div></div><style>@media print{#adminmenumain,#wpadminbar,#wpfooter,.notice,.matin-admin-hero p,form,.button{display:none!important}.matin-admin{margin:0!important;max-width:none!important}.matin-admin-card{box-shadow:none!important;border:0!important}}</style></div>';
	}
	public static function export_reservation_report(): void {
		if(!current_user_can('manage_options')||!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce']??'')),'matin_export_reservation_report'))wp_die('اجازه ندارید.', '', ['response'=>403]);
		$posts=self::get_reservations_filtered();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="matin-reservations-report.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');fputcsv($out,['Cafe','Event date','Total','Pending','Confirmed','Rejected','Cancelled','Estimated commission TRY']);
		$groups=[];foreach($posts as $p){$eid=(int)get_post_meta($p->ID,'_matin_event_id',true);$cid=(int)get_post_meta($eid,'_matin_cafe_id',true);$date=substr((string)get_post_meta($eid,'_matin_starts_at',true),0,10)?:'Unknown';$k=$cid.'|'.$date;if(!isset($groups[$k]))$groups[$k]=['cafe'=>get_the_title($cid),'date'=>$date,'total'=>0,'pending'=>0,'confirmed'=>0,'rejected'=>0,'cancelled'=>0,'fee'=>0];$groups[$k]['total']++;$st=(string)get_post_meta($p->ID,'_matin_status',true);if(isset($groups[$k][$st]))$groups[$k][$st]++;if(in_array($st,['pending','confirmed'],true))$groups[$k]['fee']+=(int)get_post_meta($p->ID,'_matin_commission_amount',true);}
		foreach($groups as $g)fputcsv($out,[$g['cafe'],$g['date'],$g['total'],$g['pending'],$g['confirmed'],$g['rejected'],$g['cancelled'],$g['fee']]);fclose($out);exit;
	}
	public static function users_page(): void {
		if(!current_user_can('list_users'))return;
		$users=get_users(['number'=>100,'orderby'=>'registered','order'=>'DESC']);echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>مدیریت کاربران</h1><p>مشاهده کاربران ثبت‌نام‌شده و مدیریت حساب‌ها از پیشخوان وردپرس.</p></div><div class="matin-admin-card" style="overflow:auto"><table class="widefat striped"><thead><tr><th>کاربر</th><th>ایمیل</th><th>تلفن</th><th>نقش</th><th>ثبت‌نام</th><th>مدیریت</th></tr></thead><tbody>';
		foreach($users as $u){$edit=admin_url('user-edit.php?user_id='.$u->ID);printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button" href="%s">ویرایش کاربر</a></td></tr>',esc_html($u->display_name),esc_html($u->user_email),esc_html((string)get_user_meta($u->ID,'phone',true)),esc_html(implode(', ',(array)$u->roles)),esc_html($u->user_registered),esc_url($edit));}
		echo '</tbody></table></div></div>';
	}
	public static function logs_page(): void { if(!current_user_can('manage_options'))return; echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>گزارش رویدادهای سیستمی</h1><p>آخرین رویدادهای ثبت‌شده برای عیب‌یابی و پیگیری.</p></div><div class="matin-admin-card">';self::audit_report();echo '</div></div>'; }

	private static function reservation_report() : void {
		$ids = get_posts( [ 'post_type' => 'matin_reservation', 'post_status' => 'private', 'numberposts' => 500, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ] );
		if ( ! $ids ) { echo '<p>هنوز رزروی ثبت نشده است.</p>'; return; }
		$groups = [];
		foreach ( $ids as $id ) { $event_id = (int) get_post_meta( $id, '_matin_event_id', true ); $cafe_id = (int) get_post_meta( $event_id, '_matin_cafe_id', true ); $date = substr( (string) get_post_meta( $event_id, '_matin_starts_at', true ), 0, 10 ) ?: 'بدون تاریخ'; $key = $cafe_id . '|' . $date; if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = [ 'cafe' => get_the_title( $cafe_id ) ?: 'کافه نامشخص', 'date' => $date, 'total' => 0, 'pending' => 0, 'confirmed' => 0, 'rejected' => 0, 'cancelled' => 0, 'fee' => 0 ]; } $groups[ $key ]['total']++; $status = (string) get_post_meta( $id, '_matin_status', true ); if ( isset( $groups[ $key ][ $status ] ) ) { $groups[ $key ][ $status ]++; } if ( in_array( $status, [ 'pending', 'confirmed' ], true ) ) { $groups[ $key ]['fee'] += (int) get_post_meta( $id, '_matin_commission_amount', true ); } }
		echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>کافه</th><th>تاریخ برنامه</th><th>کل رزرو</th><th>در انتظار</th><th>تأییدشده</th><th>ردشده</th><th>لغوشده</th><th>کارمزد برآوردی (لیر)</th></tr></thead><tbody>';
		foreach ( $groups as $g ) { printf( '<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>', esc_html( $g['cafe'] ), esc_html( $g['date'] ), $g['total'], $g['pending'], $g['confirmed'], $g['rejected'], $g['cancelled'], esc_html( number_format_i18n( $g['fee'] ) ) ); }
		echo '</tbody></table></div><p class="description">گزارش فقط برآورد کارمزد ذخیره‌شده را نشان می‌دهد و اثبات دریافت وجه نیست.</p>';
	}
	private static function audit_report() : void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$items = Matin_AI_Operations::recent( 50 );
		if ( ! $items ) { echo '<p>هنوز رویداد سیستمی ثبت نشده است.</p>'; return; }
		echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>زمان</th><th>رویداد</th><th>شناسه رزرو</th><th>کاربر مدیریتی</th><th>جزئیات</th></tr></thead><tbody>';
		foreach ( $items as $item ) { printf( '<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>', esc_html( (string) ( $item['time'] ?? '' ) ), esc_html( (string) ( $item['event'] ?? '' ) ), absint( $item['reservation_id'] ?? 0 ), absint( $item['actor'] ?? 0 ), esc_html( (string) ( $item['detail'] ?? '' ) ) ); }
		echo '</tbody></table></div><p class="description">حداکثر ۵۰۰۰ رویداد نگهداری می‌شود و ۵۰ مورد اخیر نمایش داده می‌شود؛ کلیدها، رمزها و توکن‌ها در این گزارش ذخیره نمی‌شوند.</p>';
	}

	public static function ajax_test_ai_connection() : void {
		check_ajax_referer( 'matin_ai_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( [ 'message' => 'اجازهٔ انجام این آزمایش را ندارید.' ], 403 ); }
		if ( empty( self::get_options()['ai_enabled'] ) ) { wp_send_json_error( [ 'message' => 'دستیار هوش مصنوعی غیرفعال است؛ ابتدا آن را فعال و تنظیمات را ذخیره کنید.' ], 400 ); }
		$started = microtime( true );
		$result = Matin_AI_Provider::reply( 'برای آزمایش اتصال Matin AI فقط همین عبارت را برگردان: MATIN_AI_CONNECTION_OK' );
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $result ) ) { wp_send_json_error( [ 'message' => 'اتصال ناموفق بود: ' . $result->get_error_message() ], 502 ); }
		if ( '' === trim( $result ) ) { wp_send_json_error( [ 'message' => 'سرویس پاسخ خالی برگرداند؛ مدل و کلید API را بررسی کنید.' ], 502 ); }
		$provider = (string) self::get_options()['ai_provider'];
		if ( class_exists( 'Matin_AI_Operations' ) ) { Matin_AI_Operations::log( 'ai_connection_test_success', get_current_user_id(), 'provider=' . $provider . ';latency_ms=' . $elapsed ); }
		wp_send_json_success( [ 'message' => 'اتصال موفق است. پاسخ واقعی از API دریافت شد (' . esc_html( $provider ) . '، ' . $elapsed . ' میلی‌ثانیه).', 'provider' => $provider, 'latencyMs' => $elapsed ] );
	}

	public static function reservation_summary_box() : void { add_meta_box( 'matin-reservation-admin-summary', 'گزارش مالی و مشخصات رزرو', [ __CLASS__, 'reservation_summary' ], 'matin_reservation', 'side', 'high' ); }
	public static function reservation_summary( WP_Post $post ) : void {
		$o = self::get_options(); $status = get_post_meta( $post->ID, '_matin_status', true ); $event_id = (int) get_post_meta( $post->ID, '_matin_event_id', true ); if ( ! $event_id ) { $event_id = (int) get_post_meta( $post->ID, '_matin_event_id', true ); }
		$fee = ! empty( $o['commission_enabled'] ) && in_array( $status, [ 'pending', 'confirmed' ], true ) ? (int) $o['commission_amount'] : 0;
		echo '<p><strong>کد بلیت:</strong> ' . esc_html( (string) get_post_meta( $post->ID, '_matin_ticket_code', true ) ) . '</p><p><strong>کارمزد برآوردی:</strong> ' . esc_html( number_format_i18n( $fee ) ) . ' لیر</p><p class="description">این مبلغ برآوردی است و پرداخت واقعی را نشان نمی‌دهد.</p>';
		foreach ( [ 'customer' => 'مشتری', 'cafe' => 'کافه' ] as $target => $label ) {
			$state = (string) get_post_meta( $post->ID, '_matin_whatsapp_' . $target . '_status', true );
			echo '<p><strong>واتساپ ' . esc_html( $label ) . ':</strong> ' . esc_html( $state ?: 'آماده نشده' ) . '</p>';
			if ( in_array( $status, [ 'confirmed', 'cancelled' ], true ) ) { $url = add_query_arg( [ 'action' => 'matin_retry_whatsapp', 'id' => $post->ID, 'target' => $target ], admin_url( 'admin-post.php' ) ); $url = wp_nonce_url( $url, 'matin_whatsapp_' . $post->ID ); echo '<p><a class="button" href="' . esc_url( $url ) . '">آماده‌سازی پیام واتساپ ' . esc_html( $label ) . '</a></p>'; }
		}
		$ticket_url = add_query_arg( [ 'action' => 'matin_ticket', 'id' => $post->ID, '_wpnonce' => wp_create_nonce( 'matin_ticket_' . $post->ID ) ], admin_url( 'admin-post.php' ) );
		echo '<p><a class="button" href="' . esc_url( $ticket_url ) . '">دانلود رسید تصویری</a></p>';
	}
}
