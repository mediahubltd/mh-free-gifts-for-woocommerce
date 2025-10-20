<?php
/**
 * Installation & Upgrade for MH Free Gifts for WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MHFGFWC_Install {

    /**
     * Increment when you change SQL schemas.
     */
    const SCHEMA_VERSION = '1.0.1';

    /**
     * Uniform, prefixed option key for schema version.
     */
    const OPTION_KEY = 'mhfgfwc_schema_version';

    /**
     * Entry point used on activation.
     */
    public static function install() {
        self::maybe_install_or_upgrade();
    }

    /**
     * Run on admin_init (safety net) and on activation.
     * Creates/updates tables when version mismatch is detected.
     */
    public static function maybe_install_or_upgrade() {
        $installed = get_option( self::OPTION_KEY, '' );

        if ( $installed !== self::SCHEMA_VERSION ) {
            self::install_tables();

            // Ensure the option exists with autoload 'yes' on first add; update otherwise.
            if ( false === get_option( self::OPTION_KEY, false ) ) {
                add_option( self::OPTION_KEY, self::SCHEMA_VERSION, '', 'yes' );
            } else {
                update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
            }
        }
    }

    /**
     * Create/upgrade DB tables via dbDelta.
     */
    public static function install_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $rules_table = $wpdb->prefix . 'mhfgfwc_rules';
        $usage_table = $wpdb->prefix . 'mhfgfwc_usage';

        // NOTE: Keep VARCHAR keys ≤191 for utf8mb4 compatibility.
        $sql_rules = "CREATE TABLE {$rules_table} (
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
            category_dependency LONGTEXT NULL,
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
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        $sql_usage = "CREATE TABLE {$usage_table} (
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
}
