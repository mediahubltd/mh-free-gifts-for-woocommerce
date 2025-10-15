<?php
/**
 * Frontend display and AJAX handlers for Free Gifts for WooCommerce
 *
 * @package MH_Free_Gifts_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MHFGFWC_Frontend {
	private static $instance;

	/**
	 * Singleton accessor
	 *
	 * @return MHFGFWC_Frontend
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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Gift selectors
		add_action( 'woocommerce_after_cart_table', array( $this, 'display_cart_gifts' ), 20 );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'display_checkout_toggle' ), 20 );

		// Pricing + qty hardening
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_free_gift_prices' ), 20 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'filter_gift_quantity_field' ), 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_gift_quantity_update' ), 10, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'force_gift_qty_one' ), 5 );

		// Surface “Free gift” in line item data (cart/checkout/emails)
		add_filter( 'woocommerce_get_item_data', array( $this, 'render_gift_badge_item_data' ), 10, 2 );

		// AJAX
		add_action( 'wc_ajax_mhfgfwc_add_gift', array( $this, 'ajax_add_gift' ) );
		add_action( 'wc_ajax_nopriv_mhfgfwc_add_gift', array( $this, 'ajax_add_gift' ) );
		add_action( 'wc_ajax_mhfgfwc_remove_gift', array( $this, 'ajax_remove_gift' ) );
		add_action( 'wc_ajax_nopriv_mhfgfwc_remove_gift', array( $this, 'ajax_remove_gift' ) );
        // AJAX (Blocks render)
        add_action( 'wp_ajax_mhfgfwc_render_gifts',      [ $this, 'ajax_render_gifts' ] );
        add_action( 'wp_ajax_nopriv_mhfgfwc_render_gifts', [ $this, 'ajax_render_gifts' ] );
	}

    /**
     * Enqueue CSS/JS for both classic and block-based cart/checkout.
     */
    public function enqueue_assets() {
        // --- Shared CSS ---
        $css_path = MHFGFWC_PLUGIN_DIR . 'assets/css/frontend.css';
        wp_enqueue_style(
            'mhfgfwc-frontend',
            MHFGFWC_PLUGIN_URL . 'assets/css/frontend.css',
            [ 'dashicons' ],
            file_exists( $css_path ) ? filemtime( $css_path ) : MHFGFWC_VERSION
        );
        
        // Dynamic button CSS from settings
        $opt = get_option( 'mhfgfwc_button_styles', [] );
        $opt = wp_parse_args( is_array( $opt ) ? $opt : [], [
            'text_color'   => '#ffffff',
            'bg_color'     => '#0071a1',
            'border_color' => '#0071a1',
            'border_size'  => 2,
            'radius'       => 25,
        ] );

        $css = sprintf(
            '.mhfgfwc-btn, .mhfgfwc-gift-item .mhfgfwc-add-gift, .mhfgfwc-gift-item .mhfgfwc-remove-gift{' .
                'color:%1$s;background:%2$s;border:%3$dpx solid %4$s;border-radius:%5$dpx;' .
            '}' .
            '.mhfgfwc-gift-item .mhfgfwc-add-gift:disabled, .mhfgfwc-gift-item .mhfgfwc-remove-gift:disabled{' .
                'background:#ccc;border-color:#ccc;color:#666;' .
            '}',
            esc_html( $opt['text_color'] ),
            esc_html( $opt['bg_color'] ),
            (int) $opt['border_size'],
            esc_html( $opt['border_color'] ),
            (int) $opt['radius']
        );
        wp_add_inline_style( 'mhfgfwc-frontend', $css );




        // --- Shared Classic JS (safe everywhere) ---
        $fe_path = MHFGFWC_PLUGIN_DIR . 'assets/js/frontend.js';
        wp_enqueue_script(
            'mhfgfwc-frontend',
            MHFGFWC_PLUGIN_URL . 'assets/js/frontend.js',
            [ 'jquery' ],
            file_exists( $fe_path ) ? filemtime( $fe_path ) : MHFGFWC_VERSION,
            true
        );

        wp_localize_script( 'mhfgfwc-frontend', 'mhfgfwcFrontend', [
            'ajax_url_add'    => WC_AJAX::get_endpoint( 'mhfgfwc_add_gift' ),
            'ajax_url_remove' => WC_AJAX::get_endpoint( 'mhfgfwc_remove_gift' ),
            'nonce'           => wp_create_nonce( 'mhfgfwc_frontend_nonce' ),
            'i18n' => [
                'toggle'     => __( 'Free Gift', 'mh-free-gifts-for-woocommerce' ),
                'add'        => __( 'Add Gift', 'mh-free-gifts-for-woocommerce' ),
                'adding'     => __( 'Adding…', 'mh-free-gifts-for-woocommerce' ),
                'remove'     => __( 'Remove Gift', 'mh-free-gifts-for-woocommerce' ),
                'removing'   => __( 'Removing…', 'mh-free-gifts-for-woocommerce' ),
                'ajax_error' => __( 'AJAX error. Please try again.', 'mh-free-gifts-for-woocommerce' ),
            ],
        ] );

        // --- Conditionally enqueue Block support (only if block templates present) ---
        global $post;
        $is_block_cart    = $post && function_exists( 'has_block' ) && has_block( 'woocommerce/cart', $post );
        $is_block_checkout= $post && function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $post );

        $should_enqueue_blocks = ( $is_block_cart || $is_block_checkout || ( ( is_cart() || is_checkout() ) && $theme_supports_blocks ) );

        if ( $should_enqueue_blocks ) {
            $blocks_path = MHFGFWC_PLUGIN_DIR . 'assets/js/blocks.js';
            $deps = [];

            // Only register WP dependencies if they actually exist (prevents 500s)
            if ( wp_script_is( 'wp-element', 'registered' ) ) { $deps[] = 'wp-element'; }
            if ( wp_script_is( 'wp-data', 'registered' ) ) { $deps[] = 'wp-data'; }
            if ( empty( $deps ) ) { $deps[] = 'jquery'; } // fallback

            wp_enqueue_script(
                'mhfgfwc-blocks',
                MHFGFWC_PLUGIN_URL . 'assets/js/blocks.js',
                $deps,
                file_exists( $blocks_path ) ? filemtime( $blocks_path ) : MHFGFWC_VERSION,
                true
            );

            wp_localize_script( 'mhfgfwc-blocks', 'mhfgfwcBlocks', [
              'context'      => $is_block_cart ? 'cart' : 'checkout',
              'mountId'      => 'mhfgfwc-blocks-slot',
              'mountTitle'   => __( 'Choose Your Free Gift', 'mh-free-gifts-for-woocommerce' ),
              'renderUrl'    => admin_url( 'admin-ajax.php?action=mhfgfwc_render_gifts' ),
              'addUrl'       => WC_AJAX::get_endpoint( 'mhfgfwc_add_gift' ),
              'removeUrl'    => WC_AJAX::get_endpoint( 'mhfgfwc_remove_gift' ),
              'i18nFreeGift' => __( 'Free gift', 'mh-free-gifts-for-woocommerce' ),
              'nonce'        => wp_create_nonce( 'mhfgfwc_frontend_nonce' ),
            ]);



        }
    }


    /**
     * Disable quantity field for free gifts in the cart.
     */
    public function filter_gift_quantity_field( $product_quantity, $cart_item_key, $cart_item ) {
        if ( ! empty( $cart_item['mhfgfwc_gift'] ) ) {
            // Replace with static label instead of input field.
            $product_quantity = '<span class="mhfgfwc-qty-disabled">1</span>';
        }
        return $product_quantity;
    }



	/**
	 * Block cart updates which try to set gift qty > 1.
	 */
	public function validate_gift_quantity_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! empty( $values['mhfgfwc_gift'] ) && $quantity > 1 ) {
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
			if ( ! empty( $item['mhfgfwc_gift'] ) && (int) $item['quantity'] !== 1 ) {
				// false = don’t trigger recalculation loops
				WC()->cart->set_quantity( $key, 1, false );
			}
		}
	}

	/**
	 * Add a small “Free gift” badge under the line item name.
	 */
	public function render_gift_badge_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['mhfgfwc_gift'] ) ) {
			$item_data[] = array(
				'name'    => '',
				'value'   => '<span class="mhfgfwc-badge">' . esc_html__( 'Free gift', 'mh-free-gifts-for-woocommerce' ) . '</span>',
				'display' => '',
			);
		}
		return $item_data;
	}

	/**
	 * Render “Choose Your Free Gift” as a coupon-style toggle on checkout
	 * (Uses session-provided map. If missing, we simply don’t show the UI.)
	 */
	public function display_checkout_toggle() {
		$session_key = apply_filters( 'mhfgfwc_session_key', 'mhfgfwc_available_gifts' );
		$available   = WC()->session->get( $session_key, array() );
		if ( empty( $available ) || ! is_array( $available ) ) {
			return;
		}

		$gift_cart = $this->map_gifts_in_cart();

		$counts = array();
		foreach ( $gift_cart as $rid => $products ) {
			$counts[ $rid ] = count( $products );
		}

		echo '<div class="woocommerce-form-coupon-toggle mhfgfwc-toggle">';
		echo '<a href="#" class="mhfgfwc-show-gifts-toggle">' . esc_html__( 'Free Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
		echo '</div>';

		echo '<div class="mhfgfwc-gift-section mhfgfwc-hidden">';

		foreach ( $available as $rule_id => $data ) {
			$rule_id     = (int) $rule_id;
			$max_allowed = isset( $data['allowed'] ) ? (int) $data['allowed'] : 0;
			$have        = isset( $counts[ $rule_id ] ) ? (int) $counts[ $rule_id ] : 0;
			$disabled    = ( $max_allowed > 0 && $have >= $max_allowed );
			$rule_class  = $disabled ? ' mhfgfwc-disabled-rule' : '';

			$items_per_row = isset( $data['rule']['items_per_row'] ) ? (int) $data['rule']['items_per_row'] : 4;
			$cols_class    = ' mhfgfwc-cols-' . max( 1, min( 6, $items_per_row ) );

			echo '<div class="mhfgfwc-rule-group' . esc_attr( $rule_class . $cols_class ) . '" data-rule="' . esc_attr( $rule_id ) . '">';

			$raw_gifts = isset( $data['rule']['gifts'] ) ? $data['rule']['gifts'] : array();
			$gifts     = array_filter( (array) maybe_unserialize( $raw_gifts ), 'is_numeric' );

			echo '<div class="mhfgfwc-grid">';

			foreach ( $gifts as $prod_id ) {
				$prod_id = (int) $prod_id;
				$product = wc_get_product( $prod_id );
				if ( ! $product || 'publish' !== $product->get_status() ) {
					continue;
				}

				echo '<div class="mhfgfwc-gift-item">';
				echo   '<div class="mhfgfwc-thumb">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '</div>';
				echo   '<div class="mhfgfwc-title">' . esc_html( $product->get_name() ) . '</div>';

				if ( isset( $gift_cart[ $rule_id ][ $prod_id ] ) ) {
					$item_key = (string) $gift_cart[ $rule_id ][ $prod_id ];
					echo '<a href="#" class="mhfgfwc-remove-gift" data-item-key="' . esc_attr( $item_key ) . '">' . esc_html__( 'Remove Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
				} elseif ( ! $disabled ) {
					echo '<a href="#" class="mhfgfwc-add-gift" data-rule="' . esc_attr( $rule_id ) . '" data-product="' . esc_attr( $prod_id ) . '">' . esc_html__( 'Add Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
				}

				echo '</div>'; // .mhfgfwc-gift-item
			}

			echo '</div></div>'; // .mhfgfwc-grid, .mhfgfwc-rule-group
		}

		echo '</div>'; // .mhfgfwc-gift-section
	}

	/**
	 * Render the gift selector grid on cart & checkout (cart area)
	 */
	public function display_cart_gifts() {
        $session_key = apply_filters( 'mhfgfwc_session_key', 'mhfgfwc_available_gifts' );
		$available   = WC()->session->get( $session_key, array() );
		if ( ! is_array( $available ) || empty( $available ) ) {
			return;
		}

		$gift_cart = $this->map_gifts_in_cart();

		echo '<div class="mhfgfwc-gift-header">' . esc_html__( 'Choose Your Free Gift', 'mh-free-gifts-for-woocommerce' ) . '</div>';
		echo '<div class="mhfgfwc-gift-selector">';

		foreach ( $available as $rule_id => $data ) {
			$rule_id     = (int) $rule_id;
			$max_allowed = isset( $data['allowed'] ) ? (int) $data['allowed'] : 0;
			$have        = isset( $gift_cart[ $rule_id ] ) ? count( $gift_cart[ $rule_id ] ) : 0;
			$disabled    = ( $max_allowed > 0 && $have >= $max_allowed );
			$rule_class  = $disabled ? ' mhfgfwc-disabled-rule' : '';

			$items_per_row = isset( $data['rule']['items_per_row'] ) ? (int) $data['rule']['items_per_row'] : 4;
			$cols_class    = ' mhfgfwc-cols-' . max( 1, min( 6, $items_per_row ) );

			echo '<div class="mhfgfwc-rule-group' . esc_attr( $rule_class . $cols_class ) . '" data-rule="' . esc_attr( $rule_id ) . '">';

			$raw_gifts = isset( $data['rule']['gifts'] ) ? $data['rule']['gifts'] : array();
			$gifts     = array_filter( (array) maybe_unserialize( $raw_gifts ), 'is_numeric' );

			echo '<div class="mhfgfwc-grid">';

			foreach ( $gifts as $prod_id ) {
				$prod_id = (int) $prod_id;
				$product = wc_get_product( $prod_id );
				if ( ! $product || 'publish' !== $product->get_status() ) {
					continue;
				}

				echo '<div class="mhfgfwc-gift-item">';
				echo '<div class="mhfgfwc-thumb">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '</div>';
				echo '<div class="mhfgfwc-title">' . esc_html( $product->get_name() ) . '</div>';

				if ( isset( $gift_cart[ $rule_id ][ $prod_id ] ) ) {
					$item_key = (string) $gift_cart[ $rule_id ][ $prod_id ];
					echo '<a href="#" class="mhfgfwc-remove-gift" data-item-key="' . esc_attr( $item_key ) . '">' . esc_html__( 'Remove Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
				} elseif ( ! $disabled ) {
					echo '<a href="#" class="mhfgfwc-add-gift" data-rule="' . esc_attr( $rule_id ) . '" data-product="' . esc_attr( $prod_id ) . '">' . esc_html__( 'Add Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
				}

				echo '</div>';
			}

			echo '</div></div>';
		}

		echo '</div>';
	}
    
    private function ensure_available_gifts_map() {
        $session_key = apply_filters( 'mhfgfwc_session_key', 'mhfgfwc_available_gifts' );
        $map = WC()->session->get( $session_key, array() );

        // 🔁 Compare global rules revision to the one stored in session
        $global_rev  = (int) get_option( 'mhfgfwc_rules_rev', 0 );
        $session_rev = (int) WC()->session->get( 'mhfgfwc_rules_rev', 0 );

        // If we already have a map and the revision matches, keep it
        if ( ! empty( $map ) && $global_rev === $session_rev ) {
            return $map;
        }
        
        if ( ! class_exists( 'MHFGFWC_DB' ) ) {
            // Still update rev so we don’t keep rebuilding pointlessly
            WC()->session->set( 'mhfgfwc_rules_rev', $global_rev );
            return array();
        }

        // Build a minimal availability map based on active rules and current cart.
        $rules = MHFGFWC_DB::get_active_rules();
        $built = array();

        foreach ( (array) $rules as $row ) {
            $row = is_object( $row ) ? get_object_vars( $row ) : (array) $row;
            $rid = isset( $row['id'] ) ? (int) $row['id'] : 0;
            if ( ! $rid ) continue;

            $gifts = array_filter( (array) maybe_unserialize( $row['gifts'] ?? array() ), 'is_numeric' );
            if ( empty( $gifts ) ) continue;

            $built[ $rid ] = array(
                'allowed' => isset( $row['gift_quantity'] ) ? (int) $row['gift_quantity'] : 1,
                'gifts'   => array_map( 'intval', $gifts ),
                'rule'    => array(
                    'items_per_row' => isset( $row['items_per_row'] ) ? (int) $row['items_per_row'] : 4,
                    'gifts'         => $gifts,
                ),
            );
        }

        // ✅ Save both the map and the revision into the session
        WC()->session->set( $session_key, $built );
        WC()->session->set( 'mhfgfwc_rules_rev', $global_rev );
        return $built;
    }
    
    /**
     * Allowed tags/attributes for the Free Gifts markup.
     */
    private function kses_allowed_gift_markup() {
        return [
            'div'  => [
                'class'     => true,
                'data-rule' => true,
            ],
            'h3'   => [ 'class' => true ],
            'span' => [ 'class' => true ],
            'a'    => [
                'href'          => true,
                'class'         => true,
                'data-rule'     => true,
                'data-product'  => true,
                'data-item-key' => true,
                'aria-label'    => true,
            ],
            'img'  => [
                'src'    => true,
                'class'  => true,
                'alt'    => true,
                'srcset' => true,
                'sizes'  => true,
                'width'  => true,
                'height' => true,
                'loading'=> true,
                'decoding'=> true,
            ],
        ];
    }

    
    /**
     * AJAX: return the gift grid HTML (used by Cart/Checkout Blocks mount).
     */
    public function ajax_render_gifts() {
        check_ajax_referer( 'mhfgfwc_frontend_nonce', 'nonce' );

        // Make sure availability exists even if user lands directly on checkout.
        $this->ensure_available_gifts_map();
        
        // Reuse the exact same output as the classic cart section.
        ob_start();
        $this->display_cart_gifts();
        $html = ob_get_clean();

        // Compliant: escape with an explicit KSES schema so markup remains intact. (Blocks code will drop it into the slot)
	    echo wp_kses( $html, $this->kses_allowed_gift_markup() );
        wp_die();
    }


	/**
	 * Zero out gift prices
	 */
	public function apply_free_gift_prices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['mhfgfwc_gift'] ) && isset( $item['data'] ) && is_object( $item['data'] ) ) {
				$item['data']->set_price( 0 );
				if ( method_exists( $item['data'], 'set_sale_price' ) ) {
					$item['data']->set_sale_price( 0 );
				}
			}
		}
	}

	/**
	 * AJAX handler: add a gift
	 * Primary validation uses session; if missing or stale, we fall back to MHFGFWC_DB::get_active_rules()
	 */
	public function ajax_add_gift() {
		check_ajax_referer( 'mhfgfwc_frontend_nonce', 'nonce' );

		// Sanitize POST inputs (no direct $_POST use)
		$pid = filter_input( INPUT_POST, 'product', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		$rid = filter_input( INPUT_POST, 'rule', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );

		$pid = $pid ? (int) $pid : 0;
		$rid = $rid ? (int) $rid : 0;

		if ( ! $pid || ! $rid ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing parameters.', 'mh-free-gifts-for-woocommerce' ) ) );
		}

		// Build current availability map from session first
		$session_key = apply_filters( 'mhfgfwc_session_key', 'mhfgfwc_available_gifts' );
		$available   = WC()->session->get( $session_key, array() );

		// Fallback validation via DB helper if session is empty/stale
		if ( empty( $available[ $rid ] ) && class_exists( 'MHFGFWC_DB' ) ) {
			$rules = MHFGFWC_DB::get_active_rules(); // cached; no DB hit if warm
			foreach ( (array) $rules as $row ) {
				if ( is_object( $row ) ) {
					$row = get_object_vars( $row );
				}
				$rule_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
				if ( ! $rule_id ) {
					continue;
				}
				if ( $rule_id === $rid ) {
					$gifts = array_filter( (array) maybe_unserialize( $row['gifts'] ?? array() ), 'is_numeric' );
					$available[ $rule_id ] = array(
						'allowed' => isset( $row['gift_quantity'] ) ? (int) $row['gift_quantity'] : 1,
						'gifts'   => array_map( 'intval', $gifts ),
						'rule'    => array(
							'items_per_row' => isset( $row['items_per_row'] ) ? (int) $row['items_per_row'] : 4,
							'gifts'         => $gifts,
						),
					);
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
			wp_send_json_error( array( 'message' => esc_html__( 'Gift not available.', 'mh-free-gifts-for-woocommerce' ) ) );
		}

		// Enforce per-rule max count
		$max   = (int) $available[ $rid ]['allowed'];
		$count = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['mhfgfwc_gift'] ) && (int) $item['mhfgfwc_gift'] === $rid ) {
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

			wp_send_json_error( array( 'message' => esc_html( $msg ) ) );
		}

		// Load product and basic publish/stock sanity checks
		$product = wc_get_product( $pid );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This gift is currently unavailable.', 'mh-free-gifts-for-woocommerce' ) ) );
		}
		if ( ! $product->is_in_stock() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This gift is out of stock.', 'mh-free-gifts-for-woocommerce' ) ) );
		}

		// Unique key prevents merging of identical gift items
		$uid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'gift_', true );
		$gift_meta = array(
			'mhfgfwc_gift'     => $rid,
			'mhfgfwc_gift_uid' => $uid,
		);

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
				array(),
				$gift_meta
			);
		}

		if ( ! $cart_key ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not add gift.', 'mh-free-gifts-for-woocommerce' ) ) );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler: remove a gift
	 */
	public function ajax_remove_gift() {
		check_ajax_referer( 'mhfgfwc_frontend_nonce', 'nonce' );

		$item_key = isset( $_POST['item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['item_key'] ) ) : '';

		if ( '' === $item_key ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing parameters.', 'mh-free-gifts-for-woocommerce' ) ) );
		}

		$removed = WC()->cart->remove_cart_item( $item_key );
		if ( $removed ) {
			wp_send_json_success();
		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not remove gift.', 'mh-free-gifts-for-woocommerce' ) ) );
	}

	/**
	 * Build a map of existing gift items in cart: [ rule_id => [ product_id => cart_item_key ] ]
	 *
	 * @return array
	 */
	private function map_gifts_in_cart() {
		$gift_cart = array();
		foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
			if ( ! empty( $item['mhfgfwc_gift'] ) ) {
				$rid = (int) $item['mhfgfwc_gift'];
				$pid = (int) $item['product_id'];
				if ( ! isset( $gift_cart[ $rid ] ) ) {
					$gift_cart[ $rid ] = array();
				}
				$gift_cart[ $rid ][ $pid ] = $cart_item_key;
			}
		}
		return $gift_cart;
	}
}

// Initialize frontend
MHFGFWC_Frontend::instance();
