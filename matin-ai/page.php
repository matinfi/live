<?php get_header(); ?>
<main id="main" class="matin-page" dir="auto">
	<?php while ( have_posts() ) : the_post(); ?>
	<article class="matin-page-panel">
		<h1 class="matin-page-title"><?php the_title(); ?></h1>
		<div class="matin-page-body"><?php the_content(); ?></div>
	</article>
	<?php endwhile; ?>
</main>
<?php get_footer(); ?>
