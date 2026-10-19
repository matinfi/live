<?php get_header(); ?>
<main id="main" class="shell page-content state-page" aria-labelledby="search-title">
	<p class="eyebrow">جست‌وجو</p>
	<h1 id="search-title">نتایج برای «<?php echo esc_html( get_search_query() ); ?>»</h1>
	<?php if ( have_posts() ) : ?>
		<ul class="search-results">
			<?php while ( have_posts() ) : the_post(); ?>
				<li><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></li>
			<?php endwhile; ?>
		</ul>
	<?php else : ?>
		<div class="empty-chat-state" role="status">
			<p>نتیجه‌ای پیدا نشد. می‌توانید از گفت‌وگو با ماتین بخواهید کافه یا برنامهٔ مدنظرتان را پیدا کند؛ فقط برنامه‌های ثبت‌شده در همین سایت نمایش داده می‌شوند.</p>
			<p><a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>">رفتن به گفت‌وگو</a></p>
		</div>
	<?php endif; ?>
</main>
<?php get_footer();
