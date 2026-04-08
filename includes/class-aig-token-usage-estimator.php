<?php
defined( 'ABSPATH' ) || exit;

class AIG_Token_Usage_Estimator {

    private const OPENAI_PRICING = [
        'gpt-5-nano'   => [ 'input' => 0.20, 'output' => 1.25 ],
        'gpt-5.4-nano' => [ 'input' => 0.20, 'output' => 1.25 ],
    ];

    private const CLAUDE_PRICING = [
        'claude-opus-4'      => [ 'input' => 15.0, 'output' => 75.0 ],
        'claude-sonnet-4'    => [ 'input' => 3.0,  'output' => 15.0 ],
        'claude-haiku-4'     => [ 'input' => 0.8,  'output' => 4.0 ],
        'claude-3-7-sonnet'  => [ 'input' => 3.0,  'output' => 15.0 ],
        'claude-3-5-sonnet'  => [ 'input' => 3.0,  'output' => 15.0 ],
        'claude-3-5-haiku'   => [ 'input' => 0.8,  'output' => 4.0 ],
        'claude-3-opus'      => [ 'input' => 15.0, 'output' => 75.0 ],
        'claude-3-haiku'     => [ 'input' => 0.25, 'output' => 1.25 ],
    ];

    /**
     * @return array<string,mixed>
     */
    public static function begin_estimate( string $provider, string $model, string $prompt ): array {
        $input_tokens = AIG_Tiktoken::count_tokens_for_model( $provider, $model, $prompt );

        if ( null === $input_tokens ) {
            return [];
        }

        return self::build_estimate( $provider, $model, $input_tokens, 0 );
    }

    /**
     * @param array<string,mixed> $usage
     * @return array<string,mixed>
     */
    public static function update_estimate( array $usage, string $output_text ): array {
        if ( empty( $usage['provider'] ) ) {
            return [];
        }

        $provider      = (string) $usage['provider'];
        $model         = (string) ( $usage['model'] ?? '' );
        $input_tokens  = max( 0, (int) ( $usage['input_tokens'] ?? 0 ) );
        $output_tokens = AIG_Tiktoken::count_tokens_for_model( $provider, $model, $output_text );

        if ( null === $output_tokens ) {
            return $usage;
        }

        return self::build_estimate( $provider, $model, $input_tokens, $output_tokens );
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_estimate( string $provider, string $model, int $input_tokens, int $output_tokens ): array {
        $input_tokens  = max( 0, $input_tokens );
        $output_tokens = max( 0, $output_tokens );
        $cost_usd      = self::estimate_cost( $provider, $model, $input_tokens, $output_tokens );

        return [
            'provider'        => $provider,
            'model'           => $model,
            'input_tokens'    => $input_tokens,
            'thinking_tokens' => null,
            'output_tokens'   => $output_tokens,
            'total_tokens'    => $input_tokens + $output_tokens,
            'cost_usd'        => $cost_usd,
            'currency'        => 'USD',
            'estimated'       => true,
            'estimate_source' => 'tiktoken',
        ];
    }

    private static function estimate_cost( string $provider, string $model, int $input_tokens, int $output_tokens ): ?float {
        $pricing = self::resolve_pricing( $provider, $model );

        if ( null === $pricing ) {
            return null;
        }

        return round(
            ( ( $input_tokens * $pricing['input'] ) + ( $output_tokens * $pricing['output'] ) ) / 1_000_000,
            8
        );
    }

    /**
     * @return array{input:float,output:float}|null
     */
    private static function resolve_pricing( string $provider, string $model ): ?array {
        $normalized_provider = strtolower( trim( $provider ) );
        $normalized_model    = strtolower( trim( $model ) );

        if ( 'openai' === $normalized_provider ) {
            foreach ( self::OPENAI_PRICING as $prefix => $rates ) {
                if ( '' !== $normalized_model && str_starts_with( $normalized_model, $prefix ) ) {
                    return $rates;
                }
            }
        }

        if ( 'claude' === $normalized_provider ) {
            foreach ( self::CLAUDE_PRICING as $prefix => $rates ) {
                if ( '' !== $normalized_model && str_starts_with( $normalized_model, $prefix ) ) {
                    return $rates;
                }
            }
        }

        return null;
    }
}
