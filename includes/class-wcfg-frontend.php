<?php
/**
 * Frontend display and AJAX handlers for Free Gifts for WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCFG_Frontend {
    private static $instance;

    /**
     * Singleton accessor
     *
     * @return WCFG_Frontend
     */
    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor: hook into front-end behavior
     */
    private function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Gift selectors
        add_action( 'woocommerce_after_cart_table', [ $this, 'display_cart_gifts' ], 20 );
        add_action( 'woocommerce_checkout_before_order_review', [ $this, 'display_checkout_toggle' ], 20 );

        // Pricing + qty hardening
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_free_gift_prices' ], 20 );
        add_filter( 'woocommerce_cart_item_quantity',     [ $this, 'filter_gift_quantity_field' ], 10, 3 );
        add_filter( 'woocommerce_update_cart_validation', [ $this, 'validate_gift_quantity_update' ], 10, 4 );
        add_action( 'woocommerce_before_calculate_totals',[ $this, 'force_gift_qty_one' ], 5 );

        // Surface “Free gift” in line item data (cart/checkout/emails)
        add_filter( 'woocommerce_get_item_data', [ $this, 'render_gift_badge_item_data' ], 10, 2 );

        // AJAX
        add_action( 'wc_ajax_wcfg_add_gift',            [ $this, 'ajax_add_gift' ] );
        add_action( 'wc_ajax_nopriv_wcfg_add_gift',     [ $this, 'ajax_add_gift' ] );
        add_action( 'wc_ajax_wcfg_remove_gift',         [ $this, 'ajax_remove_gift' ] );
        add_action( 'wc_ajax_nopriv_wcfg_remove_gift',  [ $this, 'ajax_remove_gift' ] );
    }

    /**
     * Enqueue CSS/JS and localize AJAX params
     */
    public function enqueue_assets() {
        wp_enqueue_style( 'wcfg-frontend', WCFG_PLUGIN_URL . 'assets/css/frontend.css', [], WCFG_VERSION );
        wp_enqueue_script( 'wcfg-frontend', WCFG_PLUGIN_URL . 'assets/js/frontend.js', [ 'jquery' ], WCFG_VERSION, true );
        wp_localize_script( 'wcfg-frontend', 'wcfgFrontend', [
            'ajax_url_add'    => WC_AJAX::get_endpoint( 'wcfg_add_gift' ),
            'ajax_url_remove' => WC_AJAX::get_endpoint( 'wcfg_remove_gift' ),
            'nonce'           => wp_create_nonce( 'wcfg_frontend_nonce' ),
            'i18n'            => [
                'toggle'     => __( 'Free Gift', 'mh-free-gifts-for-woocommerce' ),
                'add'        => __( 'Add Gift', 'mh-free-gifts-for-woocommerce' ),
                'adding'     => __( 'Adding…', 'mh-free-gifts-for-woocommerce' ),
                'remove'     => __( 'Remove Gift', 'mh-free-gifts-for-woocommerce' ),
                'removing'   => __( 'Removing…', 'mh-free-gifts-for-woocommerce' ),
                'ajax_error' => __( 'AJAX error. Please try again.', 'mh-free-gifts-for-woocommerce' ),
            ],
        ]);
    }

    /**
     * Show a non-editable “1” for gift line items.
     */
    public function filter_gift_quantity_field( $product_quantity, $cart_item_key, $cart_item ) {
        if ( ! empty( $cart_item['wcfg_gift'] ) ) {
            return '<span class="wcfg-qty">1</span>';
        }
        return $product_quantity;
    }

    /**
     * Block cart updates which try to set gift qty > 1.
     */
    public function validate_gift_quantity_update( $passed, $cart_item_key, $values, $quantity ) {
        if ( ! empty( $values['wcfg_gift'] ) && $quantity > 1 ) {
            wc_add_notice( __( 'Free gifts are limited to quantity 1 per selection.', 'mh-free-gifts-for-woocommerce' ), 'error' );
            return false;
        }
        return $passed;
    }

    /**
     * If anything slipped through, force gift quantity back to 1 before totals.
     */
    public function force_gift_qty_one( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        foreach ( $cart->get_cart() as $key => $item ) {
            if ( ! empty( $item['wcfg_gift'] ) && (int) $item['quantity'] !== 1 ) {
                // false = don’t trigger recalculation loops
                WC()->cart->set_quantity( $key, 1, false );
            }
        }
    }

    /**
     * Add a small “Free gift” badge under the line item name.
     */
    public function render_gift_badge_item_data( $item_data, $cart_item ) {
        if ( ! empty( $cart_item['wcfg_gift'] ) ) {
            $item_data[] = [
                'name'  => '',
                'value' => '<span class="wcfg-badge">' . esc_html__( 'Free gift', 'mh-free-gifts-for-woocommerce' ) . '</span>',
                'display' => '',
            ];
        }
        return $item_data;
    }

    /**
     * Render “Choose Your Free Gift” as a coupon-style toggle on checkout
     * (Uses session-provided map. If missing, we simply don’t show the UI.)
     */
    public function display_checkout_toggle() {
        $available = WC()->session->get( 'wcfg_available_gifts', [] );
        if ( empty( $available ) || ! is_array( $available ) ) {
            return;
        }

        $gift_cart = [];
        foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
            if ( ! empty( $item['wcfg_gift'] ) ) {
                $rid = (int) $item['wcfg_gift'];
                $pid = (int) $item['product_id'];
                if ( ! isset( $gift_cart[ $rid ] ) ) {
                    $gift_cart[ $rid ] = [];
                }
                $gift_cart[ $rid ][ $pid ] = $cart_item_key;
            }
        }

        $counts = [];
        foreach ( $gift_cart as $rid => $products ) {
            $counts[ $rid ] = count( $products );
        }

        echo '<div class="woocommerce-form-coupon-toggle wcfg-toggle">';
        echo '<a href="#" class="wcfg-show-gifts-toggle">'
            . esc_html__( 'Free Gift', 'mh-free-gifts-for-woocommerce' )
            . '</a>';
        echo '</div>';

        echo '<div class="wcfg-gift-section" style="display:none;">';

        foreach ( $available as $rule_id => $data ) {
            $rule_id     = (int) $rule_id;
            $max_allowed = isset( $data['allowed'] ) ? (int) $data['allowed'] : 0;
            $have        = isset( $counts[ $rule_id ] ) ? (int) $counts[ $rule_id ] : 0;
            $disabled    = ( $max_allowed > 0 && $have >= $max_allowed );
            $rule_class  = $disabled ? ' wcfg-disabled-rule' : '';

            echo '<div class="wcfg-rule-group' . esc_attr( $rule_class ) . '" data-rule="' . esc_attr( $rule_id ) . '">';

            $items_per_row = isset( $data['rule']['items_per_row'] ) ? (int) $data['rule']['items_per_row'] : 4;
            echo '<div class="wcfg-grid" style="--wcfg-items-per-row:' . esc_attr( $items_per_row ) . '">';

            $raw_gifts = isset( $data['rule']['gifts'] ) ? $data['rule']['gifts'] : [];
            $gifts     = array_filter( (array) maybe_unserialize( $raw_gifts ), 'is_numeric' );

            foreach ( $gifts as $prod_id ) {
                $prod_id = (int) $prod_id;
                $product = wc_get_product( $prod_id );
                if ( ! $product || 'publish' !== $product->get_status() ) {
                    continue;
                }

                echo '<div class="wcfg-gift-item">';
                echo   '<div class="wcfg-thumb">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '</div>';
                echo   '<div class="wcfg-title">' . esc_html( $product->get_name() ) . '</div>';

                if ( isset( $gift_cart[ $rule_id ][ $prod_id ] ) ) {
                    $item_key = (string) $gift_cart[ $rule_id ][ $prod_id ];
                    echo '<a href="#" class="wcfg-remove-gift" data-item-key="' . esc_attr( $item_key ) . '">' . esc_html__( 'Remove Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
                } elseif ( ! $disabled ) {
                    echo '<a href="#" class="wcfg-add-gift" data-rule="' . esc_attr( $rule_id ) . '" data-product="' . esc_attr( $prod_id ) . '">' . esc_html__( 'Add Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
                }

                echo '</div>'; // .wcfg-gift-item
            }

            echo '</div></div>'; // .wcfg-grid, .wcfg-rule-group
        }

        echo '</div>'; // .wcfg-gift-section
    }

    /**
     * Render the gift selector grid on cart & checkout (cart area)
     */
    public function display_cart_gifts() {
        $available = WC()->session->get( 'wcfg_available_gifts', [] );
        if ( ! is_array( $available ) || empty( $available ) ) {
            return;
        }

        // Map existing gift items in cart: [ rule_id => [ product_id => cart_item_key ] ]
        $gift_cart = [];
        foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
            if ( ! empty( $item['wcfg_gift'] ) ) {
                $rid = (int) $item['wcfg_gift'];
                $pid = (int) $item['product_id'];
                if ( ! isset( $gift_cart[ $rid ] ) ) {
                    $gift_cart[ $rid ] = [];
                }
                $gift_cart[ $rid ][ $pid ] = $cart_item_key;
            }
        }

        echo '<div class="wcfg-gift-header">' . esc_html__( 'Choose Your Free Gift', 'mh-free-gifts-for-woocommerce' ) . '</div>';
        echo '<div class="wcfg-gift-selector">';

        foreach ( $available as $rule_id => $data ) {
            $rule_id     = (int) $rule_id;
            $max_allowed = isset( $data['allowed'] ) ? (int) $data['allowed'] : 0;
            $have        = isset( $gift_cart[ $rule_id ] ) ? count( $gift_cart[ $rule_id ] ) : 0;
            $disabled    = ( $max_allowed > 0 && $have >= $max_allowed );
            $rule_class  = $disabled ? ' wcfg-disabled-rule' : '';

            echo '<div class="wcfg-rule-group' . esc_attr( $rule_class ) . '" data-rule="' . esc_attr( $rule_id ) . '">';

            $items_per_row = isset( $data['rule']['items_per_row'] ) ? (int) $data['rule']['items_per_row'] : 4;
            echo '<div class="wcfg-grid" style="--wcfg-items-per-row:' . esc_attr( $items_per_row ) . '">';

            $raw_gifts = isset( $data['rule']['gifts'] ) ? $data['rule']['gifts'] : [];
            $gifts     = array_filter( (array) maybe_unserialize( $raw_gifts ), 'is_numeric' );

            foreach ( $gifts as $prod_id ) {
                $prod_id = (int) $prod_id;
                $product = wc_get_product( $prod_id );
                if ( ! $product || 'publish' !== $product->get_status() ) {
                    continue;
                }

                echo '<div class="wcfg-gift-item">';
                echo '<div class="wcfg-thumb">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '</div>';
                echo '<div class="wcfg-title">' . esc_html( $product->get_name() ) . '</div>';

                if ( isset( $gift_cart[ $rule_id ][ $prod_id ] ) ) {
                    $item_key = (string) $gift_cart[ $rule_id ][ $prod_id ];
                    echo '<a href="#" class="wcfg-remove-gift" data-item-key="' . esc_attr( $item_key ) . '">' . esc_html__( 'Remove Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
                } elseif ( ! $disabled ) {
                    echo '<a href="#" class="wcfg-add-gift" data-rule="' . esc_attr( $rule_id ) . '" data-product="' . esc_attr( $prod_id ) . '">' . esc_html__( 'Add Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
                }

                echo '</div>';
            }

            echo '</div></div>';
        }

        echo '</div>';
    }

    /**
     * Zero out gift prices
     */
    public function apply_free_gift_prices( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item['wcfg_gift'] ) && isset( $item['data'] ) && is_object( $item['data'] ) ) {
                $item['data']->set_price( 0 );
                if ( method_exists( $item['data'], 'set_sale_price' ) ) {
                    $item['data']->set_sale_price( 0 );
                }
            }
        }
    }

    /**
     * AJAX handler: add a gift
     * Primary validation uses session; if missing or stale, we fall back to WCFG_DB::get_active_rules()
     */
    public function ajax_add_gift() {
        check_ajax_referer( 'wcfg_frontend_nonce', 'nonce' );

        // Sanitize POST inputs (no direct $_POST use)
        $pid = filter_input( INPUT_POST, 'product', FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
        $rid = filter_input( INPUT_POST, 'rule',    FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );

        $pid = $pid ? (int) $pid : 0;
        $rid = $rid ? (int) $rid : 0;

        if ( ! $pid || ! $rid ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Missing parameters.', 'mh-free-gifts-for-woocommerce' ) ] );
        }

        // Build current availability map from session first
        $available = WC()->session->get( 'wcfg_available_gifts', [] );

        // Fallback validation via DB helper if session is empty/stale
        if ( empty( $available[ $rid ] ) && class_exists( 'WCFG_DB' ) ) {
            $rules = WCFG_DB::get_active_rules(); // cached; no DB hit if warm
            foreach ( (array) $rules as $row ) {
                // Normalize to array if needed
                if ( is_object( $row ) ) {
                    $row = get_object_vars( $row );
                }
                $rule_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
                if ( ! $rule_id ) {
                    continue;
                }
                if ( $rule_id === $rid ) {
                    $gifts = array_filter( (array) maybe_unserialize( $row['gifts'] ?? [] ), 'is_numeric' );
                    $available[ $rule_id ] = [
                        'allowed' => isset( $row['gift_quantity'] ) ? (int) $row['gift_quantity'] : 1,
                        'gifts'   => array_map( 'intval', $gifts ),
                        'rule'    => [
                            'items_per_row' => isset( $row['items_per_row'] ) ? (int) $row['items_per_row'] : 4,
                            'gifts'         => $gifts,
                        ],
                    ];
                    break;
                }
            }
        }

        // Validate gift existence in allowed list
        if (
            empty( $available[ $rid ] ) ||
            empty( $available[ $rid ]['gifts'] ) ||
            ! in_array( $pid, (array) $available[ $rid ]['gifts'], true )
        ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Gift not available.', 'mh-free-gifts-for-woocommerce' ) ] );
        }

        // Enforce per-rule max count
        $max   = (int) $available[ $rid ]['allowed'];
        $count = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['wcfg_gift'] ) && (int) $item['wcfg_gift'] === $rid ) {
                $count++;
            }
        }
        if ( $count >= $max ) {
            /* translators: %d: maximum number of free gifts the customer may choose for this rule. */
            $text = _n(
                'You can only select %d gift.',
                'You can only select %d gifts.',
                $max,
                'mh-free-gifts-for-woocommerce'
            );
            $msg = sprintf( $text, $max );

            wp_send_json_error( [ 'message' => esc_html( $msg ) ] );
        }

        // Load product and basic publish/stock sanity checks
        $product = wc_get_product( $pid );
        if ( ! $product || 'publish' !== $product->get_status() ) {
            wp_send_json_error( [ 'message' => esc_html__( 'This gift is currently unavailable.', 'mh-free-gifts-for-woocommerce' ) ] );
        }
        if ( ! $product->is_in_stock() ) {
            wp_send_json_error( [ 'message' => esc_html__( 'This gift is out of stock.', 'mh-free-gifts-for-woocommerce' ) ] );
        }

        // Unique key prevents merging of identical gift items
        $uid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'gift_', true );
        $gift_meta = [
            'wcfg_gift'     => $rid,
            'wcfg_gift_uid' => $uid,
        ];

        if ( $product instanceof WC_Product_Variation ) {
            $cart_key = WC()->cart->add_to_cart(
                $product->get_parent_id(),
                1,
                $pid,
                $product->get_variation_attributes(),
                $gift_meta
            );
        } else {
            $cart_key = WC()->cart->add_to_cart(
                $pid,
                1,
                0,
                [],
                $gift_meta
            );
        }

        if ( ! $cart_key ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Could not add gift.', 'mh-free-gifts-for-woocommerce' ) ] );
        }

        wp_send_json_success();
    }


    /**
     * AJAX handler: remove a gift
     */
    public function ajax_remove_gift() {
        check_ajax_referer( 'wcfg_frontend_nonce', 'nonce' );

        $item_key = isset( $_POST['item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['item_key'] ) ) : '';

        if ( '' === $item_key ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Missing parameters.', 'mh-free-gifts-for-woocommerce' ) ] );
        }

        $removed = WC()->cart->remove_cart_item( $item_key );
        if ( $removed ) {
            wp_send_json_success();
        }

        wp_send_json_error( [ 'message' => esc_html__( 'Could not remove gift.', 'mh-free-gifts-for-woocommerce' ) ] );
    }
}

// Initialize frontend
WCFG_Frontend::instance();
