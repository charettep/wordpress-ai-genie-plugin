<?php
defined( 'ABSPATH' ) || exit;

class AIG_Admin {

    private static function image_url( string $filename ): string {
        return AIG_PLUGIN_URL . 'images/' . ltrim( $filename, '/' );
    }

    private static function menu_icon_data_uri(): string {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
  <ellipse cx="10" cy="13.5" rx="5.5" ry="6" fill="currentColor"/>
  <path d="M4.5,11 Q2.5,8 3.5,6 Q4.5,4.5 6,5.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
  <circle cx="3.5" cy="5.5" r="2" fill="currentColor"/>
  <path d="M15.5,11 Q17.5,8 16.5,6 Q15.5,4.5 14,5.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
  <circle cx="16.5" cy="5.5" r="2" fill="currentColor"/>
  <ellipse cx="10" cy="7" rx="5" ry="2.5" fill="currentColor"/>
  <ellipse cx="10" cy="5.5" rx="3" ry="2" fill="currentColor"/>
  <circle cx="10" cy="4" r="1.5" fill="currentColor" opacity="0.9"/>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    private static function provider_icon_filename( string $slug ): string {
        return match ( $slug ) {
            'claude' => 'claude-ai-icon.png',
            'openai' => 'openai-icon.png',
            'ollama' => 'ollama-icon.png',
            default  => 'plugin-icon.png',
        };
    }

    private static function provider_icon_markup( string $slug, string $base_class = 'aig-provider-logo' ): string {
        return sprintf(
            '<span class="%1$s aig-logo-%2$s" aria-hidden="true"><img src="%3$s" alt="" class="aig-provider-logo-image" loading="lazy" decoding="async"></span>',
            esc_attr( $base_class ),
            esc_attr( $slug ),
            esc_url( self::image_url( self::provider_icon_filename( $slug ) ) )
        );
    }

    public static function init(): void {
        add_action( 'admin_menu',    [ self::class, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function register_menu(): void {
        add_menu_page(
            __( 'AI Genie', 'ai-genie' ),
            __( 'AI Genie', 'ai-genie' ),
            'manage_options',
            'ai-genie',
            [ self::class, 'render_page' ],
            self::menu_icon_data_uri(),
            66
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'toplevel_page_ai-genie', 'settings_page_ai-genie' ], true ) ) {
            return;
        }
        wp_enqueue_style(
            'aig-admin',
            AIG_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AIG_VERSION
        );
        wp_enqueue_script(
            'aig-admin',
            AIG_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            AIG_VERSION,
            true
        );
        wp_localize_script( 'aig-admin', 'aigAdmin', [
            'restUrl' => rest_url( AIG_Rest_API::REST_NAMESPACE ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'settings' => AIG_Settings::for_js(),
            'i18n' => [
                'checking'         => __( 'Checking…', 'ai-genie' ),
                'connected'        => __( 'Connected', 'ai-genie' ),
                'failed'           => __( 'Connection failed', 'ai-genie' ),
                'enterApiKey'      => __( 'Enter an API key to load models', 'ai-genie' ),
                'enterBaseUrl'     => __( 'Enter a Base URL to load models', 'ai-genie' ),
                'loadingModels'    => __( 'Loading available models…', 'ai-genie' ),
                'noModels'         => __( 'No models returned for this API key', 'ai-genie' ),
                'noOllamaModels'   => __( 'No models returned by this Ollama server', 'ai-genie' ),
                'generating'       => __( 'Generating…', 'ai-genie' ),
            ],
        ] );
    }

    public static function render_page(): void {
        $settings = AIG_Settings::all();
        $opt      = AIG_Settings::OPTION_KEY;

        // ── Summary strip data ─────────────────────────────────────────────────
        $provider_labels  = [ 'claude' => 'Anthropic Claude', 'openai' => 'OpenAI', 'ollama' => 'Ollama' ];
        $default_provider = $settings['default_provider'] ?? 'claude';
        $default_model    = match ( $default_provider ) {
            'claude' => $settings['claude_model'] ?? '',
            'openai' => $settings['openai_model'] ?? '',
            'ollama' => $settings['ollama_model'] ?? '',
            default  => '',
        };

        $prompts  = [
            'post_content' => [
                'label'       => __( 'Post Content Prompt', 'ai-genie' ),
                'description' => __( 'Used for full post or page body generation.', 'ai-genie' ),
                'rows'        => 14,
            ],
            'seo_title' => [
                'label'       => __( 'SEO Title Prompt', 'ai-genie' ),
                'description' => __( 'Used for generating short SEO title tags.', 'ai-genie' ),
                'rows'        => 10,
            ],
            'meta_description' => [
                'label'       => __( 'Meta Description Prompt', 'ai-genie' ),
                'description' => __( 'Used for generating meta descriptions.', 'ai-genie' ),
                'rows'        => 10,
            ],
            'excerpt' => [
                'label'       => __( 'Excerpt Prompt', 'ai-genie' ),
                'description' => __( 'Used for generating short post excerpts.', 'ai-genie' ),
                'rows'        => 10,
            ],
        ];
        $placeholders = [
            '{title}',
            '{tone}',
            '{keywords}',
            '{keywords_line}',
            '{post_type}',
            '{language}',
            '{structure}',
            '{structure_line}',
            '{target_length}',
            '{target_length_line}',
            '{existing_content}',
            '{existing_content_block}',
        ];
        $cloudflare_signup_url      = 'https://dash.cloudflare.com/sign-up';
        $cloudflare_domain_url      = 'https://developers.cloudflare.com/fundamentals/manage-domains/add-site/';
        $cloudflare_zero_trust_url  = 'https://one.dash.cloudflare.com/';
        $cloudflare_pkg_url         = 'https://pkg.cloudflare.com/';
        $cloudflare_config_url      = 'https://developers.cloudflare.com/tunnel/advanced/local-management/configuration-file/';
        $cloudflare_access_url      = 'https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-apps/';
        $cloudflare_tokens_url      = 'https://developers.cloudflare.com/cloudflare-one/access-controls/service-credentials/service-tokens/';
        $cloudflare_workers_url     = 'https://developers.cloudflare.com/workers/';
        $ollama_download_url        = 'https://ollama.com/download/linux';
        ?>
        <div class="wrap aig-settings-wrap">
            <h1 class="aig-page-title">
                <img src="<?php echo esc_url( self::image_url( 'plugin-icon.png' ) ); ?>" alt="" class="aig-logo-image" loading="lazy" decoding="async">
                <?php esc_html_e( 'AI Genie', 'ai-genie' ); ?>
            </h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php" id="aig-settings-form">
                <?php settings_fields( 'aig_settings_group' ); ?>

                <!-- ── Summary strip ─────────────────────────────────────── -->
                <div class="aig-summary-strip">
                    <div class="aig-summary-cell aig-summary-cell-default">
                        <span class="aig-summary-label"><?php esc_html_e( 'Default Provider', 'ai-genie' ); ?></span>
                        <span class="aig-summary-value" id="aig-summary-default-provider">
                            <?php echo esc_html( $provider_labels[ $default_provider ] ?? $default_provider ); ?>
                        </span>
                        <span class="aig-summary-model" id="aig-summary-default-model">
                            <?php echo $default_model ? esc_html( '— ' . $default_model ) : ''; ?>
                        </span>
                    </div>
                    <div class="aig-summary-cell aig-summary-badges" role="radiogroup" aria-label="<?php esc_attr_e( 'Default provider', 'ai-genie' ); ?>">
                        <?php foreach ( AIG_Settings::PROVIDERS as $slug ) : ?>
                            <?php $is_selected = $default_provider === $slug; ?>
                            <label class="aig-summary-badge <?php echo $is_selected ? 'is-selected' : ''; ?>" data-summary-provider="<?php echo esc_attr( $slug ); ?>">
                                <input class="screen-reader-text" type="radio"
                                       name="<?php echo esc_attr( $opt ); ?>[default_provider]"
                                       value="<?php echo esc_attr( $slug ); ?>"
                                       <?php checked( $default_provider, $slug ); ?>>
                                <span class="aig-badge-indicator" aria-hidden="true"><?php echo $is_selected ? '⭐' : '●'; ?></span>
                                <span class="aig-summary-badge-label"><?php echo esc_html( $provider_labels[ $slug ] ?? $slug ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="aig-summary-cell aig-summary-cell-control">
                        <label class="aig-summary-label" for="aig-max-output-tokens"><?php esc_html_e( 'Output Tokens', 'ai-genie' ); ?></label>
                        <input type="number" min="100" max="200000" step="50"
                               class="small-text aig-summary-input"
                               id="aig-max-output-tokens"
                               name="<?php echo esc_attr( $opt ); ?>[max_output_tokens]"
                               value="<?php echo esc_attr( $settings['max_output_tokens'] ?? ( $settings['max_tokens'] ?? 15000 ) ); ?>">
                    </div>
                    <div class="aig-summary-cell aig-summary-cell-control">
                        <label class="aig-summary-label" for="aig-max-thinking-tokens"><?php esc_html_e( 'Thinking Tokens', 'ai-genie' ); ?></label>
                        <input type="number" min="0" max="200000" step="50"
                               class="small-text aig-summary-input"
                               id="aig-max-thinking-tokens"
                               name="<?php echo esc_attr( $opt ); ?>[max_thinking_tokens]"
                               value="<?php echo esc_attr( $settings['max_thinking_tokens'] ?? 15000 ); ?>">
                    </div>
                    <div class="aig-summary-cell aig-summary-cell-control">
                        <label class="aig-summary-label" for="aig-temperature"><?php esc_html_e( 'Temp', 'ai-genie' ); ?></label>
                        <input type="number" min="0" max="2" step="0.1"
                               class="small-text aig-summary-input"
                               id="aig-temperature"
                               name="<?php echo esc_attr( $opt ); ?>[temperature]"
                               value="<?php echo esc_attr( $settings['temperature'] ); ?>">
                    </div>
                </div>
                <p class="description aig-summary-help" id="aig-token-limit-hint"><?php esc_html_e( 'These defaults apply to Gutenberg generation unless overridden in the editor Advanced panel. Check your provider documentation for exact token limits and whether thinking tokens share the same cap.', 'ai-genie' ); ?></p>

                <!-- ── Tab navigation ────────────────────────────────────── -->
                <nav class="nav-tab-wrapper aig-tab-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'ai-genie' ); ?>">
                    <a href="#" class="nav-tab" data-tab="providers"><?php esc_html_e( 'Providers', 'ai-genie' ); ?></a>
                    <a href="#" class="nav-tab" data-tab="prompts"><?php esc_html_e( 'Prompts', 'ai-genie' ); ?></a>
                    <a href="#" class="nav-tab" data-tab="ollama-setup"><?php esc_html_e( 'Ollama Setup', 'ai-genie' ); ?></a>
                </nav>

                <!-- ════════════════════════════════════════════════════ -->
                <!-- Tab: Providers                                        -->
                <!-- ════════════════════════════════════════════════════ -->
                <div class="aig-tab-panel" data-panel="providers">

                    <!-- ── Claude ──────────────────────────────────── -->
                    <div class="aig-card aig-provider-section" id="section-claude">
                        <div class="aig-provider-header">
                            <?php echo wp_kses_post( self::provider_icon_markup( 'claude' ) ); ?>
                            <h2><?php esc_html_e( 'Anthropic Claude', 'ai-genie' ); ?></h2>
                            <span class="aig-provider-status" id="status-claude" aria-live="polite"></span>
                        </div>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><?php esc_html_e( 'API Key', 'ai-genie' ); ?></th>
                                <td>
                                    <div class="aig-key-wrap">
                                        <input type="password" class="regular-text aig-api-key-input"
                                               data-provider="claude"
                                               name="<?php echo esc_attr( $opt ); ?>[claude_api_key]"
                                               value="<?php echo esc_attr( $settings['claude_api_key'] ); ?>" autocomplete="off">
                                        <button type="button" class="button aig-key-toggle" aria-label="<?php esc_attr_e( 'Show/hide API key', 'ai-genie' ); ?>" aria-pressed="false" title="<?php esc_attr_e( 'Show/hide API key', 'ai-genie' ); ?>">👁</button>
                                    </div>
                                    <p class="description"><?php esc_html_e( 'Connection is checked automatically as soon as this field has a value. Works on self-hosted and managed WordPress sites when the server can reach the Anthropic API over HTTPS.', 'ai-genie' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Model', 'ai-genie' ); ?></th>
                                <td>
                                    <select class="regular-text aig-model-select"
                                            data-provider="claude"
                                            data-placeholder="<?php esc_attr_e( 'Enter an API key to load models', 'ai-genie' ); ?>"
                                            data-loading-label="<?php esc_attr_e( 'Loading available models…', 'ai-genie' ); ?>"
                                            data-empty-label="<?php esc_attr_e( 'No models returned for this API key', 'ai-genie' ); ?>"
                                            name="<?php echo esc_attr( $opt ); ?>[claude_model]">
                                        <?php if ( ! empty( $settings['claude_api_key'] ) && ! empty( $settings['claude_model'] ) ) : ?>
                                            <option value="<?php echo esc_attr( $settings['claude_model'] ); ?>" selected>
                                                <?php echo esc_html( $settings['claude_model'] ); ?>
                                            </option>
                                        <?php else : ?>
                                            <option value="" selected><?php esc_html_e( 'Enter an API key to load models', 'ai-genie' ); ?></option>
                                        <?php endif; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Available Claude models are loaded automatically from the Anthropic Models API.', 'ai-genie' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ── OpenAI ──────────────────────────────────── -->
                    <div class="aig-card aig-provider-section" id="section-openai">
                        <div class="aig-provider-header">
                            <?php echo wp_kses_post( self::provider_icon_markup( 'openai' ) ); ?>
                            <h2><?php esc_html_e( 'OpenAI', 'ai-genie' ); ?></h2>
                            <span class="aig-provider-status" id="status-openai" aria-live="polite"></span>
                        </div>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><?php esc_html_e( 'API Key', 'ai-genie' ); ?></th>
                                <td>
                                    <div class="aig-key-wrap">
                                        <input type="password" class="regular-text aig-api-key-input"
                                               data-provider="openai"
                                               name="<?php echo esc_attr( $opt ); ?>[openai_api_key]"
                                               value="<?php echo esc_attr( $settings['openai_api_key'] ); ?>" autocomplete="off">
                                        <button type="button" class="button aig-key-toggle" aria-label="<?php esc_attr_e( 'Show/hide API key', 'ai-genie' ); ?>" aria-pressed="false" title="<?php esc_attr_e( 'Show/hide API key', 'ai-genie' ); ?>">👁</button>
                                    </div>
                                    <p class="description"><?php esc_html_e( 'Connection is checked automatically as soon as this field has a value. Works on self-hosted and managed WordPress sites when the server can reach the OpenAI API over HTTPS.', 'ai-genie' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Model', 'ai-genie' ); ?></th>
                                <td>
                                    <select class="regular-text aig-model-select"
                                            data-provider="openai"
                                            data-placeholder="<?php esc_attr_e( 'Enter an API key to load models', 'ai-genie' ); ?>"
                                            data-loading-label="<?php esc_attr_e( 'Loading available models…', 'ai-genie' ); ?>"
                                            data-empty-label="<?php esc_attr_e( 'No models returned for this API key', 'ai-genie' ); ?>"
                                            name="<?php echo esc_attr( $opt ); ?>[openai_model]">
                                        <?php if ( ! empty( $settings['openai_api_key'] ) && ! empty( $settings['openai_model'] ) ) : ?>
                                            <option value="<?php echo esc_attr( $settings['openai_model'] ); ?>" selected>
                                                <?php echo esc_html( $settings['openai_model'] ); ?>
                                            </option>
                                        <?php else : ?>
                                            <option value="" selected><?php esc_html_e( 'Enter an API key to load models', 'ai-genie' ); ?></option>
                                        <?php endif; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Available OpenAI text-generation models are loaded automatically from the Models API.', 'ai-genie' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ── Ollama ──────────────────────────────────── -->
                    <div class="aig-card aig-provider-section" id="section-ollama">
                        <div class="aig-provider-header">
                            <?php echo wp_kses_post( self::provider_icon_markup( 'ollama' ) ); ?>
                            <h2><?php esc_html_e( 'Ollama', 'ai-genie' ); ?></h2>
                            <span class="aig-provider-status" id="status-ollama" aria-live="polite"></span>
                        </div>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><?php esc_html_e( 'Base URL', 'ai-genie' ); ?></th>
                                <td>
                                    <input type="url" class="regular-text aig-base-url-input"
                                           data-provider="ollama"
                                           name="<?php echo esc_attr( $opt ); ?>[ollama_url]"
                                           value="<?php echo esc_attr( $settings['ollama_url'] ); ?>">
                                    <p class="description"><?php esc_html_e( 'Connection is checked automatically as soon as this field has a value.', 'ai-genie' ); ?> <?php esc_html_e( 'Default:', 'ai-genie' ); ?> <code>http://localhost:11434</code>. <?php esc_html_e( 'For managed or cloud-hosted WordPress, use a remote Ollama hostname that the WordPress server can reach, such as a Cloudflare Tunnel hostname.', 'ai-genie' ); ?></p>
                                </td>
                            </tr>
                        </table>

                        <?php
                        $ollama_has_auth = ! empty( $settings['ollama_auth_header_name'] ) || ! empty( $settings['ollama_auth_header_value'] );
                        ?>
                        <details class="aig-ollama-auth-details" <?php echo $ollama_has_auth ? 'open' : ''; ?>>
                            <summary><?php esc_html_e( 'Remote gateway auth (optional)', 'ai-genie' ); ?></summary>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th><?php esc_html_e( 'Header Name', 'ai-genie' ); ?></th>
                                    <td>
                                        <input type="text" class="regular-text aig-ollama-auth-input"
                                               data-provider="ollama"
                                               data-role="header-name"
                                               name="<?php echo esc_attr( $opt ); ?>[ollama_auth_header_name]"
                                               value="<?php echo esc_attr( $settings['ollama_auth_header_name'] ?? '' ); ?>"
                                               placeholder="Authorization"
                                               autocomplete="off">
                                        <p class="description"><?php esc_html_e( 'Optional. The exact header name required by your remote Ollama gateway or Cloudflare Access single-header mode. Leave blank to use Authorization.', 'ai-genie' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Header Value', 'ai-genie' ); ?></th>
                                    <td>
                                        <div class="aig-key-wrap">
                                            <input type="password" class="regular-text aig-ollama-auth-input"
                                                   data-provider="ollama"
                                                   data-role="header-value"
                                                   name="<?php echo esc_attr( $opt ); ?>[ollama_auth_header_value]"
                                                   value="<?php echo esc_attr( $settings['ollama_auth_header_value'] ?? '' ); ?>"
                                                   placeholder='{"cf-access-client-id":"...","cf-access-client-secret":"..."}'
                                                   autocomplete="off">
                                            <button type="button" class="button aig-key-toggle" aria-label="<?php esc_attr_e( 'Show/hide header value', 'ai-genie' ); ?>" aria-pressed="false" title="<?php esc_attr_e( 'Show/hide header value', 'ai-genie' ); ?>">👁</button>
                                        </div>
                                        <p class="description"><?php esc_html_e( 'Optional. Paste the exact header value required by your proxy, gateway, or Cloudflare Access single-header setup.', 'ai-genie' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </details>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th><?php esc_html_e( 'Model', 'ai-genie' ); ?></th>
                                <td>
                                    <select class="regular-text aig-model-select"
                                            data-provider="ollama"
                                            data-placeholder="<?php esc_attr_e( 'Enter a Base URL to load models', 'ai-genie' ); ?>"
                                            data-loading-label="<?php esc_attr_e( 'Loading available models…', 'ai-genie' ); ?>"
                                            data-empty-label="<?php esc_attr_e( 'No models returned by this Ollama server', 'ai-genie' ); ?>"
                                            name="<?php echo esc_attr( $opt ); ?>[ollama_model]">
                                        <?php if ( ! empty( $settings['ollama_url'] ) && ! empty( $settings['ollama_model'] ) ) : ?>
                                            <option value="<?php echo esc_attr( $settings['ollama_model'] ); ?>" selected>
                                                <?php echo esc_html( $settings['ollama_model'] ); ?>
                                            </option>
                                        <?php else : ?>
                                            <option value="" selected><?php esc_html_e( 'Enter a Base URL to load models', 'ai-genie' ); ?></option>
                                        <?php endif; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Available Ollama models are loaded automatically from the Ollama tags API after the base URL is detected and validated.', 'ai-genie' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p class="aig-provider-footer-link">
                            <?php esc_html_e( 'Need to connect Ollama to a remote WordPress site?', 'ai-genie' ); ?>
                            <a href="#" class="aig-tab-link" data-target-tab="ollama-setup"><?php esc_html_e( 'Open Setup Guide →', 'ai-genie' ); ?></a>
                        </p>
                    </div>

                </div><!-- /tab: providers -->

                <!-- ════════════════════════════════════════════════════ -->
                <!-- Tab: Prompts                                          -->
                <!-- ════════════════════════════════════════════════════ -->
                <div class="aig-tab-panel" data-panel="prompts">

                    <div class="aig-card">
                        <h2><?php esc_html_e( 'Prompt Templates', 'ai-genie' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'Edit the default prompt used for each content type. Leave a prompt blank to restore its built-in default on save. The built-in defaults now enforce stricter WordPress-safe formatting rules for headings, paragraphs, lists, tables, links, embeds, media, and other structured output.', 'ai-genie' ); ?>
                        </p>

                        <div class="aig-prompt-layout">

                            <!-- Left rail -->
                            <nav class="aig-prompt-rail" aria-label="<?php esc_attr_e( 'Prompt types', 'ai-genie' ); ?>">
                                <?php
                                $prompt_icons = [
                                    'post_content'     => '✍️',
                                    'seo_title'        => '🏷️',
                                    'meta_description' => '📝',
                                    'excerpt'          => '✂️',
                                ];
                                $first_prompt = true;
                                foreach ( $prompts as $type => $config ) : ?>
                                    <button type="button"
                                            class="aig-prompt-rail-item <?php echo $first_prompt ? 'is-active' : ''; ?>"
                                            data-prompt-type="<?php echo esc_attr( $type ); ?>">
                                        <span class="aig-prompt-rail-icon"><?php echo $prompt_icons[ $type ] ?? '📄'; ?></span>
                                        <span class="aig-prompt-rail-label"><?php echo esc_html( $config['label'] ); ?></span>
                                    </button>
                                    <?php $first_prompt = false; ?>
                                <?php endforeach; ?>
                            </nav>

                            <!-- Right editor pane -->
                            <div class="aig-prompt-editor">
                                <?php
                                $first_prompt = true;
                                foreach ( $prompts as $type => $config ) :
                                    $field_key = AIG_Settings::prompt_setting_key( $type );
                                ?>
                                <div class="aig-prompt-pane <?php echo $first_prompt ? 'is-active' : ''; ?>"
                                     data-prompt-pane="<?php echo esc_attr( $type ); ?>">
                                    <label class="aig-prompt-pane-label" for="aig-prompt-<?php echo esc_attr( $type ); ?>">
                                        <?php echo esc_html( $config['label'] ); ?>
                                    </label>
                                    <textarea
                                        id="aig-prompt-<?php echo esc_attr( $type ); ?>"
                                        class="large-text code aig-prompt-textarea"
                                        rows="18"
                                        name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $field_key ); ?>]"
                                    ><?php echo esc_textarea( $settings[ $field_key ] ?? '' ); ?></textarea>
                                    <p class="description"><?php echo esc_html( $config['description'] ); ?></p>
                                    <div class="aig-placeholder-list" aria-label="<?php esc_attr_e( 'Available prompt placeholders', 'ai-genie' ); ?>">
                                        <?php foreach ( $placeholders as $placeholder ) : ?>
                                            <code><?php echo esc_html( $placeholder ); ?></code>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php $first_prompt = false; endforeach; ?>
                            </div><!-- /.aig-prompt-editor -->

                        </div><!-- /.aig-prompt-layout -->
                    </div>

                </div><!-- /tab: prompts -->

                <!-- ════════════════════════════════════════════════════ -->
                <!-- Tab: Ollama Setup                                     -->
                <!-- ════════════════════════════════════════════════════ -->
                <div class="aig-tab-panel" data-panel="ollama-setup">

                    <div class="aig-card aig-setup-guide" id="aig-ollama-wizard">
                        <h2><?php esc_html_e( 'Ollama Setup Guide', 'ai-genie' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'This guide is written for people starting from zero. Follow it from top to bottom. If a step already matches your setup, skip only that step and continue with the next one.', 'ai-genie' ); ?></p>

                        <div class="aig-guide-nav" aria-label="<?php esc_attr_e( 'Ollama guide sections', 'ai-genie' ); ?>">
                            <a href="#aig-ollama-guide-choose"><?php esc_html_e( '1. Choose the right path', 'ai-genie' ); ?></a>
                            <a href="#aig-ollama-guide-browser"><?php esc_html_e( '2. If you use Playground/browser WordPress', 'ai-genie' ); ?></a>
                            <a href="#aig-ollama-guide-cloudflare"><?php esc_html_e( '3. Create Cloudflare + domain', 'ai-genie' ); ?></a>
                            <a href="#aig-ollama-guide-ollama"><?php esc_html_e( '4. Install Ollama', 'ai-genie' ); ?></a>
                            <a href="#aig-ollama-guide-cloudflared"><?php esc_html_e( '5. Install cloudflared', 'ai-genie' ); ?></a>
                            <a href="#aig-ollama-guide-tunnel"><?php esc_html_e( '6. Create the tunnel', 'ai-genie' ); ?></a>
                            <a href="#aig-ollama-guide-access"><?php esc_html_e( '7. Lock it down with Access', 'ai-genie' ); ?></a>
                            <a href="#aig-ollama-guide-wordpress"><?php esc_html_e( '8. Paste values into WordPress', 'ai-genie' ); ?></a>
                            <a href="#aig-ollama-guide-wsl"><?php esc_html_e( '9. WSL notes', 'ai-genie' ); ?></a>
                        </div>

                    <div class="aig-guide-note">
                        <p><strong><?php esc_html_e( 'Recommended path for cloud-hosted WordPress:', 'ai-genie' ); ?></strong> <?php esc_html_e( 'Cloudflare Tunnel + Cloudflare Access + a single-header service token. That gives you one public hostname to paste here, plus one header name and one header value.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'If you are using the GitHub repository locally, you can run scripts/ollama-cloudflare-wizard.sh. It can verify local Ollama, create the tunnel, DNS record, Access app, service token, Service Auth policy, and single-header mode, then print the exact WordPress values to paste here.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'For the lowest-permission setup, enter ACCOUNT_ID and ZONE_ID manually when the script asks. In that mode, the Cloudflare API token only needs: Cloudflare Tunnel Edit, Access: Apps and Policies Edit, Access: Service Tokens Edit, and DNS Edit. Zone Read is optional and is only needed if you want the script to auto-detect the IDs from your domain name.', 'ai-genie' ); ?></p>
                    </div>

                    <div class="aig-guide-links">
                        <h3><?php esc_html_e( 'Official Links', 'ai-genie' ); ?></h3>
                        <ul>
                            <li><a href="<?php echo esc_url( $cloudflare_signup_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a Cloudflare account', 'ai-genie' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_domain_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Add your domain to Cloudflare', 'ai-genie' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $ollama_download_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download/install Ollama for Linux', 'ai-genie' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_pkg_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Install cloudflared packages', 'ai-genie' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_config_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Cloudflare Tunnel config file reference', 'ai-genie' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_access_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a Cloudflare Access self-hosted app', 'ai-genie' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_tokens_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create Cloudflare service tokens and single-header auth', 'ai-genie' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_workers_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Cloudflare Workers documentation', 'ai-genie' ); ?></a></li>
                        </ul>
                    </div>

                    <details class="aig-guide-detail" id="aig-ollama-guide-choose" open>
                        <summary><?php esc_html_e( '1. Choose the right path before you touch Cloudflare', 'ai-genie' ); ?></summary>
                        <p><?php esc_html_e( 'Use the simple path if WordPress and Ollama are on the same machine, or if your WordPress server can already reach Ollama directly over your private network. In that case, you do not need Cloudflare Tunnel, Cloudflare Access, or the Access Header fields.', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>Base URL: http://localhost:11434
Access Header Name: leave blank
Access Header Value: leave blank</code></pre>
                        <p><?php esc_html_e( 'Use the remote path only when WordPress is hosted somewhere else and cannot reach your local Ollama server directly. That is the case this guide solves.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'Before you continue, choose and write down these four values on paper: your main domain, your Ollama subdomain, your tunnel name, and the Ollama model you want to use. Example: main domain example.com, Ollama subdomain ollama.example.com, tunnel name home-ollama, model llama3.2:3b.', 'ai-genie' ); ?></p>
                    </details>

                    <details class="aig-guide-detail" id="aig-ollama-guide-browser">
                        <summary><?php esc_html_e( '2. If WordPress runs in the browser, use the Worker proxy path after the upstream path works', 'ai-genie' ); ?></summary>
                        <p><?php esc_html_e( 'WordPress Playground and other browser-executed WordPress runtimes should not send the upstream Cloudflare Access Authorization header directly to Ollama. Use the direct upstream path only as the first validation step, then deploy the Worker proxy and paste the Worker proxy values into this plugin.', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>./scripts/create-ollama-worker-proxy.sh</code></pre>
                        <p><?php esc_html_e( 'That script deploys the Worker, creates or reuses the public Worker hostname, writes the Worker secrets, tests browser preflight plus authenticated GET /api/tags, and prints the exact Base URL, Header Name, and Header Value for this plugin. Use those Worker values in WordPress Playground and similar browser-based runtimes.', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>Base URL: https://ollama-proxy.example.com
Access Header Name: X-Ollama-Proxy-Token
Access Header Value: YOUR_LONG_RANDOM_PROXY_TOKEN</code></pre>
                        <p><?php esc_html_e( 'If you are using normal server-hosted WordPress instead of Playground, skip this section and continue with the direct upstream Access path below.', 'ai-genie' ); ?></p>
                    </details>

                    <details class="aig-guide-detail" id="aig-ollama-guide-cloudflare">
                        <summary><?php esc_html_e( '3. Create Cloudflare access to your domain if you do not already have it', 'ai-genie' ); ?></summary>
                        <p><?php esc_html_e( 'If you do not already have a Cloudflare account, open the Cloudflare sign-up page and create one first. Then add your domain to Cloudflare. Cloudflare will show you two nameservers. You must copy those two nameservers into your domain registrar account, where you bought the domain name.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'Do not continue until your domain shows as Active inside Cloudflare. If the domain is not active yet, the hostname for Ollama will not work.', 'ai-genie' ); ?></p>
                        <ol class="aig-step-list">
                            <li><?php esc_html_e( 'Open the Cloudflare sign-up page and create your account.', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'Open Add your domain to Cloudflare and follow the onboarding wizard.', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'At your domain registrar, replace the old nameservers with the two nameservers that Cloudflare gave you.', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'Wait until Cloudflare shows your domain as Active.', 'ai-genie' ); ?></li>
                        </ol>
                        <p><?php esc_html_e( 'If you already use Cloudflare for your domain, you can skip this section.', 'ai-genie' ); ?></p>
                    </details>

                    <details class="aig-guide-detail" id="aig-ollama-guide-ollama">
                        <summary><?php esc_html_e( '4. Install Ollama locally and confirm it works before you add any tunnel', 'ai-genie' ); ?></summary>
                        <p><?php esc_html_e( 'The easiest beginner path on Ubuntu, Debian, or Ubuntu inside WSL is to install Ollama first, pull one model, and test it locally. If this local test fails, the tunnel setup will fail too.', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>curl -fsSL https://ollama.com/install.sh | sh
ollama pull llama3.2:3b
curl http://localhost:11434/api/tags</code></pre>
                        <p><?php esc_html_e( 'If the last command prints JSON, Ollama is responding. If it fails, stop here and fix Ollama first.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'If you are on WSL and systemctl does not work, start Ollama manually in a dedicated Ubuntu terminal and keep that terminal open while you test:', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>ollama serve</code></pre>
                    </details>

                    <details class="aig-guide-detail" id="aig-ollama-guide-cloudflared">
                        <summary><?php esc_html_e( '5. Install cloudflared on the same machine where Ollama is running', 'ai-genie' ); ?></summary>
                        <p><?php esc_html_e( 'Install cloudflared on the same computer, VM, server, or WSL Ubuntu instance that can already reach http://localhost:11434. Keeping Ollama and cloudflared in the same environment is the least confusing setup for beginners.', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>sudo mkdir -p --mode=0755 /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' | sudo tee /etc/apt/sources.list.d/cloudflared.list
sudo apt-get update
sudo apt-get install -y cloudflared jq
cloudflared --version</code></pre>
                        <p><?php esc_html_e( 'The jq package is used later to generate the single-header Access update payload more safely.', 'ai-genie' ); ?></p>
                    </details>

                    <details class="aig-guide-detail" id="aig-ollama-guide-tunnel">
                        <summary><?php esc_html_e( '6. Create the tunnel and publish a dedicated Ollama hostname', 'ai-genie' ); ?></summary>
                        <p><?php esc_html_e( 'The easiest CLI flow is: log into Cloudflare, create a tunnel, attach a DNS hostname to it, and point that hostname at your local Ollama port. Use a hostname dedicated to Ollama only, such as ollama.example.com.', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>cloudflared login
cloudflared tunnel create home-ollama
sudo cloudflared tunnel route dns home-ollama ollama.example.com</code></pre>
                        <p><?php esc_html_e( 'Now create or update /etc/cloudflared/config.yml so that requests for that hostname go to Ollama on localhost. Keep the final catch-all rule at the bottom.', 'ai-genie' ); ?></p>
<pre class="aig-code-block"><code>tunnel: YOUR_TUNNEL_ID
credentials-file: /etc/cloudflared/YOUR_TUNNEL_ID.json

ingress:
  - hostname: ollama.example.com
    service: http://localhost:11434
  - service: http_status:404</code></pre>
                        <p><?php esc_html_e( 'Then start or restart the tunnel service. If you are testing manually instead of as a background service, use the tunnel run command and keep that terminal open.', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>sudo systemctl restart cloudflared
sudo systemctl status cloudflared --no-pager

# Manual test mode if you are not using systemd:
cloudflared tunnel --config /etc/cloudflared/config.yml run</code></pre>
                    </details>

                    <details class="aig-guide-detail" id="aig-ollama-guide-access">
                        <summary><?php esc_html_e( '7. Protect the hostname with Cloudflare Access and convert it to one header', 'ai-genie' ); ?></summary>
                        <p><?php esc_html_e( 'Do not expose the Ollama hostname without protection. In Cloudflare Zero Trust, create a Self-hosted application for your Ollama hostname, add a Service Auth policy, and create one service token. Cloudflare will show you a Client ID and a Client Secret. Save them immediately.', 'ai-genie' ); ?></p>
                        <ol class="aig-step-list">
                            <li><?php esc_html_e( 'Open Cloudflare Zero Trust.', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'Go to Access -> Applications -> Add an application -> Self-hosted.', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'Give the app a clear name such as Ollama API.', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'Set the application domain to your Ollama hostname, for example ollama.example.com.', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'Create a policy with the action Service Auth, then attach your new service token to that policy.', 'ai-genie' ); ?></li>
                        </ol>
                        <p><?php esc_html_e( 'This plugin accepts one header name and one header value. Cloudflare service tokens normally use two headers, so you must switch the Access app to single-header mode once.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'First create a Cloudflare API token with Access app read/write permissions. Then run the commands below, replacing the placeholders. The first command downloads your current Access app config. The second command adds read_service_tokens_from_header. The third command writes the updated config back to Cloudflare.', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>curl "https://api.cloudflare.com/client/v4/accounts/$ACCOUNT_ID/access/apps/$APP_ID" \
  --request GET \
  --header "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  > access-app-response.json

jq '.result | .read_service_tokens_from_header = "Authorization"' \
  access-app-response.json \
  > access-app-update.json

curl "https://api.cloudflare.com/client/v4/accounts/$ACCOUNT_ID/access/apps/$APP_ID" \
  --request PUT \
  --header "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  --header "Content-Type: application/json" \
  --data @access-app-update.json</code></pre>
                        <p><?php esc_html_e( 'After that, your request header will look like this. Replace the placeholders with the service token values Cloudflare gave you.', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>Authorization: {"cf-access-client-id":"YOUR_CLIENT_ID","cf-access-client-secret":"YOUR_CLIENT_SECRET"}</code></pre>
                        <p><?php esc_html_e( 'Test the protected hostname before you return to WordPress:', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>curl \
  -H 'Authorization: {"cf-access-client-id":"YOUR_CLIENT_ID","cf-access-client-secret":"YOUR_CLIENT_SECRET"}' \
  https://ollama.example.com/api/tags</code></pre>
                        <p><?php esc_html_e( 'If this prints JSON, your hostname, tunnel, Access app, and service token are all working together.', 'ai-genie' ); ?></p>
                    </details>

                    <details class="aig-guide-detail" id="aig-ollama-guide-wordpress">
                        <summary><?php esc_html_e( '8. Paste the final values into WordPress', 'ai-genie' ); ?></summary>
                        <p><?php esc_html_e( 'When the direct upstream curl test above works, return to this plugin page and paste exactly these three values into a normal server-hosted WordPress site:', 'ai-genie' ); ?></p>
                        <pre class="aig-code-block"><code>Base URL: https://ollama.example.com
Access Header Name: Authorization
Access Header Value: {"cf-access-client-id":"YOUR_CLIENT_ID","cf-access-client-secret":"YOUR_CLIENT_SECRET"}</code></pre>
                        <p><?php esc_html_e( 'If you are using WordPress Playground or another browser-executed WordPress runtime, do not paste the Authorization JSON here. Run the Worker proxy script instead and paste its X-Ollama-Proxy-Token values.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'The connection check runs automatically. Wait for the green Connected status. Then open the Model dropdown, choose your Ollama model, and click Save Settings.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'If Connected does not appear, do not keep changing random fields in WordPress. Go back one step and re-run the curl test against the protected hostname until that succeeds first.', 'ai-genie' ); ?></p>
                    </details>

                    <details class="aig-guide-detail" id="aig-ollama-guide-wsl">
                        <summary><?php esc_html_e( '9. Extra notes for Ubuntu running inside WSL', 'ai-genie' ); ?></summary>
                        <p><?php esc_html_e( 'The least confusing setup is to run both Ollama and cloudflared inside the same Ubuntu/WSL environment. Avoid splitting them across Windows and WSL unless you already understand the networking difference.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'If systemctl is unavailable in your WSL environment, keep two terminals open: one running ollama serve and another running cloudflared tunnel --config /etc/cloudflared/config.yml run. If you later enable systemd in WSL, you can move both services into systemd.', 'ai-genie' ); ?></p>
                        <p><?php esc_html_e( 'If you reboot Windows or shut down WSL, both services stop. Start them again before testing WordPress.', 'ai-genie' ); ?></p>
                    </details>

                    <div class="aig-guide-note">
                        <p><strong><?php esc_html_e( 'If something fails, test in this exact order:', 'ai-genie' ); ?></strong></p>
                        <ol class="aig-step-list">
                            <li><?php esc_html_e( 'curl http://localhost:11434/api/tags on the Ollama machine', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'curl with the Authorization header against https://your-ollama-hostname/api/tags', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'if you use Playground or another browser-executed WordPress runtime, run ./scripts/create-ollama-worker-proxy.sh and test the Worker hostname next', 'ai-genie' ); ?></li>
                            <li><?php esc_html_e( 'only after the correct upstream or Worker test succeeds, return to WordPress and wait for Connected', 'ai-genie' ); ?></li>
                        </ol>
                    </div>
                </div><!-- /aig-card aig-setup-guide -->

                </div><!-- /tab: ollama-setup -->

                <!-- ── Sticky save footer ──────────────────────────────── -->
                <div class="aig-save-footer" id="aig-save-footer">
                    <span class="aig-save-dirty-notice" id="aig-dirty-notice" aria-live="polite">
                        <?php esc_html_e( 'You have unsaved changes', 'ai-genie' ); ?>
                    </span>
                    <button type="button" class="button" id="aig-discard-btn">
                        <?php esc_html_e( 'Discard Changes', 'ai-genie' ); ?>
                    </button>
                    <input type="submit" name="submit" id="submit" class="button button-primary"
                           value="<?php esc_attr_e( 'Save Settings', 'ai-genie' ); ?>">
                </div>

            </form>
        </div><!-- /wrap -->
        <?php
    }
}
