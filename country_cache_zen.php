<?php
/*
Plugin Name: Country Caching Extension
Plugin URI: http://means.us.com
Description: Makes Country GeoLocation work with ZenCache/Quick Cache 
Author: Andrew Wrigley
Version: 0.9.0
Author URI: http://means.us.com/
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

define('CCZC_SETTINGS_SLUG', 'cczc-cache-settings');   // THIS SHOULD BE DIFFERENT FOR BUILT-IN AND SEPARATE PLUGINS
define('CCZC_PLUGINDIR', plugin_dir_path(__FILE__));
define('ZC_PLUGINDIR', WP_CONTENT_DIR . '/ac-plugins/');
define('CCZC_ADDON_SCRIPT','cca_qc_geoip_plugin.php' );  // we use the same script name as premium version to ensure problem free switch between plugins
define('ZC_ADDON_FILE', ZC_PLUGINDIR . CCZC_ADDON_SCRIPT);

  if (file_exists(ZC_PLUGINDIR)) {
define('ZC_DIREXISTS',TRUE);
  } else { define('ZC_DIREXISTS',FALSE); }

// location of the Maxmind script that returns location country code
define('CCZC_MAXMIND_DIR', CCZC_PLUGINDIR . 'maxmind/');

// 0.7.0
//  a number of plugins share Maxmind data
  if (!defined('CCA_MAXMIND_DATA_DIR'))
define('CCA_MAXMIND_DATA_DIR', WP_CONTENT_DIR . '/cca_maxmind_data/');
// 0.7.0 end


add_action( 'admin_init', 'cczc_version_mangement' );
function cczc_version_mangement(){  // credit to "thenbrent" www.wpaustralia.org/wordpress-forums/topic/update-plugin-hook/
	$plugin_info = get_plugin_data( __FILE__ , false, false );
	$last_script_ver = get_option('CCZC_VERSION');
	if (empty($last_script_ver)):
	  // its a new install
	  update_option('CCZC_VERSION', $plugin_info['Version']);
  else:
	   $new_ver = $plugin_info['Version'];
	   $version_status = version_compare( $new_ver , $last_script_ver );
    // can test if script is later {1}, or earlier {-1} than the previous installed e.g. if ($version_status > 0 &&  version_compare( "0.6.3" , $last_script_ver )  > 0) :
		if ($version_status != 0):
		  // this flag ensures the activation function is run on plugin upgrade,
		  update_option('CCZC_VERSION_UPDATE', true);
		endif;
    update_option('CCZC_VERSION', $new_ver);
	endif;
}


if( is_admin() ):
  define('CCZC_CALLING_SCRIPT', __FILE__);
  include_once(CCZC_PLUGINDIR . 'inc/cczc_settings_form.php');
endif;


//=======================================
// FOR EXTERNAL CCA PLUGIN ACTION & FILTER HOOKS

// if country caching is enabled return FALSE to override "disable geoip" option in CCA plugin  

//  NOT NEEDED AS OF CCA V0.9.0
/*
add_filter( 'cca_disable_geoip', 'cca_disable_geoip_cache' );
function cca_disable_geoip_cache( $current=FALSE ) {
  	$cczc_options = get_option( 'cczc_caching_options' );
  	if (! $cczc_options) return $current;
  	if ( empty($cczc_options['caching_mode']) || $cczc_options['caching_mode'] == 'none' ) return TRUE;
  	return FALSE;
}
*/
//=======================================