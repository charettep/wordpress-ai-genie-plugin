<?php
defined( 'ABSPATH' ) || exit;

class AIG_Deep_Research_Install {

    const CRON_HOOK = 'aig_deep_research_poll_runs';
    const CRON_SCHEDULE = 'aig_deep_research_minutely';

    public static function init(): void {
        add_filter( 'cron_schedules', [ self::class, 'register_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ 'AIG_Deep_Research_Service', 'poll_active_runs' ] );

        if ( version_compare( AIG_Deep_Research_Store::get_db_version(), AIG_Deep_Research_Store::DB_VERSION, '<' ) ) {
            AIG_Deep_Research_Store::install_schema();
        }

        self::ensure_cron_scheduled();
    }

    public static function activate(): void {
        AIG_Deep_Research_Store::install_schema();
        self::ensure_cron_scheduled();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function register_cron_schedule( array $schedules ): array {
        $schedules[ self::CRON_SCHEDULE ] = [
            'interval' => 60,
            'display'  => __( 'Every minute', 'ai-genie' ),
        ];

        return $schedules;
    }

    private static function ensure_cron_scheduled(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }
}
