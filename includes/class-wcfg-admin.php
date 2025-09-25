<?php
/**
 * Admin UI for WooBuddy Free Gifts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCFG_Admin {
    private static $instance;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Mutations
        add_action( 'admin_post_wcfg_save_rule',   [ $this, 'save_rule' ] );
        add_action( 'admin_post_wcfg_delete_rule', [ $this, 'delete_rule' ] );

        // AJAX
        add_action( 'wp_ajax_wcfg_search_products', [ $this, 'ajax_search_products' ] );
        add_action( 'wp_ajax_wcfg_toggle_status',   [ $this, 'ajax_toggle_status' ] );
        add_action( 'wp_ajax_wcfg_search_users',    [ $this, 'ajax_search_users' ] );

        // Clamp menu icon to 20×20
        add_action( 'admin_head', function() {
            ?>
            <style>
                #toplevel_page_wcfg_rules .wp-menu-image img,
                #toplevel_page_wcfg_rules .wp-menu-image svg {
                    width: 20px !important;
                    height: 20px !important;
                    padding-top: 7px;
                }
            </style>
            <?php
        } );
    }

    public function register_menu() {
        add_menu_page(
            __( 'WooBuddy Free Gifts', 'mh-free-gifts-for-woocommerce' ),
            __( 'Free Gifts', 'mh-free-gifts-for-woocommerce' ),
            'manage_options',
            'wcfg_rules',
            [ $this, 'render_rules_list' ],
            WCFG_PLUGIN_URL . 'assets/images/wcfg-menu-icon.svg',
            56
        );

        add_submenu_page(
            'wcfg_rules',
            __( 'Add New Rule', 'mh-free-gifts-for-woocommerce' ),
            __( 'Add Rule', 'mh-free-gifts-for-woocommerce' ),
            'manage_options',
            'wcfg_add_rule',
            [ $this, 'render_rule_form' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wcfg_' ) === false ) {
            return;
        }

        // Core jQuery UI
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_script( 'jquery-ui-slider' );

        // Theme CSS for jQuery UI
        wp_register_style(
            'wcfg-jquery-ui-theme',
            WCFG_PLUGIN_URL . 'assets/css/jquery-ui.css',
            [],
            '1.12.1'
        );
        wp_enqueue_style( 'wcfg-jquery-ui-theme' );

        // Timepicker addon
        wp_enqueue_style(
            'wcfg-timepicker',
            WCFG_PLUGIN_URL . 'assets/css/jquery-ui-timepicker-addon.css',
            [ 'wcfg-jquery-ui-theme' ],
            '1.6.3'
        );
        wp_enqueue_script(
            'wcfg-timepicker',
            WCFG_PLUGIN_URL . 'assets/js/jquery-ui-timepicker-addon.js',
            [ 'jquery-ui-datepicker', 'jquery-ui-slider' ],
            '1.6.3',
            true
        );

        // SelectWoo
        if ( function_exists( 'wc_enqueue_select2' ) ) {
            wc_enqueue_select2();
        } else {
            wp_enqueue_style( 'selectWoo' );
            wp_enqueue_script( 'selectWoo' );
        }

        wp_enqueue_script(
            'wcfg-admin',
            WCFG_PLUGIN_URL . 'assets/js/admin.js',
            [ 'selectWoo' ],
            WCFG_VERSION . '.' . time(),
            true
        );

        wp_enqueue_style(
            'wcfg-admin',
            WCFG_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WCFG_VERSION
        );

        wp_localize_script( 'wcfg-admin', 'wcfgAdmin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wcfg_admin_nonce' ),
        ] );
    }

    /**
     * List of rules (cached briefly to keep admin snappy)
     */
    public function render_rules_list() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table = method_exists( 'WCFG_DB', 'rules_table' ) ? WCFG_DB::rules_table() : $wpdb->prefix . 'wcfg_rules';

        // Tiny 30s admin cache (non-persistent OK)
        $cache_key = 'wcfg_admin_rules_list_v1';
        // phpcs:ignore WordPressVIPCodingStandards.VipCache.CacheGetNonPersistent
        $rules = wp_cache_get( $cache_key, 'wcfg' );

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
            wp_cache_set( $cache_key, $rules, 'wcfg', 30 );
        }



        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Free Gift Rules', 'mh-free-gifts-for-woocommerce' ); ?>
                <a href="admin.php?page=wcfg_add_rule" class="page-title-action"><?php esc_html_e( 'Add Rule', 'mh-free-gifts-for-woocommerce' ); ?></a>
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
                            <label class="wcfg-switch">
                                <input
                                    type="checkbox"
                                    class="wcfg-status-toggle"
                                    data-rule-id="<?php echo esc_attr( $rule->id ); ?>"
                                    <?php checked( (int) $rule->status, 1 ); ?>
                                />
                                <span class="wcfg-slider"></span>
                            </label>
                        </td>
                        <td>
                            <a href="admin.php?page=wcfg_add_rule&rule_id=<?php echo esc_attr( $rule->id ); ?>">
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
                            <a href="admin.php?page=wcfg_add_rule&rule_id=<?php echo esc_attr( $rule->id ); ?>" class="button">
                                <?php esc_html_e( 'Edit', 'mh-free-gifts-for-woocommerce' ); ?>
                            </a>
                            <a href="<?php echo esc_url( wp_nonce_url( 'admin-post.php?action=wcfg_delete_rule&rule_id=' . $rule->id, 'wcfg_delete_rule' ) ); ?>" class="button wcfg-delete-rule">
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
        $table   = method_exists( 'WCFG_DB', 'rules_table' ) ? WCFG_DB::rules_table() : $wpdb->prefix . 'wcfg_rules';

        // Success notices
        if ( ! empty( $_GET['message'] ) ) {
            $msg = '';
            switch ( sanitize_text_field( wp_unslash( $_GET['message'] ) ) ) {
                case 'created': $msg = __( 'New gift‐rule created.', 'mh-free-gifts-for-woocommerce' ); break;
                case 'updated': $msg = __( 'Gift‐rule updated.',   'mh-free-gifts-for-woocommerce' ); break;
            }
            if ( $msg ) {
                printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
            }
        }

        $rule_id = filter_input( INPUT_GET, 'rule_id', FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
        $rule_id = $rule_id ? (int) $rule_id : 0;

        $rule = null;
        if ( $rule_id ) {
            $cache_key = 'wcfg_admin_rule_' . $rule_id;
            // phpcs:ignore WordPressVIPCodingStandards.VipCache.CacheGetNonPersistent
            $rule = wp_cache_get( $cache_key, 'wcfg' );

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
                wp_cache_set( $cache_key, $rule, 'wcfg', 30 );
            }
        }



        $gifts     = $rule ? maybe_unserialize( $rule->gifts ) : [];
        $prod_deps = $rule ? maybe_unserialize( $rule->product_dependency ) : [];
        $user_deps = $rule ? maybe_unserialize( $rule->user_dependency ) : [];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap">
            <h1><?php echo $rule ? esc_html__( 'Edit Rule', 'mh-free-gifts-for-woocommerce' ) : esc_html__( 'Add New Rule', 'mh-free-gifts-for-woocommerce' ); ?></h1>
            <form method="post" action="admin-post.php">
                <?php wp_nonce_field( 'wcfg_save_rule', 'wcfg_nonce' ); ?>
                <input type="hidden" name="action" value="wcfg_save_rule">
                <input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule_id ); ?>">

                <div class="wcfg-section-title">General Settings</div>
                <table class="form-table"><tbody>
                    <tr>
                        <th><label for="wcfg_status"><?php esc_html_e( 'Status', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <select name="status" id="wcfg_status">
                                <option value="1" <?php selected( $rule->status ?? 1, 1 ); ?>><?php esc_html_e( 'Active', 'mh-free-gifts-for-woocommerce' ); ?></option>
                                <option value="0" <?php selected( $rule->status ?? 1, 0 ); ?>><?php esc_html_e( 'Disabled', 'mh-free-gifts-for-woocommerce' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wcfg_name"><?php esc_html_e( 'Rule Name', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="name" id="wcfg_name" type="text" value="<?php echo esc_attr( $rule->name ?? '' ); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="wcfg_description"><?php esc_html_e( 'Description', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><textarea name="description" id="wcfg_description" class="regular-text" rows="3"><?php echo esc_textarea( $rule->description ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="wcfg_gifts"><?php esc_html_e( 'Select Gifts', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <select name="gifts[]" id="wcfg_gifts" class="wcfg-product-select" multiple="multiple" data-placeholder="<?php esc_attr_e( 'Search products...', 'mh-free-gifts-for-woocommerce' ); ?>" style="width:100%;">
                                <?php foreach ( (array) $gifts as $gid ) :
                                    $prod = wc_get_product( $gid ); if ( $prod ) : ?>
                                        <option value="<?php echo esc_attr( $gid ); ?>" selected><?php echo esc_html( $prod->get_name() ); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </tbody></table>

                <div class="wcfg-section-title">Display Settings</div>
                <table class="form-table"><tbody>
                    <tr>
                        <th><label for="wcfg_display_location"><?php esc_html_e( 'Display Gifts On', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <select name="display_location" id="wcfg_display_location">
                                <option value="cart"     <?php selected( $rule->display_location ?? '', 'cart' ); ?>><?php esc_html_e( 'Cart', 'mh-free-gifts-for-woocommerce' ); ?></option>
                                <option value="checkout" <?php selected( $rule->display_location ?? '', 'checkout' ); ?>><?php esc_html_e( 'Cart & Checkout', 'mh-free-gifts-for-woocommerce' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wcfg_items_per_row"><?php esc_html_e( 'Items Per Row (Cart)', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="items_per_row" id="wcfg_items_per_row" type="number" min="1" max="6" value="<?php echo esc_attr( $rule->items_per_row ?? 4 ); ?>" class="small-text"></td>
                    </tr>
                </tbody></table>

                <div class="wcfg-section-title">Usage Restrictions</div>
                <table class="form-table"><tbody>
                    <tr>
                        <th><?php esc_html_e( 'Product Dependency', 'mh-free-gifts-for-woocommerce' ); ?></th>
                        <td>
                            <select name="product_dependency[]" class="wcfg-product-select" multiple="multiple" data-placeholder="<?php esc_attr_e( 'Search products...', 'mh-free-gifts-for-woocommerce' ); ?>" style="width:100%;">
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
                            <select name="user_dependency[]" class="wcfg-user-select" multiple="multiple" data-placeholder="<?php esc_attr_e( 'Search users...', 'mh-free-gifts-for-woocommerce' ); ?>" style="width:100%;">
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
                        <th><label for="wcfg_gift_quantity"><?php esc_html_e( 'Number of Gifts Allowed', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="gift_quantity" id="wcfg_gift_quantity" type="number" min="1" value="<?php echo esc_attr( $rule->gift_quantity ?? 1 ); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><label for="wcfg_disable_with_coupon"><?php esc_html_e( 'Disable if Coupon Applied', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input type="checkbox" name="disable_with_coupon" id="wcfg_disable_with_coupon" value="1" <?php checked( $rule->disable_with_coupon ?? 0, 1 ); ?>></td>
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
                        <th><label for="wcfg_date_from"><?php esc_html_e( 'Valid From', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <input
                                name="date_from"
                                id="wcfg_date_from"
                                type="text"
                                class="wcfg-datepicker"
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
                        <th><label for="wcfg_date_to"><?php esc_html_e( 'Valid To', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td>
                            <input
                                name="date_to"
                                id="wcfg_date_to"
                                type="text"
                                class="wcfg-datepicker"
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

                <div class="wcfg-section-title">Usage Limits</div>
                <table class="form-table"><tbody>
                    <tr>
                        <th><label for="wcfg_limit_per_rule"><?php esc_html_e( 'Usage Limit per Rule', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="limit_per_rule" id="wcfg_limit_per_rule" type="number" min="0" value="<?php echo esc_attr( $rule->limit_per_rule ?? '' ); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><label for="wcfg_limit_per_user"><?php esc_html_e( 'Usage Limit per User', 'mh-free-gifts-for-woocommerce' ); ?></label></th>
                        <td><input name="limit_per_user" id="wcfg_limit_per_user" type="number" min="0" value="<?php echo esc_attr( $rule->limit_per_user ?? '' ); ?>" class="small-text"></td>
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
        $raw_nonce = isset( $_POST['wcfg_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wcfg_nonce'] ) ) : '';
        if ( empty( $raw_nonce ) || ! wp_verify_nonce( $raw_nonce, 'wcfg_save_rule' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'mh-free-gifts-for-woocommerce' ) );
        }

        global $wpdb;
        $table = method_exists( 'WCFG_DB', 'rules_table' ) ? WCFG_DB::rules_table() : $wpdb->prefix . 'wcfg_rules';

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

        $limit_per_rule = ( $post['limit_per_rule'] !== '' && isset( $post['limit_per_rule'] ) ) ? (int) $post['limit_per_rule'] : null;
        $limit_per_user = ( $post['limit_per_user'] !== '' && isset( $post['limit_per_user'] ) ) ? (int) $post['limit_per_user'] : null;

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
        if ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'bust_rules_cache' ) ) {
            WCFG_DB::bust_rules_cache();
        }

        // Redirect: based on which button was clicked
        $stay = isset( $_POST['save'] ); // name="save" => continue editing
        if ( $stay ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wcfg_add_rule&rule_id=' . $rule_id . '&message=updated' ) );
        } else {
            wp_safe_redirect( admin_url( 'admin.php?page=wcfg_rules&message=updated' ) );
        }
        exit;
    }

    public function delete_rule() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'mh-free-gifts-for-woocommerce' ) );
        }

        $get_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( empty( $get_nonce ) || ! wp_verify_nonce( $get_nonce, 'wcfg_delete_rule' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'mh-free-gifts-for-woocommerce' ) );
        }

        global $wpdb;
        $table   = method_exists( 'WCFG_DB', 'rules_table' ) ? WCFG_DB::rules_table() : $wpdb->prefix . 'wcfg_rules';
        $rule_id = isset( $_GET['rule_id'] ) ? absint( wp_unslash( $_GET['rule_id'] ) ) : 0;

        // Delete
        // We intentionally use $wpdb writes here; inputs are sanitized and non-user identifiers.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( $rule_id ) {
            $wpdb->delete( $table, [ 'id' => $rule_id ] );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching


        if ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'bust_rules_cache' ) ) {
            WCFG_DB::bust_rules_cache();
        } else {
            wp_cache_delete( 'wcfg_admin_rules_list_v1', 'wcfg' );
            wp_cache_delete( 'rules_active_v1', 'wcfg' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wcfg_rules&message=deleted' ) );
        exit;
    }

    /**
     * AJAX: search products + variations
     */
    public function ajax_search_products() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'mh-free-gifts-for-woocommerce' ) ], 403 );
        }

        if ( empty( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'wcfg_admin_nonce' ) ) {
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
        if ( empty( $ajax_nonce ) || ! wp_verify_nonce( $ajax_nonce, 'wcfg_admin_nonce' ) ) {
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
        $table = method_exists( 'WCFG_DB', 'rules_table' ) ? WCFG_DB::rules_table() : $wpdb->prefix . 'wcfg_rules';
        $wpdb->update( $table, [ 'status' => $status ], [ 'id' => $rule_id ] );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( class_exists( 'WCFG_DB' ) && method_exists( 'WCFG_DB', 'bust_rules_cache' ) ) {
            WCFG_DB::bust_rules_cache();
        }

        wp_send_json_success();
    }

    /**
     * AJAX: search users
     */
    public function ajax_search_users() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'mh-free-gifts-for-woocommerce' ) ], 403 );
        }

        if ( empty( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'wcfg_admin_nonce' ) ) {
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
}

// Initialize Admin
WCFG_Admin::instance();
