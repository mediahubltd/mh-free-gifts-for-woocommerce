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

		// Evaluate in multiple late-but-safe places so first page loads work.
		add_action( 'template_redirect', array( $this, 'maybe_eval_for_page' ), 1 ); // early in templating.
		add_action( 'woocommerce_before_cart', array( $this, 'evaluate_cart_now' ), 1 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'evaluate_cart_now' ), 1 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'evaluate_cart_now' ), 20 );
		add_action( 'woocommerce_cart_updated', array( $this, 'evaluate_cart_now' ), 20 );

		// Clear when done.
		add_action( 'woocommerce_cart_emptied', array( $this, 'clear_session' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'clear_session' ) );
        
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'evaluate_cart' ), 1 );
        add_action( 'woocommerce_add_to_cart', array( $this, 'evaluate_cart' ), 20 );
        add_action( 'woocommerce_cart_item_removed', array( $this, 'evaluate_cart' ), 20 );


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
		// Do not run in wp-admin (except AJAX) to avoid overhead.
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
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
	private function get_cart_subtotal_for_rules( $cart ) {
		$contents_total = (float) $cart->get_cart_contents_total();
		$subtotal = ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() )
			? $contents_total + (float) $cart->get_cart_contents_tax()
			: $contents_total;

		/**
		 * Filter the computed cart subtotal used by the gift engine.
		 *
		 * @param float    $subtotal
		 * @param \WC_Cart $cart
		 */
		return (float) apply_filters( 'mhfgfwc_rules_subtotal', $subtotal, $cart );
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
