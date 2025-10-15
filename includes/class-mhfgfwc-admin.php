<?php
/**
 * Admin UI for MH Free Gifts for WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MHFGFWC_Admin {
    private static $instance;

    /**
     * Store our exact screen hooks so we can scope enqueues precisely.
     * @var string
     */
    private $menu_hook = '';
    private $submenu_hook = '';
    private $settings_hook = '';

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_settings_assets' ] );

        // Mutations
        add_action( 'admin_post_mhfgfwc_save_rule',   [ $this, 'save_rule' ] );
        add_action( 'admin_post_mhfgfwc_delete_rule', [ $this, 'delete_rule' ] );

        // AJAX
        add_action( 'wp_ajax_mhfgfwc_search_products', [ $this, 'ajax_search_products' ] );
        add_action( 'wp_ajax_mhfgfwc_toggle_status',   [ $this, 'ajax_toggle_status' ] );
        add_action( 'wp_ajax_mhfgfwc_search_users',    [ $this, 'ajax_search_users' ] );
    }

    public function register_menu() {
        $this->menu_hook = add_menu_page(
            __( 'MH Free Gifts for WooCommerce', 'mh-free-gifts-for-woocommerce' ),
            __( 'Free Gifts', 'mh-free-gifts-for-woocommerce' ),
            'manage_options',
            'mhfgfwc_rules',
            [ $this, 'render_rules_list' ],
            'dashicons-cart', 
            56
        );

        $this->submenu_hook = add_submenu_page(
            'mhfgfwc_rules',
            __( 'Add New Rule', 'mh-free-gifts-for-woocommerce' ),
            __( 'Add Rule', 'mh-free-gifts-for-woocommerce' ),
            'manage_options',
            'mhfgfwc_add_rule',
            [ $this, 'render_rule_form' ]
        );
        
        $this->settings_hook = add_submenu_page(
            'mhfgfwc_rules',
            __( 'Free Gifts Settings', 'mh-free-gifts-for-woocommerce' ),
            __( 'Settings', 'mh-free-gifts-for-woocommerce' ),
            'manage_options',
            'mhfgfwc_settings',
            [ $this, 'render_settings_page' ]
        );

    }

    /**
     * Only enqueue on our plugin screens.
     */
    public function enqueue_assets( $hook ) {
        // Bail if not one of our pages.
        if ( $hook !== $this->menu_hook && $hook !== $this->submenu_hook && $hook !== $this->settings_hook ) {
            return;
        }

        // ---------------------------
        // Register vendor dependencies
        // ---------------------------
        // Core jQuery UI (registered by WP)
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_script( 'jquery-ui-slider' );

        // Register jQuery UI theme CSS used by our date/time picker.
        wp_register_style(
            'mhfgfwc-jquery-ui-theme',
            MHFGFWC_PLUGIN_URL . 'assets/css/jquery-ui.css',
            [],
            '1.12.1'
        );

        // Timepicker addon (CSS + JS)
        wp_register_style(
            'mhfgfwc-timepicker',
            MHFGFWC_PLUGIN_URL . 'assets/css/jquery-ui-timepicker-addon.css',
            [ 'mhfgfwc-jquery-ui-theme' ],
            '1.6.3'
        );
        wp_register_script(
            'mhfgfwc-timepicker',
            MHFGFWC_PLUGIN_URL . 'assets/js/jquery-ui-timepicker-addon.js',
            [ 'jquery-ui-datepicker', 'jquery-ui-slider' ],
            '1.6.3',
            true
        );

        // --- Ensure WooCommerce SelectWoo is available (script + style) ---
        $wc_url = function_exists( 'WC' ) && is_object( WC() ) ? WC()->plugin_url() : plugins_url( 'woocommerce' );

        // Script: selectWoo
        if ( ! wp_script_is( 'selectWoo', 'registered' ) && ! wp_script_is( 'selectWoo', 'enqueued' ) ) {
            // WooCommerce path: /assets/js/selectWoo/selectWoo.full.min.js
            wp_register_script(
                'selectWoo',
                trailingslashit( $wc_url ) . 'assets/js/selectWoo/selectWoo.full.min.js',
                [ 'jquery' ],
                '1.0.8',
                true
            );
        }
        wp_enqueue_script( 'selectWoo' );

        // Style: select2
        if ( ! wp_style_is( 'select2', 'registered' ) && ! wp_style_is( 'select2', 'enqueued' ) ) {
            // WooCommerce path: /assets/css/select2.css
            wp_register_style(
                'select2',
                trailingslashit( $wc_url ) . 'assets/css/select2.css',
                [],
                '4.0.3'
            );
        }
        wp_enqueue_style( 'select2' );


        // ---------------------------
        // Register our admin assets
        // ---------------------------
        wp_register_style(
            'mhfgfwc-admin',
            MHFGFWC_PLUGIN_URL . 'assets/css/admin.css',
            [ 'mhfgfwc-jquery-ui-theme', 'mhfgfwc-timepicker', 'select2' ], 
            MHFGFWC_VERSION
        );

        wp_register_script(
            'mhfgfwc-admin',
            MHFGFWC_PLUGIN_URL . 'assets/js/admin.js',
            [ 'selectWoo', 'mhfgfwc-timepicker' ], 
            MHFGFWC_VERSION,
            true
        );

        // ---------------------------
        // Enqueue our admin assets
        // ---------------------------
        wp_enqueue_style( 'mhfgfwc-admin' );
        wp_enqueue_script( 'mhfgfwc-admin' );

        // Localize/vars
        wp_localize_script( 'mhfgfwc-admin', 'mhfgfwcAdmin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mhfgfwc_admin_nonce' ),
        ] );

        // Tiny inline CSS tweak: use proper API (no <style> tags)
        $inline_admin_css = '
        #toplevel_page_mhfgfwc_rules .wp-menu-image img,
        #toplevel_page_mhfgfwc_rules .wp-menu-image svg {
            width: 20px !important;
            height: 20px !important;
            padding-top: 7px;
        }';
        wp_add_inline_style( 'mhfgfwc-admin', $inline_admin_css );
    }
    
    public function enqueue_settings_assets( $hook ) {
        if ( $hook !== $this->settings_hook ) {
            return;
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_script(
            'mhfgfwc-admin-preview',
            MHFGFWC_PLUGIN_URL . 'assets/js/admin-preview.js',
            [ 'jquery', 'wp-color-picker' ],
            MHFGFWC_VERSION,
            true
        );
    }


    /**
     * List of rules (cached briefly to keep admin snappy)
     */
    public function render_rules_list() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table = method_exists( 'MHFGFWC_DB', 'rules_table' ) ? MHFGFWC_DB::rules_table() : $wpdb->prefix . 'mhfgfwc_rules';

        // Tiny 30s admin cache (non-persistent OK)
        $cache_key = 'mhfgfwc_admin_rules_list_v1';
        // phpcs:ignore WordPressVIPCodingStandards.VipCache.CacheGetNonPersistent
        $rules = wp_cache_get( $cache_key, 'mhfgfwc' );

        if ( false === $rules ) {
            // Table names can't use placeholders; {$table} is trusted (plugin-owned).
            // Cached below; safe, read-only admin view.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rules = $wpdb->get_results(
                "SELECT id, status, name, subtotal_operator, subtotal_amount, date_from, date_to, last_modified
                 FROM {$table}
                 ORDER BY last_modified DESC"
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            // phpcs:ignore WordPressVIPCodingStandards.VipCache.CacheSetNonPersistent
            wp_cache_set( $cache_key, $rules, 'mhfgfwc', 30 );
        }

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Free Gift Rules', 'mh-free-gifts-for-woocommerce' ); ?>
                <a href="admin.php?page=mhfgfwc_add_rule" class="page-title-action"><?php esc_html_e( 'Add Rule', 'mh-free-gifts-for-woocommerce' ); ?></a>
            </h1>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Rule Name', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Cart Subtotal', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'From', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'To', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Last Modified', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'mh-free-gifts-for-woocommerce' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $rules ) : foreach ( $rules as $rule ) : ?>
                    <tr>
                        <td>
                            <label class="mhfgfwc-switch">
                                <input
                                    type="checkbox"
                                    class="mhfgfwc-status-toggle"
                                    data-rule-id="<?php echo esc_attr( $rule->id ); ?>"
                                    <?php checked( (int) $rule->status, 1 ); ?>
                                />
                                <span class="mhfgfwc-slider"></span>
                            </label>
                        </td>
                        <td>
                            <a href="admin.php?page=mhfgfwc_add_rule&rule_id=<?php echo esc_attr( $rule->id ); ?>">
                                <?php echo esc_html( $rule->name ); ?>
                            </a>
                        </td>
                        <td>
                            <?php
                            if ( $rule->subtotal_amount !== null && $rule->subtotal_amount !== '' ) {
                                echo esc_html( $rule->subtotal_operator . ' ' . $rule->subtotal_amount );
                            } else {
                                echo '&mdash;'; // em-dash when not set
                            }
                            ?>
                        </td>
                        <td><?php echo $rule->date_from ? esc_html( $rule->date_from ) : '&mdash;'; ?></td>
                        <td><?php echo $rule->date_to   ? esc_html( $rule->date_to )   : '&mdash;'; ?></td>
                        <td><?php echo esc_html( $rule->last_modified ); ?></td>
                        <td>
                            <a href="admin.php?page=mhfgfwc_add_rule&rule_id=<?php echo esc_attr( $rule->id ); ?>" class="button">
                                <?php esc_html_e( 'Edit', 'mh-free-gifts-for-woocommerce' ); ?>
                            </a>
                            <a href="<?php echo esc_url( wp_nonce_url( 'admin-post.php?action=mhfgfwc_delete_rule&rule_id=' . $rule->id, 'mhfgfwc_delete_rule' ) ); ?>" class="button mhfgfwc-delete-rule">
                                <?php esc_html_e( 'Delete', 'mh-free-gifts-for-woocommerce' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No rules found.', 'mh-free-gifts-for-woocommerce' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_rule_form() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        // Read-only GET usage to render the form
        global $wpdb;
        $table   = method_exists( 'MHFGFWC_DB', 'rules_table' ) ? MHFGFWC_DB::rules_table() : $wpdb->prefix . 'mhfgfwc_rules';

        // Success notices
        if ( ! empty( $_GET['message'] ) ) {
            $msg = '';
            switch ( sanitize_text_field( wp_unslash( $_GET['message'] ) ) ) {
                case 'created': $msg = __( 'New gift‐rule created.', 'mh-free-gifts-for-woocommerce' ); break;
                case 'updated': $msg = __( 'Gift‐rule updated.',   'mh-free-gifts-for-woocommerce' ); break;
                case 'deleted': $msg = __( 'Gift‐rule deleted.',   'mh-free-gifts-for-woocommerce' ); break;
            }
            if ( $msg ) {
                printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
            }
        }

        $rule_id = filter_input( INPUT_GET, 'rule_id', FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
        $rule_id = $rule_id ? (int) $rule_id : 0;

        $rule = null;
        if ( $rule_id ) {
            $cache_key = 'mhfgfwc_admin_rule_' . $rule_id;
            // phpcs:ignore WordPressVIPCodingStandards.VipCache.CacheGetNonPersistent
            $rule = wp_cache_get( $cache_key, 'mhfgfwc' );

            if ( false === $rule ) {
                // Table names can't use placeholders; {$table} is trusted.
                // Read-only + we cache the result.
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rule = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE id = %d",
                        $rule_id
                    )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

                // phpcs:ignore WordPressVIPCodingStandards.VipCache.CacheSetNonPersistent
                wp_cache_set( $cache_key, $rule, 'mhfgfwc', 30 );
            }
        }

        $gifts     = $rule ? maybe_unserialize( $rule->gifts ) : [];
        $prod_deps = $rule ? maybe_unserialize( $rule->product_dependency ) : [];
        $user_deps = $rule ? maybe_unserialize( $rule->user_dependency ) : [];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap">
            <h1><?php echo $rule ? esc_html__( 'Edit Rule', 'mh-free-gifts-for-woocommerce' ) : esc_html__( 'Add New Rule', 'mh-free-gifts-for-woocommerce' ); ?></h1>
            <form method="post" action="admin-post.php" autocomplete="off">
                <?php wp_nonce_field( 'mhfgfwc_save_rule', 'mhfgfwc_nonce' ); ?>
                <input type="hidden" name="action" value="mhfgfwc_save_rule">
                <input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule_id ); ?>">

                <div class="mhfgfwc-section-title">General Settings</div>
                <table class="form-table"><tbody>
                    <tr>
                        <th><label for="mhfgfwc_status"><?php esc_html_e( 'Status', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <select name="status" id="mhfgfwc_status">
                                <option value="1" <?php selected( $rule->status ?? 1, 1 ); ?>><?php esc_html_e( 'Active', 'mh-free-gifts-for-woocommerce' ); ?></option>
                                <option value="0" <?php selected( $rule->status ?? 1, 0 ); ?>><?php esc_html_e( 'Disabled', 'mh-free-gifts-for-woocommerce' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mhfgfwc_name"><?php esc_html_e( 'Rule Name', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="name" id="mhfgfwc_name" type="text" value="<?php echo esc_attr( $rule->name ?? '' ); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="mhfgfwc_description"><?php esc_html_e( 'Description', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><textarea name="description" id="mhfgfwc_description" class="regular-text" rows="3"><?php echo esc_textarea( $rule->description ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="mhfgfwc_gifts"><?php esc_html_e( 'Select Gifts', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <select name="gifts[]" id="mhfgfwc_gifts" class="mhfgfwc-product-select" multiple="multiple" data-placeholder="<?php esc_attr_e( 'Search products...', 'mh-free-gifts-for-woocommerce' ); ?>" style="width:100%;">
                                <?php foreach ( (array) $gifts as $gid ) :
                                    $prod = wc_get_product( $gid ); if ( $prod ) : ?>
                                        <option value="<?php echo esc_attr( $gid ); ?>" selected><?php echo esc_html( $prod->get_name() ); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </tbody></table>

                <div class="mhfgfwc-section-title">Display Settings</div>
                <table class="form-table"><tbody>
                    <tr>
                        <th><label for="mhfgfwc_display_location"><?php esc_html_e( 'Display Gifts On', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <select name="display_location" id="mhfgfwc_display_location">
                                <option value="cart"     <?php selected( $rule->display_location ?? '', 'cart' ); ?>><?php esc_html_e( 'Cart', 'mh-free-gifts-for-woocommerce' ); ?></option>
                                <option value="checkout" <?php selected( $rule->display_location ?? '', 'checkout' ); ?>><?php esc_html_e( 'Cart & Checkout', 'mh-free-gifts-for-woocommerce' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mhfgfwc_items_per_row"><?php esc_html_e( 'Items Per Row (Cart)', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="items_per_row" id="mhfgfwc_items_per_row" type="number" min="1" max="6" value="<?php echo esc_attr( $rule->items_per_row ?? 4 ); ?>" class="small-text"></td>
                    </tr>
                </tbody></table>

                <div class="mhfgfwc-section-title">Usage Restrictions</div>
                <table class="form-table"><tbody>
                    <tr>
                        <th><?php esc_html_e( 'Product Dependency', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <td>
                            <select name="product_dependency[]" class="mhfgfwc-product-select" multiple="multiple" autocomplete="off" data-placeholder="<?php esc_attr_e( 'Search products...', 'mh-free-gifts-for-woocommerce' ); ?>" style="width:100%;">
                                <?php foreach ( (array) $prod_deps as $pid ) :
                                    $p = wc_get_product( $pid ); if ( $p ) : ?>
                                        <option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $p->get_name() ); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'User Dependency', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <td>
                            <select name="user_dependency[]" class="mhfgfwc-user-select" multiple="multiple" autocomplete="off" data-placeholder="<?php esc_attr_e( 'Search users...', 'mh-free-gifts-for-woocommerce' ); ?>" style="width:100%;">
                                <?php foreach ( (array) $user_deps as $uid ) :
                                    $u = get_userdata( $uid ); if ( $u ) : ?>
                                        <option value="<?php echo esc_attr( $uid ); ?>" selected><?php echo esc_html( $u->display_name ); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Registered Users Only', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="user_only" value="1" <?php checked( $rule->user_only ?? 0, 1 ); ?>> <?php esc_html_e( 'Yes', 'mh-free-gifts-for-woocommerce' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mhfgfwc_gift_quantity"><?php esc_html_e( 'Number of Gifts Allowed', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="gift_quantity" id="mhfgfwc_gift_quantity" type="number" min="1" value="<?php echo esc_attr( $rule->gift_quantity ?? 1 ); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><label for="mhfgfwc_disable_with_coupon"><?php esc_html_e( 'Disable if Coupon Applied', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input type="checkbox" name="disable_with_coupon" id="mhfgfwc_disable_with_coupon" value="1" <?php checked( $rule->disable_with_coupon ?? 0, 1 ); ?>></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Cart Subtotal', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <td>
                            <select name="subtotal_operator">
                                <?php
                                $subtotal_ops = [
                                    '<'  => __( 'Is Less Than', 'mh-free-gifts-for-woocommerce' ),
                                    '>'  => __( 'Is Greater Than', 'mh-free-gifts-for-woocommerce' ),
                                    '<=' => __( 'Is Less Than or Equal To', 'mh-free-gifts-for-woocommerce' ),
                                    '>=' => __( 'Is Greater Than or Equal To', 'mh-free-gifts-for-woocommerce' ),
                                    '==' => __( 'Is Equal To', 'mh-free-gifts-for-woocommerce' ),
                                ];
                                foreach ( $subtotal_ops as $op => $label ) :
                                    printf( '<option value="%s" %s>%s</option>',
                                        esc_attr( $op ),
                                        selected( $rule->subtotal_operator ?? '', $op, false ),
                                        esc_html( $label )
                                    );
                                endforeach; ?>
                            </select>
                            <input name="subtotal_amount" type="text" value="<?php echo esc_attr( $rule->subtotal_amount ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Amt', 'mh-free-gifts-for-woocommerce' ); ?>" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Cart Quantity', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <td>
                            <select name="qty_operator">
                                <?php
                                $quantity_ops = [
                                    '<'  => __( 'Is Less Than', 'mh-free-gifts-for-woocommerce' ),
                                    '>'  => __( 'Is Greater Than', 'mh-free-gifts-for-woocommerce' ),
                                    '<=' => __( 'Is Less Than or Equal To', 'mh-free-gifts-for-woocommerce' ),
                                    '>=' => __( 'Is Greater Than or Equal To', 'mh-free-gifts-for-woocommerce' ),
                                    '==' => __( 'Is Equal To', 'mh-free-gifts-for-woocommerce' ),
                                ];
                                foreach ( $quantity_ops as $op => $label ) :
                                    printf( '<option value="%s" %s>%s</option>',
                                        esc_attr( $op ),
                                        selected( $rule->qty_operator ?? '', $op, false ),
                                        esc_html( $label )
                                    );
                                endforeach; ?>
                            </select>
                            <input name="qty_amount" type="number" min="1" value="<?php echo esc_attr( $rule->qty_amount ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Qty', 'mh-free-gifts-for-woocommerce' ); ?>" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mhfgfwc_date_from"><?php esc_html_e( 'Valid From', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <input
                                name="date_from"
                                id="mhfgfwc_date_from"
                                type="text"
                                class="mhfgfwc-datepicker"
                                value="<?php echo esc_attr( $rule->date_from ? gmdate( 'Y-m-d H:i:s', strtotime( $rule->date_from ) ) : '' ); ?>"
                                autocomplete="new-password"
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                                inputmode="none"
                            >
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mhfgfwc_date_to"><?php esc_html_e( 'Valid To', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <input
                                name="date_to"
                                id="mhfgfwc_date_to"
                                type="text"
                                class="mhfgfwc-datepicker"
                                value="<?php echo esc_attr( $rule->date_to ? gmdate( 'Y-m-d H:i:s', strtotime( $rule->date_to ) ) : '' ); ?>"
                                autocomplete="new-password"
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                                inputmode="none"
                            >
                        </td>
                    </tr>
                </tbody></table>

                <div class="mhfgfwc-section-title">Usage Limits</div>
                <table class="form-table"><tbody>
                    <tr>
                        <th><label for="mhfgfwc_limit_per_rule"><?php esc_html_e( 'Usage Limit per Rule', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="limit_per_rule" id="mhfgfwc_limit_per_rule" type="number" min="0" value="<?php echo esc_attr( $rule->limit_per_rule ?? '' ); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><label for="mhfgfwc_limit_per_user"><?php esc_html_e( 'Usage Limit per User', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="limit_per_user" id="mhfgfwc_limit_per_user" type="number" min="0" value="<?php echo esc_attr( $rule->limit_per_user ?? '' ); ?>" class="small-text"></td>
                    </tr>
                </tbody></table>

                <?php
                if ( $rule_id ) {
                    // Buttons: distinguish by their "name" presence
                    submit_button( __( 'Update & Close', 'mh-free-gifts-for-woocommerce' ), 'primary',   'save_close', false );
                    submit_button( __( 'Update & Continue Editing', 'mh-free-gifts-for-woocommerce' ), 'secondary', 'save',       false );
                } else {
                    submit_button( __( 'Create & Close', 'mh-free-gifts-for-woocommerce' ), 'primary',   'save_close', false );
                    submit_button( __( 'Create & Continue Editing', 'mh-free-gifts-for-woocommerce' ), 'secondary', 'save',       false );
                }
                ?>
            </form>
        </div>
        <?php
    }

    public function save_rule() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'mh-free-gifts-for-woocommerce' ) );
        }

        // Verify nonce
        $raw_nonce = isset( $_POST['mhfgfwc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mhfgfwc_nonce'] ) ) : '';
        if ( empty( $raw_nonce ) || ! wp_verify_nonce( $raw_nonce, 'mhfgfwc_save_rule' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'mh-free-gifts-for-woocommerce' ) );
        }

        global $wpdb;
        $table = method_exists( 'MHFGFWC_DB', 'rules_table' ) ? MHFGFWC_DB::rules_table() : $wpdb->prefix . 'mhfgfwc_rules';

        // Build sanitized $_POST clone
        $post = array_map( static function( $v ) {
            return is_array( $v ) ? array_map( 'wp_unslash', $v ) : wp_unslash( $v );
        }, $_POST );

        $rule_id = isset( $post['rule_id'] ) ? absint( $post['rule_id'] ) : 0;

        // Collect/sanitize
        $name          = isset( $post['name'] ) ? sanitize_text_field( $post['name'] ) : '';
        $description   = isset( $post['description'] ) ? sanitize_textarea_field( $post['description'] ) : '';
        $status        = isset( $post['status'] ) ? (int) $post['status'] : 0;
        $user_only     = ! empty( $post['user_only'] ) ? 1 : 0;

        $limit_per_rule = ( isset( $post['limit_per_rule'] ) && $post['limit_per_rule'] !== '' ) ? (int) $post['limit_per_rule'] : null;
        $limit_per_user = ( isset( $post['limit_per_user'] ) && $post['limit_per_user'] !== '' ) ? (int) $post['limit_per_user'] : null;

        $gifts = isset( $post['gifts'] ) ? array_map( 'intval', (array) $post['gifts'] ) : [];
        $gift_quantity = isset( $post['gift_quantity'] ) ? (int) $post['gift_quantity'] : 1;

        $product_dependency = isset( $post['product_dependency'] ) ? array_map( 'intval', (array) $post['product_dependency'] ) : [];
        $user_dependency    = isset( $post['user_dependency'] )    ? array_map( 'intval', (array) $post['user_dependency'] )    : [];

        $disable_with_coupon = ! empty( $post['disable_with_coupon'] ) ? 1 : 0;

        $subtotal_operator = isset( $post['subtotal_operator'] ) ? sanitize_text_field( $post['subtotal_operator'] ) : '';
        $subtotal_amount   = ( isset( $post['subtotal_amount'] ) && $post['subtotal_amount'] !== '' ) ? (float) $post['subtotal_amount'] : null;

        $qty_operator = isset( $post['qty_operator'] ) ? sanitize_text_field( $post['qty_operator'] ) : '';
        $qty_amount   = ( isset( $post['qty_amount'] ) && $post['qty_amount'] !== '' ) ? (int) $post['qty_amount'] : null;

        $display_location = isset( $post['display_location'] ) ? sanitize_text_field( $post['display_location'] ) : 'cart';
        $items_per_row    = isset( $post['items_per_row'] ) ? (int) $post['items_per_row'] : 4;

        // Dates -> MySQL UTC
        $raw_date_from = isset( $post['date_from'] ) ? sanitize_text_field( $post['date_from'] ) : '';
        $raw_date_to   = isset( $post['date_to'] )   ? sanitize_text_field( $post['date_to'] )   : '';

        $date_from = $raw_date_from !== '' ? gmdate( 'Y-m-d H:i:s', strtotime( str_replace( 'T', ' ', $raw_date_from ) ) ) : null;
        $date_to   = $raw_date_to   !== '' ? gmdate( 'Y-m-d H:i:s', strtotime( str_replace( 'T', ' ', $raw_date_to ) ) )   : null;

        $data = [
            'name'                => $name,
            'description'         => $description,
            'status'              => $status,
            'user_only'           => $user_only,
            'limit_per_rule'      => $limit_per_rule,
            'limit_per_user'      => $limit_per_user,
            'gifts'               => maybe_serialize( $gifts ),
            'gift_quantity'       => $gift_quantity,
            'product_dependency'  => maybe_serialize( $product_dependency ),
            'user_dependency'     => maybe_serialize( $user_dependency ),
            'disable_with_coupon' => $disable_with_coupon,
            'subtotal_operator'   => $subtotal_operator,
            'subtotal_amount'     => $subtotal_amount,
            'qty_operator'        => $qty_operator,
            'qty_amount'          => $qty_amount,
            'date_from'           => $date_from,
            'date_to'             => $date_to,
            'display_location'    => $display_location,
            'items_per_row'       => $items_per_row,
        ];

        // Insert / Update
        // We intentionally use $wpdb writes here; inputs are sanitized and non-user identifiers.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $rule_id ) {
            $wpdb->update( $table, $data, [ 'id' => $rule_id ] );
        } else {
            $wpdb->insert( $table, $data );
            $rule_id = (int) $wpdb->insert_id;
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        // Bust caches so engine/frontend see fresh data
        if ( class_exists( 'MHFGFWC_DB' ) && method_exists( 'MHFGFWC_DB', 'bust_rules_cache' ) ) {
            MHFGFWC_DB::bust_rules_cache();
        }
        
        wp_cache_delete( 'mhfgfwc_admin_rule_' . $rule_id, 'mhfgfwc' );   // ← clear rule form cache
        wp_cache_delete( 'mhfgfwc_admin_rules_list_v1', 'mhfgfwc' );       // ← clear list cache

        // Redirect: based on which button was clicked
        $stay = isset( $_POST['save'] ); // name="save" => continue editing
        if ( $stay ) {
            wp_safe_redirect( admin_url( 'admin.php?page=mhfgfwc_add_rule&rule_id=' . $rule_id . '&message=updated' ) );
        } else {
            wp_safe_redirect( admin_url( 'admin.php?page=mhfgfwc_rules&message=updated' ) );
        }
        exit;
    }

    public function delete_rule() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'mh-free-gifts-for-woocommerce' ) );
        }

        $get_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( empty( $get_nonce ) || ! wp_verify_nonce( $get_nonce, 'mhfgfwc_delete_rule' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'mh-free-gifts-for-woocommerce' ) );
        }

        global $wpdb;
        $table   = method_exists( 'MHFGFWC_DB', 'rules_table' ) ? MHFGFWC_DB::rules_table() : $wpdb->prefix . 'mhfgfwc_rules';
        $rule_id = isset( $_GET['rule_id'] ) ? absint( wp_unslash( $_GET['rule_id'] ) ) : 0;

        // Delete
        // We intentionally use $wpdb writes here; inputs are sanitized and non-user identifiers.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $rule_id ) {
            $wpdb->delete( $table, [ 'id' => $rule_id ] );
        }

        // Bust runtime caches used by engine/frontend
        if ( class_exists( 'MHFGFWC_DB' ) && method_exists( 'MHFGFWC_DB', 'bust_rules_cache' ) ) {
            MHFGFWC_DB::bust_rules_cache();
        }

        // Bust admin-page caches (do this regardless of the branch above)
        wp_cache_delete( 'mhfgfwc_admin_rule_' . (int) $rule_id, 'mhfgfwc' ); // edit form cache
        wp_cache_delete( 'mhfgfwc_admin_rules_list_v1', 'mhfgfwc' );          // rules list cache


        wp_safe_redirect( admin_url( 'admin.php?page=mhfgfwc_rules&message=deleted' ) );
        exit;
    }

    /**
     * AJAX: search products + variations
     */
    public function ajax_search_products() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'mh-free-gifts-for-woocommerce' ) ], 403 );
        }

        if ( empty( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'mhfgfwc_admin_nonce' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid nonce', 'mh-free-gifts-for-woocommerce' ) ], 400 );
        }

        $term = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : '';

        $posts = get_posts( [
            'post_type'      => [ 'product', 'product_variation' ],
            's'              => $term,
            'posts_per_page' => 20,
        ] );

        $results = [];
        foreach ( $posts as $p ) {
            if ( 'product_variation' === $p->post_type ) {
                $variation = wc_get_product( $p->ID );
                if ( ! $variation ) {
                    continue;
                }
                $label = $variation->get_name();
            } else {
                $product = wc_get_product( $p->ID );
                if ( ! $product ) {
                    continue;
                }
                $label = $product->get_name();
            }

            $results[] = [
                'id'   => $p->ID,
                'text' => $label,
            ];
        }

        wp_send_json_success( $results );
    }

    /**
     * AJAX: toggle rule status
     */
    public function ajax_toggle_status() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'mh-free-gifts-for-woocommerce' ) ], 403 );
        }

        $ajax_nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( empty( $ajax_nonce ) || ! wp_verify_nonce( $ajax_nonce, 'mhfgfwc_admin_nonce' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid nonce', 'mh-free-gifts-for-woocommerce' ) ], 400 );
        }

        $rule_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;
        $status  = isset( $_POST['status'] ) && absint( wp_unslash( $_POST['status'] ) ) ? 1 : 0;

        if ( ! $rule_id ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Missing rule_id.', 'mh-free-gifts-for-woocommerce' ) ], 400 );
        }

        // Update
        // We intentionally use $wpdb writes here; inputs are sanitized and non-user identifiers.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        global $wpdb;
        $table = method_exists( 'MHFGFWC_DB', 'rules_table' ) ? MHFGFWC_DB::rules_table() : $wpdb->prefix . 'mhfgfwc_rules';
        $wpdb->update( $table, [ 'status' => $status ], [ 'id' => $rule_id ] );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( class_exists( 'MHFGFWC_DB' ) && method_exists( 'MHFGFWC_DB', 'bust_rules_cache' ) ) {
            MHFGFWC_DB::bust_rules_cache();
        }
        
        // Clear admin-page caches so the list & edit screens reflect immediately
        wp_cache_delete( 'mhfgfwc_admin_rules_list_v1', 'mhfgfwc' );
        wp_cache_delete( 'mhfgfwc_admin_rule_' . (int) $rule_id, 'mhfgfwc' );

        // Bump a “rules revision” for frontend sessions
        update_option( 'mhfgfwc_rules_rev', time() );

        wp_send_json_success();
    }

    /**
     * AJAX: search users
     */
    public function ajax_search_users() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'mh-free-gifts-for-woocommerce' ) ], 403 );
        }

        if ( empty( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'mhfgfwc_admin_nonce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce', 'mh-free-gifts-for-woocommerce' ) ], 400 );
        }

        $term = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : '';

        $user_query = new WP_User_Query( [
            'search'         => '*' . esc_sql( $term ) . '*',
            'search_columns' => [ 'user_login', 'display_name', 'user_email' ],
            'number'         => 20,
        ] );

        $results = [];
        foreach ( $user_query->get_results() as $u ) {
            $results[] = [
                'id'   => $u->ID,
                'text' => sprintf( '%s (%s)', $u->display_name, $u->user_email ),
            ];
        }

        wp_send_json_success( $results );
    }
    
    public function register_settings() {
        register_setting(
            'mhfgfwc_settings',
            'mhfgfwc_button_styles',
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_button_styles' ],
                'default'           => [
                    'text_color'   => '#ffffff',
                    'bg_color'     => '#0071a1',
                    'border_color' => '#0071a1',
                    'border_size'  => 2,
                    'radius'       => 25,
                ],
            ]
        );

        add_settings_section(
            'mhfgfwc_buttons_section',
            __( 'Button Styles', 'mh-free-gifts-for-woocommerce' ),
            function () {
                echo '<p>' . esc_html__( 'Customize how “Add Gift” / “Remove Gift” buttons look across cart & checkout.', 'mh-free-gifts-for-woocommerce' ) . '</p>';
            },
            'mhfgfwc_settings'
        );

        add_settings_field(
            'mhfgfwc_text_color',
            __( 'Button Text Color', 'mh-free-gifts-for-woocommerce' ),
            [ $this, 'field_text_color' ],
            'mhfgfwc_settings',
            'mhfgfwc_buttons_section'
        );

        add_settings_field(
            'mhfgfwc_bg_color',
            __( 'Button Background Color', 'mh-free-gifts-for-woocommerce' ),
            [ $this, 'field_bg_color' ],
            'mhfgfwc_settings',
            'mhfgfwc_buttons_section'
        );

        add_settings_field(
            'mhfgfwc_border_color',
            __( 'Button Border Color', 'mh-free-gifts-for-woocommerce' ),
            [ $this, 'field_border_color' ],
            'mhfgfwc_settings',
            'mhfgfwc_buttons_section'
        );

        add_settings_field(
            'mhfgfwc_border_size',
            __( 'Border Size (px)', 'mh-free-gifts-for-woocommerce' ),
            [ $this, 'field_border_size' ],
            'mhfgfwc_settings',
            'mhfgfwc_buttons_section'
        );

        add_settings_field(
            'mhfgfwc_radius',
            __( 'Border Radius (px)', 'mh-free-gifts-for-woocommerce' ),
            [ $this, 'field_radius' ],
            'mhfgfwc_settings',
            'mhfgfwc_buttons_section'
        );
    }

    public function sanitize_button_styles( $input ) {
        $out = [];

        $out['text_color']   = isset( $input['text_color'] )   ? sanitize_hex_color( $input['text_color'] )   : '#ffffff';
        $out['bg_color']     = isset( $input['bg_color'] )     ? sanitize_hex_color( $input['bg_color'] )     : '#0071a1';
        $out['border_color'] = isset( $input['border_color'] ) ? sanitize_hex_color( $input['border_color'] ) : '#0071a1';

        $out['border_size']  = isset( $input['border_size'] ) ? max( 0, min( 12, absint( $input['border_size'] ) ) ) : 2;
        $out['radius']       = isset( $input['radius'] )      ? max( 0, min( 50,  absint( $input['radius'] ) ) )      : 25;

        // Fallback for any invalid hex colors
        foreach ( ['text_color','bg_color','border_color'] as $k ) {
            if ( empty( $out[ $k ] ) ) {
                $out[ $k ] = ( 'text_color' === $k ) ? '#ffffff' : '#0071a1';
            }
        }

        return $out;
    }


    public function render_color_field( $args ) {
        $opts = (array) get_option( 'mhfgfwc_settings', [] );
        $key  = (string) $args['key'];
        $val  = isset( $opts[ $key ] ) ? (string) $opts[ $key ] : (string) ( $args['default'] ?? '' );

        printf(
            '<input type="text" class="regular-text" name="mhfgfwc_settings[%1$s]" value="%2$s" placeholder="%3$s" pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$" />',
            esc_attr( $key ),
            esc_attr( $val ),
            esc_attr__( '#RRGGBB (leave empty to inherit theme accent)', 'mh-free-gifts-for-woocommerce' )
        );
        if ( '' === $val ) {
            echo '<p class="description">' . esc_html__( 'Empty will use your theme accent color (or fall back to #0071a1).', 'mh-free-gifts-for-woocommerce' ) . '</p>';
        }
    }

    public function render_radius_field( $args ) {
        $opts = (array) get_option( 'mhfgfwc_settings', [] );
        $key  = (string) $args['key'];
        $val  = isset( $opts[ $key ] ) ? (int) $opts[ $key ] : (int) ( $args['default'] ?? 25 );

        printf(
            '<input type="number" class="small-text" min="0" max="64" name="mhfgfwc_settings[%1$s]" value="%2$d" /> %3$s',
            esc_attr( $key ),
            esc_attr( $val ),
            esc_html__( 'px', 'mh-free-gifts-for-woocommerce' )
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $o = $this->get_button_styles();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Free Gifts Settings', 'mh-free-gifts-for-woocommerce' ) . '</h1>';

        // Live preview (reflects current inputs before save via JS)
        $style_attr = sprintf(
            'color:%1$s;background:%2$s;border:%3$dpx solid %4$s;border-radius:%5$dpx;',
            esc_attr( $o['text_color'] ),
            esc_attr( $o['bg_color'] ),
            (int) $o['border_size'],
            esc_attr( $o['border_color'] ),
            (int) $o['radius']
        );

        echo '<hr style="margin:24px 0;">';
        echo '<h2>' . esc_html__( 'Live Preview', 'mh-free-gifts-for-woocommerce' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'This is how your buttons will look on the frontend.', 'mh-free-gifts-for-woocommerce' ) . '</p>';

        echo '<div id="mhfgfwc-preview" style="display:flex;gap:12px;align-items:center;">';
        echo '  <a href="#" class="button mhfgfwc-preview-btn" style="' . esc_attr( $style_attr ) . '">' . esc_html__( 'Add Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
        echo '  <a href="#" class="button mhfgfwc-preview-btn" style="' . esc_attr( $style_attr ) . '">' . esc_html__( 'Remove Gift', 'mh-free-gifts-for-woocommerce' ) . '</a>';
        echo '</div>';
        
        echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
        settings_fields( 'mhfgfwc_settings' );
        do_settings_sections( 'mhfgfwc_settings' );
        submit_button();
        echo '</form>';

        echo '</div>'; // .wrap
    }

    
    public function render_border_size_field( $args ) {
        $opts = (array) get_option( 'mhfgfwc_settings', [] );
        $key  = (string) $args['key'];
        $val  = isset( $opts[ $key ] ) ? (int) $opts[ $key ] : (int) ( $args['default'] ?? 2 );

        printf(
            '<input type="number" class="small-text" min="0" max="10" name="mhfgfwc_settings[%1$s]" value="%2$d" /> %3$s',
            esc_attr( $key ),
            esc_html( $val ),
            esc_html__( 'px', 'mh-free-gifts-for-woocommerce' )
        );
    }
    
    private function get_button_styles() {
        $opt = get_option( 'mhfgfwc_button_styles', [] );
        $def = [
            'text_color'   => '#ffffff',
            'bg_color'     => '#000000',
            'border_color' => '#000000',
            'border_size'  => 2,
            'radius'       => 25,
        ];
        return wp_parse_args( is_array( $opt ) ? $opt : [], $def );
    }

    public function field_text_color() {
        $o = $this->get_button_styles();
        printf(
            '<input type="text" id="mhfgfwc_text_color" name="mhfgfwc_button_styles[text_color]" value="%s" class="regular-text mhfgfwc-color" placeholder="#ffffff" />',
            esc_attr( $o['text_color'] )
        );
        echo '<p class="description">' .
            esc_html__( 'Default: #ffffff (white). Sets the text color inside your “Add Gift” / “Remove Gift” buttons.', 'mh-free-gifts-for-woocommerce' ) .
        '</p>';
    }

    public function field_bg_color() {
        $o = $this->get_button_styles();
        printf(
            '<input type="text" id="mhfgfwc_bg_color" name="mhfgfwc_button_styles[bg_color]" value="%s" class="regular-text mhfgfwc-color" placeholder="#0071a1" />',
            esc_attr( $o['bg_color'] )
        );
        echo '<p class="description">' .
            esc_html__( 'Default: #000000 (black). The button background color. You can also leave it blank to use your theme’s accent color (var(--accent-color)).', 'mh-free-gifts-for-woocommerce' ) .
        '</p>';
    }

    public function field_border_color() {
        $o = $this->get_button_styles();
        printf(
            '<input type="text" id="mhfgfwc_border_color" name="mhfgfwc_button_styles[border_color]" value="%s" class="regular-text mhfgfwc-color" placeholder="#0071a1" />',
            esc_attr( $o['border_color'] )
        );
        echo '<p class="description">' .
            esc_html__( 'Default: #000000 (black). Defines the outline color. You can clear this field to fall back to your theme’s accent color.', 'mh-free-gifts-for-woocommerce' ) .
        '</p>';
    }

    public function field_border_size() {
        $o = $this->get_button_styles();
        printf(
            '<input type="number" id="mhfgfwc_border_size" name="mhfgfwc_button_styles[border_size]" value="%d" min="0" max="12" class="small-text" />',
            (int) $o['border_size']
        );
        echo ' <span class="description">' .
            esc_html__( 'Pixels (default: 2). Controls the border thickness of your buttons.', 'mh-free-gifts-for-woocommerce' ) .
        '</span>';
    }

    public function field_radius() {
        $o = $this->get_button_styles();
        printf(
            '<input type="number" id="mhfgfwc_radius" name="mhfgfwc_button_styles[radius]" value="%d" min="0" max="50" class="small-text" />',
            (int) $o['radius']
        );
        echo ' <span class="description">' .
            esc_html__( 'Pixels (default: 25). Adjusts how rounded the button corners are.', 'mh-free-gifts-for-woocommerce' ) .
        '</span>';
    }

    
}

// Initialize Admin
MHFGFWC_Admin::instance();
