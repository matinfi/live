<?php
defined( 'ABSPATH' ) || exit;

/**
 * Complaints and feedback. Each message is stored as a private "ticket" (matin_complaint) that only
 * administrators can read in the WordPress admin. Customers can open the form from a signed link in
 * their follow-up email; that link ties the feedback to their reservation without requiring a login.
 */
final class Matin_AI_Complaints {
	public const PAGE_SLUG = 'complaints';
	private const RATE_LIMIT = 5; // submissions per IP per 10 minutes

	public static function init() : void {
		add_action( 'init', [ __CLASS__, 'register_type' ] );
		add_shortcode( 'matin_ai_complaints', [ __CLASS__, 'render_form' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_submit' ] );
		add_action( 'admin_post_matin_ai_complaint_status', [ __CLASS__, 'handle_status' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_box' ] );
		add_filter( 'manage_matin_complaint_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_matin_complaint_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
	}

	public static function register_type() : void {
		register_post_type( 'matin_complaint', [
			'labels'       => [ 'name' => 'شکایات و بازخورد', 'singular_name' => 'شکایت', 'menu_name' => 'شکایات و بازخورد', 'all_items' => 'همه موارد', 'search_items' => 'جست‌وجوی شکایات', 'not_found' => 'موردی پیدا نشد.' ],
			'public'       => false, 'show_ui' => true, 'show_in_menu' => 'matin-ai-dashboard', 'show_in_rest' => false,
			'supports'     => [ 'title' ], 'capability_type' => 'post', 'map_meta_cap' => true,
			'menu_icon'    => 'dashicons-feedback',
		] );
	}

	/** Public URL of the complaints page (created by Matin_AI_Pages). */
	public static function page_url() : string {
		$page = get_page_by_path( self::PAGE_SLUG );
		return $page ? (string) get_permalink( $page ) : home_url( '/' . self::PAGE_SLUG . '/' );
	}

	/** Signed, per-reservation token. Changes if the site salts change. */
	public static function token( int $reservation_id ) : string {
		return substr( hash_hmac( 'sha256', 'feedback|' . $reservation_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function feedback_url( int $reservation_id ) : string {
		return add_query_arg( [ 'rid' => $reservation_id, 't' => self::token( $reservation_id ) ], self::page_url() );
	}

	/** Validates a reservation id and token pair from a feedback link. */
	private static function verified_reservation( mixed $rid, mixed $t ) : int {
		$id = absint( $rid );
		$t  = is_string( $t ) ? preg_replace( '/[^a-f0-9]/', '', $t ) : '';
		if ( ! $id || 'matin_reservation' !== get_post_type( $id ) || '' === $t ) { return 0; }
		return hash_equals( self::token( $id ), $t ) ? $id : 0;
	}

	public static function render_form() : string {
		$notice = '';
		$status = sanitize_key( wp_unslash( $_GET['complaint'] ?? '' ) );
		if ( 'ok' === $status ) { $notice = '<p class="matin-notice matin-ok" role="status">پیام شما ثبت شد. از اینکه وقت گذاشتید سپاسگزاریم؛ در اسرع وقت بررسی می‌شود.</p>'; }
		elseif ( 'invalid' === $status ) { $notice = '<p class="matin-notice matin-error" role="alert">لطفاً نوع پیام، متن و در صورت وجود، ایمیل معتبر را بررسی کنید.</p>'; }
		elseif ( 'limit' === $status ) { $notice = '<p class="matin-notice matin-error" role="alert">تعداد پیام‌های ارسالی زیاد است. چند دقیقه بعد دوباره تلاش کنید.</p>'; }
		$rid = self::verified_reservation( $_GET['rid'] ?? 0, $_GET['t'] ?? '' );
		$user = wp_get_current_user();
		$name = $user->exists() ? (string) $user->display_name : '';
		$email = $user->exists() ? (string) $user->user_email : '';
		$context = $rid ? '<p class="matin-muted">این پیام به رزرو شما با کد <strong>' . esc_html( (string) get_post_meta( $rid, '_matin_ticket_code', true ) ) . '</strong> مربوط می‌شود.</p>' : '';
		return '<section class="matin-complaint-form"><h2>شکایت، پیشنهاد یا بازخورد</h2><p>نظر شما به بهتر شدن برنامه‌ها کمک می‌کند. پیام شما فقط برای مدیریت Matin AI قابل مشاهده است.</p>' . $notice . $context
			. '<form method="post" action="' . esc_url( self::page_url() ) . '">' . wp_nonce_field( 'matin_ai_complaint', '_matin_nonce', true, false )
			. '<input type="hidden" name="matin_complaint_action" value="submit"><input type="hidden" name="rid" value="' . (int) $rid . '"><input type="hidden" name="t" value="' . esc_attr( $rid ? self::token( $rid ) : '' ) . '">'
			. '<p style="position:absolute;left:-9999px" aria-hidden="true"><label>وب‌سایت<input name="matin_website" tabindex="-1" autocomplete="off"></label></p>'
			. '<p><label>نوع پیام<br><select name="matin_type" required><option value="complaint">شکایت</option><option value="suggestion">پیشنهاد</option><option value="feedback">بازخورد</option></select></label></p>'
			. '<p><label>نام<br><input name="matin_name" value="' . esc_attr( $name ) . '" required minlength="2" maxlength="120"></label></p>'
			. '<p><label>ایمیل (برای پاسخ)<br><input type="email" name="matin_email" value="' . esc_attr( $email ) . '" maxlength="190"></label></p>'
			. '<p><label>پیام شما<br><textarea name="matin_message" rows="6" required minlength="10" maxlength="2000"></textarea></label></p>'
			. '<button type="submit">ارسال پیام</button></form></section>';
	}

	/** Field names are prefixed: WordPress reads public query vars (such as name) from POST data and would 404. */
	public static function handle_submit() : void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['matin_complaint_action'] ) || ! is_singular() || ! has_shortcode( (string) get_post()?->post_content, 'matin_ai_complaints' ) ) { return; }
		$target = get_permalink();
		$nonce  = sanitize_text_field( wp_unslash( $_POST['_matin_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'matin_ai_complaint' ) ) { wp_safe_redirect( add_query_arg( 'complaint', 'invalid', $target ) ); exit; }
		if ( '' !== trim( (string) ( $_POST['matin_website'] ?? '' ) ) ) { wp_safe_redirect( add_query_arg( 'complaint', 'ok', $target ) ); exit; } // Honeypot: pretend success.

		$ip_key = 'matin_complaint_rl_' . md5( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ) );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= self::RATE_LIMIT ) { wp_safe_redirect( add_query_arg( 'complaint', 'limit', $target ) ); exit; }

		$type    = sanitize_key( wp_unslash( $_POST['matin_type'] ?? '' ) );
		$name    = sanitize_text_field( wp_unslash( $_POST['matin_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['matin_email'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['matin_message'] ?? '' ) );
		$rid     = self::verified_reservation( $_POST['rid'] ?? 0, $_POST['t'] ?? '' );
		$types   = [ 'complaint' => 'شکایت', 'suggestion' => 'پیشنهاد', 'feedback' => 'بازخورد' ];
		if ( ! isset( $types[ $type ] ) || strlen( $name ) < 2 || mb_strlen( $message ) < 10 || mb_strlen( $message ) > 2000 || ( '' !== $email && ! is_email( $email ) ) ) {
			wp_safe_redirect( add_query_arg( 'complaint', 'invalid', $target ) ); exit;
		}

		$id = wp_insert_post( [
			'post_type' => 'matin_complaint', 'post_status' => 'private',
			'post_title' => $types[ $type ] . ' — ' . $name . ' — ' . wp_date( 'Y-m-d H:i' ),
			'post_content' => $message,
		], true );
		if ( is_wp_error( $id ) ) { wp_safe_redirect( add_query_arg( 'complaint', 'invalid', $target ) ); exit; }
		update_post_meta( $id, '_matin_c_type', $type );
		update_post_meta( $id, '_matin_c_name', $name );
		update_post_meta( $id, '_matin_c_email', $email );
		update_post_meta( $id, '_matin_c_reservation', $rid );
		update_post_meta( $id, '_matin_c_status', 'open' );
		set_transient( $ip_key, $count + 1, 10 * MINUTE_IN_SECONDS );
		Matin_AI_Operations::log( 'complaint_received', $rid, $type );

		if ( is_email( $email ) ) {
			wp_mail( $email, 'پیام شما به Matin AI رسید', "سلام " . wp_strip_all_tags( $name ) . "،\n\nپیام شما دریافت شد و بررسی می‌شود. از وقتی که گذاشتید سپاسگزاریم.\n\nMatin AI", [ 'Content-Type: text/plain; charset=UTF-8' ] );
		}
		wp_safe_redirect( add_query_arg( 'complaint', 'ok', $target ) );
		exit;
	}

	public static function handle_status() : void {
		$id = absint( $_POST['complaint_id'] ?? 0 );
		check_admin_referer( 'matin_ai_complaint_status_' . $id );
		if ( ! current_user_can( 'manage_options' ) || 'matin_complaint' !== get_post_type( $id ) ) { wp_die( esc_html__( 'دسترسی مجاز نیست.', 'matin-ai' ), '', [ 'response' => 403 ] ); }
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? 'open' ) );
		update_post_meta( $id, '_matin_c_status', 'closed' === $status ? 'closed' : 'open' );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=matin_complaint' ) );
		exit;
	}

	public static function meta_box() : void {
		add_meta_box( 'matin-complaint-detail', 'جزئیات پیام', [ __CLASS__, 'render_meta_box' ], 'matin_complaint', 'side', 'high' );
	}

	public static function render_meta_box( WP_Post $post ) : void {
		$status = (string) get_post_meta( $post->ID, '_matin_c_status', true ) ?: 'open';
		$rid    = (int) get_post_meta( $post->ID, '_matin_c_reservation', true );
		echo '<p><strong>نوع:</strong> ' . esc_html( [ 'complaint' => 'شکایت', 'suggestion' => 'پیشنهاد', 'feedback' => 'بازخورد' ][ (string) get_post_meta( $post->ID, '_matin_c_type', true ) ] ?? '—' ) . '</p>';
		echo '<p><strong>نام:</strong> ' . esc_html( (string) get_post_meta( $post->ID, '_matin_c_name', true ) ) . '</p>';
		echo '<p><strong>ایمیل:</strong> ' . esc_html( (string) get_post_meta( $post->ID, '_matin_c_email', true ) ?: '—' ) . '</p>';
		if ( $rid ) { echo '<p><strong>رزرو:</strong> ' . esc_html( (string) get_post_meta( $rid, '_matin_ticket_code', true ) ) . '</p>'; }
		echo '<p><strong>وضعیت:</strong> ' . ( 'closed' === $status ? 'بسته شده' : 'باز' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'matin_ai_complaint_status_' . $post->ID, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="matin_ai_complaint_status"><input type="hidden" name="complaint_id" value="' . (int) $post->ID . '">'
			. '<select name="status"><option value="open"' . selected( $status, 'open', false ) . '>باز</option><option value="closed"' . selected( $status, 'closed', false ) . '>بسته</option></select> <button class="button" type="submit">ذخیره وضعیت</button></form>';
	}

	public static function columns( array $columns ) : array {
		return [ 'cb' => $columns['cb'] ?? '', 'title' => 'موضوع', 'matin_c_type' => 'نوع', 'matin_c_status' => 'وضعیت', 'date' => 'تاریخ' ];
	}

	public static function column_content( string $column, int $post_id ) : void {
		if ( 'matin_c_type' === $column ) { echo esc_html( [ 'complaint' => 'شکایت', 'suggestion' => 'پیشنهاد', 'feedback' => 'بازخورد' ][ (string) get_post_meta( $post_id, '_matin_c_type', true ) ] ?? '—' ); }
		if ( 'matin_c_status' === $column ) { echo 'closed' === get_post_meta( $post_id, '_matin_c_status', true ) ? 'بسته' : 'باز'; }
	}
}
