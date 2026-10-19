<?php
defined( 'ABSPATH' ) || exit;

final class Matin_AI_Content {
	private const META_PREFIX = '_matin_';

	public static function init() : void {
		add_action( 'init', [ __CLASS__, 'register_types' ] );
		add_filter( 'manage_matin_event_posts_columns', [ __CLASS__, 'event_columns' ] );
		add_action( 'manage_matin_event_posts_custom_column', [ __CLASS__, 'event_column_content' ], 10, 2 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_boxes' ] );
		add_action( 'save_post', [ __CLASS__, 'save_meta' ], 10, 2 );
		add_filter( 'manage_matin_reservation_posts_columns', [ __CLASS__, 'reservation_columns' ] );
		add_action( 'manage_matin_reservation_posts_custom_column', [ __CLASS__, 'reservation_column_content' ], 10, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
	}

	public static function register_types() : void {
		register_post_type( 'matin_cafe', [
			'labels' => [ 'name' => __( 'کافه‌ها', 'matin-ai' ), 'singular_name' => __( 'کافه', 'matin-ai' ) ],
			'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-coffee',
			'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ], 'has_archive' => true,
			'rewrite' => [ 'slug' => 'cafes' ], 'show_in_menu' => true, 'capability_type' => 'post', 'map_meta_cap' => true,
		] );
		register_post_type( 'matin_event', [
			'labels' => [ 'name' => __( 'رویدادها', 'matin-ai' ), 'singular_name' => __( 'رویداد', 'matin-ai' ) ],
			'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-calendar-alt',
			'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ], 'has_archive' => true,
			'rewrite' => [ 'slug' => 'events' ], 'show_in_menu' => true, 'capability_type' => 'post', 'map_meta_cap' => true,
		] );
		register_post_type( 'matin_reservation', [
			'labels' => [ 'name' => __( 'درخواست‌های رزرو', 'matin-ai' ), 'singular_name' => __( 'رزرو', 'matin-ai' ) ],
			'public' => false, 'show_ui' => true, 'show_in_menu' => 'edit.php?post_type=matin_event',
			'capability_type' => 'post', 'supports' => [ 'title' ], 'map_meta_cap' => true,
		] );
		register_taxonomy( 'matin_event_type', 'matin_event', [
			'labels' => [ 'name' => __( 'دسته‌بندی رویدادها', 'matin-ai' ), 'singular_name' => __( 'دسته رویداد', 'matin-ai' ) ],
			'public' => true, 'show_in_rest' => true, 'hierarchical' => true,
		] );
	}

	public static function register_meta_boxes() : void {
		add_meta_box( 'matin-cafe-details', __( 'اطلاعات کافه', 'matin-ai' ), [ __CLASS__, 'cafe_box' ], 'matin_cafe', 'normal', 'high' );
		add_meta_box( 'matin-event-details', __( 'اطلاعات رویداد', 'matin-ai' ), [ __CLASS__, 'event_box' ], 'matin_event', 'normal', 'high' );
		add_meta_box( 'matin-reservation-details', __( 'جزئیات رزرو', 'matin-ai' ), [ __CLASS__, 'reservation_box' ], 'matin_reservation', 'normal', 'high' );
	}

	private static function input( string $label, string $name, string $value = '', string $type = 'text', string $step = '' ) : void {
		printf( '<p><label for="%1$s"><strong>%2$s</strong></label><br><input class="widefat" id="%1$s" name="%1$s" type="%3$s" value="%4$s"%5$s></p>', esc_attr( $name ), esc_html( $label ), esc_attr( $type ), esc_attr( $value ), $step ? ' step="' . esc_attr( $step ) . '"' : '' );
	}
	public static function cafe_box( WP_Post $post ) : void {
		wp_nonce_field( 'matin_save_meta', 'matin_meta_nonce' );
		self::input( __( 'شهر / محله', 'matin-ai' ), 'matin_location', (string) get_post_meta( $post->ID, self::META_PREFIX . 'location', true ) );
		self::input( __( 'نشانی', 'matin-ai' ), 'matin_address', (string) get_post_meta( $post->ID, self::META_PREFIX . 'address', true ) );
		self::input( __( 'پیوند نقشه (فقط HTTPS)', 'matin-ai' ), 'matin_map_url', (string) get_post_meta( $post->ID, self::META_PREFIX . 'map_url', true ), 'url' );
		self::input( 'عرض جغرافیایی (lat)', 'matin_lat', (string) get_post_meta( $post->ID, self::META_PREFIX . 'lat', true ), 'number', 'any' );
		self::input( 'طول جغرافیایی (lng)', 'matin_lng', (string) get_post_meta( $post->ID, self::META_PREFIX . 'lng', true ), 'number', 'any' );
		self::input( 'شماره تماس کافه (برای پیام‌های واتساپ)', 'matin_cafe_phone', (string) get_post_meta( $post->ID, self::META_PREFIX . 'cafe_phone', true ), 'tel' );
		self::input( 'وب‌سایت یا صفحه اجتماعی', 'matin_cafe_website', (string) get_post_meta( $post->ID, self::META_PREFIX . 'cafe_website', true ), 'url' );
		self::input( 'ساعت کاری', 'matin_opening_hours', (string) get_post_meta( $post->ID, self::META_PREFIX . 'opening_hours', true ) );
		self::input( 'محدوده قیمت (لیر)', 'matin_price_range', (string) get_post_meta( $post->ID, self::META_PREFIX . 'price_range', true ), 'text' );
		self::input( 'ظرفیت کلی کافه (نفر)', 'matin_cafe_capacity', (string) get_post_meta( $post->ID, self::META_PREFIX . 'cafe_capacity', true ), 'number' );
	}
	public static function event_box( WP_Post $post ) : void {
		wp_nonce_field( 'matin_save_meta', 'matin_meta_nonce' );
		$cafes = get_posts( [ 'post_type' => 'matin_cafe', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$current = (int) get_post_meta( $post->ID, self::META_PREFIX . 'cafe_id', true );
		echo '<p><label for="matin_cafe_id"><strong>' . esc_html__( 'کافه', 'matin-ai' ) . '</strong></label><br><select class="widefat" name="matin_cafe_id" id="matin_cafe_id"><option value="">' . esc_html__( 'انتخاب کافه', 'matin-ai' ) . '</option>';
		foreach ( $cafes as $cafe ) { printf( '<option value="%d" %s>%s</option>', (int) $cafe->ID, selected( $current, $cafe->ID, false ), esc_html( $cafe->post_title ) ); }
		echo '</select></p>';
		self::input( __( 'زمان شروع (منطقه زمانی سایت)', 'matin-ai' ), 'matin_starts_at', (string) get_post_meta( $post->ID, self::META_PREFIX . 'starts_at', true ), 'datetime-local' );
		self::input( __( 'زمان پایان (منطقه زمانی سایت)', 'matin-ai' ), 'matin_ends_at', (string) get_post_meta( $post->ID, self::META_PREFIX . 'ends_at', true ), 'datetime-local' );
		self::input( __( 'ظرفیت (نفر)', 'matin-ai' ), 'matin_capacity', (string) get_post_meta( $post->ID, self::META_PREFIX . 'capacity', true ), 'number' );
		self::input( 'نام خواننده / هنرمند', 'matin_performer', (string) get_post_meta( $post->ID, self::META_PREFIX . 'performer', true ) );
		self::input( 'تیم‌ها / مسابقه', 'matin_match_teams', (string) get_post_meta( $post->ID, self::META_PREFIX . 'match_teams', true ) );
		self::input( 'قیمت ورودی (لیر؛ صفر یعنی رایگان)', 'matin_event_price', (string) get_post_meta( $post->ID, self::META_PREFIX . 'event_price', true ), 'number' );
		self::input( 'حداقل نفرات هر رزرو', 'matin_min_guests', (string) get_post_meta( $post->ID, self::META_PREFIX . 'min_guests', true ) ?: '1', 'number' );
		self::input( 'حداکثر نفرات هر رزرو', 'matin_max_guests', (string) get_post_meta( $post->ID, self::META_PREFIX . 'max_guests', true ) ?: '20', 'number' );
		self::input( 'روش انتخاب میز (خودکار / انتخاب توسط کاربر)', 'matin_table_mode', (string) get_post_meta( $post->ID, self::META_PREFIX . 'table_mode', true ) ?: 'both' );
		self::input( 'وضعیت برنامه (فعال / لغو شده)', 'matin_event_state', (string) get_post_meta( $post->ID, self::META_PREFIX . 'event_state', true ) ?: 'active' );
		self::input( 'اطلاعات تکمیلی بلیت', 'matin_ticket_instructions', (string) get_post_meta( $post->ID, self::META_PREFIX . 'ticket_instructions', true ) );
	}
	public static function reservation_box( WP_Post $post ) : void {
		wp_nonce_field( 'matin_save_meta', 'matin_meta_nonce' );
		$keys = [ 'name' => 'نام و نام خانوادگی', 'email' => 'ایمیل', 'phone' => 'شماره موبایل', 'guests' => 'تعداد نفرات', 'ticket_code' => 'کد بلیت', 'user_id' => 'شناسه کاربر', 'commission_amount' => 'کارمزد ثبت‌شده (لیر)' ];
		foreach ( $keys as $key => $label ) { self::input( $label, 'matin_' . $key, (string) get_post_meta( $post->ID, self::META_PREFIX . $key, true ), 'guests' === $key ? 'number' : 'text' ); }
		$status = (string) get_post_meta( $post->ID, self::META_PREFIX . 'status', true );
		echo '<p><label for="matin_status"><strong>' . esc_html__( 'وضعیت', 'matin-ai' ) . '</strong></label><br><select name="matin_status" id="matin_status">';
		foreach ( [ 'pending' => __( 'در انتظار بررسی', 'matin-ai' ), 'confirmed' => __( 'تأیید شده', 'matin-ai' ), 'rejected' => __( 'رد شده', 'matin-ai' ), 'cancelled' => __( 'لغو شده', 'matin-ai' ) ] as $value => $label ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $status, $value, false ), esc_html( $label ) ); }
		echo '</select></p>';
		$history = get_post_meta( $post->ID, self::META_PREFIX . 'history', true );
		if ( is_array( $history ) && $history ) {
			echo '<p><strong>' . esc_html__( 'تاریخچه وضعیت', 'matin-ai' ) . '</strong></p><ul class="ul-disc">';
			foreach ( array_slice( array_reverse( $history ), 0, 20 ) as $row ) {
				printf( '<li>%s — %s → %s (%s)</li>', esc_html( (string) ( $row['at'] ?? '' ) ), esc_html( (string) ( $row['from'] ?? '' ) ), esc_html( (string) ( $row['to'] ?? '' ) ), esc_html( (string) ( $row['by'] ?? '' ) ) );
			}
			echo '</ul>';
		}
	}

	public static function save_meta( int $post_id, WP_Post $post ) : void {
		if ( ! in_array( $post->post_type, [ 'matin_cafe', 'matin_event', 'matin_reservation' ], true ) || wp_is_post_revision( $post_id ) || ! isset( $_POST['matin_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['matin_meta_nonce'] ) ), 'matin_save_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$old_status = (string) get_post_meta( $post_id, self::META_PREFIX . 'status', true );
		$fields = [ 'location' => 'sanitize_text_field', 'address' => 'sanitize_textarea_field', 'map_url' => 'esc_url_raw', 'lat' => 'self::coord', 'lng' => 'self::coord', 'cafe_phone' => 'sanitize_text_field', 'cafe_website' => 'esc_url_raw', 'opening_hours' => 'sanitize_text_field', 'price_range' => 'sanitize_text_field', 'cafe_capacity' => 'absint', 'cafe_id' => 'absint', 'starts_at' => 'self::datetime', 'ends_at' => 'self::datetime', 'capacity' => 'absint', 'performer' => 'sanitize_text_field', 'match_teams' => 'sanitize_text_field', 'event_price' => 'absint', 'min_guests' => 'absint', 'max_guests' => 'absint', 'table_mode' => 'sanitize_key', 'event_state' => 'sanitize_key', 'ticket_instructions' => 'sanitize_textarea_field', 'name' => 'sanitize_text_field', 'email' => 'sanitize_email', 'phone' => 'sanitize_text_field', 'guests' => 'absint', 'ticket_code' => 'sanitize_text_field', 'user_id' => 'absint', 'commission_amount' => 'absint', 'status' => 'sanitize_key' ];
		foreach ( $fields as $field => $sanitizer ) {
			$key = 'matin_' . $field;
			if ( ! isset( $_POST[ $key ] ) ) { continue; }
			$raw   = wp_unslash( $_POST[ $key ] );
			$value = str_starts_with( $sanitizer, 'self::' ) ? call_user_func( [ __CLASS__, substr( $sanitizer, 6 ) ], $raw ) : call_user_func( $sanitizer, $raw );
			if ( 'status' === $field && ! in_array( $value, Matin_AI_Rules::STATUSES, true ) ) { $value = $old_status ?: 'pending'; }
			if ( 'table_mode' === $field && ! in_array( $value, [ 'automatic', 'customer', 'both' ], true ) ) { $value = 'both'; }
			if ( 'event_state' === $field && ! in_array( $value, [ 'active', 'cancelled' ], true ) ) { $value = 'active'; }
			if ( 'map_url' === $field && '' !== $value && 0 !== strpos( $value, 'https://' ) ) { continue; }
			update_post_meta( $post_id, self::META_PREFIX . $field, $value );
		}

		if ( 'matin_reservation' !== $post->post_type ) { return; }
		$new_status = (string) get_post_meta( $post_id, self::META_PREFIX . 'status', true );
		if ( $old_status === $new_status || '' === $old_status ) { return; }
		if ( ! Matin_AI_Rules::can_transition( $old_status, $new_status ) ) {
			update_post_meta( $post_id, self::META_PREFIX . 'status', $old_status );
			set_transient( 'matin_admin_notice_' . get_current_user_id(), 'تغییر وضعیت از «' . Matin_AI_Rules::status_label( $old_status ) . '» به «' . Matin_AI_Rules::status_label( $new_status ) . '» مجاز نیست.', 60 );
			return;
		}
		self::record_status( $post_id, $old_status, $new_status, 'مدیر' );
		if ( 'confirmed' === $new_status ) {
			Matin_AI_Messaging::notify_reservation( $post_id, 'confirmed' );
		} elseif ( 'cancelled' === $new_status ) {
			Matin_AI_Messaging::notify_reservation( $post_id, 'cancelled' );
		}
		$email = sanitize_email( (string) get_post_meta( $post_id, self::META_PREFIX . 'email', true ) );
		if ( is_email( $email ) && in_array( $new_status, [ 'confirmed', 'rejected', 'cancelled' ], true ) ) {
			$labels = [ 'confirmed' => 'تأیید شده', 'rejected' => 'رد شده', 'cancelled' => 'لغو شده' ];
			wp_mail( $email, 'به‌روزرسانی رزرو Matin AI', sprintf( "وضعیت رزرو شما برای «%s» تغییر کرد: %s\nکد رزرو: %s\nبرای مشاهده جزئیات به حساب کاربری خود در سایت مراجعه کنید.", get_the_title( (int) get_post_meta( $post_id, self::META_PREFIX . 'event_id', true ) ), $labels[ $new_status ], (string) get_post_meta( $post_id, self::META_PREFIX . 'ticket_code', true ) ) );
		}
	}

	/** Appends a status change to the reservation's history (used by admin and cancellation flows). */
	public static function record_status( int $reservation_id, string $from, string $to, string $by ) : void {
		$history   = get_post_meta( $reservation_id, self::META_PREFIX . 'history', true );
		$history   = is_array( $history ) ? $history : [];
		$history[] = [ 'at' => current_time( 'mysql' ), 'from' => $from, 'to' => $to, 'by' => $by ];
		update_post_meta( $reservation_id, self::META_PREFIX . 'history', array_slice( $history, -100 ) );
		Matin_AI_Logger::log( 'info', 'reservation', 'تغییر وضعیت رزرو', [ 'from' => $from, 'to' => $to, 'by' => $by ], $reservation_id );
	}

	public static function coord( mixed $value ) : string {
		$number = is_numeric( $value ) ? (float) $value : null;
		return null === $number ? '' : (string) round( $number, 6 );
	}

	/** datetime-local values (2026-10-19T20:00) are stored in the MySQL-like form (2026-10-19 20:00). */
	public static function datetime( mixed $value ) : string {
		return trim( str_replace( 'T', ' ', sanitize_text_field( (string) $value ) ) );
	}

	public static function admin_notice() : void {
		$message = get_transient( 'matin_admin_notice_' . get_current_user_id() );
		if ( ! $message ) { return; }
		delete_transient( 'matin_admin_notice_' . get_current_user_id() );
		echo '<div class="notice notice-warning"><p>' . esc_html( (string) $message ) . '</p></div>';
	}

	public static function event_columns( array $columns ) : array { $columns['matin_cafe'] = 'کافه'; $columns['matin_starts'] = 'زمان شروع'; $columns['matin_capacity'] = 'ظرفیت'; return $columns; }
	public static function event_column_content( string $column, int $post_id ) : void { if ( 'matin_cafe' === $column ) { echo esc_html( get_the_title( (int) get_post_meta( $post_id, '_matin_cafe_id', true ) ) ?: '—' ); } elseif ( 'matin_starts' === $column ) { echo esc_html( (string) get_post_meta( $post_id, '_matin_starts_at', true ) ?: '—' ); } elseif ( 'matin_capacity' === $column ) { echo esc_html( (string) get_post_meta( $post_id, '_matin_capacity', true ) ?: '—' ); } }
	public static function reservation_columns( array $columns ) : array { return [ 'cb' => $columns['cb'], 'title' => __( 'رزرو', 'matin-ai' ), 'matin_event' => __( 'رویداد', 'matin-ai' ), 'matin_guest' => 'مهمان', 'matin_status' => __( 'وضعیت', 'matin-ai' ), 'date' => $columns['date'] ]; }
	public static function reservation_column_content( string $column, int $post_id ) : void { if ( 'matin_status' === $column ) { $status = (string) get_post_meta( $post_id, self::META_PREFIX . 'status', true ); echo esc_html( Matin_AI_Rules::status_label( $status ) ); } if ( 'matin_guest' === $column ) { echo esc_html( (string) get_post_meta( $post_id, self::META_PREFIX . 'name', true ) . ' / ' . (string) get_post_meta( $post_id, self::META_PREFIX . 'phone', true ) ); } if ( 'matin_event' === $column ) { $event = get_post( (int) get_post_meta( $post_id, self::META_PREFIX . 'event_id', true ) ); echo $event ? esc_html( $event->post_title ) : '—'; } }
}
