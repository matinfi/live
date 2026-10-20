<?php defined( 'ABSPATH' ) || exit; ?>
<?php if ( is_front_page() ) : ?>
<footer class="site-footer"><div class="shell footer-grid"><p class="brand">ماتین <i>AI</i></p><p>کشف آگاهانهٔ کافه و رویداد، با تأیید نهایی توسط میزبان.</p><a href="#discover">شروع جست‌وجو</a><button type="button" class="install-app" data-install-app hidden>نصب اپلیکیشن</button></div></footer>
<?php else : ?>
<footer class="site-footer page-footer"><div class="shell footer-grid"><p class="brand">ماتین <i>AI</i></p><p>کشف آگاهانهٔ کافه و رویداد، با تأیید نهایی توسط میزبان.</p><p><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>">درباره ما</a> · <a href="<?php echo esc_url( home_url( '/complaints/' ) ); ?>">شکایت و بازخورد</a></p><button type="button" class="install-app" data-install-app hidden>نصب اپلیکیشن</button></div></footer>
<?php endif; ?>
<?php wp_footer(); ?></body></html>
