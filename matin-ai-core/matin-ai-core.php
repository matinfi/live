<?php
/**
 * Plugin Name: Matin AI Core
 * Description: Cafe and event discovery, the Matin AI assistant, reservations, tickets, customer follow-up, feedback and PWA support.
 * Version: 1.6.8
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: matin-ai
 */

defined( 'ABSPATH' ) || exit;

define( 'MATIN_AI_CORE_VERSION', '1.6.8' );
define( 'MATIN_AI_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once MATIN_AI_CORE_DIR . 'includes/class-matin-content.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-ai-provider.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-rest.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-secrets.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-admin.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-account.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-operations.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-customers.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-complaints.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-pages.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-pwa.php';

add_action( 'plugins_loaded', static function () : void {
	Matin_AI_Content::init();
	Matin_AI_REST::init();
	Matin_AI_Admin::init();
	Matin_AI_Account::init();
	Matin_AI_Operations::init();
	Matin_AI_Customers::init();
	Matin_AI_Complaints::init();
	Matin_AI_Pages::init();
	Matin_AI_PWA::init();
} );

register_activation_hook( __FILE__, static function () : void {
	Matin_AI_Content::register_types();
	Matin_AI_Complaints::register_type();
	Matin_AI_Pages::create_pages( true );
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
