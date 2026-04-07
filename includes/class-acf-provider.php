<?php
defined( 'ABSPATH' ) || exit;

abstract class ACF_Provider {

    protected string $model_override = '';

    /**
     * @param string $prompt     Full prompt string
     * @param int    $max_output_tokens
     * @param float  $temperature
     * @param int    $max_thinking_tokens
     * @return string            Generated text
     * @throws RuntimeException  on API error
     */
    abstract public function generate( string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens = 0 ): string;

    /**
     * Provider identifier slug (claude / openai / ollama).
     */
    abstract public function id(): string;

    /**
     * Human-readable label.
     */
    abstract public function label(): string;

    /**
     * Override the model for a single generation run.
     */
    public function set_model_override( string $model = '' ): void {
        $this->model_override = trim( $model );
    }

    /**
     * Whether the provider appears correctly configured.
     */
    abstract public function is_configured( array $config = [] ): bool;

    /**
     * Return provider-exposed model options for the supplied runtime config.
     *
     * @return array<int,array{id:string,label:string}>
     */
    public function discover_models( array $config = [] ): array {
        throw new RuntimeException( 'Model discovery is not supported for this provider.' );
    }

    /**
     * Stream generated text deltas to the supplied callback.
     *
     * Providers that do not support true streaming fall back to one final chunk.
     *
     * @param callable(string):void $emit
     * @return array<string,mixed>
     */
    public function stream_generate( string $prompt, int $max_output_tokens, float $temperature, int $max_thinking_tokens, callable $emit ): array {
        $text = $this->generate( $prompt, $max_output_tokens, $temperature, $max_thinking_tokens );

        if ( '' !== $text ) {
            $emit( $text );
        }

        return [];
    }

    /**
     * Attempt to stop an active generation run for this provider.
     *
     * Providers can override this to implement cancellation semantics.
     */
    public function cancel_generation(): bool {
        return false;
    }

    protected function resolve_setting( string $key, $fallback = null, array $config = [] ) {
        return $config[ $key ] ?? ACF_Settings::get( $key, $fallback );
    }

    protected function get_model_override(): string {
        return $this->model_override;
    }

    /**
     * Shared wp_remote_get helper with error normalisation.
     */
    protected function http_get( string $url, array $headers, int $timeout = 180 ): array {
        $response = wp_remote_get( $url, [
            'headers' => $headers,
            'timeout' => $timeout,
        ] );

        return $this->normalize_response( $response );
    }

    /**
     * Shared wp_remote_post helper with error normalisation.
     */
    protected function http_post( string $url, array $body, array $headers, int $timeout = 180 ): array {
        $response = wp_remote_post( $url, [
            'headers'     => $headers,
            'body'        => wp_json_encode( $body ),
            'timeout'     => $timeout,
            'data_format' => 'body',
        ] );

        return $this->normalize_response( $response );
    }

    /**
     * Normalize remote responses and bubble up provider error messages.
     */
    protected function normalize_response( $response ): array {
        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'HTTP error: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code >= 400 ) {
            $msg = $data['error']['message'] ?? $data['error'] ?? "HTTP $code";
            throw new RuntimeException( $msg );
        }

        return $data;
    }
}
