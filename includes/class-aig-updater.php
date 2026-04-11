<?php
defined( 'ABSPATH' ) || exit;

class AIG_Updater {

    private const GITHUB_OWNER = 'charettep';
    private const GITHUB_REPO  = 'wordpress-ai-genie-plugin';
    private const PLUGIN_SLUG  = 'ai-genie';
    private const CACHE_KEY    = 'aig_github_latest_release';
    private const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
    private const ERROR_TTL    = 15 * MINUTE_IN_SECONDS;

    public static function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ self::class, 'inject_update_data' ] );
        add_filter( 'plugins_api', [ self::class, 'filter_plugin_info' ], 10, 3 );
    }

    /**
     * Inject GitHub release metadata into the native WordPress update response.
     *
     * @param mixed $transient
     * @return mixed
     */
    public static function inject_update_data( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $plugin_file = plugin_basename( AIG_PLUGIN_FILE );
        $release     = self::get_latest_release();

        if ( ! $release || empty( $release['version'] ) || version_compare( $release['version'], AIG_VERSION, '<=' ) ) {
            if ( isset( $transient->response[ $plugin_file ] ) ) {
                unset( $transient->response[ $plugin_file ] );
            }

            return $transient;
        }

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = [];
        }

        $transient->response[ $plugin_file ] = (object) [
            'id'          => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
            'slug'        => self::PLUGIN_SLUG,
            'plugin'      => $plugin_file,
            'new_version' => $release['version'],
            'url'         => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
            'package'     => $release['package_url'],
            'requires'    => '6.4',
            'requires_php' => '8.1',
        ];

        return $transient;
    }

    /**
     * Supply the details modal data for the plugin information screen.
     *
     * @param mixed  $result
     * @param string $action
     * @param object  $args
     * @return mixed
     */
    public static function filter_plugin_info( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::PLUGIN_SLUG ) {
            return $result;
        }

        $release = self::get_latest_release();

        if ( ! $release ) {
            return $result;
        }

        $sections = [
            'description' => '<p>AI Genie now checks GitHub Releases for native WordPress updates and automatic update availability.</p>',
        ];

        if ( ! empty( $release['body_html'] ) ) {
            $sections['changelog'] = $release['body_html'];
        }

        return (object) [
            'name'          => 'AI Genie',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/' . self::GITHUB_OWNER . '">' . esc_html( self::GITHUB_OWNER ) . '</a>',
            'homepage'      => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
            'requires'      => '6.4',
            'requires_php'  => '8.1',
            'download_link' => $release['package_url'],
            'last_updated'  => $release['published_at'] ?? '',
            'sections'      => $sections,
        ];
    }

    /**
     * Return the latest GitHub release details in a cached array format.
     *
     * @return array<string,string>|null
     */
    private static function get_latest_release(): ?array {
        $cached = get_site_transient( self::CACHE_KEY );

        if ( is_array( $cached ) ) {
            if ( ! empty( $cached['version'] ) && ! empty( $cached['package_url'] ) ) {
                return $cached;
            }

            if ( array_key_exists( 'version', $cached ) ) {
                return null;
            }
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '/releases/latest',
            [
                'timeout' => 15,
                'headers' => [
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent'           => 'AI Genie WordPress updater',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            self::store_cache_failure();
            return null;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = wp_remote_retrieve_body( $response );

        if ( 200 !== $status || '' === $body ) {
            self::store_cache_failure();
            return null;
        }

        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            self::store_cache_failure();
            return null;
        }

        $version = self::normalize_version( $data['tag_name'] ?? '' );
        $asset   = self::find_release_asset( $data['assets'] ?? [], $version );

        if ( '' === $version || empty( $asset['browser_download_url'] ) ) {
            self::store_cache_failure();
            return null;
        }

        $release = [
            'version'       => $version,
            'package_url'   => $asset['browser_download_url'],
            'published_at'  => $data['published_at'] ?? '',
            'body_html'     => wp_kses_post( wpautop( esc_html( $data['body'] ?? '' ) ) ),
        ];

        set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

        return $release;
    }

    /**
     * Store a short-lived negative cache so a temporary API failure does not hammer GitHub.
     */
    private static function store_cache_failure(): void {
        set_site_transient( self::CACHE_KEY, [ 'version' => '', 'package_url' => '', 'failed' => true ], self::ERROR_TTL );
    }

    private static function normalize_version( string $tag ): string {
        $tag = trim( $tag );

        if ( '' === $tag ) {
            return '';
        }

        return preg_replace( '/^v/i', '', $tag ) ?: '';
    }

    /**
     * @param array<int,array<string,mixed>> $assets
     * @return array<string,mixed>
     */
    private static function find_release_asset( array $assets, string $version ): array {
        $expected_name = 'ai-genie-v' . $version . '.zip';

        foreach ( $assets as $asset ) {
            if ( ( $asset['name'] ?? '' ) === $expected_name ) {
                return $asset;
            }
        }

        return [];
    }
}
