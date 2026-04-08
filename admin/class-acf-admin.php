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
        $cloudflare_signup_url      = 'https://dash.cloudflare.com/sign-up';
        $cloudflare_domain_url      = 'https://developers.cloudflare.com/fundamentals/manage-domains/add-site/';
        $cloudflare_zero_trust_url  = 'https://one.dash.cloudflare.com/';
        $cloudflare_pkg_url         = 'https://pkg.cloudflare.com/';
        $cloudflare_config_url      = 'https://developers.cloudflare.com/tunnel/advanced/local-management/configuration-file/';
        $cloudflare_access_url      = 'https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-apps/';
        $cloudflare_tokens_url      = 'https://developers.cloudflare.com/cloudflare-one/access-controls/service-credentials/service-tokens/';
        $ollama_download_url        = 'https://ollama.com/download/linux';
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

                <div class="acf-card acf-setup-guide" id="acf-ollama-wizard">
                    <h2><?php esc_html_e( 'Ollama Setup Wizard', 'ai-content-forge' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'This guide is written for people starting from zero. Follow it from top to bottom. If a step already matches your setup, skip only that step and continue with the next one.', 'ai-content-forge' ); ?></p>

                    <div class="acf-guide-nav" aria-label="<?php esc_attr_e( 'Ollama guide sections', 'ai-content-forge' ); ?>">
                        <a href="#acf-ollama-guide-choose"><?php esc_html_e( '1. Choose the right path', 'ai-content-forge' ); ?></a>
                        <a href="#acf-ollama-guide-cloudflare"><?php esc_html_e( '2. Create Cloudflare + domain', 'ai-content-forge' ); ?></a>
                        <a href="#acf-ollama-guide-ollama"><?php esc_html_e( '3. Install Ollama', 'ai-content-forge' ); ?></a>
                        <a href="#acf-ollama-guide-cloudflared"><?php esc_html_e( '4. Install cloudflared', 'ai-content-forge' ); ?></a>
                        <a href="#acf-ollama-guide-tunnel"><?php esc_html_e( '5. Create the tunnel', 'ai-content-forge' ); ?></a>
                        <a href="#acf-ollama-guide-access"><?php esc_html_e( '6. Lock it down with Access', 'ai-content-forge' ); ?></a>
                        <a href="#acf-ollama-guide-wordpress"><?php esc_html_e( '7. Paste values into WordPress', 'ai-content-forge' ); ?></a>
                        <a href="#acf-ollama-guide-wsl"><?php esc_html_e( '8. WSL notes', 'ai-content-forge' ); ?></a>
                    </div>

                    <div class="acf-guide-note">
                        <p><strong><?php esc_html_e( 'Recommended path for cloud-hosted WordPress:', 'ai-content-forge' ); ?></strong> <?php esc_html_e( 'Cloudflare Tunnel + Cloudflare Access + a single-header service token. That gives you one public hostname to paste here, plus one header name and one header value.', 'ai-content-forge' ); ?></p>
                        <p><?php esc_html_e( 'If you are using the GitHub repository locally, you can run scripts/ollama-cloudflare-wizard.sh. It can verify local Ollama, create the tunnel, DNS record, Access app, service token, Service Auth policy, and single-header mode, then print the exact WordPress values to paste here.', 'ai-content-forge' ); ?></p>
                        <p><?php esc_html_e( 'For full automation, the script needs a Cloudflare API token with: Cloudflare Tunnel Edit, Access: Apps and Policies Edit, Access: Service Tokens Edit, DNS Edit, and Zone Read.', 'ai-content-forge' ); ?></p>
                    </div>

                    <div class="acf-guide-links">
                        <h3><?php esc_html_e( 'Official Links', 'ai-content-forge' ); ?></h3>
                        <ul>
                            <li><a href="<?php echo esc_url( $cloudflare_signup_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a Cloudflare account', 'ai-content-forge' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_domain_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Add your domain to Cloudflare', 'ai-content-forge' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $ollama_download_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download/install Ollama for Linux', 'ai-content-forge' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_pkg_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Install cloudflared packages', 'ai-content-forge' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_config_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Cloudflare Tunnel config file reference', 'ai-content-forge' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_access_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a Cloudflare Access self-hosted app', 'ai-content-forge' ); ?></a></li>
                            <li><a href="<?php echo esc_url( $cloudflare_tokens_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create Cloudflare service tokens and single-header auth', 'ai-content-forge' ); ?></a></li>
                        </ul>
                    </div>

                    <details class="acf-guide-detail" id="acf-ollama-guide-choose" open>
                        <summary><?php esc_html_e( '1. Choose the right path before you touch Cloudflare', 'ai-content-forge' ); ?></summary>
                        <p><?php esc_html_e( 'Use the simple path if WordPress and Ollama are on the same machine, or if your WordPress server can already reach Ollama directly over your private network. In that case, you do not need Cloudflare Tunnel, Cloudflare Access, or the Access Header fields.', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>Base URL: http://localhost:11434
Access Header Name: leave blank
Access Header Value: leave blank</code></pre>
                        <p><?php esc_html_e( 'Use the remote path only when WordPress is hosted somewhere else and cannot reach your local Ollama server directly. That is the case this guide solves.', 'ai-content-forge' ); ?></p>
                        <p><?php esc_html_e( 'Before you continue, choose and write down these four values on paper: your main domain, your Ollama subdomain, your tunnel name, and the Ollama model you want to use. Example: main domain example.com, Ollama subdomain ollama.example.com, tunnel name home-ollama, model llama3.2:3b.', 'ai-content-forge' ); ?></p>
                    </details>

                    <details class="acf-guide-detail" id="acf-ollama-guide-cloudflare">
                        <summary><?php esc_html_e( '2. Create Cloudflare access to your domain if you do not already have it', 'ai-content-forge' ); ?></summary>
                        <p><?php esc_html_e( 'If you do not already have a Cloudflare account, open the Cloudflare sign-up page and create one first. Then add your domain to Cloudflare. Cloudflare will show you two nameservers. You must copy those two nameservers into your domain registrar account, where you bought the domain name.', 'ai-content-forge' ); ?></p>
                        <p><?php esc_html_e( 'Do not continue until your domain shows as Active inside Cloudflare. If the domain is not active yet, the hostname for Ollama will not work.', 'ai-content-forge' ); ?></p>
                        <ol class="acf-step-list">
                            <li><?php esc_html_e( 'Open the Cloudflare sign-up page and create your account.', 'ai-content-forge' ); ?></li>
                            <li><?php esc_html_e( 'Open Add your domain to Cloudflare and follow the onboarding wizard.', 'ai-content-forge' ); ?></li>
                            <li><?php esc_html_e( 'At your domain registrar, replace the old nameservers with the two nameservers that Cloudflare gave you.', 'ai-content-forge' ); ?></li>
                            <li><?php esc_html_e( 'Wait until Cloudflare shows your domain as Active.', 'ai-content-forge' ); ?></li>
                        </ol>
                        <p><?php esc_html_e( 'If you already use Cloudflare for your domain, you can skip this section.', 'ai-content-forge' ); ?></p>
                    </details>

                    <details class="acf-guide-detail" id="acf-ollama-guide-ollama">
                        <summary><?php esc_html_e( '3. Install Ollama locally and confirm it works before you add any tunnel', 'ai-content-forge' ); ?></summary>
                        <p><?php esc_html_e( 'The easiest beginner path on Ubuntu, Debian, or Ubuntu inside WSL is to install Ollama first, pull one model, and test it locally. If this local test fails, the tunnel setup will fail too.', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>curl -fsSL https://ollama.com/install.sh | sh
ollama pull llama3.2:3b
curl http://localhost:11434/api/tags</code></pre>
                        <p><?php esc_html_e( 'If the last command prints JSON, Ollama is responding. If it fails, stop here and fix Ollama first.', 'ai-content-forge' ); ?></p>
                        <p><?php esc_html_e( 'If you are on WSL and systemctl does not work, start Ollama manually in a dedicated Ubuntu terminal and keep that terminal open while you test:', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>ollama serve</code></pre>
                    </details>

                    <details class="acf-guide-detail" id="acf-ollama-guide-cloudflared">
                        <summary><?php esc_html_e( '4. Install cloudflared on the same machine where Ollama is running', 'ai-content-forge' ); ?></summary>
                        <p><?php esc_html_e( 'Install cloudflared on the same computer, VM, server, or WSL Ubuntu instance that can already reach http://localhost:11434. Keeping Ollama and cloudflared in the same environment is the least confusing setup for beginners.', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>sudo mkdir -p --mode=0755 /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' | sudo tee /etc/apt/sources.list.d/cloudflared.list
sudo apt-get update
sudo apt-get install -y cloudflared jq
cloudflared --version</code></pre>
                        <p><?php esc_html_e( 'The jq package is used later to generate the single-header Access update payload more safely.', 'ai-content-forge' ); ?></p>
                    </details>

                    <details class="acf-guide-detail" id="acf-ollama-guide-tunnel">
                        <summary><?php esc_html_e( '5. Create the tunnel and publish a dedicated Ollama hostname', 'ai-content-forge' ); ?></summary>
                        <p><?php esc_html_e( 'The easiest CLI flow is: log into Cloudflare, create a tunnel, attach a DNS hostname to it, and point that hostname at your local Ollama port. Use a hostname dedicated to Ollama only, such as ollama.example.com.', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>cloudflared login
cloudflared tunnel create home-ollama
sudo cloudflared tunnel route dns home-ollama ollama.example.com</code></pre>
                        <p><?php esc_html_e( 'Now create or update /etc/cloudflared/config.yml so that requests for that hostname go to Ollama on localhost. Keep the final catch-all rule at the bottom.', 'ai-content-forge' ); ?></p>
<pre class="acf-code-block"><code>tunnel: YOUR_TUNNEL_ID
credentials-file: /etc/cloudflared/YOUR_TUNNEL_ID.json

ingress:
  - hostname: ollama.example.com
    service: http://localhost:11434
  - service: http_status:404</code></pre>
                        <p><?php esc_html_e( 'Then start or restart the tunnel service. If you are testing manually instead of as a background service, use the tunnel run command and keep that terminal open.', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>sudo systemctl restart cloudflared
sudo systemctl status cloudflared --no-pager

# Manual test mode if you are not using systemd:
cloudflared tunnel --config /etc/cloudflared/config.yml run</code></pre>
                    </details>

                    <details class="acf-guide-detail" id="acf-ollama-guide-access">
                        <summary><?php esc_html_e( '6. Protect the hostname with Cloudflare Access and convert it to one header', 'ai-content-forge' ); ?></summary>
                        <p><?php esc_html_e( 'Do not expose the Ollama hostname without protection. In Cloudflare Zero Trust, create a Self-hosted application for your Ollama hostname, add a Service Auth policy, and create one service token. Cloudflare will show you a Client ID and a Client Secret. Save them immediately.', 'ai-content-forge' ); ?></p>
                        <ol class="acf-step-list">
                            <li><?php esc_html_e( 'Open Cloudflare Zero Trust.', 'ai-content-forge' ); ?></li>
                            <li><?php esc_html_e( 'Go to Access -> Applications -> Add an application -> Self-hosted.', 'ai-content-forge' ); ?></li>
                            <li><?php esc_html_e( 'Give the app a clear name such as Ollama API.', 'ai-content-forge' ); ?></li>
                            <li><?php esc_html_e( 'Set the application domain to your Ollama hostname, for example ollama.example.com.', 'ai-content-forge' ); ?></li>
                            <li><?php esc_html_e( 'Create a policy with the action Service Auth, then attach your new service token to that policy.', 'ai-content-forge' ); ?></li>
                        </ol>
                        <p><?php esc_html_e( 'This plugin accepts one header name and one header value. Cloudflare service tokens normally use two headers, so you must switch the Access app to single-header mode once.', 'ai-content-forge' ); ?></p>
                        <p><?php esc_html_e( 'First create a Cloudflare API token with Access app read/write permissions. Then run the commands below, replacing the placeholders. The first command downloads your current Access app config. The second command adds read_service_tokens_from_header. The third command writes the updated config back to Cloudflare.', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>curl "https://api.cloudflare.com/client/v4/accounts/$ACCOUNT_ID/access/apps/$APP_ID" \
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
                        <p><?php esc_html_e( 'After that, your request header will look like this. Replace the placeholders with the service token values Cloudflare gave you.', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>Authorization: {"cf-access-client-id":"YOUR_CLIENT_ID","cf-access-client-secret":"YOUR_CLIENT_SECRET"}</code></pre>
                        <p><?php esc_html_e( 'Test the protected hostname before you return to WordPress:', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>curl \
  -H 'Authorization: {"cf-access-client-id":"YOUR_CLIENT_ID","cf-access-client-secret":"YOUR_CLIENT_SECRET"}' \
  https://ollama.example.com/api/tags</code></pre>
                        <p><?php esc_html_e( 'If this prints JSON, your hostname, tunnel, Access app, and service token are all working together.', 'ai-content-forge' ); ?></p>
                    </details>

                    <details class="acf-guide-detail" id="acf-ollama-guide-wordpress">
                        <summary><?php esc_html_e( '7. Paste the final values into WordPress', 'ai-content-forge' ); ?></summary>
                        <p><?php esc_html_e( 'When the curl test above works, return to this plugin page and paste exactly these three values:', 'ai-content-forge' ); ?></p>
                        <pre class="acf-code-block"><code>Base URL: https://ollama.example.com
Access Header Name: Authorization
Access Header Value: {"cf-access-client-id":"YOUR_CLIENT_ID","cf-access-client-secret":"YOUR_CLIENT_SECRET"}</code></pre>
                        <p><?php esc_html_e( 'The connection check runs automatically. Wait for the green Connected status. Then open the Model dropdown, choose your Ollama model, and click Save Settings.', 'ai-content-forge' ); ?></p>
                        <p><?php esc_html_e( 'If Connected does not appear, do not keep changing random fields in WordPress. Go back one step and re-run the curl test against the protected hostname until that succeeds first.', 'ai-content-forge' ); ?></p>
                    </details>

                    <details class="acf-guide-detail" id="acf-ollama-guide-wsl">
                        <summary><?php esc_html_e( '8. Extra notes for Ubuntu running inside WSL', 'ai-content-forge' ); ?></summary>
                        <p><?php esc_html_e( 'The least confusing setup is to run both Ollama and cloudflared inside the same Ubuntu/WSL environment. Avoid splitting them across Windows and WSL unless you already understand the networking difference.', 'ai-content-forge' ); ?></p>
                        <p><?php esc_html_e( 'If systemctl is unavailable in your WSL environment, keep two terminals open: one running ollama serve and another running cloudflared tunnel --config /etc/cloudflared/config.yml run. If you later enable systemd in WSL, you can move both services into systemd.', 'ai-content-forge' ); ?></p>
                        <p><?php esc_html_e( 'If you reboot Windows or shut down WSL, both services stop. Start them again before testing WordPress.', 'ai-content-forge' ); ?></p>
                    </details>

                    <div class="acf-guide-note">
                        <p><strong><?php esc_html_e( 'If something fails, test in this exact order:', 'ai-content-forge' ); ?></strong></p>
                        <ol class="acf-step-list">
                            <li><?php esc_html_e( 'curl http://localhost:11434/api/tags on the Ollama machine', 'ai-content-forge' ); ?></li>
                            <li><?php esc_html_e( 'curl with the Authorization header against https://your-ollama-hostname/api/tags', 'ai-content-forge' ); ?></li>
                            <li><?php esc_html_e( 'only after both curl tests succeed, return to WordPress and wait for Connected', 'ai-content-forge' ); ?></li>
                        </ol>
                    </div>
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
