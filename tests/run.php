<?php
/**
 * Unit tests for pure logic (no WordPress, no database).
 * Run from the repository root:  php tests/run.php
 */

define( 'ABSPATH', __DIR__ . '/' ); // Lets the plugin classes load outside WordPress.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MATIN_AI_CORE_DIR', dirname( __DIR__ ) . '/matin-ai-core/' );

$dir = MATIN_AI_CORE_DIR . 'includes/';
foreach ( [ 'class-matin-rules.php', 'class-matin-secrets.php', 'class-matin-logger.php', 'class-matin-tickets.php', 'class-matin-rest.php' ] as $file ) {
	require_once $dir . $file;
}

$failures = 0;
$passes   = 0;
function check( string $name, bool $ok, string $detail = '' ) : void {
	global $failures, $passes;
	if ( $ok ) { $passes++; return; }
	$failures++;
	echo "FAIL: {$name}" . ( $detail ? " ({$detail})" : '' ) . PHP_EOL;
}

$tz = new DateTimeZone( 'Asia/Tehran' );

/* ---------- Matin_AI_Rules ---------- */

$t = Matin_AI_Rules::parse_datetime( '2026-10-19T20:00', $tz );
$u = Matin_AI_Rules::parse_datetime( '2026-10-19 20:00:00', $tz );
check( 'datetime-local and MySQL forms parse to the same timestamp', $t !== null && $t === $u );
check( 'empty datetime is null', Matin_AI_Rules::parse_datetime( '', $tz ) === null );
check( 'garbage datetime is null', Matin_AI_Rules::parse_datetime( 'not a date', $tz ) === null );

$start = Matin_AI_Rules::parse_datetime( '2026-10-19T20:00', $tz );
$six   = Matin_AI_Rules::CANCEL_WINDOW_SECONDS;
check( 'cancel allowed with more than six hours left', Matin_AI_Rules::can_cancel( 'confirmed', $start, $start - $six - 1 ) );
check( 'cancel allowed exactly six hours before start', Matin_AI_Rules::can_cancel( 'pending', $start, $start - $six ) );
check( 'cancel refused one second under six hours', ! Matin_AI_Rules::can_cancel( 'confirmed', $start, $start - $six + 1 ) );
check( 'cancel refused after start', ! Matin_AI_Rules::can_cancel( 'confirmed', $start, $start + 60 ) );
check( 'cancel refused for already cancelled', ! Matin_AI_Rules::can_cancel( 'cancelled', $start, $start - $six - 1 ) );
check( 'cancel refused for rejected', ! Matin_AI_Rules::can_cancel( 'rejected', $start, $start - $six - 1 ) );
check( 'cancel refused without a start time', ! Matin_AI_Rules::can_cancel( 'confirmed', null, 0 ) );

check( 'pending may become confirmed', Matin_AI_Rules::can_transition( 'pending', 'confirmed' ) );
check( 'pending may become rejected', Matin_AI_Rules::can_transition( 'pending', 'rejected' ) );
check( 'confirmed may not go back to pending', ! Matin_AI_Rules::can_transition( 'confirmed', 'pending' ) );
check( 'cancelled is terminal', ! Matin_AI_Rules::can_transition( 'cancelled', 'confirmed' ) );
check( 'rejected is terminal', ! Matin_AI_Rules::can_transition( 'rejected', 'confirmed' ) );

/* ---------- Matin_AI_Secrets ---------- */

$material = 'auth-salt-example';
$cipher   = Matin_AI_Secrets::encrypt( 'sk-test-123', $material );
check( 'encrypted value has the versioned prefix', Matin_AI_Secrets::is_encrypted( $cipher ) );
check( 'encrypted value does not contain the plaintext', strpos( $cipher, 'sk-test-123' ) === false );
check( 'decrypt round-trips', Matin_AI_Secrets::decrypt( $cipher, $material ) === 'sk-test-123' );
check( 'decrypt with a different salt fails closed', Matin_AI_Secrets::decrypt( $cipher, 'other-salt' ) === '' );
check( 'two encryptions of the same value differ (random nonce)', Matin_AI_Secrets::encrypt( 'x', $material ) !== Matin_AI_Secrets::encrypt( 'x', $material ) );
check( 'legacy plaintext is returned unchanged', Matin_AI_Secrets::decrypt( 'plain-old-key', $material ) === 'plain-old-key' );
check( 'empty value stays empty', Matin_AI_Secrets::encrypt( '', $material ) === '' && Matin_AI_Secrets::decrypt( '', $material ) === '' );
check( 'tampered ciphertext fails closed', Matin_AI_Secrets::decrypt( substr( $cipher, 0, -4 ) . 'AAAA', $material ) === '' );

/* ---------- Matin_AI_Logger::redact ---------- */

$redacted = Matin_AI_Logger::redact( [ 'api_key' => 'abc', 'nested' => [ 'Authorization' => 'Bearer x', 'guests' => 3 ], 'ok' => 'yes' ] );
check( 'sensitive keys are redacted at any depth', $redacted['api_key'] === '[redacted]' && $redacted['nested']['Authorization'] === '[redacted]' );
check( 'non-sensitive values are kept', $redacted['nested']['guests'] === 3 && $redacted['ok'] === 'yes' );
check( 'key= query parameter is redacted from text', strpos( Matin_AI_Logger::redact_text( 'GET https://x.test/a?key=AIzaSECRET&alt=json' ), 'AIzaSECRET' ) === false );
check( 'bearer token is redacted from text', strpos( Matin_AI_Logger::redact_text( 'header: Bearer tok_123' ), 'tok_123' ) === false );

/* ---------- Matin_AI_Tickets ---------- */

$secret = 'site-secret-for-tests';
$code   = Matin_AI_Tickets::validation_code( 42, 'MAT-ABCDEFGH', $secret );
check( 'validation code is grouped as XXXX-XXXX-XXXX-XXXX', (bool) preg_match( '/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', $code ) );
check( 'validation code is deterministic', $code === Matin_AI_Tickets::validation_code( 42, 'MAT-ABCDEFGH', $secret ) );
check( 'code verifies with lowercase and no dashes', Matin_AI_Tickets::verify_validation_code( strtolower( str_replace( '-', '', $code ) ), 42, 'MAT-ABCDEFGH', $secret ) );
check( 'code is bound to the reservation id', ! Matin_AI_Tickets::verify_validation_code( $code, 43, 'MAT-ABCDEFGH', $secret ) );
check( 'code is bound to the ticket code', ! Matin_AI_Tickets::verify_validation_code( $code, 42, 'MAT-ZZZZZZZZ', $secret ) );
check( 'code is bound to the secret', ! Matin_AI_Tickets::verify_validation_code( $code, 42, 'MAT-ABCDEFGH', 'other' ) );
check( 'mask_name keeps first name and initial', Matin_AI_Tickets::mask_name( 'علی رضایی' ) === 'علی ر.' );
check( 'mask_name handles one word', Matin_AI_Tickets::mask_name( 'مریم' ) === 'مریم' );
check( 'mask_name handles empty input', Matin_AI_Tickets::mask_name( '   ' ) === '' );

$svg = Matin_AI_Tickets::render_svg( [
	'kind' => 'ticket', 'cafe' => 'کافه <script>alert(1)</script> & co', 'event' => 'بازی "بزرگ"', 'starts' => '2026-10-19 20:00',
	'reserved_at' => '2026-10-18 10:00', 'guest' => 'علی ر.', 'guests' => '2', 'reservation_id' => '42', 'ticket_code' => 'MAT-ABCDEFGH',
	'status_label' => 'تأییدشده', 'validation' => $code, 'notice' => 'بلیت قطعی است.',
] );
check( 'SVG is well-formed XML', simplexml_load_string( $svg ) !== false );
check( 'SVG escapes markup from values', strpos( $svg, '<script>' ) === false && strpos( $svg, '&lt;script&gt;' ) !== false );
check( 'SVG contains the validation code', strpos( $svg, $code ) !== false );
$cancel = Matin_AI_Tickets::render_svg( [ 'kind' => 'cancellation', 'cafe' => 'c', 'event' => 'e', 'validation' => $code, 'notice' => 'لغو' ] );
check( 'cancellation SVG uses the cancellation title', strpos( $cancel, 'تأییدیه لغو رزرو' ) !== false );

/* ---------- Matin_AI_REST::haversine_km ---------- */

$km = Matin_AI_REST::haversine_km( 35.6892, 51.3890, 35.8400, 50.9391 ); // Tehran → Karaj
check( 'haversine: Tehran to Karaj is about 40 km', $km > 38 && $km < 44, (string) $km );
check( 'haversine: zero distance for the same point', Matin_AI_REST::haversine_km( 35.7, 51.4, 35.7, 51.4 ) === 0.0 );

echo PHP_EOL . "{$passes} passed, {$failures} failed" . PHP_EOL;
exit( $failures ? 1 : 0 );
