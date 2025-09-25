<?php
class WCFG_Rule_Post_Type {
    private static $instance;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_post_wcfg_save_rule', [ $this, 'save_rule' ] );
        // other hooks: delete, status toggle
    }

    public function register_menu() {
        add_menu_page(
            __( 'Free Gifts', 'mh-free-gifts-for-woocommerce' ),
            __( 'Free Gifts', 'mh-free-gifts-for-woocommerce' ),
            'manage_woocommerce',
            'wcfg_rules',
            [ $this, 'render_rules_list' ],
            'dashicons-gift',
            56
        );
        add_submenu_page(
            'wcfg_rules',
            __( 'Add Rule', 'mh-free-gifts-for-woocommerce' ),
            __( 'Add Rule', 'mh-free-gifts-for-woocommerce' ),
            'manage_woocommerce',
            'wcfg_add_rule',
            [ $this, 'render_rule_form' ]
        );
    }

  
}
