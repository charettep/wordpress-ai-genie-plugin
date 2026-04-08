<?php
defined( 'ABSPATH' ) || exit;

class AIG_Deep_Research_Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'ai-genie',
            __( 'Deep Research', 'ai-genie' ),
            __( 'Deep Research', 'ai-genie' ),
            'manage_options',
            'ai-genie-deep-research',
            [ self::class, 'render_page' ]
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( 'ai-genie_page_ai-genie-deep-research' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'aig-admin',
            AIG_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AIG_VERSION
        );

        wp_enqueue_script(
            'aig-deep-research-admin',
            AIG_PLUGIN_URL . 'assets/js/deep-research-admin.js',
            [],
            AIG_VERSION,
            true
        );

        wp_localize_script(
            'aig-deep-research-admin',
            'aigDeepResearchAdmin',
            [
                'restUrl'  => rest_url( AIG_Rest_API::REST_NAMESPACE ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'settings' => AIG_Deep_Research_Settings::all(),
                'i18n'     => [
                    'creating'   => __( 'Starting research…', 'ai-genie' ),
                    'refreshing' => __( 'Refreshing…', 'ai-genie' ),
                    'cancelling' => __( 'Cancelling…', 'ai-genie' ),
                    'drafting'   => __( 'Creating draft…', 'ai-genie' ),
                    'error'      => __( 'Request failed.', 'ai-genie' ),
                ],
            ]
        );
    }

    public static function render_page(): void {
        $settings = AIG_Deep_Research_Settings::all();
        ?>
        <div class="wrap aig-settings-wrap aig-deep-research-wrap">
            <h1 class="aig-page-title">
                <img src="<?php echo esc_url( AIG_PLUGIN_URL . 'images/plugin-icon.png' ); ?>" alt="" class="aig-logo-image" loading="lazy" decoding="async">
                <?php esc_html_e( 'Deep Research', 'ai-genie' ); ?>
            </h1>

            <p class="description aig-deep-research-intro">
                <?php esc_html_e( 'Run OpenAI Deep Research jobs from a dedicated wp-admin workspace. This first implementation pass covers background or synchronous runs, web search, vector store ids, remote MCP search/fetch servers, code interpreter, and draft creation from completed reports.', 'ai-genie' ); ?>
            </p>

            <div class="aig-card">
                <form id="aig-deep-research-form" class="aig-deep-research-form">
                    <div class="aig-dr-grid">
                        <label>
                            <span><?php esc_html_e( 'Report Title', 'ai-genie' ); ?></span>
                            <input type="text" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'Quarterly competitor landscape', 'ai-genie' ); ?>">
                        </label>

                        <label>
                            <span><?php esc_html_e( 'Model', 'ai-genie' ); ?></span>
                            <select name="model">
                                <option value="o4-mini-deep-research" <?php selected( $settings['default_model'], 'o4-mini-deep-research' ); ?>>o4-mini-deep-research</option>
                                <option value="o3-deep-research">o3-deep-research</option>
                            </select>
                        </label>

                        <label>
                            <span><?php esc_html_e( 'Max Tool Calls', 'ai-genie' ); ?></span>
                            <input type="number" min="1" max="100" name="max_tool_calls" value="<?php echo esc_attr( (string) $settings['default_max_tool_calls'] ); ?>">
                        </label>

                        <label class="aig-dr-toggle">
                            <input type="checkbox" name="background" value="1" <?php checked( ! empty( $settings['default_background'] ) ); ?>>
                            <span><?php esc_html_e( 'Run in background mode', 'ai-genie' ); ?></span>
                        </label>
                    </div>

                    <label class="aig-dr-block">
                        <span><?php esc_html_e( 'Research Prompt', 'ai-genie' ); ?></span>
                        <textarea name="prompt" rows="10" class="large-text" placeholder="<?php esc_attr_e( 'Research the top competitors in the North American AI writing plugin market, compare pricing, positioning, feature gaps, and likely opportunities for a WordPress plugin release strategy.', 'ai-genie' ); ?>"></textarea>
                    </label>

                    <div class="aig-dr-sources">
                        <div class="aig-dr-source-card">
                            <label class="aig-dr-toggle">
                                <input type="checkbox" name="web_search_enabled" value="1" checked>
                                <span><?php esc_html_e( 'Enable web search', 'ai-genie' ); ?></span>
                            </label>
                            <label class="aig-dr-block">
                                <span><?php esc_html_e( 'Domain allowlist', 'ai-genie' ); ?></span>
                                <textarea name="web_domain_allowlist" rows="5" class="large-text" placeholder="openai.com&#10;wordpress.org&#10;example.com"><?php echo esc_textarea( implode( "\n", (array) ( $settings['web_domain_allowlist'] ?? [] ) ) ); ?></textarea>
                            </label>
                            <p class="description"><?php esc_html_e( 'Stored as a whitelist now. The first implementation pass persists it in the run config and admin UI while the exact Responses API filters shape is finalized against the API reference.', 'ai-genie' ); ?></p>
                        </div>

                        <div class="aig-dr-source-card">
                            <label class="aig-dr-block">
                                <span><?php esc_html_e( 'Vector Store IDs', 'ai-genie' ); ?></span>
                                <textarea name="vector_store_ids" rows="4" class="large-text" placeholder="vs_123&#10;vs_456"></textarea>
                            </label>
                            <p class="description"><?php esc_html_e( 'Use up to two OpenAI vector store ids for file search. Separate ids with commas or new lines.', 'ai-genie' ); ?></p>
                        </div>

                        <div class="aig-dr-source-card">
                            <label class="aig-dr-block">
                                <span><?php esc_html_e( 'Remote MCP Server URL', 'ai-genie' ); ?></span>
                                <input type="url" name="mcp_server_url" class="large-text" placeholder="https://example.com/mcp">
                            </label>
                            <div class="aig-dr-grid">
                                <label>
                                    <span><?php esc_html_e( 'Server Label', 'ai-genie' ); ?></span>
                                    <input type="text" name="mcp_server_label" value="trusted-mcp">
                                </label>
                                <label>
                                    <span><?php esc_html_e( 'Authorization', 'ai-genie' ); ?></span>
                                    <input type="text" name="mcp_authorization" class="regular-text" placeholder="Bearer ...">
                                </label>
                            </div>
                            <p class="description"><?php esc_html_e( 'Deep Research MCP sources must be read-only search/fetch servers. This implementation narrows allowed tools to search and fetch and always sets require_approval to never.', 'ai-genie' ); ?></p>
                        </div>

                        <div class="aig-dr-source-card">
                            <label class="aig-dr-toggle">
                                <input type="checkbox" name="code_interpreter_enabled" value="1">
                                <span><?php esc_html_e( 'Enable code interpreter', 'ai-genie' ); ?></span>
                            </label>
                            <label>
                                <span><?php esc_html_e( 'Memory limit', 'ai-genie' ); ?></span>
                                <select name="code_memory_limit">
                                    <option value="1g" <?php selected( $settings['default_code_memory_limit'], '1g' ); ?>>1g</option>
                                    <option value="4g">4g</option>
                                    <option value="16g">16g</option>
                                    <option value="64g">64g</option>
                                </select>
                            </label>
                            <p class="description"><?php esc_html_e( 'Containers expire after 20 minutes of inactivity. Generated files should be downloaded while the container is still active.', 'ai-genie' ); ?></p>
                        </div>
                    </div>

                    <div class="aig-dr-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Start Deep Research', 'ai-genie' ); ?></button>
                        <span id="aig-dr-form-status" class="aig-dr-form-status" aria-live="polite"></span>
                    </div>
                </form>
            </div>

            <div class="aig-card">
                <div class="aig-dr-runs-header">
                    <h2><?php esc_html_e( 'Runs', 'ai-genie' ); ?></h2>
                    <button type="button" class="button" id="aig-dr-refresh-runs"><?php esc_html_e( 'Refresh', 'ai-genie' ); ?></button>
                </div>
                <div id="aig-dr-runs" class="aig-dr-runs"></div>
            </div>
        </div>
        <?php
    }
}
