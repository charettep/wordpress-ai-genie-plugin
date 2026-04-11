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
        $openai_status = self::get_openai_status();
        ?>
        <div class="wrap aig-settings-wrap aig-deep-research-wrap">
            <h1 class="aig-page-title">
                <img src="<?php echo esc_url( AIG_PLUGIN_URL . 'images/plugin-icon.png' ); ?>" alt="" class="aig-logo-image" loading="lazy" decoding="async">
                <?php esc_html_e( 'Deep Research', 'ai-genie' ); ?>
            </h1>

            <p class="description aig-deep-research-intro">
                <?php esc_html_e( 'Run OpenAI Deep Research jobs from a dedicated wp-admin workspace. The research form is now split into Context and Data Sources tabs, with collapsible Web, Files, MCP, and Code Interpreter source panels.', 'ai-genie' ); ?>
            </p>

            <div class="aig-card aig-dr-status-card">
                <div class="aig-dr-runs-header">
                    <h2><?php esc_html_e( 'OpenAI Deep Research Status', 'ai-genie' ); ?></h2>
                </div>
                <p class="description"><?php esc_html_e( 'Deep Research uses the OpenAI API key configured in the main AI Genie settings page. The status below checks whether that key is available and whether the deep research models are exposed under it.', 'ai-genie' ); ?></p>
                <div class="aig-dr-status-grid">
                    <div class="aig-dr-status-item <?php echo ! empty( $openai_status['connected'] ) ? 'is-ok' : 'is-off'; ?>">
                        <span class="aig-dr-status-label"><?php esc_html_e( 'OpenAI API Key', 'ai-genie' ); ?></span>
                        <strong class="aig-dr-status-value"><?php echo ! empty( $openai_status['connected'] ) ? esc_html__( 'Connected', 'ai-genie' ) : esc_html__( 'Not Connected', 'ai-genie' ); ?></strong>
                    </div>
                    <div class="aig-dr-status-item <?php echo ! empty( $openai_status['models']['o4-mini-deep-research'] ) ? 'is-ok' : 'is-off'; ?>">
                        <span class="aig-dr-status-label"><?php esc_html_e( 'o4-mini-deep-research', 'ai-genie' ); ?></span>
                        <strong class="aig-dr-status-value"><?php echo ! empty( $openai_status['models']['o4-mini-deep-research'] ) ? esc_html__( 'Available', 'ai-genie' ) : esc_html__( 'Unavailable', 'ai-genie' ); ?></strong>
                    </div>
                    <div class="aig-dr-status-item <?php echo ! empty( $openai_status['models']['o3-deep-research'] ) ? 'is-ok' : 'is-off'; ?>">
                        <span class="aig-dr-status-label"><?php esc_html_e( 'o3-deep-research', 'ai-genie' ); ?></span>
                        <strong class="aig-dr-status-value"><?php echo ! empty( $openai_status['models']['o3-deep-research'] ) ? esc_html__( 'Available', 'ai-genie' ) : esc_html__( 'Unavailable', 'ai-genie' ); ?></strong>
                    </div>
                </div>
                <?php if ( ! empty( $openai_status['message'] ) ) : ?>
                    <p class="description"><?php echo esc_html( $openai_status['message'] ); ?></p>
                <?php endif; ?>
            </div>

            <div class="aig-dr-shell">
                <div class="aig-dr-main-tabs" role="tablist" aria-orientation="vertical">
                    <button type="button" class="aig-dr-main-tab is-active" data-main-tab="context" aria-selected="true"><?php esc_html_e( 'Context', 'ai-genie' ); ?></button>
                    <button type="button" class="aig-dr-main-tab" data-main-tab="data-sources" aria-selected="false"><?php esc_html_e( 'Data Sources', 'ai-genie' ); ?></button>
                    <button type="button" class="aig-dr-main-tab" data-main-tab="webhook" aria-selected="false"><?php esc_html_e( 'Webhook', 'ai-genie' ); ?></button>
                    <button type="button" class="aig-dr-main-tab" data-main-tab="runs" aria-selected="false"><?php esc_html_e( 'Runs', 'ai-genie' ); ?></button>
                </div>

                <div class="aig-dr-main-panels">
                    <form id="aig-deep-research-form" class="aig-deep-research-form">
                        <section class="aig-card aig-dr-main-panel is-active" data-main-panel="context">
                            <div class="aig-dr-runs-header">
                                <h2><?php esc_html_e( 'Context', 'ai-genie' ); ?></h2>
                            </div>
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
                        </section>

                        <section class="aig-card aig-dr-main-panel" data-main-panel="data-sources">
                            <div class="aig-dr-runs-header">
                                <h2><?php esc_html_e( 'Data Sources', 'ai-genie' ); ?></h2>
                            </div>
                            <p class="description"><?php esc_html_e( 'Deep Research requires at least one enabled source from Web, Files, or MCP. Disabled tabs stay locked and collapsed until enabled.', 'ai-genie' ); ?></p>

                            <div class="aig-dr-source-stack">
                                <section class="aig-dr-source-panel is-enabled is-open" data-source-panel="web">
                                    <div class="aig-dr-source-header">
                                        <label class="aig-dr-toggle">
                                            <input type="checkbox" name="web_search_enabled" value="1" checked>
                                            <span><?php esc_html_e( 'Web', 'ai-genie' ); ?></span>
                                        </label>
                                        <button type="button" class="button-link aig-dr-source-trigger" data-source-trigger="web" aria-expanded="true"><?php esc_html_e( 'Collapse', 'ai-genie' ); ?></button>
                                    </div>
                                    <div class="aig-dr-source-body">
                                        <div class="aig-dr-domain-modes">
                                            <label class="aig-dr-checkline">
                                                <input type="checkbox" name="web_domain_allow_all" value="1" checked>
                                                <span><?php esc_html_e( 'Allow all domains', 'ai-genie' ); ?></span>
                                            </label>
                                            <label class="aig-dr-checkline">
                                                <input type="checkbox" name="web_domain_allow_only" value="1">
                                                <span><?php esc_html_e( 'Allow ONLY these domains', 'ai-genie' ); ?></span>
                                            </label>
                                            <label class="aig-dr-block">
                                                <span><?php esc_html_e( 'Allowed domains', 'ai-genie' ); ?></span>
                                                <textarea name="web_domain_allowlist" rows="5" class="large-text" placeholder="openai.com&#10;wordpress.org&#10;example.com"><?php echo esc_textarea( implode( "\n", (array) ( $settings['web_domain_allowlist'] ?? [] ) ) ); ?></textarea>
                                            </label>
                                            <label class="aig-dr-checkline">
                                                <input type="checkbox" name="web_domain_block" value="1">
                                                <span><?php esc_html_e( 'Block these domains', 'ai-genie' ); ?></span>
                                            </label>
                                            <label class="aig-dr-block">
                                                <span><?php esc_html_e( 'Blocked domains', 'ai-genie' ); ?></span>
                                                <textarea name="web_domain_blocklist" rows="5" class="large-text" placeholder="example.com&#10;ads.example"><?php echo esc_textarea( '' ); ?></textarea>
                                            </label>
                                        </div>
                                        <p class="description"><?php esc_html_e( 'Domain rules are stored in the Deep Research configuration now. The exact Responses API filter shape for web search domains is still being finalized against the API reference.', 'ai-genie' ); ?></p>
                                    </div>
                                </section>

                                <section class="aig-dr-source-panel is-disabled" data-source-panel="files">
                                    <div class="aig-dr-source-header">
                                        <label class="aig-dr-toggle">
                                            <input type="checkbox" name="file_search_enabled" value="1">
                                            <span><?php esc_html_e( 'Files', 'ai-genie' ); ?></span>
                                        </label>
                                        <button type="button" class="button-link aig-dr-source-trigger" data-source-trigger="files" aria-expanded="false"><?php esc_html_e( 'Expand', 'ai-genie' ); ?></button>
                                    </div>
                                    <div class="aig-dr-source-body">
                                        <div class="aig-dr-section-block">
                                            <span class="aig-dr-card-title"><?php esc_html_e( 'Vector Stores', 'ai-genie' ); ?></span>
                                            <div id="aig-dr-vector-store-options" class="aig-dr-options-list">
                                                <p class="description"><?php esc_html_e( 'Loading vector stores…', 'ai-genie' ); ?></p>
                                            </div>
                                            <p class="description"><?php esc_html_e( 'Attach up to two OpenAI vector stores for file search.', 'ai-genie' ); ?></p>
                                        </div>

                                        <div class="aig-dr-section-block">
                                            <label class="aig-dr-block">
                                                <span><?php esc_html_e( 'New Vector Store Name', 'ai-genie' ); ?></span>
                                                <input type="text" id="aig-dr-vector-store-name" class="large-text" placeholder="<?php esc_attr_e( 'Product docs corpus', 'ai-genie' ); ?>">
                                            </label>
                                            <div class="aig-dr-actions">
                                                <button type="button" class="button" id="aig-dr-create-vector-store"><?php esc_html_e( 'Create Vector Store', 'ai-genie' ); ?></button>
                                                <span id="aig-dr-vector-store-status" class="aig-dr-form-status" aria-live="polite"></span>
                                            </div>
                                        </div>

                                        <div id="aig-dr-vector-stores-list" class="aig-dr-managed-list"></div>
                                    </div>
                                </section>

                                <section class="aig-dr-source-panel is-disabled" data-source-panel="mcp">
                                    <div class="aig-dr-source-header">
                                        <label class="aig-dr-toggle">
                                            <input type="checkbox" name="mcp_enabled" value="1">
                                            <span><?php esc_html_e( 'MCP', 'ai-genie' ); ?></span>
                                        </label>
                                        <button type="button" class="button-link aig-dr-source-trigger" data-source-trigger="mcp" aria-expanded="false"><?php esc_html_e( 'Expand', 'ai-genie' ); ?></button>
                                    </div>
                                    <div class="aig-dr-source-body">
                                        <div class="aig-dr-section-block">
                                            <span class="aig-dr-card-title"><?php esc_html_e( 'Saved MCP Sources', 'ai-genie' ); ?></span>
                                            <div id="aig-dr-source-options" class="aig-dr-options-list">
                                                <p class="description"><?php esc_html_e( 'Loading saved sources…', 'ai-genie' ); ?></p>
                                            </div>
                                            <p class="description"><?php esc_html_e( 'Deep Research MCP sources must be read-only search/fetch servers. This feature narrows allowed tools to search and fetch and always sets require_approval to never.', 'ai-genie' ); ?></p>
                                        </div>

                                        <div class="aig-dr-section-block">
                                            <div class="aig-dr-grid">
                                                <label>
                                                    <span><?php esc_html_e( 'Source Name', 'ai-genie' ); ?></span>
                                                    <input type="text" id="aig-dr-source-name" class="regular-text" placeholder="<?php esc_attr_e( 'Internal research index', 'ai-genie' ); ?>">
                                                </label>
                                                <label>
                                                    <span><?php esc_html_e( 'Server Label', 'ai-genie' ); ?></span>
                                                    <input type="text" id="aig-dr-source-label" class="regular-text" value="trusted-mcp">
                                                </label>
                                            </div>
                                            <label class="aig-dr-block">
                                                <span><?php esc_html_e( 'Remote MCP Server URL', 'ai-genie' ); ?></span>
                                                <input type="url" id="aig-dr-source-url" class="large-text" placeholder="https://example.com/mcp">
                                            </label>
                                            <label class="aig-dr-block">
                                                <span><?php esc_html_e( 'Authorization', 'ai-genie' ); ?></span>
                                                <input type="text" id="aig-dr-source-authorization" class="large-text" placeholder="Bearer ...">
                                            </label>
                                            <label class="aig-dr-toggle">
                                                <input type="checkbox" id="aig-dr-source-active" value="1" checked>
                                                <span><?php esc_html_e( 'Source is active', 'ai-genie' ); ?></span>
                                            </label>
                                            <div class="aig-dr-actions">
                                                <button type="button" class="button" id="aig-dr-save-source"><?php esc_html_e( 'Save Source', 'ai-genie' ); ?></button>
                                                <span id="aig-dr-source-status" class="aig-dr-form-status" aria-live="polite"></span>
                                            </div>
                                        </div>

                                        <div id="aig-dr-sources-list" class="aig-dr-managed-list"></div>
                                    </div>
                                </section>

                                <section class="aig-dr-source-panel is-disabled" data-source-panel="code">
                                    <div class="aig-dr-source-header">
                                        <label class="aig-dr-toggle">
                                            <input type="checkbox" name="code_interpreter_enabled" value="1">
                                            <span><?php esc_html_e( 'Code Interpreter', 'ai-genie' ); ?></span>
                                        </label>
                                        <button type="button" class="button-link aig-dr-source-trigger" data-source-trigger="code" aria-expanded="false"><?php esc_html_e( 'Expand', 'ai-genie' ); ?></button>
                                    </div>
                                    <div class="aig-dr-source-body">
                                        <label>
                                            <span><?php esc_html_e( 'Memory Limit', 'ai-genie' ); ?></span>
                                            <select name="code_memory_limit">
                                                <option value="" <?php selected( $settings['default_code_memory_limit'], '' ); ?>><?php esc_html_e( 'No limit', 'ai-genie' ); ?></option>
                                                <option value="1g" <?php selected( $settings['default_code_memory_limit'], '1g' ); ?>>1g</option>
                                                <option value="4g" <?php selected( $settings['default_code_memory_limit'], '4g' ); ?>>4g</option>
                                                <option value="16g" <?php selected( $settings['default_code_memory_limit'], '16g' ); ?>>16g</option>
                                                <option value="64g" <?php selected( $settings['default_code_memory_limit'], '64g' ); ?>>64g</option>
                                            </select>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'Containers expire after 20 minutes of inactivity. Generated files should be downloaded while the container is still active.', 'ai-genie' ); ?></p>
                                    </div>
                                </section>
                            </div>
                        </section>

                        <div class="aig-dr-actions aig-dr-form-footer">
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Start Deep Research', 'ai-genie' ); ?></button>
                            <span id="aig-dr-form-status" class="aig-dr-form-status" aria-live="polite"></span>
                        </div>
                    </form>

                    <section class="aig-card aig-dr-main-panel" data-main-panel="webhook">
                        <div class="aig-dr-runs-header">
                            <h2><?php esc_html_e( 'Webhook Callback', 'ai-genie' ); ?></h2>
                        </div>
                        <p class="description"><?php esc_html_e( 'Use this endpoint for optional OpenAI webhook delivery when your WordPress site has a public callback URL. Polling remains the fallback for every run.', 'ai-genie' ); ?></p>
                        <div id="aig-dr-webhook-details" class="aig-dr-webhook-details"></div>
                    </section>

                    <section class="aig-card aig-dr-main-panel" data-main-panel="runs">
                        <div class="aig-dr-runs-header">
                            <h2><?php esc_html_e( 'Runs', 'ai-genie' ); ?></h2>
                            <button type="button" class="button" id="aig-dr-refresh-runs"><?php esc_html_e( 'Refresh', 'ai-genie' ); ?></button>
                        </div>
                        <div id="aig-dr-runs" class="aig-dr-runs"></div>
                    </section>
                </div>
            </div>
        </div>
        <?php
    }

    private static function get_openai_status(): array {
        $api_key = trim( (string) AIG_Settings::get( 'openai_api_key', '' ) );

        if ( '' === $api_key ) {
            return [
                'connected' => false,
                'models'    => [
                    'o4-mini-deep-research' => false,
                    'o3-deep-research'      => false,
                ],
                'message'   => __( 'Configure an OpenAI API key in AI Genie settings to use Deep Research.', 'ai-genie' ),
            ];
        }

        $cache_key = 'aig_dr_openai_status_' . md5( $api_key );
        $cached = get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $status = [
            'connected' => false,
            'models'    => [
                'o4-mini-deep-research' => false,
                'o3-deep-research'      => false,
            ],
            'message'   => '',
        ];

        try {
            $provider = AIG_Generator::get_provider( 'openai' );
            $models = $provider->discover_models();
            $model_ids = array_map(
                static fn( array $model ): string => (string) ( $model['id'] ?? '' ),
                is_array( $models ) ? $models : []
            );

            $status['connected'] = true;
            $status['models']['o4-mini-deep-research'] = in_array( 'o4-mini-deep-research', $model_ids, true );
            $status['models']['o3-deep-research'] = in_array( 'o3-deep-research', $model_ids, true );

            if ( ! $status['models']['o4-mini-deep-research'] || ! $status['models']['o3-deep-research'] ) {
                $status['message'] = __( 'The API key is valid, but one or both Deep Research models are not exposed for this account right now.', 'ai-genie' );
            }
        } catch ( \Throwable $e ) {
            $status['message'] = $e->getMessage();
        }

        set_transient( $cache_key, $status, 5 * MINUTE_IN_SECONDS );

        return $status;
    }
}
