<?php
defined( 'ABSPATH' ) || exit;

class ACF_Admin {

    public static function init(): void {
        add_action( 'admin_menu',    [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function register_menu(): void {
        add_menu_page(
            __( 'AI Content Forge', 'ai-content-forge' ),
            __( 'AI Content Forge', 'ai-content-forge' ),
            'manage_options',
            'ai-content-forge',
            [ self::class, 'render_page' ],
            'dashicons-superhero',
            66
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'toplevel_page_ai-content-forge', 'settings_page_ai-content-forge' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'acf-admin',
            ACF_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ACF_VERSION
        );

        wp_enqueue_script(
            'acf-admin-react',
            ACF_PLUGIN_URL . 'assets/js/admin-react.js',
            [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ],
            ACF_VERSION,
            true
        );

        wp_localize_script( 'acf-admin-react', 'acfAdmin', [
            'restUrl'  => rest_url( ACF_Rest_API::REST_NAMESPACE ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'settings' => ACF_Settings::for_js(),
        ] );
    }

    public static function render_page(): void {
        ?>
        <div class="wrap acf-settings-wrap">
            <h1 class="acf-page-title">
                <span class="acf-logo">⚡</span>
                <?php esc_html_e( 'AI Content Forge', 'ai-content-forge' ); ?>
            </h1>

            <div id="acf-admin-react-app" aria-live="polite"></div>
        </div>
        <?php
    }
}
