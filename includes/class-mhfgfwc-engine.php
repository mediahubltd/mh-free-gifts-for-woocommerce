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

        $running = true;

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

            // Find existing gifts for this rule.
            $existing = $this->get_cart_gift_items_for_rule( $rule_id, $desired_gift_id );

            // If gift exists and matches, nothing to do.
            if ( ! empty( $existing['matches_desired'] ) ) {
                continue;
            }

            // If gifts exist but don't match the desired gift, remove them (auto-swap).
            $had_existing = ! empty( $existing['keys'] );
            if ( $had_existing ) {
                foreach ( (array) $existing['keys'] as $cart_item_key ) {
                    WC()->cart->remove_cart_item( $cart_item_key );
                }
            }

            // Add the desired gift.
            $added = $this->add_gift_to_cart( $desired_gift_id, $rule_id );

            if ( $added && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {
                $product = wc_get_product( $desired_gift_id );
                $name    = $product ? $product->get_name() : __( 'Free gift', 'mh-free-gifts-for-woocommerce' );

                $notice_key = ( $had_existing ? 'auto_swap_' : 'auto_add_' ) . $rule_id . '_' . $desired_gift_id;
                if ( empty( $notice_sent[ $notice_key ] ) ) {
                    $msg = $had_existing
                        ? sprintf( __( 'Free gift updated: %s', 'mh-free-gifts-for-woocommerce' ), $name )
                        : sprintf( __( 'Free gift added: %s', 'mh-free-gifts-for-woocommerce' ), $name );

                    wc_add_notice( $msg, 'success' );
                    $notice_sent[ $notice_key ] = true;
                }
            }
        }

        $running = false;
    }

    /**
     * Return cart gift items for a rule.
     *
     * @param int $rule_id Rule ID.
     * @return array {keys: string[], matches_desired: bool}
     */
    private function get_cart_gift_items_for_rule( $rule_id, $desired_gift_id = 0 ) {
        $rule_id = absint( $rule_id );
        $desired_gift_id = absint( $desired_gift_id );
        $out = array(
            'keys'            => array(),
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
                    $out['matches_desired'] = true;
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

		// Subtotal: contents total; include tax if the store displays prices incl. tax.
		$subtotal = $this->get_cart_subtotal_for_rules( $cart_obj );

		// Quantity across all line items.
		$qty = 0;
		foreach ( $cart_obj->get_cart() as $ci ) {
			$qty += isset( $ci['quantity'] ) ? (int) $ci['quantity'] : 0;
		}

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

			// Subtotal condition.
			if ( isset( $rule['subtotal_operator'], $rule['subtotal_amount'] )
				&& $rule['subtotal_operator'] !== ''
				&& $rule['subtotal_amount'] !== null && $rule['subtotal_amount'] !== '' ) {

				if ( ! $this->compare( $subtotal, (string) $rule['subtotal_operator'], (float) $rule['subtotal_amount'] ) ) {
					do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'subtotal' );
					continue;
				}
			}

			// Quantity condition.
			if ( isset( $rule['qty_operator'], $rule['qty_amount'] )
				&& $rule['qty_operator'] !== ''
				&& $rule['qty_amount'] !== null && $rule['qty_amount'] !== '' ) {

				if ( ! $this->compare( $qty, (string) $rule['qty_operator'], (int) $rule['qty_amount'] ) ) {
					do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'qty' );
					continue;
				}
			}

			// Product dependency (check both product_id and variation_id).
			$deps = array_filter( (array) maybe_unserialize( $rule['product_dependency'] ?? array() ), 'is_numeric' );
			if ( $deps ) {
				$deps   = array_map( 'intval', $deps );
				$found  = false;
				foreach ( $cart_obj->get_cart() as $item ) {
					$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
					$vid = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
					if ( ( $pid && in_array( $pid, $deps, true ) ) || ( $vid && in_array( $vid, $deps, true ) ) ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'product_dependency' );
					continue;
				}
			}
            
            /**
             * Category dependency (product_cat terms).
             * Accepts serialized array stored in DB column:
             *   - 'category_dependency' (preferred), OR
             *   - 'product_category_dependency' (fallback if you used that name)
             */
            $cat_deps_raw = $rule['category_dependency'] ?? ( $rule['product_category_dependency'] ?? array() );

            $cat_deps = array_map(
                'intval',
                array_filter( (array) maybe_unserialize( $cat_deps_raw ), 'is_numeric' )
            );

            if ( $cat_deps ) {
                if ( ! $this->cart_has_required_categories( $cat_deps ) ) {
                    do_action( 'mhfgfwc_rule_is_eligible', $rule_id, false, 'category_dependency' );
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

			$allowed = isset( $rule['gift_quantity'] ) ? (int) $rule['gift_quantity'] : 1;
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
