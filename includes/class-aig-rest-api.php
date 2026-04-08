<?php
defined( 'ABSPATH' ) || exit;

class AIG_Rest_API {

    const REST_NAMESPACE = 'ai-genie/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route( self::REST_NAMESPACE, '/deep-research/runs', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ self::class, 'handle_deep_research_runs_list' ],
                'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ self::class, 'handle_deep_research_runs_create' ],
                'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/deep-research/runs/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'handle_deep_research_run_get' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/deep-research/runs/(?P<id>\d+)/refresh', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_deep_research_run_refresh' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/deep-research/runs/(?P<id>\d+)/cancel', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_deep_research_run_cancel' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/deep-research/runs/(?P<id>\d+)/create-draft', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_deep_research_run_create_draft' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            'args'                => [
                'post_type' => [
                    'required'          => true,
                    'validate_callback' => fn( $value ) => in_array( $value, [ 'post', 'page' ], true ),
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/deep-research/sources', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ self::class, 'handle_deep_research_sources_list' ],
                'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ self::class, 'handle_deep_research_source_create' ],
                'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/deep-research/sources/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ self::class, 'handle_deep_research_source_delete' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/deep-research/vector-stores', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ self::class, 'handle_deep_research_vector_stores_list' ],
                'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ self::class, 'handle_deep_research_vector_store_create' ],
                'permission_callback' => [ self::class, 'check_manage_options_permission' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/deep-research/vector-stores/(?P<id>[A-Za-z0-9_-]+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ self::class, 'handle_deep_research_vector_store_delete' ],
            'permission_callback' => [ self::class, 'check_manage_options_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/deep-research/webhook', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_deep_research_webhook' ],
            'permission_callback' => '__return_true',
        ] );

        // Generate content
        register_rest_route( self::REST_NAMESPACE, '/generate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_generate' ],
            'permission_callback' => [ self::class, 'check_permission' ],
            'args'                => [
                'type'     => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => in_array( $v, AIG_Generator::TYPES, true ),
                ],
                'provider' => [
                    'default'           => '',
                    'validate_callback' => fn( $v ) => $v === '' || in_array( $v, AIG_Settings::PROVIDERS, true ),
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
                'generation_id'     => [ 'default' => '' ],
                'prompt_override'   => [ 'default' => '' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/generate-stream', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_generate_stream' ],
            'permission_callback' => [ self::class, 'check_permission' ],
            'args'                => [
                'type'     => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => in_array( $v, AIG_Generator::TYPES, true ),
                ],
                'provider' => [
                    'default'           => '',
                    'validate_callback' => fn( $v ) => $v === '' || in_array( $v, AIG_Settings::PROVIDERS, true ),
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
                'generation_id'     => [ 'default' => '' ],
                'prompt_override'   => [ 'default' => '' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/generate-stop', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'handle_generate_stop' ],
            'permission_callback' => [ self::class, 'check_permission' ],
            'args'                => [
                'provider' => [
                    'default'           => '',
                    'validate_callback' => fn( $v ) => $v === '' || in_array( $v, AIG_Settings::PROVIDERS, true ),
                ],
                'generation_id' => [
                    'default' => '',
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
                    'validate_callback' => fn( $v ) => in_array( $v, AIG_Settings::PROVIDERS, true ),
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
                    'validate_callback' => fn( $v ) => in_array( $v, AIG_Settings::PROVIDERS, true ),
                ],
                'api_key' => [
                    'default' => '',
                ],
                'base_url' => [
                    'default' => '',
                ],
                'auth_header_name' => [
                    'default' => '',
                ],
                'auth_header_value' => [
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
                    'validate_callback' => fn( $v ) => in_array( $v, AIG_Settings::PROVIDERS, true ),
                ],
                'refresh'  => [
                    'default' => false,
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

    public static function handle_deep_research_runs_list(): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success' => true,
                'runs'    => AIG_Deep_Research_Service::list_runs(),
            ],
            200
        );
    }

    public static function handle_deep_research_runs_create( WP_REST_Request $request ): WP_REST_Response {
        try {
            $run = AIG_Deep_Research_Service::create_run( $request->get_json_params() ?: $request->get_params() );

            return new WP_REST_Response(
                [
                    'success' => true,
                    'run'     => $run,
                ],
                201
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

    public static function handle_deep_research_run_get( WP_REST_Request $request ): WP_REST_Response {
        try {
            $run = AIG_Deep_Research_Service::get_run(
                absint( $request['id'] ),
                rest_sanitize_boolean( $request->get_param( 'refresh' ) )
            );

            return new WP_REST_Response(
                [
                    'success' => true,
                    'run'     => $run,
                ],
                200
            );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                ],
                404
            );
        }
    }

    public static function handle_deep_research_run_refresh( WP_REST_Request $request ): WP_REST_Response {
        try {
            $run = AIG_Deep_Research_Service::get_run( absint( $request['id'] ), true );

            return new WP_REST_Response(
                [
                    'success' => true,
                    'run'     => $run,
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

    public static function handle_deep_research_run_cancel( WP_REST_Request $request ): WP_REST_Response {
        try {
            $run = AIG_Deep_Research_Service::cancel_run( absint( $request['id'] ) );

            return new WP_REST_Response(
                [
                    'success' => true,
                    'run'     => $run,
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

    public static function handle_deep_research_run_create_draft( WP_REST_Request $request ): WP_REST_Response {
        try {
            $result = AIG_Deep_Research_Service::create_draft_from_run(
                absint( $request['id'] ),
                sanitize_key( (string) $request->get_param( 'post_type' ) )
            );

            return new WP_REST_Response(
                [
                    'success' => true,
                    'result'  => $result,
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

    public static function handle_deep_research_sources_list(): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success' => true,
                'sources' => AIG_Deep_Research_Service::list_sources(),
                'webhook' => AIG_Deep_Research_Service::get_webhook_details(),
            ],
            200
        );
    }

    public static function handle_deep_research_source_create( WP_REST_Request $request ): WP_REST_Response {
        try {
            $source = AIG_Deep_Research_Service::create_source( $request->get_json_params() ?: $request->get_params() );

            return new WP_REST_Response(
                [
                    'success' => true,
                    'source'  => $source,
                ],
                201
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

    public static function handle_deep_research_source_delete( WP_REST_Request $request ): WP_REST_Response {
        try {
            AIG_Deep_Research_Service::delete_source( absint( $request['id'] ) );

            return new WP_REST_Response(
                [
                    'success' => true,
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

    public static function handle_deep_research_vector_stores_list(): WP_REST_Response {
        try {
            return new WP_REST_Response(
                [
                    'success'       => true,
                    'vector_stores' => AIG_Deep_Research_Service::list_vector_stores(),
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

    public static function handle_deep_research_vector_store_create( WP_REST_Request $request ): WP_REST_Response {
        try {
            $vector_store = AIG_Deep_Research_Service::create_vector_store( $request->get_json_params() ?: $request->get_params() );

            return new WP_REST_Response(
                [
                    'success'      => true,
                    'vector_store' => $vector_store,
                ],
                201
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

    public static function handle_deep_research_vector_store_delete( WP_REST_Request $request ): WP_REST_Response {
        try {
            AIG_Deep_Research_Service::delete_vector_store( sanitize_text_field( (string) $request['id'] ) );

            return new WP_REST_Response(
                [
                    'success' => true,
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

    public static function handle_deep_research_webhook( WP_REST_Request $request ): WP_REST_Response {
        try {
            $result = AIG_Deep_Research_Service::handle_webhook( $request );

            return new WP_REST_Response(
                [
                    'success' => true,
                    'result'  => $result,
                ],
                202
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

    public static function handle_generate( WP_REST_Request $request ): WP_REST_Response {
        try {
            $context  = self::build_generation_context( $request );
            $provider = (string) $request->get_param( 'provider' );
            $type     = (string) $request->get_param( 'type' );
            $chunks   = [];

            $usage = AIG_Generator::stream_generate(
                $type,
                $context,
                $provider,
                static function ( string $chunk ) use ( &$chunks ): void {
                    $chunks[] = $chunk;
                }
            );

            return new WP_REST_Response( [
                'success' => true,
                'result'  => implode( '', $chunks ),
                'usage'   => ! empty( $usage ) ? $usage : null,
            ], 200 );

        } catch ( \Throwable $e ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => $e->getMessage() ],
                500
            );
        }
    }

    public static function handle_generate_stream( WP_REST_Request $request ) {
        $provider = (string) $request->get_param( 'provider' );
        $generation_id = (string) $request->get_param( 'generation_id' );

        try {
            self::start_event_stream();
            self::send_stream_event( 'start', [ 'success' => true ] );

            $usage = AIG_Generator::stream_generate(
                (string) $request->get_param( 'type' ),
                self::build_generation_context( $request ),
                $provider,
                static function ( string $chunk ): void {
                    if ( '' !== $chunk ) {
                        self::send_stream_event( 'chunk', [ 'text' => $chunk ] );
                    }
                },
                static function ( array $usage_estimate ): void {
                    if ( ! empty( $usage_estimate ) ) {
                        self::send_stream_event( 'usage_estimate', $usage_estimate );
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
                AIG_Generator::stop_generation( $provider, $generation_id );
                exit;
            }

            if ( 'Generation canceled.' === $e->getMessage() ) {
                exit;
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
            $stopped = AIG_Generator::stop_generation(
                (string) $request->get_param( 'provider' ),
                (string) $request->get_param( 'generation_id' )
            );

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
            $provider = AIG_Generator::get_provider( $slug );
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
            $auth_header_name  = sanitize_text_field( trim( (string) $request->get_param( 'auth_header_name' ) ) );
            $auth_header_value = sanitize_text_field( trim( (string) $request->get_param( 'auth_header_value' ) ) );

            if ( '' === $base_url ) {
                return new WP_REST_Response(
                    [ 'success' => false, 'message' => 'Base URL is required.' ],
                    400
                );
            }

            $config['ollama_url']               = $base_url;
            $config['ollama_auth_header_name']  = $auth_header_name;
            $config['ollama_auth_header_value'] = $auth_header_value;
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
            $provider = AIG_Generator::get_provider( $slug );
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
        foreach ( AIG_Settings::PROVIDERS as $slug ) {
            $p      = AIG_Generator::get_provider( $slug );
            $list[] = [
                'id'            => $slug,
                'label'         => $p->label(),
                'is_configured' => $p->is_configured(),
                'is_default'    => ( $slug === AIG_Settings::get( 'default_provider' ) ),
            ];
        }
        return new WP_REST_Response( $list, 200 );
    }

    public static function handle_provider_models( WP_REST_Request $request ): WP_REST_Response {
        $slug    = (string) $request->get_param( 'provider' );
        $refresh = filter_var( $request->get_param( 'refresh' ), FILTER_VALIDATE_BOOLEAN );
        $cache_key = 'aig_models_' . $slug;

        if ( ! $refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return new WP_REST_Response( [ 'success' => true, 'models' => $cached ], 200 );
            }
        }

        try {
            $provider = AIG_Generator::get_provider( $slug );
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

    private static function build_generation_context( WP_REST_Request $request ): array {
        $context = [
            'title'            => $request->get_param( 'title' ),
            'keywords'         => $request->get_param( 'keywords' ),
            'tone'             => $request->get_param( 'tone' ),
            'existing_content' => $request->get_param( 'existing_content' ),
            'post_type'        => $request->get_param( 'post_type' ),
            'language'         => $request->get_param( 'language' ),
        ];

        foreach ( [ 'target_length', 'structure', 'model', 'max_output_tokens', 'max_thinking_tokens', 'temperature', 'generation_id', 'prompt_override' ] as $key ) {
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
