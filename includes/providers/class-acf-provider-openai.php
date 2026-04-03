<?php
defined( 'ABSPATH' ) || exit;

class ACF_Provider_OpenAI extends ACF_Provider {

    const CHAT_API_URL = 'https://api.openai.com/v1/chat/completions';
    const RESPONSES_API_URL = 'https://api.openai.com/v1/responses';
    const MODELS_API_URL = 'https://api.openai.com/v1/models';
    const GPT_5_4_NANO_INPUT_USD_PER_1M = 0.20;
    const GPT_5_4_NANO_OUTPUT_USD_PER_1M = 1.25;

    public function id(): string    { return 'openai'; }
    public function label(): string { return 'OpenAI'; }

    public function is_configured( array $config = [] ): bool {
        return '' !== trim( (string) $this->resolve_setting( 'openai_api_key', '', $config ) );
    }

    public function discover_models( array $config = [] ): array {
        $api_key = trim( (string) $this->resolve_setting( 'openai_api_key', '', $config ) );

        if ( '' === $api_key ) {
            throw new RuntimeException( 'OpenAI API key is not set.' );
        }

        $data = $this->http_get(
            self::MODELS_API_URL,
            [
                'Authorization' => 'Bearer ' . $api_key,
            ]
        );

        $models = array_filter(
            array_map(
                static function ( array $item ): ?array {
                    $id = (string) ( $item['id'] ?? '' );

                    if ( '' === $id || ! self::is_supported_text_model( $id ) ) {
                        return null;
                    }

                    return [
                        'id'    => $id,
                        'label' => $id,
                    ];
                },
                $data['data'] ?? []
            )
        );

        usort(
            $models,
            static function ( array $a, array $b ): int {
                return strcasecmp( $a['id'], $b['id'] );
            }
        );

        if ( empty( $models ) ) {
            throw new RuntimeException( 'No supported text-generation models were returned for this API key.' );
        }

        return array_values( $models );
    }

    public function generate( string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens = 0 ): string {
        if ( ! $this->is_configured() ) {
            throw new RuntimeException( 'OpenAI API key is not set.' );
        }

        $model   = $this->resolve_model();
        $api_key = (string) ACF_Settings::get( 'openai_api_key' );

        if ( self::should_use_responses_api( $model ) ) {
            $body = [
                'model'             => $model,
                'input'             => $prompt,
                'max_output_tokens' => self::resolve_responses_token_budget( $model, $max_output_tokens, $max_thinking_tokens ),
            ];

            if ( self::supports_temperature( $model ) ) {
                $body['temperature'] = $temperature;
            }

            $reasoning = self::build_reasoning_config( $model, $max_thinking_tokens );
            if ( ! empty( $reasoning ) ) {
                $body['reasoning'] = $reasoning;
            }

            $headers = [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ];

            return $this->generate_responses_text( $body, $headers, $model );
        }

        $body = [
            'model'       => $model,
            'messages'    => [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
        ];

        if ( self::supports_temperature( $model ) ) {
            $body['temperature'] = $temperature;
        }

        if ( self::should_use_max_completion_tokens( $model ) ) {
            $body['max_completion_tokens'] = self::resolve_chat_completion_token_budget( $model, $max_output_tokens, $max_thinking_tokens );
        } else {
            $body['max_tokens'] = $max_output_tokens;
        }

        $data = $this->post_with_parameter_fallback(
            self::CHAT_API_URL,
            $body,
            [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'temperature'
        );

        return $data['choices'][0]['message']['content'] ?? '';
    }

    public function stream_generate( string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens, callable $emit ): array {
        if ( ! function_exists( 'curl_init' ) ) {
            return parent::stream_generate( $prompt, $max_output_tokens, $temperature, $max_thinking_tokens, $emit );
        }

        if ( ! $this->is_configured() ) {
            throw new RuntimeException( 'OpenAI API key is not set.' );
        }

        $model   = $this->resolve_model();
        $api_key = (string) ACF_Settings::get( 'openai_api_key' );

        if ( self::should_use_responses_api( $model ) ) {
            $usage = null;

            $body = [
                'model'             => $model,
                'input'             => $prompt,
                'max_output_tokens' => self::resolve_responses_token_budget( $model, $max_output_tokens, $max_thinking_tokens ),
                'stream'            => true,
            ];

            if ( self::supports_temperature( $model ) ) {
                $body['temperature'] = $temperature;
            }

            $reasoning = self::build_reasoning_config( $model, $max_thinking_tokens );
            if ( ! empty( $reasoning ) ) {
                $body['reasoning'] = $reasoning;
            }

            $this->stream_sse_request(
                self::RESPONSES_API_URL,
                $body,
                [
                    'Authorization: Bearer ' . $api_key,
                    'Content-Type: application/json',
                ],
                static function ( string $event, array $data ) use ( $emit, &$usage ): void {
                    if ( 'response.output_text.delta' === $event && isset( $data['delta'] ) ) {
                        $emit( (string) $data['delta'] );
                    }

                    $event_usage = self::extract_responses_stream_usage( $event, $data );
                    if ( is_array( $event_usage ) ) {
                        $usage = $event_usage;
                    }
                }
            );

            return $this->with_openai_usage_metadata( $usage, $model );
        }

        $usage = null;
        $body = [
            'model'    => $model,
            'messages' => [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
            'stream'   => true,
            'stream_options' => [
                'include_usage' => true,
            ],
        ];

        if ( self::supports_temperature( $model ) ) {
            $body['temperature'] = $temperature;
        }

        if ( self::should_use_max_completion_tokens( $model ) ) {
            $body['max_completion_tokens'] = self::resolve_chat_completion_token_budget( $model, $max_output_tokens, $max_thinking_tokens );
        } else {
            $body['max_tokens'] = $max_output_tokens;
        }

        $this->stream_sse_request(
            self::CHAT_API_URL,
            $body,
            [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ],
            static function ( string $event, array $data ) use ( $emit, &$usage ): void {
                if ( '[DONE]' === $event ) {
                    return;
                }

                $delta = (string) ( $data['choices'][0]['delta']['content'] ?? '' );
                if ( '' !== $delta ) {
                    $emit( $delta );
                }

                $event_usage = self::extract_chat_stream_usage( $data );
                if ( is_array( $event_usage ) ) {
                    $usage = $event_usage;
                }
            }
        );

        return $this->with_openai_usage_metadata( $usage, $model );
    }

    private function extract_responses_text( array $data ): string {
        if ( ! empty( $data['output_text'] ) ) {
            return trim( (string) $data['output_text'] );
        }

        $chunks = [];

        foreach ( $data['output'] ?? [] as $output ) {
            foreach ( $output['content'] ?? [] as $content ) {
                if ( 'output_text' === ( $content['type'] ?? '' ) && ! empty( $content['text'] ) ) {
                    $chunks[] = $content['text'];
                }
            }
        }

        return trim( implode( "\n\n", $chunks ) );
    }

    private function generate_responses_text( array $body, array $headers, string $model ): string {
        $data = $this->post_with_parameter_fallback(
            self::RESPONSES_API_URL,
            $body,
            $headers,
            'temperature'
        );

        $text = $this->extract_responses_text( $data );

        if ( '' !== $text ) {
            return $text;
        }

        if ( self::should_retry_empty_responses_output( $data, $model ) ) {
            $retry_body = $body;
            $retry_body['max_output_tokens'] = max(
                (int) ( $body['max_output_tokens'] ?? 0 ),
                self::minimum_responses_retry_budget( $model )
            );

            if ( $retry_body['max_output_tokens'] !== (int) ( $body['max_output_tokens'] ?? 0 ) ) {
                $retry_data = $this->post_with_parameter_fallback(
                    self::RESPONSES_API_URL,
                    $retry_body,
                    $headers,
                    'temperature'
                );

                $retry_text = $this->extract_responses_text( $retry_data );

                if ( '' !== $retry_text ) {
                    return $retry_text;
                }

                $data = $retry_data;
            }
        }

        throw new RuntimeException( self::build_empty_responses_error( $data ) );
    }

    private static function build_reasoning_config( string $model, int $max_thinking_tokens ): array {
        if ( ! self::is_reasoning_model( $model ) ) {
            return [];
        }

        if ( self::is_gpt5_pro( $model ) ) {
            return [ 'effort' => 'high' ];
        }

        return [ 'effort' => self::map_reasoning_effort( $model, $max_thinking_tokens ) ];
    }

    private static function should_use_responses_api( string $model ): bool {
        return preg_match( '/^(gpt-5|gpt-4\.1|gpt-4o|o1|o3|o4|chatgpt-4o)/i', $model ) === 1;
    }

    private static function should_use_max_completion_tokens( string $model ): bool {
        return preg_match( '/^(gpt-5|o1|o3|o4)/i', $model ) === 1;
    }

    private static function supports_temperature( string $model ): bool {
        return preg_match( '/^(gpt-5|o1|o3|o4)/i', $model ) !== 1;
    }

    private static function is_gpt5_family( string $model ): bool {
        return preg_match( '/^gpt-5(?!-pro)/i', $model ) === 1;
    }

    private static function is_gpt5_pro( string $model ): bool {
        return preg_match( '/^gpt-5(?:\.\d+)?-pro/i', $model ) === 1;
    }

    private static function is_reasoning_model( string $model ): bool {
        return preg_match( '/^(gpt-5|o1|o3|o4)/i', $model ) === 1;
    }

    private static function is_supported_text_model( string $model ): bool {
        if ( preg_match( '/(audio|image|tts|transcribe|embedding|search|moderation|realtime)/i', $model ) ) {
            return false;
        }

        return preg_match( '/^(gpt-|o1|o3|o4|chatgpt-)/i', $model ) === 1;
    }

    private function resolve_model(): string {
        $override = $this->get_model_override();
        if ( '' !== $override ) {
            return $override;
        }

        $model = trim( (string) ACF_Settings::get( 'openai_model', '' ) );

        if ( '' !== $model ) {
            return $model;
        }

        $models = $this->discover_models();
        $model  = (string) ( $models[0]['id'] ?? '' );

        if ( '' === $model ) {
            throw new RuntimeException( 'No OpenAI model is selected.' );
        }

        return $model;
    }

    private static function should_retry_empty_responses_output( array $data, string $model ): bool {
        return self::minimum_responses_retry_budget( $model ) > 0
            && 'incomplete' === (string) ( $data['status'] ?? '' )
            && 'max_output_tokens' === (string) ( $data['incomplete_details']['reason'] ?? '' );
    }

    private static function minimum_responses_retry_budget( string $model ): int {
        if ( self::is_gpt5_family( $model ) || self::is_gpt5_pro( $model ) ) {
            return 2048;
        }

        return 0;
    }

    private static function build_empty_responses_error( array $data ): string {
        if ( 'incomplete' === (string) ( $data['status'] ?? '' ) && 'max_output_tokens' === (string) ( $data['incomplete_details']['reason'] ?? '' ) ) {
            return 'OpenAI did not return visible text before hitting max_output_tokens. Increase Max Output Tokens and/or Max Thinking Tokens and try again.';
        }

        return 'OpenAI returned an empty response.';
    }

    private static function resolve_responses_token_budget( string $model, int $max_output_tokens, int $max_thinking_tokens ): int {
        if ( self::is_reasoning_model( $model ) ) {
            return max( 1, $max_output_tokens + max( 0, $max_thinking_tokens ) );
        }

        return max( 1, $max_output_tokens );
    }

    private static function resolve_chat_completion_token_budget( string $model, int $max_output_tokens, int $max_thinking_tokens ): int {
        if ( self::is_reasoning_model( $model ) ) {
            return max( 1, $max_output_tokens + max( 0, $max_thinking_tokens ) );
        }

        return max( 1, $max_output_tokens );
    }

    private static function map_reasoning_effort( string $model, int $max_thinking_tokens ): string {
        if ( $max_thinking_tokens <= 0 ) {
            return self::supports_reasoning_none( $model ) ? 'none' : 'minimal';
        }

        if ( $max_thinking_tokens <= 1024 ) {
            return 'minimal';
        }

        if ( $max_thinking_tokens <= 4096 ) {
            return 'low';
        }

        if ( $max_thinking_tokens <= 12288 ) {
            return 'medium';
        }

        return 'high';
    }

    private static function supports_reasoning_none( string $model ): bool {
        return preg_match( '/^gpt-5\.1/i', $model ) === 1;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function extract_responses_stream_usage( string $event, array $data ): ?array {
        if ( ! in_array( $event, [ 'response.completed', 'response.incomplete' ], true ) ) {
            return null;
        }

        $root  = isset( $data['response'] ) && is_array( $data['response'] ) ? $data['response'] : $data;
        $usage = $root['usage'] ?? null;

        if ( ! is_array( $usage ) ) {
            return null;
        }

        return self::normalize_openai_usage(
            $usage,
            'input_tokens',
            'output_tokens',
            'output_tokens_details'
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function extract_chat_stream_usage( array $data ): ?array {
        $usage = $data['usage'] ?? null;

        if ( ! is_array( $usage ) ) {
            return null;
        }

        return self::normalize_openai_usage(
            $usage,
            'prompt_tokens',
            'completion_tokens',
            'completion_tokens_details'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function normalize_openai_usage( array $usage, string $input_key, string $output_key, string $details_key ): array {
        $input_tokens = (int) ( $usage[ $input_key ] ?? 0 );
        $output_total = (int) ( $usage[ $output_key ] ?? 0 );
        $thinking_tokens = (int) ( $usage[ $details_key ]['reasoning_tokens'] ?? 0 );
        $output_tokens = max( 0, $output_total - $thinking_tokens );
        $total_tokens = (int) ( $usage['total_tokens'] ?? ( $input_tokens + $output_total ) );

        return [
            'input_tokens'    => $input_tokens,
            'thinking_tokens' => $thinking_tokens,
            'output_tokens'   => $output_tokens,
            'total_tokens'    => $total_tokens,
            '_billed_output_tokens' => $output_total,
        ];
    }

    /**
     * @param array<string,mixed>|null $usage
     * @return array<string,mixed>
     */
    private function with_openai_usage_metadata( ?array $usage, string $model ): array {
        if ( ! is_array( $usage ) ) {
            return [];
        }

        $input_tokens = max( 0, (int) ( $usage['input_tokens'] ?? 0 ) );
        $thinking_tokens = max( 0, (int) ( $usage['thinking_tokens'] ?? 0 ) );
        $output_tokens = max( 0, (int) ( $usage['output_tokens'] ?? 0 ) );
        $total_tokens = max( 0, (int) ( $usage['total_tokens'] ?? 0 ) );
        $billed_output_tokens = max( 0, (int) ( $usage['_billed_output_tokens'] ?? ( $output_tokens + $thinking_tokens ) ) );

        $pricing = self::get_model_pricing_per_million( $model );
        $cost_usd = null;

        if ( is_array( $pricing ) ) {
            $cost_usd = (
                ( $input_tokens * (float) $pricing['input'] ) +
                ( $billed_output_tokens * (float) $pricing['output'] )
            ) / 1000000;
        }

        return [
            'provider'        => $this->id(),
            'model'           => $model,
            'input_tokens'    => $input_tokens,
            'thinking_tokens' => $thinking_tokens,
            'output_tokens'   => $output_tokens,
            'total_tokens'    => $total_tokens,
            'cost_usd'        => is_numeric( $cost_usd ) ? round( (float) $cost_usd, 8 ) : null,
            'currency'        => 'USD',
        ];
    }

    /**
     * @return array{input:float,output:float}|null
     */
    private static function get_model_pricing_per_million( string $model ): ?array {
        $normalized = strtolower( trim( $model ) );

        if ( preg_match( '/^gpt-5(?:\.4)?-nano(?:$|-)/', $normalized ) === 1 ) {
            return [
                'input'  => self::GPT_5_4_NANO_INPUT_USD_PER_1M,
                'output' => self::GPT_5_4_NANO_OUTPUT_USD_PER_1M,
            ];
        }

        return null;
    }

    private function post_with_parameter_fallback( string $url, array $body, array $headers, string $parameter ): array {
        try {
            return $this->http_post( $url, $body, $headers );
        } catch ( RuntimeException $e ) {
            if ( ! isset( $body[ $parameter ] ) ) {
                throw $e;
            }

            if ( ! self::is_unsupported_parameter_error( $e->getMessage(), $parameter ) ) {
                throw $e;
            }

            unset( $body[ $parameter ] );

            return $this->http_post( $url, $body, $headers );
        }
    }

    private static function is_unsupported_parameter_error( string $message, string $parameter ): bool {
        return preg_match( "/unsupported parameter:\\s*'?" . preg_quote( $parameter, '/' ) . "'?/i", $message ) === 1;
    }

    /**
     * @param callable(string,array<string,mixed>):void $on_event
     */
    private function stream_sse_request( string $url, array $body, array $headers, callable $on_event ): void {
        $buffer     = '';
        $curl_error = null;
        $http_code  = 0;

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_WRITEFUNCTION  => static function ( $curl, string $chunk ) use ( &$buffer, $on_event ): int {
                if ( connection_aborted() ) {
                    return 0;
                }

                $buffer .= $chunk;

                while ( false !== ( $pos = strpos( $buffer, "\n\n" ) ) ) {
                    $frame  = substr( $buffer, 0, $pos );
                    $buffer = substr( $buffer, $pos + 2 );

                    $event = 'message';
                    $data  = '';

                    foreach ( preg_split( "/\r?\n/", $frame ) as $line ) {
                        if ( 0 === strpos( $line, 'event:' ) ) {
                            $event = trim( substr( $line, 6 ) );
                        } elseif ( 0 === strpos( $line, 'data:' ) ) {
                            $data .= substr( $line, 5 );
                        }
                    }

                    $data = trim( $data );

                    if ( '' === $data ) {
                        continue;
                    }

                    if ( '[DONE]' === $data ) {
                        $on_event( '[DONE]', [] );
                        continue;
                    }

                    $decoded = json_decode( $data, true );
                    if ( is_array( $decoded ) ) {
                        $on_event( $event, $decoded );
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

            throw new RuntimeException( 'HTTP error: ' . $curl_error );
        }

        if ( $http_code >= 400 ) {
            throw new RuntimeException( "HTTP $http_code" );
        }
    }
}
