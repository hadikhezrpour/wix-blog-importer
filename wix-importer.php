<?php
/**
 * Plugin Name: Wix Blog Importer
 * Plugin URI:  https://mannapottery.com
 * Description: Crawls a Wix RSS/blog feed and imports all posts (with images) into WordPress as drafts.
 * Version:     1.2.0
 * Author:      Hadi Khezrpour
 * Author URI:  https://mannapottery.com
 * License:     GPL-2.0+
 * Text Domain: wix-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MWI_VERSION',    '1.2.0' );
define( 'MWI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MWI_PLUGIN_DIR . 'includes/class-mwi-admin.php';
require_once MWI_PLUGIN_DIR . 'includes/class-mwi-importer.php';
require_once MWI_PLUGIN_DIR . 'includes/class-mwi-ajax.php';

/**
 * Bootstrap the plugin.
 */
function mwi_init() {
    new MWI_Admin();
    new MWI_Ajax();
}
add_action( 'plugins_loaded', 'mwi_init' );
