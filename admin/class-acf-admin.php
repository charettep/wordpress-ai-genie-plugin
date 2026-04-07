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
            'acf-admin',
            ACF_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            ACF_VERSION,
            true
        );
        wp_localize_script( 'acf-admin', 'acfAdmin', [
            'restUrl' => rest_url( ACF_Rest_API::REST_NAMESPACE ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'settings' => ACF_Settings::for_js(),
            'i18n' => [
                'checking'         => __( 'Checking…', 'ai-content-forge' ),
                'connected'        => __( 'Connected', 'ai-content-forge' ),
                'failed'           => __( 'Connection failed', 'ai-content-forge' ),
                'enterApiKey'      => __( 'Enter an API key to load models', 'ai-content-forge' ),
                'enterBaseUrl'     => __( 'Enter a Base URL to load models', 'ai-content-forge' ),
                'loadingModels'    => __( 'Loading available models…', 'ai-content-forge' ),
                'noModels'         => __( 'No models returned for this API key', 'ai-content-forge' ),
                'noOllamaModels'   => __( 'No models returned by this Ollama server', 'ai-content-forge' ),
                'generating'       => __( 'Generating…', 'ai-content-forge' ),
            ],
        ] );
    }

    public static function render_page(): void {
        $settings = ACF_Settings::all();
        $opt      = ACF_Settings::OPTION_KEY;
        $prompts  = [
            'post_content' => [
                'label'       => __( 'Post Content Prompt', 'ai-content-forge' ),
                'description' => __( 'Used for full post or page body generation.', 'ai-content-forge' ),
                'rows'        => 14,
            ],
            'seo_title' => [
                'label'       => __( 'SEO Title Prompt', 'ai-content-forge' ),
                'description' => __( 'Used for generating short SEO title tags.', 'ai-content-forge' ),
                'rows'        => 10,
            ],
            'meta_description' => [
                'label'       => __( 'Meta Description Prompt', 'ai-content-forge' ),
                'description' => __( 'Used for generating meta descriptions.', 'ai-content-forge' ),
                'rows'        => 10,
            ],
            'excerpt' => [
                'label'       => __( 'Excerpt Prompt', 'ai-content-forge' ),
                'description' => __( 'Used for generating short post excerpts.', 'ai-content-forge' ),
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
            '{existing_content}',
            '{existing_content_block}',
        ];
        ?>
        <div class="wrap acf-settings-wrap">
            <h1 class="acf-page-title">
                <span class="acf-logo">⚡</span>
                <?php esc_html_e( 'AI Content Forge', 'ai-content-forge' ); ?>
            </h1>

            <div class="notice notice-info inline">
                <p>
                    <?php esc_html_e( 'Release packages are built for direct installation, update, and activation from the WordPress wp-admin upload flow. OpenAI and Claude work on self-hosted and managed WordPress sites when the server can make outbound HTTPS requests. Ollama works anywhere the WordPress/PHP runtime can reach the configured Ollama endpoint, including remote Ollama servers exposed securely through Cloudflare Tunnel or another authenticated proxy.', 'ai-content-forge' ); ?>
                </p>
            </div>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'acf_settings_group' );
                ?>

                <!-- ── Provider Default ───────────────────────────────── -->
                <div class="acf-card">
                    <h2><?php esc_html_e( 'Default Provider', 'ai-content-forge' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Used when no per-use override is selected.', 'ai-content-forge' ); ?></p>
                    <div class="acf-provider-cards">
                        <?php foreach ( ACF_Settings::PROVIDERS as $slug ) :
                            $checked = checked( $settings['default_provider'], $slug, false );
                            $labels  = [ 'claude' => 'Anthropic Claude', 'openai' => 'OpenAI', 'ollama' => 'Ollama' ];
                            $icons   = [ 'claude' => '🟠', 'openai' => '🟢', 'ollama' => '🔵' ];
                        ?>
                        <label class="acf-provider-card <?php echo $settings['default_provider'] === $slug ? 'selected' : ''; ?>">
                            <input type="radio" name="<?php echo esc_attr( $opt ); ?>[default_provider]"
                                   value="<?php echo esc_attr( $slug ); ?>" <?php echo $checked; ?>>
                            <span class="acf-provider-icon"><?php echo $icons[ $slug ]; ?></span>
                            <span class="acf-provider-name"><?php echo esc_html( $labels[ $slug ] ); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ── Claude ─────────────────────────────────────────── -->
                <div class="acf-card acf-provider-section" id="section-claude">
                    <div class="acf-provider-header">
                        <h2>🟠 <?php esc_html_e( 'Anthropic Claude', 'ai-content-forge' ); ?></h2>
                        <span class="acf-provider-status" id="status-claude" aria-live="polite"></span>
                    </div>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php esc_html_e( 'API Key', 'ai-content-forge' ); ?></th>
                            <td>
                                <input type="password" class="regular-text acf-api-key-input"
                                       data-provider="claude"
                                       name="<?php echo esc_attr( $opt ); ?>[claude_api_key]"
                                       value="<?php echo esc_attr( $settings['claude_api_key'] ); ?>" autocomplete="off">
                                <p class="description"><?php esc_html_e( 'Connection is checked automatically as soon as this field has a value. Works on self-hosted and managed WordPress sites when the server can reach the Anthropic API over HTTPS.', 'ai-content-forge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Model', 'ai-content-forge' ); ?></th>
                            <td>
                                <select class="regular-text acf-model-select"
                                        data-provider="claude"
                                        data-placeholder="<?php esc_attr_e( 'Enter an API key to load models', 'ai-content-forge' ); ?>"
                                        data-loading-label="<?php esc_attr_e( 'Loading available models…', 'ai-content-forge' ); ?>"
                                        data-empty-label="<?php esc_attr_e( 'No models returned for this API key', 'ai-content-forge' ); ?>"
                                        name="<?php echo esc_attr( $opt ); ?>[claude_model]">
                                    <?php if ( ! empty( $settings['claude_api_key'] ) && ! empty( $settings['claude_model'] ) ) : ?>
                                        <option value="<?php echo esc_attr( $settings['claude_model'] ); ?>" selected>
                                            <?php echo esc_html( $settings['claude_model'] ); ?>
                                        </option>
                                    <?php else : ?>
                                        <option value="" selected><?php esc_html_e( 'Enter an API key to load models', 'ai-content-forge' ); ?></option>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Available Claude models are loaded automatically from the Anthropic Models API.', 'ai-content-forge' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── OpenAI ─────────────────────────────────────────── -->
                <div class="acf-card acf-provider-section" id="section-openai">
                    <div class="acf-provider-header">
                        <h2>🟢 <?php esc_html_e( 'OpenAI', 'ai-content-forge' ); ?></h2>
                        <span class="acf-provider-status" id="status-openai" aria-live="polite"></span>
                    </div>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php esc_html_e( 'API Key', 'ai-content-forge' ); ?></th>
                            <td>
                                <input type="password" class="regular-text acf-api-key-input"
                                       data-provider="openai"
                                       name="<?php echo esc_attr( $opt ); ?>[openai_api_key]"
                                       value="<?php echo esc_attr( $settings['openai_api_key'] ); ?>" autocomplete="off">
                                <p class="description"><?php esc_html_e( 'Connection is checked automatically as soon as this field has a value. Works on self-hosted and managed WordPress sites when the server can reach the OpenAI API over HTTPS.', 'ai-content-forge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Model', 'ai-content-forge' ); ?></th>
                            <td>
                                <select class="regular-text acf-model-select"
                                        data-provider="openai"
                                        data-placeholder="<?php esc_attr_e( 'Enter an API key to load models', 'ai-content-forge' ); ?>"
                                        data-loading-label="<?php esc_attr_e( 'Loading available models…', 'ai-content-forge' ); ?>"
                                        data-empty-label="<?php esc_attr_e( 'No models returned for this API key', 'ai-content-forge' ); ?>"
                                        name="<?php echo esc_attr( $opt ); ?>[openai_model]">
                                    <?php if ( ! empty( $settings['openai_api_key'] ) && ! empty( $settings['openai_model'] ) ) : ?>
                                        <option value="<?php echo esc_attr( $settings['openai_model'] ); ?>" selected>
                                            <?php echo esc_html( $settings['openai_model'] ); ?>
                                        </option>
                                    <?php else : ?>
                                        <option value="" selected><?php esc_html_e( 'Enter an API key to load models', 'ai-content-forge' ); ?></option>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Available OpenAI text-generation models are loaded automatically from the Models API.', 'ai-content-forge' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Ollama ─────────────────────────────────────────── -->
                <div class="acf-card acf-provider-section" id="section-ollama">
                    <div class="acf-provider-header">
                        <h2>🔵 <?php esc_html_e( 'Ollama', 'ai-content-forge' ); ?></h2>
                        <span class="acf-provider-status" id="status-ollama" aria-live="polite"></span>
                    </div>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php esc_html_e( 'Base URL', 'ai-content-forge' ); ?></th>
                            <td>
                                <input type="url" class="regular-text acf-base-url-input"
                                       data-provider="ollama"
                                       name="<?php echo esc_attr( $opt ); ?>[ollama_url]"
                                       value="<?php echo esc_attr( $settings['ollama_url'] ); ?>">
                                <p class="description"><?php esc_html_e( 'Connection is checked automatically as soon as this field has a value.', 'ai-content-forge' ); ?> <?php esc_html_e( 'Default:', 'ai-content-forge' ); ?> <code>http://localhost:11434</code>. <?php esc_html_e( 'For managed or cloud-hosted WordPress, use a remote Ollama hostname that the WordPress server can reach, such as a Cloudflare Tunnel hostname.', 'ai-content-forge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Access Header Name', 'ai-content-forge' ); ?></th>
                            <td>
                                <input type="text" class="regular-text acf-ollama-auth-input"
                                       data-provider="ollama"
                                       data-role="header-name"
                                       name="<?php echo esc_attr( $opt ); ?>[ollama_auth_header_name]"
                                       value="<?php echo esc_attr( $settings['ollama_auth_header_name'] ?? '' ); ?>"
                                       placeholder="Authorization"
                                       autocomplete="off">
                                <p class="description"><?php esc_html_e( 'Optional. Paste the exact header name required by your remote Ollama gateway. For Cloudflare Access single-header mode, copy the header name exactly as Cloudflare shows it. If you leave this blank but provide a value below, the plugin will use Authorization.', 'ai-content-forge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Access Header Value', 'ai-content-forge' ); ?></th>
                            <td>
                                <input type="password" class="regular-text acf-ollama-auth-input"
                                       data-provider="ollama"
                                       data-role="header-value"
                                       name="<?php echo esc_attr( $opt ); ?>[ollama_auth_header_value]"
                                       value="<?php echo esc_attr( $settings['ollama_auth_header_value'] ?? '' ); ?>"
                                       placeholder='{"cf-access-client-id":"...","cf-access-client-secret":"..."}'
                                       autocomplete="off">
                                <p class="description"><?php esc_html_e( 'Optional. Paste the exact header value required by your proxy, gateway, or Cloudflare Access single-header setup. The plugin sends this value on Ollama model discovery, generation, streaming, and stop requests.', 'ai-content-forge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Model', 'ai-content-forge' ); ?></th>
                            <td>
                                <select class="regular-text acf-model-select"
                                        data-provider="ollama"
                                        data-placeholder="<?php esc_attr_e( 'Enter a Base URL to load models', 'ai-content-forge' ); ?>"
                                        data-loading-label="<?php esc_attr_e( 'Loading available models…', 'ai-content-forge' ); ?>"
                                        data-empty-label="<?php esc_attr_e( 'No models returned by this Ollama server', 'ai-content-forge' ); ?>"
                                        name="<?php echo esc_attr( $opt ); ?>[ollama_model]">
                                    <?php if ( ! empty( $settings['ollama_url'] ) && ! empty( $settings['ollama_model'] ) ) : ?>
                                        <option value="<?php echo esc_attr( $settings['ollama_model'] ); ?>" selected>
                                            <?php echo esc_html( $settings['ollama_model'] ); ?>
                                        </option>
                                    <?php else : ?>
                                        <option value="" selected><?php esc_html_e( 'Enter a Base URL to load models', 'ai-content-forge' ); ?></option>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Available Ollama models are loaded automatically from the Ollama tags API after the base URL is detected and validated.', 'ai-content-forge' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="acf-card acf-setup-guide">
                    <h2><?php esc_html_e( 'Ollama Setup Wizard', 'ai-content-forge' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Use these steps if Ollama runs on your computer, NAS, or home server while WordPress is hosted somewhere else.', 'ai-content-forge' ); ?></p>

                    <ol class="acf-step-list">
                        <li>
                            <strong><?php esc_html_e( 'Choose the simple path first.', 'ai-content-forge' ); ?></strong>
                            <p><?php esc_html_e( 'If WordPress and Ollama are on the same machine or private network, enter the directly reachable Ollama URL such as http://localhost:11434 or a LAN URL and leave the access header fields blank.', 'ai-content-forge' ); ?></p>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'For cloud-hosted WordPress, create a dedicated Ollama hostname through Cloudflare Tunnel.', 'ai-content-forge' ); ?></strong>
                            <p><?php esc_html_e( 'Use a hostname such as ollama.example.com that points only to your Ollama service, not to your main website.', 'ai-content-forge' ); ?></p>
                            <pre class="acf-code-block"><code>sudo cloudflared tunnel route dns YOUR_TUNNEL_NAME ollama.example.com</code></pre>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Add an ingress rule that forwards that hostname to your local Ollama server.', 'ai-content-forge' ); ?></strong>
                            <p><?php esc_html_e( 'In /etc/cloudflared/config.yml, send the new hostname to http://localhost:11434 and keep your final http_status:404 catch-all rule at the end.', 'ai-content-forge' ); ?></p>
<pre class="acf-code-block"><code>tunnel: YOUR_TUNNEL_ID
credentials-file: /etc/cloudflared/YOUR_TUNNEL_ID.json

ingress:
  - hostname: ollama.example.com
    service: http://localhost:11434
  - service: http_status:404</code></pre>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Protect the hostname with Cloudflare Access before using it in WordPress.', 'ai-content-forge' ); ?></strong>
                            <p><?php esc_html_e( 'In Cloudflare Zero Trust, create a Self-hosted application for the Ollama hostname, add a Service Auth policy, create a service token, and enable single-header mode for that application.', 'ai-content-forge' ); ?></p>
                            <p><?php esc_html_e( 'Cloudflare will then show one header name and one header value. Copy both exactly.', 'ai-content-forge' ); ?></p>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Paste the Cloudflare values into this plugin.', 'ai-content-forge' ); ?></strong>
                            <p><?php esc_html_e( 'In the Ollama section above, set Base URL to the public tunnel hostname, Access Header Name to the exact Cloudflare header name, and Access Header Value to the exact Cloudflare single-header value.', 'ai-content-forge' ); ?></p>
                            <pre class="acf-code-block"><code>Base URL: https://ollama.example.com
Access Header Name: Authorization
Access Header Value: {"cf-access-client-id":"...","cf-access-client-secret":"..."}</code></pre>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'Wait for the connection check to turn green, then choose your Ollama model.', 'ai-content-forge' ); ?></strong>
                            <p><?php esc_html_e( 'As soon as the plugin can reach /api/tags through your protected hostname, the header above will show Connected and the model dropdown will populate automatically.', 'ai-content-forge' ); ?></p>
                        </li>
                        <li>
                            <strong><?php esc_html_e( 'If the connection fails, check the path in this order.', 'ai-content-forge' ); ?></strong>
                            <p><?php esc_html_e( 'First confirm Ollama works locally, then confirm the tunnel hostname reaches Ollama, then confirm Cloudflare Access is accepting the service token, and finally confirm your WordPress host allows outbound HTTPS requests to that hostname.', 'ai-content-forge' ); ?></p>
                        </li>
                    </ol>

                    <p class="acf-guide-note"><?php esc_html_e( 'This plugin currently supports one optional Ollama access header, which is enough for Cloudflare Access single-header mode and many simple authenticated reverse proxies.', 'ai-content-forge' ); ?></p>
                </div>

                <!-- ── Generation defaults ────────────────────────────── -->
                <div class="acf-card">
                    <h2><?php esc_html_e( 'Generation Defaults', 'ai-content-forge' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php esc_html_e( 'Max Output Tokens', 'ai-content-forge' ); ?></th>
                            <td>
                                <input type="number" min="100" max="200000" step="50"
                                       id="acf-max-output-tokens"
                                       name="<?php echo esc_attr( $opt ); ?>[max_output_tokens]"
                                       value="<?php echo esc_attr( $settings['max_output_tokens'] ?? ( $settings['max_tokens'] ?? 1500 ) ); ?>">
                                <p class="description" id="acf-token-limit-hint"></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Max Thinking Tokens', 'ai-content-forge' ); ?></th>
                            <td>
                                <input type="number" min="0" max="200000" step="50"
                                       id="acf-max-thinking-tokens"
                                       name="<?php echo esc_attr( $opt ); ?>[max_thinking_tokens]"
                                       value="<?php echo esc_attr( $settings['max_thinking_tokens'] ?? 0 ); ?>">
                                <p class="description">
                                    <?php esc_html_e( 'Used only for reasoning-capable models. Anthropic maps this to thinking.budget_tokens, OpenAI folds it into the total response token cap and reasoning effort, and Ollama uses it to enable thinking plus expand the shared generation budget.', 'ai-content-forge' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Temperature', 'ai-content-forge' ); ?></th>
                            <td>
                                <input type="number" min="0" max="2" step="0.1"
                                       name="<?php echo esc_attr( $opt ); ?>[temperature]"
                                       value="<?php echo esc_attr( $settings['temperature'] ); ?>">
                                <p class="description"><?php esc_html_e( '0 = deterministic, 1 = creative, 2 = chaotic', 'ai-content-forge' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="acf-card">
                    <h2><?php esc_html_e( 'Prompt Templates', 'ai-content-forge' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Edit the default prompt used for each content type. Leave a prompt blank to restore its built-in default on save.', 'ai-content-forge' ); ?>
                    </p>
                    <div class="acf-placeholder-list" aria-label="<?php esc_attr_e( 'Available prompt placeholders', 'ai-content-forge' ); ?>">
                        <?php foreach ( $placeholders as $placeholder ) : ?>
                            <code><?php echo esc_html( $placeholder ); ?></code>
                        <?php endforeach; ?>
                    </div>

                    <div class="acf-prompt-stack">
                        <?php foreach ( $prompts as $type => $config ) : ?>
                            <?php $field_key = ACF_Settings::prompt_setting_key( $type ); ?>
                            <div class="acf-prompt-field">
                                <label for="acf-prompt-<?php echo esc_attr( $type ); ?>">
                                    <?php echo esc_html( $config['label'] ); ?>
                                </label>
                                <textarea
                                    id="acf-prompt-<?php echo esc_attr( $type ); ?>"
                                    class="large-text code"
                                    rows="<?php echo esc_attr( (string) $config['rows'] ); ?>"
                                    name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $field_key ); ?>]"
                                ><?php echo esc_textarea( $settings[ $field_key ] ?? '' ); ?></textarea>
                                <p class="description"><?php echo esc_html( $config['description'] ); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php submit_button( __( 'Save Settings', 'ai-content-forge' ) ); ?>
            </form>
        </div>
        <?php
    }
}
