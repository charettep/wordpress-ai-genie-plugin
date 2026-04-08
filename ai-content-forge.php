<?php
/**
 * Plugin Name: AI Content Forge
 * Plugin URI:  https://github.com/charettep/wordpress-ai-content-forge-plugin
 * Description: AI-powered content generation (posts, SEO, descriptions) via Claude, OpenAI, or Ollama — with global default and per-use provider override.
 * Version:     2.9.0
 * Author:      charettep
 * License:     GPL-2.0+
 * Text Domain: ai-content-forge
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'ACF_VERSION',    '2.9.0' );
define( 'ACF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ACF_PLUGIN_DIR . 'includes/class-acf-settings.php';
require_once ACF_PLUGIN_DIR . 'includes/class-acf-provider.php';
require_once ACF_PLUGIN_DIR . 'includes/providers/class-acf-provider-claude.php';
require_once ACF_PLUGIN_DIR . 'includes/providers/class-acf-provider-openai.php';
require_once ACF_PLUGIN_DIR . 'includes/providers/class-acf-provider-ollama.php';
require_once ACF_PLUGIN_DIR . 'includes/class-acf-generator.php';
require_once ACF_PLUGIN_DIR . 'includes/class-acf-rest-api.php';
require_once ACF_PLUGIN_DIR . 'admin/class-acf-admin.php';
require_once ACF_PLUGIN_DIR . 'admin/class-acf-gutenberg.php';

function acf_init() {
    ACF_Settings::init();
    ACF_Rest_API::init();
    ACF_Admin::init();
    ACF_Gutenberg::init();
}
add_action( 'plugins_loaded', 'acf_init' );
