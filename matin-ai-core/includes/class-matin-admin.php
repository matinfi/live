<?php
defined( 'ABSPATH' ) || exit;

final class Matin_AI_Admin {
	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'styles' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'reservation_summary_box' ] );
	}
	public static function menu() : void {
		add_menu_page( 'تنظیمات Matin AI', 'تنظیمات Matin AI', 'manage_options', 'matin-ai-settings', [ __CLASS__, 'page' ], 'dashicons-format-chat', 58 );
	}
	public static function settings() : void {
		register_setting( 'matin_ai_settings', 'matin_ai_options', [ 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
	}
	public static function sanitize( $input ) : array {
		$input = is_array( $input ) ? $input : [];
		$defaults = self::defaults(); $out = [];
		foreach ( [ 'commission_enabled', 'cafe_fee_enabled', 'google_login_enabled', 'apple_login_enabled', 'whatsapp_enabled', 'ai_enabled' ] as $key ) { $out[ $key ] = empty( $input[ $key ] ) ? 0 : 1; }
		$out['commission_amount'] = max( 0, min( 1000000, absint( $input['commission_amount'] ?? $defaults['commission_amount'] ) ) );
		$out['cafe_fee_mode'] = in_array( $input['cafe_fee_mode'] ?? '', [ 'per_reservation', 'monthly', 'fixed' ], true ) ? $input['cafe_fee_mode'] : 'monthly';
		$out['cafe_fee_amount'] = max( 0, min( 100000000, absint( $input['cafe_fee_amount'] ?? 0 ) ) );
		$out['whatsapp_number'] = preg_replace( '/[^0-9]/', '', (string) ( $input['whatsapp_number'] ?? '' ) );
		$out['ai_provider'] = in_array( $input['ai_provider'] ?? '', [ 'gemini', 'openai', 'compatible' ], true ) ? $input['ai_provider'] : 'gemini';
		$out['ai_model'] = sanitize_text_field( $input['ai_model'] ?? '' );
		$out['ai_api_key'] = '' !== trim( (string) ( $input['ai_api_key'] ?? '' ) ) ? sanitize_text_field( $input['ai_api_key'] ) : (string) ( get_option( 'matin_ai_options', [] )['ai_api_key'] ?? '' );
		$out['compatible_base_url'] = esc_url_raw( $input['compatible_base_url'] ?? '' );
		$out['google_client_id'] = sanitize_text_field( $input['google_client_id'] ?? '' );
		$out['apple_client_id'] = sanitize_text_field( $input['apple_client_id'] ?? '' );
		$out['ticket_note'] = sanitize_textarea_field( $input['ticket_note'] ?? '' );
		return $out;
	}
	public static function defaults() : array { return [ 'commission_enabled' => 1, 'commission_amount' => 100, 'cafe_fee_enabled' => 0, 'cafe_fee_mode' => 'monthly', 'cafe_fee_amount' => 0, 'google_login_enabled' => 0, 'apple_login_enabled' => 0, 'whatsapp_enabled' => 1, 'whatsapp_number' => '', 'ai_enabled' => 1, 'ai_provider' => 'gemini', 'ai_model' => '', 'ai_api_key' => '', 'compatible_base_url' => '', 'google_client_id' => '', 'apple_client_id' => '', 'ticket_note' => 'این رسید تا زمان تأیید کافه، بلیت قطعی ورود نیست.' ]; }
	public static function get_options() : array { return wp_parse_args( (array) get_option( 'matin_ai_options', [] ), self::defaults() ); }
	public static function styles( string $hook ) : void {
		if ( 'toplevel_page_matin-ai-settings' !== $hook ) { return; }
		wp_add_inline_style( 'wp-admin', '.matin-admin{max-width:1180px;margin:24px 18px 0 0;direction:rtl;color:#172033}.matin-admin-hero{padding:28px;border-radius:20px;background:linear-gradient(125deg,#101827,#273c59);color:#fff;margin-bottom:18px}.matin-admin-hero h1{color:#fff;font-size:26px}.matin-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.matin-admin-card{background:#fff;border:1px solid #e5eaf1;border-radius:16px;padding:22px;box-shadow:0 8px 24px #17203308}.matin-admin-card h2{margin-top:0}.matin-admin-field{margin:15px 0}.matin-admin-field label{display:block;font-weight:600;margin-bottom:7px}.matin-admin-field input[type=text],.matin-admin-field input[type=password],.matin-admin-field input[type=number],.matin-admin-field select,.matin-admin-field textarea{width:100%;max-width:100%;border-radius:9px;padding:9px 11px}.matin-admin-toggle{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #edf0f5}.matin-admin-hint{color:#64748b;font-size:12px}.matin-admin-save{position:sticky;bottom:12px;padding:12px;background:#ffffffed;border:1px solid #e5eaf1;border-radius:12px;margin-top:16px;backdrop-filter:blur(10px)}@media(max-width:780px){.matin-admin-grid{grid-template-columns:1fr}.matin-admin{margin-right:10px}}' );
	}
	private static function toggle( string $key, string $label, array $o, string $hint = '' ) : void { printf( '<div class="matin-admin-toggle"><div><strong>%s</strong>%s</div><label><input type="checkbox" name="matin_ai_options[%s]" value="1" %s> فعال</label></div>', esc_html( $label ), $hint ? '<div class="matin-admin-hint">' . esc_html( $hint ) . '</div>' : '', esc_attr( $key ), checked( ! empty( $o[ $key ] ), true, false ) ); }
	private static function field( string $key, string $label, array $o, string $type = 'text', string $hint = '' ) : void { printf( '<div class="matin-admin-field"><label for="matin-%1$s">%2$s</label><input id="matin-%1$s" type="%3$s" name="matin_ai_options[%1$s]" value="%4$s">%5$s</div>', esc_attr( $key ), esc_html( $label ), esc_attr( $type ), esc_attr( (string) ( $o[ $key ] ?? '' ) ), $hint ? '<p class="matin-admin-hint">' . esc_html( $hint ) . '</p>' : '' ); }
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
		echo '<div class="matin-admin-field"><label for="matin-ai_provider">ارائه‌دهنده</label><select id="matin-ai_provider" name="matin_ai_options[ai_provider]"><option value="gemini" ' . selected( $o['ai_provider'], 'gemini', false ) . '>Google Gemini</option><option value="openai" ' . selected( $o['ai_provider'], 'openai', false ) . '>OpenAI</option><option value="compatible" ' . selected( $o['ai_provider'], 'compatible', false ) . '>سازگار با API</option></select></div>';
		self::field( 'ai_model', 'نام مدل', $o ); self::field( 'compatible_base_url', 'آدرس پایه API سازگار', $o, 'url' ); self::field( 'ai_api_key', 'کلید API (برای تغییر وارد کنید)', [], 'password', 'برای حفظ امنیت کلید ذخیره‌شده نمایش داده نمی‌شود؛ اگر تغییر نمی‌دهید این قسمت را خالی بگذارید. برای Gemini، ارائه‌دهنده را روی Google Gemini بگذارید؛ نام مدل را می‌توانید خالی بگذارید تا مدل پیش‌فرض gemini-2.5-flash استفاده شود. آدرس پایه فقط برای ارائه‌دهندهٔ سازگار با API لازم است.' ); echo '</section>';
		echo '<section class="matin-admin-card"><h2>ورود و ثبت‌نام</h2>'; self::toggle( 'google_login_enabled', 'ورود با Google', $o, 'فعال‌سازی واقعی نیازمند OAuth Client و پیاده‌سازی callback امن است.' ); self::field( 'google_client_id', 'Google Client ID', $o ); self::toggle( 'apple_login_enabled', 'ورود با Apple ID', $o, 'نیازمند Apple Developer Services و تنظیم دامنه است.' ); self::field( 'apple_client_id', 'Apple Service ID', $o ); echo '<p class="matin-admin-hint">نمایش کلید روشن/خاموش به‌تنهایی اتصال OAuth را فعال نمی‌کند؛ اتصال ارائه‌دهنده باید تکمیل و آزمایش شود.</p></section>';
		echo '<section class="matin-admin-card"><h2>واتساپ و بلیت</h2>'; self::toggle( 'whatsapp_enabled', 'نمایش ارسال بلیت با واتساپ', $o ); self::field( 'whatsapp_number', 'شماره واتساپ مقصد (با کد کشور، فقط رقم)', $o, 'text', 'نسخه اول پیام آماده را باز می‌کند و کاربر ارسال را تأیید می‌کند؛ ارسال خودکار به اتصال رسمی WhatsApp Business نیاز دارد.' ); printf( '<div class="matin-admin-field"><label for="matin-ticket_note">متن توضیح بلیت</label><textarea id="matin-ticket_note" name="matin_ai_options[ticket_note]" rows="3">%s</textarea></div>', esc_textarea( $o['ticket_note'] ) ); echo '</section></div><div class="matin-admin-card" style="margin-top:16px"><h2>گزارش رزروها به تفکیک کافه و تاریخ برنامه</h2>'; self::reservation_report(); echo '</div><div class="matin-admin-save">'; submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); echo '</div></form></div>';
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
	public static function reservation_summary_box() : void { add_meta_box( 'matin-reservation-admin-summary', 'گزارش مالی و مشخصات رزرو', [ __CLASS__, 'reservation_summary' ], 'matin_reservation', 'side', 'high' ); }
	public static function reservation_summary( WP_Post $post ) : void {
		$o = self::get_options(); $status = get_post_meta( $post->ID, '_matin_status', true ); $event_id = (int) get_post_meta( $post->ID, '_matin_event_id', true ); if ( ! $event_id ) { $event_id = (int) get_post_meta( $post->ID, '_matin_event_id', true ); }
		$fee = ! empty( $o['commission_enabled'] ) && in_array( $status, [ 'pending', 'confirmed' ], true ) ? (int) $o['commission_amount'] : 0;
		echo '<p><strong>کد بلیت:</strong> ' . esc_html( (string) get_post_meta( $post->ID, '_matin_ticket_code', true ) ) . '</p><p><strong>کارمزد برآوردی:</strong> ' . esc_html( number_format_i18n( $fee ) ) . ' لیر</p><p class="description">این مبلغ برآوردی است و پرداخت واقعی را نشان نمی‌دهد.</p>';
	}
}
