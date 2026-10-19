<?php
defined( 'ABSPATH' ) || exit;

final class Matin_AI_Admin {
	private const SECRET_FIELDS = [ 'ai_api_key', 'whatsapp_token', 'sms_api_key', 'google_client_secret' ];

	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'styles' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'reservation_summary_box' ] );
		add_action( 'admin_post_matin_ai_message_action', [ __CLASS__, 'handle_message_action' ] );
		add_action( 'admin_post_matin_ai_prune_logs', [ __CLASS__, 'handle_prune_logs' ] );
		add_action( 'admin_post_matin_ai_verify_ticket', [ __CLASS__, 'handle_verify_ticket' ] );
		add_action( 'admin_post_matin_ai_test_connection', [ __CLASS__, 'handle_test_connection' ] );
	}

	public static function menu() : void {
		add_menu_page( 'تنظیمات Matin AI', 'تنظیمات Matin AI', 'manage_options', 'matin-ai-settings', [ __CLASS__, 'page' ], 'dashicons-format-chat', 58 );
		add_submenu_page( 'matin-ai-settings', 'پیام‌های واتساپ', 'پیام‌های واتساپ', 'manage_options', 'matin-ai-messages', [ __CLASS__, 'messages_page' ] );
		add_submenu_page( 'matin-ai-settings', 'اعتبارسنجی بلیت', 'اعتبارسنجی بلیت', 'manage_options', 'matin-ai-verify', [ __CLASS__, 'verify_page' ] );
		add_submenu_page( 'matin-ai-settings', 'گزارش خطاها', 'گزارش خطاها', 'manage_options', 'matin-ai-logs', [ __CLASS__, 'logs_page' ] );
	}

	public static function settings() : void {
		register_setting( 'matin_ai_settings', 'matin_ai_options', [ 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
	}

	public static function sanitize( $input ) : array {
		$input    = is_array( $input ) ? $input : [];
		$existing = (array) get_option( 'matin_ai_options', [] );
		$defaults = self::defaults();
		$out      = [];
		foreach ( [ 'commission_enabled', 'cafe_fee_enabled', 'whatsapp_enabled', 'ai_enabled', 'google_login_enabled', 'require_phone_verification' ] as $key ) { $out[ $key ] = empty( $input[ $key ] ) ? 0 : 1; }
		$out['commission_amount'] = max( 0, min( 1000000, absint( $input['commission_amount'] ?? $defaults['commission_amount'] ) ) );
		$out['cafe_fee_mode'] = in_array( $input['cafe_fee_mode'] ?? '', [ 'per_reservation', 'monthly', 'fixed' ], true ) ? $input['cafe_fee_mode'] : 'monthly';
		$out['cafe_fee_amount'] = max( 0, min( 100000000, absint( $input['cafe_fee_amount'] ?? 0 ) ) );
		$out['whatsapp_number'] = preg_replace( '/[^0-9]/', '', (string) ( $input['whatsapp_number'] ?? '' ) );
		$out['whatsapp_phone_id'] = preg_replace( '/[^0-9]/', '', (string) ( $input['whatsapp_phone_id'] ?? '' ) );
		$out['ai_provider'] = in_array( $input['ai_provider'] ?? '', [ 'gemini', 'openai', 'compatible' ], true ) ? $input['ai_provider'] : 'gemini';
		$out['ai_model'] = sanitize_text_field( $input['ai_model'] ?? '' );
		$out['compatible_base_url'] = esc_url_raw( $input['compatible_base_url'] ?? '' );
		$out['google_client_id'] = sanitize_text_field( $input['google_client_id'] ?? '' );
		$out['sms_sender'] = preg_replace( '/[^0-9]/', '', (string) ( $input['sms_sender'] ?? '' ) );
		$out['ticket_note'] = sanitize_textarea_field( $input['ticket_note'] ?? '' );
		$out['log_retention_days'] = max( 1, min( 365, absint( $input['log_retention_days'] ?? 30 ) ) );

		// Secrets: a blank field keeps the stored value; a new value is encrypted before it reaches the database.
		foreach ( self::SECRET_FIELDS as $field ) {
			$new = trim( (string) ( $input[ $field ] ?? '' ) );
			if ( '' === $new ) {
				$out[ $field ] = (string) ( $existing[ $field ] ?? '' );
			} else {
				$out[ $field ] = Matin_AI_Secrets::encrypt( sanitize_text_field( $new ), Matin_AI_Messaging::secret_material() );
			}
		}
		return $out;
	}

	public static function defaults() : array {
		return [
			'commission_enabled' => 1, 'commission_amount' => 100, 'cafe_fee_enabled' => 0, 'cafe_fee_mode' => 'monthly', 'cafe_fee_amount' => 0,
			'whatsapp_enabled' => 1, 'whatsapp_number' => '', 'whatsapp_phone_id' => '', 'whatsapp_token' => '',
			'ai_enabled' => 1, 'ai_provider' => 'gemini', 'ai_model' => '', 'ai_api_key' => '', 'compatible_base_url' => '',
			'google_login_enabled' => 0, 'google_client_id' => '', 'google_client_secret' => '',
			'require_phone_verification' => 0, 'sms_api_key' => '', 'sms_sender' => '',
			'ticket_note' => 'این رسید تا زمان تأیید کافه، بلیت قطعی ورود نیست.', 'log_retention_days' => 30,
		];
	}

	public static function get_options() : array { return wp_parse_args( (array) get_option( 'matin_ai_options', [] ), self::defaults() ); }

	public static function styles( string $hook ) : void {
		if ( ! str_starts_with( $hook, 'toplevel_page_matin-ai' ) && ! str_starts_with( $hook, 'matin-ai_page_matin-ai' ) ) { return; }
		wp_add_inline_style( 'wp-admin', '.matin-admin{max-width:1180px;margin:24px 18px 0 0;direction:rtl;color:#172033}.matin-admin-hero{padding:28px;border-radius:20px;background:linear-gradient(125deg,#101827,#273c59);color:#fff;margin-bottom:18px}.matin-admin-hero h1{color:#fff;font-size:26px}.matin-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.matin-admin-card{background:#fff;border:1px solid #e5eaf1;border-radius:16px;padding:22px;box-shadow:0 8px 24px #17203308}.matin-admin-card h2{margin-top:0}.matin-admin-field{margin:15px 0}.matin-admin-field label{display:block;font-weight:600;margin-bottom:7px}.matin-admin-field input[type=text],.matin-admin-field input[type=password],.matin-admin-field input[type=number],.matin-admin-field select,.matin-admin-field textarea{width:100%;max-width:100%;border-radius:9px;padding:9px 11px}.matin-admin-toggle{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #edf0f5}.matin-admin-hint{color:#64748b;font-size:12px}.matin-admin-save{position:sticky;bottom:12px;padding:12px;background:#ffffffed;border:1px solid #e5eaf1;border-radius:12px;margin-top:16px;backdrop-filter:blur(10px)}.matin-admin-ok{color:#166534}.matin-admin-missing{color:#991b1b}@media(max-width:780px){.matin-admin-grid{grid-template-columns:1fr}.matin-admin{margin-right:10px}}' );
	}

	private static function toggle( string $key, string $label, array $o, string $hint = '' ) : void { printf( '<div class="matin-admin-toggle"><div><strong>%s</strong>%s</div><label><input type="checkbox" name="matin_ai_options[%s]" value="1" %s> فعال</label></div>', esc_html( $label ), $hint ? '<div class="matin-admin-hint">' . esc_html( $hint ) . '</div>' : '', esc_attr( $key ), checked( ! empty( $o[ $key ] ), true, false ) ); }
	private static function field( string $key, string $label, array $o, string $type = 'text', string $hint = '' ) : void { printf( '<div class="matin-admin-field"><label for="matin-%1$s">%2$s</label><input id="matin-%1$s" type="%3$s" name="matin_ai_options[%1$s]" value="%4$s">%5$s</div>', esc_attr( $key ), esc_html( $label ), esc_attr( $type ), esc_attr( (string) ( $o[ $key ] ?? '' ) ), $hint ? '<p class="matin-admin-hint">' . esc_html( $hint ) . '</p>' : '' ); }
	private static function secret_field( string $key, string $label, array $o, string $hint ) : void {
		$stored = trim( (string) ( $o[ $key ] ?? '' ) ) !== '';
		printf( '<div class="matin-admin-field"><label for="matin-%1$s">%2$s</label><input id="matin-%1$s" type="password" name="matin_ai_options[%1$s]" value="" autocomplete="new-password"><p class="matin-admin-hint">%3$s</p></div>', esc_attr( $key ), esc_html( $label ), esc_html( ( $stored ? 'مقدار ذخیره‌شده است (رمزنگاری‌شده). ' : 'هنوز ذخیره نشده است. ' ) . $hint ) );
	}

	/** Shows the result of the last connection test, then forgets it. */
	private static function connection_panel() : void {
		$result = get_transient( 'matin_ai_test_' . get_current_user_id() );
		if ( is_array( $result ) ) { delete_transient( 'matin_ai_test_' . get_current_user_id() ); }
		$class = is_array( $result ) && ! empty( $result['ok'] ) ? 'matin-admin-ok' : 'matin-admin-missing';
		echo '<section class="matin-admin-card" style="margin-bottom:16px"><h2>اتصال به هوش مصنوعی</h2><p class="matin-admin-hint">اول کلید را ذخیره کنید، سپس این دکمه را بزنید. نتیجه دقیق سرویس نمایش داده می‌شود.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'matin_ai_test_connection', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="matin_ai_test_connection"><button class="button button-secondary" type="submit">تست اتصال</button></form>';
		if ( is_array( $result ) ) { echo '<p class="' . $class . '" style="margin-top:12px">' . esc_html( (string) $result['message'] ) . '</p>'; }
		echo '</section>';
	}

	public static function handle_test_connection() : void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'دسترسی مجاز نیست.', 'matin-ai' ), '', [ 'response' => 403 ] ); }
		check_admin_referer( 'matin_ai_test_connection' );
		$result = Matin_AI_Provider::test();
		set_transient( 'matin_ai_test_' . get_current_user_id(), $result, MINUTE_IN_SECONDS * 5 );
		wp_safe_redirect( admin_url( 'admin.php?page=matin-ai-settings' ) );
		exit;
	}

	public static function page() : void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$o = self::get_options();
		echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>Matin AI | مرکز مدیریت</h1><p>تنظیمات هوش مصنوعی، پیام‌های واتساپ، ورود کاربران، بلیت‌ها و درآمد در یک پنل.</p></div>';
		self::readiness( $o );
		self::connection_panel();
		echo '<form method="post" action="options.php">';
		settings_fields( 'matin_ai_settings' );
		echo '<div class="matin-admin-grid"><section class="matin-admin-card"><h2>مدل درآمد</h2>';
		self::toggle( 'commission_enabled', 'کارمزد هر رزرو', $o, 'نمونه: ۱۰۰ لیر برای هر رزرو مشمول کارمزد.' ); self::field( 'commission_amount', 'مبلغ کارمزد (لیر)', $o, 'number' );
		self::toggle( 'cafe_fee_enabled', 'دریافت هزینه از کافه', $o );
		echo '<div class="matin-admin-field"><label for="matin-cafe_fee_mode">مدل هزینه کافه</label><select id="matin-cafe_fee_mode" name="matin_ai_options[cafe_fee_mode]"><option value="per_reservation" ' . selected( $o['cafe_fee_mode'], 'per_reservation', false ) . '>به‌ازای هر رزرو</option><option value="monthly" ' . selected( $o['cafe_fee_mode'], 'monthly', false ) . '>اشتراک ماهانه</option><option value="fixed" ' . selected( $o['cafe_fee_mode'], 'fixed', false ) . '>مبلغ ثابت توافق‌شده</option></select></div>';
		self::field( 'cafe_fee_amount', 'مبلغ هزینه کافه (لیر)', $o, 'number' ); echo '<p class="matin-admin-hint">درگاه پرداخت در این نسخه وجود ندارد؛ این تنظیمات برای محاسبه و گزارش‌گیری هستند، نه دریافت خودکار پول.</p></section>';

		echo '<section class="matin-admin-card"><h2>دستیار هوشمند</h2>'; self::toggle( 'ai_enabled', 'فعال بودن دستیار AI', $o );
		echo '<div class="matin-admin-field"><label for="matin-ai_provider">ارائه‌دهنده</label><select id="matin-ai_provider" name="matin_ai_options[ai_provider]"><option value="gemini" ' . selected( $o['ai_provider'], 'gemini', false ) . '>Google Gemini</option><option value="openai" ' . selected( $o['ai_provider'], 'openai', false ) . '>OpenAI</option><option value="compatible" ' . selected( $o['ai_provider'], 'compatible', false ) . '>سازگار با API</option></select></div>';
		self::field( 'ai_model', 'نام مدل', $o ); self::field( 'compatible_base_url', 'آدرس پایه API سازگار', $o, 'url' );
		self::secret_field( 'ai_api_key', 'کلید API هوش مصنوعی', $o, 'برای تغییر وارد کنید؛ خالی بگذارید تا کلید فعلی حفظ شود. کلید هرگز نمایش داده نمی‌شود.' );
		echo '</section>';

		echo '<section class="matin-admin-card"><h2>واتساپ و بلیت</h2>'; self::toggle( 'whatsapp_enabled', 'ارسال پیام‌های واتساپ', $o, 'بدون WhatsApp Cloud API، پیام‌ها به‌صورت «آماده ارسال دستی» ثبت می‌شوند و ارسال خودکار گزارش نمی‌شود.' );
		self::field( 'whatsapp_phone_id', 'Phone Number ID (WhatsApp Cloud API)', $o, 'text', 'شناسه شماره فرستنده در Meta. برای پیام به کافه و مشتری پیش از ۲۴ ساعت از آخرین پیام کاربر، Meta قالب تأییدشده می‌خواهد.' );
		self::secret_field( 'whatsapp_token', 'Access Token (WhatsApp Cloud API)', $o, 'توکن دسترسی Meta؛ به‌صورت رمزنگاری‌شده ذخیره می‌شود.' );
		self::field( 'whatsapp_number', 'شماره پشتیبانی برای نمایش (فقط رقم)', $o );
		printf( '<div class="matin-admin-field"><label for="matin-ticket_note">متن توضیح بلیت</label><textarea id="matin-ticket_note" name="matin_ai_options[ticket_note]" rows="3">%s</textarea></div>', esc_textarea( $o['ticket_note'] ) ); echo '</section>';

		echo '<section class="matin-admin-card"><h2>ورود و تأیید موبایل</h2>';
		self::toggle( 'require_phone_verification', 'اجبار تأیید موبایل برای رزرو', $o, 'تا وقتی پیامک فعال نشده، این گزینه را روشن نگذارید؛ در غیر این صورت کاربران نمی‌توانند رزرو کنند.' );
		self::field( 'sms_sender', 'شماره فرستنده پیامک (Kavenegar)', $o );
		self::secret_field( 'sms_api_key', 'کلید API پیامک (Kavenegar)', $o, 'کد تأیید یک‌بارمصرف ۵ دقیقه معتبر است.' );
		self::toggle( 'google_login_enabled', 'ورود با Google', $o, 'آدرس بازگشت در Google Cloud: ' . home_url( '/my-account/?matin_oauth=google_callback' ) );
		self::field( 'google_client_id', 'Google Client ID', $o );
		self::secret_field( 'google_client_secret', 'Google Client Secret', $o, 'می‌توانید به‌جای این فیلد، ثابت MATIN_GOOGLE_CLIENT_SECRET را در wp-config.php تعریف کنید.' );
		echo '</section></div>';

		echo '<div class="matin-admin-card" style="margin-top:16px"><h2>گزارش رزروها به تفکیک کافه و تاریخ برنامه</h2>'; self::reservation_report(); echo '</div>';
		echo '<div class="matin-admin-card" style="margin-top:16px"><h2>نگهداری گزارش‌ها</h2>'; self::field( 'log_retention_days', 'مدت نگهداری گزارش خطا (روز)', $o, 'number', 'گزارش‌های قدیمی‌تر به‌صورت خودکار پاک می‌شوند.' ); echo '</div>';
		echo '<div class="matin-admin-save">'; submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); echo '</div></form></div>';
	}

	/** Shows which external services are ready, so nothing looks active when it is not configured. */
	private static function readiness( array $o ) : void {
		$items = [
			'هوش مصنوعی'    => '' !== (string) $o['ai_api_key'] || defined( 'MATIN_GEMINI_API_KEY' ) || defined( 'MATIN_OPENAI_API_KEY' ) || defined( 'MATIN_COMPATIBLE_API_KEY' ),
			'WhatsApp API' => '' !== (string) $o['whatsapp_token'] && '' !== (string) $o['whatsapp_phone_id'],
			'پیامک تأیید'  => '' !== (string) $o['sms_api_key'] && '' !== (string) $o['sms_sender'],
			'ورود با Google' => Matin_AI_Google::enabled(),
		];
		echo '<div class="matin-admin-card" style="margin-bottom:16px"><h2>وضعیت سرویس‌ها</h2><ul>';
		foreach ( $items as $label => $ready ) {
			printf( '<li class="%s">%s: %s</li>', $ready ? 'matin-admin-ok' : 'matin-admin-missing', esc_html( $label ), $ready ? 'آماده' : 'تنظیم نشده (بدون این سرویس، قابلیت مربوط غیرفعال است)' );
		}
		echo '</ul></div>';
	}

	private static function reservation_report() : void {
		$ids = get_posts( [ 'post_type' => 'matin_reservation', 'post_status' => 'private', 'numberposts' => 500, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ] );
		if ( ! $ids ) { echo '<p>هنوز رزروی ثبت نشده است.</p>'; return; }
		$groups = [];
		foreach ( $ids as $id ) { $event_id = (int) get_post_meta( $id, '_matin_event_id', true ); $cafe_id = (int) get_post_meta( $event_id, '_matin_cafe_id', true ); $date = substr( (string) get_post_meta( $event_id, '_matin_starts_at', true ), 0, 10 ) ?: 'بدون تاریخ'; $key = $cafe_id . '|' . $date; if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = [ 'cafe' => get_the_title( $cafe_id ) ?: 'کافه نامشخص', 'date' => $date, 'total' => 0, 'pending' => 0, 'confirmed' => 0, 'rejected' => 0, 'cancelled' => 0, 'fee' => 0 ]; } $groups[ $key ]['total']++; $status = (string) get_post_meta( $id, '_matin_status', true ); if ( isset( $groups[ $key ][ $status ] ) ) { $groups[ $key ][ $status ]++; } if ( in_array( $status, [ 'pending', 'confirmed' ], true ) ) { $groups[ $key ]['fee'] += (int) get_post_meta( $id, '_matin_commission_amount', true ); } }
		echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>کافه</th><th>تاریخ برنامه</th><th>کل رزرو</th><th>در انتظار</th><th>تأییدشده</th><th>ردشده</th><th>لغوشده</th><th>کارمزد برآوردی (لیر)</th></tr></thead><tbody>';
		foreach ( $groups as $g ) { printf( '<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>', esc_html( $g['cafe'] ), esc_html( $g['date'] ), $g['total'], $g['pending'], $g['confirmed'], $g['rejected'], $g['cancelled'], esc_html( number_format_i18n( $g['fee'] ) ) ); }
		echo '</tbody></table></div><p class="description">گزارش فقط برآورد کارمزد ذخیره‌شده را نشان می‌دهد و اثبات دریافت وجه نیست.</p>';
	}

	public static function messages_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>پیام‌های واتساپ</h1><p>هر گیرنده وضعیت جدا دارد. «آماده ارسال دستی» یعنی هنوز ارسال نشده؛ فقط بعد از ارسال دستی آن را ثبت کنید.</p></div>';
		echo '<form method="get"><input type="hidden" name="page" value="matin-ai-messages"><select name="status"><option value="">همه</option>';
		foreach ( [ 'queued' => 'در صف', 'sent' => 'ارسال شد', 'failed' => 'ناموفق', 'manual' => 'آماده ارسال دستی', 'manual_done' => 'ارسال دستی ثبت شد' ] as $value => $label ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $status, $value, false ), esc_html( $label ) ); }
		echo '</select> <button class="button">فیلتر</button></form>';
		echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>رزرو</th><th>گیرنده</th><th>نوع</th><th>وضعیت</th><th>تلاش</th><th>خطا</th><th>عملیات</th></tr></thead><tbody>';
		foreach ( Matin_AI_Messaging::list( $status, 200 ) as $m ) {
			$actions = '';
			if ( 'failed' === $m->status ) { $actions .= self::message_button( $m->id, 'retry', 'تلاش مجدد' ); }
			if ( 'manual' === $m->status ) { $actions .= '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( Matin_AI_Messaging::wa_link( $m->phone, $m->body ) ) . '">باز کردن واتساپ</a> ' . self::message_button( $m->id, 'manual_done', 'ثبت ارسال دستی' ); }
			printf( '<tr><td>%d</td><td><a href="%s">#%d</a></td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>', (int) $m->id, esc_url( get_edit_post_link( (int) $m->reservation_id ) ), (int) $m->reservation_id, esc_html( 'cafe' === $m->recipient ? 'کافه' : 'مشتری' ), esc_html( $m->phone ), esc_html( $m->status ), (int) $m->attempts, esc_html( $m->last_error ), $actions );
		}
		echo '</tbody></table></div>';
	}

	private static function message_button( int $id, string $action, string $label ) : string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'matin_ai_message_' . $id, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="matin_ai_message_action"><input type="hidden" name="message_id" value="' . (int) $id . '"><input type="hidden" name="do" value="' . esc_attr( $action ) . '"><button class="button" type="submit">' . esc_html( $label ) . '</button></form> ';
	}

	public static function handle_message_action() : void {
		$id = absint( $_POST['message_id'] ?? 0 );
		check_admin_referer( 'matin_ai_message_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی مجاز نیست.', 403 ); }
		$do = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		if ( 'retry' === $do ) { Matin_AI_Messaging::reset_for_retry( $id ); }
		if ( 'manual_done' === $do ) { Matin_AI_Messaging::mark_manual_done( $id ); }
		wp_safe_redirect( admin_url( 'admin.php?page=matin-ai-messages' ) );
		exit;
	}

	public static function logs_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$level   = sanitize_key( wp_unslash( $_GET['level'] ?? '' ) );
		$channel = sanitize_key( wp_unslash( $_GET['channel'] ?? '' ) );
		echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>گزارش خطاها و عملیات</h1><p>کلید API، رمز و توکن در گزارش‌ها ذخیره نمی‌شوند.</p></div>';
		echo '<form method="get"><input type="hidden" name="page" value="matin-ai-logs"><select name="level"><option value="">همه سطوح</option>';
		foreach ( [ 'error', 'warning', 'info' ] as $value ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $level, $value, false ), esc_html( $value ) ); }
		echo '</select> <select name="channel"><option value="">همه بخش‌ها</option>';
		foreach ( [ 'ai', 'whatsapp', 'sms', 'google', 'reservation', 'php' ] as $value ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $channel, $value, false ), esc_html( $value ) ); }
		echo '</select> <button class="button">فیلتر</button></form>';
		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>زمان</th><th>سطح</th><th>بخش</th><th>پیام</th><th>رزرو</th><th>جزئیات</th></tr></thead><tbody>';
		foreach ( Matin_AI_Logger::query( $level, $channel, 200 ) as $row ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code style="white-space:pre-wrap">%s</code></td></tr>', esc_html( $row->created_at ), esc_html( $row->level ), esc_html( $row->channel ), esc_html( $row->message ), $row->reservation_id ? esc_html( (string) $row->reservation_id ) : '—', esc_html( (string) $row->context ) );
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px">' . wp_nonce_field( 'matin_ai_prune_logs', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="matin_ai_prune_logs"><button class="button button-secondary" type="submit">پاک‌سازی گزارش‌های قدیمی</button></form></div>';
	}

	public static function handle_prune_logs() : void {
		check_admin_referer( 'matin_ai_prune_logs' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی مجاز نیست.', 403 ); }
		Matin_AI_Logger::prune( (int) self::get_options()['log_retention_days'] );
		wp_safe_redirect( admin_url( 'admin.php?page=matin-ai-logs' ) );
		exit;
	}

	public static function verify_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$result = get_transient( 'matin_verify_' . get_current_user_id() );
		delete_transient( 'matin_verify_' . get_current_user_id() );
		echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>اعتبارسنجی بلیت</h1><p>شناسه رزرو و کد اعتبارسنجی روی بلیت را وارد کنید.</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="matin-admin-card">' . wp_nonce_field( 'matin_ai_verify_ticket', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="matin_ai_verify_ticket">';
		echo '<p><label>شناسه رزرو<br><input type="number" name="reservation" required min="1"></label></p><p><label>کد اعتبارسنجی<br><input type="text" name="code" required dir="ltr"></label></p><button class="button button-primary" type="submit">بررسی</button></form>';
		if ( is_array( $result ) ) {
			$class = ! empty( $result['valid'] ) ? 'matin-admin-ok' : 'matin-admin-missing';
			echo '<div class="matin-admin-card ' . esc_attr( $class ) . '" style="margin-top:16px">' . ( ! empty( $result['valid'] ) ? '<strong>بلیت معتبر است.</strong>' : '<strong>بلیت معتبر نیست.</strong>' ) . '<ul>';
			foreach ( [ 'رویداد' => 'event', 'زمان' => 'startsAt', 'مهمان' => 'guest', 'نفرات' => 'guests', 'وضعیت' => 'status' ] as $label => $key ) { printf( '<li>%s: %s</li>', esc_html( $label ), esc_html( (string) ( $result[ $key ] ?? '—' ) ) ); }
			echo '</ul></div>';
		}
		echo '</div>';
	}

	public static function handle_verify_ticket() : void {
		check_admin_referer( 'matin_ai_verify_ticket' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی مجاز نیست.', 403 ); }
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'reservation', absint( $_POST['reservation'] ?? 0 ) );
		$request->set_param( 'code', sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
		$response = Matin_AI_REST::verify_ticket( $request );
		set_transient( 'matin_verify_' . get_current_user_id(), (array) $response->get_data(), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=matin-ai-verify' ) );
		exit;
	}

	public static function reservation_summary_box() : void { add_meta_box( 'matin-reservation-admin-summary', 'گزارش مالی و مشخصات رزرو', [ __CLASS__, 'reservation_summary' ], 'matin_reservation', 'side', 'high' ); }
	public static function reservation_summary( WP_Post $post ) : void {
		$o = self::get_options(); $status = (string) get_post_meta( $post->ID, '_matin_status', true );
		$fee = ! empty( $o['commission_enabled'] ) && in_array( $status, [ 'pending', 'confirmed' ], true ) ? (int) $o['commission_amount'] : 0;
		echo '<p><strong>کد بلیت:</strong> ' . esc_html( (string) get_post_meta( $post->ID, '_matin_ticket_code', true ) ) . '</p><p><strong>کارمزد برآوردی:</strong> ' . esc_html( number_format_i18n( $fee ) ) . ' لیر</p><p class="description">این مبلغ برآوردی است و پرداخت واقعی را نشان نمی‌دهد.</p>';
	}
}
