<?php
defined( 'ABSPATH' ) || exit;
get_header();
$today = current_datetime()->format( 'Y-m-d' );
$tomorrow = current_datetime()->modify( '+1 day' )->format( 'Y-m-d' );
$day = sanitize_key( (string) ( $_GET['day'] ?? '' ) );
if ( ! in_array( $day, [ 'today', 'tomorrow' ], true ) ) { $day = ''; }
$q = sanitize_text_field( (string) ( $_GET['q'] ?? '' ) );
$type = sanitize_title( (string) ( $_GET['type'] ?? '' ) );
$now = current_datetime()->format( 'Y-m-d\\TH:i' );
$meta_query = [ 'relation' => 'AND', [ 'key' => '_matin_starts_at', 'value' => $now, 'compare' => '>=', 'type' => 'CHAR' ] ];
if ( 'today' === $day ) { $meta_query[] = [ 'key' => '_matin_starts_at', 'value' => [ $today . 'T00:00', $today . 'T23:59' ], 'compare' => 'BETWEEN', 'type' => 'CHAR' ]; }
if ( 'tomorrow' === $day ) { $meta_query[] = [ 'key' => '_matin_starts_at', 'value' => [ $tomorrow . 'T00:00', $tomorrow . 'T23:59' ], 'compare' => 'BETWEEN', 'type' => 'CHAR' ]; }
$tax_query = [];
if ( $type ) { $tax_query[] = [ 'taxonomy' => 'matin_event_type', 'field' => 'slug', 'terms' => $type ]; }
$event_search_filter = static function ( string $search, WP_Query $query ) use ( $q ) : string {
	if ( ! $query->get( 'matin_event_archive_filter' ) || '' === $q ) { return $search; }
	global $wpdb;
	$like = '%' . $wpdb->esc_like( $q ) . '%';
	return $wpdb->prepare( " AND {$wpdb->posts}.post_password = '' AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS matin_event_pm WHERE matin_event_pm.post_id = {$wpdb->posts}.ID AND matin_event_pm.meta_key IN ('_matin_performer','_matin_match_teams') AND matin_event_pm.meta_value LIKE %s) OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS matin_cafe_link JOIN {$wpdb->posts} AS matin_cafe_post ON matin_cafe_post.ID = matin_cafe_link.meta_value LEFT JOIN {$wpdb->postmeta} AS matin_cafe_location ON matin_cafe_location.post_id = matin_cafe_post.ID AND matin_cafe_location.meta_key = '_matin_location' WHERE matin_cafe_link.post_id = {$wpdb->posts}.ID AND matin_cafe_link.meta_key = '_matin_cafe_id' AND (matin_cafe_post.post_title LIKE %s OR matin_cafe_location.meta_value LIKE %s)) )", $like, $like, $like, $like, $like );
};
add_filter( 'posts_search', $event_search_filter, 10, 2 );
$events = new WP_Query( [ 'post_type' => 'matin_event', 'post_status' => 'publish', 'posts_per_page' => 12, 'paged' => max( 1, get_query_var( 'paged' ) ? absint( get_query_var( 'paged' ) ) : absint( $_GET['paged'] ?? 1 ) ), 'orderby' => 'meta_value', 'meta_key' => '_matin_starts_at', 'order' => 'ASC', 'meta_query' => $meta_query, 'tax_query' => $tax_query, 's' => $q, 'matin_event_archive_filter' => true ] );
remove_filter( 'posts_search', $event_search_filter, 10 );
$terms = get_terms( [ 'taxonomy' => 'matin_event_type', 'hide_empty' => true ] );
?>
<main id="main" class="matin-catalog matin-events-page" dir="rtl">
	<header class="matin-catalog-head">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="matin-back">← بازگشت به گفت‌وگو</a>
		<p class="matin-kicker">MATIN AI · DISCOVER</p>
		<h1>رویدادهای کافه‌ها</h1>
		<p>موسیقی زنده، پخش مسابقات فوتبال و سایر برنامه‌های ثبت‌شده؛ رویدادها بر اساس زمان شروع مرتب شده‌اند.</p>
		<form class="matin-catalog-filter" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'matin_event' ) ); ?>">
			<label>جست‌وجوی برنامه<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="نام برنامه، خواننده، تیم یا کافه"></label>
			<label>زمان برنامه<select name="day"><option value="">همهٔ زمان‌های آینده</option><option value="today" <?php selected( $day, 'today' ); ?>>امروز</option><option value="tomorrow" <?php selected( $day, 'tomorrow' ); ?>>فردا</option></select></label>
			<label>نوع برنامه<select name="type"><option value="">همهٔ دسته‌ها</option><?php if ( ! is_wp_error( $terms ) ) : foreach ( $terms as $term ) : ?><option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $type, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; endif; ?></select></label>
			<button type="submit">اعمال فیلتر</button>
		</form>
		<p class="matin-catalog-count"><?php echo esc_html( number_format_i18n( (int) $events->found_posts ) ); ?> رویداد منطبق پیدا شد</p>
	</header>
	<section class="matin-catalog-grid" aria-label="فهرست رویدادها">
	<?php if ( $events->have_posts() ) : while ( $events->have_posts() ) : $events->the_post(); $event_id = get_the_ID(); $cafe_id = (int) get_post_meta( $event_id, '_matin_cafe_id', true ); $starts = (string) get_post_meta( $event_id, '_matin_starts_at', true ); $types = wp_get_post_terms( $event_id, 'matin_event_type', [ 'fields' => 'names' ] ); $cafe_name = get_the_title( $cafe_id ); $starts_ts = 0; if ( $starts ) { try { $starts_ts = ( new DateTimeImmutable( $starts, wp_timezone() ) )->getTimestamp(); } catch ( Exception $e ) { $starts_ts = 0; } } ?>
		<article class="matin-catalog-card">
			<a class="matin-catalog-image" href="<?php the_permalink(); ?>"><?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'large', [ 'loading' => 'lazy' ] ); } else { echo '<span class="matin-event-placeholder"><strong>MATIN AI</strong><small>رویداد کافه</small></span>'; } ?></a>
			<div class="matin-catalog-card-body">
				<p class="matin-catalog-meta"><?php echo esc_html( implode( ' · ', is_array( $types ) ? $types : [] ) ); ?></p>
				<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<p><?php echo esc_html( wp_trim_words( get_the_excerpt() ?: get_the_content(), 24 ) ); ?></p>
				<dl><dt>کافه</dt><dd><?php echo esc_html( $cafe_name ?: 'کافه نامشخص' ); ?></dd><dt>زمان</dt><dd><?php echo esc_html( $starts_ts ? wp_date( 'l j F، H:i', $starts_ts, wp_timezone() ) : 'زمان ثبت نشده' ); ?></dd><dt>قیمت ورودی</dt><dd><?php $price = get_post_meta( $event_id, '_matin_event_price', true ); echo esc_html( '' === $price ? 'اعلام نشده' : ( 0 === (int) $price ? 'رایگان' : number_format_i18n( (int) $price ) . ' لیر' ) ); ?></dd></dl>
				<a class="matin-catalog-cta" href="<?php the_permalink(); ?>">مشاهده جزئیات و رزرو <span aria-hidden="true">←</span></a>
			</div>
		</article>
	<?php endwhile; wp_reset_postdata(); else : ?>
		<div class="matin-catalog-empty"><h2><?php echo esc_html( $day ? ( 'today' === $day ? 'رویداد آینده‌ای برای امروز پیدا نشد' : 'رویداد آینده‌ای برای فردا پیدا نشد' ) : 'رویدادی مطابق فیلترها پیدا نشد' ); ?></h2><p>فقط برنامه‌هایی نمایش داده می‌شوند که در ماتین ثبت و منتشر شده‌اند. فیلترها را تغییر دهید یا از دستیار ماتین سؤال کنید.</p><a class="matin-catalog-cta" href="<?php echo esc_url( home_url( '/' ) ); ?>">پرسیدن از دستیار ماتین</a></div>
	<?php endif; ?>
	</section>
	<?php if ( $events->max_num_pages > 1 ) : ?><nav class="matin-pagination" aria-label="صفحه‌بندی رویدادها"><?php echo wp_kses_post( paginate_links( [ 'total' => $events->max_num_pages, 'current' => max( 1, absint( $_GET['paged'] ?? get_query_var( 'paged' ) ) ), 'format' => '?paged=%#%', 'add_args' => array_filter( [ 'q' => $q, 'day' => $day, 'type' => $type ] ), 'prev_text' => '→ قبلی', 'next_text' => 'بعدی ←' ] ) ); ?></nav><?php endif; ?>
</main>
<?php get_footer();
