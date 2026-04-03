<?php
defined( 'ABSPATH' ) || exit;

class ACF_Provider_Ollama extends ACF_Provider {

    const DEFAULT_BASE_URL = 'http://localhost:11434';
    const DOCKER_HOST_ALIAS = 'host.docker.internal';
    const DOCKER_PROXY_PORT = 11435;
    const REQUEST_TIMEOUT = 600;

    public function id(): string    { return 'ollama'; }
    public function label(): string { return 'Ollama (Local)'; }

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

        $model    = $this->resolve_model();
        $attempts = $this->build_stream_attempts( $model, $prompt, $max_output_tokens, $temperature, $max_thinking_tokens );
        $last_err = null;

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
                    }
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
    }

    public function cancel_generation(): bool {
        if ( ! $this->is_configured() ) {
            return false;
        }

        try {
            $model = $this->resolve_model();
        } catch ( RuntimeException $e ) {
            return false;
        }

        try {
            // keep_alive=0 requests immediate unload/stop for the selected model runtime.
            $this->request( 'POST', '/api/generate', [
                'model'      => $model,
                'prompt'     => '',
                'stream'     => false,
                'keep_alive' => 0,
            ] );
            return true;
        } catch ( RuntimeException $e ) {
            return false;
        }
    }

    private function resolve_model(): string {
        $model = trim( (string) ACF_Settings::get( 'ollama_model', '' ) );

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
                    return $this->http_get( $url, [], self::REQUEST_TIMEOUT );
                }

                return $this->http_post(
                    $url,
                    $body,
                    [
                        'Content-Type' => 'application/json',
                    ],
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
    private function stream_request( string $path, array $body, callable $on_data, array $config = [] ): array {
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

            curl_setopt_array( $ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
                CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER         => false,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_WRITEFUNCTION  => static function ( $curl, string $chunk ) use ( &$buffer, &$last_data, $on_data ): int {
                    if ( connection_aborted() ) {
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

                    if ( connection_aborted() ) {
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
                if ( connection_aborted() || str_contains( strtolower( $curl_error ), 'callback aborted' ) ) {
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
        $proxy = (int) ( getenv( 'ACF_OLLAMA_DOCKER_PROXY_PORT' ) ?: getenv( 'OLLAMA_PROXY_PORT' ) ?: self::DOCKER_PROXY_PORT );

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
