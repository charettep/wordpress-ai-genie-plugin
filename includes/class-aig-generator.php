<?php
defined( 'ABSPATH' ) || exit;

class AIG_Generator {

    const TYPES = [ 'post_content', 'seo_title', 'meta_description', 'excerpt' ];

    private static array $provider_instances = [];

    /**
     * Resolve a provider instance by slug.
     *
     * @throws InvalidArgumentException on unknown slug
     */
    public static function get_provider( string $slug ): AIG_Provider {
        if ( ! isset( self::$provider_instances[ $slug ] ) ) {
            switch ( $slug ) {
                case 'claude':
                    self::$provider_instances['claude'] = new AIG_Provider_Claude();
                    break;
                case 'openai':
                    self::$provider_instances['openai'] = new AIG_Provider_OpenAI();
                    break;
                case 'ollama':
                    self::$provider_instances['ollama'] = new AIG_Provider_Ollama();
                    break;
                default:
                    throw new InvalidArgumentException( "Unknown provider: $slug" );
            }
        }
        return self::$provider_instances[ $slug ];
    }

    /**
     * Generate content.
     *
     * @param string $type         One of self::TYPES
     * @param array  $context      [ title, keywords, tone, existing_content, post_type ]
     * @param string $provider     Provider slug or '' to use global default
     * @return string
     * @throws RuntimeException|InvalidArgumentException
     */
    public static function generate( string $type, array $context, string $provider = '' ): string {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            throw new InvalidArgumentException( "Unknown generation type: $type" );
        }

        $provider_slug = $provider ?: AIG_Settings::get( 'default_provider', 'claude' );
        $instance      = self::get_provider( $provider_slug );

        $overrides           = self::normalize_overrides( $context );
        $prompt             = self::build_prompt( $type, $context );
        $max_output_tokens  = $overrides['max_output_tokens']
            ?? AIG_Settings::get( 'max_output_tokens', AIG_Settings::get( 'max_tokens', 15000 ) );
        $max_thinking_tokens = $overrides['max_thinking_tokens']
            ?? AIG_Settings::get( 'max_thinking_tokens', 15000 );
        $temp               = $overrides['temperature']
            ?? AIG_Settings::get( 'temperature', 0.7 );

        // Shorter outputs need fewer tokens
        if ( in_array( $type, [ 'seo_title', 'meta_description', 'excerpt' ], true ) ) {
            $max_output_tokens = min( $max_output_tokens, 300 );
            $temp              = max( 0.3, $temp - 0.2 );
        }

        $instance->set_model_override( $overrides['model'] ?? '' );
        $instance->set_generation_id( $overrides['generation_id'] ?? '' );

        try {
            return $instance->generate( $prompt, $max_output_tokens, $temp, $max_thinking_tokens );
        } finally {
            $instance->set_model_override( '' );
            $instance->set_generation_id( '' );
        }
    }

    /**
     * Stream generated content through the provider.
     *
     * @param callable(string):void $emit
     * @return array<string,mixed>
     */
    public static function stream_generate( string $type, array $context, string $provider, callable $emit, ?callable $emit_usage_estimate = null ): array {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            throw new InvalidArgumentException( "Unknown generation type: $type" );
        }

        $provider_slug        = $provider ?: AIG_Settings::get( 'default_provider', 'claude' );
        $instance             = self::get_provider( $provider_slug );
        $overrides            = self::normalize_overrides( $context );
        $prompt               = self::build_prompt( $type, $context );
        $max_output_tokens    = $overrides['max_output_tokens']
            ?? AIG_Settings::get( 'max_output_tokens', AIG_Settings::get( 'max_tokens', 15000 ) );
        $max_thinking_tokens  = $overrides['max_thinking_tokens']
            ?? AIG_Settings::get( 'max_thinking_tokens', 15000 );
        $temp                 = $overrides['temperature']
            ?? AIG_Settings::get( 'temperature', 0.7 );

        if ( in_array( $type, [ 'seo_title', 'meta_description', 'excerpt' ], true ) ) {
            $max_output_tokens = min( $max_output_tokens, 300 );
            $temp              = max( 0.3, $temp - 0.2 );
        }

        $instance->set_model_override( $overrides['model'] ?? '' );
        $instance->set_generation_id( $overrides['generation_id'] ?? '' );

        try {
            $resolved_model   = self::resolve_usage_estimate_model( $provider_slug, $overrides );
            $estimated_usage  = null;
            $streamed_output  = '';
            $last_output_tokens = -1;

            if ( null !== $emit_usage_estimate ) {
                $estimated_usage = AIG_Token_Usage_Estimator::begin_estimate( $provider_slug, $resolved_model, $prompt );

                if ( ! empty( $estimated_usage ) ) {
                    $emit_usage_estimate( $estimated_usage );
                    $last_output_tokens = (int) ( $estimated_usage['output_tokens'] ?? 0 );
                }
            }

            return $instance->stream_generate(
                $prompt,
                $max_output_tokens,
                $temp,
                $max_thinking_tokens,
                static function ( string $chunk ) use ( $emit, $emit_usage_estimate, &$estimated_usage, &$streamed_output, &$last_output_tokens ): void {
                    if ( '' === $chunk ) {
                        return;
                    }

                    $emit( $chunk );

                    if ( null === $emit_usage_estimate || empty( $estimated_usage ) ) {
                        return;
                    }

                    $streamed_output .= $chunk;
                    $estimated_usage  = AIG_Token_Usage_Estimator::update_estimate( $estimated_usage, $streamed_output );
                    $output_tokens    = (int) ( $estimated_usage['output_tokens'] ?? 0 );

                    if ( $output_tokens !== $last_output_tokens ) {
                        $last_output_tokens = $output_tokens;
                        $emit_usage_estimate( $estimated_usage );
                    }
                }
            );
        } finally {
            $instance->set_model_override( '' );
            $instance->set_generation_id( '' );
        }
    }

    /**
     * Attempt to stop an active generation for the selected/default provider.
     */
    public static function stop_generation( string $provider = '', string $generation_id = '' ): bool {
        $provider_slug = $provider ?: AIG_Settings::get( 'default_provider', 'claude' );
        $instance      = self::get_provider( $provider_slug );
        $instance->set_generation_id( $generation_id );

        try {
            return $instance->cancel_generation( $generation_id );
        } finally {
            $instance->set_generation_id( '' );
        }
    }

    // -------------------------------------------------------------------------
    // Prompt builders
    // -------------------------------------------------------------------------

    private static function build_prompt( string $type, array $context ): string {
        $title           = sanitize_text_field( $context['title'] ?? '' );
        $keywords        = sanitize_text_field( $context['keywords'] ?? '' );
        $tone            = sanitize_text_field( $context['tone'] ?? 'professional' );
        $existing        = wp_strip_all_tags( $context['existing_content'] ?? '' );
        $post_type       = sanitize_text_field( $context['post_type'] ?? 'post' );
        $language        = sanitize_text_field( $context['language'] ?? 'English' );
        $structure       = sanitize_text_field( $context['structure'] ?? '' );
        $target_length   = absint( $context['target_length'] ?? 0 );
        $existing_snip   = $existing ? mb_substr( $existing, 0, 1000 ) : '';
        $prompt_override = isset( $context['prompt_override'] ) ? (string) $context['prompt_override'] : '';
        $prompt_template = '' !== trim( $prompt_override )
            ? self::normalize_prompt_template( $prompt_override )
            : AIG_Settings::get_prompt_template( $type );

        if ( '' === $structure && 'post_content' === $type ) {
            $structure = 'Full Draft';
        }

        if ( $target_length <= 0 && 'post_content' === $type ) {
            $target_length = 900;
        }

        $structure_line = '' !== $structure ? "Requested format: {$structure}." : '';
        $target_length_line = $target_length > 0 ? "Target length: about {$target_length} words." : '';

        $prompt = strtr(
            $prompt_template,
            [
                '{title}'                  => $title,
                '{tone}'                   => $tone,
                '{keywords}'               => $keywords,
                '{keywords_line}'          => $keywords ? "Focus keywords: {$keywords}." : '',
                '{post_type}'              => $post_type,
                '{language}'               => $language,
                '{structure}'              => $structure,
                '{structure_line}'         => $structure_line,
                '{target_length}'          => $target_length ? (string) $target_length : '',
                '{target_length_line}'     => $target_length_line,
                '{existing_content}'       => $existing_snip,
                '{existing_content_block}' => $existing_snip
                    ? "Existing content for reference:\n---\n{$existing_snip}\n---"
                    : '',
            ]
        );

        $prompt = preg_replace( "/[ \t]+\n/", "\n", $prompt );
        $prompt = preg_replace( "/\n{3,}/", "\n\n", $prompt );

        return trim( $prompt );
    }

    private static function normalize_prompt_template( string $template ): string {
        $template = wp_check_invalid_utf8( $template );
        $template = preg_replace( "/\r\n?/", "\n", $template );

        return trim( $template );
    }

    private static function normalize_overrides( array $context ): array {
        $overrides = [];

        if ( array_key_exists( 'model', $context ) ) {
            $model = sanitize_text_field( (string) $context['model'] );
            if ( '' !== $model ) {
                $overrides['model'] = $model;
            }
        }

        if ( array_key_exists( 'generation_id', $context ) ) {
            $generation_id = sanitize_text_field( (string) $context['generation_id'] );
            if ( '' !== $generation_id ) {
                $overrides['generation_id'] = $generation_id;
            }
        }

        if ( array_key_exists( 'max_output_tokens', $context ) ) {
            $max_output_tokens = absint( $context['max_output_tokens'] );
            if ( $max_output_tokens > 0 ) {
                $overrides['max_output_tokens'] = $max_output_tokens;
            }
        }

        if ( array_key_exists( 'max_thinking_tokens', $context ) ) {
            $overrides['max_thinking_tokens'] = absint( $context['max_thinking_tokens'] );
        }

        if ( array_key_exists( 'temperature', $context ) && is_numeric( $context['temperature'] ) ) {
            $temp = (float) $context['temperature'];
            $overrides['temperature'] = min( 2.0, max( 0.0, $temp ) );
        }

        return $overrides;
    }

    private static function resolve_usage_estimate_model( string $provider_slug, array $overrides ): string {
        if ( ! empty( $overrides['model'] ) ) {
            return (string) $overrides['model'];
        }

        switch ( $provider_slug ) {
            case 'claude':
                return trim( (string) AIG_Settings::get( 'claude_model', '' ) );
            case 'openai':
                return trim( (string) AIG_Settings::get( 'openai_model', '' ) );
            case 'ollama':
                return trim( (string) AIG_Settings::get( 'ollama_model', '' ) );
            default:
                return '';
        }
    }
}
