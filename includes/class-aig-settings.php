<?php
defined( 'ABSPATH' ) || exit;

class AIG_Settings {

    const OPTION_KEY = 'aig_settings';

    const PROVIDERS = [ 'claude', 'openai', 'ollama' ];

    private static array $cache = [];

    public static function defaults(): array {
        $defaults = [
            'default_provider'  => 'claude',
            'claude_api_key'    => '',
            'claude_model'      => '',
            'openai_api_key'    => '',
            'openai_model'      => '',
            'ollama_url'        => 'http://localhost:11434',
            'ollama_auth_header_name'  => '',
            'ollama_auth_header_value' => '',
            'ollama_model'      => '',
            'max_output_tokens' => 15000,
            'max_thinking_tokens' => 15000,
            'max_tokens'        => 15000,
            'temperature'       => 0.7,
        ];

        foreach ( self::prompt_defaults() as $type => $template ) {
            $defaults[ self::prompt_setting_key( $type ) ] = $template;
        }

        return $defaults;
    }

    public static function prompt_setting_key( string $type ): string {
        return 'prompt_' . $type;
    }

    public static function prompt_defaults(): array {
        return [
            'post_content' => <<<PROMPT
You are an expert WordPress content writer. Write a complete, publication-ready WordPress {post_type} in {language}.

Title: {title}
Tone: {tone}
{keywords_line}
{structure_line}
{target_length_line}
{existing_content_block}

Requirements:
- Return only WordPress-compatible output. Do not add commentary, notes, markdown fences, YAML, or explanations.
- Default to clean HTML that the WordPress block editor can safely ingest as post content.
- Do NOT include the post title as an <h1>. WordPress outputs the main title separately.
- Use a strict heading hierarchy with <h2> for main sections, <h3> for subsections, and <h4> only when truly necessary.
- Wrap normal body copy in <p> tags. Keep paragraphs readable and moderately short.
- Use only WordPress-safe inline formatting: <strong>, <em>, <code>, <sup>, <sub>, <mark>, <small>.
- Use lists only with valid <ul>, <ol>, and <li> markup. Do not fake lists with hyphens.
- Use tables only when the content clearly benefits from tabular comparison. If needed, output valid <table>, <thead>, <tbody>, <tr>, <th>, and <td> markup.
- Use hyperlinks only when useful. Every link must use descriptive anchor text and a valid <a href="..."> tag.
- For inline code examples, use <code>. For multi-line code examples, use <pre><code>...</code></pre> and keep them short.
- For images, videos, embeds, iframes, buttons, columns, accordions, page breaks, footnotes, or advanced Gutenberg patterns: include them only if the topic genuinely requires them or the prompt clearly calls for them.
- If you include an image, use WordPress-safe markup such as <figure class="wp-block-image"><img ... /><figcaption>...</figcaption></figure>.
- If you include a video or external embed, use WordPress-safe embed markup or a core embed block wrapper. Never output raw unsupported script tags.
- If you include buttons, use WordPress core buttons markup with <div class="wp-block-buttons"> and <div class="wp-block-button"><a class="wp-block-button__link" ...>Label</a></div>.
- If you include columns, use WordPress core columns markup with wp-block-columns and wp-block-column containers.
- If you include an accordion, use semantic <details><summary>...</summary>...</details> markup.
- If you include a page break, use the WordPress next page marker <!-- wp:nextpage -->.
- If you include footnotes, use linked footnote references and a short endnotes section.
- If alignment is needed, use simple WordPress-compatible classes or semantic markup. Do not invent unsupported CSS or scripts.
- Include an engaging introduction and a clear conclusion.
- Keep the structure coherent, factual, and ready to publish in WordPress without manual cleanup.
PROMPT,
            'seo_title' => <<<PROMPT
You are an SEO specialist. Write an optimised SEO title tag for a WordPress {post_type}.

Post title: {title}
Tone: {tone}
{keywords_line}

Requirements:
- 50–60 characters maximum
- Include the primary keyword naturally
- Be compelling and click-worthy
- Output plain text only
- Do not output HTML, markdown, emojis, quotation marks, separators, labels, or explanations
PROMPT,
            'meta_description' => <<<PROMPT
You are an SEO specialist. Write a meta description for a WordPress {post_type}.

Post title: {title}
Tone: {tone}
{keywords_line}
{existing_content_block}

Requirements:
- 150–160 characters maximum
- Include the primary keyword naturally
- Include a subtle call to action
- Output plain text only
- Do not output HTML, markdown, emojis, quotation marks, labels, or explanations
PROMPT,
            'excerpt' => <<<PROMPT
You are a content editor. Write a short excerpt for a WordPress {post_type}.

Post title: {title}
Tone: {tone}
{existing_content_block}

Requirements:
- 40–55 words
- Engaging, teases the content without giving everything away
- Plain text only
- Do not output HTML, markdown, bullets, headings, quotation marks, labels, or explanations
PROMPT,
        ];
    }

    private static function legacy_prompt_defaults(): array {
        return [
            'post_content' => [
                <<<PROMPT
You are an expert content writer. Write a complete, well-structured WordPress {post_type} in {language}.

Title: {title}
Tone: {tone}
{keywords_line}
{structure_line}
{target_length_line}
{existing_content_block}

Requirements:
- Use proper heading hierarchy (H2, H3)
- Include an engaging introduction and a clear conclusion
- Output clean HTML suitable for the WordPress block editor (use <h2>, <h3>, <p>, <ul>/<ol>)
- Do NOT include the post title as an H1 — WordPress outputs that separately
- Do NOT wrap the output in code fences
PROMPT,
            ],
            'seo_title' => [
                <<<PROMPT
You are an SEO specialist. Write an optimised SEO title tag for a WordPress {post_type}.

Post title: {title}
Tone: {tone}
{keywords_line}

Requirements:
- 50–60 characters maximum
- Include the primary keyword naturally
- Be compelling and click-worthy
- Output only the title text, no quotes, no explanation
PROMPT,
            ],
            'meta_description' => [
                <<<PROMPT
You are an SEO specialist. Write a meta description for a WordPress {post_type}.

Post title: {title}
Tone: {tone}
{keywords_line}
{existing_content_block}

Requirements:
- 150–160 characters maximum
- Include the primary keyword naturally
- Include a subtle call to action
- Output only the description text, no quotes, no explanation
PROMPT,
            ],
            'excerpt' => [
                <<<PROMPT
You are a content editor. Write a short excerpt for a WordPress {post_type}.

Post title: {title}
Tone: {tone}
{existing_content_block}

Requirements:
- 40–55 words
- Engaging, teases the content without giving everything away
- Plain text only — no HTML, no quotes around the output, no explanation
PROMPT,
            ],
        ];
    }

    public static function get_prompt_template( string $type ): string {
        $defaults = self::prompt_defaults();
        return self::get( self::prompt_setting_key( $type ), $defaults[ $type ] ?? '' );
    }

    public static function init(): void {
        register_setting( 'aig_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [ self::class, 'sanitize' ],
        ] );
    }

    public static function get( string $key, $fallback = null ) {
        if ( empty( self::$cache ) ) {
            self::$cache = self::normalize_settings(
                wp_parse_args(
                    get_option( self::OPTION_KEY, [] ),
                    self::defaults()
                )
            );
        }
        return self::$cache[ $key ] ?? $fallback;
    }

    public static function all(): array {
        if ( empty( self::$cache ) ) {
            self::$cache = self::normalize_settings(
                wp_parse_args(
                    get_option( self::OPTION_KEY, [] ),
                    self::defaults()
                )
            );
        }
        return self::$cache;
    }

    public static function sanitize( array $input ): array {
        $clean = self::defaults();

        if ( isset( $input['default_provider'] ) && in_array( $input['default_provider'], self::PROVIDERS, true ) ) {
            $clean['default_provider'] = $input['default_provider'];
        }
        $clean['claude_api_key']  = sanitize_text_field( $input['claude_api_key'] ?? '' );
        $clean['claude_model']    = '' === $clean['claude_api_key']
            ? ''
            : sanitize_text_field( $input['claude_model'] ?? '' );
        $clean['openai_api_key']  = sanitize_text_field( $input['openai_api_key'] ?? '' );
        $clean['openai_model']    = '' === $clean['openai_api_key']
            ? ''
            : sanitize_text_field( $input['openai_model'] ?? '' );
        $clean['ollama_url']      = esc_url_raw( $input['ollama_url'] ?? 'http://localhost:11434' );
        $clean['ollama_auth_header_name'] = sanitize_text_field( $input['ollama_auth_header_name'] ?? '' );
        $clean['ollama_auth_header_value'] = sanitize_text_field( $input['ollama_auth_header_value'] ?? '' );
        $clean['ollama_model']    = '' === $clean['ollama_url']
            ? ''
            : sanitize_text_field( $input['ollama_model'] ?? '' );
        $legacy_max_tokens          = absint( $input['max_tokens'] ?? 15000 );
        $clean['max_output_tokens'] = absint( $input['max_output_tokens'] ?? $legacy_max_tokens );
        $clean['max_thinking_tokens'] = absint( $input['max_thinking_tokens'] ?? 15000 );
        $clean['max_tokens']        = $clean['max_output_tokens'];
        $clean['temperature']       = min( 2.0, max( 0.0, (float) ( $input['temperature'] ?? 0.7 ) ) );

        foreach ( self::prompt_defaults() as $type => $default_template ) {
            $key      = self::prompt_setting_key( $type );
            $template = self::normalize_prompt_template( (string) ( $input[ $key ] ?? $default_template ) );

            $clean[ $key ] = '' === $template ? $default_template : $template;
        }

        self::$cache = [];  // bust cache on save
        return $clean;
    }

    private static function normalize_prompt_template( string $template ): string {
        $template = wp_check_invalid_utf8( $template );
        $template = preg_replace( "/\r\n?/", "\n", $template );

        return trim( $template );
    }

    private static function normalize_settings( array $settings ): array {
        foreach ( self::prompt_defaults() as $type => $default_template ) {
            $key     = self::prompt_setting_key( $type );
            $current = self::normalize_prompt_template( (string) ( $settings[ $key ] ?? '' ) );
            $legacy  = array_map( [ self::class, 'normalize_prompt_template' ], self::legacy_prompt_defaults()[ $type ] ?? [] );

            if ( '' === $current || in_array( $current, $legacy, true ) ) {
                $settings[ $key ] = $default_template;
            }
        }

        if ( empty( $settings['max_output_tokens'] ) && ! empty( $settings['max_tokens'] ) ) {
            $settings['max_output_tokens'] = absint( $settings['max_tokens'] );
        }

        if ( ! isset( $settings['max_thinking_tokens'] ) ) {
            $settings['max_thinking_tokens'] = 15000;
        }

        if ( ! isset( $settings['ollama_auth_header_name'] ) ) {
            $settings['ollama_auth_header_name'] = '';
        }

        if ( ! isset( $settings['ollama_auth_header_value'] ) ) {
            $settings['ollama_auth_header_value'] = '';
        }

        $settings['max_tokens'] = $settings['max_output_tokens'] ?? $settings['max_tokens'] ?? 15000;

        return $settings;
    }

    /**
     * Return only non-sensitive settings for JS (no API keys).
     */
    public static function for_js(): array {
        $s = self::all();
        return [
            'default_provider'    => $s['default_provider'],
            'claude_model'        => $s['claude_model'],
            'openai_model'        => $s['openai_model'],
            'ollama_url'          => $s['ollama_url'],
            'ollama_model'        => $s['ollama_model'],
            'max_output_tokens'   => $s['max_output_tokens'] ?? ( $s['max_tokens'] ?? 15000 ),
            'max_thinking_tokens' => $s['max_thinking_tokens'] ?? 15000,
            'temperature'         => $s['temperature'] ?? 0.7,
            'providers'           => self::PROVIDERS,
        ];
    }
}
