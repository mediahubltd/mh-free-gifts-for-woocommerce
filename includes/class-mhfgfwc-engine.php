<?php
/**
 * Gift engine – evaluates cart against active rules and exposes eligible gifts.
 *
 * @package MH_Free_Gifts_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MHFGFWC_Engine {

	/**
	 * Singleton instance.
	 *
	 * @var MHFGFWC_Engine|null
	 */
	private static $instance = null;

	/**
	 * Cached rules for the request.
	 *
	 * @var array
	 */
	private $rules = array();

	/**
	 * Session key for available gifts.
	 */
	const SESSION_KEY = 'mhfgfwc_available_gifts';

	/**
	 * Get instance.
	 *
	 * @return MHFGFWC_Engine
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'init_hooks' ), 20 );
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init_hooks() {
		$this->rules = $this->load_rules();
        
        add_action(
            'woocommerce_cart_loaded_from_session',
            [ $this, 'evaluate_cart' ],
            20
        );

        add_action(
            'woocommerce_before_calculate_totals',
            [ $this, 'evaluate_cart' ],
            5
        );
        
        add_action(
            'mhfgfwc_after_evaluate_cart',
            [ $this, 'remove_ineligible_gifts' ],
            10,
            2
        );

        // Auto-add (and auto-swap) gifts after eligibility is computed and ineligible gifts are removed.
        add_action(
            'mhfgfwc_after_evaluate_cart',
            [ $this, 'auto_add_eligible_gifts' ],
            20,
            2
        );



	}

    /**
     * Auto-add gifts to cart when:
     * - rule is eligible
     * - rule has auto_add_gift enabled
     * - rule has exactly 1 gift product configured
     *
     * Also performs "auto-swap" when the rule's single gift changes.
     *
     * @param array $eligible Eligible rules payload keyed by rule_id.
     * @param int   $user_id  Current user ID.
     * @return void
     */
	    public function auto_add_eligible_gifts( $eligible, $user_id ) {
	        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
	            return;
	        }

	        // Avoid doing this work in admin (except AJAX), and avoid notices during AJAX.
	        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
	            return;
	        }

	        // Prevent recursion / loops.
	        static $running = false;
	        if ( $running ) {
	            return;
	        }

	        // Track notices per request to avoid duplicates when Woo triggers multiple cart recalcs.
	        static $notice_sent = array();
	        $allow_accumulation = $this->allow_gift_accumulation();
	        $running = true;

	        if ( ! $allow_accumulation ) {
	            $preferred = $this->get_preferred_auto_add_rule( (array) $eligible );
	            if ( ! empty( $preferred['rule_id'] ) ) {
	                $this->sync_preferred_auto_add_gift( (int) $preferred['rule_id'], (array) $preferred['payload'], $notice_sent );
	            }

	            $running = false;
	            return;
	        }

	        foreach ( (array) $eligible as $rule_id => $payload ) {
	            $rule_id = absint( $rule_id );
	            if ( $rule_id <= 0 || ! is_array( $payload ) ) {
	                continue;
	            }

	            $rule = (array) ( $payload['rule'] ?? array() );
	            $auto = ! empty( $rule['auto_add_gift'] );
	            if ( ! $auto ) {
	                continue;
	            }

	            $gifts = array_map( 'intval', (array) ( $payload['gifts'] ?? array() ) );
	            $gifts = array_values( array_filter( $gifts ) );
	            if ( 1 !== count( $gifts ) ) {
	                // Guardrail: auto-add only works with a single gift in the rule.
	                continue;
	            }

	            $desired_gift_id = (int) $gifts[0];
	            if ( $desired_gift_id <= 0 ) {
	                continue;
	            }

	            $this->sync_auto_add_rule_gift_quantity( $rule_id, (array) $payload, $notice_sent );
	        }

	        $running = false;
	    }

	/**
	 * Pick the preferred auto-add rule when accumulation is disabled.
	 * Higher qualifying spend/qty tiers win over lower ones.
	 *
	 * @param array $eligible Eligible rules payload keyed by rule ID.
	 * @return array{rule_id:int,payload:array}
	 */
	private function get_preferred_auto_add_rule( array $eligible ) {
		$candidates = array();

		foreach ( $eligible as $rule_id => $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$rule  = isset( $payload['rule'] ) && is_array( $payload['rule'] ) ? $payload['rule'] : array();
			$gifts = array_values( array_filter( array_map( 'intval', (array) ( $payload['gifts'] ?? array() ) ) ) );

			if ( empty( $rule['auto_add_gift'] ) || 1 !== count( $gifts ) ) {
				continue;
			}

			$payload['rule']  = $rule;
			$payload['gifts'] = $gifts;
			$candidates[ absint( $rule_id ) ] = $payload;
		}

		if ( empty( $candidates ) ) {
			return array(
				'rule_id' => 0,
				'payload' => array(),
			);
		}

		uasort(
			$candidates,
			function( $a, $b ) {
				return $this->compare_auto_add_rule_priority( (array) $a, (array) $b );
			}
		);

		$rule_id = (int) key( $candidates );
		$payload = current( $candidates );

		return array(
			'rule_id' => $rule_id,
			'payload' => is_array( $payload ) ? $payload : array(),
		);
	}

	/**
	 * Compare two eligible auto-add rules for non-accumulation mode.
	 * Prefer higher subtotal thresholds, then higher quantity thresholds, then higher gift value.
	 *
	 * @param array $left  Eligible rule payload.
	 * @param array $right Eligible rule payload.
	 * @return int
	 */
	private function compare_auto_add_rule_priority( array $left, array $right ) {
		$left_rule  = isset( $left['rule'] ) && is_array( $left['rule'] ) ? $left['rule'] : array();
		$right_rule = isset( $right['rule'] ) && is_array( $right['rule'] ) ? $right['rule'] : array();

		$comparisons = array(
			array(
				$this->get_rule_threshold_weight( $left_rule, 'subtotal_operator', 'subtotal_amount' ),
				$this->get_rule_threshold_weight( $right_rule, 'subtotal_operator', 'subtotal_amount' ),
			),
			array(
				$this->get_rule_threshold_weight( $left_rule, 'qty_operator', 'qty_amount' ),
				$this->get_rule_threshold_weight( $right_rule, 'qty_operator', 'qty_amount' ),
			),
			array(
				$this->get_payload_max_gift_price( $left ),
				$this->get_payload_max_gift_price( $right ),
			),
			array(
				$this->get_rule_timestamp_weight( $left_rule ),
				$this->get_rule_timestamp_weight( $right_rule ),
			),
			array(
				(int) ( $left_rule['id'] ?? 0 ),
				(int) ( $right_rule['id'] ?? 0 ),
			),
		);

		foreach ( $comparisons as $pair ) {
			if ( $pair[0] === $pair[1] ) {
				continue;
			}

			return ( $pair[0] > $pair[1] ) ? -1 : 1;
		}

		return 0;
	}

	/**
	 * Convert a rule threshold into a sortable weight.
	 * Greater-than style rules rank higher as the threshold increases.
	 *
	 * @param array  $rule           Rule payload.
	 * @param string $operator_key   Operator field.
	 * @param string $threshold_key  Threshold field.
	 * @return float
	 */
	private function get_rule_threshold_weight( array $rule, $operator_key, $threshold_key ) {
		if ( ! isset( $rule[ $threshold_key ] ) || '' === $rule[ $threshold_key ] || null === $rule[ $threshold_key ] ) {
			return -INF;
		}

		$weight   = (float) $rule[ $threshold_key ];
		$operator = isset( $rule[ $operator_key ] ) ? (string) $rule[ $operator_key ] : '';

		if ( in_array( $operator, array( '<', '<=' ), true ) ) {
			return 0 - $weight;
		}

		return $weight;
	}

	/**
	 * Resolve a comparable timestamp weight from the rule.
	 *
	 * @param array $rule Rule payload.
	 * @return int
	 */
	private function get_rule_timestamp_weight( array $rule ) {
		$raw = isset( $rule['last_modified'] ) ? (string) $rule['last_modified'] : '';
		$ts  = $raw ? strtotime( $raw ) : false;

		return $ts ? (int) $ts : 0;
	}

	/**
	 * Find the maximum configured gift price for an eligible rule payload.
	 *
	 * @param array $payload Eligible rule payload.
	 * @return float
	 */
	private function get_payload_max_gift_price( array $payload ) {
		$max   = 0.0;
		$gifts = array_values( array_filter( array_map( 'intval', (array) ( $payload['gifts'] ?? array() ) ) ) );

		foreach ( $gifts as $gift_id ) {
			$product = wc_get_product( $gift_id );
			if ( ! $product ) {
				continue;
			}

			$price = (float) $product->get_price();
			if ( $price > $max ) {
				$max = $price;
			}
		}

		return $max;
	}

	/**
	 * Keep non-accumulating auto-add gifts synced to the preferred eligible rule.
	 *
	 * @param int   $rule_id     Preferred rule ID.
	 * @param array $payload     Preferred rule payload.
	 * @param array $notice_sent Deduplication map for notices.
	 * @return void
	 */
	private function sync_preferred_auto_add_gift( $rule_id, array $payload, array &$notice_sent ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$rule_id = absint( $rule_id );
		$gifts   = array_values( array_filter( array_map( 'intval', (array) ( $payload['gifts'] ?? array() ) ) ) );
		if ( $rule_id <= 0 || 1 !== count( $gifts ) ) {
			return;
		}

		$desired_gift_id  = (int) $gifts[0];
		$manual_gift_found = false;
		$auto_keys_to_remove = array();

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['mhfgfwc_gift'] ) ) {
				continue;
			}

			$item_rule_id = absint( $cart_item['mhfgfwc_gift'] );
			$item_auto    = ! empty( $cart_item['mhfgfwc_auto_added'] );
			$item_pid     = isset( $cart_item['variation_id'] ) && (int) $cart_item['variation_id']
				? (int) $cart_item['variation_id']
				: (int) ( $cart_item['product_id'] ?? 0 );

			$is_preferred_match = ( $item_rule_id === $rule_id && $item_pid === $desired_gift_id );

			if ( $item_auto ) {
				if ( ! $is_preferred_match ) {
					$auto_keys_to_remove[] = $cart_item_key;
				}
				continue;
			}

			if ( $item_rule_id !== $rule_id ) {
				$manual_gift_found = true;
			}
		}

		if ( ! empty( $auto_keys_to_remove ) ) {
			foreach ( $auto_keys_to_remove as $cart_item_key ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
		}

		$existing = $this->get_cart_gift_items_for_rule( $rule_id, $desired_gift_id );
		if ( ! empty( $existing['other_keys'] ) ) {
			foreach ( (array) $existing['other_keys'] as $cart_item_key ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
			$existing = $this->get_cart_gift_items_for_rule( $rule_id, $desired_gift_id );
		}

		if ( $manual_gift_found && empty( $existing['desired_keys'] ) ) {
			return;
		}

		$desired_count = $this->get_payload_allowed_gift_count( $payload );
		$current_count = count( (array) $existing['desired_keys'] );
		if ( $current_count >= $desired_count ) {
			return;
		}

		$missing      = $desired_count - $current_count;
		$had_existing = ! empty( $auto_keys_to_remove ) || ! empty( $existing['keys'] );
		$added        = false;

		for ( $i = 0; $i < $missing; $i++ ) {
			if ( ! $this->add_gift_to_cart( $desired_gift_id, $rule_id ) ) {
				break;
			}
			$added = true;
		}

		if ( ! $added ) {
			return;
		}

		$this->maybe_add_auto_gift_notice( $desired_gift_id, $rule_id, $desired_count, $had_existing, $notice_sent );
	}

	/**
	 * Keep an accumulating auto-add rule synced to the required number of copies.
	 *
	 * @param int   $rule_id     Rule ID.
	 * @param array $payload     Eligible rule payload.
	 * @param array $notice_sent Deduplication map for notices.
	 * @return void
	 */
	private function sync_auto_add_rule_gift_quantity( $rule_id, array $payload, array &$notice_sent ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$rule_id = absint( $rule_id );
		$gifts   = array_values( array_filter( array_map( 'intval', (array) ( $payload['gifts'] ?? array() ) ) ) );
		if ( $rule_id <= 0 || 1 !== count( $gifts ) ) {
			return;
		}

		$desired_gift_id = (int) $gifts[0];
		$desired_count   = $this->get_payload_allowed_gift_count( $payload );
		$existing        = $this->get_cart_gift_items_for_rule( $rule_id, $desired_gift_id );
		$had_existing    = ! empty( $existing['keys'] );

		if ( ! empty( $existing['other_keys'] ) ) {
			foreach ( (array) $existing['other_keys'] as $cart_item_key ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
			$existing = $this->get_cart_gift_items_for_rule( $rule_id, $desired_gift_id );
		}

		$current_count = count( (array) $existing['desired_keys'] );
		if ( $current_count >= $desired_count ) {
			return;
		}

		$missing = $desired_count - $current_count;
		$added   = false;

		for ( $i = 0; $i < $missing; $i++ ) {
			if ( ! $this->add_gift_to_cart( $desired_gift_id, $rule_id ) ) {
				break;
			}
			$added = true;
		}

		if ( ! $added ) {
			return;
		}

		$this->maybe_add_auto_gift_notice( $desired_gift_id, $rule_id, $desired_count, $had_existing, $notice_sent );
	}

	/**
	 * Resolve the required auto-added gift count from an eligible payload.
	 *
	 * @param array $payload Eligible rule payload.
	 * @return int
	 */
	private function get_payload_allowed_gift_count( array $payload ) {
		return max( 1, (int) ( $payload['allowed'] ?? 1 ) );
	}

	/**
	 * Add a deduplicated success notice for auto-added gifts.
	 *
	 * @param int   $gift_product_id Gift product ID.
	 * @param int   $rule_id         Rule ID.
	 * @param int   $desired_count   Required auto-added copy count.
	 * @param bool  $had_existing    Whether the rule already had any gift entries.
	 * @param array $notice_sent     Deduplication map.
	 * @return void
	 */
	private function maybe_add_auto_gift_notice( $gift_product_id, $rule_id, $desired_count, $had_existing, array &$notice_sent ) {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		$product    = wc_get_product( $gift_product_id );
		$name       = $product ? $product->get_name() : __( 'Free gift', 'mh-free-gifts-for-woocommerce' );
		$notice_key = ( $had_existing ? 'auto_update_' : 'auto_add_' ) . absint( $rule_id ) . '_' . absint( $gift_product_id ) . '_' . absint( $desired_count );

		if ( ! empty( $notice_sent[ $notice_key ] ) ) {
			return;
		}

		$msg = $had_existing
			? sprintf( __( 'Free gift updated: %s', 'mh-free-gifts-for-woocommerce' ), $name )
			: sprintf( __( 'Free gift added: %s', 'mh-free-gifts-for-woocommerce' ), $name );

		wc_add_notice( $msg, 'success' );
		$notice_sent[ $notice_key ] = true;
	}

    /**
     * Return cart gift items for a rule.
     *
     * @param int $rule_id Rule ID.
     * @return array {keys: string[], desired_keys: string[], other_keys: string[], matches_desired: bool}
     */
    private function get_cart_gift_items_for_rule( $rule_id, $desired_gift_id = 0 ) {
        $rule_id = absint( $rule_id );
        $desired_gift_id = absint( $desired_gift_id );
        $out = array(
            'keys'            => array(),
            'desired_keys'    => array(),
            'other_keys'      => array(),
            'matches_desired' => false,
        );

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return $out;
        }

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( empty( $cart_item['mhfgfwc_gift'] ) || (int) $cart_item['mhfgfwc_gift'] !== $rule_id ) {
                continue;
            }
            $out['keys'][] = $cart_item_key;

            if ( $desired_gift_id ) {
                $pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
                $vid = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
                if ( $desired_gift_id === $pid || $desired_gift_id === $vid ) {
                    $out['desired_keys'][] = $cart_item_key;
                    $out['matches_desired'] = true;
                } else {
                    $out['other_keys'][] = $cart_item_key;
                }
            }
        }

        return $out;
    }

    /**
     * Add a gift product to the cart with the correct meta flags.
     * Mirrors the behavior of the AJAX add handler, but server-side.
     *
     * @param int $gift_product_id Gift product/variation ID.
     * @param int $rule_id         Rule ID.
     * @return bool True if added.
     */
	    private function add_gift_to_cart( $gift_product_id, $rule_id ) {
	        $gift_product_id = absint( $gift_product_id );
	        $rule_id         = absint( $rule_id );

        if ( ! $gift_product_id || ! $rule_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }

        $product = wc_get_product( $gift_product_id );
        if ( ! $product || 'publish' !== $product->get_status() ) {
            return false;
        }
        if ( ! $product->is_in_stock() ) {
            return false;
        }

        $uid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'gift_', true );
        $gift_meta = array(
            'mhfgfwc_gift'       => $rule_id,
            'mhfgfwc_gift_uid'   => $uid,
            'mhfgfwc_auto_added' => 1,
        );

        if ( $product instanceof WC_Product_Variation ) {
            $cart_key = WC()->cart->add_to_cart(
                $product->get_parent_id(),
                1,
                $gift_product_id,
                $product->get_variation_attributes(),
                $gift_meta
            );
        } else {
            $cart_key = WC()->cart->add_to_cart(
                $gift_product_id,
                1,
                0,
                array(),
                $gift_meta
            );
        }

	        return ! empty( $cart_key );
	    }

	/**
	 * Check whether gifts may stack across multiple eligible rules.
	 *
	 * @return bool
	 */
	private function allow_gift_accumulation() {
		$settings = get_option( 'mhfgfwc_general_settings', array() );
		if ( ! is_array( $settings ) ) {
			return true;
		}

		return ! isset( $settings['allow_accumulation'] ) || ! empty( $settings['allow_accumulation'] );
	}

	/**
	 * Count free-gift line items currently in the cart.
	 *
	 * @return int
	 */
	private function count_cart_gifts() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		$count = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['mhfgfwc_gift'] ) ) {
				$count++;
			}
		}

		return $count;
	}
    
	/**
	 * Load active rules (cached in DB helper).
	 *
	 * @return array
	 */
	private function load_rules() {
		if ( class_exists( 'MHFGFWC_DB' ) && method_exists( 'MHFGFWC_DB', 'get_active_rules' ) ) {
			$rows = MHFGFWC_DB::get_active_rules(); // cached
			return is_array( $rows ) ? $rows : array();
		}
		return array();
	}

	/**
	 * Only evaluate on cart/checkout templates to keep things lean.
	 *
	 * @return void
	 */
	public function maybe_eval_for_page() {
		if ( function_exists( 'is_cart' ) && function_exists( 'is_checkout' ) ) {
			if ( is_cart() || is_checkout() ) {
				$this->evaluate_cart_now();
			}
		}
	}

	/**
	 * Helper to evaluate with the live cart.
	 *
	 * @return void
	 */
	public function evaluate_cart_now() {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$this->evaluate_cart( WC()->cart );
		}
	}

	/**
	 * Evaluate the cart and set eligible gifts in WC session.
	 *
	 * @param \WC_Cart $cart Cart object.
	 * @return void
	 */
	public function evaluate_cart( $cart ) {
		// Don't hard-stop based on woocommerce_init timing; hooks can fire before it.
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
            return;
        }


		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		// If rules were empty (cold cache / reload needed), fetch again now.
		if ( empty( $this->rules ) && class_exists( 'MHFGFWC_DB' ) && method_exists( 'MHFGFWC_DB', 'get_active_rules' ) ) {
			$this->rules = MHFGFWC_DB::get_active_rules();
		}

		// ---- Normalize rules into a flat array of associative arrays with an id key ----
		$rules = $this->normalize_rules_array( $this->rules );

		// Allow 3rd parties to filter/short-circuit the rule list before evaluation.
		$rules = (array) apply_filters( 'mhfgfwc_rules_pre_evaluate', $rules );

		$cart_obj = WC()->cart;

		$cart_items = $this->get_purchased_cart_items_for_rule_evaluation( $cart_obj );
		$subtotal   = $this->get_cart_items_subtotal_for_rules( $cart_items, $cart_obj );
		$qty        = $this->get_cart_items_quantity_for_rules( $cart_items );

		$eligible        = array();
		$user_id         = get_current_user_id();
		$applied_coupons = (array) $cart_obj->get_applied_coupons();

		foreach ( (array) $rules as $rule ) {
			if ( is_object( $rule ) ) {
				$rule = get_object_vars( $rule );
			}
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$rule_id = $this->coerce_rule_id( $rule );
			if ( $rule_id <= 0 ) {
				continue;
			}

			// Basic checks / gatekeepers.
			if ( ! empty( $rule['user_only'] ) && ! $user_id ) {
				do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'user_only' );
				continue;
			}

			// Usage limits (total and per-user).
			if ( ! empty( $rule['limit_per_rule'] ) && $this->get_total_usage( $rule_id ) >= (int) $rule['limit_per_rule'] ) {
				do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'limit_per_rule' );
				continue;
			}
			if ( ! empty( $rule['limit_per_user'] ) && $user_id && $this->get_user_usage( $rule_id, $user_id ) >= (int) $rule['limit_per_user'] ) {
				do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'limit_per_user' );
				continue;
			}

			// Coupon conflict.
			if ( ! empty( $rule['disable_with_coupon'] ) && ! empty( $applied_coupons ) ) {
				do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'coupon_present' );
				continue;
			}

			$rule_context = $this->get_rule_threshold_context( $rule, $cart_items, $subtotal, $qty );

			// Product dependency.
			if ( ! $rule_context['has_product_dependency_match'] ) {
				do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'product_dependency' );
				continue;
			}

			// Category dependency.
			if ( ! $rule_context['has_category_dependency_match'] ) {
				do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'category_dependency' );
				continue;
			}

			// Subtotal condition.
			if ( isset( $rule['subtotal_operator'], $rule['subtotal_amount'] )
				&& $rule['subtotal_operator'] !== ''
				&& $rule['subtotal_amount'] !== null && $rule['subtotal_amount'] !== '' ) {

				if ( ! $this->compare( $rule_context['subtotal'], (string) $rule['subtotal_operator'], (float) $rule['subtotal_amount'] ) ) {
					do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'subtotal' );
					continue;
				}
			}

			// Quantity condition.
			if ( isset( $rule['qty_operator'], $rule['qty_amount'] )
				&& $rule['qty_operator'] !== ''
				&& $rule['qty_amount'] !== null && $rule['qty_amount'] !== '' ) {

				if ( ! $this->compare( $rule_context['qty'], (string) $rule['qty_operator'], (int) $rule['qty_amount'] ) ) {
					do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'qty' );
					continue;
				}
			}

			// User dependency.
			$users = array_filter( (array) maybe_unserialize( $rule['user_dependency'] ?? array() ), 'is_numeric' );
			if ( $users ) {
				$users = array_map( 'intval', $users );
				if ( ! $user_id || ! in_array( (int) $user_id, $users, true ) ) {
					do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'user_dependency' );
					continue;
				}
			}

			// Gifts payload.
			$gifts = array_map( 'intval', array_filter( (array) maybe_unserialize( $rule['gifts'] ?? array() ), 'is_numeric' ) );
			if ( ! $gifts ) {
				do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'no_gifts' );
				continue;
			}

			$allowed = $this->get_rule_allowed_gift_quantity( $rule, $rule_context['qty'] );
			$payload = array(
				'rule'    => $rule,
				'gifts'   => $gifts,
				'allowed' => max( 1, $allowed ),
			);

			/**
			 * Filter the payload stored per eligible rule.
			 *
			 * @param array $payload Payload array.
			 * @param int   $rule_id Rule ID.
			 */
			$payload = (array) apply_filters( 'mhfgfwc_eligible_gifts_payload', $payload, $rule_id );

			$eligible[ $rule_id ] = $payload;

			do_action( 'mhfgfwc_rule_is_eligible', $rule_id, true, '' );
		}

		// Store in WC session under a filterable key.
		$session_key = apply_filters( 'mhfgfwc_session_key', self::SESSION_KEY );
		WC()->session->set( $session_key, $eligible );
		WC()->session->set( 'mhfgfwc_rules_rev', (int) get_option( 'mhfgfwc_rules_rev', 0 ) );
		WC()->session->set( 'mhfgfwc_rules_user_id', (int) get_current_user_id() );

		/**
		 * Fires after the cart has been evaluated and session updated.
		 *
		 * @param array $eligible Eligible payload keyed by rule_id.
		 * @param int   $user_id  Current user ID.
		 */
		do_action( 'mhfgfwc_after_evaluate_cart', $eligible, $user_id );
	}
    
    /*public function enforce_gift_rules() {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( ! WC()->cart || ! WC()->session ) {
            return;
        }

        $session_key = apply_filters( 'mhfgfwc_session_key', self::SESSION_KEY );
        $eligible    = (array) WC()->session->get( $session_key, array() );

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

            if ( empty( $cart_item['mhfgfwc_rule_id'] ) ) {
                continue;
            }

            $rule_id = absint( $cart_item['mhfgfwc_rule_id'] );

            // Rule no longer eligible → remove gift
            if ( empty( $eligible[ $rule_id ] ) ) {
                WC()->cart->remove_cart_item( $cart_item_key );
            }
        }
    }*/

    public function enforce_gift_rules( $cart ) {
        // Do not run in wp-admin (except AJAX)
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
            return;
        }

        // Prevent recursion / loops
        static $running = false;
        if ( $running ) {
            return;
        }

        $session_key = apply_filters( 'mhfgfwc_session_key', self::SESSION_KEY );
        $eligible    = (array) WC()->session->get( $session_key, array() );

        $to_remove = array();

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

            // Gifts are tagged with mhfgfwc_gift = rule_id
            if ( empty( $cart_item['mhfgfwc_gift'] ) ) {
                continue;
            }

            $rule_id = absint( $cart_item['mhfgfwc_gift'] );

            // Rule no longer eligible → remove gift
            if ( $rule_id && empty( $eligible[ $rule_id ] ) ) {
                $to_remove[] = $cart_item_key;
            }
        }

        if ( empty( $to_remove ) ) {
            return;
        }

        // Defer the actual removal until after totals to avoid calc loops.
        add_action( 'woocommerce_after_calculate_totals', function() use ( $to_remove, &$running ) {
            if ( ! WC()->cart ) {
                return;
            }

            $running = true;

            foreach ( $to_remove as $cart_item_key ) {
                if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
                    WC()->cart->remove_cart_item( $cart_item_key );
                }
            }

            $running = false;
        }, 999 );
    }

    public function remove_ineligible_gifts( $eligible, $user_id ) {

        if ( ! WC()->cart ) {
            return;
        }

        // Avoid duplicate notices during Woo recalculation cascades.
        static $notice_sent = array();

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

            // Gifts are tagged with mhfgfwc_gift = rule_id
            if ( empty( $cart_item['mhfgfwc_gift'] ) ) {
                continue;
            }

            $rule_id = absint( $cart_item['mhfgfwc_gift'] );

            // Rule no longer eligible → remove gift
            if ( empty( $eligible[ $rule_id ] ) ) {

                $auto_add = $this->is_rule_auto_add_enabled( $rule_id );
                if ( $auto_add && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {
                    $pid  = isset( $cart_item['variation_id'] ) && (int) $cart_item['variation_id'] ? (int) $cart_item['variation_id'] : (int) ( $cart_item['product_id'] ?? 0 );
                    $prod = $pid ? wc_get_product( $pid ) : null;
                    $name = $prod ? $prod->get_name() : __( 'Free gift', 'mh-free-gifts-for-woocommerce' );

                    $notice_key = 'auto_remove_' . $rule_id . '_' . $pid;
                    if ( empty( $notice_sent[ $notice_key ] ) ) {
                        wc_add_notice( sprintf( __( 'Free gift removed: %s', 'mh-free-gifts-for-woocommerce' ), $name ), 'notice' );
                        $notice_sent[ $notice_key ] = true;
                    }
                }

                WC()->cart->remove_cart_item( $cart_item_key );
            }
        }
    }

    /**
     * Check if a rule has auto_add_gift enabled.
     * We look in the cached rules loaded for the request.
     *
     * @param int $rule_id Rule ID.
     * @return bool
     */
    private function is_rule_auto_add_enabled( $rule_id ) {
        $rule_id = absint( $rule_id );
        if ( $rule_id <= 0 ) {
            return false;
        }

        foreach ( (array) $this->normalize_rules_array( $this->rules ) as $rule ) {
            if ( is_object( $rule ) ) {
                $rule = get_object_vars( $rule );
            }
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $id = $this->coerce_rule_id( $rule );
            if ( $id === $rule_id ) {
                return ! empty( $rule['auto_add_gift'] );
            }
        }

        return false;
    }


	/**
	 * Normalize a potentially nested rules array into a flat array of associative arrays.
	 *
	 * @param mixed $raw Raw rules from cache/DB.
	 * @return array
	 */
	private function normalize_rules_array( $raw ) {
		$rules_raw = $raw;

		if ( is_object( $rules_raw ) ) {
			$rules_raw = get_object_vars( $rules_raw );
		}

		// If wrapped (e.g., ['rows'=>[...]] or ['data'=>[...]]), unwrap.
		if ( is_array( $rules_raw ) ) {
			if ( isset( $rules_raw['rows'] ) && is_array( $rules_raw['rows'] ) ) {
				$rules_raw = $rules_raw['rows'];
			} elseif ( isset( $rules_raw['data'] ) && is_array( $rules_raw['data'] ) ) {
				$rules_raw = $rules_raw['data'];
			} elseif ( count( $rules_raw ) === 1 && is_array( reset( $rules_raw ) ) ) {
				$first = reset( $rules_raw );
				$all_are_arrays = true;
				foreach ( $first as $v ) {
					if ( ! is_array( $v ) && ! is_object( $v ) ) {
						$all_are_arrays = false;
						break;
					}
				}
				if ( $all_are_arrays ) {
					$rules_raw = $first;
				}
			}
		}

		$rules = array();
		if ( is_array( $rules_raw ) ) {
			foreach ( $rules_raw as $item ) {
				if ( is_object( $item ) ) {
					$item = get_object_vars( $item );
				}
				if ( is_array( $item ) ) {
					$rules[] = $item;
				}
			}
		}

		return $rules;
	}

	/**
	 * Coerce a rule ID from commonly used keys.
	 *
	 * @param array $rule Rule row.
	 * @return int
	 */
	private function coerce_rule_id( array $rule ) {
		if ( isset( $rule['id'] ) ) {
			return (int) $rule['id'];
		}
		if ( isset( $rule['ID'] ) ) {
			return (int) $rule['ID'];
		}
		if ( isset( $rule['rule_id'] ) ) {
			return (int) $rule['rule_id'];
		}
		if ( isset( $rule['ruleID'] ) ) {
			return (int) $rule['ruleID'];
		}
		return 0;
	}

	/**
	 * Compare helpers for numeric operators.
	 *
	 * @param float|int $value     Left value.
	 * @param string    $op        Operator.
	 * @param float|int $threshold Right value.
	 * @return bool
	 */
	private function compare( $value, $op, $threshold ) {
		switch ( (string) $op ) {
			case '>':  return ( $value >  $threshold );
			case '>=': return ( $value >= $threshold );
			case '<':  return ( $value <  $threshold );
			case '<=': return ( $value <= $threshold );
			case '=':
			case '==': return ( $value == $threshold ); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
		}
		return false;
	}

	/**
	 * Resolve the allowed gift count for a rule after quantity scaling.
	 *
	 * @param array $rule Rule payload.
	 * @param int   $qty  Current cart quantity.
	 * @return int
	 */
	private function get_rule_allowed_gift_quantity( array $rule, $qty ) {
		$base_allowed = isset( $rule['gift_quantity'] ) ? max( 1, (int) $rule['gift_quantity'] ) : 1;

		if ( empty( $rule['gift_quantity_multiplier'] ) ) {
			return $base_allowed;
		}

		$step = $this->get_rule_quantity_multiple_step( $rule );
		if ( $step <= 0 ) {
			return $base_allowed;
		}

		$multiples = (int) floor( max( 0, (int) $qty ) / $step );
		if ( $multiples < 1 ) {
			return $base_allowed;
		}

		return max( 1, $base_allowed * $multiples );
	}

	/**
	 * Resolve the cart quantity step used for gift-quantity multiplication.
	 *
	 * @param array $rule Rule payload.
	 * @return int
	 */
	private function get_rule_quantity_multiple_step( array $rule ) {
		$qty_amount = isset( $rule['qty_amount'] ) ? (int) $rule['qty_amount'] : 0;
		if ( $qty_amount <= 0 ) {
			return 0;
		}

		$operator = isset( $rule['qty_operator'] ) ? (string) $rule['qty_operator'] : '';
		if ( '>=' === $operator ) {
			return $qty_amount;
		}

		if ( '>' === $operator ) {
			return $qty_amount + 1;
		}

		return 0;
	}

	/**
	 * Calculate the subtotal used for rules.
	 * Defaults to contents total, optionally including tax if prices include tax.
	 * Filterable for stores needing different semantics.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return float
	 */
	/*private function get_cart_subtotal_for_rules( $cart ) {
		$contents_total = (float) $cart->get_cart_contents_total();
		$subtotal = ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() )
			? $contents_total + (float) $cart->get_cart_contents_tax()
			: $contents_total;

		return (float) apply_filters( 'mhfgfwc_rules_subtotal', $subtotal, $cart );
	}*/
    
    private function get_cart_subtotal_for_rules( $cart ) {

        $total = 0.0;

        foreach ( $cart->get_cart() as $item ) {

            // Exclude all free gifts
            if ( ! empty( $item['mhfgfwc_gift'] ) ) {
                continue;
            }

            // line_total = ex tax
            // line_tax   = tax amount for this line
            $line_total = isset( $item['line_total'] ) ? (float) $item['line_total'] : 0;
            $line_tax   = isset( $item['line_tax'] )   ? (float) $item['line_tax']   : 0;

            $total += ( $line_total + $line_tax );
        }

        /**
         * Filter the computed cart total used by the gift engine.
         *
         * @param float    $total
         * @param WC_Cart  $cart
         */
        return (float) apply_filters( 'mhfgfwc_rules_subtotal', $total, $cart );
    }

	/**
	 * Normalize purchased cart line items for per-rule evaluation.
	 *
	 * @param \WC_Cart $cart Cart instance.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_purchased_cart_items_for_rule_evaluation( $cart ) {
		$items = array();

		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['mhfgfwc_gift'] ) ) {
				continue;
			}

			$category_ids = $this->get_cart_item_category_ids_for_rule_evaluation( $item );

			$items[] = array(
				'product_id'   => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
				'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'quantity'     => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
				'subtotal'     => ( isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0 ) + ( isset( $item['line_tax'] ) ? (float) $item['line_tax'] : 0.0 ),
				'category_ids' => $category_ids,
			);
		}

		return $items;
	}

	/**
	 * Resolve cart-item category IDs for rule evaluation.
	 * Variations inherit categories from the parent product, so prefer product_id.
	 *
	 * @param array<string,mixed> $item Raw Woo cart item.
	 * @return array<int>
	 */
	private function get_cart_item_category_ids_for_rule_evaluation( array $item ) {
		$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

		if ( ! $product && isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'get_category_ids' ) ) {
			$product = $item['data'];
		}

		if ( ! $product || ! method_exists( $product, 'get_category_ids' ) ) {
			return array();
		}

		return array_map( 'intval', (array) $product->get_category_ids() );
	}

	/**
	 * Total purchased quantity from normalized cart items.
	 *
	 * @param array<int,array<string,mixed>> $cart_items Normalized purchased cart items.
	 * @return int
	 */
	private function get_cart_items_quantity_for_rules( array $cart_items ) {
		$total = 0;

		foreach ( $cart_items as $cart_item ) {
			$total += isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
		}

		return $total;
	}

	/**
	 * Total purchased subtotal from normalized cart items.
	 *
	 * @param array<int,array<string,mixed>> $cart_items Normalized purchased cart items.
	 * @param \WC_Cart                       $cart       Cart instance.
	 * @return float
	 */
	private function get_cart_items_subtotal_for_rules( array $cart_items, $cart ) {
		$total = 0.0;

		foreach ( $cart_items as $cart_item ) {
			$total += isset( $cart_item['subtotal'] ) ? (float) $cart_item['subtotal'] : 0.0;
		}

		return (float) apply_filters( 'mhfgfwc_rules_subtotal', $total, $cart );
	}

	/**
	 * Resolve the quantity/subtotal basis for a rule.
	 *
	 * @param array                         $rule          Rule payload.
	 * @param array<int,array<string,mixed>> $cart_items    Normalized purchased cart items.
	 * @param float                         $cart_subtotal Whole-cart purchased subtotal.
	 * @param int                           $cart_qty      Whole-cart purchased quantity.
	 * @return array<string,mixed>
	 */
	private function get_rule_threshold_context( array $rule, array $cart_items, $cart_subtotal, $cart_qty ) {
		$product_dependencies = $this->get_rule_product_dependencies( $rule );
		$category_dependencies = $this->get_rule_category_dependencies( $rule );
		$dependency_scope = ! empty( $product_dependencies ) || ! empty( $category_dependencies );
		$scoped_qty = 0;
		$scoped_subtotal = 0.0;
		$has_product_dependency_match = empty( $product_dependencies );
		$has_category_dependency_match = empty( $category_dependencies );

		foreach ( $cart_items as $cart_item ) {
			$matches_product = $this->cart_item_matches_products( $cart_item, $product_dependencies );
			$matches_category = $this->cart_item_matches_categories( $cart_item, $category_dependencies );

			if ( $matches_product ) {
				$has_product_dependency_match = true;
			}

			if ( $matches_category ) {
				$has_category_dependency_match = true;
			}

			if ( ! $this->cart_item_matches_dependency_filters( $cart_item, $product_dependencies, $category_dependencies ) ) {
				continue;
			}

			$scoped_qty += isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
			$scoped_subtotal += isset( $cart_item['subtotal'] ) ? (float) $cart_item['subtotal'] : 0.0;
		}

		$use_dependency_scope = $dependency_scope && 'dependencies' === $this->get_rule_threshold_scope( $rule );

		return array(
			'qty'                         => $use_dependency_scope ? $scoped_qty : (int) $cart_qty,
			'subtotal'                    => $use_dependency_scope ? $scoped_subtotal : (float) $cart_subtotal,
			'has_product_dependency_match' => $has_product_dependency_match,
			'has_category_dependency_match' => $has_category_dependency_match,
		);
	}

	/**
	 * Resolve the threshold scope setting for a rule.
	 *
	 * @param array $rule Rule payload.
	 * @return string
	 */
	private function get_rule_threshold_scope( array $rule ) {
		$scope = isset( $rule['threshold_scope'] ) ? sanitize_key( (string) $rule['threshold_scope'] ) : 'cart';

		return in_array( $scope, array( 'cart', 'dependencies' ), true ) ? $scope : 'cart';
	}

	/**
	 * Parse configured product dependency IDs.
	 *
	 * @param array $rule Rule payload.
	 * @return array<int>
	 */
	private function get_rule_product_dependencies( array $rule ) {
		return array_map(
			'intval',
			array_filter( (array) maybe_unserialize( $rule['product_dependency'] ?? array() ), 'is_numeric' )
		);
	}

	/**
	 * Parse configured category dependency IDs.
	 *
	 * @param array $rule Rule payload.
	 * @return array<int>
	 */
	private function get_rule_category_dependencies( array $rule ) {
		$raw = $rule['category_dependency'] ?? ( $rule['product_category_dependency'] ?? array() );

		return array_map(
			'intval',
			array_filter( (array) maybe_unserialize( $raw ), 'is_numeric' )
		);
	}

	/**
	 * Whether a cart item matches the configured product dependency IDs.
	 *
	 * @param array<string,mixed> $cart_item            Normalized cart item.
	 * @param array<int>          $product_dependencies Product IDs / variation IDs.
	 * @return bool
	 */
	private function cart_item_matches_products( array $cart_item, array $product_dependencies ) {
		if ( empty( $product_dependencies ) ) {
			return true;
		}

		$product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;

		return ( $product_id && in_array( $product_id, $product_dependencies, true ) )
			|| ( $variation_id && in_array( $variation_id, $product_dependencies, true ) );
	}

	/**
	 * Whether a cart item matches the configured category dependency IDs.
	 *
	 * @param array<string,mixed> $cart_item              Normalized cart item.
	 * @param array<int>          $category_dependencies Category term IDs.
	 * @return bool
	 */
	private function cart_item_matches_categories( array $cart_item, array $category_dependencies ) {
		if ( empty( $category_dependencies ) ) {
			return true;
		}

		$category_ids = isset( $cart_item['category_ids'] ) ? array_map( 'intval', (array) $cart_item['category_ids'] ) : array();

		return ! empty( array_intersect( $category_dependencies, $category_ids ) );
	}

	/**
	 * Whether a cart item matches all configured dependency filters for scoped thresholds.
	 *
	 * @param array<string,mixed> $cart_item              Normalized cart item.
	 * @param array<int>          $product_dependencies  Product IDs / variation IDs.
	 * @param array<int>          $category_dependencies Category term IDs.
	 * @return bool
	 */
	private function cart_item_matches_dependency_filters( array $cart_item, array $product_dependencies, array $category_dependencies ) {
		if ( ! empty( $product_dependencies ) && ! $this->cart_item_matches_products( $cart_item, $product_dependencies ) ) {
			return false;
		}

		if ( ! empty( $category_dependencies ) && ! $this->cart_item_matches_categories( $cart_item, $category_dependencies ) ) {
			return false;
		}

		return true;
	}



    public function handle_cart_item_removed( $cart_item_key, $cart ) {

        if ( ! WC()->cart || ! WC()->session ) {
            return;
        }

        // Re-evaluate eligibility using live cart contents
        $this->evaluate_cart( WC()->cart );

        // Enforce removal of gifts immediately
        $this->enforce_gift_rules();

        // Force WooCommerce to recalculate totals
        WC()->cart->calculate_totals();
    }



	/**
	 * Total usage across all users for a rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @return int
	 */
	private function get_total_usage( $rule_id ) {
		if ( class_exists( 'MHFGFWC_DB' ) && method_exists( 'MHFGFWC_DB', 'get_rule_total_usage' ) ) {
			return (int) MHFGFWC_DB::get_rule_total_usage( (int) $rule_id );
		}
		return 0;
	}

	/**
	 * Usage for a specific user and rule.
	 *
	 * @param int $rule_id Rule ID.
	 * @param int $user_id User ID.
	 * @return int
	 */
	private function get_user_usage( $rule_id, $user_id ) {
		if ( class_exists( 'MHFGFWC_DB' ) && method_exists( 'MHFGFWC_DB', 'get_rule_user_usage' ) ) {
			return (int) MHFGFWC_DB::get_rule_user_usage( (int) $rule_id, (int) $user_id );
		}
		return 0;
	}
    
    protected function cart_has_required_categories( array $required_term_ids ) {
        if ( empty( $required_term_ids ) ) {
            return true; // no dependency set
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }

        $required = array_map( 'intval', $required_term_ids );

        foreach ( WC()->cart->get_cart() as $item ) {
            $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            if ( ! $pid ) {
                continue;
            }
            $product = wc_get_product( $pid );
            if ( ! $product ) {
                continue;
            }

            // WooCommerce stores categories on the parent for variations;
            // get_category_ids() handles that appropriately.
            $cats = (array) $product->get_category_ids(); // array<int>
            if ( array_intersect( $required, $cats ) ) {
                return true;
            }
        }

        return false;
    }

	/**
	 * Clear session payload after checkout / when cart empties.
	 *
	 * @return void
	 */
	public function clear_session() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$key = apply_filters( 'mhfgfwc_session_key', self::SESSION_KEY );
			WC()->session->__unset( $key );
		}
	}
}

MHFGFWC_Engine::instance();
