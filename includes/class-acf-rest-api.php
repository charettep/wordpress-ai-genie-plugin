<?php
defined( 'ABSPATH' ) || exit;

class ACF_Rest_API {

    const REST_NAMESPACE = 'ai-content-forge/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
    }

    public static function register_routes(): void {
        // Generate content
        register_rest_route( self::REST_NAMESPACE, '/generate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_generate' ],
            'permission_callback' => [ self::class, 'check_permission' ],
            'args'                => [
                'type'     => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => in_array( $v, ACF_Generator::TYPES, true ),
                ],
                'provider' => [
                    'default'           => '',
                    'validate_callback' => fn( $v ) => $v === '' || in_array( $v, ACF_Settings::PROVIDERS, true ),
                ],
                'title'             => [ 'default' => '' ],
                'keywords'          => [ 'default' => '' ],
                'tone'              => [ 'default' => 'professional' ],
                'existing_content'  => [ 'default' => '' ],
                'post_type'         => [ 'default' => 'post' ],
                'language'          => [ 'default' => 'English' ],
                'target_length'     => [
                    'default'           => null,
                    'sanitize_callback' => 'absint',
                ],
                'structure'         => [ 'default' => '' ],
                'model'             => [ 'default' => '' ],
                'max_output_tokens' => [
                    'default'           => null,
                    'sanitize_callback' => 'absint',
                ],
                'max_thinking_tokens' => [
                    'default'           => null,
                    'sanitize_callback' => 'absint',
                ],
                'temperature'       => [ 'default' => null ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/generate-stream', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_generate_stream' ],
            'permission_callback' => [ self::class, 'check_permission' ],
            'args'                => [
                'type'     => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => in_array( $v, ACF_Generator::TYPES, true ),
                ],
                'provider' => [
                    'default'           => '',
                    'validate_callback' => fn( $v ) => $v === '' || in_array( $v, ACF_Settings::PROVIDERS, true ),
                ],
                'title'            => [ 'default' => '' ],
                'keywords'         => [ 'default' => '' ],
                'tone'             => [ 'default' => 'professional' ],
                'existing_content' => [ 'default' => '' ],
                'post_type'        => [ 'default' => 'post' ],
                'language'         => [ 'default' => 'English' ],
                'target_length'     => [
                    'default'           => null,
                    'sanitize_callback' => 'absint',
                ],
                'structure'         => [ 'default' => '' ],
                'model'             => [ 'default' => '' ],
                'max_output_tokens' => [
                    'default'           => null,
                    'sanitize_callback' => 'absint',
                ],
                'max_thinking_tokens' => [
                    'default'           => null,
                    'sanitize_callback' => 'absint',
                ],
                'temperature'       => [ 'default' => null ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/generate-stop', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_generate_stop' ],
            'permission_callback' => [ self::class, 'check_permission' ],
            'args'                => [
                'provider' => [
                    'default'           => '',
                    'validate_callback' => fn( $v ) => $v === '' || in_array( $v, ACF_Settings::PROVIDERS, true ),
                ],
            ],
        ] );

        // Test provider connection
        register_rest_route( self::REST_NAMESPACE, '/test-provider', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_test_provider' ],
            'permission_callback' => [ self::class, 'check_permission' ],
            'args'                => [
                'provider' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => in_array( $v, ACF_Settings::PROVIDERS, true ),
                ],
            ],
        ] );

        // Sync provider connection state + model list from unsaved admin form inputs
        register_rest_route( self::REST_NAMESPACE, '/sync-provider', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_sync_provider' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            'args'                => [
                'provider' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => in_array( $v, ACF_Settings::PROVIDERS, true ),
                ],
                'api_key' => [
                    'default' => '',
                ],
                'base_url' => [
                    'default' => '',
                ],
                'current_model' => [
                    'default' => '',
                ],
            ],
        ] );

        // Get available providers
        register_rest_route( self::REST_NAMESPACE, '/providers', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'handle_providers' ],
            'permission_callback' => [ self::class, 'check_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/provider-models', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'handle_provider_models' ],
            'permission_callback' => [ self::class, 'check_permission' ],
            'args'                => [
                'provider' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => in_array( $v, ACF_Settings::PROVIDERS, true ),
                ],
                'refresh'  => [
                    'default' => false,
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/settings', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'handle_get_settings' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/settings', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [ self::class, 'handle_update_settings' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/prompt-templates', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'handle_get_prompt_templates' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/prompt-templates/(?P<type>[a-z_]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'handle_get_prompt_template' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            'args'                => [
                'type' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => array_key_exists( $v, ACF_Settings::prompt_defaults() ),
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/prompt-templates/(?P<type>[a-z_]+)', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [ self::class, 'handle_update_prompt_template' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            'args'                => [
                'type' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => array_key_exists( $v, ACF_Settings::prompt_defaults() ),
                ],
                'template' => [
                    'required' => true,
                ],
            ],
        ] );
    }

    public static function check_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    public static function check_manage_options_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    public static function handle_generate( WP_REST_Request $request ): WP_REST_Response {
        try {
            $context = self::build_generation_context( $request );

            $result = ACF_Generator::generate(
                $request->get_param( 'type' ),
                $context,
                $request->get_param( 'provider' )
            );

            return new WP_REST_Response( [ 'success' => true, 'result' => $result ], 200 );

        } catch ( \Throwable $e ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => $e->getMessage() ],
                500
            );
        }
    }

    public static function handle_generate_stream( WP_REST_Request $request ) {
        $provider = (string) $request->get_param( 'provider' );

        try {
            self::start_event_stream();
            self::send_stream_event( 'start', [ 'success' => true ] );

            $usage = ACF_Generator::stream_generate(
                (string) $request->get_param( 'type' ),
                self::build_generation_context( $request ),
                $provider,
                static function ( string $chunk ): void {
                    if ( '' !== $chunk ) {
                        self::send_stream_event( 'chunk', [ 'text' => $chunk ] );
                    }
                }
            );

            if ( ! empty( $usage ) ) {
                self::send_stream_event( 'usage', $usage );
            }

            self::send_stream_event( 'done', [
                'success' => true,
                'usage'   => $usage,
            ] );
        } catch ( \Throwable $e ) {
            if ( 'Stream canceled by client.' === $e->getMessage() ) {
                ACF_Generator::stop_generation( $provider );
            }

            self::send_stream_event( 'error', [
                'success' => false,
                'message' => $e->getMessage(),
            ] );
        }

        exit;
    }

    public static function handle_generate_stop( WP_REST_Request $request ): WP_REST_Response {
        try {
            $stopped = ACF_Generator::stop_generation( (string) $request->get_param( 'provider' ) );

            return new WP_REST_Response(
                [
                    'success' => true,
                    'stopped' => $stopped,
                ],
                200
            );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                ],
                500
            );
        }
    }

    public static function handle_test_provider( WP_REST_Request $request ): WP_REST_Response {
        $slug = $request->get_param( 'provider' );
        try {
            $provider = ACF_Generator::get_provider( $slug );
            if ( ! $provider->is_configured() ) {
                return new WP_REST_Response(
                    [ 'success' => false, 'message' => 'Provider not configured — check API key / URL.' ],
                    400
                );
            }
            try {
                $provider->discover_models();
            } catch ( RuntimeException $e ) {
                $provider->generate( 'Reply with exactly: OK', 10, 0.0 );
            }

            return new WP_REST_Response( [ 'success' => true, 'message' => 'Connection successful.' ], 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    public static function handle_sync_provider( WP_REST_Request $request ): WP_REST_Response {
        $slug          = (string) $request->get_param( 'provider' );
        $current_model = sanitize_text_field( (string) $request->get_param( 'current_model' ) );
        $config        = [];

        if ( 'ollama' === $slug ) {
            $base_url = esc_url_raw( trim( (string) $request->get_param( 'base_url' ) ) );

            if ( '' === $base_url ) {
                return new WP_REST_Response(
                    [ 'success' => false, 'message' => 'Base URL is required.' ],
                    400
                );
            }

            $config['ollama_url'] = $base_url;
        } else {
            $api_key = trim( (string) $request->get_param( 'api_key' ) );

            if ( '' === $api_key ) {
                return new WP_REST_Response(
                    [ 'success' => false, 'message' => 'API key is required.' ],
                    400
                );
            }

            $config[ $slug . '_api_key' ] = $api_key;
        }

        try {
            $provider = ACF_Generator::get_provider( $slug );
            $models   = $provider->discover_models( $config );

            $model_ids = array_column( $models, 'id' );
            $selected  = in_array( $current_model, $model_ids, true )
                ? $current_model
                : ( $model_ids[0] ?? '' );

            return new WP_REST_Response(
                [
                    'success'        => true,
                    'connected'      => true,
                    'message'        => 'Connected',
                    'models'         => $models,
                    'selected_model' => $selected,
                ],
                200
            );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response(
                [ 'success' => false, 'connected' => false, 'message' => $e->getMessage() ],
                500
            );
        }
    }

    public static function handle_providers(): WP_REST_Response {
        $list = [];
        foreach ( ACF_Settings::PROVIDERS as $slug ) {
            $p      = ACF_Generator::get_provider( $slug );
            $list[] = [
                'id'            => $slug,
                'label'         => $p->label(),
                'is_configured' => $p->is_configured(),
                'is_default'    => ( $slug === ACF_Settings::get( 'default_provider' ) ),
            ];
        }
        return new WP_REST_Response( $list, 200 );
    }

    public static function handle_provider_models( WP_REST_Request $request ): WP_REST_Response {
        $slug    = (string) $request->get_param( 'provider' );
        $refresh = filter_var( $request->get_param( 'refresh' ), FILTER_VALIDATE_BOOLEAN );
        $cache_key = 'acf_models_' . $slug;

        if ( ! $refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return new WP_REST_Response( [ 'success' => true, 'models' => $cached ], 200 );
            }
        }

        try {
            $provider = ACF_Generator::get_provider( $slug );
            $models   = $provider->discover_models();
            set_transient( $cache_key, $models, 10 * MINUTE_IN_SECONDS );

            return new WP_REST_Response( [ 'success' => true, 'models' => $models ], 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => $e->getMessage() ],
                500
            );
        }
    }

    public static function handle_get_settings(): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => true, 'settings' => ACF_Settings::all() ], 200 );
    }

    public static function handle_update_settings( WP_REST_Request $request ): WP_REST_Response {
        $settings = (array) $request->get_json_params();

        if ( empty( $settings ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing settings in request.' ], 400 );
        }

        try {
            $clean = ACF_Settings::sanitize( $settings );
            update_option( ACF_Settings::OPTION_KEY, $clean );
            ACF_Settings::get( 'default_provider' ); // reload cache

            return new WP_REST_Response( [ 'success' => true, 'settings' => $clean ], 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    public static function handle_get_prompt_templates(): WP_REST_Response {
        $templates = [];

        foreach ( ACF_Settings::prompt_defaults() as $type => $default ) {
            $templates[] = [
                'type'     => $type,
                'label'    => ucwords( str_replace( '_', ' ', $type ) ),
                'template' => ACF_Settings::get_prompt_template( $type ),
            ];
        }

        return new WP_REST_Response( [ 'success' => true, 'templates' => $templates ], 200 );
    }

    public static function handle_get_prompt_template( WP_REST_Request $request ): WP_REST_Response {
        $type = (string) $request->get_param( 'type' );

        if ( ! array_key_exists( $type, ACF_Settings::prompt_defaults() ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid prompt type.' ], 400 );
        }

        return new WP_REST_Response( [ 'success' => true, 'type' => $type, 'template' => ACF_Settings::get_prompt_template( $type ) ], 200 );
    }

    public static function handle_update_prompt_template( WP_REST_Request $request ): WP_REST_Response {
        $type     = (string) $request->get_param( 'type' );
        $template = (string) $request->get_param( 'template' );

        if ( ! array_key_exists( $type, ACF_Settings::prompt_defaults() ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid prompt type.' ], 400 );
        }

        $settings = ACF_Settings::all();
        $settings[ ACF_Settings::prompt_setting_key( $type ) ] = $template;

        try {
            $clean = ACF_Settings::sanitize( $settings );
            update_option( ACF_Settings::OPTION_KEY, $clean );
            ACF_Settings::get( 'default_provider' ); // refresh cache

            return new WP_REST_Response( [ 'success' => true, 'type' => $type, 'template' => $clean[ ACF_Settings::prompt_setting_key( $type ) ] ], 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    private static function build_generation_context( WP_REST_Request $request ): array {
        $context = [
            'title'            => $request->get_param( 'title' ),
            'keywords'         => $request->get_param( 'keywords' ),
            'tone'             => $request->get_param( 'tone' ),
            'existing_content' => $request->get_param( 'existing_content' ),
            'post_type'        => $request->get_param( 'post_type' ),
            'language'         => $request->get_param( 'language' ),
        ];

        foreach ( [ 'target_length', 'structure', 'model', 'max_output_tokens', 'max_thinking_tokens', 'temperature' ] as $key ) {
            if ( $request->has_param( $key ) ) {
                $context[ $key ] = $request->get_param( $key );
            }
        }

        return $context;
    }

    private static function start_event_stream(): void {
        if ( ! headers_sent() ) {
            status_header( 200 );
            nocache_headers();
            header( 'Content-Type: text/event-stream; charset=utf-8' );
            header( 'X-Accel-Buffering: no' );
        }

        ignore_user_abort( false );

        while ( ob_get_level() > 0 ) {
            ob_end_flush();
        }

        @ini_set( 'output_buffering', 'off' );
        @ini_set( 'zlib.output_compression', '0' );
        @set_time_limit( 0 );

        echo ":" . str_repeat( ' ', 2048 ) . "\n\n";
        @flush();
    }

    private static function send_stream_event( string $event, array $payload ): void {
        if ( connection_aborted() ) {
            throw new RuntimeException( 'Stream canceled by client.' );
        }

        echo 'event: ' . $event . "\n";
        echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
        @flush();

        if ( connection_aborted() ) {
            throw new RuntimeException( 'Stream canceled by client.' );
        }
    }
}
