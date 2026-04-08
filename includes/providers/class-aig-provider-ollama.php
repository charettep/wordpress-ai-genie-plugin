<?php
defined( 'ABSPATH' ) || exit;

class AIG_Provider_Ollama extends AIG_Provider {

    const DEFAULT_BASE_URL = 'http://localhost:11434';
    const DOCKER_HOST_ALIAS = 'host.docker.internal';
    const DOCKER_PROXY_PORT = 11435;
    const REQUEST_TIMEOUT = 600;
    public function id(): string    { return 'ollama'; }
    public function label(): string { return 'Ollama'; }

    public function is_configured( array $config = [] ): bool {
        return '' !== trim( (string) $this->resolve_setting( 'ollama_url', '', $config ) );
    }

    public function discover_models( array $config = [] ): array {
        $data = $this->request( 'GET', '/api/tags', [], $config );

        $models = array_filter(
            array_map(
                static function ( array $item ): ?array {
                    $id = trim( (string) ( $item['model'] ?? $item['name'] ?? '' ) );

                    if ( '' === $id ) {
                        return null;
                    }

                    return [
                        'id'    => $id,
                        'label' => (string) ( $item['name'] ?? $id ),
                    ];
                },
                $data['models'] ?? []
            )
        );

        usort(
            $models,
            static function ( array $a, array $b ): int {
                return strcasecmp( $a['id'], $b['id'] );
            }
        );

        if ( empty( $models ) ) {
            throw new RuntimeException( 'No Ollama models were returned by this server.' );
        }

        return array_values( $models );
    }

    public function generate( string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens = 0 ): string {
        if ( ! $this->is_configured() ) {
            throw new RuntimeException( 'Ollama URL is not configured.' );
        }

        $model    = $this->resolve_model();
        $attempts = $this->build_generate_attempts( $model, $prompt, $max_output_tokens, $temperature, $max_thinking_tokens );
        $last_err = null;

        foreach ( $attempts as $payload ) {
            try {
                $data    = $this->request( 'POST', '/api/chat', $payload );
                $content = trim( (string) ( $data['message']['content'] ?? '' ) );

                if ( '' !== $content ) {
                    return $content;
                }

                $thinking = trim( (string) ( $data['message']['thinking'] ?? '' ) );
                $last_err = '' !== $thinking
                    ? new RuntimeException( 'Ollama returned internal reasoning without a final answer. Retrying with a direct-answer prompt.' )
                    : new RuntimeException( 'Ollama returned an empty response.' );
            } catch ( RuntimeException $e ) {
                if ( 'Stream canceled by client.' === $e->getMessage() ) {
                    $this->cancel_generation();
                }

                $last_err = $e;
            }
        }

        if ( null !== $last_err ) {
            throw $last_err;
        }

        throw new RuntimeException( 'Ollama returned an empty response.' );
    }

    public function stream_generate( string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens, callable $emit ): array {
        if ( ! function_exists( 'curl_init' ) ) {
            return parent::stream_generate( $prompt, $max_output_tokens, $temperature, $max_thinking_tokens, $emit );
        }

        if ( ! $this->is_configured() ) {
            throw new RuntimeException( 'Ollama URL is not configured.' );
        }

        $generation_id = $this->get_generation_id();
        $model    = $this->resolve_model();
        $attempts = $this->build_stream_attempts( $model, $prompt, $max_output_tokens, $temperature, $max_thinking_tokens );
        $last_err = null;

        $this->clear_cancel_flag( $generation_id );

        try {
            foreach ( $attempts as $payload ) {
                try {
                    $received_text = false;
                    $final_data    = [];

                    $final_data = $this->stream_request(
                        '/api/chat',
                        $payload,
                        static function ( array $data ) use ( $emit, &$received_text ): void {
                            $chunk = (string) ( $data['message']['content'] ?? '' );
                            if ( '' !== $chunk ) {
                                $received_text = true;
                                $emit( $chunk );
                            }
                        },
                        [],
                        $generation_id
                    );

                    if ( $received_text ) {
                        return $this->build_usage_from_stream_response( $final_data, $model );
                    }

                    $last_err = new RuntimeException( 'Ollama streamed no visible output.' );
                } catch ( RuntimeException $e ) {
                    $last_err = $e;
                }
            }

            if ( null !== $last_err ) {
                throw $last_err;
            }

            throw new RuntimeException( 'Ollama streamed no visible output.' );
        } finally {
            $this->clear_cancel_flag( $generation_id );
        }
    }

    public function cancel_generation( string $generation_id = '' ): bool {
        if ( ! $this->is_configured() ) {
            return false;
        }

        $generation_id = '' !== trim( $generation_id ) ? trim( $generation_id ) : $this->get_generation_id();

        if ( '' !== $generation_id ) {
            $this->set_cancel_flag( $generation_id );
        }

        try {
            $model = $this->resolve_model();
        } catch ( RuntimeException $e ) {
            return '' !== $generation_id;
        }

        try {
            // Empty chat + keep_alive=0 asks Ollama to unload the active model runtime.
            $this->request( 'POST', '/api/chat', [
                'model'      => $model,
                'messages'   => [],
                'keep_alive' => 0,
            ] );
            return true;
        } catch ( RuntimeException $e ) {
            return '' !== $generation_id;
        }
    }

    private function resolve_model(): string {
        $override = $this->get_model_override();
        if ( '' !== $override ) {
            return $override;
        }

        $model = trim( (string) AIG_Settings::get( 'ollama_model', '' ) );

        if ( '' !== $model ) {
            return $model;
        }

        $models = $this->discover_models();
        $model  = (string) ( $models[0]['id'] ?? '' );

        if ( '' === $model ) {
            throw new RuntimeException( 'No Ollama model is selected.' );
        }

        return $model;
    }

    private function request( string $method, string $path, array $body = [], array $config = [] ): array {
        $base_urls = $this->get_runtime_base_urls( $config );

        if ( empty( $base_urls ) ) {
            throw new RuntimeException( 'Ollama URL is not configured.' );
        }

        $last_error = null;

        foreach ( $base_urls as $base_url ) {
            $url = $base_url . $path;

            try {
                if ( 'GET' === $method ) {
                    return $this->http_get( $url, $this->build_auth_headers( $config ), self::REQUEST_TIMEOUT );
                }

                return $this->http_post(
                    $url,
                    $body,
                    $this->build_json_headers( $config ),
                    self::REQUEST_TIMEOUT
                );
            } catch ( RuntimeException $e ) {
                $last_error = $e;
            }
        }

        if ( null !== $last_error ) {
            throw $last_error;
        }

        throw new RuntimeException( 'Unable to reach the Ollama server.' );
    }

    /**
     * @param callable(array<string,mixed>):void $on_data
     * @return array<string,mixed>
     */
    private function stream_request( string $path, array $body, callable $on_data, array $config = [], string $generation_id = '' ): array {
        $base_urls = $this->get_runtime_base_urls( $config );

        if ( empty( $base_urls ) ) {
            throw new RuntimeException( 'Ollama URL is not configured.' );
        }

        $last_error = null;

        foreach ( $base_urls as $base_url ) {
            $buffer     = '';
            $curl_error = null;
            $http_code  = 0;
            $last_data  = [];
            $ch         = curl_init( $base_url . $path );
            $should_cancel = function () use ( $generation_id ): bool {
                return connection_aborted() || $this->is_cancel_requested( $generation_id );
            };

            curl_setopt_array( $ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $this->build_curl_headers( $config ),
                CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER         => false,
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_XFERINFOFUNCTION => static function ( $curl, float $download_total, float $download_now, float $upload_total, float $upload_now ) use ( $should_cancel ): int {
                    return $should_cancel() ? 1 : 0;
                },
                CURLOPT_WRITEFUNCTION  => static function ( $curl, string $chunk ) use ( &$buffer, &$last_data, $on_data, $should_cancel ): int {
                    if ( $should_cancel() ) {
                        return 0;
                    }

                    $buffer .= $chunk;

                    while ( false !== ( $pos = strpos( $buffer, "\n" ) ) ) {
                        $line   = trim( substr( $buffer, 0, $pos ) );
                        $buffer = substr( $buffer, $pos + 1 );

                        if ( '' === $line ) {
                            continue;
                        }

                        $decoded = json_decode( $line, true );
                        if ( is_array( $decoded ) ) {
                            $last_data = $decoded;
                            $on_data( $decoded );
                        }
                    }

                    if ( $should_cancel() ) {
                        return 0;
                    }

                    return strlen( $chunk );
                },
            ] );

            curl_exec( $ch );
            $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

            if ( curl_errno( $ch ) ) {
                $curl_error = curl_error( $ch );
            }

            curl_close( $ch );

            if ( null !== $curl_error ) {
                $normalized_error = strtolower( $curl_error );

                if ( $this->is_cancel_requested( $generation_id ) ) {
                    throw new RuntimeException( 'Generation canceled.' );
                }

                if ( connection_aborted() || str_contains( $normalized_error, 'callback aborted' ) || str_contains( $normalized_error, 'aborted by callback' ) ) {
                    throw new RuntimeException( 'Stream canceled by client.' );
                }

                $last_error = new RuntimeException( 'HTTP error: ' . $curl_error );
                continue;
            }

            if ( $http_code >= 400 ) {
                $last_error = new RuntimeException( "HTTP $http_code" );
                continue;
            }

            return $last_data;
        }

        if ( null !== $last_error ) {
            throw $last_error;
        }

        throw new RuntimeException( 'Unable to reach the Ollama server.' );
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function build_usage_from_stream_response( array $response, string $model ): array {
        $input_tokens  = isset( $response['prompt_eval_count'] ) ? (int) $response['prompt_eval_count'] : null;
        $generated_raw = isset( $response['eval_count'] ) ? (int) $response['eval_count'] : null;
        $total_tokens  = ( is_int( $input_tokens ) && is_int( $generated_raw ) )
            ? $input_tokens + $generated_raw
            : null;

        return [
            'provider'        => $this->id(),
            'model'           => $model,
            'input_tokens'    => $input_tokens,
            'thinking_tokens' => null,
            'output_tokens'   => $generated_raw,
            'total_tokens'    => $total_tokens,
            'cost_usd'        => null,
            'currency'        => 'USD',
        ];
    }

    private function get_runtime_base_urls( array $config = [] ): array {
        $base_url = $this->normalize_base_url( (string) $this->resolve_setting( 'ollama_url', self::DEFAULT_BASE_URL, $config ) );

        if ( '' === $base_url ) {
            return [];
        }

        $candidates = [ $base_url ];

        if ( ! $this->should_try_docker_local_fallbacks( $base_url ) ) {
            return array_values( array_unique( $candidates ) );
        }

        $parts = wp_parse_url( $base_url );

        if ( ! is_array( $parts ) ) {
            return array_values( array_unique( $candidates ) );
        }

        $port  = isset( $parts['port'] ) ? (int) $parts['port'] : 11434;
        $proxy = (int) ( getenv( 'AIG_OLLAMA_DOCKER_PROXY_PORT' ) ?: getenv( 'OLLAMA_PROXY_PORT' ) ?: self::DOCKER_PROXY_PORT );

        $candidates[] = $this->build_url_from_parts( $parts, self::DOCKER_HOST_ALIAS, $port );

        if ( $proxy > 0 && $proxy !== $port ) {
            $candidates[] = $this->build_url_from_parts( $parts, self::DOCKER_HOST_ALIAS, $proxy );
        }

        return array_values( array_unique( array_filter( $candidates ) ) );
    }

    private function should_try_docker_local_fallbacks( string $base_url ): bool {
        return $this->is_running_in_docker() && $this->is_loopback_host( $base_url );
    }

    private function is_running_in_docker(): bool {
        return file_exists( '/.dockerenv' );
    }

    private function is_loopback_host( string $base_url ): bool {
        $host = strtolower( (string) wp_parse_url( $base_url, PHP_URL_HOST ) );

        return in_array( $host, [ 'localhost', '127.0.0.1', '::1', '[::1]' ], true );
    }

    private function build_url_from_parts( array $parts, string $host, int $port ): string {
        $scheme = (string) ( $parts['scheme'] ?? 'http' );
        $path   = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';

        return sprintf( '%s://%s:%d%s', $scheme, $host, $port, $path );
    }

    private function normalize_base_url( string $base_url ): string {
        return rtrim( trim( $base_url ), '/' );
    }

    /**
     * @return array<string,string>
     */
    private function build_auth_headers( array $config = [] ): array {
        $header_name  = trim( (string) $this->resolve_setting( 'ollama_auth_header_name', '', $config ) );
        $header_value = trim( (string) $this->resolve_setting( 'ollama_auth_header_value', '', $config ) );

        if ( '' === $header_value ) {
            return [];
        }

        if ( '' === $header_name ) {
            $header_name = 'Authorization';
        }

        return [
            $header_name => $header_value,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function build_json_headers( array $config = [] ): array {
        return array_merge(
            [ 'Content-Type' => 'application/json' ],
            $this->build_auth_headers( $config )
        );
    }

    /**
     * @return array<int,string>
     */
    private function build_curl_headers( array $config = [] ): array {
        $headers = [];

        foreach ( $this->build_json_headers( $config ) as $name => $value ) {
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }

    private function get_cancel_flag_key( string $generation_id ): string {
        return 'acf_ollama_cancel_' . md5( $generation_id );
    }

    private function is_cancel_requested( string $generation_id ): bool {
        if ( '' === $generation_id ) {
            return false;
        }

        global $wpdb;

        if ( ! isset( $wpdb ) || ! $wpdb instanceof wpdb ) {
            return false;
        }

        $option_name = $this->get_cancel_flag_key( $generation_id );
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $option_name
            )
        );

        return null !== $value;
    }

    private function set_cancel_flag( string $generation_id ): void {
        if ( '' === $generation_id ) {
            return;
        }

        update_option( $this->get_cancel_flag_key( $generation_id ), 1, false );
    }

    private function clear_cancel_flag( string $generation_id ): void {
        if ( '' === $generation_id ) {
            return;
        }

        delete_option( $this->get_cancel_flag_key( $generation_id ) );
    }

    /**
     * Some Ollama models default to hidden reasoning before final output.
     * These retries bias them toward direct-answer mode and disable thinking when supported.
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_generate_attempts( string $model, string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens ): array {
        $think_setting = $this->resolve_think_setting( $model, $max_thinking_tokens );
        $options = [
            'temperature' => $temperature,
            'num_predict' => $this->resolve_num_predict_budget( $model, $max_output_tokens, $max_thinking_tokens ),
        ];

        $messages = [
            [ 'role' => 'user', 'content' => $prompt ],
        ];

        $attempt = [
            'model'    => $model,
            'stream'   => false,
            'keep_alive' => 0,
            'options'  => $options,
            'messages' => $messages,
        ];

        if ( null !== $think_setting ) {
            $attempt['think'] = $think_setting;
        }

        $attempts = [ $attempt ];

        if ( $this->is_reasoning_model( $model ) ) {
            $direct_attempt = [
                'model'    => $model,
                'stream'   => false,
                'keep_alive' => 0,
                'options'  => $options,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => 'Respond with the final answer only. Do not expose or narrate your reasoning. Start writing the answer immediately.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => "Write the final answer directly.\n\n" . $prompt,
                    ],
                ],
            ];

            if ( null !== $think_setting ) {
                $direct_attempt['think'] = $max_thinking_tokens > 0 ? $think_setting : false;
            }

            $attempts[] = $direct_attempt;
        }

        return $attempts;
    }

    /**
     * Streaming should favor direct-answer mode for reasoning models so the UI receives visible tokens quickly.
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_stream_attempts( string $model, string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens ): array {
        $attempts = $this->build_generate_attempts( $model, $prompt, $max_output_tokens, $temperature, $max_thinking_tokens );

        foreach ( $attempts as &$attempt ) {
            $attempt['stream'] = true;
        }

        return $attempts;
    }

    private function is_reasoning_model( string $model ): bool {
        $model = strtolower( trim( $model ) );

        foreach ( [ 'deepseek-r1', 'qwen3', 'qwq', 'thinking', 'gpt-oss' ] as $needle ) {
            if ( false !== strpos( $model, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    private function resolve_num_predict_budget( string $model, int $max_output_tokens, int $max_thinking_tokens ): int {
        $budget = max( 1, $max_output_tokens );

        if ( $this->is_reasoning_model( $model ) && $max_thinking_tokens > 0 ) {
            $budget += $max_thinking_tokens;
        }

        return $budget;
    }

    /**
     * Most Ollama reasoning models accept a boolean `think` flag.
     * GPT-OSS uses qualitative levels instead, so map the budget into one.
     *
     * @return bool|string|null
     */
    private function resolve_think_setting( string $model, int $max_thinking_tokens ) {
        if ( ! $this->is_reasoning_model( $model ) ) {
            return null;
        }

        if ( false !== strpos( strtolower( $model ), 'gpt-oss' ) ) {
            if ( $max_thinking_tokens <= 2048 ) {
                return 'low';
            }

            if ( $max_thinking_tokens <= 8192 ) {
                return 'medium';
            }

            return 'high';
        }

        return $max_thinking_tokens > 0;
    }
}
