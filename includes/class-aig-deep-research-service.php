<?php
defined( 'ABSPATH' ) || exit;

class AIG_Deep_Research_Service {

    private const RESPONSES_API_URL = 'https://api.openai.com/v1/responses';
    private const VECTOR_STORES_API_URL = 'https://api.openai.com/v1/vector_stores';
    private const MODEL_PRICING = [
        'o4-mini-deep-research' => [
            'input'  => 2.00,
            'output' => 8.00,
        ],
        'o3-deep-research' => [
            'input'  => 10.00,
            'output' => 40.00,
        ],
    ];

    private const TOOL_ITEM_TYPES = [
        'web_search_call',
        'code_interpreter_call',
        'mcp_tool_call',
        'file_search_call',
    ];

    public static function create_run( array $args ): array {
        $title          = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
        $prompt         = trim( (string) ( $args['prompt'] ?? '' ) );
        $model          = sanitize_text_field( (string) ( $args['model'] ?? AIG_Deep_Research_Settings::get( 'default_model', 'o4-mini-deep-research' ) ) );
        $background     = ! empty( $args['background'] );
        $max_tool_calls = max( 1, min( 100, absint( $args['max_tool_calls'] ?? AIG_Deep_Research_Settings::get( 'default_max_tool_calls', 12 ) ) ) );
        $response_type    = self::normalize_response_type( (string) ( $args['response_type'] ?? AIG_Deep_Research_Settings::get( 'default_response_type', 'text' ) ) );
        $reasoning_effort = self::normalize_reasoning_effort( (string) ( $args['reasoning_effort'] ?? AIG_Deep_Research_Settings::get( 'default_reasoning_effort', 'medium' ) ) );
        $verbosity      = self::normalize_verbosity( (string) ( $args['verbosity'] ?? AIG_Deep_Research_Settings::get( 'default_verbosity', 'medium' ) ) );
        $tools_config   = self::normalize_tools_config( $args );

        if ( '' === $prompt ) {
            throw new RuntimeException( 'Deep Research prompt is required.' );
        }

        self::assert_supported_model( $model );
        self::assert_has_data_source( $tools_config );

        $payload  = self::build_request_payload( $prompt, $model, $background, $max_tool_calls, $tools_config, $response_type, $reasoning_effort, $verbosity );
        $response = self::request_openai( 'POST', self::RESPONSES_API_URL, $payload, $background ? 120 : 900 );

        $run_id = AIG_Deep_Research_Store::create_run(
            [
                'user_id'         => get_current_user_id(),
                'status'          => self::map_run_status( (string) ( $response['status'] ?? 'queued' ) ),
                'model'           => $model,
                'title'           => $title,
                'prompt'          => $prompt,
                'background'      => $background,
                'max_tool_calls'  => $max_tool_calls,
                'response_type'   => $response_type,
                'reasoning_effort'=> $reasoning_effort,
                'verbosity'       => $verbosity,
                'response_id'     => (string) ( $response['id'] ?? '' ),
                'response_status' => (string) ( $response['status'] ?? '' ),
                'source_config'   => $tools_config,
                'request_payload' => $payload,
                'response_payload'=> $response,
                'last_error'      => '',
            ]
        );

        self::sync_response_to_run( $run_id, $response );

        $run = AIG_Deep_Research_Store::get_run( $run_id );

        if ( ! $run ) {
            throw new RuntimeException( 'Deep Research run was created but could not be reloaded.' );
        }

        return self::hydrate_run( $run );
    }

    public static function list_runs( bool $refresh_active = false ): array {
        if ( $refresh_active ) {
            self::poll_active_runs();
        }

        return self::hydrate_runs( AIG_Deep_Research_Store::list_runs( 50 ) );
    }

    public static function list_sources(): array {
        return AIG_Deep_Research_Store::list_sources();
    }

    public static function create_source( array $args ): array {
        $source_type = sanitize_key( (string) ( $args['source_type'] ?? 'mcp' ) );
        $name        = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
        $server_url  = esc_url_raw( (string) ( $args['server_url'] ?? '' ) );
        $label       = sanitize_text_field( (string) ( $args['server_label'] ?? $name ) );
        $status      = ! empty( $args['active'] ) ? 'active' : 'inactive';

        if ( 'mcp' !== $source_type ) {
            throw new RuntimeException( 'Only MCP sources are supported in this release.' );
        }

        if ( '' === $name ) {
            throw new RuntimeException( 'Source name is required.' );
        }

        if ( '' === $server_url ) {
            throw new RuntimeException( 'MCP server URL is required.' );
        }

        $source_id = AIG_Deep_Research_Store::create_source(
            [
                'source_type' => 'mcp',
                'name'        => $name,
                'status'      => $status,
                'config'      => [
                    'server_label'  => '' !== $label ? $label : $name,
                    'server_url'    => $server_url,
                    'authorization' => sanitize_text_field( (string) ( $args['authorization'] ?? '' ) ),
                ],
            ]
        );

        $source = AIG_Deep_Research_Store::get_source( $source_id );

        if ( ! $source ) {
            throw new RuntimeException( 'The source was created but could not be reloaded.' );
        }

        return $source;
    }

    public static function delete_source( int $source_id ): void {
        $source = AIG_Deep_Research_Store::get_source( $source_id );

        if ( ! $source ) {
            throw new RuntimeException( 'Source not found.' );
        }

        AIG_Deep_Research_Store::delete_source( $source_id );
    }

    public static function list_vector_stores(): array {
        $response = self::request_openai(
            'GET',
            add_query_arg(
                [
                    'limit' => 100,
                    'order' => 'desc',
                ],
                self::VECTOR_STORES_API_URL
            ),
            null,
            180,
            [
                'OpenAI-Beta' => 'assistants=v2',
            ]
        );

        $stores = [];
        foreach ( $response['data'] ?? [] as $store ) {
            if ( ! is_array( $store ) ) {
                continue;
            }

            $stores[] = [
                'id'         => sanitize_text_field( (string) ( $store['id'] ?? '' ) ),
                'name'       => sanitize_text_field( (string) ( $store['name'] ?? '' ) ),
                'status'     => sanitize_text_field( (string) ( $store['status'] ?? '' ) ),
                'file_counts'=> is_array( $store['file_counts'] ?? null ) ? $store['file_counts'] : [],
                'created_at' => ! empty( $store['created_at'] ) ? gmdate( 'c', (int) $store['created_at'] ) : '',
            ];
        }

        return $stores;
    }

    public static function create_vector_store( array $args ): array {
        $name = sanitize_text_field( (string) ( $args['name'] ?? '' ) );

        if ( '' === $name ) {
            throw new RuntimeException( 'Vector store name is required.' );
        }

        $response = self::request_openai(
            'POST',
            self::VECTOR_STORES_API_URL,
            [
                'name' => $name,
            ],
            180,
            [
                'OpenAI-Beta' => 'assistants=v2',
            ]
        );

        return [
            'id'         => sanitize_text_field( (string) ( $response['id'] ?? '' ) ),
            'name'       => sanitize_text_field( (string) ( $response['name'] ?? '' ) ),
            'status'     => sanitize_text_field( (string) ( $response['status'] ?? '' ) ),
            'file_counts'=> is_array( $response['file_counts'] ?? null ) ? $response['file_counts'] : [],
            'created_at' => ! empty( $response['created_at'] ) ? gmdate( 'c', (int) $response['created_at'] ) : '',
        ];
    }

    public static function delete_vector_store( string $vector_store_id ): void {
        $vector_store_id = sanitize_text_field( $vector_store_id );

        if ( '' === $vector_store_id ) {
            throw new RuntimeException( 'Vector store id is required.' );
        }

        self::request_openai(
            'DELETE',
            trailingslashit( self::VECTOR_STORES_API_URL ) . rawurlencode( $vector_store_id ),
            null,
            180,
            [
                'OpenAI-Beta' => 'assistants=v2',
            ]
        );
    }

    public static function get_webhook_details(): array {
        $settings = AIG_Deep_Research_Settings::all();

        return [
            'url'              => rest_url( AIG_Rest_API::REST_NAMESPACE . '/deep-research/webhook' ),
            'enabled'          => ! empty( $settings['webhook_enabled'] ),
            'secret_configured'=> '' !== trim( (string) ( $settings['webhook_secret'] ?? '' ) ),
            'verification'     => class_exists( '\StandardWebhooks\Webhook' ) ? 'standard-webhooks' : 'none',
        ];
    }

    public static function handle_webhook( WP_REST_Request $request ): array {
        $settings = AIG_Deep_Research_Settings::all();

        if ( empty( $settings['webhook_enabled'] ) ) {
            throw new RuntimeException( 'Deep Research webhooks are not enabled.' );
        }

        $payload = (string) $request->get_body();
        $headers = array_map(
            static function ( $values ) {
                return is_array( $values ) ? (string) reset( $values ) : (string) $values;
            },
            $request->get_headers()
        );

        self::verify_webhook_payload( $payload, $headers, (string) ( $settings['webhook_secret'] ?? '' ) );

        $webhook_id = sanitize_text_field( (string) ( $headers['webhook-id'] ?? '' ) );
        if ( '' !== $webhook_id ) {
            $dedupe_key = 'aig_dr_webhook_' . md5( $webhook_id );
            if ( get_transient( $dedupe_key ) ) {
                return [
                    'processed' => false,
                    'duplicate' => true,
                ];
            }
            set_transient( $dedupe_key, 1, 3 * DAY_IN_SECONDS );
        }

        $event = json_decode( $payload, true );
        if ( ! is_array( $event ) ) {
            throw new RuntimeException( 'Webhook payload is not valid JSON.' );
        }

        $response_id = self::extract_response_id_from_webhook( $event );
        if ( '' === $response_id ) {
            return [
                'processed' => false,
                'duplicate' => false,
                'ignored'   => true,
            ];
        }

        $run = self::sync_run_by_response_id( $response_id, $event );

        return [
            'processed' => null !== $run,
            'duplicate' => false,
            'ignored'   => null === $run,
            'run_id'    => (int) ( $run['id'] ?? 0 ),
        ];
    }

    public static function get_run( int $run_id, bool $refresh = false ): array {
        $run = AIG_Deep_Research_Store::get_run( $run_id );

        if ( ! $run ) {
            throw new RuntimeException( 'Deep Research run not found.' );
        }

        if ( $refresh && ! empty( $run['response_id'] ) && in_array( $run['response_status'], [ 'queued', 'in_progress' ], true ) ) {
            $response = self::request_openai(
                'GET',
                trailingslashit( self::RESPONSES_API_URL ) . rawurlencode( (string) $run['response_id'] ),
                null,
                180
            );
            self::sync_response_to_run( $run_id, $response );
            $run = AIG_Deep_Research_Store::get_run( $run_id );
        }

        if ( ! $run ) {
            throw new RuntimeException( 'Deep Research run could not be loaded.' );
        }

        return self::hydrate_run( $run );
    }

    public static function cancel_run( int $run_id ): array {
        $run = self::get_run( $run_id, false );

        if ( empty( $run['response_id'] ) ) {
            throw new RuntimeException( 'This run does not have an OpenAI response id.' );
        }

        $response = self::request_openai(
            'POST',
            trailingslashit( self::RESPONSES_API_URL ) . rawurlencode( (string) $run['response_id'] ) . '/cancel',
            [],
            180
        );

        self::sync_response_to_run( $run_id, $response );

        return self::get_run( $run_id, false );
    }

    public static function create_draft_from_run( int $run_id, string $post_type ): array {
        $run = self::get_run( $run_id, false );

        if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
            throw new RuntimeException( 'Only post and page drafts are supported.' );
        }

        if ( empty( $run['report_message'] ) ) {
            throw new RuntimeException( 'This run does not have a completed report yet.' );
        }

        $title = $run['title'];
        if ( '' === $title ) {
            $title = wp_trim_words( $run['prompt'], 10, '' );
        }

        $content = wpautop( esc_html( (string) $run['report_message'] ) );
        $sources = self::build_sources_html( is_array( $run['report_annotations'] ?? null ) ? $run['report_annotations'] : [] );

        if ( '' !== $sources ) {
            $content .= "\n\n<h2>Sources</h2>\n" . $sources;
        }

        $post_id = wp_insert_post(
            [
                'post_type'    => $post_type,
                'post_status'  => 'draft',
                'post_title'   => $title,
                'post_content' => $content,
            ],
            true
        );

        if ( is_wp_error( $post_id ) ) {
            throw new RuntimeException( $post_id->get_error_message() );
        }

        update_post_meta( $post_id, '_aig_deep_research_run_id', $run_id );
        update_post_meta( $post_id, '_aig_deep_research_response_id', (string) ( $run['response_id'] ?? '' ) );

        AIG_Deep_Research_Store::update_run(
            $run_id,
            [
                'draft_post_id' => (int) $post_id,
            ]
        );

        return [
            'post_id'   => (int) $post_id,
            'edit_link' => get_edit_post_link( (int) $post_id, 'raw' ),
        ];
    }

    public static function poll_active_runs(): void {
        foreach ( AIG_Deep_Research_Store::list_active_runs() as $run ) {
            $run_id = absint( $run['id'] ?? 0 );
            $response_id = sanitize_text_field( (string) ( $run['response_id'] ?? '' ) );

            if ( $run_id <= 0 || '' === $response_id ) {
                continue;
            }

            try {
                $response = self::request_openai(
                    'GET',
                    trailingslashit( self::RESPONSES_API_URL ) . rawurlencode( $response_id ),
                    null,
                    180
                );
                self::sync_response_to_run( $run_id, $response );
            } catch ( \Throwable $e ) {
                AIG_Deep_Research_Store::update_run(
                    $run_id,
                    [
                        'last_error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    public static function sync_run_by_response_id( string $response_id, ?array $event = null ): ?array {
        $run = AIG_Deep_Research_Store::get_run_by_response_id( $response_id );

        if ( ! $run ) {
            return null;
        }

        $response = null;
        if ( is_array( $event ) ) {
            if ( ! empty( $event['data'] ) && is_array( $event['data'] ) && ! empty( $event['data']['id'] ) ) {
                $response = $event['data'];
            } elseif ( ! empty( $event['response'] ) && is_array( $event['response'] ) && ! empty( $event['response']['id'] ) ) {
                $response = $event['response'];
            }
        }

        if ( ! is_array( $response ) || empty( $response['output'] ) ) {
            $response = self::request_openai(
                'GET',
                trailingslashit( self::RESPONSES_API_URL ) . rawurlencode( $response_id ),
                null,
                180
            );
        }

        self::sync_response_to_run( (int) $run['id'], $response );

        return AIG_Deep_Research_Store::get_run( (int) $run['id'] );
    }

    private static function build_request_payload( string $prompt, string $model, bool $background, int $max_tool_calls, array $tools_config, string $response_type, string $reasoning_effort, string $verbosity ): array {
        $payload = [
            'model'          => $model,
            'input'          => $prompt,
            'background'     => $background,
            'store'          => true,
            'max_tool_calls' => $max_tool_calls,
            'tools'          => self::build_tools( $tools_config ),
            'include'        => [
                'output[*].file_search_call.search_results',
            ],
            'text'           => [
                'format'   => [
                    'type' => $response_type,
                ],
                'verbosity' => $verbosity,
            ],
            'reasoning'      => [
                'effort' => $reasoning_effort,
            ],
        ];

        if ( ! empty( $tools_config['code_interpreter_enabled'] ) ) {
            $payload['instructions'] = 'Use the python tool when calculations, synthesis, or file analysis would improve the research report.';
        }

        return $payload;
    }

    private static function build_tools( array $tools_config ): array {
        $tools = [];

        if ( ! empty( $tools_config['web_search_enabled'] ) ) {
            $tools[] = [
                'type' => 'web_search_preview',
            ];
        }

        if ( ! empty( $tools_config['vector_store_ids'] ) ) {
            $tools[] = [
                'type'             => 'file_search',
                'vector_store_ids' => array_values( array_slice( $tools_config['vector_store_ids'], 0, 2 ) ),
            ];
        }

        if ( ! empty( $tools_config['mcp_servers'] ) ) {
            foreach ( $tools_config['mcp_servers'] as $server ) {
                if ( empty( $server['server_url'] ) || empty( $server['server_label'] ) ) {
                    continue;
                }

                $tool = [
                    'type'             => 'mcp',
                    'server_label'     => (string) $server['server_label'],
                    'server_url'       => (string) $server['server_url'],
                    'allowed_tools'    => [ 'search', 'fetch' ],
                    'require_approval' => 'never',
                ];

                if ( ! empty( $server['authorization'] ) ) {
                    $tool['authorization'] = (string) $server['authorization'];
                }

                $tools[] = $tool;
            }
        }

        if ( ! empty( $tools_config['code_interpreter_enabled'] ) ) {
            $tool = [
                'type'      => 'code_interpreter',
                'container' => [
                    'type' => 'auto',
                ],
            ];

            if ( '' !== (string) ( $tools_config['code_memory_limit'] ?? '' ) ) {
                $tool['container']['memory_limit'] = (string) $tools_config['code_memory_limit'];
            }

            $tools[] = $tool;
        }

        return $tools;
    }

    private static function normalize_tools_config( array $args ): array {
        $default_domains = AIG_Deep_Research_Settings::get( 'web_domain_allowlist', [] );
        $allow_domains   = $args['web_domain_allowlist'] ?? $default_domains;
        $block_domains   = $args['web_domain_blocklist'] ?? [];
        $domain_mode     = sanitize_key( (string) ( $args['web_domain_mode'] ?? '' ) );

        if ( is_string( $allow_domains ) ) {
            $allow_domains = preg_split( '/[\r\n,]+/', $allow_domains );
        }

        if ( is_string( $block_domains ) ) {
            $block_domains = preg_split( '/[\r\n,]+/', $block_domains );
        }

        $vector_store_ids = $args['vector_store_ids'] ?? [];
        if ( is_string( $vector_store_ids ) ) {
            $vector_store_ids = preg_split( '/[\r\n,]+/', $vector_store_ids );
        }

        $mcp_servers = $args['mcp_servers'] ?? [];
        if ( is_string( $mcp_servers ) ) {
            $decoded = json_decode( $mcp_servers, true );
            $mcp_servers = is_array( $decoded ) ? $decoded : [];
        }

        $saved_source_ids = $args['saved_source_ids'] ?? [];
        if ( ! is_array( $saved_source_ids ) ) {
            $saved_source_ids = [ $saved_source_ids ];
        }

        foreach ( $saved_source_ids as $source_id ) {
            $source = AIG_Deep_Research_Store::get_source( absint( $source_id ) );

            if ( ! $source || 'mcp' !== ( $source['source_type'] ?? '' ) || 'active' !== ( $source['status'] ?? '' ) ) {
                continue;
            }

            $config = is_array( $source['config'] ?? null ) ? $source['config'] : [];
            $mcp_servers[] = [
                'server_label'  => $config['server_label'] ?? $source['name'],
                'server_url'    => $config['server_url'] ?? '',
                'authorization' => $config['authorization'] ?? '',
            ];
        }

        $mcp_server_url = trim( (string) ( $args['mcp_server_url'] ?? '' ) );
        if ( '' !== $mcp_server_url ) {
            $mcp_servers[] = [
                'server_label' => sanitize_text_field( (string) ( $args['mcp_server_label'] ?? 'mcp-source' ) ),
                'server_url'   => esc_url_raw( $mcp_server_url ),
                'authorization'=> sanitize_text_field( (string) ( $args['mcp_authorization'] ?? '' ) ),
            ];
        }

        $normalize_domains = static function ( $domains ): array {
            return array_values(
                array_slice(
                    array_filter(
                        array_map(
                            static function ( $domain ): string {
                                $domain = strtolower( trim( (string) $domain ) );
                                $domain = preg_replace( '#^https?://#', '', $domain );
                                return trim( sanitize_text_field( $domain ), '/' );
                            },
                            is_array( $domains ) ? $domains : []
                        )
                    ),
                    0,
                    100
                )
            );
        };

        $allow_domains = $normalize_domains( $allow_domains );
        $block_domains = $normalize_domains( $block_domains );

        $vector_store_ids = array_values(
            array_slice(
                array_filter(
                    array_map(
                        static fn( $id ): string => sanitize_text_field( trim( (string) $id ) ),
                        is_array( $vector_store_ids ) ? $vector_store_ids : []
                    )
                ),
                0,
                2
            )
        );

        $normalized_mcp = [];
        foreach ( is_array( $mcp_servers ) ? $mcp_servers : [] as $server ) {
            if ( ! is_array( $server ) ) {
                continue;
            }

            $normalized_mcp[] = [
                'server_label'  => sanitize_text_field( (string) ( $server['server_label'] ?? 'mcp-source' ) ),
                'server_url'    => esc_url_raw( (string) ( $server['server_url'] ?? '' ) ),
                'authorization' => sanitize_text_field( (string) ( $server['authorization'] ?? '' ) ),
            ];
        }

        if ( ! in_array( $domain_mode, [ 'allow_all', 'allow_only', 'block' ], true ) ) {
            $domain_mode = ! empty( $block_domains ) ? 'block' : ( ! empty( $allow_domains ) ? 'allow_only' : 'allow_all' );
        }

        $memory_limit = (string) ( $args['code_memory_limit'] ?? AIG_Deep_Research_Settings::get( 'default_code_memory_limit', '' ) );
        if ( ! in_array( $memory_limit, [ '', '1g', '4g', '16g', '64g' ], true ) ) {
            $memory_limit = (string) AIG_Deep_Research_Settings::get( 'default_code_memory_limit', '' );
        }

        return [
            'web_search_enabled'       => ! empty( $args['web_search_enabled'] ),
            'web_domain_mode'          => $domain_mode,
            'web_domain_allowlist'     => $allow_domains,
            'web_domain_blocklist'     => $block_domains,
            'vector_store_ids'         => $vector_store_ids,
            'saved_source_ids'         => array_values( array_filter( array_map( 'absint', $saved_source_ids ) ) ),
            'mcp_servers'              => $normalized_mcp,
            'code_interpreter_enabled' => ! empty( $args['code_interpreter_enabled'] ),
            'code_memory_limit'        => $memory_limit,
        ];
    }

    private static function assert_supported_model( string $model ): void {
        if ( ! in_array( $model, [ 'o4-mini-deep-research', 'o3-deep-research' ], true ) ) {
            throw new RuntimeException( 'Deep Research currently supports o4-mini-deep-research and o3-deep-research only.' );
        }
    }

    private static function normalize_response_type( string $response_type ): string {
        return in_array( $response_type, [ 'text' ], true ) ? $response_type : 'text';
    }

    private static function normalize_reasoning_effort( string $reasoning_effort ): string {
        return in_array( $reasoning_effort, [ 'low', 'medium', 'high' ], true ) ? $reasoning_effort : 'medium';
    }

    private static function normalize_verbosity( string $verbosity ): string {
        return in_array( $verbosity, [ 'low', 'medium', 'high' ], true ) ? $verbosity : 'medium';
    }

    private static function assert_has_data_source( array $tools_config ): void {
        if ( empty( $tools_config['web_search_enabled'] ) && empty( $tools_config['vector_store_ids'] ) && empty( $tools_config['mcp_servers'] ) ) {
            throw new RuntimeException( 'Deep Research requires at least one data source: web search, vector stores, or a remote MCP server.' );
        }
    }

    private static function request_openai( string $method, string $url, ?array $body, int $timeout, array $extra_headers = [] ): array {
        $api_key = trim( (string) AIG_Settings::get( 'openai_api_key', '' ) );

        if ( '' === $api_key ) {
            throw new RuntimeException( 'OpenAI API key is not configured.' );
        }

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => $timeout,
            'headers' => array_merge( [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ], $extra_headers ),
        ];

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'HTTP error: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );
        $data   = json_decode( $raw, true );

        if ( $status >= 400 ) {
            $message = is_array( $data ) ? ( $data['error']['message'] ?? $data['error'] ?? '' ) : '';
            throw new RuntimeException( '' !== $message ? (string) $message : 'OpenAI request failed.' );
        }

        if ( ! is_array( $data ) ) {
            throw new RuntimeException( 'OpenAI returned an invalid JSON response.' );
        }

        return $data;
    }

    private static function sync_response_to_run( int $run_id, array $response ): void {
        $output      = is_array( $response['output'] ?? null ) ? $response['output'] : [];
        $report_text = '';
        $annotations = [];

        foreach ( $output as $item ) {
            if ( 'message' !== ( $item['type'] ?? '' ) ) {
                continue;
            }

            foreach ( $item['content'] ?? [] as $content ) {
                if ( 'output_text' !== ( $content['type'] ?? '' ) ) {
                    continue;
                }

                $report_text .= (string) ( $content['text'] ?? '' );
                if ( ! empty( $content['annotations'] ) && is_array( $content['annotations'] ) ) {
                    $annotations = array_merge( $annotations, $content['annotations'] );
                }
            }
        }

        $status = (string) ( $response['status'] ?? '' );
        $update = [
            'status'             => self::map_run_status( $status ),
            'response_status'    => $status,
            'response_payload'   => $response,
            'report_message'     => trim( $report_text ),
            'report_annotations' => $annotations,
            'last_error'         => isset( $response['error']['message'] ) ? (string) $response['error']['message'] : '',
        ];

        if ( in_array( $status, [ 'completed', 'failed', 'cancelled', 'canceled', 'incomplete' ], true ) ) {
            $update['completed_at'] = current_time( 'mysql', true );
        }

        AIG_Deep_Research_Store::update_run( $run_id, $update );
        AIG_Deep_Research_Store::replace_run_items( $run_id, $output );
    }

    private static function hydrate_runs( array $runs ): array {
        return array_map( [ self::class, 'hydrate_run' ], $runs );
    }

    private static function hydrate_run( array $run ): array {
        $tools_metrics = self::build_tool_metrics( is_array( $run['items'] ?? null ) ? $run['items'] : [] );
        $usage_metrics = self::build_usage_metrics( $run );
        $output_items   = is_array( $run['response_payload']['output'] ?? null ) ? $run['response_payload']['output'] : [];

        $run['can_stop'] = ! empty( $run['response_id'] ) && in_array( (string) ( $run['response_status'] ?? '' ), [ 'queued', 'in_progress' ], true );
        $run['metrics']  = [
            'usage' => $usage_metrics,
            'tools' => $tools_metrics,
        ];
        $run['tool_trace'] = self::build_tool_trace( $output_items );
        $run['citations']  = self::build_citations( $output_items );

        return $run;
    }

    private static function build_usage_metrics( array $run ): array {
        $response = is_array( $run['response_payload'] ?? null ) ? $run['response_payload'] : [];
        $usage    = is_array( $response['usage'] ?? null ) ? $response['usage'] : [];

        $input_tokens      = isset( $usage['input_tokens'] ) ? max( 0, (int) $usage['input_tokens'] ) : null;
        $output_tokens     = isset( $usage['output_tokens'] ) ? max( 0, (int) $usage['output_tokens'] ) : null;
        $reasoning_tokens  = null;
        $output_details    = is_array( $usage['output_tokens_details'] ?? null ) ? $usage['output_tokens_details'] : [];
        $input_details     = is_array( $usage['input_tokens_details'] ?? null ) ? $usage['input_tokens_details'] : [];

        if ( isset( $output_details['reasoning_tokens'] ) ) {
            $reasoning_tokens = max( 0, (int) $output_details['reasoning_tokens'] );
        } elseif ( isset( $input_details['cached_tokens'] ) ) {
            $reasoning_tokens = null;
        }

        $total_tokens = isset( $usage['total_tokens'] ) ? max( 0, (int) $usage['total_tokens'] ) : null;
        if ( null === $total_tokens && null !== $input_tokens && null !== $output_tokens ) {
            $total_tokens = $input_tokens + $output_tokens;
        }

        $cost_usd = self::calculate_model_cost( (string) ( $run['model'] ?? '' ), $input_tokens, $output_tokens );

        if ( null !== $input_tokens || null !== $output_tokens || null !== $total_tokens ) {
            return [
                'provider'         => 'openai',
                'model'            => (string) ( $run['model'] ?? '' ),
                'input_tokens'     => $input_tokens,
                'reasoning_tokens' => $reasoning_tokens,
                'output_tokens'    => $output_tokens,
                'total_tokens'     => $total_tokens,
                'cost_usd'         => null !== $cost_usd ? round( $cost_usd, 6 ) : null,
                'estimated'        => false,
                'source'           => 'openai',
            ];
        }

        $estimate = AIG_Token_Usage_Estimator::begin_estimate(
            'openai',
            (string) ( $run['model'] ?? '' ),
            (string) ( $run['prompt'] ?? '' )
        );

        if ( ! empty( $estimate ) ) {
            $estimate = AIG_Token_Usage_Estimator::update_estimate(
                $estimate,
                (string) ( $run['report_message'] ?? '' )
            );
        }

        return [
            'provider'         => 'openai',
            'model'            => (string) ( $run['model'] ?? '' ),
            'input_tokens'     => isset( $estimate['input_tokens'] ) ? (int) $estimate['input_tokens'] : null,
            'reasoning_tokens' => null,
            'output_tokens'    => isset( $estimate['output_tokens'] ) ? (int) $estimate['output_tokens'] : null,
            'total_tokens'     => isset( $estimate['total_tokens'] ) ? (int) $estimate['total_tokens'] : null,
            'cost_usd'         => null !== $cost_usd ? round( $cost_usd, 6 ) : null,
            'estimated'        => true,
            'source'           => ! empty( $estimate['estimate_source'] ) ? (string) $estimate['estimate_source'] : 'tiktoken',
        ];
    }

    private static function calculate_model_cost( string $model, ?int $input_tokens, ?int $output_tokens ): ?float {
        if ( null === $input_tokens || null === $output_tokens ) {
            return null;
        }

        $pricing = self::MODEL_PRICING[ $model ] ?? null;
        if ( ! is_array( $pricing ) ) {
            return null;
        }

        return (
            ( $input_tokens / 1000000 ) * (float) ( $pricing['input'] ?? 0 )
        ) + (
            ( $output_tokens / 1000000 ) * (float) ( $pricing['output'] ?? 0 )
        );
    }

    private static function build_tool_metrics( array $items ): array {
        $details = [];

        foreach ( self::TOOL_ITEM_TYPES as $type ) {
            $details[ $type ] = [
                'count'            => 0,
                'input_tokens'     => null,
                'output_tokens'    => null,
                'reasoning_tokens' => null,
                'total_tokens'     => null,
                'has_usage'        => false,
            ];
        }

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $type = sanitize_key( (string) ( $item['type'] ?? '' ) );
            if ( ! isset( $details[ $type ] ) ) {
                continue;
            }

            $details[ $type ]['count']++;

            $usage = is_array( $item['usage'] ?? null ) ? $item['usage'] : [];
            if ( empty( $usage ) ) {
                continue;
            }

            $details[ $type ]['input_tokens'] = self::sum_nullable_int( $details[ $type ]['input_tokens'], $usage['input_tokens'] ?? null );
            $details[ $type ]['output_tokens'] = self::sum_nullable_int( $details[ $type ]['output_tokens'], $usage['output_tokens'] ?? null );
            $details[ $type ]['total_tokens'] = self::sum_nullable_int( $details[ $type ]['total_tokens'], $usage['total_tokens'] ?? null );

            $output_details = is_array( $usage['output_tokens_details'] ?? null ) ? $usage['output_tokens_details'] : [];
            $details[ $type ]['reasoning_tokens'] = self::sum_nullable_int( $details[ $type ]['reasoning_tokens'], $output_details['reasoning_tokens'] ?? null );
            $details[ $type ]['has_usage'] = true;
        }

        return [
            'total_calls' => array_sum( array_map( static fn( array $detail ): int => (int) $detail['count'], $details ) ),
            'details'     => $details,
        ];
    }

    private static function build_tool_trace( array $items ): array {
        $trace = [];

        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $entry = [
                'index' => $index,
                'type'  => sanitize_text_field( (string) ( $item['type'] ?? '' ) ),
            ];

            foreach ( [ 'id', 'status', 'action', 'name', 'tool_name', 'call_id', 'item_id' ] as $key ) {
                if ( ! empty( $item[ $key ] ) ) {
                    $entry[ $key ] = sanitize_text_field( (string) $item[ $key ] );
                }
            }

            if ( ! empty( $item['content'] ) && is_array( $item['content'] ) ) {
                $entry['content'] = [];
                foreach ( $item['content'] as $content ) {
                    if ( ! is_array( $content ) ) {
                        continue;
                    }

                    $content_entry = [
                        'type' => sanitize_text_field( (string) ( $content['type'] ?? '' ) ),
                    ];

                    foreach ( [ 'text', 'input', 'output', 'status', 'id' ] as $key ) {
                        if ( isset( $content[ $key ] ) && '' !== (string) $content[ $key ] ) {
                            $content_entry[ $key ] = is_scalar( $content[ $key ] ) ? sanitize_text_field( (string) $content[ $key ] ) : wp_json_encode( $content[ $key ] );
                        }
                    }

                    $entry['content'][] = $content_entry;
                }
            }

            if ( ! empty( $item['queries'] ) ) {
                $entry['queries'] = array_values(
                    array_filter(
                        array_map(
                            static fn( $query ) => sanitize_text_field( (string) $query ),
                            is_array( $item['queries'] ) ? $item['queries'] : [ $item['queries'] ]
                        )
                    )
                );
            }

            if ( ! empty( $item['usage'] ) && is_array( $item['usage'] ) ) {
                $entry['usage'] = [
                    'input_tokens'  => isset( $item['usage']['input_tokens'] ) ? max( 0, (int) $item['usage']['input_tokens'] ) : null,
                    'output_tokens' => isset( $item['usage']['output_tokens'] ) ? max( 0, (int) $item['usage']['output_tokens'] ) : null,
                    'total_tokens'  => isset( $item['usage']['total_tokens'] ) ? max( 0, (int) $item['usage']['total_tokens'] ) : null,
                ];
            }

            $trace[] = $entry;
        }

        return $trace;
    }

    private static function build_citations( array $items ): array {
        $citations = [];
        $current_index = null;

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            if ( 'web_search_call' === ( $item['type'] ?? '' ) ) {
                $citations[] = [
                    'id'      => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
                    'action'   => sanitize_text_field( (string) ( $item['action'] ?? '' ) ),
                    'queries'  => array_values(
                        array_filter(
                            array_map(
                                static fn( $query ) => sanitize_text_field( (string) $query ),
                                is_array( $item['queries'] ?? null ) ? $item['queries'] : ( empty( $item['queries'] ) ? [] : [ $item['queries'] ] )
                            )
                        )
                    ),
                    'urls'     => [],
                ];
                $current_index = array_key_last( $citations );
                continue;
            }

            if ( 'message' !== ( $item['type'] ?? '' ) || null === $current_index ) {
                continue;
            }

            foreach ( $item['content'] ?? [] as $content ) {
                if ( ! is_array( $content ) || 'output_text' !== ( $content['type'] ?? '' ) ) {
                    continue;
                }

                foreach ( $content['annotations'] ?? [] as $annotation ) {
                    if ( ! is_array( $annotation ) || 'url_citation' !== ( $annotation['type'] ?? '' ) || empty( $annotation['url'] ) ) {
                        continue;
                    }

                    if ( empty( $citations[ $current_index ]['urls'] ) || ! is_array( $citations[ $current_index ]['urls'] ) ) {
                        $citations[ $current_index ]['urls'] = [];
                    }

                    $citations[ $current_index ]['urls'][ (string) $annotation['url'] ] = [
                        'url'      => esc_url_raw( (string) $annotation['url'] ),
                        'title'    => sanitize_text_field( (string) ( $annotation['title'] ?? '' ) ),
                        'location' => sanitize_text_field( (string) ( $annotation['location'] ?? '' ) ),
                    ];
                }
            }

            if ( ! empty( $citations[ $current_index ]['urls'] ) ) {
                $citations[ $current_index ]['urls'] = array_values( $citations[ $current_index ]['urls'] );
            }
        }

        return array_values( array_filter( $citations, static fn( $entry ): bool => ! empty( $entry['urls'] ) ) );
    }

    private static function sum_nullable_int( $current, $next ): ?int {
        $current = null === $current ? null : (int) $current;

        if ( null === $next || '' === $next ) {
            return $current;
        }

        return ( null === $current ? 0 : $current ) + max( 0, (int) $next );
    }

    private static function verify_webhook_payload( string $payload, array $headers, string $secret ): void {
        if ( '' === trim( $secret ) ) {
            return;
        }

        if ( ! class_exists( '\StandardWebhooks\Webhook' ) ) {
            throw new RuntimeException( 'Webhook secret verification requires the standard-webhooks PHP library.' );
        }

        try {
            $verifier = new \StandardWebhooks\Webhook( $secret );
            $verifier->verify( $payload, $headers );
        } catch ( \Throwable $e ) {
            throw new RuntimeException( 'Webhook verification failed: ' . $e->getMessage() );
        }
    }

    private static function extract_response_id_from_webhook( array $event ): string {
        $type = sanitize_text_field( (string) ( $event['type'] ?? '' ) );

        if ( '' !== $type && 0 !== strpos( $type, 'response.' ) ) {
            return '';
        }

        $candidates = [
            $event['data']['id'] ?? '',
            $event['response']['id'] ?? '',
            $event['id'] ?? '',
        ];

        foreach ( $candidates as $candidate ) {
            $candidate = sanitize_text_field( (string) $candidate );
            if ( '' !== $candidate && 0 === strpos( $candidate, 'resp_' ) ) {
                return $candidate;
            }
        }

        return '';
    }

    private static function map_run_status( string $response_status ): string {
        return match ( $response_status ) {
            'queued'      => 'queued',
            'in_progress' => 'running',
            'completed'   => 'completed',
            'cancelled'   => 'cancelled',
            'canceled'    => 'cancelled',
            'failed',
            'incomplete'  => 'failed',
            default       => 'draft',
        };
    }

    private static function build_sources_html( array $annotations ): string {
        $sources = [];

        foreach ( $annotations as $annotation ) {
            if ( ! is_array( $annotation ) ) {
                continue;
            }

            if ( 'url_citation' === ( $annotation['type'] ?? '' ) && ! empty( $annotation['url'] ) ) {
                $url = esc_url( (string) $annotation['url'] );
                $label = esc_html( (string) ( $annotation['title'] ?? $annotation['url'] ) );
                $sources[ $url ] = sprintf( '<li><a href="%1$s">%2$s</a></li>', $url, $label );
            }

            if ( 'file_citation' === ( $annotation['type'] ?? '' ) && ! empty( $annotation['filename'] ) ) {
                $label = esc_html( (string) $annotation['filename'] );
                $sources[ 'file:' . $label ] = sprintf( '<li>%s</li>', $label );
            }
        }

        if ( empty( $sources ) ) {
            return '';
        }

        return "<ul>\n" . implode( "\n", array_values( $sources ) ) . "\n</ul>";
    }
}
