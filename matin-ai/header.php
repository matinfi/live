<?php defined( 'ABSPATH' ) || exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><a class="skip-link" href="#main">پرش به محتوا</a><?php wp_body_open(); ?>
<?php if ( is_front_page() ) : ?>
<header class="site-header"><div class="shell header-inner"><a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="ماتین، صفحهٔ اصلی"><span>ماتین</span><i>AI</i></a><nav aria-label="ناوبری اصلی"><a href="#discover">کشف کافه‌ها</a><a href="#how">چطور کار می‌کند</a></nav></div></header>
<?php else : ?>
<header class="site-header page-header"><div class="shell header-inner">
	<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="ماتین، صفحهٔ اصلی"><span>ماتین</span><i>AI</i></a>
	<nav aria-label="ناوبری اصلی"><ul class="page-nav">
		<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">گفت‌وگو</a></li>
		<li><a href="<?php echo esc_url( home_url( '/cafes/' ) ); ?>">کافه‌ها</a></li>
		<li><a href="<?php echo esc_url( home_url( '/events/' ) ); ?>">رویدادها</a></li>
		<li><a href="<?php echo esc_url( home_url( '/my-account/' ) ); ?>">حساب من</a></li>
		<li><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>">درباره ما</a></li>
		<li><a href="<?php echo esc_url( home_url( '/complaints/' ) ); ?>">شکایت و بازخورد</a></li>
	</ul></nav>
</div></header>
<?php endif; ?>
