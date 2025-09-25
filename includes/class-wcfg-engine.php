<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCFG_Engine {
    private static $instance;
    private $rules = [];

    public static function instance() {
        if ( ! self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'init_hooks' ], 20 );
    }

    public function init_hooks() {
        $this->rules = $this->load_rules();

        // Evaluate in multiple late-but-safe places so first page loads work:
        add_action( 'template_redirect',                 [ $this, 'maybe_eval_for_page' ], 1 );   // early in templating
        add_action( 'woocommerce_before_cart',           [ $this, 'evaluate_cart_now'   ], 1 );
        add_action( 'woocommerce_before_checkout_form',  [ $this, 'evaluate_cart_now'   ], 1 );
        add_action( 'woocommerce_cart_loaded_from_session', [ $this, 'evaluate_cart_now' ], 20 );
        add_action( 'woocommerce_cart_updated',             [ $this, 'evaluate_cart_now' ], 20 );

        // Clear when done
        add_action( 'woocommerce_cart_emptied', [ $this, 'clear_session' ] );
        add_action( 'woocommerce_thankyou',     [ $this, 'clear_session' ] );
    }

    
    private function load_rules() {
        if ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'get_active_rules' ) ) {
            $rows = WCFG_DB::get_active_rules(); // cached
            return is_array( $rows ) ? $rows : [];
        }
        return [];
    }

    public function maybe_eval_for_page() {
        // Only bother on cart/checkout; harmless if called extra times
        if ( function_exists( 'is_cart' ) && function_exists( 'is_checkout' ) ) {
            if ( is_cart() || is_checkout() ) {
                $this->evaluate_cart_now();
            }
        }
    }

    /** Helper to call evaluate_cart with the live cart */
    public function evaluate_cart_now() {
        if ( function_exists( 'WC' ) && WC()->cart ) {
            $this->evaluate_cart( WC()->cart );
        }
    }

    /**
     * Evaluate the cart and set eligible gifts in session
     */
    public function evaluate_cart( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) { return; }

        // If rules were empty (cold cache / reload needed), fetch again now.
        if ( empty( $this->rules ) && class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'get_active_rules' ) ) {
            $this->rules = WCFG_DB::get_active_rules();
        }

        // ---- Normalize rules into a flat array of associative arrays with an id key ----
        $rules_raw = $this->rules;

        // If an object was cached, coerce to array
        if ( is_object( $rules_raw ) ) {
            $rules_raw = get_object_vars( $rules_raw );
        }

        // If wrapped (e.g., ['rows'=>[...]] or ['data'=>[...]]), unwrap
        if ( is_array( $rules_raw ) ) {
            if ( isset( $rules_raw['rows'] ) && is_array( $rules_raw['rows'] ) ) {
                $rules_raw = $rules_raw['rows'];
            } elseif ( isset( $rules_raw['data'] ) && is_array( $rules_raw['data'] ) ) {
                $rules_raw = $rules_raw['data'];
            } elseif ( count( $rules_raw ) === 1 && is_array( reset( $rules_raw ) ) ) {
                // Handle accidental double nesting: [ 0 => [ ...rule items... ] ]
                $first = reset( $rules_raw );
                // If the first element is an array-of-arrays/objects, assume that's the list
                $all_are_arrays = true;
                foreach ( $first as $k => $v ) {
                    if ( ! is_array( $v ) && ! is_object( $v ) ) { $all_are_arrays = false; break; }
                }
                if ( $all_are_arrays ) {
                    $rules_raw = $first;
                }
            }
        }

        // Final: ensure each item is an associative array
        $rules = [];
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

        $cart_obj = WC()->cart;

        // Use contents total (pre-fees/shipping); add tax if store displays prices incl. tax
        $contents_total = (float) $cart_obj->get_cart_contents_total();
        $subtotal = ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() )
            ? $contents_total + (float) $cart_obj->get_cart_contents_tax()
            : $contents_total;

        $qty = 0;
        foreach ( $cart_obj->get_cart() as $ci ) {
            $qty += isset( $ci['quantity'] ) ? (int) $ci['quantity'] : 0;
        }

        $eligible        = [];
        $user_id         = get_current_user_id();
        $applied_coupons = $cart_obj->get_applied_coupons();

        $debug = [];

        foreach ( (array) $rules as $rule ) {
            // Defensive: normalize again in case anything slipped through
            if ( is_object( $rule ) ) {
                $rule = get_object_vars( $rule );
            }

            // Accept common id key variants
            $rule_id = 0;
            if ( isset( $rule['id'] ) ) {
                $rule_id = (int) $rule['id'];
            } elseif ( isset( $rule['ID'] ) ) {
                $rule_id = (int) $rule['ID'];
            } elseif ( isset( $rule['rule_id'] ) ) {
                $rule_id = (int) $rule['rule_id'];
            } elseif ( isset( $rule['ruleID'] ) ) {
                $rule_id = (int) $rule['ruleID'];
            }


            // Registered-only
            if ( ! empty( $rule['user_only'] ) && ! $user_id ) { $why[] = 'user_only'; $this->debug_collect( $debug, $rule_id, $why ); continue; }

            // Usage limits
            if ( ! empty( $rule['limit_per_rule'] ) && $this->get_total_usage( $rule_id ) >= (int) $rule['limit_per_rule'] ) { $why[] = 'limit_per_rule'; $this->debug_collect( $debug, $rule_id, $why ); continue; }
            if ( ! empty( $rule['limit_per_user'] ) && $user_id && $this->get_user_usage( $rule_id, $user_id ) >= (int) $rule['limit_per_user'] ) { $why[] = 'limit_per_user'; $this->debug_collect( $debug, $rule_id, $why ); continue; }

            // Coupon conflict
            if ( ! empty( $rule['disable_with_coupon'] ) && ! empty( $applied_coupons ) ) { $why[] = 'coupon_present'; $this->debug_collect( $debug, $rule_id, $why ); continue; }

            // Subtotal condition
            if ( ! empty( $rule['subtotal_operator'] ) && $rule['subtotal_amount'] !== null && $rule['subtotal_amount'] !== '' ) {
                if ( ! $this->compare( $subtotal, (string) $rule['subtotal_operator'], (float) $rule['subtotal_amount'] ) ) {
                    $why[] = 'subtotal';
                    $this->debug_collect( $debug, $rule_id, $why );
                    continue;
                }
            }

            // Quantity condition
            if ( ! empty( $rule['qty_operator'] ) && $rule['qty_amount'] !== null && $rule['qty_amount'] !== '' ) {
                if ( ! $this->compare( $qty, (string) $rule['qty_operator'], (int) $rule['qty_amount'] ) ) {
                    $why[] = 'qty';
                    $this->debug_collect( $debug, $rule_id, $why );
                    continue;
                }
            }

            // Product dependency
            $deps = array_filter( (array) maybe_unserialize( $rule['product_dependency'] ?? [] ), 'is_numeric' );
            if ( $deps ) {
                $found = false;
                foreach ( $cart_obj->get_cart() as $item ) {
                    if ( in_array( (int) $item['product_id'], $deps, true ) ) { $found = true; break; }
                }
                if ( ! $found ) { $why[] = 'product_dependency'; $this->debug_collect( $debug, $rule_id, $why ); continue; }
            }

            // User dependency
            $users = array_filter( (array) maybe_unserialize( $rule['user_dependency'] ?? [] ), 'is_numeric' );
            if ( $users && ! in_array( (int) $user_id, $users, true ) ) { $why[] = 'user_dependency'; $this->debug_collect( $debug, $rule_id, $why ); continue; }

            // Gifts payload
            $gifts = array_map( 'intval', array_filter( (array) maybe_unserialize( $rule['gifts'] ?? [] ), 'is_numeric' ) );
            if ( $gifts ) {
                $allowed = isset( $rule['gift_quantity'] ) ? (int) $rule['gift_quantity'] : 1;
                $eligible[ $rule_id ] = [
                    'rule'    => $rule,
                    'gifts'   => $gifts,
                    'allowed' => max( 1, $allowed ),
                ];
            } else {
                $why[] = 'no_gifts';
                $this->debug_collect( $debug, $rule_id, $why );
            }
        }

        WC()->session->set( 'wcfg_available_gifts', $eligible );

    }

    private function debug_collect( array &$debug, $rule_id, array $why ) {
        if ( ! isset( $debug[ $rule_id ] ) ) { $debug[ $rule_id ] = []; }
        $debug[ $rule_id ] = array_values( array_unique( array_merge( $debug[ $rule_id ], $why ) ) );
    }

    private function compare( $value, $op, $threshold ) {
        switch ( $op ) {
            case '>':  return $value >  $threshold;
            case '>=': return $value >= $threshold;
            case '<':  return $value <  $threshold;
            case '<=': return $value <= $threshold;
            case '=':
            case '==': return $value == $threshold;
            default:   return false;
        }
    }

    private function get_total_usage( $rule_id ) {
        if ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'get_rule_total_usage' ) ) {
            return (int) WCFG_DB::get_rule_total_usage( (int) $rule_id );
        }
        return 0;
    }

    private function get_user_usage( $rule_id, $user_id ) {
        if ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'get_rule_user_usage' ) ) {
            return (int) WCFG_DB::get_rule_user_usage( (int) $rule_id, (int) $user_id );
        }
        return 0;
    }

    public function clear_session() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( 'wcfg_available_gifts' );
        }
    }
}

WCFG_Engine::instance();
