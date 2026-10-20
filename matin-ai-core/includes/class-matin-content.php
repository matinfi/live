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

	private static function input( string $label, string $name, string $value = '', string $type = 'text' ) : void {
		printf( '<p><label for="%1$s"><strong>%2$s</strong></label><br><input class="widefat" id="%1$s" name="%1$s" type="%3$s" value="%4$s"></p>', esc_attr( $name ), esc_html( $label ), esc_attr( $type ), esc_attr( $value ) );
	}
	public static function turkish_cities() : array { return [ 'Adana','Adıyaman','Afyonkarahisar','Ağrı','Amasya','Ankara','Antalya','Artvin','Aydın','Balıkesir','Bilecik','Bingöl','Bitlis','Bolu','Burdur','Bursa','Çanakkale','Çankırı','Çorum','Denizli','Diyarbakır','Edirne','Elazığ','Erzincan','Erzurum','Eskişehir','Gaziantep','Giresun','Gümüşhane','Hakkâri','Hatay','Isparta','Mersin','İstanbul','İzmir','Kars','Kastamonu','Kayseri','Kırklareli','Kırşehir','Kocaeli','Konya','Kütahya','Malatya','Manisa','Kahramanmaraş','Mardin','Muğla','Muş','Nevşehir','Niğde','Ordu','Rize','Sakarya','Samsun','Siirt','Sinop','Sivas','Tekirdağ','Tokat','Trabzon','Tunceli','Şanlıurfa','Uşak','Van','Yozgat','Zonguldak','Aksaray','Bayburt','Karaman','Kırıkkale','Batman','Şırnak','Bartın','Ardahan','Iğdır','Yalova','Karabük','Kilis','Osmaniye','Düzce' ]; }
	public static function cafe_box( WP_Post $post ) : void {
		wp_nonce_field( 'matin_save_meta', 'matin_meta_nonce' );
		$country = (string) get_post_meta( $post->ID, self::META_PREFIX . 'country', true ); if ( '' === $country ) { $country = 'Türkiye'; }
		echo '<p><label for="matin_country"><strong>کشور</strong></label><br><select class="widefat" id="matin_country" name="matin_country"><option value="Türkiye" ' . selected( $country, 'Türkiye', false ) . '>ترکیه (Türkiye)</option><option value="Other" ' . selected( $country, 'Other', false ) . '>کشور دیگر</option></select></p>';
		$city = (string) get_post_meta( $post->ID, self::META_PREFIX . 'city', true ); echo '<p><label for="matin_city"><strong>شهر</strong></label><br><select class="widefat" id="matin_city" name="matin_city"><option value="">انتخاب شهر ترکیه</option>'; foreach ( self::turkish_cities() as $city_name ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $city_name ), selected( $city, $city_name, false ), esc_html( $city_name ) ); } echo '<option value="__custom__" ' . selected( ! in_array( $city, self::turkish_cities(), true ) && '' !== $city, true, false ) . '>شهر دیگر / وارد کردن دستی</option></select></p>';
		self::input( 'نام شهر (در صورت انتخاب شهر دیگر)', 'matin_location', (string) get_post_meta( $post->ID, self::META_PREFIX . 'location', true ) );
		if ( '' === $city ) { $city = (string) get_post_meta( $post->ID, self::META_PREFIX . 'location', true ); }
		self::input( __( 'نشانی', 'matin-ai' ), 'matin_address', (string) get_post_meta( $post->ID, self::META_PREFIX . 'address', true ) );
		self::input( __( 'پیوند نقشه (فقط HTTPS)', 'matin-ai' ), 'matin_map_url', (string) get_post_meta( $post->ID, self::META_PREFIX . 'map_url', true ), 'url' );
		self::input( 'شماره تماس کافه', 'matin_cafe_phone', (string) get_post_meta( $post->ID, self::META_PREFIX . 'cafe_phone', true ), 'tel' );
		self::input( 'شماره واتساپ کافه (با کد کشور، فقط رقم)', 'matin_cafe_whatsapp', (string) get_post_meta( $post->ID, self::META_PREFIX . 'cafe_whatsapp', true ), 'tel' );
		self::input( 'وب‌سایت یا صفحه اجتماعی', 'matin_cafe_website', (string) get_post_meta( $post->ID, self::META_PREFIX . 'cafe_website', true ), 'url' );
		self::input( 'ساعت کاری', 'matin_opening_hours', (string) get_post_meta( $post->ID, self::META_PREFIX . 'opening_hours', true ) );
		self::input( 'محدوده قیمت (لیر)', 'matin_price_range', (string) get_post_meta( $post->ID, self::META_PREFIX . 'price_range', true ), 'text' );
		self::input( 'ظرفیت کلی کافه (نفر)', 'matin_cafe_capacity', (string) get_post_meta( $post->ID, self::META_PREFIX . 'cafe_capacity', true ), 'number' );
	}
	public static function event_box( WP_Post $post ) : void {
		wp_nonce_field( 'matin_save_meta', 'matin_meta_nonce' );
		$cafes = get_posts( [ 'post_type' => 'matin_cafe', 'post_status' => [ 'publish', 'draft', 'pending' ], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$current = (int) get_post_meta( $post->ID, self::META_PREFIX . 'cafe_id', true );
		$kind = (string) get_post_meta( $post->ID, self::META_PREFIX . 'event_kind', true );
		if ( ! in_array( $kind, [ 'live_music', 'football' ], true ) ) {
			$kind = get_post_meta( $post->ID, self::META_PREFIX . 'match_teams', true ) ? 'football' : 'live_music';
		}
		echo '<div class="matin-event-admin-fields"><p><label for="matin_event_kind"><strong>نوع برنامه</strong></label><br><select class="widefat" name="matin_event_kind" id="matin_event_kind"><option value="live_music" ' . selected( $kind, 'live_music', false ) . '>موسیقی زنده</option><option value="football" ' . selected( $kind, 'football', false ) . '>پخش مسابقه فوتبال</option></select><span class="description">با تغییر نوع برنامه، فقط فیلدهای مرتبط نمایش داده می‌شوند.</span></p>';
		echo '<p><label for="matin_cafe_id"><strong>' . esc_html__( 'کافه', 'matin-ai' ) . '</strong></label><br><select class="widefat" name="matin_cafe_id" id="matin_cafe_id"><option value="">' . esc_html__( 'انتخاب کافه', 'matin-ai' ) . '</option>';
		foreach ( $cafes as $cafe ) { printf( '<option value="%d" %s>%s</option>', (int) $cafe->ID, selected( $current, $cafe->ID, false ), esc_html( $cafe->post_title ) ); }
		echo '</select></p>';
		self::input( __( 'زمان شروع (منطقه زمانی سایت وردپرس)', 'matin-ai' ), 'matin_starts_at', (string) get_post_meta( $post->ID, self::META_PREFIX . 'starts_at', true ), 'datetime-local' );
		self::input( __( 'زمان پایان (منطقه زمانی سایت وردپرس)', 'matin-ai' ), 'matin_ends_at', (string) get_post_meta( $post->ID, self::META_PREFIX . 'ends_at', true ), 'datetime-local' );
		self::input( 'ظرفیت قابل رزرو (نفر)', 'matin_bookable_capacity', (string) get_post_meta( $post->ID, self::META_PREFIX . 'bookable_capacity', true ), 'number' );
		self::input( 'ظرفیت کل (نفر؛ برای رویدادهای قدیمی و داده‌های قبلی)', 'matin_capacity', (string) get_post_meta( $post->ID, self::META_PREFIX . 'capacity', true ), 'number' );
		echo '<div class="matin-event-kind-field matin-kind-music">';
		self::input( 'نام خواننده / هنرمند', 'matin_performer', (string) get_post_meta( $post->ID, self::META_PREFIX . 'performer', true ) );
		echo '</div><div class="matin-event-kind-field matin-kind-football">';
		self::input( 'تیم میزبان و مهمان', 'matin_match_teams', (string) get_post_meta( $post->ID, self::META_PREFIX . 'match_teams', true ) );
		self::input( 'نام لیگ / رقابت (اختیاری)', 'matin_competition', (string) get_post_meta( $post->ID, self::META_PREFIX . 'competition', true ) );
		echo '</div>';
		self::input( 'قیمت ورودی (لیر؛ صفر یعنی رایگان)', 'matin_event_price', (string) get_post_meta( $post->ID, self::META_PREFIX . 'event_price', true ), 'number' );
		self::input( 'حداقل نفرات هر رزرو', 'matin_min_guests', (string) get_post_meta( $post->ID, self::META_PREFIX . 'min_guests', true ) ?: '1', 'number' );
		self::input( 'حداکثر نفرات هر رزرو', 'matin_max_guests', (string) get_post_meta( $post->ID, self::META_PREFIX . 'max_guests', true ) ?: '20', 'number' );
		$state = (string) get_post_meta( $post->ID, self::META_PREFIX . 'event_state', true ) ?: 'active';
		echo '<p><label for="matin_event_state"><strong>وضعیت برنامه</strong></label><br><select class="widefat" name="matin_event_state" id="matin_event_state"><option value="active" ' . selected( $state, 'active', false ) . '>فعال</option><option value="cancelled" ' . selected( $state, 'cancelled', false ) . '>لغو شده</option></select></p>';
		self::input( 'اطلاعات تکمیلی بلیت', 'matin_ticket_instructions', (string) get_post_meta( $post->ID, self::META_PREFIX . 'ticket_instructions', true ) );
		echo '</div>';
		?>
		<script>
		(function(){
			const kind = document.getElementById('matin_event_kind');
			if (!kind) return;
			const update = function(){
				document.querySelectorAll('.matin-event-kind-field').forEach(function(el){ el.style.display='none'; });
				const target = document.querySelector(kind.value === 'football' ? '.matin-kind-football' : '.matin-kind-music');
				if (target) target.style.display='block';
			};
			kind.addEventListener('change', update); update();
		})();
		</script>
		<?php
	}

	public static function reservation_box( WP_Post $post ) : void {
		wp_nonce_field( 'matin_save_meta', 'matin_meta_nonce' );
		$keys = [ 'name' => 'نام و نام خانوادگی', 'email' => 'ایمیل', 'phone' => 'شماره موبایل', 'guests' => 'تعداد نفرات', 'ticket_code' => 'کد بلیت', 'user_id' => 'شناسه کاربر', 'commission_amount' => 'کارمزد ثبت‌شده (لیر)' ];
		foreach ( $keys as $key => $label ) { self::input( $label, 'matin_' . $key, (string) get_post_meta( $post->ID, self::META_PREFIX . $key, true ), 'guests' === $key ? 'number' : 'text' ); }
		$status = (string) get_post_meta( $post->ID, self::META_PREFIX . 'status', true );
		echo '<p><label for="matin_status"><strong>' . esc_html__( 'وضعیت', 'matin-ai' ) . '</strong></label><br><select name="matin_status" id="matin_status">';
		foreach ( [ 'pending' => __( 'در انتظار بررسی', 'matin-ai' ), 'confirmed' => __( 'تأیید شده', 'matin-ai' ), 'rejected' => __( 'رد شده', 'matin-ai' ), 'cancelled' => __( 'لغو شده', 'matin-ai' ) ] as $value => $label ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $status, $value, false ), esc_html( $label ) ); }
		echo '</select></p>';
	}
	public static function save_meta( int $post_id, WP_Post $post ) : void {
		$old_status = (string) get_post_meta( $post_id, self::META_PREFIX . 'status', true );
		if ( ! in_array( $post->post_type, [ 'matin_cafe', 'matin_event', 'matin_reservation' ], true ) || wp_is_post_revision( $post_id ) || ! isset( $_POST['matin_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['matin_meta_nonce'] ) ), 'matin_save_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$fields = [ 'location' => 'sanitize_text_field', 'country' => 'sanitize_text_field', 'city' => 'sanitize_text_field', 'address' => 'sanitize_textarea_field', 'map_url' => 'esc_url_raw', 'cafe_phone' => 'sanitize_text_field', 'cafe_whatsapp' => 'sanitize_text_field', 'cafe_website' => 'esc_url_raw', 'opening_hours' => 'sanitize_text_field', 'price_range' => 'sanitize_text_field', 'cafe_capacity' => 'absint', 'cafe_id' => 'absint', 'starts_at' => 'sanitize_text_field', 'ends_at' => 'sanitize_text_field', 'capacity' => 'absint', 'bookable_capacity' => 'absint', 'event_kind' => 'sanitize_key', 'performer' => 'sanitize_text_field', 'match_teams' => 'sanitize_text_field', 'competition' => 'sanitize_text_field', 'event_price' => 'absint', 'min_guests' => 'absint', 'max_guests' => 'absint', 'table_mode' => 'sanitize_key', 'event_state' => 'sanitize_key', 'ticket_instructions' => 'sanitize_textarea_field', 'name' => 'sanitize_text_field', 'email' => 'sanitize_email', 'phone' => 'sanitize_text_field', 'guests' => 'absint', 'ticket_code' => 'sanitize_text_field', 'user_id' => 'absint', 'commission_amount' => 'absint', 'status' => 'sanitize_key' ];
		foreach ( $fields as $field => $sanitizer ) { $key = 'matin_' . $field; if ( isset( $_POST[ $key ] ) ) { $value = call_user_func( $sanitizer, wp_unslash( $_POST[ $key ] ) ); if ( 'status' === $field && ! in_array( $value, [ 'pending', 'confirmed', 'rejected', 'cancelled' ], true ) ) { $value = 'pending'; }
			if ( 'table_mode' === $field && ! in_array( $value, [ 'automatic', 'customer', 'both' ], true ) ) { $value = 'both'; }
			if ( 'event_state' === $field && ! in_array( $value, [ 'active', 'cancelled' ], true ) ) { $value = 'active'; } if ( 'event_kind' === $field && ! in_array( $value, [ 'live_music', 'football' ], true ) ) { $value = 'live_music'; } if ( 'cafe_whatsapp' === $field ) { $value = preg_replace( '/\\D+/', '', (string) $value ); } if ( in_array( $field, [ 'map_url', 'cafe_website' ], true ) && '' !== $value && 'https' !== strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ) ) { continue; } if ( 'map_url' !== $field || '' === $value || 'https' === strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ) ) { update_post_meta( $post_id, self::META_PREFIX . $field, $value ); } } }
		if ( 'matin_cafe' === $post->post_type ) { $country = (string) get_post_meta( $post_id, self::META_PREFIX . 'country', true ); if ( '' === $country ) { update_post_meta( $post_id, self::META_PREFIX . 'country', 'Türkiye' ); } $city = (string) get_post_meta( $post_id, self::META_PREFIX . 'city', true ); if ( '' !== $city && '__custom__' !== $city ) { update_post_meta( $post_id, self::META_PREFIX . 'location', $city ); } }
		if ( 'matin_event' === $post->post_type ) {
			$kind = (string) get_post_meta( $post_id, self::META_PREFIX . 'event_kind', true );
			// Prevent stale metadata from making a football match appear in artist searches, or vice versa.
			if ( 'football' === $kind ) { delete_post_meta( $post_id, self::META_PREFIX . 'performer' ); }
			elseif ( 'live_music' === $kind ) { delete_post_meta( $post_id, self::META_PREFIX . 'match_teams' ); delete_post_meta( $post_id, self::META_PREFIX . 'competition' ); }
			if ( in_array( $kind, [ 'live_music', 'football' ], true ) ) {
				$term_name = 'football' === $kind ? 'Football' : 'Live Music';
				$term = term_exists( $term_name, 'matin_event_type' );
				if ( ! $term ) { $term = wp_insert_term( $term_name, 'matin_event_type', [ 'slug' => $kind ] ); }
				if ( ! is_wp_error( $term ) ) { wp_set_object_terms( $post_id, [ (int) ( is_array( $term ) ? $term['term_id'] : $term ) ], 'matin_event_type', false ); }
			}
		}
		$new_status = (string) get_post_meta( $post_id, self::META_PREFIX . 'status', true );
		if ( 'matin_reservation' === $post->post_type && $old_status !== $new_status && class_exists( 'Matin_AI_Operations' ) ) { Matin_AI_Operations::log( 'reservation_status_changed', $post_id, $old_status . '_to_' . $new_status ); }
		if ( 'matin_reservation' === $post->post_type && $old_status !== $new_status && in_array( $new_status, [ 'confirmed', 'rejected', 'cancelled' ], true ) ) { $email = sanitize_email( (string) get_post_meta( $post_id, self::META_PREFIX . 'email', true ) ); if ( is_email( $email ) ) { $event_id = (int) get_post_meta( $post_id, self::META_PREFIX . 'event_id', true ); $labels = [ 'confirmed' => 'تأیید شده', 'rejected' => 'رد شده', 'cancelled' => 'لغو شده' ]; wp_mail( $email, 'به‌روزرسانی رزرو Matin AI', sprintf( "وضعیت رزرو شما برای «%s» تغییر کرد: %s\nکد رزرو: %s\nبرای مشاهده جزئیات به حساب کاربری خود در سایت مراجعه کنید.", get_the_title( $event_id ), $labels[ $new_status ], (string) get_post_meta( $post_id, self::META_PREFIX . 'ticket_code', true ) ) ); } }
	}
	public static function event_columns( array $columns ) : array { $columns['matin_cafe'] = 'کافه'; $columns['matin_starts'] = 'زمان شروع'; $columns['matin_capacity'] = 'ظرفیت'; return $columns; }
	public static function event_column_content( string $column, int $post_id ) : void { if ( 'matin_cafe' === $column ) { echo esc_html( get_the_title( (int) get_post_meta( $post_id, '_matin_cafe_id', true ) ) ?: '—' ); } elseif ( 'matin_starts' === $column ) { echo esc_html( (string) get_post_meta( $post_id, '_matin_starts_at', true ) ?: '—' ); } elseif ( 'matin_capacity' === $column ) { echo esc_html( (string) get_post_meta( $post_id, '_matin_capacity', true ) ?: '—' ); } }
	public static function reservation_columns( array $columns ) : array { return [ 'cb' => $columns['cb'], 'title' => __( 'رزرو', 'matin-ai' ), 'matin_event' => __( 'رویداد', 'matin-ai' ), 'matin_guest' => 'مهمان', 'matin_status' => __( 'وضعیت', 'matin-ai' ), 'date' => $columns['date'] ]; }
	public static function reservation_column_content( string $column, int $post_id ) : void { if ( 'matin_status' === $column ) { $labels = [ 'pending' => 'در انتظار تأیید', 'confirmed' => 'تأییدشده', 'rejected' => 'ردشده', 'cancelled' => 'لغوشده' ]; $status = (string) get_post_meta( $post_id, self::META_PREFIX . 'status', true ); echo esc_html( $labels[ $status ] ?? 'در انتظار تأیید' ); } if ( 'matin_guest' === $column ) { echo esc_html( (string) get_post_meta( $post_id, self::META_PREFIX . 'name', true ) . ' / ' . (string) get_post_meta( $post_id, self::META_PREFIX . 'phone', true ) ); } if ( 'matin_event' === $column ) { $event = get_post( (int) get_post_meta( $post_id, self::META_PREFIX . 'event_id', true ) ); echo $event ? esc_html( $event->post_title ) : '—'; } }
}

