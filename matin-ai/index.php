<?php defined( 'ABSPATH' ) || exit; get_header(); ?><main id="main" class="shell page-content"><h1><?php bloginfo( 'name' ); ?></h1><?php if ( have_posts() ) { while ( have_posts() ) { the_post(); the_content(); } } ?></main><?php get_footer();

