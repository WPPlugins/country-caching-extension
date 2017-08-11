<?php
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) exit();

delete_option('CCZC_VERSION');
delete_option('CCZC_DO_UPDATE');

// done by deactivate but no harm in making sure:
if (defined('CCZC_ADDON_SCRIPT') ) :
  if (defined('CCZC_PLUGINDIR') && validate_file(CCZC_PLUGINDIR) === 0) @unlink(CCZC_PLUGINDIR . CCZC_ADDON_SCRIPT);
endif;

delete_option('cczc_caching_options');