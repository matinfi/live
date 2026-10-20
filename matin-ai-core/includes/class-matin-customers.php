<?php
defined( 'ABSPATH' ) || exit;

/**
 * Today's confirmed customers: attendance marking, a printable report (save as PDF from the
 * browser's print dialog) and per-customer follow-up emails with a private feedback link.
 */
final class Matin_AI_Customers {
	private const PAGE = 'matin-ai-customers';

	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_init', [ __CLASS__, 'maybe_print' ] );
		add_action( 'admin_post_matin_ai_attendance', [ __CLASS__, 'handle_attendance' ] );
		add_action( 'admin_post_matin_ai_followup', [ __CLASS__, 'handle_followup' ] );
	}

	public static function menu() : void {
		add_submenu_page( 'matin-ai-dashboard', 'مشتریان امروز', 'مشتریان امروز', 'manage_options', self::PAGE, [ __CLASS__, 'page' ] );
	}

	/** Requested day (Y-m-d) in the site time zone; defaults to today. */
	public static function selected_date() : string {
		$raw = sanitize_text_field( wp_unslash( $_GET['date'] ?? '' ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : current_datetime()->format( 'Y-m-d' );
	}

	/**
	 * Confirmed reservations for events that start on the given day, earliest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( string $date ) : array {
		$events = get_posts( [
			'post_type' => 'matin_event', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids',
			// Stored values use the datetime-local format (YYYY-MM-DDTHH:MM), so a string range covers the whole day.
			'meta_query' => [ [ 'key' => '_matin_starts_at', 'value' => [ $date . 'T00:00', $date . 'T23:59:59' ], 'compare' => 'BETWEEN', 'type' => 'CHAR' ] ],
		] );
		if ( ! $events ) { return []; }
		$ids = get_posts( [
			'post_type' => 'matin_reservation', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
			'meta_query' => [
				[ 'key' => '_matin_event_id', 'value' => array_map( 'strval', $events ), 'compare' => 'IN' ],
				[ 'key' => '_matin_status', 'value' => 'confirmed' ],
			],
		] );
		$rows = [];
		foreach ( $ids as $id ) {
			$event_id = (int) get_post_meta( $id, '_matin_event_id', true );
			$cafe_id  = (int) get_post_meta( $event_id, '_matin_cafe_id', true );
			$rows[]   = [
				'id'         => (int) $id,
				'starts'     => str_replace( 'T', ' ', (string) get_post_meta( $event_id, '_matin_starts_at', true ) ),
				'event'      => get_the_title( $event_id ),
				'cafe'       => get_the_title( $cafe_id ),
				'name'       => (string) get_post_meta( $id, '_matin_name', true ),
				'phone'      => (string) get_post_meta( $id, '_matin_phone', true ),
				'email'      => (string) get_post_meta( $id, '_matin_email', true ),
				'guests'     => (int) get_post_meta( $id, '_matin_guests', true ),
				'code'       => (string) get_post_meta( $id, '_matin_ticket_code', true ),
				'attendance' => (string) get_post_meta( $id, '_matin_attendance', true ),
				'followup'   => (string) get_post_meta( $id, '_matin_followup_status', true ),
			];
		}
		usort( $rows, static fn( array $a, array $b ) => strcmp( $a['starts'], $b['starts'] ) ?: strcmp( $a['name'], $b['name'] ) );
		return $rows;
	}

	private static function page_url( array $args = [] ) : string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
	}

	public static function page() : void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$date  = self::selected_date();
		$rows  = self::rows( $date );
		$notice = self::notice();
		$attended = count( array_filter( $rows, static fn( $r ) => 'attended' === $r['attendance'] ) );
		$absent   = count( array_filter( $rows, static fn( $r ) => 'not_attended' === $r['attendance'] ) );
		echo '<div class="wrap matin-admin"><div class="matin-admin-hero"><h1>مشتریان امروز</h1><p>رزروهای تأییدشده برنامه‌های روز انتخاب‌شده. حضور هر مشتری را ثبت کنید، پیام پیگیری بفرستید یا خروجی PDF بگیرید.</p></div>';
		echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in notice().
		echo '<form method="get" class="matin-admin-card" style="margin-bottom:16px;display:flex;gap:10px;align-items:end;flex-wrap:wrap"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label>روز<br><input type="date" name="date" value="' . esc_attr( $date ) . '"></label><button class="button" type="submit">نمایش</button>';
		echo '<a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url( self::page_url( [ 'date' => $date, 'print' => 1 ] ) ) . '">خروجی PDF / چاپ</a>';
		echo '<span class="matin-admin-hint">مشتریان: ' . count( $rows ) . ' · حاضر: ' . $attended . ' · غایب: ' . $absent . '</span></form>';
		if ( ! $rows ) {
			echo '<div class="matin-admin-card"><p>برای این روز رزرو تأییدشده‌ای ثبت نشده است.</p></div></div>';
			return;
		}
		echo '<div class="matin-admin-card" style="overflow:auto"><table class="widefat striped"><thead><tr><th>ساعت</th><th>برنامه</th><th>کافه</th><th>مشتری</th><th>تماس</th><th>نفرات</th><th>کد</th><th>حضور</th><th>پیگیری</th><th>عملیات</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			echo '<tr><td>' . esc_html( $row['starts'] ) . '</td><td>' . esc_html( $row['event'] ) . '</td><td>' . esc_html( $row['cafe'] ) . '</td><td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( $row['phone'] ) . '<br><small>' . esc_html( $row['email'] ) . '</small></td><td>' . (int) $row['guests'] . '</td><td><code>' . esc_html( $row['code'] ) . '</code></td>';
			echo '<td>' . esc_html( self::attendance_label( $row['attendance'] ) ) . '</td><td>' . esc_html( self::followup_label( $row['followup'] ) ) . '</td><td>';
			echo self::attendance_form( $id, 'attended', 'حاضر', $date ) . self::attendance_form( $id, 'not_attended', 'غایب', $date );
			if ( 'attended' === $row['attendance'] || 'not_attended' === $row['attendance'] ) { echo self::attendance_form( $id, '', 'پاک‌کردن', $date ); }
			echo self::followup_form( $id, $date ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private static function attendance_form( int $id, string $value, string $label, string $date ) : string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:2px">' . wp_nonce_field( 'matin_ai_attendance_' . $id, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="matin_ai_attendance"><input type="hidden" name="reservation_id" value="' . $id . '"><input type="hidden" name="attendance" value="' . esc_attr( $value ) . '"><input type="hidden" name="date" value="' . esc_attr( $date ) . '">'
			. '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}

	private static function followup_form( int $id, string $date ) : string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:6px">' . wp_nonce_field( 'matin_ai_followup_' . $id, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="matin_ai_followup"><input type="hidden" name="reservation_id" value="' . $id . '"><input type="hidden" name="date" value="' . esc_attr( $date ) . '">'
			. '<button class="button button-secondary" type="submit">ارسال پیام پیگیری</button></form>';
	}

	public static function attendance_label( string $value ) : string { return [ 'attended' => 'حاضر', 'not_attended' => 'غایب' ][ $value ] ?? 'ثبت‌نشده'; }
	public static function followup_label( string $value ) : string { return [ 'sent' => 'ارسال شد', 'failed' => 'ارسال نشد', 'no_email' => 'ایمیل ندارد' ][ $value ] ?? '—'; }

	/** Printable page (A4) for saving as PDF. Opened in a new tab and launches the print dialog. */
	public static function maybe_print() : void {
		if ( empty( $_GET['print'] ) || self::PAGE !== ( $_GET['page'] ?? '' ) || ! current_user_can( 'manage_options' ) ) { return; }
		$date = self::selected_date();
		$rows = self::rows( $date );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>مشتریان ' . esc_html( $date ) . '</title><style>
body{font-family:Tahoma,Arial,sans-serif;color:#111;margin:24px;font-size:12px}
h1{font-size:18px;margin:0 0 4px}.meta{color:#555;margin-bottom:12px}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:5px 6px;text-align:right;vertical-align:top}
th{background:#eee}.btn{margin-bottom:12px;padding:6px 14px}@media print{.btn{display:none}body{margin:10mm}}
@page{size:A4 landscape;margin:10mm}</style></head><body>
<button class="btn" onclick="window.print()">چاپ / ذخیره به‌صورت PDF</button>
<h1>Matin AI — مشتریان روز ' . esc_html( $date ) . '</h1>
<div class="meta">تعداد: ' . count( $rows ) . ' · تهیه‌شده در ' . esc_html( wp_date( 'Y-m-d H:i' ) ) . '</div>
<table><thead><tr><th>#</th><th>ساعت</th><th>برنامه</th><th>کافه</th><th>نام مشتری</th><th>موبایل</th><th>ایمیل</th><th>نفرات</th><th>کد رزرو</th><th>وضعیت حضور</th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			echo '<tr><td>' . ( $i + 1 ) . '</td><td>' . esc_html( $row['starts'] ) . '</td><td>' . esc_html( $row['event'] ) . '</td><td>' . esc_html( $row['cafe'] ) . '</td><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['phone'] ) . '</td><td>' . esc_html( $row['email'] ) . '</td><td>' . (int) $row['guests'] . '</td><td>' . esc_html( $row['code'] ) . '</td><td>' . esc_html( self::attendance_label( $row['attendance'] ) ) . '</td></tr>';
		}
		echo '</tbody></table><script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)});</script></body></html>';
		exit;
	}

	public static function handle_attendance() : void {
		$id = absint( $_POST['reservation_id'] ?? 0 );
		check_admin_referer( 'matin_ai_attendance_' . $id );
		self::guard( $id );
		$value = sanitize_key( wp_unslash( $_POST['attendance'] ?? '' ) );
		if ( ! in_array( $value, [ 'attended', 'not_attended', '' ], true ) ) { wp_die( esc_html__( 'وضعیت حضور نامعتبر است.', 'matin-ai' ), '', [ 'response' => 422 ] ); }
		if ( '' === $value ) {
			delete_post_meta( $id, '_matin_attendance' );
			delete_post_meta( $id, '_matin_attendance_at' );
			delete_post_meta( $id, '_matin_attendance_by' );
		} else {
			update_post_meta( $id, '_matin_attendance', $value );
			update_post_meta( $id, '_matin_attendance_at', current_time( 'mysql' ) );
			update_post_meta( $id, '_matin_attendance_by', get_current_user_id() );
		}
		Matin_AI_Operations::log( 'attendance_marked', $id, $value ?: 'cleared' );
		wp_safe_redirect( self::page_url( [ 'date' => self::posted_date(), 'notice' => 'attendance' ] ) );
		exit;
	}

	public static function handle_followup() : void {
		$id = absint( $_POST['reservation_id'] ?? 0 );
		check_admin_referer( 'matin_ai_followup_' . $id );
		self::guard( $id );
		$email = sanitize_email( (string) get_post_meta( $id, '_matin_email', true ) );
		if ( ! is_email( $email ) ) {
			update_post_meta( $id, '_matin_followup_status', 'no_email' );
			wp_safe_redirect( self::page_url( [ 'date' => self::posted_date(), 'notice' => 'no_email' ] ) );
			exit;
		}
		$event = get_the_title( (int) get_post_meta( $id, '_matin_event_id', true ) );
		$name  = (string) get_post_meta( $id, '_matin_name', true );
		$code  = (string) get_post_meta( $id, '_matin_ticket_code', true );
		$link  = Matin_AI_Complaints::feedback_url( $id );
		$subject = 'پیگیری رزرو شما در Matin AI — ' . wp_strip_all_tags( $event );
		$body = "سلام " . wp_strip_all_tags( $name ) . "،\n\nاز اینکه در برنامه «" . wp_strip_all_tags( $event ) . "» با ما همراه بودید سپاسگزاریم. امیدواریم تجربهٔ خوبی داشته باشید.\n\n"
			. "کد رزرو شما: " . $code . "\n\n"
			. "اگر نظر، پیشنهاد یا شکایتی دارید، می‌توانید از این لینک اختصاصی برای ما بنویسید:\n" . $link . "\n\n"
			. "با احترام\nتیم Matin AI";
		$sent = wp_mail( $email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		update_post_meta( $id, '_matin_followup_status', $sent ? 'sent' : 'failed' );
		update_post_meta( $id, '_matin_followup_at', current_time( 'mysql' ) );
		Matin_AI_Operations::log( 'followup_email', $id, $sent ? 'sent' : 'wp_mail_failed' );
		wp_safe_redirect( self::page_url( [ 'date' => self::posted_date(), 'notice' => $sent ? 'followup_sent' : 'followup_failed' ] ) );
		exit;
	}

	private static function guard( int $id ) : void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'دسترسی مجاز نیست.', 'matin-ai' ), '', [ 'response' => 403 ] ); }
		if ( 'matin_reservation' !== get_post_type( $id ) ) { wp_die( esc_html__( 'رزرو پیدا نشد.', 'matin-ai' ), '', [ 'response' => 404 ] ); }
		if ( 'confirmed' !== (string) get_post_meta( $id, '_matin_status', true ) ) { wp_die( esc_html__( 'این عملیات فقط برای رزروهای تأییدشده است.', 'matin-ai' ), '', [ 'response' => 409 ] ); }
	}

	private static function posted_date() : string {
		$raw = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : current_datetime()->format( 'Y-m-d' );
	}

	private static function notice() : string {
		$map = [
			'attendance'      => [ 'ثبت حضور به‌روزرسانی شد.', 'success' ],
			'no_email'        => [ 'این مشتری ایمیل معتبری ندارد؛ پیام ارسال نشد.', 'warning' ],
			'followup_sent'   => [ 'پیام پیگیری ارسال شد.', 'success' ],
			'followup_failed' => [ 'ارسال ایمیل انجام نشد. تنظیمات ایمیل سرور را بررسی کنید.', 'error' ],
		];
		$key = sanitize_key( wp_unslash( $_GET['notice'] ?? '' ) );
		if ( ! isset( $map[ $key ] ) ) { return ''; }
		return '<div class="notice notice-' . esc_attr( $map[ $key ][1] ) . ' is-dismissible"><p>' . esc_html( $map[ $key ][0] ) . '</p></div>';
	}
}
