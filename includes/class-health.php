<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

/**
 * System health checks for EntryVault.
 *
 * Reports DB schema completeness, composer autoload state, PHP / WordPress /
 * plugin versions, and an aggregate "overall" status. Extensions can append
 * their own sections via the entryvault_health_report filter.
 *
 * Status rules:
 *   - fail: required tables missing OR composer autoload not present
 *   - ok:   everything healthy
 */
class Health {

    /**
     * Tables that must exist for EntryVault to function. Names are unprefixed;
     * the actual lookup uses {$wpdb->prefix} . 'entryvault_' . $name. Extensions
     * append their own tables via the entryvault_health_required_tables filter
     * in check_db().
     */
    private const REQUIRED_TABLES = [
        'submissions',
        'submission_data',
        'pipelines',
        'stages',
        'tags',
        'submission_tags',
        'timeline',
        'emails',
    ];

    /**
     * Run all checks and return a structured report.
     *
     * @return array{
     *   db: array{ok:bool, tables_present:bool, missing_tables: array<int,string>},
     *   composer: array{ok:bool, autoload_ok:bool},
     *   php_version: string,
     *   wp_version: string,
     *   plugin_version: string,
     *   overall: string
     * }
     */
    /**
     * Which form plugins EntryVault is currently listening to.
     *
     * The headline promise is that entries are captured from the form plugins you
     * already run, with nothing to configure — but no screen said which ones had
     * been detected, so the only way to find out was to submit a live form and see
     * whether a card appeared. List them.
     *
     * @return array{ok:bool, active:array<int,string>, count:int}
     */
    private static function check_sources(): array {
        $active = [];

        if ( class_exists( '\\EntryVault\\Adapter_Registry' ) ) {
            foreach ( Adapter_Registry::instance()->all() as $slug => $adapter ) {
                $active[] = method_exists( $adapter, 'label' )
                    ? (string) $adapter->label()
                    : (string) $slug;
            }
        }

        sort( $active );

        return [
            // Not a failure (CSV import still works), but not all-clear either:
            // with no form plugin nothing is being captured, and a green "OK"
            // next to "No form plugin detected yet" said the opposite.
            'ok'     => count( $active ) > 0,
            'active' => $active,
            'count'  => count( $active ),
        ];
    }

    public static function check(): array {
        $db       = self::check_db();
        $composer = self::check_composer();

        $php_version    = PHP_VERSION;
        $wp_version     = self::wp_version();
        $plugin_version = defined( 'ENTRYVAULT_VERSION' ) ? (string) ENTRYVAULT_VERSION : '';

        $sources = self::check_sources();

        $overall = 'ok';
        if ( ! $db['ok'] || ! $composer['ok'] ) {
            $overall = 'fail';
        } elseif ( ! $sources['ok'] ) {
            $overall = 'warn';
        }

        $report = [
            'db'             => $db,
            'composer'       => $composer,
            'sources'        => $sources,
            'php_version'    => $php_version,
            'wp_version'     => $wp_version,
            'plugin_version' => $plugin_version,
            'overall'        => $overall,
        ];

        // Extensions may append their own report sections here.
        return (array) apply_filters( 'entryvault_health_report', $report );
    }

    private static function check_db(): array {
        global $wpdb;

        $missing = [];
        $prefix  = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';

        // SHOW TABLES LIKE '<prefix>entryvault_%' — collect into a lookup map.
        $like   = $prefix . 'entryvault_%';
        $rows   = is_object( $wpdb ) && method_exists( $wpdb, 'get_col' )
            ? (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) )
            : [];
        $found  = [];
        foreach ( $rows as $name ) {
            $found[ (string) $name ] = true;
        }

        $required = (array) apply_filters( 'entryvault_health_required_tables', self::REQUIRED_TABLES );
        foreach ( $required as $t ) {
            $full = $prefix . 'entryvault_' . $t;
            if ( ! isset( $found[ $full ] ) ) {
                $missing[] = $full;
            }
        }

        $present = empty( $missing );
        return [
            'ok'             => $present,
            'tables_present' => $present,
            'missing_tables' => array_values( $missing ),
        ];
    }

    private static function check_composer(): array {
        $autoload = defined( 'ENTRYVAULT_PATH' ) ? ENTRYVAULT_PATH . 'vendor/autoload.php' : '';
        $autoload_ok = $autoload !== '' && is_file( $autoload );
        return [
            'ok'          => $autoload_ok,
            'autoload_ok' => $autoload_ok,
        ];
    }

    private static function wp_version(): string {
        if ( isset( $GLOBALS['wp_version'] ) ) {
            return (string) $GLOBALS['wp_version'];
        }
        if ( function_exists( 'get_bloginfo' ) ) {
            $v = get_bloginfo( 'version' );
            if ( is_string( $v ) && $v !== '' ) {
                return $v;
            }
        }
        return '';
    }
}
