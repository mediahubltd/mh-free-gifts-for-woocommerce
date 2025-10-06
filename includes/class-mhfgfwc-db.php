<?php
/**
 * DB helpers for MH Free Gifts for WooCommerce
 *
 * @package MH_Free_Gifts_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Final DB class (no instances)
 */
final class MHFGFWC_DB {

	/**
	 * Object cache group.
	 */
	const CACHE_GROUP = 'mhfgfwc';

	/**
	 * Single source of truth for the active rules cache key.
	 */
	const RULES_KEY = 'mhfgfwc_rules_active_v1';

	/**
	 * Table name for rules.
	 *
	 * @return string
	 */
	public static function rules_table() {
		global $wpdb;
		return $wpdb->prefix . 'mhfgfwc_rules';
	}

	/**
	 * Table name for usage.
	 *
	 * @return string
	 */
	public static function usage_table() {
		global $wpdb;
		return $wpdb->prefix . 'mhfgfwc_usage';
	}

	/**
	 * Clear the active-rules cache everywhere we might have stored it.
	 *
	 * @return void
	 */
	public static function bust_rules_cache() {
		wp_cache_delete( self::RULES_KEY, self::CACHE_GROUP );

		// Legacy keys we may have used previously.
		wp_cache_delete( 'mhfgfwc_rules', self::CACHE_GROUP );
		wp_cache_delete( 'rules_active_v1', self::CACHE_GROUP );
		wp_cache_delete( 'rules_active_v2', self::CACHE_GROUP );

		delete_transient( self::RULES_KEY );
	}

	/**
	 * Get from object cache (null if cache miss).
	 *
	 * @param string $key Cache key.
	 * @return mixed|null
	 */
	private static function cache_get( $key ) {
		$v = wp_cache_get( $key, self::CACHE_GROUP );
		return ( false === $v ) ? null : $v;
	}

	/**
	 * Set object cache and transient fallback if applicable.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds to keep cached.
	 * @return void
	 */
	private static function cache_set( $key, $value, $ttl = 60 ) {
		$ttl = (int) $ttl;
		if ( $ttl < 0 ) {
			$ttl = 0;
		}

		// Object cache (always).
		wp_cache_set( $key, $value, self::CACHE_GROUP, $ttl );

		// If there is no external object cache, also persist briefly via transient.
		if ( ! wp_using_ext_object_cache() ) {
			set_transient( $key, $value, $ttl );
		}
	}

	/**
	 * Return ACTIVE, date-valid rules (assoc arrays) – cached.
	 *
	 * @return array[] Array of associative arrays for rules.
	 */
	public static function get_active_rules() {
		// Try object cache first.
		$cached = self::cache_get( self::RULES_KEY );

		// Fall back to transient if we didn't find it in object cache.
		if ( null === $cached ) {
			$transient = get_transient( self::RULES_KEY );
			if ( false !== $transient ) {
				// Re-hydrate object cache for subsequent calls in this request.
				wp_cache_set( self::RULES_KEY, $transient, self::CACHE_GROUP );
				return is_array( $transient ) ? $transient : [];
			}
		} else {
			return $cached;
		}

		global $wpdb;

		$table   = self::rules_table(); // Trusted internal table name.
		$now_gmt = current_time( 'mysql', true );

		/**
		 * Allow 3rd parties to adjust the WHERE time columns (advanced).
		 *
		 * @param array $columns Array with 'from' and 'to' keys.
		 */
		$time_cols = apply_filters(
			'mhfgfwc_rules_time_columns',
			array(
				'from' => 'date_from',
				'to'   => 'date_to',
			)
		);

		$from_col = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) ( $time_cols['from'] ?? 'date_from' ) );
		$to_col   = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) ( $time_cols['to'] ?? 'date_to' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				   FROM {$table}
				  WHERE CAST(status AS UNSIGNED) = %d
				    AND (
				            {$from_col} IS NULL
				         OR {$from_col} = ''
				         OR {$from_col} = '0000-00-00 00:00:00'
				         OR {$from_col} <= %s
				        )
				    AND (
				            {$to_col} IS NULL
				         OR {$to_col} = ''
				         OR {$to_col} = '0000-00-00 00:00:00'
				         OR {$to_col} >= %s
				        )",
				1,
				$now_gmt,
				$now_gmt
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = is_array( $rows ) ? $rows : array();

		/**
		 * Allow filtering or normalization of rows before caching.
		 *
		 * @param array $rows
		 */
		$rows = (array) apply_filters( 'mhfgfwc_active_rules_rows', $rows );

		/**
		 * Filter the cache TTL (seconds) for active rules.
		 *
		 * @param int $ttl Default 60.
		 */
		$ttl = (int) apply_filters( 'mhfgfwc_rules_cache_ttl', 60 );

		self::cache_set( self::RULES_KEY, $rows, $ttl );

		return $rows;
	}

	/**
	 * Aggregate usage across all users for a rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @return int Total times used (>= 0).
	 */
	public static function get_rule_total_usage( $rule_id ) {
		global $wpdb;
		$table = self::usage_table();

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
	 *
	 * @param int $rule_id Rule ID.
	 * @param int $user_id User ID.
	 * @return int Times used (>= 0).
	 */
	public static function get_rule_user_usage( $rule_id, $user_id ) {
		global $wpdb;
		$table = self::usage_table();

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
	 *
	 * @param int $rule_id Rule ID.
	 * @param int $user_id User ID (0 = guest).
	 * @param int $by      Increment by (>= 1).
	 * @return void
	 */
	public static function increment_usage( $rule_id, $user_id = 0, $by = 1 ) {
		global $wpdb;
		$table = self::usage_table();

		$rule_id = absint( $rule_id );
		$user_id = absint( $user_id );
		$by      = max( 1, (int) $by );

		// Check existence.
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
			// Update in place.
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

		// Insert new row.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'rule_id'    => $rule_id,
				'user_id'    => $user_id,
				'times_used' => $by,
			),
			array( '%d', '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
