<?php
/**
 * Installation & Upgrade for WooBuddy Free Gifts
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCFG_Install {
    const SCHEMA_VERSION = '1.1.0'; // bump when you change SQL

    /**
     * Main entry: called on plugin activation.
     */
    public static function install() {
        self::maybe_install_or_upgrade();
    }

    /**
     * Also run on admin_init if schema version doesn't match (fixes missed activation).
     */
    public static function maybe_install_or_upgrade() {
        $installed = get_option( 'wcfg_schema_version' );
        if ( $installed !== self::SCHEMA_VERSION ) {
            self::install_tables();
            // self::seed(); // optional
            if ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'bust_rules_cache' ) ) {
                WCFG_DB::bust_rules_cache();
            }
            update_option( 'wcfg_schema_version', self::SCHEMA_VERSION, true );
        }
    }

    /**
     * Create/upgrade custom tables using dbDelta (idempotent).
     */
    public static function install_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Prefer helper for table names to avoid typos and keep single source of truth
        $rules_table = ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'rules_table' ) )
            ? WCFG_DB::rules_table()
            : $wpdb->prefix . 'wcfg_rules';

        $usage_table = ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'usage_table' ) )
            ? WCFG_DB::usage_table()
            : $wpdb->prefix . 'wcfg_usage';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Rules table
        $sql_rules = "
        CREATE TABLE {$rules_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            user_only TINYINT(1) NOT NULL DEFAULT 0,
            limit_per_rule INT(10) UNSIGNED NULL,
            limit_per_user INT(10) UNSIGNED NULL,
            gifts LONGTEXT NOT NULL,
            gift_quantity INT(10) UNSIGNED NOT NULL DEFAULT 1,
            product_dependency LONGTEXT NULL,
            user_dependency LONGTEXT NULL,
            disable_with_coupon TINYINT(1) NOT NULL DEFAULT 0,
            subtotal_operator VARCHAR(4) NULL,
            subtotal_amount DECIMAL(10,2) NULL,
            qty_operator VARCHAR(4) NULL,
            qty_amount INT(10) UNSIGNED NULL,
            date_from DATETIME NULL,
            date_to DATETIME NULL,
            display_location VARCHAR(20) NOT NULL DEFAULT 'cart',
            items_per_row INT(3) NOT NULL DEFAULT 4,
            last_modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY date_from (date_from),
            KEY date_to (date_to)
        ) {$charset_collate};";

        // Usage table
        $sql_usage = "
        CREATE TABLE {$usage_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            times_used INT(10) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY rule_id (rule_id),
            KEY user_id (user_id)
        ) {$charset_collate};";

        dbDelta( $sql_rules );
        dbDelta( $sql_usage );
    }

    /**
     * Optional initial seed for testing/dev.
     */
    private static function seed() {
        return; // no-op by default

        /*
        global $wpdb;
        $rules_table = ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'rules_table' ) )
            ? WCFG_DB::rules_table()
            : $wpdb->prefix . 'wcfg_rules';

        $exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rules_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        if ( ! $exists ) {
            $wpdb->insert( $rules_table, [
                'name'          => 'Welcome Gift',
                'status'        => 1,
                'gifts'         => maybe_serialize( [] ),
                'gift_quantity' => 1,
            ] );
        }
        */
    }
}
