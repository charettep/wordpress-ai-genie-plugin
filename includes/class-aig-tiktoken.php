<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minimal bundled tiktoken-compatible encoder adapted for this plugin.
 *
 * This implementation is based on the MIT-licensed yethee/tiktoken-php port of
 * OpenAI's tiktoken and uses packaged vocab files so the plugin does not depend
 * on Composer or runtime downloads.
 */
class AIG_Tiktoken {

    /**
     * @var array<string,array{pattern:string}>
     */
    private const ENCODINGS = [
        'cl100k_base' => [
            'pattern' => '(?i:\'s|\'t|\'re|\'ve|\'m|\'ll|\'d)|[^\r\n\p{L}\p{N}]?\p{L}+|\p{N}{1,3}| ?[^\s\p{L}\p{N}]+[\r\n]*|\s*[\r\n]+|\s+(?!\S)|\s+',
        ],
        'o200k_base' => [
            'pattern' => '[^\r\n\p{L}\p{N}]?[\p{Lu}\p{Lt}\p{Lm}\p{Lo}\p{M}]*[\p{Ll}\p{Lm}\p{Lo}\p{M}]+(?i:\'s|\'t|\'re|\'ve|\'m|\'ll|\'d)?|[^\r\n\p{L}\p{N}]?[\p{Lu}\p{Lt}\p{Lm}\p{Lo}\p{M}]+[\p{Ll}\p{Lm}\p{Lo}\p{M}]*(?i:\'s|\'t|\'re|\'ve|\'m|\'ll|\'d)?|\p{N}{1,3}| ?[^\s\p{L}\p{N}]+[\r\n\/]*|\s*[\r\n]+|\s+(?!\S)|\s+',
        ],
    ];

    /**
     * @var array<string,string>
     */
    private const MODEL_TO_ENCODING = [
        'o1'             => 'o200k_base',
        'o3'             => 'o200k_base',
        'o4-mini'        => 'o200k_base',
        'gpt-5'          => 'o200k_base',
        'gpt-5.1'        => 'o200k_base',
        'gpt-5.2'        => 'o200k_base',
        'gpt-4'          => 'cl100k_base',
        'gpt-4.1'        => 'o200k_base',
        'gpt-4o'         => 'o200k_base',
        'gpt-3.5'        => 'cl100k_base',
        'gpt-3.5-turbo'  => 'cl100k_base',
        'davinci-002'    => 'cl100k_base',
        'babbage-002'    => 'cl100k_base',
    ];

    /**
     * @var array<string,string>
     */
    private const MODEL_PREFIX_TO_ENCODING = [
        'o1-'             => 'o200k_base',
        'o3-'             => 'o200k_base',
        'o4-mini-'        => 'o200k_base',
        'chatgpt-4o-'     => 'o200k_base',
        'gpt-5-'          => 'o200k_base',
        'gpt-5.1-'        => 'o200k_base',
        'gpt-5.2-'        => 'o200k_base',
        'gpt-4-'          => 'cl100k_base',
        'gpt-4.1-'        => 'o200k_base',
        'gpt-4.5-'        => 'o200k_base',
        'gpt-4o-'         => 'o200k_base',
        'gpt-3.5-turbo-'  => 'cl100k_base',
        'gpt-oss-'        => 'o200k_base',
        'claude-'         => 'cl100k_base',
        'llama'           => 'cl100k_base',
        'mistral'         => 'cl100k_base',
        'qwen'            => 'cl100k_base',
        'gemma'           => 'cl100k_base',
        'phi'             => 'cl100k_base',
    ];

    /**
     * @var array<string,AIG_Tiktoken_Encoder>
     */
    private static array $encoders = [];

    public static function count_tokens_for_model( string $provider, string $model, string $text ): ?int {
        if ( '' === $text ) {
            return 0;
        }

        try {
            $encoding = self::resolve_encoding( $provider, $model );

            return self::get_encoder( $encoding )->count( $text );
        } catch ( Throwable $e ) {
            return null;
        }
    }

    private static function resolve_encoding( string $provider, string $model ): string {
        $normalized_model = strtolower( trim( $model ) );

        if ( isset( self::MODEL_TO_ENCODING[ $normalized_model ] ) ) {
            return self::MODEL_TO_ENCODING[ $normalized_model ];
        }

        foreach ( self::MODEL_PREFIX_TO_ENCODING as $prefix => $encoding ) {
            if ( '' !== $normalized_model && str_starts_with( $normalized_model, $prefix ) ) {
                return $encoding;
            }
        }

        $normalized_provider = strtolower( trim( $provider ) );

        if ( 'openai' === $normalized_provider ) {
            return 'o200k_base';
        }

        return 'cl100k_base';
    }

    private static function get_encoder( string $encoding ): AIG_Tiktoken_Encoder {
        if ( isset( self::$encoders[ $encoding ] ) ) {
            return self::$encoders[ $encoding ];
        }

        if ( ! isset( self::ENCODINGS[ $encoding ] ) ) {
            throw new InvalidArgumentException( 'Unknown tiktoken encoding: ' . $encoding );
        }

        $pattern = '/' . self::ENCODINGS[ $encoding ]['pattern'] . '/u';
        $vocab   = AIG_Tiktoken_Vocab::from_file(
            AIG_PLUGIN_DIR . 'includes/vendor/aig-tiktoken/vocab/' . $encoding . '.tiktoken'
        );

        self::$encoders[ $encoding ] = new AIG_Tiktoken_Encoder( $vocab, $pattern );

        return self::$encoders[ $encoding ];
    }
}

final class AIG_Tiktoken_Encoder {

    public function __construct(
        private AIG_Tiktoken_Vocab $vocab,
        private string $pattern
    ) {
    }

    public function count( string $text ): int {
        if ( '' === $text ) {
            return 0;
        }

        if ( preg_match_all( $this->pattern, $text, $matches ) === false ) {
            throw new RuntimeException( 'tiktoken regex matching failed.' );
        }

        $tokens = 0;

        foreach ( $matches[0] as $match ) {
            if ( '' === $match ) {
                continue;
            }

            $rank = $this->vocab->try_get_rank( $match );

            if ( null !== $rank ) {
                $tokens++;
                continue;
            }

            $tokens += count( $this->merge_byte_pairs( $match ) );
        }

        return $tokens;
    }

    /**
     * @return array<int,int>
     */
    private function merge_byte_pairs( string $piece ): array {
        $parts = [];

        for ( $i = 0, $length = strlen( $piece ); $i <= $length; $i++ ) {
            $parts[] = [ $i, PHP_INT_MAX ];
        }

        $get_rank = function ( array $parts_list, int $start_index, int $skip = 0 ) use ( $piece ): int {
            if ( ( $start_index + $skip + 2 ) >= count( $parts_list ) ) {
                return PHP_INT_MAX;
            }

            $offset = $parts_list[ $start_index ][0];
            $length = $parts_list[ $start_index + $skip + 2 ][0] - $offset;

            return $this->vocab->try_get_rank( substr( $piece, $offset, $length ) ) ?? PHP_INT_MAX;
        };

        for ( $i = 0; $i < count( $parts ) - 2; $i++ ) {
            $parts[ $i ][1] = $get_rank( $parts, $i );
        }

        while ( count( $parts ) > 1 ) {
            $min_rank   = PHP_INT_MAX;
            $part_index = 0;
            $stop       = count( $parts ) - 1;

            for ( $i = 0; $i < $stop; $i++ ) {
                if ( $min_rank <= $parts[ $i ][1] ) {
                    continue;
                }

                $min_rank   = $parts[ $i ][1];
                $part_index = $i;
            }

            if ( PHP_INT_MAX === $min_rank ) {
                break;
            }

            array_splice( $parts, $part_index + 1, 1 );
            $parts[ $part_index ][1] = $get_rank( $parts, $part_index );

            if ( $part_index > 0 ) {
                $parts[ $part_index - 1 ][1] = $get_rank( $parts, $part_index - 1 );
            }
        }

        $tokens = [];
        $stop   = count( $parts ) - 1;

        for ( $i = 0; $i < $stop; $i++ ) {
            $offset = $parts[ $i ][0];
            $length = $parts[ $i + 1 ][0] - $offset;
            $tokens[] = $this->vocab->get_rank( substr( $piece, $offset, $length ) );
        }

        return $tokens;
    }
}

final class AIG_Tiktoken_Vocab {

    /**
     * @var array<string,self>
     */
    private static array $instances = [];

    /**
     * @param array<string,int> $token_rank_map
     */
    private function __construct( private array $token_rank_map ) {
    }

    public static function from_file( string $path ): self {
        if ( isset( self::$instances[ $path ] ) ) {
            return self::$instances[ $path ];
        }

        if ( ! file_exists( $path ) ) {
            throw new RuntimeException( 'Missing packaged tiktoken vocab: ' . $path );
        }

        $stream = fopen( $path, 'rb' );

        if ( false === $stream ) {
            throw new RuntimeException( 'Unable to open packaged tiktoken vocab: ' . $path );
        }

        $map = [];

        try {
            while ( false !== ( $line = fgets( $stream ) ) ) {
                $line = trim( $line );

                if ( '' === $line ) {
                    continue;
                }

                $parts = explode( ' ', $line, 2 );

                if ( 2 !== count( $parts ) ) {
                    continue;
                }

                $token = base64_decode( $parts[0], true );

                if ( false === $token || '' === $token ) {
                    continue;
                }

                $map[ $token ] = (int) $parts[1];
            }
        } finally {
            fclose( $stream );
        }

        self::$instances[ $path ] = new self( $map );

        return self::$instances[ $path ];
    }

    public function try_get_rank( string $binary ): ?int {
        return '' === $binary ? null : ( $this->token_rank_map[ $binary ] ?? null );
    }

    public function get_rank( string $binary ): int {
        if ( '' === $binary || ! isset( $this->token_rank_map[ $binary ] ) ) {
            throw new RuntimeException( 'Missing tiktoken rank for merged byte pair.' );
        }

        return $this->token_rank_map[ $binary ];
    }
}
