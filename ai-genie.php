<?php
/**
 * Plugin Name: AI Genie
 * Plugin URI:  https://github.com/charettep/wordpress-ai-genie-plugin
 * Description: AI-powered content generation (posts, SEO, descriptions) via Claude, OpenAI, or Ollama — your AI genie for WordPress content.
 * Version:     3.2.1
 * Author:      charettep
 * License:     GPL-2.0+
 * Text Domain: ai-genie
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'AIG_VERSION',    '3.2.1' );
define( 'AIG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AIG_PLUGIN_DIR . 'includes/class-aig-settings.php';
require_once AIG_PLUGIN_DIR . 'includes/class-aig-deep-research-settings.php';
require_once AIG_PLUGIN_DIR . 'includes/class-aig-deep-research-store.php';
require_once AIG_PLUGIN_DIR . 'includes/class-aig-deep-research-install.php';
require_once AIG_PLUGIN_DIR . 'includes/class-aig-deep-research-service.php';
require_once AIG_PLUGIN_DIR . 'includes/class-aig-tiktoken.php';
require_once AIG_PLUGIN_DIR . 'includes/class-aig-token-usage-estimator.php';
require_once AIG_PLUGIN_DIR . 'includes/class-aig-provider.php';
require_once AIG_PLUGIN_DIR . 'includes/providers/class-aig-provider-claude.php';
require_once AIG_PLUGIN_DIR . 'includes/providers/class-aig-provider-openai.php';
require_once AIG_PLUGIN_DIR . 'includes/providers/class-aig-provider-ollama.php';
require_once AIG_PLUGIN_DIR . 'includes/class-aig-generator.php';
require_once AIG_PLUGIN_DIR . 'includes/class-aig-rest-api.php';
require_once AIG_PLUGIN_DIR . 'admin/class-aig-admin.php';
require_once AIG_PLUGIN_DIR . 'admin/class-aig-deep-research-admin.php';
require_once AIG_PLUGIN_DIR . 'admin/class-aig-gutenberg.php';

register_activation_hook( __FILE__, [ 'AIG_Deep_Research_Install', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'AIG_Deep_Research_Install', 'deactivate' ] );

function aig_init() {
    AIG_Settings::init();
    AIG_Deep_Research_Settings::init();
    AIG_Deep_Research_Install::init();
    AIG_Rest_API::init();
    AIG_Admin::init();
    AIG_Deep_Research_Admin::init();
    AIG_Gutenberg::init();
}
add_action( 'plugins_loaded', 'aig_init' );
