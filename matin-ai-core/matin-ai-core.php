<?php
/**
 * Plugin Name: Matin AI Core
 * Description: Cafe, event, discovery, Gemini assistant and pending reservation business logic for Matin AI.
 * Version: 1.3.2
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: matin-ai
 */

defined( 'ABSPATH' ) || exit;

define( 'MATIN_AI_CORE_VERSION', '1.3.2' );
define( 'MATIN_AI_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once MATIN_AI_CORE_DIR . 'includes/class-matin-content.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-ai-provider.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-rest.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-admin.php';
require_once MATIN_AI_CORE_DIR . 'includes/class-matin-account.php';

add_action( 'plugins_loaded', static function () : void {
	Matin_AI_Content::init();
	Matin_AI_REST::init();
	Matin_AI_Admin::init();
	Matin_AI_Account::init();
} );

register_activation_hook( __FILE__, static function () : void {
	Matin_AI_Content::register_types();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

