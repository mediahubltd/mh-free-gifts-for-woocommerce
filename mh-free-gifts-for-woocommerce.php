<?php
/**
 * Plugin Name: MH Free Gifts for WooCommerce 
 * Plugin URI:  https://github.com/mediahubltd/mh-free-gifts-for-woocommerce
 * Description: Mediahub Free Gifts for WooCommerce gives store owners a powerful yet intuitive way to reward customers with a choice of complimentary products.
 * Version:     1.0.4
 * Author:      mediahub
 * Author URI:  https://www.mediahubsolutions.com
 * Text Domain: mh-free-gifts-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** ------------------------------------------------------------------------
 * Constants
 * --------------------------------------------------------------------- */
if ( ! defined( 'MHFGFWC_VERSION' ) ) {
    define( 'MHFGFWC_VERSION', '1.0.4' );
}
if ( ! defined( 'MHFGFWC_PLUGIN_FILE' ) ) {
    define( 'MHFGFWC_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'MHFGFWC_PLUGIN_DIR' ) ) {
    define( 'MHFGFWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'MHFGFWC_PLUGIN_URL' ) ) {
    define( 'MHFGFWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'MHFGFWC_TEXT_DOMAIN' ) ) {
    define( 'MHFGFWC_TEXT_DOMAIN', 'mh-free-gifts-for-woocommerce' );
}

/** ------------------------------------------------------------------------
 * Internationalization (load text domain)
 * --------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain(
        MHFGFWC_TEXT_DOMAIN,
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}, 0 );

/** ------------------------------------------------------------------------
 * Activation: require WooCommerce, then install/upgrade schema
 * --------------------------------------------------------------------- */
function mhfgfwc_activate() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'Free Gifts for WooCommerce requires WooCommerce. Please activate WooCommerce first.', MHFGFWC_TEXT_DOMAIN ),
            esc_html__( 'Plugin dependency check', MHFGFWC_TEXT_DOMAIN ),
            [ 'back_link' => true ]
        );
    }

    // Install/upgrade schema
    require_once MHFGFWC_PLUGIN_DIR . 'includes/class-mhfgfwc-install.php';
    if ( class_exists( 'MHFGFWC_Install' ) && method_exists( 'MHFGFWC_Install', 'install' ) ) {
        MHFGFWC_Install::install();
    } elseif ( class_exists( 'MHFGFWC_Install' ) && method_exists( 'MHFGFWC_Install', 'install_tables' ) ) {
        // Back-compat if your installer still uses install_tables()
        MHFGFWC_Install::install_tables();
    }
}
register_activation_hook( __FILE__, 'mhfgfwc_activate' );

/** ------------------------------------------------------------------------
 * Optional schema safety net after updates
 * --------------------------------------------------------------------- */
add_action( 'admin_init', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    $installer = MHFGFWC_PLUGIN_DIR . 'includes/class-mhfgfwc-install.php';
    if ( file_exists( $installer ) ) {
        require_once $installer;
        if ( class_exists( 'MHFGFWC_Install' ) && method_exists( 'MHFGFWC_Install', 'maybe_install_or_upgrade' ) ) {
            MHFGFWC_Install::maybe_install_or_upgrade();
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
               . esc_html__( 'Free Gifts for WooCommerce requires WooCommerce.', MHFGFWC_TEXT_DOMAIN )
               . '</p></div>';
        } );
        return;
    }

    // Core includes (DB helper first)
    require_once MHFGFWC_PLUGIN_DIR . 'includes/class-mhfgfwc-db.php';
    require_once MHFGFWC_PLUGIN_DIR . 'includes/class-mhfgfwc-install.php';
    require_once MHFGFWC_PLUGIN_DIR . 'includes/class-mhfgfwc-admin.php';
    require_once MHFGFWC_PLUGIN_DIR . 'includes/class-mhfgfwc-engine.php';
    require_once MHFGFWC_PLUGIN_DIR . 'includes/class-mhfgfwc-frontend.php';

    // Instantiate subsystems
    if ( class_exists( 'MHFGFWC_Admin' ) )    { MHFGFWC_Admin::instance(); }
    if ( class_exists( 'MHFGFWC_Engine' ) )   { MHFGFWC_Engine::instance(); }
    if ( class_exists( 'MHFGFWC_Frontend' ) ) { MHFGFWC_Frontend::instance(); }
}, 20 );
