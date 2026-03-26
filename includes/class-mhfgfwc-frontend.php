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
	private $pending_pruned_gift_keys = array();
	private $pending_prune_hook_registered = false;

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
		add_action( $this->get_checkout_display_hook(), array( $this, 'display_checkout_toggle' ), 20 );

		// Pricing + qty hardening
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_free_gift_prices' ), 20 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'filter_gift_quantity_field' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'append_cart_free_gift_badge' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'hide_cart_free_gift_price' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'hide_cart_free_gift_price' ), 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_gift_quantity_update' ), 10, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'force_gift_qty_one' ), 5 );

		// Surface “Free gift” in line item data (cart/checkout/emails)
		add_filter( 'woocommerce_get_item_data', array( $this, 'render_gift_badge_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'hide_auto_added_gift_remove_link' ), 10, 2 );

		// AJAX
		add_action( 'wc_ajax_mhfgfwc_add_gift', array( $this, 'ajax_add_gift' ) );
		add_action( 'wc_ajax_nopriv_mhfgfwc_add_gift', array( $this, 'ajax_add_gift' ) );
		add_action( 'wc_ajax_mhfgfwc_remove_gift', array( $this, 'ajax_remove_gift' ) );
		add_action( 'wc_ajax_nopriv_mhfgfwc_remove_gift', array( $this, 'ajax_remove_gift' ) );

		// AJAX (Blocks render)
		add_action( 'wp_ajax_mhfgfwc_render_gifts',      [ $this, 'ajax_render_gifts' ] );
		add_action( 'wp_ajax_nopriv_mhfgfwc_render_gifts', [ $this, 'ajax_render_gifts' ] );

		// 🔹 New: provide inner renderer for refresh endpoint
		add_action( 'mhfgfwc_render_gifts_section_inner', [ $this, 'render_gifts_section_inner' ], 10, 2 );
        
		// Auto-prune ineligible gifts whenever the engine updates eligibility.
		add_action( 'mhfgfwc_after_evaluate_cart', array( $this, 'prune_ineligible_gifts' ), 10, 2 );

		// After each re-evaluation, tell WooCommerce JS to refresh fragments
		add_action( 'mhfgfwc_after_evaluate_cart', [ $this, 'inject_refresh_js' ], 20 );

		add_action( 'woocommerce_after_calculate_totals', function() {
			if ( is_checkout() ) {
				add_action( 'wp_footer', function() {
					?>
					<script>
					jQuery(function($){
						$(document.body).trigger('update_checkout');
					});
					</script>
					<?php
				}, 99 );
			}
		}, 50 );

		// AJAX endpoint to re-render the gift grid
		add_action( 'wp_ajax_mhfgfwc_refresh_gifts', 'mhfgfwc_refresh_gifts' );
		add_action( 'wp_ajax_nopriv_mhfgfwc_refresh_gifts', 'mhfgfwc_refresh_gifts' );

		// NOTE: this defines a global callback; our new render_gifts_section_inner()
		// is wired above via the mhfgfwc_render_gifts_section_inner hook.
		function mhfgfwc_refresh_gifts() {
			$context = filter_input( INPUT_POST, 'context', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			if ( ! is_string( $context ) || '' === $context ) {
				$context = filter_input( INPUT_GET, 'context', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			}

			ob_start();
			do_action( 'mhfgfwc_render_gifts_section_inner', $context, false ); // IMPORTANT: inner grid only
			$html = ob_get_clean();
			wp_send_json_success( [ 'html' => $html ] );
		}

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
		
		// Dynamic button/text CSS from settings
		$opt     = $this->get_button_styles();
		$display = $this->get_display_settings();

		$css = sprintf(
			'.mhfgfwc-btn, .mhfgfwc-gift-item .mhfgfwc-add-gift, .mhfgfwc-gift-item .mhfgfwc-remove-gift{' .
				'color:%1$s;background:%2$s;border:%3$dpx solid %4$s;border-radius:%5$dpx;font-size:%6$dpx;' .
			'}' .
			'.mhfgfwc-gift-header{font-size:%7$dpx;}' .
			'.mhfgfwc-show-gifts-toggle{font-size:%8$dpx;}' .
			'.mhfgfwc-gift-item .mhfgfwc-add-gift:disabled, .mhfgfwc-gift-item .mhfgfwc-remove-gift:disabled{' .
				'background:#ccc;border-color:#ccc;color:#666;' .
			'}',
			esc_html( $opt['text_color'] ),
			esc_html( $opt['bg_color'] ),
			(int) $opt['border_size'],
			esc_html( $opt['border_color'] ),
			(int) $opt['radius'],
			(int) $opt['font_size'],
			(int) $display['cart_heading_font_size'],
			(int) $display['checkout_toggle_font_size']
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
			// Keep WooCommerce’s proper AJAX endpoints for add/remove
			'ajax_url_add'    => WC_AJAX::get_endpoint( 'mhfgfwc_add_gift' ),
			'ajax_url_remove' => WC_AJAX::get_endpoint( 'mhfgfwc_remove_gift' ),

			// Add refresh endpoint for gift section reload
			'ajax_url_refresh'       => admin_url( 'admin-ajax.php?action=mhfgfwc_refresh_gifts' ),
			'ajax_url_refresh_gifts' => admin_url( 'admin-ajax.php?action=mhfgfwc_get_gift_section' ),
			
			// Generic admin-ajax fallback (for older hooks or other AJAX needs)
			'ajaxurl' => admin_url( 'admin-ajax.php' ),

			// Security
			'nonce' => wp_create_nonce( 'mhfgfwc_frontend_nonce' ),

			// Translations
			'i18n' => [
				'toggle'     => $display['checkout_toggle_text'],
				'add'        => $display['add_button_text'],
				'adding'     => __( 'Adding…', 'mh-free-gifts-for-woocommerce' ),
				'remove'     => $display['remove_button_text'],
				'removing'   => __( 'Removing…', 'mh-free-gifts-for-woocommerce' ),
				'added'      => __( 'Added', 'mh-free-gifts-for-woocommerce' ),
				'ajax_error' => __( 'AJAX error. Please try again.', 'mh-free-gifts-for-woocommerce' ),
			],
		] );


		// --- Conditionally enqueue Block support (only if block templates present) ---
		global $post;
		$is_block_cart     = $post && function_exists( 'has_block' ) && has_block( 'woocommerce/cart', $post );
		$is_block_checkout = $post && function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $post );
        
        $theme_supports_blocks = function_exists( 'wc_current_theme_supports_woocommerce_blocks' )
            ? wc_current_theme_supports_woocommerce_blocks()
            : false;


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
				'mountTitle'   => $display['cart_heading_text'],
				'renderUrl'    => admin_url( 'admin-ajax.php?action=mhfgfwc_render_gifts' ),
				'addUrl'       => WC_AJAX::get_endpoint( 'mhfgfwc_add_gift' ),
				'removeUrl'    => WC_AJAX::get_endpoint( 'mhfgfwc_remove_gift' ),
				'i18nFreeGift' => __( 'Free gift', 'mh-free-gifts-for-woocommerce' ),
				'nonce'        => wp_create_nonce( 'mhfgfwc_frontend_nonce' ),
			]);
		}
	}

	/**
	 * Get button-style settings with defaults.
	 *
	 * @return array
	 */
	private function get_button_styles() {
		$opt = get_option( 'mhfgfwc_button_styles', array() );
		$def = array(
			'text_color'   => '#ffffff',
			'bg_color'     => '#000000',
			'border_color' => '#000000',
			'border_size'  => 2,
			'font_size'    => 15,
			'radius'       => 25,
		);

		return wp_parse_args( is_array( $opt ) ? $opt : array(), $def );
	}

	/**
	 * Get frontend display/text settings with defaults.
	 *
	 * @return array
	 */
	private function get_display_settings() {
		$opt = get_option( 'mhfgfwc_display_settings', array() );
		$def = array(
			'checkout_hook'             => 'woocommerce_checkout_before_order_review',
			'cart_heading_text'         => __( 'Choose Your Free Gift', 'mh-free-gifts-for-woocommerce' ),
			'checkout_toggle_text'      => __( 'Free Gift', 'mh-free-gifts-for-woocommerce' ),
			'add_button_text'           => __( 'Add Gift', 'mh-free-gifts-for-woocommerce' ),
			'remove_button_text'        => __( 'Remove Gift', 'mh-free-gifts-for-woocommerce' ),
			'cart_heading_font_size'    => 15,
			'checkout_toggle_font_size' => 18,
		);
		$settings = wp_parse_args( is_array( $opt ) ? $opt : array(), $def );
		$allowed  = array(
			'woocommerce_before_checkout_form',
			'woocommerce_checkout_before_customer_details',
			'woocommerce_checkout_before_order_review',
			'woocommerce_checkout_after_order_review',
		);

		if ( ! in_array( $settings['checkout_hook'], $allowed, true ) ) {
			$settings['checkout_hook'] = $def['checkout_hook'];
		}

		return $settings;
	}

	/**
	 * Resolve the configured classic-checkout display hook.
	 *
	 * @return string
	 */
	private function get_checkout_display_hook() {
		$settings = $this->get_display_settings();
		return (string) $settings['checkout_hook'];
	}

	/**
	 * Normalize a gift-display context value.
	 *
	 * @param string $context Raw context.
	 * @return string
	 */
	private function normalize_display_context( $context ) {
		$context = sanitize_key( (string) $context );
		return 'checkout' === $context ? 'checkout' : 'cart';
	}

	/**
	 * Check whether a rule should render in the current cart/checkout context.
	 *
	 * Rules set to "Cart & Checkout" are stored as "checkout" and should render
	 * on both pages. Rules set to "Cart" should render only on cart.
	 *
	 * @param array  $rule    Rule payload.
	 * @param string $context Render context.
	 * @return bool
	 */
	private function rule_matches_display_context( array $rule, $context ) {
		$context  = $this->normalize_display_context( $context );
		$location = isset( $rule['display_location'] ) ? sanitize_key( (string) $rule['display_location'] ) : 'cart';

		if ( 'checkout' === $context ) {
			return 'checkout' === $location;
		}

		return in_array( $location, array( 'cart', 'checkout' ), true );
	}

	/**
	 * Filter eligible rules down to those that should render in the given context.
	 *
	 * @param array  $available Eligible rules keyed by rule ID.
	 * @param string $context   Render context.
	 * @return array
	 */
	private function filter_rules_for_display_context( $available, $context ) {
		$visible = array();

		foreach ( (array) $available as $rule_id => $data ) {
			$rule = isset( $data['rule'] ) && is_array( $data['rule'] ) ? $data['rule'] : array();
			if ( ! $this->rule_matches_display_context( $rule, $context ) ) {
				continue;
			}

			$visible[ $rule_id ] = $data;
		}

		return $visible;
	}

	/**
	 * Decide whether a non-eligible rule should still render as a disabled block tier.
	 *
	 * We keep user-restricted rules hidden, but allow cart-dependent/manual rules
	 * to render as greyed-out tiers in WooCommerce Blocks.
	 *
	 * @param array  $rule    Rule payload.
	 * @param string $context Render context.
	 * @return bool
	 */
	private function should_render_inactive_rule( array $rule, $context ) {
		if ( ! $this->rule_matches_display_context( $rule, $context ) ) {
			return false;
		}

		if ( ! empty( $rule['auto_add_gift'] ) ) {
			return false;
		}

		$gifts = array_values( array_filter( array_map( 'intval', (array) maybe_unserialize( $rule['gifts'] ?? array() ) ) ) );
		if ( empty( $gifts ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( ! empty( $rule['user_only'] ) && ! $user_id ) {
			return false;
		}

		$users = array_filter( (array) maybe_unserialize( $rule['user_dependency'] ?? array() ), 'is_numeric' );
		if ( $users ) {
			$users = array_map( 'intval', $users );
			if ( ! $user_id || ! in_array( $user_id, $users, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the manual gift rules to render for the current context.
	 *
	 * @param string $context          Render context.
	 * @param bool   $include_inactive Whether to append disabled inactive rules.
	 * @return array
	 */
	private function get_manual_rules_for_context( $context = 'cart', $include_inactive = false ) {
		$context     = $this->normalize_display_context( $context );
		$session_key = apply_filters( 'mhfgfwc_session_key', 'mhfgfwc_available_gifts' );
		$available   = WC()->session->get( $session_key, array() );

		if ( empty( $available ) ) {
			$available = $this->ensure_available_gifts_map();
		}

		$available = $this->get_selector_available_rules( $available );
		$available = $this->filter_rules_for_display_context( $available, $context );

		if ( ! $include_inactive || ! class_exists( 'MHFGFWC_DB' ) || ! method_exists( 'MHFGFWC_DB', 'get_active_rules' ) ) {
			return $available;
		}

		foreach ( (array) MHFGFWC_DB::get_active_rules() as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$rule_id = isset( $rule['id'] ) ? absint( $rule['id'] ) : 0;
			if ( $rule_id <= 0 || isset( $available[ $rule_id ] ) ) {
				continue;
			}

			if ( ! $this->should_render_inactive_rule( $rule, $context ) ) {
				continue;
			}

			$gifts = array_values( array_filter( array_map( 'intval', (array) maybe_unserialize( $rule['gifts'] ?? array() ) ) ) );
			if ( empty( $gifts ) ) {
				continue;
			}

			$available[ $rule_id ] = array(
				'rule'     => $rule,
				'gifts'    => $gifts,
				'allowed'  => max( 1, (int) ( $rule['gift_quantity'] ?? 1 ) ),
				'inactive' => true,
			);
		}

		return $available;
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
	 * Append a styled “Free gift” label under the product name on classic cart/checkout rows.
	 *
	 * @param string $product_name  Existing rendered product name HTML.
	 * @param array  $cart_item     Cart item payload.
	 * @param string $cart_item_key Cart item key (unused).
	 * @return string
	 */
	public function append_cart_free_gift_badge( $product_name, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );

		if ( empty( $cart_item['mhfgfwc_gift'] ) || ( ! is_cart() && ! is_checkout() ) ) {
			return $product_name;
		}

		return $product_name . '<div class="mhfgfwc-badge mhfgfwc-cart-badge">' . esc_html__( 'Free gift', 'mh-free-gifts-for-woocommerce' ) . '</div>';
	}

	/**
	 * Hide the classic cart/checkout price-subtotal output for free-gift rows.
	 *
	 * @param string $price_html    Existing WooCommerce price HTML.
	 * @param array  $cart_item     Cart item payload.
	 * @param string $cart_item_key Cart item key (unused).
	 * @return string
	 */
	public function hide_cart_free_gift_price( $price_html, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );

		if ( ! empty( $cart_item['mhfgfwc_gift'] ) && ( is_cart() || is_checkout() ) ) {
			return '';
		}

		return $price_html;
	}

	/**
	 * Add a small “Free gift” badge under the line item name.
	 */
	public function render_gift_badge_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['mhfgfwc_gift'] ) && ( is_cart() || is_checkout() ) ) {
			return $item_data;
		}

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
	 * Auto-added gifts should not expose the standard cart remove link.
	 *
	 * @param string $link          Existing remove link HTML.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function hide_auto_added_gift_remove_link( $link, $cart_item_key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $link;
		}

		$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		if ( empty( $cart_item ) || ! is_array( $cart_item ) ) {
			return $link;
		}

		if ( ! empty( $cart_item['mhfgfwc_auto_added'] ) ) {
			return '';
		}

		return $link;
	}

	/**
	 * Keep auto-added rules out of the manual gift selector UI.
	 *
	 * @param array $available Eligible rules payload.
	 * @return array
	 */
	private function get_selector_available_rules( $available ) {
		$available = is_array( $available ) ? $available : array();
		$visible   = array();

		foreach ( $available as $rule_id => $data ) {
			$rule = isset( $data['rule'] ) && is_array( $data['rule'] ) ? $data['rule'] : array();
			if ( ! empty( $rule['auto_add_gift'] ) ) {
				continue;
			}

			$visible[ $rule_id ] = $data;
		}

		return $visible;
	}

	/**
	 * Resolve the effective product ID for a cart gift item.
	 * Variation gifts must be tracked by variation ID, not parent product ID.
	 *
	 * @param array $item Cart item payload.
	 * @return int
	 */
	private function get_gift_cart_product_id( $item ) {
		$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
		if ( $variation_id > 0 ) {
			return $variation_id;
		}

		return isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
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
	 * Count free-gift line items for a single rule in the mapped gift cart array.
	 *
	 * @param array $gift_cart Gift cart map.
	 * @param int   $rule_id   Rule ID.
	 * @return int
	 */
	private function count_rule_gifts_in_map( $gift_cart, $rule_id ) {
		$count = 0;

		if ( empty( $gift_cart[ $rule_id ] ) || ! is_array( $gift_cart[ $rule_id ] ) ) {
			return 0;
		}

		foreach ( $gift_cart[ $rule_id ] as $item_keys ) {
			$count += count( (array) $item_keys );
		}

		return $count;
	}

	/**
	 * Flatten a mapped gift cart entry to a list of cart item keys.
	 *
	 * @param array $products_map Product ID => cart item keys[].
	 * @return array
	 */
	private function flatten_gift_cart_keys( $products_map ) {
		$keys = array();

		foreach ( (array) $products_map as $item_keys ) {
			foreach ( (array) $item_keys as $item_key ) {
				$item_key = (string) $item_key;
				if ( '' !== $item_key ) {
					$keys[] = $item_key;
				}
			}
		}

		return $keys;
	}

	/**
	 * Resolve the currently locked rule when accumulation is disabled.
	 * The first rule with retained gift selections wins, which preserves user choice.
	 *
	 * @param array $gift_cart     Gift cart map.
	 * @param array $ignored_keys  Cart item keys already scheduled for removal.
	 * @return int
	 */
	private function get_locked_rule_id_for_non_accumulation( $gift_cart, $ignored_keys = array() ) {
		foreach ( (array) $gift_cart as $rule_id => $products_map ) {
			foreach ( $this->flatten_gift_cart_keys( $products_map ) as $cart_item_key ) {
				if ( empty( $ignored_keys[ $cart_item_key ] ) ) {
					return (int) $rule_id;
				}
			}
		}

		return 0;
	}

	/**
	 * 🔹 NEW: shared inner renderer for rule groups & gift items
	 * This is used by:
	 * - display_checkout_toggle()
	 * - display_cart_gifts()
	 * - mhfgfwc_refresh_gifts AJAX (via mhfgfwc_render_gifts_section_inner)
	 */
	public function render_gifts_section_inner( $context = 'cart', $include_inactive = false ) {
        $available = $this->get_manual_rules_for_context( $context, (bool) $include_inactive );

        if ( empty( $available ) || ! is_array( $available ) ) {
            return;
        }

        $texts = $this->get_display_settings();
        $gift_cart = $this->map_gifts_in_cart();
        $allow_accumulation = $this->allow_gift_accumulation();
        $locked_rule_id     = $allow_accumulation ? 0 : $this->get_locked_rule_id_for_non_accumulation( $gift_cart );

        /**
         * Determine max columns across all visible rules.
         * This preserves existing items_per_row behaviour
         * but applies it once at grid level.
         */
        $max_cols = 1;
        foreach ( $available as $data ) {
            if ( ! empty( $data['rule']['items_per_row'] ) ) {
                $max_cols = max(
                    $max_cols,
                    (int) $data['rule']['items_per_row']
                );
            }
        }
        $max_cols   = max( 1, min( 6, $max_cols ) );
        $cols_class = ' mhfgfwc-cols-' . $max_cols;

        // 🔹 ONE shared grid wrapper
        echo '<div class="mhfgfwc-gift-selector' . esc_attr( $cols_class ) . '">';
        echo '<div class="mhfgfwc-grid">';

        foreach ( $available as $rule_id => $data ) {

            $rule_id     = (int) $rule_id;
            $inactive    = ! empty( $data['inactive'] );
            $max_allowed = isset( $data['allowed'] ) ? (int) $data['allowed'] : 0;
            $have        = $this->count_rule_gifts_in_map( $gift_cart, $rule_id );
            $rule_locked = ( ! $allow_accumulation && $locked_rule_id > 0 && $locked_rule_id !== $rule_id );
            $disabled    = $inactive || $rule_locked || ( $max_allowed > 0 && $have >= $max_allowed );
            $rule_class  = $disabled ? ' mhfgfwc-disabled-rule' : '';

            $raw_gifts = isset( $data['rule']['gifts'] ) ? $data['rule']['gifts'] : array();
            $gifts     = array_filter( (array) maybe_unserialize( $raw_gifts ), 'is_numeric' );

	            foreach ( $gifts as $prod_id ) {

	                $prod_id = (int) $prod_id;
	                $product = wc_get_product( $prod_id );

	                if ( ! $product || 'publish' !== $product->get_status() ) {
	                    continue;
	                }

	                $selected_keys  = isset( $gift_cart[ $rule_id ][ $prod_id ] ) ? array_values( (array) $gift_cart[ $rule_id ][ $prod_id ] ) : array();
	                $selected_count = count( $selected_keys );
	                $can_add_more   = ! $disabled;

	                echo '<div class="mhfgfwc-gift-item' . esc_attr( $rule_class ) . '" data-rule="' . esc_attr( $rule_id ) . '">';

                echo '<div class="mhfgfwc-thumb">' .
                        wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) .
                     '</div>';

                echo '<div class="mhfgfwc-title">' .
                        esc_html( $product->get_name() ) .
                     '</div>';

	                if ( $selected_count > 0 ) {
					/* translators: %d: number of selected free-gift copies for this product. */
					echo '<div class="mhfgfwc-selected-count">' . esc_html( sprintf( _n( 'Selected: %d', 'Selected: %d', $selected_count, 'mh-free-gifts-for-woocommerce' ), $selected_count ) ) . '</div>';

					foreach ( $selected_keys as $item_key ) {
	                    echo '<a href="#" class="mhfgfwc-remove-gift" data-item-key="' .
	                            esc_attr( (string) $item_key ) .
	                         '">' .
	                            esc_html( $texts['remove_button_text'] ) .
	                         '</a>';
					}
                }

                if ( $can_add_more ) {

                    echo '<a href="#" class="mhfgfwc-add-gift" data-rule="' .
                            esc_attr( $rule_id ) .
                            '" data-product="' .
                            esc_attr( $prod_id ) .
                         '">' .
                            esc_html( $texts['add_button_text'] ) .
                         '</a>';
                }

                echo '</div>'; // .mhfgfwc-gift-item
            }
        }

        echo '</div></div>'; // .mhfgfwc-grid, .mhfgfwc-gift-selector
    }


	/**
	 * Render “Choose Your Free Gift” as a coupon-style toggle on checkout
	 * (Uses session-provided map. If missing, we simply don’t show the UI.)
	 */
	public function display_checkout_toggle() {
		$texts = $this->get_display_settings();
		$available   = $this->get_manual_rules_for_context( 'checkout', false );
		if ( empty( $available ) || ! is_array( $available ) ) {
			return;
		}

		echo '<div class="woocommerce-form-coupon-toggle mhfgfwc-toggle">';
		echo '<a href="#" class="mhfgfwc-show-gifts-toggle">' . esc_html( $texts['checkout_toggle_text'] ) . '</a>';
		echo '</div>';

		echo '<div class="mhfgfwc-gift-section mhfgfwc-hidden">';

		// 🔹 Use shared inner renderer
		$this->render_gifts_section_inner( 'checkout', false );

		echo '</div>'; // .mhfgfwc-gift-section
	}

	/**
	 * Render the gift selector grid on cart & checkout (cart area)
	 */
	public function display_cart_gifts() {
		$texts = $this->get_display_settings();
		$available   = $this->get_manual_rules_for_context( 'cart', false );
		if ( ! is_array( $available ) || empty( $available ) ) {
			return;
		}

		echo '<div class="mhfgfwc-gift-header">' . esc_html( $texts['cart_heading_text'] ) . '</div>';
		echo '<div class="mhfgfwc-gift-selector">';

		// 🔹 Use shared inner renderer
		$this->render_gifts_section_inner( 'cart', false );

		echo '</div>';
	}
    
	private function ensure_available_gifts_map() {
		$session_key = apply_filters( 'mhfgfwc_session_key', 'mhfgfwc_available_gifts' );
		$map = WC()->session->get( $session_key, array() );

		// 🔁 Compare global rules revision to the one stored in session
		$global_rev      = (int) get_option( 'mhfgfwc_rules_rev', 0 );
		$session_rev     = (int) WC()->session->get( 'mhfgfwc_rules_rev', 0 );
		$current_user_id = (int) get_current_user_id();
		$session_user_id = (int) WC()->session->get( 'mhfgfwc_rules_user_id', -1 );

		// If we already have a map and both the revision and current user match, keep it.
		if ( ! empty( $map ) && $global_rev === $session_rev && $current_user_id === $session_user_id ) {
			return $map;
		}

		if ( class_exists( 'MHFGFWC_Engine' ) ) {
			$engine = MHFGFWC_Engine::instance();
			if ( $engine && method_exists( $engine, 'evaluate_cart_now' ) ) {
				$engine->evaluate_cart_now();
				$map = WC()->session->get( $session_key, array() );
				if ( is_array( $map ) ) {
					return $map;
				}
			}
		}

		WC()->session->set( 'mhfgfwc_rules_rev', $global_rev );
		WC()->session->set( 'mhfgfwc_rules_user_id', $current_user_id );
		WC()->session->set( $session_key, array() );
		return array();
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
	 * Build a map of existing gift items in cart: [ rule_id => [ product_id => cart_item_key[] ] ]
	 *
	 * @return array
	 */
	private function map_gifts_in_cart() {
		$gift_cart = array();
		foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
			if ( ! empty( $item['mhfgfwc_gift'] ) ) {
				$rid = (int) $item['mhfgfwc_gift'];
				$pid = $this->get_gift_cart_product_id( $item );
				if ( ! isset( $gift_cart[ $rid ] ) ) {
					$gift_cart[ $rid ] = array();
				}
				if ( $pid > 0 ) {
					if ( ! isset( $gift_cart[ $rid ][ $pid ] ) ) {
						$gift_cart[ $rid ][ $pid ] = array();
					}
					$gift_cart[ $rid ][ $pid ][] = $cart_item_key;
				}
			}
		}
		return $gift_cart;
	}
    
	/**
	 * Schedule and safely remove ineligible gifts after WooCommerce finishes recalculating totals.
	 *
	 * @param array $eligible Eligible payload keyed by rule_id.
	 * @param int   $user_id  Current user ID (unused).
	 */
	public function prune_ineligible_gifts( $eligible, $user_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$eligible = is_array( $eligible ) ? $eligible : array();
		$gift_cart = $this->map_gifts_in_cart();
		$allow_accumulation = $this->allow_gift_accumulation();

		// Collect cart item keys to remove after WooCommerce finishes this totals pass.
		$to_remove = array();

		foreach ( $gift_cart as $rule_id => $products_map ) {
			// Entire rule invalid
			if ( empty( $eligible[ $rule_id ] ) || empty( $eligible[ $rule_id ]['gifts'] ) ) {
				foreach ( $products_map as $item_keys ) {
					foreach ( (array) $item_keys as $cart_item_key ) {
						$to_remove[ $cart_item_key ] = true;
					}
				}
				continue;
			}

			$allowed        = max( 1, (int) ( $eligible[ $rule_id ]['allowed'] ?? 1 ) );
			$valid_products = array_map( 'intval', (array) $eligible[ $rule_id ]['gifts'] );

			// Invalid product gifts
			foreach ( $products_map as $pid => $item_keys ) {
				if ( ! in_array( (int) $pid, $valid_products, true ) ) {
					foreach ( (array) $item_keys as $cart_item_key ) {
						$to_remove[ $cart_item_key ] = true;
					}
					unset( $products_map[ $pid ] );
				}
			}

			// Over-limit cleanup
			$rule_gift_keys = $this->flatten_gift_cart_keys( $products_map );
			if ( count( $rule_gift_keys ) > $allowed ) {
				$excess = array_slice( $rule_gift_keys, $allowed );
				foreach ( $excess as $cart_item_key ) {
					$to_remove[ $cart_item_key ] = true;
				}
			}
		}

		if ( ! $allow_accumulation ) {
			$locked_rule_id = $this->get_locked_rule_id_for_non_accumulation( $gift_cart, $to_remove );

			if ( $locked_rule_id > 0 ) {
				foreach ( $gift_cart as $rule_id => $products_map ) {
					if ( (int) $rule_id === $locked_rule_id ) {
						continue;
					}

					foreach ( $this->flatten_gift_cart_keys( $products_map ) as $cart_item_key ) {
						$to_remove[ $cart_item_key ] = true;
					}
				}
			}
		}

		// Nothing to remove? Bail.
		if ( empty( $to_remove ) ) {
			return;
		}

		$this->queue_pruned_gift_removals( $to_remove );
	}

	/**
	 * Queue gift removals so they run after the current totals calculation finishes.
	 *
	 * @param array $to_remove Cart item keys keyed to true.
	 * @return void
	 */
	private function queue_pruned_gift_removals( $to_remove ) {
		foreach ( (array) $to_remove as $cart_item_key => $_ ) {
			$cart_item_key = (string) $cart_item_key;
			if ( '' !== $cart_item_key ) {
				$this->pending_pruned_gift_keys[ $cart_item_key ] = true;
			}
		}

		if ( $this->pending_prune_hook_registered ) {
			return;
		}

		$this->pending_prune_hook_registered = true;

		add_action( 'woocommerce_after_calculate_totals', function() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				$this->pending_prune_hook_registered = false;
				return;
			}

			static $running = false;
			if ( $running ) {
				return;
			}

			$cart_item_keys = array_keys( $this->pending_pruned_gift_keys );
			if ( empty( $cart_item_keys ) ) {
				$this->pending_prune_hook_registered = false;
				return;
			}

			$running = true;

			foreach ( $cart_item_keys as $cart_item_key ) {
				if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
					WC()->cart->remove_cart_item( $cart_item_key );
				}
			}

			$this->pending_pruned_gift_keys    = array();
			$this->pending_prune_hook_registered = false;
			$running = false;
		}, 999 );
	}

	public function inject_refresh_js() {
		if ( is_cart() || is_checkout() ) {
			add_action( 'wp_footer', function() {
				?>
				<script>
				document.addEventListener('DOMContentLoaded',function(){
					if (typeof jQuery!=='undefined') {
						var $=jQuery;
						// refresh mini-cart fragments
						if (typeof wc_cart_fragments_params!=='undefined'){
							$(document.body).trigger('wc_fragment_refresh');
						}
						// refresh totals on checkout page
						if ($('form.woocommerce-checkout').length){
							$(document.body).trigger('update_checkout');
						}
					}
				});
				</script>
				<?php
			}, 99 );
		}
	}
    
	/**
	 * AJAX: return the gift grid HTML (used by Cart/Checkout Blocks mount).
	 */
	public function ajax_render_gifts() {
		check_ajax_referer( 'mhfgfwc_frontend_nonce', 'nonce' );

		$context = filter_input( INPUT_GET, 'context', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! is_string( $context ) || '' === $context ) {
			$context = filter_input( INPUT_POST, 'context', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		}
		$context = $this->normalize_display_context( $context );

		// Make sure availability exists even if user lands directly on checkout.
		$this->ensure_available_gifts_map();
		
		// Blocks use their own panel placement, so render the shared heading + grid
		// while appending inactive manual tiers as disabled cards.
		ob_start();
		$texts     = $this->get_display_settings();
		$available = $this->get_manual_rules_for_context( $context, true );
		if ( ! empty( $available ) && is_array( $available ) ) {
			echo '<div class="mhfgfwc-gift-header">' . esc_html( $texts['cart_heading_text'] ) . '</div>';
			echo '<div class="mhfgfwc-gift-selector">';
			$this->render_gifts_section_inner( $context, true );
			echo '</div>';
		}
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

		// Fallback validation via a fresh engine evaluation if session is empty/stale.
		if ( empty( $available[ $rid ] ) ) {
			$available = $this->ensure_available_gifts_map();
		}

		// Validate gift existence in allowed list
		if (
			empty( $available[ $rid ] ) ||
			empty( $available[ $rid ]['gifts'] ) ||
			! in_array( $pid, (array) $available[ $rid ]['gifts'], true )
		) {
			wp_send_json_error( array( 'message' => esc_html__( 'Gift not available.', 'mh-free-gifts-for-woocommerce' ) ) );
		}

		if ( ! empty( $available[ $rid ]['rule']['auto_add_gift'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This gift is added automatically when the rule is matched.', 'mh-free-gifts-for-woocommerce' ) ) );
		}

		if ( ! $this->allow_gift_accumulation() ) {
			$locked_rule_id = $this->get_locked_rule_id_for_non_accumulation( $this->map_gifts_in_cart() );
			if ( $locked_rule_id > 0 && $locked_rule_id !== $rid ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Only gifts from one eligible rule can be added per order.', 'mh-free-gifts-for-woocommerce' ) ) );
			}
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
        
		//error_log( "MHFGFWC: add_gift pid=$pid rid=$rid session=" . print_r( WC()->session->get( 'mhfgfwc_available_gifts' ), true ) );

		if ( $product instanceof WC_Product_Variation ) {
			$cart_key = WC()->cart->add_to_cart(
				$product->get_parent_id(),
				1,
				$pid,
				$product->get_variation_attributes(),
				$gift_meta
			);
			//error_log( "MHFGFWC Variations: cart_key=" . var_export( $cart_key, true ) );
		} else {
			$cart_key = WC()->cart->add_to_cart(
				$pid,
				1,
				0,
				array(),
				$gift_meta
			);
			//error_log( "MHFGFWC: cart_key=" . var_export( $cart_key, true ) );
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

	
}

// Initialize frontend
MHFGFWC_Frontend::instance();
