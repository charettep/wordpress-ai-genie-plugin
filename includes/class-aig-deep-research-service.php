<?php
defined( 'ABSPATH' ) || exit;

class AIG_Deep_Research_Service {

    private const RESPONSES_API_URL = 'https://api.openai.com/v1/responses';
    private const VECTOR_STORES_API_URL = 'https://api.openai.com/v1/vector_stores';

    public static function create_run( array $args ): array {
        $title          = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
        $prompt         = trim( (string) ( $args['prompt'] ?? '' ) );
        $model          = sanitize_text_field( (string) ( $args['model'] ?? AIG_Deep_Research_Settings::get( 'default_model', 'o4-mini-deep-research' ) ) );
        $background     = ! empty( $args['background'] );
        $max_tool_calls = max( 1, min( 100, absint( $args['max_tool_calls'] ?? AIG_Deep_Research_Settings::get( 'default_max_tool_calls', 12 ) ) ) );
        $tools_config   = self::normalize_tools_config( $args );

        if ( '' === $prompt ) {
            throw new RuntimeException( 'Deep Research prompt is required.' );
        }

        self::assert_supported_model( $model );
        self::assert_has_data_source( $tools_config );

        $payload  = self::build_request_payload( $prompt, $model, $background, $max_tool_calls, $tools_config );
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

        return $run;
    }

    public static function list_runs(): array {
        return AIG_Deep_Research_Store::list_runs( 50 );
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

        return $run;
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

    private static function build_request_payload( string $prompt, string $model, bool $background, int $max_tool_calls, array $tools_config ): array {
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
            $tools[] = [
                'type'      => 'code_interpreter',
                'container' => [
                    'type'         => 'auto',
                    'memory_limit' => (string) ( $tools_config['code_memory_limit'] ?? '1g' ),
                ],
            ];
        }

        return $tools;
    }

    private static function normalize_tools_config( array $args ): array {
        $default_domains = AIG_Deep_Research_Settings::get( 'web_domain_allowlist', [] );
        $domains         = $args['web_domain_allowlist'] ?? $default_domains;

        if ( is_string( $domains ) ) {
            $domains = preg_split( '/[\r\n,]+/', $domains );
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

        $domains = array_values(
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

        $memory_limit = (string) ( $args['code_memory_limit'] ?? AIG_Deep_Research_Settings::get( 'default_code_memory_limit', '1g' ) );
        if ( ! in_array( $memory_limit, [ '1g', '4g', '16g', '64g' ], true ) ) {
            $memory_limit = (string) AIG_Deep_Research_Settings::get( 'default_code_memory_limit', '1g' );
        }

        return [
            'web_search_enabled'       => ! empty( $args['web_search_enabled'] ),
            'web_domain_allowlist'     => $domains,
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

        if ( in_array( $status, [ 'completed', 'failed', 'cancelled', 'incomplete' ], true ) ) {
            $update['completed_at'] = current_time( 'mysql', true );
        }

        AIG_Deep_Research_Store::update_run( $run_id, $update );
        AIG_Deep_Research_Store::replace_run_items( $run_id, $output );
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
