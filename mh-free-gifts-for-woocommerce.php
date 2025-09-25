<?php
/**
 * Plugin Name: MH Free Gifts for WooCommerce
 * Plugin URI:  https://github.com/mediahubltd/mh-free-gifts-for-woocommerce
 * Description: Mediahub Free Gifts for WooCommerce gives store owners a powerful yet intuitive way to reward customers with a choice of complimentary products.
 * Version:     1.0.3
 * Author:      mediahub
 * Author URI:  https://www.mediahubsolutions.com
 * Text Domain: mh-free-gifts-for-woocommerce
 * Requires at least: 6.0
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** ------------------------------------------------------------------------
 * Constants
 * --------------------------------------------------------------------- */
if ( ! defined( 'WCFG_VERSION' ) ) {
    define( 'WCFG_VERSION', '1.0.3' );
}
if ( ! defined( 'WCFG_PLUGIN_DIR' ) ) {
    define( 'WCFG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WCFG_PLUGIN_URL' ) ) {
    define( 'WCFG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/** ------------------------------------------------------------------------
 * Activation: require WooCommerce, then install/upgrade schema
 * --------------------------------------------------------------------- */
function wcfg_activate() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'Free Gifts for WooCommerce requires WooCommerce. Please activate WooCommerce first.', 'mh-free-gifts-for-woocommerce' ),
            esc_html__( 'Plugin dependency check', 'mh-free-gifts-for-woocommerce' ),
            [ 'back_link' => true ]
        );
    }

    // Install/upgrade schema
    require_once WCFG_PLUGIN_DIR . 'includes/class-wcfg-install.php';
    if ( class_exists( 'WCFG_Install' ) && method_exists( 'WCFG_Install', 'install' ) ) {
        WCFG_Install::install();
    } elseif ( class_exists( 'WCFG_Install' ) && method_exists( 'WCFG_Install', 'install_tables' ) ) {
        // Back-compat if your installer still uses install_tables()
        WCFG_Install::install_tables();
    }
}
register_activation_hook( __FILE__, 'wcfg_activate' );

/** ------------------------------------------------------------------------
 * Optional schema safety net after updates
 * --------------------------------------------------------------------- */
add_action( 'admin_init', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    $installer = WCFG_PLUGIN_DIR . 'includes/class-wcfg-install.php';
    if ( file_exists( $installer ) ) {
        require_once $installer;
        if ( class_exists( 'WCFG_Install' ) && method_exists( 'WCFG_Install', 'maybe_install_or_upgrade' ) ) {
            WCFG_Install::maybe_install_or_upgrade();
        }
    }
} );

/** ------------------------------------------------------------------------
 * Bootstrap after all plugins are loaded
 * --------------------------------------------------------------------- */
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>'
               . esc_html__( 'Free Gifts for WooCommerce requires WooCommerce.', 'mh-free-gifts-for-woocommerce' )
               . '</p></div>';
        } );
        return;
    }

    // Core includes (DB helper first)
    require_once WCFG_PLUGIN_DIR . 'includes/class-wcfg-db.php';
    require_once WCFG_PLUGIN_DIR . 'includes/class-wcfg-install.php';
    require_once WCFG_PLUGIN_DIR . 'includes/class-wcfg-admin.php';
    require_once WCFG_PLUGIN_DIR . 'includes/class-wcfg-engine.php';
    require_once WCFG_PLUGIN_DIR . 'includes/class-wcfg-frontend.php';

    // Instantiate subsystems
    if ( class_exists( 'WCFG_Admin' ) )    { WCFG_Admin::instance(); }
    if ( class_exists( 'WCFG_Engine' ) )   { WCFG_Engine::instance(); }
    if ( class_exists( 'WCFG_Frontend' ) ) { WCFG_Frontend::instance(); }
}, 20 );
