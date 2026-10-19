<?php defined( 'ABSPATH' ) || exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><a class="skip-link" href="#main">پرش به محتوا</a><?php wp_body_open(); ?>
<?php if ( ! is_front_page() ) : $base = home_url( '/my-account/' ); ?>
<div class="account-bar"><div class="shell"><?php if ( is_user_logged_in() ) : $me = wp_get_current_user(); $name = $me->display_name ?: $me->user_login; ?>
<details><summary><span class="account-initial" aria-hidden="true"><?php echo esc_html( mb_substr( $name, 0, 1, 'UTF-8' ) ); ?></span><span><?php echo esc_html( $name ); ?></span></summary>
<div class="account-panel"><a href="<?php echo esc_url( add_query_arg( [ 'matin_tab' => 'reservations', 'status' => 'pending' ], $base ) ); ?>">⏳ در انتظار تأیید</a><a href="<?php echo esc_url( add_query_arg( [ 'matin_tab' => 'reservations', 'status' => 'confirmed' ], $base ) ); ?>">✅ تأییدشده</a><a href="<?php echo esc_url( add_query_arg( [ 'matin_tab' => 'reservations', 'status' => 'cancelled' ], $base ) ); ?>">🚫 لغوشده</a><a href="<?php echo esc_url( add_query_arg( [ 'matin_tab' => 'reservations', 'status' => 'all' ], $base ) ); ?>">🎟 همه بلیت‌ها</a><a href="<?php echo esc_url( add_query_arg( 'matin_tab', 'settings', $base ) ); ?>">⚙ تنظیمات حساب</a><a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">↩ خروج</a></div></details>
<?php else : ?><a class="sign-in" href="<?php echo esc_url( $base ); ?>">ورود / ساخت حساب</a><?php endif; ?></div></div>
<?php endif; ?>
<header class="site-header"><div class="shell header-inner"><a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="ماتین، صفحهٔ اصلی"><span>ماتین</span><i>AI</i></a><nav aria-label="ناوبری اصلی"><a href="#discover">کشف کافه‌ها</a><a href="#how">چطور کار می‌کند</a></nav></div></header>

