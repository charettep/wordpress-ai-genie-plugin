<?php
defined( 'ABSPATH' ) || exit;

class AIG_Deep_Research_Settings {

    const OPTION_KEY = 'aig_deep_research_settings';

    public static function init(): void {
        register_setting(
            'aig_deep_research_settings_group',
            self::OPTION_KEY,
            [
                'sanitize_callback' => [ self::class, 'sanitize' ],
            ]
        );
    }

    public static function defaults(): array {
        return [
            'default_model'             => 'o4-mini-deep-research',
            'advanced_model'            => 'o3-deep-research',
            'default_background'        => 1,
            'default_max_tool_calls'    => 12,
            'default_response_type'     => 'text',
            'default_reasoning_effort'  => 'medium',
            'default_verbosity'         => 'medium',
            'web_domain_allowlist'      => [],
            'default_code_memory_limit' => '',
            'webhook_secret'            => '',
            'webhook_enabled'           => 0,
            'poll_interval_seconds'     => 60,
        ];
    }

    public static function all(): array {
        $settings = get_option( self::OPTION_KEY, [] );

        return wp_parse_args( is_array( $settings ) ? $settings : [], self::defaults() );
    }

    public static function get( string $key, $fallback = null ) {
        $settings = self::all();

        return $settings[ $key ] ?? $fallback;
    }

    public static function sanitize( array $input ): array {
        $defaults = self::defaults();
        $clean    = $defaults;

        $clean['default_model'] = in_array(
            (string) ( $input['default_model'] ?? '' ),
            [ 'o4-mini-deep-research', 'o3-deep-research' ],
            true
        ) ? (string) $input['default_model'] : $defaults['default_model'];

        $clean['advanced_model'] = 'o3-deep-research';
        $clean['default_background'] = empty( $input['default_background'] ) ? 0 : 1;
        $clean['default_max_tool_calls'] = max( 1, min( 100, absint( $input['default_max_tool_calls'] ?? $defaults['default_max_tool_calls'] ) ) );
        $clean['default_response_type'] = in_array(
            (string) ( $input['default_response_type'] ?? '' ),
            [ 'text' ],
            true
        ) ? (string) $input['default_response_type'] : $defaults['default_response_type'];
        $clean['default_reasoning_effort'] = in_array(
            (string) ( $input['default_reasoning_effort'] ?? '' ),
            [ 'low', 'medium', 'high' ],
            true
        ) ? (string) $input['default_reasoning_effort'] : $defaults['default_reasoning_effort'];
        $clean['default_verbosity'] = in_array(
            (string) ( $input['default_verbosity'] ?? '' ),
            [ 'low', 'medium', 'high' ],
            true
        ) ? (string) $input['default_verbosity'] : $defaults['default_verbosity'];
        $clean['default_code_memory_limit'] = in_array(
            (string) ( $input['default_code_memory_limit'] ?? '' ),
            [ '', '1g', '4g', '16g', '64g' ],
            true
        ) ? (string) $input['default_code_memory_limit'] : $defaults['default_code_memory_limit'];
        $clean['webhook_secret'] = sanitize_text_field( (string) ( $input['webhook_secret'] ?? '' ) );
        $clean['webhook_enabled'] = empty( $input['webhook_enabled'] ) ? 0 : 1;
        $clean['poll_interval_seconds'] = max( 30, min( 900, absint( $input['poll_interval_seconds'] ?? $defaults['poll_interval_seconds'] ) ) );

        $domains = $input['web_domain_allowlist'] ?? [];
        if ( is_string( $domains ) ) {
            $domains = preg_split( '/[\r\n,]+/', $domains );
        }

        if ( ! is_array( $domains ) ) {
            $domains = [];
        }

        $clean['web_domain_allowlist'] = array_values(
            array_slice(
                array_filter(
                    array_map(
                        static function ( $domain ): string {
                            $domain = strtolower( trim( (string) $domain ) );
                            $domain = preg_replace( '#^https?://#', '', $domain );
                            $domain = trim( $domain, " \t\n\r\0\x0B/" );

                            return sanitize_text_field( $domain );
                        },
                        $domains
                    )
                ),
                0,
                100
            )
        );

        return $clean;
    }
}
