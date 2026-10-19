<?php
/**
 * Plugin Name: Matin AI Core
 * Description: Cafe, event, discovery, AI assistant, reservations, cancellation, tickets and WhatsApp notifications for Matin AI.
 * Version: 1.4.3
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: matin-ai
 */

defined( 'ABSPATH' ) || exit;

define( 'MATIN_AI_CORE_VERSION', '1.4.3' );
define( 'MATIN_AI_CORE_DB_VERSION', '3' );
define( 'MATIN_AI_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once MATIN_AI_CORE_DIR . 'includes/class-matin-rules.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-secrets.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-logger.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-tickets.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-messaging.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-sms.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-google.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-content.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-ai-provider.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-rest.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-admin.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-account.php';

/** Custom schedule used by the WhatsApp retry job. */
add_filter( 'cron_schedules', static function ( array $schedules ) : array {
	$schedules['matin_ai_quarter_hour'] = [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'هر ۱۵ دقیقه' ];
	return $schedules;
} );

/** Creates or updates custom tables and the signing secret. dbDelta is idempotent, so this is safe on upgrades. */
function matin_ai_core_install() : void {
	Matin_AI_Logger::install();
	Matin_AI_Messaging::install();
	Matin_AI_Tickets::secret();
	if ( ! wp_next_scheduled( 'matin_ai_prune_logs' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'matin_ai_prune_logs' );
	}
	update_option( 'matin_ai_db_version', MATIN_AI_CORE_DB_VERSION, false );
}

function matin_ai_core_maybe_upgrade() : void {
	if ( get_option( 'matin_ai_db_version' ) !== MATIN_AI_CORE_DB_VERSION ) {
		matin_ai_core_install();
	}
}

/** Records PHP warnings and notices raised by this plugin's own files. Returns false so normal handling continues. */
function matin_ai_core_error_handler( int $severity, string $message, string $file, int $line ) : bool {
	static $busy = false;
	if ( $busy || ! ( error_reporting() & $severity ) || 0 !== strpos( $file, MATIN_AI_CORE_DIR ) ) {
		return false;
	}
	$busy = true;
	Matin_AI_Logger::log( 'warning', 'php', $message, [ 'file' => basename( $file ), 'line' => $line, 'severity' => $severity ] );
	$busy = false;
	return false;
}

function matin_ai_core_shutdown_handler() : void {
	$error = error_get_last();
	if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) && 0 === strpos( (string) $error['file'], MATIN_AI_CORE_DIR ) ) {
		Matin_AI_Logger::log( 'error', 'php', (string) $error['message'], [ 'file' => basename( $error['file'] ), 'line' => $error['line'] ] );
	}
}

add_action( 'plugins_loaded', static function () : void {
	Matin_AI_Content::init();
	Matin_AI_REST::init();
	Matin_AI_Admin::init();
	Matin_AI_Account::init();
	Matin_AI_Messaging::init();
	Matin_AI_Tickets::init();
	Matin_AI_Google::init();
	add_action( 'matin_ai_prune_logs', static fn() => Matin_AI_Logger::prune( (int) Matin_AI_Admin::get_options()['log_retention_days'] ) );
	set_error_handler( 'matin_ai_core_error_handler' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	register_shutdown_function( 'matin_ai_core_shutdown_handler' );
	matin_ai_core_maybe_upgrade();
} );

register_activation_hook( __FILE__, static function () : void {
	Matin_AI_Content::register_types();
	matin_ai_core_install();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function () : void {
	Matin_AI_Messaging::uninstall_schedule();
	wp_clear_scheduled_hook( 'matin_ai_prune_logs' );
	flush_rewrite_rules();
} );
