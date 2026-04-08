<?php
defined( 'ABSPATH' ) || exit;

class AIG_Deep_Research_Store {

    const DB_VERSION = '1.0.0';
    const DB_VERSION_OPTION = 'aig_deep_research_db_version';

    public static function table_runs(): string {
        global $wpdb;
        return $wpdb->prefix . 'aig_dr_runs';
    }

    public static function table_run_items(): string {
        global $wpdb;
        return $wpdb->prefix . 'aig_dr_run_items';
    }

    public static function table_sources(): string {
        global $wpdb;
        return $wpdb->prefix . 'aig_dr_sources';
    }

    public static function table_oauth_accounts(): string {
        global $wpdb;
        return $wpdb->prefix . 'aig_dr_oauth_accounts';
    }

    public static function table_batches(): string {
        global $wpdb;
        return $wpdb->prefix . 'aig_dr_batches';
    }

    public static function install_schema(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $runs            = self::table_runs();
        $run_items       = self::table_run_items();
        $sources         = self::table_sources();
        $oauth_accounts  = self::table_oauth_accounts();
        $batches         = self::table_batches();

        dbDelta(
            "CREATE TABLE {$runs} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                status varchar(32) NOT NULL DEFAULT 'draft',
                model varchar(100) NOT NULL DEFAULT '',
                title varchar(255) NOT NULL DEFAULT '',
                prompt longtext NOT NULL,
                background tinyint(1) NOT NULL DEFAULT 1,
                max_tool_calls int(11) unsigned NOT NULL DEFAULT 12,
                response_id varchar(100) NOT NULL DEFAULT '',
                response_status varchar(32) NOT NULL DEFAULT '',
                source_config longtext NULL,
                request_payload longtext NULL,
                response_payload longtext NULL,
                report_message longtext NULL,
                report_annotations longtext NULL,
                draft_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                last_error text NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                completed_at datetime NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY response_id (response_id),
                KEY status (status)
            ) {$charset_collate};"
        );

        dbDelta(
            "CREATE TABLE {$run_items} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                run_id bigint(20) unsigned NOT NULL,
                item_index int(11) unsigned NOT NULL DEFAULT 0,
                item_type varchar(64) NOT NULL DEFAULT '',
                item_payload longtext NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY run_id (run_id),
                KEY item_type (item_type)
            ) {$charset_collate};"
        );

        dbDelta(
            "CREATE TABLE {$sources} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                source_type varchar(32) NOT NULL DEFAULT '',
                name varchar(191) NOT NULL DEFAULT '',
                status varchar(32) NOT NULL DEFAULT 'inactive',
                config longtext NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY source_type (source_type),
                KEY status (status)
            ) {$charset_collate};"
        );

        dbDelta(
            "CREATE TABLE {$oauth_accounts} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                provider_slug varchar(64) NOT NULL DEFAULT '',
                account_label varchar(191) NOT NULL DEFAULT '',
                access_token longtext NULL,
                refresh_token longtext NULL,
                expires_at datetime NULL DEFAULT NULL,
                scopes text NULL,
                metadata longtext NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY provider_slug (provider_slug)
            ) {$charset_collate};"
        );

        dbDelta(
            "CREATE TABLE {$batches} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                status varchar(32) NOT NULL DEFAULT 'draft',
                model varchar(100) NOT NULL DEFAULT '',
                input_mode varchar(32) NOT NULL DEFAULT '',
                input_file_name varchar(255) NOT NULL DEFAULT '',
                openai_batch_id varchar(100) NOT NULL DEFAULT '',
                request_file_id varchar(100) NOT NULL DEFAULT '',
                output_file_id varchar(100) NOT NULL DEFAULT '',
                error_file_id varchar(100) NOT NULL DEFAULT '',
                config longtext NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                completed_at datetime NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY status (status),
                KEY openai_batch_id (openai_batch_id)
            ) {$charset_collate};"
        );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    public static function get_db_version(): string {
        return (string) get_option( self::DB_VERSION_OPTION, '' );
    }

    public static function create_run( array $data ): int {
        global $wpdb;

        $wpdb->insert(
            self::table_runs(),
            [
                'user_id'            => absint( $data['user_id'] ?? 0 ),
                'status'             => sanitize_text_field( (string) ( $data['status'] ?? 'draft' ) ),
                'model'              => sanitize_text_field( (string) ( $data['model'] ?? '' ) ),
                'title'              => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
                'prompt'             => (string) ( $data['prompt'] ?? '' ),
                'background'         => empty( $data['background'] ) ? 0 : 1,
                'max_tool_calls'     => absint( $data['max_tool_calls'] ?? 0 ),
                'response_id'        => sanitize_text_field( (string) ( $data['response_id'] ?? '' ) ),
                'response_status'    => sanitize_text_field( (string) ( $data['response_status'] ?? '' ) ),
                'source_config'      => self::maybe_json_encode( $data['source_config'] ?? null ),
                'request_payload'    => self::maybe_json_encode( $data['request_payload'] ?? null ),
                'response_payload'   => self::maybe_json_encode( $data['response_payload'] ?? null ),
                'report_message'     => (string) ( $data['report_message'] ?? '' ),
                'report_annotations' => self::maybe_json_encode( $data['report_annotations'] ?? null ),
                'draft_post_id'      => absint( $data['draft_post_id'] ?? 0 ),
                'last_error'         => (string) ( $data['last_error'] ?? '' ),
                'completed_at'       => $data['completed_at'] ?? null,
            ],
            [
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
            ]
        );

        return (int) $wpdb->insert_id;
    }

    public static function update_run( int $run_id, array $data ): void {
        global $wpdb;

        $fields  = [];
        $formats = [];
        $map     = [
            'user_id'            => '%d',
            'status'             => '%s',
            'model'              => '%s',
            'title'              => '%s',
            'prompt'             => '%s',
            'background'         => '%d',
            'max_tool_calls'     => '%d',
            'response_id'        => '%s',
            'response_status'    => '%s',
            'source_config'      => '%s',
            'request_payload'    => '%s',
            'response_payload'   => '%s',
            'report_message'     => '%s',
            'report_annotations' => '%s',
            'draft_post_id'      => '%d',
            'last_error'         => '%s',
            'completed_at'       => '%s',
        ];

        foreach ( $map as $key => $format ) {
            if ( ! array_key_exists( $key, $data ) ) {
                continue;
            }

            $value = $data[ $key ];

            if ( in_array( $key, [ 'source_config', 'request_payload', 'response_payload', 'report_annotations' ], true ) ) {
                $value = self::maybe_json_encode( $value );
            } elseif ( in_array( $key, [ 'user_id', 'background', 'max_tool_calls', 'draft_post_id' ], true ) ) {
                $value = absint( $value );
            } elseif ( 'completed_at' !== $key ) {
                $value = is_string( $value ) ? $value : (string) $value;
            }

            $fields[ $key ] = $value;
            $formats[]      = $format;
        }

        if ( empty( $fields ) ) {
            return;
        }

        $wpdb->update(
            self::table_runs(),
            $fields,
            [ 'id' => $run_id ],
            $formats,
            [ '%d' ]
        );
    }

    public static function get_run( int $run_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table_runs() . " WHERE id = %d", $run_id ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return self::decode_run( $row );
    }

    public static function get_run_by_response_id( string $response_id ): ?array {
        global $wpdb;

        $response_id = sanitize_text_field( $response_id );

        if ( '' === $response_id ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table_runs() . " WHERE response_id = %s LIMIT 1", $response_id ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return self::decode_run( $row );
    }

    public static function list_runs( int $limit = 20 ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_runs() . " ORDER BY created_at DESC LIMIT %d",
                max( 1, $limit )
            ),
            ARRAY_A
        );

        return array_map( [ self::class, 'decode_run' ], is_array( $rows ) ? $rows : [] );
    }

    public static function list_active_runs(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, response_id, response_status FROM " . self::table_runs() . " WHERE response_status IN ('queued', 'in_progress') ORDER BY created_at ASC",
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    public static function list_sources(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM " . self::table_sources() . " ORDER BY updated_at DESC, id DESC",
            ARRAY_A
        );

        return array_map( [ self::class, 'decode_source' ], is_array( $rows ) ? $rows : [] );
    }

    public static function get_source( int $source_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table_sources() . " WHERE id = %d", $source_id ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return self::decode_source( $row );
    }

    public static function create_source( array $data ): int {
        global $wpdb;

        $wpdb->insert(
            self::table_sources(),
            [
                'source_type' => sanitize_text_field( (string) ( $data['source_type'] ?? '' ) ),
                'name'        => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
                'status'      => sanitize_text_field( (string) ( $data['status'] ?? 'inactive' ) ),
                'config'      => self::maybe_json_encode( $data['config'] ?? null ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public static function delete_source( int $source_id ): void {
        global $wpdb;

        $wpdb->delete( self::table_sources(), [ 'id' => $source_id ], [ '%d' ] );
    }

    public static function replace_run_items( int $run_id, array $items ): void {
        global $wpdb;

        $wpdb->delete( self::table_run_items(), [ 'run_id' => $run_id ], [ '%d' ] );

        foreach ( array_values( $items ) as $index => $item ) {
            $wpdb->insert(
                self::table_run_items(),
                [
                    'run_id'       => $run_id,
                    'item_index'   => $index,
                    'item_type'    => sanitize_text_field( (string) ( $item['type'] ?? '' ) ),
                    'item_payload' => wp_json_encode( $item ),
                ],
                [ '%d', '%d', '%s', '%s' ]
            );
        }
    }

    public static function get_run_items( int $run_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT item_payload FROM " . self::table_run_items() . " WHERE run_id = %d ORDER BY item_index ASC",
                $run_id
            ),
            ARRAY_A
        );

        $items = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $decoded = json_decode( (string) ( $row['item_payload'] ?? '' ), true );
            if ( is_array( $decoded ) ) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    private static function maybe_json_encode( $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }

        if ( is_string( $value ) ) {
            return $value;
        }

        return wp_json_encode( $value );
    }

    private static function maybe_json_decode( $value ) {
        if ( ! is_string( $value ) || '' === $value ) {
            return $value;
        }

        $decoded = json_decode( $value, true );

        return JSON_ERROR_NONE === json_last_error() ? $decoded : $value;
    }

    private static function decode_run( array $row ): array {
        foreach ( [ 'source_config', 'request_payload', 'response_payload', 'report_annotations' ] as $key ) {
            $row[ $key ] = self::maybe_json_decode( $row[ $key ] ?? null );
        }

        $row['background'] = ! empty( $row['background'] );
        $row['items']      = self::get_run_items( (int) $row['id'] );

        return $row;
    }

    private static function decode_source( array $row ): array {
        $row['config'] = self::maybe_json_decode( $row['config'] ?? null );

        return $row;
    }
}
