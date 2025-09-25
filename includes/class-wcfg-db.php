<?php
// includes/class-wcfg-db.php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WCFG_DB {
    const CACHE_GROUP = 'wcfg';
    // Single source of truth for the rules cache key
    const RULES_KEY   = 'wcfg_rules_active_v1';

    public static function rules_table() {
        global $wpdb;
        return $wpdb->prefix . 'wcfg_rules';
    }

    public static function usage_table() {
        global $wpdb;
        return $wpdb->prefix . 'wcfg_usage';
    }

    /** Clear the active-rules cache everywhere we might have stored it */
    public static function bust_rules_cache() {
        wp_cache_delete( self::RULES_KEY, self::CACHE_GROUP );
        // Legacy keys we may have used previously:
        wp_cache_delete( 'wcfg_rules', self::CACHE_GROUP );
        wp_cache_delete( 'rules_active_v1', self::CACHE_GROUP );
        wp_cache_delete( 'rules_active_v2', self::CACHE_GROUP );
        delete_transient( self::RULES_KEY );
    }

    /** Light wrappers */
    private static function cache_get( $key ) {
        $v = wp_cache_get( $key, self::CACHE_GROUP );
        return ( false === $v ) ? null : $v;
    }
    private static function cache_set( $key, $value, $ttl = 60 ) {
        wp_cache_set( $key, $value, self::CACHE_GROUP, (int) $ttl );
    }

    /**
     * Return ACTIVE, date-valid rules (assoc arrays) – cached.
     */
    public static function get_active_rules() {
        $cached = self::cache_get( self::RULES_KEY );
        if ( null !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $table   = self::rules_table();
        $now_gmt = current_time( 'mysql', true );

        // Select
        // Table name is internal/trusted; placeholders used for values.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                   FROM {$table}
                  WHERE CAST(status AS UNSIGNED) = %d
                    AND (
                            date_from IS NULL
                         OR date_from = ''
                         OR date_from = '0000-00-00 00:00:00'
                         OR date_from <= %s
                        )
                    AND (
                            date_to IS NULL
                         OR date_to = ''
                         OR date_to = '0000-00-00 00:00:00'
                         OR date_to >= %s
                        )",
                1,
                $now_gmt,
                $now_gmt
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        self::cache_set( self::RULES_KEY, $rows ?: [], 60 );
        return $rows ?: [];
    }

    /**
     * Aggregate usage across all users for a rule.
     */
    public static function get_rule_total_usage( $rule_id ) {
        global $wpdb;
        $table = self::usage_table();

        // Select
        // Table name is internal/trusted; value uses placeholders.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(times_used) FROM {$table} WHERE rule_id = %d",
                absint( $rule_id )
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return (int) $val;
    }

    /**
     * Usage for a specific user and rule.
     */
    public static function get_rule_user_usage( $rule_id, $user_id ) {
        global $wpdb;
        $table = self::usage_table();

        // Select
        // Table name is internal/trusted; values use placeholders.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT times_used FROM {$table} WHERE rule_id = %d AND user_id = %d",
                absint( $rule_id ),
                absint( $user_id )
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return (int) $val;
    }

    /**
     * Increment usage counter (creates row if missing).
     */
    public static function increment_usage( $rule_id, $user_id = 0, $by = 1 ) {
        global $wpdb;
        $table = self::usage_table();

        $rule_id = absint( $rule_id );
        $user_id = absint( $user_id );
        $by      = (int) $by;

        // Existence check
        // Table name is internal/trusted; values use placeholders.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE rule_id = %d AND user_id = %d",
                $rule_id,
                $user_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( null !== $exists ) {
            // Update in place
            // Table name is internal/trusted; values use placeholders.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                        SET times_used = times_used + %d
                      WHERE rule_id = %d AND user_id = %d",
                    $by,
                    $rule_id,
                    $user_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return;
        }

        // Insert new row
        // Writes are intentional; placeholders used for values.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            $table,
            [
                'rule_id'    => $rule_id,
                'user_id'    => $user_id,
                'times_used' => max( 0, $by ),
            ],
            [ '%d', '%d', '%d' ]
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }
}
