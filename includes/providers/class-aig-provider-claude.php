<?php
defined( 'ABSPATH' ) || exit;

class AIG_Provider_Claude extends AIG_Provider {

    const API_URL = 'https://api.anthropic.com/v1/messages';
    const MODELS_API_URL = 'https://api.anthropic.com/v1/models';
    const API_VERSION = '2023-06-01';

    /**
     * Pricing per 1 million tokens [ input, output ] in USD.
     * Matches current Anthropic published rates; update as rates change.
     */
    private const PRICING = [
        'claude-opus-4'        => [ 'input' => 15.00, 'output' => 75.00 ],
        'claude-sonnet-4'      => [ 'input' =>  3.00, 'output' => 15.00 ],
        'claude-haiku-4'       => [ 'input' =>  0.80, 'output' =>  4.00 ],
        'claude-3-7-sonnet'    => [ 'input' =>  3.00, 'output' => 15.00 ],
        'claude-3-5-sonnet'    => [ 'input' =>  3.00, 'output' => 15.00 ],
        'claude-3-5-haiku'     => [ 'input' =>  0.80, 'output' =>  4.00 ],
        'claude-3-opus'        => [ 'input' => 15.00, 'output' => 75.00 ],
        'claude-3-haiku'       => [ 'input' =>  0.25, 'output' =>  1.25 ],
    ];

    public function id(): string    { return 'claude'; }
    public function label(): string { return 'Anthropic Claude'; }

    public function is_configured( array $config = [] ): bool {
        return '' !== trim( (string) $this->resolve_setting( 'claude_api_key', '', $config ) );
    }

    public function discover_models( array $config = [] ): array {
        $api_key = trim( (string) $this->resolve_setting( 'claude_api_key', '', $config ) );

        if ( '' === $api_key ) {
            throw new RuntimeException( 'Claude API key is not set.' );
        }

        $data = $this->http_get(
            self::MODELS_API_URL,
            [
                'x-api-key'         => $api_key,
                'anthropic-version' => self::API_VERSION,
            ]
        );

        $models = array_filter(
            array_map(
                static function ( array $item ): ?array {
                    $id = (string) ( $item['id'] ?? '' );

                    if ( '' === $id ) {
                        return null;
                    }

                    return [
                        'id'    => $id,
                        'label' => (string) ( $item['display_name'] ?? $id ),
                    ];
                },
                $data['data'] ?? []
            )
        );

        if ( empty( $models ) ) {
            throw new RuntimeException( 'No Claude models were returned for this API key.' );
        }

        return array_values( $models );
    }

    public function generate( string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens = 0 ): string {
        if ( ! $this->is_configured() ) {
            throw new RuntimeException( 'Claude API key is not set.' );
        }

        $model = $this->resolve_model();
        $body  = [
            'model'       => $model,
            'max_tokens'  => $max_output_tokens,
            'temperature' => $temperature,
            'messages'    => [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
        ];

        if ( self::supports_extended_thinking( $model ) && $max_thinking_tokens > 0 ) {
            $thinking_budget = max( 1024, $max_thinking_tokens );
            $body['thinking'] = [
                'type'          => 'enabled',
                'budget_tokens' => $thinking_budget,
            ];
            $body['max_tokens'] = max( $max_output_tokens + $thinking_budget, $thinking_budget + 1 );
        }

        $data = $this->http_post(
            self::API_URL,
            $body,
            [
                'x-api-key'         => AIG_Settings::get( 'claude_api_key' ),
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ]
        );

        return $data['content'][0]['text'] ?? '';
    }

    private static function supports_extended_thinking( string $model ): bool {
        return preg_match( '/^claude-(?:3-7|sonnet-4|opus-4|haiku-4)/i', $model ) === 1;
    }

    /**
     * Call the Messages API (non-streaming), emit the full text as one chunk,
     * and return token usage + cost metadata for the Run Usage panel.
     *
     * @param callable(string):void $emit
     * @return array<string,mixed>
     */
    public function stream_generate( string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens, callable $emit ): array {
        if ( ! $this->is_configured() ) {
            throw new RuntimeException( 'Claude API key is not set.' );
        }

        $model = $this->resolve_model();
        $body  = [
            'model'       => $model,
            'max_tokens'  => $max_output_tokens,
            'temperature' => $temperature,
            'messages'    => [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
        ];

        if ( self::supports_extended_thinking( $model ) && $max_thinking_tokens > 0 ) {
            $thinking_budget  = max( 1024, $max_thinking_tokens );
            $body['thinking'] = [
                'type'          => 'enabled',
                'budget_tokens' => $thinking_budget,
            ];
            $body['max_tokens'] = max( $max_output_tokens + $thinking_budget, $thinking_budget + 1 );
        }

        $data = $this->http_post(
            self::API_URL,
            $body,
            [
                'x-api-key'         => AIG_Settings::get( 'claude_api_key' ),
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ]
        );

        // Emit text blocks; skip thinking blocks.
        $text = '';
        foreach ( $data['content'] ?? [] as $block ) {
            if ( 'text' === ( $block['type'] ?? '' ) && '' !== ( $block['text'] ?? '' ) ) {
                $text .= $block['text'];
            }
        }

        if ( '' !== $text ) {
            $emit( $text );
        }

        return $this->build_usage_metadata( $data, $model );
    }

    /**
     * Extract usage + pricing from a Messages API response body.
     *
     * @return array<string,mixed>
     */
    private function build_usage_metadata( array $data, string $model ): array {
        $raw_usage = $data['usage'] ?? null;

        if ( ! is_array( $raw_usage ) ) {
            return [];
        }

        $input_tokens  = max( 0, (int) ( $raw_usage['input_tokens']  ?? 0 ) );
        $output_tokens = max( 0, (int) ( $raw_usage['output_tokens'] ?? 0 ) );
        $total_tokens  = $input_tokens + $output_tokens;

        // Thinking tokens are counted inside output_tokens by the API.
        // Count thinking content blocks to separate them (token count per block not exposed,
        // so we report 0 and leave the full count in output_tokens).
        $thinking_tokens = 0;
        $text_tokens     = $output_tokens;

        $pricing  = self::get_model_pricing( $model );
        $cost_usd = null;

        if ( is_array( $pricing ) ) {
            $cost_usd = round(
                ( $input_tokens * $pricing['input'] + $output_tokens * $pricing['output'] ) / 1_000_000,
                8
            );
        }

        return [
            'provider'        => $this->id(),
            'model'           => $model,
            'input_tokens'    => $input_tokens,
            'thinking_tokens' => $thinking_tokens,
            'output_tokens'   => $text_tokens,
            'total_tokens'    => $total_tokens,
            'cost_usd'        => $cost_usd,
            'currency'        => 'USD',
        ];
    }

    /**
     * Return [ input, output ] pricing per 1M tokens for a model, or null if unknown.
     *
     * @return array{input:float,output:float}|null
     */
    private static function get_model_pricing( string $model ): ?array {
        $normalized = strtolower( trim( $model ) );

        foreach ( self::PRICING as $prefix => $rates ) {
            if ( str_starts_with( $normalized, $prefix ) ) {
                return $rates;
            }
        }

        return null;
    }

    private function resolve_model(): string {
        $override = $this->get_model_override();
        if ( '' !== $override ) {
            return $override;
        }

        $model = trim( (string) AIG_Settings::get( 'claude_model', '' ) );

        if ( '' !== $model ) {
            return $model;
        }

        $models = $this->discover_models();
        $model  = (string) ( $models[0]['id'] ?? '' );

        if ( '' === $model ) {
            throw new RuntimeException( 'No Claude model is selected.' );
        }

        return $model;
    }
}
