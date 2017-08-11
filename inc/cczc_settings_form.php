<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

$cc_networkadmin = is_network_admin() ? 'network_admin_' : '';
add_filter( $cc_networkadmin . 'plugin_action_links_' . plugin_basename( CCZC_CALLING_SCRIPT ), 'cczc_add_sitesettings_link' );
function cczc_add_sitesettings_link( $links ) {
	if (is_multisite()):
	   $admin_suffix = 'network/admin.php?page=' . CCZC_SETTINGS_SLUG;
	else:
	   $admin_suffix = 'admin.php?page=' . CCZC_SETTINGS_SLUG;
	endif;
	return array_merge(	array('settings' => '<a href="' . admin_url($admin_suffix) . '">Caching Settings</a>'),	$links	);
}


// ensure CSS for dashboard forms is sent to browser
add_action('admin_enqueue_scripts', 'AW_CC_load_admincssjs');
function AW_CC_load_admincssjs() {
    if( (! wp_script_is( 'cca-textwidget-style', 'enqueued' )) && $GLOBALS['pagenow'] == 'admin.php' ): wp_enqueue_style( 'cca-textwidget-style', plugins_url( 'css/cca-textwidget.css' , __FILE__ ) ); endif;
}

function cc_admin_notices_action() {  // unlike add_options_page when using add_menu_page the settings api does not automatically display these messages
    settings_errors( 'geoip_group' );
}
if (is_multisite()):
  add_action( 'network_admin_notices', 'cc_admin_notices_action' );
else:
  add_action( 'admin_notices', 'cc_admin_notices_action' );
endif;

function cczc_return_permissions($item) {
   clearstatcache(true, $item);
   $item_perms = @fileperms($item);
return empty($item_perms) ? '' : substr(sprintf('%o', $item_perms), -4);	
}


// instantiate instance of CCZCcountryCache
if( is_admin() ) {
  $cczc_settings_page = new CCZCcountryCache();
}


//======================
class CCZCcountryCache {  // everything below this point this class
//======================
  private $initial_option_values = array(
	  'activation_status' => 'new',
		'caching_mode' => 'none',
		'cache_iso_cc' => '',
		'use_group' => FALSE,
		'my_ccgroup' => "BE,BG,CZ,DK,DE,EE,IE,GR,ES,FR,HR,IT,CY,LV,LT,LU,HU,MT,NL,AT,PL,PT,RO,SI,SK,FI,SE,GB",
		'diagnostics' => FALSE,
		'initial_message'=> ''
	);

	public $options = array();
  public $user_type;
  public $submit_action;
	public $maxmind_status = array();
  public $is_plugin_update = FALSE;
  public function __construct() {

	  // ******  element no longer used, remove this unset a few versions post 0.7.0  ******
    unset($this->options['cca_maxmind_dir']);

	  register_activation_hook(CCZC_CALLING_SCRIPT, array( $this, 'CCZC_activate' ) );
		register_deactivation_hook(CCZC_CALLING_SCRIPT, array( $this, 'CCZC_deactivate'));
		// the activation hook does not fire on plugin update, flag it, so we can call it below
		$this->is_plugin_update = get_option( 'CCZC_VERSION_UPDATE' );

// 0.7.0 Maxmind data is used by a variety of plugins so we now store its location etc in an option
    $this->maxmind_status = get_option('cc_maxmind_status' , array());

		if (empty($this->options)):
		  $this->options = $this->initial_option_values;
		endif;

		// retreive/build CC plugin settings
  	if ( get_option ( 'cczc_caching_options' ) ) :
  	  $this->options = get_option ( 'cczc_caching_options' );
		  if (empty($this->options['caching_mode'])) : $this->options['caching_mode'] = 'none'; endif;
      if (empty($this->options['cca_maxmind_data_dir']) ):  $this->options['cca_maxmind_data_dir'] = CCA_MAXMIND_DATA_DIR; endif;
  	endif;
		update_option( 'cczc_caching_options', $this->options );

//         whenever there is a plugin upgrade we want to check the sanity of existing Maxmind data and rebuild the Zen Cache add-on script as there may be logic changes 
// 0.7.0 we don't want the user to have to manually re-install Maxmind data if there is a change on plugin update
     if ($this->is_plugin_update || $this->options['cca_maxmind_data_dir'] != CCA_MAXMIND_DATA_DIR):
				$this->options['cca_maxmind_data_dir'] = CCA_MAXMIND_DATA_DIR;
        $this->CCZC_activate();
     endif;
//  0.7.0 END

    if (is_multisite() ) :
     	 $this->user_type = 'manage_network_options';
       add_action( 'network_admin_menu', array( $this, 'add_plugin_page' ) ); 
    	$this->submit_action = "../options.php";
    else:
    	$this->user_type = 'manage_options';
      add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
    	$this->submit_action = "options.php";
    endif;
    add_action( 'admin_init', array( $this, 'page_init' ) );

  }  // end "constructor"


	// REMOVE THE ZEN CACHE EXTENSION SCRIPT ON DEACTIVATION
  public function CCZC_deactivate()   {
		// brute force delete add-on script from all possible known locations
    if (defined('ZC_PLUGINDIR') && validate_file(ZC_PLUGINDIR) === 0) @unlink(ZC_ADDON_FILE);
		$this->options['activation_status'] = 'deactivated';
    update_option( 'cczc_caching_options', $this->options );
  }


	public function CCZC_activate() {

// 0.7.0 ensure sanity of GeoIP on re-activation or plugin update ( a number of plugins share/update the data files)
    $cca_ipv4_file = CCA_MAXMIND_DATA_DIR . 'GeoIP.dat';
    $cca_ipv6_file = CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat';
		if ( $this->is_plugin_update || ( $this->options['caching_mode'] == 'QuickCache' && ( ! file_exists($cca_ipv4_file) || ! file_exists($cca_ipv6_file) || @filesize($cca_ipv4_file) < 131072 || @filesize($cca_ipv6_file) < 131072 ) ) ) :
        // caching was enabled before deactivation, rebuild Maxmind directory if in error or location has changed
        //  if plugin update, then the user may not open the settings form and see error messages
        if ( ! $this->save_maxmind_data($this->is_plugin_update) ):			 // if method argument is true then email will be sent on failure
				   $this->options['initial_message'] =  $this->maxmind_status['result_msg'];  // display error/warning msg when user opens settings form
				endif;
				update_option( 'cc_maxmind_status', $this->maxmind_status );
    endif;
// 0.7.0 end

	  // The add-on script used by ZC/QC is removed on deactivation so we need to rebuild it from stored settings,
		// we also rebuild it on plugin upgrade in case of logic changes
    if ( $this->options['caching_mode'] == 'QuickCache') :
		    $this->options['activation_status'] = 'activating';
  			if (empty($this->options['use_group'])) : $this->options['use_group'] = FALSE; endif;
				if (empty($this->options['my_ccgroup'])) : $this->options['my_ccgroup'] = "BE,BG,CZ,DK,DE,EE,IE,GR,ES,FR,HR,IT,CY,LV,LT,LU,HU,MT,NL,AT,PL,PT,RO,SI,SK,FI,SE,GB"; endif;
				$script_update_result = $this->cczc_build_script( $this->options['cache_iso_cc'],$this->options['use_group'],$this->options['my_ccgroup']);
  			if ( empty($this->options['last_output_err']) ) :
  			  $script_update_result = $this->cczc_write_script($script_update_result, $this->options['cache_iso_cc'],$this->options['use_group'],$this->options['my_ccgroup']);
  			endif;
    	  if ( $script_update_result != 'Done' ) :
   		     $this->options['initial_message'] =  __('You have reactivated this plugin - however there was a problem rebuilding the Zen Cache add-on script: ') . $script_update_result . ')';
    	  else : 
  			  $this->options['initial_message']  = __('Country Caching is activated, and the add-on script for Zen Cache appears to have been built successfully');
  			endif;
    endif;
    delete_option( 'CCZC_VERSION_UPDATE' );
		$this->options['activation_status'] = 'activated';
		update_option( 'cczc_caching_options', $this->options );
 }



// Add Country Caching options page to Dashboard->Settings
  public function add_plugin_page() {
    add_menu_page(
          'Country Caching Settings', /* html title tag */
          'Country Caching', // title (shown in dash->Settings).
          $this->user_type, // 'manage_options', // min user authority
          CCZC_SETTINGS_SLUG, // page url slug
          array( $this, 'create_cczc_site_admin_page' ),  //  function/method to display settings menu
  				'dashicons-admin-plugins'
    );
  }

// Register and add settings
  public function page_init() {        
    register_setting(
      'geoip_group', // group the field is part of 
    	'cczc_caching_options',  // option prefix to name of field
			array( $this, 'sanitize' )
    );
  }


// callback func specified in add_options_page func
  public function create_cczc_site_admin_page() {

// 0.7.0 if site is not using Cloudflare GeoIP warn if Maxmind data is not installled
		if ( empty($_SERVER["HTTP_CF_IPCOUNTRY"]) && (! file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIP.dat') || ! file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat')) ) :
		  $this->options['initial_message'] .= __('Maxmind "IP to Country look-up" data files need to be installed. They will be installed automatically from Maxmind when you check the "Enable ZC" check box and save your settings. This may take a few seconds.<br />'); 
    endif;
// 0.7.0  END
		  // render the settings form
?>  <div class="wrap cca-cachesettings">  
      <div id="icon-themes" class="icon32"></div> 
      <h2>Country Caching</h2>  
<?php 
    if (!empty($this->options['initial_message'])) echo '<div class="cca-msg">' . $this->options['initial_message'] . '</div>';
    $this->options['initial_message'] = '';
		// determine which tab to display
    $active_tab = isset( $_GET[ 'tab' ] ) ? $_GET[ 'tab' ] : 'QuickCache';
		$override_tab = empty($this->options['override_tab']) ? '' : $this->options['override_tab'];
		if ($override_tab == 'files') :
		  $active_tab = 'files';
		endif;
?>
      <h2 class="nav-tab-wrapper">  
         <a href="?page=<?php echo CCZC_SETTINGS_SLUG ?>&tab=QuickCache" class="nav-tab <?php echo $active_tab == 'QuickCache' ? 'nav-tab-active' : ''; ?>">Comet/ZenCache</a>  
         <a href="?page=<?php echo CCZC_SETTINGS_SLUG ?>&tab=Configuration" class="nav-tab <?php echo $active_tab == 'Configuration' ? 'nav-tab-active' : ''; ?>">Configuration &amp; Support</a>
<?php if ( $active_tab == 'files' || !empty($override_tab) ) : ?>
          <a href="?page=<?php echo CCZC_SETTINGS_SLUG ?>&tab=files" class="nav-tab <?php echo $active_tab == 'files' ? 'nav-tab-active' : ''; ?>">Dir &amp; File Settings</a>  
<?php endif; ?>
      </h2> 
      <form method="post" action="<?php echo $this->submit_action; ?>">  
<?php 
      settings_fields( 'geoip_group' );
  		if( $active_tab == 'Configuration' ) :
   			 $this->render_config_panel();
	 		elseif ( $active_tab == 'files' ) :
			   $this->render_file_panel();
      elseif ($override_tab == 'downloaded'):
      		$this->render_upload_check_panel();
  		 else : $this->render_qc_panel();
  		endif;
?>             
      </form> 
    </div> 
<?php
     update_option( 'cczc_caching_options', $this->options );
  }  // END create_cczc_site_admin_page()


  public function render_qc_panel() {
?>
		<div class="cca-brown"><p><?php echo $this->cczc_qc_status();?></p></div>

    <hr /><h3>Country caching for Comet/Zen Cache (CC)</h3>
		<p><input type="checkbox" id="cczc_use_qc" name="cczc_caching_options[caching_mode]" <?php checked($this->options['caching_mode']=='QuickCache'); ?>><label for="cczc_use_qc">
		 <?php _e('Enable CC Country Caching add-on'); ?></label></p>

  	<h3><?php _e('Minimise country caching overheads'); ?></h3>
		<?php _e('Create separate caches for these country codes ONLY:'); ?>
		<input name="cczc_caching_options[cache_iso_cc]" type="text" value="<?php echo $this->options['cache_iso_cc']; ?>" />
		<i>(<?php _e('e.g.');?> "CA,DE,AU")</i>
		<p><i><?php _e('Example: if you set the field to "CA,DE,AU", cached copies of the page will be created for Canada, Germany, Australia, PLUS the <u>standard</u> cached page for visitors from ANYWHERE ELSE.');?><br />
    <?php _e("If left empty and group cache below is not enabled, then a cached page will be generated for every country from which you've had one or more visitors.");?></i></p>
<h3><?php _e('AND/OR create a single cache for this group of countries'); ?></h3>
<p><input type="checkbox" id="cczc_use_group" name="cczc_caching_options[use_group]" <?php checked(!empty($this->options['use_group']));?>><label for="cczc_use_group">
<?php _e('Check this box to use a single cache for this group of country codes'); ?></label></p>
<?php if (empty($this->options['my_ccgroup'])):
   $this->options['my_ccgroup'] = "BE,BG,CZ,DK,DE,EE,IE,GR,ES,FR,HR,IT,CY,LV,LT,LU,HU,MT,NL,AT,PL,PT,RO,SI,SK,FI,SE,GB";
endif;
?>
<div class="cca-indent20">
  		  <input id="cczc_my_ccgroup" name="cczc_caching_options[my_ccgroup]" type="text" style="width:600px !important" value="<?php echo $this->options['my_ccgroup']; ?>" />
  		  <br><?php _e("Edit this list to create your group country codes.<br>It is initially populated with European Union country codes (but no guarantee it is complete or will be up to date). ");  ?>
</div>
<p><i><?php _e("Example: Your standard (default) page is targeted at the US, you serve modified content to visitors from France and Canada, and display an EU cookie law bar to "); ?>
<?php _e("visitors from the EU ONLY.<br><b>How:</b> set the plugin to separately cache 'FR,CA'.  Visitors form Canada will see custom content, likewise France (including the cookie bar), "); ?>
<?php _e("other EU visitors will be served  the page with cookie bar, and all other visitors will see the standard/default 'US' page."); ?></i></p>
<p><i><?php _e("If you only want 2 separate caches one for Group and one for NOT Group e.g. European visitors and non-European then <u>add a country to the separate cache list above</u>. "); ?>
<?php _e( ' Choose a code from which you are unlikely to receive visitors e.g. AX (Aland Islands).  This will result in one cache for all EU visitors,  and a "default" cache for all other visitors except AX.'); ?> 
<?php _e( ' No pages will be cached for Aland Islands unless your site receives a visitor from there.'); ?></i></p>

		<input type="hidden" id="cczc_geoip_action" name="cczc_caching_options[action]" value="QuickCache" />
    <?php
 // 0.7.0
      if( $this->using_cloudflare_or_max_already() ):
			  _e('<br /><p>This plugin includes GeoLite data created by MaxMind, available from <a href="http://www.maxmind.com">http://www.maxmind.com</a>.</p>');
			endif;
			submit_button('Save Caching Settings','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 
  }  // END render_qc_panel()



// This panel only appears if the user downloaded the generated add-on and is going to manually FTP it to the ZC add-on folder
  public function render_upload_check_panel() {
?>
		<div class="cca-brown"><p><?php
		_e('You have downloaded a copy of the add-on script.<br />To ensure correct settings are maintained, the Country Caching plugin <u>needs to know if you have uploaded it to your server</u>.');		
?></p></div>	
		<p><input type="radio" id="cczc_notdone" name="cczc_caching_options[override_tab]" value="downloaded" checked>
			   <label for="cczc_notdone"><?php _e("Hold on, don't do anything yet, I've not had chance to upload it!!!"); ?></label><br />
				 <input type="radio" id="cczc_notdone" name="cczc_caching_options[override_tab]" value="uploaded">
			   <label for="cczc_notdone"><?php _e("I've uploaded the script. Country Caching should modify its settings to identify use of this new script."); ?></label><br />
				 <input type="radio" id="cczc_notdone" name="cczc_caching_options[override_tab]" value="abandoned">
			   <label for="cczc_notdone"><?php _e("I've decided I am NOT going to upload this particular version of the Script. Keep current settings and display the usual Country Caching settings form"); ?></label><br />
		</p>
		<input type="hidden" id="cczc_geoip_action" name="cczc_caching_options[action]" value="QuickCache" />
<?php
      submit_button('Update Caching Settings','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 
	}  // END render_upload_check_panel()


  // This panel is only visible if the plugin was unable to write the add-on script.
 // it provides diagnostic info + an option to download generated add-on for manual upload
  public function render_file_panel() { 
    if ($this->options['override_tab'] == 'files'):
       $this->options['override_tab']  = 'io_error';
    endif;
	  echo '<p>'  . __('This section is of use if the CC plugin is unable to write the generated ZC add-on script (') .  CCZC_ADDON_SCRIPT . __(') to "') . '<span class="cca-brown">' . ZC_PLUGINDIR . '</span>" (';
    echo __('the folder where ZC expects to find any add-on scripts') . ').</p>';
	  echo '<p>' . __('This problem is usually due to your server\'s Directory and File permissions settings. It can be solved by <b>either</b>:') . '</p><ol>';
	  echo '<li>' . __('changing directory ("wp-content/ac-plugins" +  possibly "wp-content/") permissions. ');
	  echo __('On a shared server appropriate  permissions for these is usually "755"; but on a <b>"dedicated" server</b> "775" might be needed and "664" for the script (if present)') . '.</li>';
	  echo '<li>' . __('<b>or</b>; by <b>clicking the download button</b> below to save ' ) . '"' . CCZC_ADDON_SCRIPT . '" ' . __('to your computer; then using FTP to up-load it to ZC\'s add-on folder') . '"<span class="cca-brown">' . ZC_PLUGINDIR . '</span>"' . __('; you may need to create this folder first ') . '.</li></ol>';
 		echo '<span class="cca-brown">' . __('You should view the Country Caching guide for ') . '<a href="http://wptest.means.us.com/2015/02/quick-cache-and-geoip-enable-caching-by-pagevisitor-country-instead-of-just-page/">' . __('more about these solutions') . '</a> ' . __('and the best for your server') . '.</span>';

    echo '<hr /><h4>' . __('Information about current directory &amp; file permissions') . ':</h4>';

    if (!empty($this->options['last_output_err'])):
        echo '<span class="cca-brown">' . __('Last reported error: ') . ':</span> ' . $this->options['last_output_err'] . '<br />';
    endif;

    echo '<span class="cca-brown">' . __('Directory "wp-content"') . ':</span> ' . __('permissions = ') . cczc_return_permissions(WP_CONTENT_DIR) . '<br />';
    echo '<span class="cca-brown">' . __('Directory "') . ZC_PLUGINDIR . '" :</span> ' . __('permissions = ') . cczc_return_permissions(ZC_PLUGINDIR) . '<br />';
    echo '<span class="cca-brown">Permissions for add-on script "' . CCZC_ADDON_SCRIPT . '": </span>' . cczc_return_permissions(ZC_ADDON_FILE) . '<br />';
		clearstatcache();
    $dir_stat = @stat(WP_CONTENT_DIR);
    if (function_exists('posix_getuid') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')
         && function_exists('posix_getgid') && function_exists('posix_getegid') && $dir_stat) :
      $real_process_uid  = posix_getuid(); 
      $real_process_data =  posix_getpwuid($real_process_uid);
      $real_process_user =  $real_process_data['name'];
    	$real_process_group = posix_getgid();
      $real_process_gdata =  posix_getpwuid($real_process_group);
      $real_process_guser =  $real_process_gdata['name'];	
      $e_process_uid  = posix_geteuid(); 
      $e_process_data =  posix_getpwuid($e_process_uid);
      $e_process_user =  $e_process_data['name'];
    	$e_process_group = posix_getegid();
      $e_process_gdata =  posix_getpwuid($e_process_group);
      $e_process_guser =  $e_process_gdata['name'];	
    	$dir_data =  posix_getpwuid($dir_stat['uid']);
    	$dir_owner = $dir_data['name'];
    	$dir_gdata =  posix_getpwuid($dir_stat['gid']);
    	$dir_group = $dir_gdata['name'];
      echo '<span class="cca-brown">' . __('This plugin is being run by "real user":') . '</span> ' . $real_process_user . ' (UID:' . $real_process_uid . ') Group: ' . $real_process_guser .' (GID:' . $real_process_group . ') N.B. this user may also be a member of other groups.<br>'; 
    	echo '<span class="cca-brown">' . __('The effective user is: ') . '</span>' . $e_process_user . ' (UID:' . $e_process_uid . ' GID:' . posix_getegid() . ')<br>'; 
      echo '<span class="cca-brown">' . __('"wp-content" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br />';
      unset($dir_stat);
      $dir_stat = @stat(ZC_PLUGINDIR);
    	$dir_data =  @posix_getpwuid($dir_stat['uid']);
      if ( $dir_stat ) :
        echo '<span class="cca-brown">' . __('ZC "add-on" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br /><hr />';
    	endif;
    else:
      __('Unable to obtain information on the plugin process owner(user).  Your server might not have the PHP posix extension (installed on the majority of Linux servers) which this plugin uses to get this info.') . '<br /><hr />';
    endif;
// 0.6.2 end
?>
		<input type="hidden" id="cczc_geoip_action" name="cczc_caching_options[action]" value="download" />
<?php
		  echo '<br /><br /><b>' .  __('This download add-on will separately cache') .  ' "<u>';
			if (empty($this->options['override_isocodes']) ):
			 echo  __('all countries') . '</u>".</b>';
			else:
			  echo $this->options['override_isocodes'] . '</u>"; ' ;
				if (! empty($this->options['override_use_group']) ):
				  echo __('These countries:') . $this->options['override_my_ccgroup'] . __(' will share a single cache');
				endif;
				echo  __('the standard cache will be used for all other countries.') . '</b>';
			endif;
      submit_button('Download Add-on Script','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 
  }


// Panel to display Diagnostic Information with option to reset the plugin 
  public function render_config_panel() {
?>
    <p class="cca-brown"><?php _e('View ');?> <a href="http://wptest.means.us.com/2015/02/quick-cache-and-geoip-enable-caching-by-pagevisitor-country-instead-of-just-page/" target="_blank"><?php _e('Country Caching Guide');?></a>.</p>

		<hr /><h3>Problem Fixing</h3>
    <p><input id="cczc_force_reset" name="cczc_caching_options[force_reset]" type="checkbox"/>
    <label for="cczc_force_reset"><?php _e("Reset Country Caching to initial values (also removes the country caching add-on script(s) generated for Comet/ZenCache).");?></label></p><hr />

		<h3>Information about the add-on script being used by Comet/ZenCache:</h3>
		<p><input type="checkbox" id="cczc_addon_info" name="cczc_caching_options[addon_data]" ><label for="cczc_addon_info">
 		  <?php _e('Display script data'); ?></label></p>
<?php
		if ($this->options['addon_data']) :
			$this->options['addon_data'] = '';
			clearstatcache(true, ZC_ADDON_FILE);
			if ( ! file_exists(ZC_ADDON_FILE) ) :
			  echo '<br /><span class="cca-brown">' . __('The Add-on script does not exist.') . '</span><br>';
			else:		
  			 include_once(ZC_ADDON_FILE);
  			 if ( function_exists('cca_qc_salt_shaker') ):
				    $add_on_ver = cca_qc_salt_shaker('cca_version');
						echo '<span class="cca-brown">' . __('Add-on script version: ') . '</span>' . esc_html($add_on_ver) . '<br>';
  				  $new_codes = cca_qc_salt_shaker('cca_options');
						$valid_codes = $this->is_valid_ISO_list($new_codes);
            if ($valid_codes):
          		  echo '<span class="cca-brown">' . __('The script is set to separately cache') .  '</span> "<u>';
          			if (empty($new_codes) ):
          			 echo  __('all countries') . '</u>".<br />';
          			else:
          			  echo $new_codes . '</u>"; ' .  __('the standard cache will be used for all other countries.') . '<br />';
          			endif;
            elseif (substr($new_codes, 0, 11) == 'cca_options') :  // addons created by previous plugin versions do not recognise 'options' and will simply return a string starting with 'options'
					    echo  __('The add-on script was created by a previous version of the Country Caching plugin.<br />It will work, but the latest version will show you (here) which Countries it is set to cache') . '<br />';
							echo __('You can update to the latest add-on script by saving settings again on the "Comet/ZenCache" tab.<br />');
					  else:
					    echo  __('Add-on script "') . CCZC_ADDON_SCRIPT . __(' is present in "') . ZC_PLUGINDIR . __('" but has an INVALID Country Code List (values: "') . esc_html($new_codes) . __('") and should be deleted.') . '<br /';
  				  endif;
					else:
					  echo  __('The add-on script "') . CCZC_ADDON_SCRIPT . __(' is present in "') . ZC_PLUGINDIR . __('" but I am unable to identify its country settings.')  . '<br />';
					endif;


$new_codes = cca_qc_salt_shaker('cca_group');
if (! empty($new_codes) ):
	 if (substr($new_codes, 0,9 ) == 'cca_group') :  // addons created by previous plugin versions do not recognise 'options' and will simply return a string starting with 'options'
					    echo  __('The add-on script was created by a previous version of the Country Caching plugin.<br />It will work, but the latest version allows you to cache countries as a group') . '<br />';
							echo __('You can update to the latest add-on script by saving settings again on the "Comet/ZenCache" tab.<br />');
	 elseif ($this->is_valid_ISO_list($new_codes)):
	    echo '<span class="cca-brown">' . __('The script is set to create a single cache for this group of countries:') .  '</span> ' . $new_codes . '<br>';
   endif;
endif;




					$max_dir = cca_qc_salt_shaker('cca_data');
					if ($max_dir != 'cca_data'):
					  echo __('The script looks for Maxmind data in "') . esc_html($max_dir) . '".<br />';
					endif;
			 endif;
		endif;
// 0.7.0
?>
		<h3>GeoIP Information and Status:</h3>
		<p><input type="checkbox" id="cczc_geoip_info" name="cczc_caching_options[geoip_data]" ><label for="cczc_geoip_info">
 		  <?php _e('Display GeoIP data'); ?></label></p>
<?php
		if ($this->options['geoip_data']) :
			 $this->options['geoip_data'] = '';
    	 if (! empty($_SERVER["HTTP_CF_IPCOUNTRY"]) ) :
			    echo '<br /><span class="cca-brown">' . __('It looks like Cloudflare is being used for GeoIP.') . '</span><br>';
			 endif;
 			 echo '<br /><b>' . __('Maxmind Status recorded by plugin') . ':</b><br />Directory: <span class="cca-brown">' . CCA_MAXMIND_DATA_DIR . '</span><br />';
			 if (! empty($this->maxmind_status) && ! empty($this->maxmind_status['health'])) :
			 	  if ($this->maxmind_status['health'] == 'fail'):
				    echo __('Maxmind may not be working; error recorded= ') . $this->maxmind_status['result_msg']  . '<br />';
				  elseif ($this->maxmind_status['health'] == 'warn'):
				     echo __('The plugin identified an error on last up date but GeoIP is probably still working; error recorded= ') . $this->maxmind_status['result_msg'] . '<br />';
				  endif;
			    echo   __('Files last updated: ') .' <span class="cca-brown">(IPv4 data) ' . date('j M Y Hi e', $this->maxmind_status['ipv4_file_date']) .  ' &nbsp; ';
				  echo __(" (IPv6 data) ") . date('j M Y Hi e', $this->maxmind_status['ipv6_file_date']) . '</span><br />';
			else:
			   echo __("The plugin has not stored information on current state of Maxmind files (if you haven't already enabled Country Caching this is to be expected") . '<br />';
			endif;
			clearstatcache();
      echo __('On Checking files right now:') .  '<br /><span class="cca-brown">"GeoIP.dat" ';
			if ( file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIP.dat') ) :
				 _e('was present, and  ');
			else:
				 _e('could not be found, and ');
			endif;
			if ( file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat') ) :
				 echo __('"GeoIPv6.dat" was present, in the Maxmind directory.') . '</span><br>';
			else:
				 echo  __('"GeoIPv6.dat" could not be found, in the Maxmind directory.') . '</span><br>';
			endif;

		endif;
// 0.7.0 end
?>

		<h3>Information useful for support requests:</h3>
		<p><input type="checkbox" id="cczc_diagnostics" name="cczc_caching_options[diagnostics]" ><label for="cczc_diagnostics">
 		  <?php _e('List plugin values'); ?></label></p>
<?php
		if ($this->options['diagnostics']) :
			$this->options['diagnostics'] = '';
		  echo '<br /><span class="cca-brown">This plugin version: </span>' . get_option('CCZC_VERSION') . '<br>';
		  echo '<h4>Comet/Zen Cache Status:</h4>';
			echo '<div class="cca-brown">' . $this->cczc_qc_status() . '</div>';
			echo '<i>' . __("(any reference to 'submit button' refers to the button at the bottom of the Comet/Zen Cache tab)") . '</i>';
 
      echo '<h4>Constants:</h4>';
      echo '<span class="cca-brown">ZC_PLUGINDIR = </span>'; echo defined('ZC_PLUGINDIR') ? ZC_PLUGINDIR : 'not defined';
      echo '<br /><span class="cca-brown">ZC_DIREXISTS = </span>'; echo (defined('ZC_DIREXISTS') && ZC_DIREXISTS) ? 'TRUE' : 'FALSE';
      echo '<br /><span class="cca-brown">CCZC_ADDON_SCRIPT = </span>'; echo defined('CCZC_ADDON_SCRIPT') ? CCZC_ADDON_SCRIPT : 'not defined';

      echo '<h4>Variables:</h4>';
		  $esc_options = esc_html(print_r($this->options, TRUE ));  // option values from memory there is a slim chance stored values will differ
		  echo '<span class="cca-brown">' . __("Current setting values") . ':</span>' . str_replace ( '[' , '<br /> [' , print_r($esc_options, TRUE )) . '</p>';
// 0.7.0 display maxmind_status option
			echo '<hr /><h4>Maxmind Data status:</h4>';
		  $esc_options = esc_html(print_r($this->maxmind_status, TRUE ));  // option values from memory there is a slim chance stored values will differ
		  echo '<span class="cca-brown">' . __("Current values") . ':</span>' . str_replace ( '[' , '<br /> [' , print_r($esc_options, TRUE )) . '</p>';
// 0.7.0 end
      echo '<h4>' . __('File and Directory Permissions') . ':</h4>';
      echo '<span class="cca-brown">' . __('Last file/directory error":') . '</span> ' . $this->options['last_output_err'] . '<br>';
			clearstatcache();
      $dir_stat = @stat(WP_CONTENT_DIR);
      if (function_exists('posix_getuid') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')
           && function_exists('posix_getgid') && function_exists('posix_getegid') && $dir_stat) :
        $real_process_uid  = posix_getuid(); 
        $real_process_data =  posix_getpwuid($real_process_uid);
        $real_process_user =  $real_process_data['name'];
      	$real_process_group = posix_getgid();
        $real_process_gdata =  posix_getpwuid($real_process_group);
        $real_process_guser =  $real_process_gdata['name'];	
        $e_process_uid  = posix_geteuid(); 
        $e_process_data =  posix_getpwuid($e_process_uid);
        $e_process_user =  $e_process_data['name'];
      	$e_process_group = posix_getegid();
        $e_process_gdata =  posix_getpwuid($e_process_group);
        $e_process_guser =  $e_process_gdata['name'];	
      	$dir_data =  posix_getpwuid($dir_stat['uid']);
      	$dir_owner = $dir_data['name'];
      	$dir_gdata =  posix_getpwuid($dir_stat['gid']);
      	$dir_group = $dir_gdata['name'];
        echo '<span class="cca-brown">' . __('This plugin is being run by "real user":') . '</span> ' . $real_process_user . ' (UID:' . $real_process_uid . ') Group: ' . $real_process_guser .' (GID:' . $real_process_group . ') N.B. this user may also be a member of other groups.<br>'; 
      	echo '<span class="cca-brown">' . __('The effective user is: ') . '</span>' . $e_process_user . ' (UID:' . $e_process_uid . ' GID:' . posix_getegid() . ')<br>'; 
        echo '<span class="cca-brown">' . __('"wp-content" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br />';
        unset($dir_stat);
        $dir_stat = @stat(ZC_PLUGINDIR);
      	$dir_data =  @posix_getpwuid($dir_stat['uid']);
        if ( $dir_stat ) :
          echo '<span class="cca-brown">' . __('ZC "add-on" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br />';
      	endif;
      else:
        __('Unable to obtain information on the plugin process owner(user).  Your server might not have the PHP posix extension (installed on the majority of Linux servers) which this plugin uses to get this info.') . '<br />';
      endif; 
      echo '<span class="cca-brown">' . __('"wp-content" folder permissions: </span>') . cczc_return_permissions(WP_CONTENT_DIR) . '<br />';
      echo '<span class="cca-brown">' . __('"ZC add-on\'s folder" ') . ZC_PLUGINDIR . __(' permissions') .'</span>: ' . cczc_return_permissions(ZC_PLUGINDIR) . '<br />';
      echo '<span class="cca-brown">Permissions for add-on script "' . CCZC_ADDON_SCRIPT . '": </span>' . cczc_return_permissions(ZC_ADDON_FILE);

		endif;
?>
		<input type="hidden" id="cczc_geoip_action" name="cczc_caching_options[action]" value="Configuration" />
<?php
      submit_button('Submit','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 
 
  }   // END render_config_panel()


  // validate and save settings fields changes
  public function sanitize( $input ) {
  	$input['action'] = empty($input['action']) ? '' : strip_tags($input['action']);
  
    // the user has requested download of the add-on script
    if ( $input['action'] == 'download'):
  	  $this->options['override_tab'] = 'downloaded';
      if ($this->options['override_isocodes'] != $this->options['cache_iso_cc'] || $this->options['override_use_group'] != $this->options['use_group'] || $this->options['override_my_ccgroup'] != $this->options['my_ccgroup']):
  		  $this->options['initial_message']  = __('IMPORTANT! You have downloaded the add-on script, you must use the "<i>Comet/ZenCache</i>" tab to inform this plugin of the action you have taken.');
  		endif;
      $addon_script = $this->cczc_build_script($this->options['override_isocodes'],$this->options['override_use_group'], $this->options['override_my_ccgroup'] );
 		  update_option( 'cczc_caching_options', $this->options );
      header("Pragma: public");
      header("Expires: 0");
      header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
      header("Cache-Control: private", false);
      header("Content-Type: application/octet-stream");
      header('Content-Disposition: attachment; filename="' . CCZC_ADDON_SCRIPT . ";" );
      header("Content-Transfer-Encoding: binary");
      echo $addon_script;
      exit;
    endif;
  
  	// INITIALISE MESSAGES
  	$settings_msg = '';
  	$msg_type = 'updated';
     $delete_result = '';

  	// process input from special settings form that only appears after a user saves add-on script to their local machine
    if (isset($input['override_tab'] )):

  		if ( $input['override_tab'] == 'downloaded'):  // user has submitted settings, but nothing has changed since script was downloaded
  		  return $this->options;
      endif;
			if ( $input['override_tab'] == 'abandoned'):  // user has opted not to use the downloaded script - return settings for standard mode
    	  $this->options['override_tab'] = 'io_error';
    		$this->options['override_isocodes'] = $this->options['cache_iso_cc'];
				$this->options['override_use_group'] = empty($this->options['use_group'] ) ? FALSE : TRUE;
				if (empty($this->options['my_ccgroup'])): $this->options['my_ccgroup'] = "BE,BG,CZ,DK,DE,EE,IE,GR,ES,FR,HR,IT,CY,LV,LT,LU,HU,MT,NL,AT,PL,PT,RO,SI,SK,FI,SE,GB";endif;
				$this->options['override_my_ccgroup'] = $this->options['my_ccgroup'];
      	return $this->options;
      endif;

			if ( $input['override_tab'] == 'uploaded'):  // user has uploaded the saved script - adjust plugin settings to reflect its use
  		  clearstatcache();
  			if ( file_exists(ZC_ADDON_FILE) ):
  			  include_once(ZC_ADDON_FILE);
  				if ( function_exists('cca_qc_salt_shaker') ):
  				  $new_codes = cca_qc_salt_shaker('cca_options');
						$new_group = cca_qc_salt_shaker('cca_group');
						$valid_codes = $this->is_valid_ISO_list($new_codes);
// 0.9.0
#            if ($valid_codes): $valid_codes = $this->is_valid_ISO_list($new_group); endif;
// 0.9.0
						if ($valid_codes):
  					  $this->options['cache_iso_cc'] = $new_codes;
// 0.9.0
#$this->options['cache_iso_cc'] = $new_group;
// 0.9.0 end
  						$this->options['caching_mode'] = 'QuickCache';
							unset($this->options['override_tab']);
              add_settings_error('geoip_group', esc_attr( 'settings_updated' ), 	__('Settings have been updated.'),	'updated'	);
  					  return $this->options;
            elseif (substr($new_codes, 0, 11) == 'cca_options') :  // addons created by previous plugin versions do not recognise 'options' and will simply return a string starting with 'options'
					    $settings_msg = __('Directory "') . ZC_PLUGINDIR . __('" still contains an OLD version of "') . CCZC_ADDON_SCRIPT . '".';
					  else:
					    $settings_msg = __('Add-on script "') . CCZC_ADDON_SCRIPT . __(' is present in "') . ZC_PLUGINDIR . __('" but has an INVALID Country Code List (values: "') . esc_html($new_codes) . __('") and should be deleted.');
  				  endif;
					else:
					  $settings_msg = __('The add-on script "') . CCZC_ADDON_SCRIPT . __(' is present in "') . ZC_PLUGINDIR . __('" but I am unable to identify its settings.') ;
					endif;
  			else:
				  $settings_msg = __('The add-on script "') . CCZC_ADDON_SCRIPT . __(' still DOES NOT EXIST in directory "') . ZC_PLUGINDIR . '".';
  			endif;

				$msg_type = 'error';
    		if ($settings_msg != '') :
          add_settings_error('geoip_group', esc_attr( 'settings_updated' ), $settings_msg,	$msg_type	);
        endif;
      	return $this->options;
  		endif;

    endif;  //  END special form input

    if ($this->options['activation_status'] != 'activated'): return $this->options; endif;  // activation hook carries out its own "sanitizing"

		// check if ZC or Quickcache plugin is activated
// 0.9.0
//    if ($input['action'] == 'QuickCache' && ! empty($input['caching_mode']) && ! class_exists('\\quick_cache\\plugin')   && ! class_exists('\\zencache\\plugin')) :
    if ($input['action'] == 'QuickCache' && ! empty($input['caching_mode']) && empty($GLOBALS['comet_cache_advanced_cache'])  && empty($GLOBALS['zencache_advanced_cache']) ) :
// 0.9.0 end
	add_settings_error('geoip_group',esc_attr( 'settings_updated' ),
       		__("ERROR: Comet/ZenCache plugin folder (") . ZC_PLUGINDIR . __(" ) could not be found. Either Comet/ZenCache is not installed or there is a problem with it's activation.<br />NOT SAVED - you will have to re-enter your changes."),
        		'error'
       	);
       	return $this->options;
    endif;


//  PROCESS INPUT FROM  "FILES" TAB
		if ($input['action'] == 'files'):  return $this->options; endif;


//  PROCESS INPUT FROM  "MONITORING" TAB
    if ($input['action'] == 'Configuration') :
		  $this->options['diagnostics'] = empty($input['diagnostics']) ? FALSE : TRUE;
		  $this->options['addon_data'] = empty($input['addon_data']) ? FALSE : TRUE;
		  $this->options['geoip_data'] = empty($input['geoip_data']) ? FALSE : TRUE;
			if (! empty($input['force_reset']) ) :
			  update_option('cczc_caching_options',$this->initial_option_values);
				$this->options = $this->initial_option_values;
				$this->options['activation_status'] = 'activated';
		    $delete_result = $this->delete_qc_addon();	
  		  if ($delete_result != ''):
  				$msg_type = 'error';
					$settings_msg = $delete_result;
  			else:
			    $this->options['caching_mode'] = 'none';
					$settings_msg = __('Country caching has been reset to none.<br />');
  				$this->options['cache_iso_cc'] = '';
  			endif;
			endif;
  		if ($settings_msg != '') :
        add_settings_error('geoip_group',esc_attr( 'settings_updated' ), __($settings_msg),	$msg_type	);
      endif;
  		return $this->options;
    endif;


//  RETURN IF INPUT IS NOT FROM "QuickCache" TAB (The QC tab should be the only one not sanitized at this point).
    if ($input['action'] != 'QuickCache'): return $this->options; endif;


//  PROCESS INPUT FROM "QuickCache" TAB

		$cache_iso_cc = empty($input['cache_iso_cc']) ? '' : strtoupper(trim($input['cache_iso_cc']));

$use_group = empty($input['use_group'] ) ? FALSE : TRUE;
$my_ccgroup = empty($input['my_ccgroup']) ? '' : strtoupper(trim($input['my_ccgroup']));

		$new_mode = empty($input['caching_mode']) ? 'none' : 'QuickCache';

		// user is not enabling country caching and it wasn't previously enabled
		if ( $new_mode == 'none' && $this->options['caching_mode'] == 'none') :

		  if ( $this->options['cache_iso_cc'] != $cache_iso_cc || $this->options['my_ccgroup'] != $my_ccgroup || $this->options['use_group'] != $use_group && $this->is_valid_ISO_list($cache_iso_cc) && $this->is_valid_ISO_list($my_ccgroup)) :
			  $this->options['cache_iso_cc'] = $cache_iso_cc;
        $this->options['my_ccgroup'] = $my_ccgroup;
        $this->options['use_group'] = $use_group;
        $settings_msg = __("The Country Codes list has been updated; HOWEVER you have NOT ENABLED country caching.") .  '.<br />';
			else :
        $settings_msg .= __("Settings have not changed - country caching is NOT enabled.<br />");
			endif;
			$settings_msg .= __("I'll take this opportunity to housekeep and remove any orphan country caching scripts. ");
		  $settings_msg .= $this->delete_qc_addon();
			add_settings_error('geoip_group',esc_attr( 'settings_updated' ), $settings_msg, 'error' );
      return $this->options;	  
		endif;
			
		$msg_part = '';
		// user is changing to OPTION "NONE" we are disabling country caching and need to remove the QC add-on script
    if ($new_mode == 'none') :
		  $delete_result = $this->delete_qc_addon();	
		  if ($delete_result != ''):
				$msg_type = 'error';
			  $msg_part = $delete_result;
			else:
			  $msg_part = __('Country caching has been disabled.<br />');
        if ( $this->options['cache_iso_cc'] != $cache_iso_cc && $this->is_valid_ISO_list($cache_iso_cc) ) :
        		$this->options['cache_iso_cc'] = $cache_iso_cc;
        endif;
        if ($this->options['my_ccgroup'] != $my_ccgroup && $this->is_valid_ISO_list($my_ccgroup) ) :
          $this->options['my_ccgroup'] = $my_ccgroup;
        endif;
        $this->options['use_group'] = $use_group;
				$this->options['caching_mode'] = 'none';
			endif;
			$settings_msg = $msg_part . $settings_msg;

		// else using ZEN/QUICK CACHE
    elseif ( $new_mode == 'QuickCache'  && (! $this->is_valid_ISO_list($cache_iso_cc) || ! $this->is_valid_ISO_list($my_ccgroup))):
					$settings_msg .= __('WARNING: Settings have NOT been changed; your Country Code List or Group entry was is invalid (list must be empty or contain 2 character alphabetic codes separated by commas).<br />');
					$msg_type = 'error';

  	elseif ( $new_mode == 'QuickCache') :  // and country code list is valid
  			$script_update_result = $this->cczc_build_script( $cache_iso_cc, $use_group, $my_ccgroup );
  			if ( empty($this->options['last_output_err']) ) :
  			  $script_update_result = $this->cczc_write_script($script_update_result, $cache_iso_cc, $use_group, $my_ccgroup);
  			endif;
  			if ($script_update_result == 'Done') :
  			  $this->options['cache_iso_cc'] = $cache_iso_cc;
          $this->options['use_group'] = $use_group;
          $this->options['my_ccgroup'] = $my_ccgroup;
  				$this->options['caching_mode'] = 'QuickCache';
  				$msg_part = __("Settings have been updated and country caching is enabled for Comet/Zen Cache. Don't forget to CLEAR THE CACHE.<br />"); 
  				$settings_msg = $msg_part . $settings_msg;
  			else:
  				$msg_type = 'error';
  			  $settings_msg .= $script_update_result . '<br />';
  			endif;
    endif;

//0.7.0
		// Zen Cache has been enabled; ensure Maxmind files are installed
		if ( ! file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIP.dat') || ! file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat') ) :
		  if ( ! $this->save_maxmind_data() ) :
			  $settings_msg = $settings_msg . '<br />' . $this->maxmind_status['result_msg'];
				$msg_type = 'error';
			endif;
		endif;
// 0.7.0 end
		if ($settings_msg != '') :
      add_settings_error('geoip_group',esc_attr( 'settings_updated' ), __($settings_msg),	$msg_type	);
    endif;
		return $this->options;

  }   // END santize func


  function delete_qc_addon() {
    if ( ZC_DIREXISTS && ! $this->remove_addon_file(ZC_ADDON_FILE) ) :
  	 return __('Warning: I was unable to remove the old country caching addon script(s): "') . $file . __('". You will have to delete this file yourself.<br />');
  	endif;
    return '';
  }
  
  function cczc_qc_status() {
// 0.7.0
    if (! empty($_SERVER["HTTP_CF_IPCOUNTRY"]) ) :
	     $geoip_used = __('Cloudflare data is being used for GeoIP	');
		elseif ($this->maxmind_status['health'] == 'fail') :
		   $geoip_used = __('There is a problem with GeoIP. Check GeoIP info on the Cofiguration tab.');
		else:
		   $geoip_used = '';
		endif;
// 0.9.0
//  	if( class_exists('\\quick_cache\\plugin') || class_exists('\\zencache\\plugin')) : 
  	if( class_exists('\\zencache\\plugin') || !empty($GLOBALS['zencache_advanced_cache']) || !empty($GLOBALS['comet_cache_advanced_cache']) ) : 
// 0.9.0 end
	    if ($this->options['caching_mode'] == 'QuickCache'):
    		if (empty($this->options['cache_iso_cc'])) :
    		  $opto = __("<br />To fully optimize performance you should limit the countries that are individually cached.");
    		 else: $opto = '';
  			endif;
  	    if ( file_exists(ZC_ADDON_FILE) ) :
 				  $qc_status = __("Your site is using Comet/Zen Cache and country caching is enabled.<br />");
 				  $qc_status .= $opto . $geoip_used;
  			else:
  				$qc_status = $geoip_used . '<br />' . __("Your site is using Comet/Zen Cache and you have enabled country caching. However something has gone wrong and the relevant add-on script cannot be found in ZC's plugin folder.<br />");
  				$qc_status .= __("Clicking the submit button below might hopefully cause the script to be regenerated and fix the problem<br />");
  				$qc_status .= $opto ;
  			endif;
  		else:
   			$qc_status = $geoip_used . '<br />' . __("It looks like your site is using Comet/Zen Cache, but you have not enabled country caching.");
    		if ( file_exists(ZC_ADDON_FILE) ) :
    			$qc_status = __("Something went wrong; although not 'enabled', the country caching addon script was found in ZC's plugins directory and may still be running.<br />");
    			$qc_status .= __("Clicking the Submit button below might result in the add-on being deleted and resolve this problem.");
    		endif;
  		endif;
  	else:
  	  $qc_status = __("Your site does not appear to be using Comet/Zen Cache, or it has been deactivated.");
			if ($this->options['caching_mode'] == 'QuickCache'):
 			  $qc_status .= '<br />' . __('You should either activate ZC/QC or ensure the country caching extension script is disabled by unchecking "Enable ZC/QC Country Caching add-on" and save settings.');
			endif;				
    endif;
  	return $qc_status;
  }   // END  cczc_qc_status()


  // build the script that ZC/QC will use to cache by page + country
  function cczc_build_script( $country_codes, $use_group, $my_ccgroup) {

// 0.9.0
//	  if( $this->options['activation_status'] != 'activating' && ! class_exists('\\quick_cache\\plugin') && ! class_exists('\\zencache\\plugin')):
	  if( $this->options['activation_status'] != 'activating' && ! class_exists('\\zencache\\plugin') && empty($GLOBALS['zencache_advanced_cache']) && empty($GLOBALS['comet_cache_advanced_cache']) ):
// 0.9.0 end
		  $this->options['last_output_err']  = '* ' . __("Country caching script has NOT been created. The Comet/Zen Cache plugin doesn't appear to be running on your site (maybe you have de-activated it, or it isn't installed).");
		  return $this->options['last_output_err'];
		endif;
		
    $template_script = CCZC_PLUGINDIR . 'caching_plugins/' . CCZC_ADDON_SCRIPT;
    $file_string = @file_get_contents(  CCZC_PLUGINDIR . 'caching_plugins/' . CCZC_ADDON_SCRIPT); // $this->options['qc_addon_script']
		if (empty($file_string)) : 
			if ( file_exists( $template_script ) ):
			  $this->options['last_output_err']  =  '*' . __('Error: unable to read the template script ("') . $template_script . __('") used to build or alter the plugin for Comet/Zen cache');		
				return $this->options['last_output_err'];
		  else:
			  $this->options['last_output_err']  = '*' . __('Error: it looks like the template script ("') . $template_script . __('") needed to build or alter the add-on to Comet/Zen cache has been deleted.');
				return $this->options['last_output_err'];
      endif;
		endif;
		unset($this->options['last_output_err']) ;
		if ( ! empty($country_codes) ) : $file_string = str_replace('$just_these = array();', '$just_these = explode(",","' . $country_codes .'");',  $file_string); endif;
		if ( ! empty($use_group) && !  empty($my_ccgroup)) : $file_string = str_replace('$my_ccgroup = array();', '$my_ccgroup = explode(",","' . $my_ccgroup .'");',  $file_string); endif;
		$this->options['cca_maxmind_data_dir'] = CCA_MAXMIND_DATA_DIR;
		$file_string = str_replace('ccaMaxDataDirReplace', CCA_MAXMIND_DATA_DIR, $file_string);
    $file_string = str_replace('cczcMaxDirReplace', CCZC_MAXMIND_DIR, $file_string);
    return $file_string;

  }

	// write the generated script to ZC/QuickCaches add_ons folder
  function cczc_write_script( $file_string, $country_codes, $use_group, $my_ccgroup) {
  	unset($this->options['override_tab']);
    $item_perms = cczc_return_permissions(CCZC_PLUGINDIR);  // determine permissions to set when creating directory
    if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) == '7') :
      $cczc_perms = 0775;
    else:
      $cczc_perms = 0755;
    endif;
  
    clearstatcache(true, ZC_PLUGINDIR);
    if( ! file_exists(ZC_PLUGINDIR) && ! mkdir(ZC_PLUGINDIR, $cczc_perms, true) ):
  			$this->options['override_tab'] = 'files';
 				$this->options['override_isocodes'] = $country_codes;
				$this->options['override_use_group'] = $use_group;
				$this->options['override_my_ccgroup'] = $my_ccgroup;
  			$this->options['last_output_err'] =  date('d M Y h:i a; ') .  __(' unable to create the ZC add-on directory "') . ZC_PLUGINDIR . '" .' . __('Full error message = ') . implode(' | ',error_get_last());
  		  return __('Error: Unable to read/create the Comet/Zen Cache add-on folder (this might be due to wp-content permissions). Actual error reported = ') . implode(' | ',error_get_last());
  	endif;
  	
    if ( !  file_put_contents(ZC_PLUGINDIR . CCZC_ADDON_SCRIPT, $file_string, LOCK_EX ) ) :
  		$this->options['override_tab'] = 'files';
  		$this->options['override_isocodes'] = $country_codes;
			$this->options['use_group'] = $use_group;
			$this->options['my_ccgroup'] = $my_ccgroup;
  	  $this->options['last_output_err'] =  date('d M Y h:i a; ') .  __(' unable to create/update add-on script ') . ZC_ADDON_FILE;
  	  return "Error: creating/updating script in Comet/Zen Cache's add-on directory. You might not have write permissions to either create or replace it.";  // locking added in 0.6.2
    endif;
    unset($this->options['last_output_err']);
    unset($this->options['override_isocodes']);
		unset($this->options['use_group'] );
		unset($this->options['my_ccgroup']); 
    // check what file permissions are being set by server in wp-content folders and set this scripts files permissions to match
    $item_perms = cczc_return_permissions(CCZC_CALLING_SCRIPT);
    if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) > "5" ) :
      $cczc_perms = 0664;
    else:
      $cczc_perms = 0644;
    endif;
  	chmod(ZC_ADDON_FILE,$cczc_perms);
  
   	return 'Done';
  }


  function remove_addon_file($file) {
    if ( validate_file($file) === 0 && is_file($file) && ! unlink($file) ) return FALSE;
  	return TRUE;
  }


  function is_valid_ISO_list($list) {
    if ( $list != '') :
  	  $codes = explode(',' , $list);
  		foreach ($codes as $code) :
  		   if ( ! ctype_alpha($code) || strlen($code) != 2) :
     		   return FALSE;
  			 endif;
  		endforeach;	
  	endif;
		return TRUE;
	}

// 0.7.0
  function using_cloudflare_or_max_already() {
	   if(! empty($_SERVER["HTTP_CF_IPCOUNTRY"]) || ! empty($this->maxmind_status) || $this->options['caching_mode'] == 'QuickCache') :
		   return TRUE;
		endif;
		return FALSE;
	}


//  0.7.0  All Methods below this point are used to retreive and save Maxmind data files 

// this is the main controlling method
function save_maxmind_data($plugin_update=FALSE) {

	// intialize function
	 $max_ipv4download_url = 'http://geolite.maxmind.com/download/geoip/database/GeoLiteCountry/GeoIP.dat.gz';
	 $max_ipv6download_url = 'http://geolite.maxmind.com/download/geoip/database/GeoIPv6.dat.gz';
   $ipv4_gz = 'GeoIP.dat.gz';
   $ipv4_dat = 'GeoIP.dat';
   $cca_ipv4_file = CCA_MAXMIND_DATA_DIR . $ipv4_dat;
   $ipv6_gz = 'GeoIPv6.dat.gz';
   $ipv6_dat = 'GeoIPv6.dat';
   $cca_ipv6_file = CCA_MAXMIND_DATA_DIR . $ipv6_dat;
   $error_prefix = __("Error: Maxmind files are NOT installed. ");
	 if(empty($this->maxmind_status['ipv4_file_date'])) : $this->maxmind_status['ipv4_file_date'] = 0; endif;
	 if(empty($this->maxmind_status['ipv6_file_date']) ): $this->maxmind_status['ipv6_file_date'] = 0; endif;
	 $original_health = $this->maxmind_status['health'] = empty($this->maxmind_status['health']) ? 'ok' : $this->maxmind_status['health'];
	 $files_written_ok = FALSE;

   clearstatcache();

	 if (file_exists($cca_ipv4_file) && file_exists($cca_ipv6_file) && filesize($cca_ipv4_file) > 131072 && filesize($cca_ipv6_file) > 131072) :
     // return if an install/update is not necessary (another plugin may have recently done an update)
     if ($original_health == 'ok' && ! empty($this->maxmind_status['ipv4_file_date']) && $this->maxmind_status['ipv4_file_date'] > (time() - 3600 * 24 * 10) ): return TRUE; endif;
		 $original_health = 'ok';
  else:
	   $original_health = 'fail';
	endif;

	// re-initialize status msg
	$this->maxmind_status['result_msg'] = '';

	// create Maxmind directory if necessary
  if ( validate_file(CCA_MAXMIND_DATA_DIR) != 0 ) :  	// 0 means a valid format for a directory path
	   $this->maxmind_status['health'] = 'fail';
	   $this->maxmind_status['result_msg'] = $error_prefix . __('Constant CCA_MAXMIND_DATA_DIR contains an invalid value: "') . esc_html(CCA_MAXMIND_DATA_DIR) . '"';
	elseif ( ! file_exists(CCA_MAXMIND_DATA_DIR) ): 
	    // then this is the first download, or a new directory location has been defined
      $item_perms = cczc_return_permissions(CCZC_PLUGINDIR);  // determine required folder permissions (e.g. for shared or dedicated server)
      if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) == '7') :
          $cczc_perms = 0775;
      else:
          $cczc_perms = 0755;
      endif;							
  		if ( ! @mkdir(CCA_MAXMIND_DATA_DIR, $cczc_perms, true) ): 
			    $this->maxmind_status['health'] = 'fail';
				  $this->maxmind_status['result_msg'] = $error_prefix . __('Unable to create directory "') . CCA_MAXMIND_DATA_DIR . __('" This may be due to your server permission settings. See the Country Caching "Support" tab for more information.');
		  endif;
	endif;

	// if the Maxmind directory exists
	if ($this->maxmind_status['health'] == 'ok') :

    	// get and write the IPv4 Maxmind data
      if ($original_health == 'fail') : 
         $error_prefix = __("Error; unable to install the Maxmind IPv4 data file:\n");
      else:
    		 $error_prefix = __("Warning; unable to update the Maxmind IPv4 data file:\n");
    	endif;
      $ipv4_result = $this->update_dat_file($max_ipv4download_url, $ipv4_gz, $ipv4_dat, $error_prefix);
	    $temp_health = $this->maxmind_status['health']; 
			if ($ipv4_result == 'done') :
			  $ipv4_result =  'IPv4 file updated successfully.';
				$files_written_ok = TRUE;
				$this->maxmind_status['ipv4_file_date'] = time(); 
			endif;
 
       // get and write the IPv6 Maxmind data
      if ($original_health == 'fail') : 
         $error_prefix = __("Error; unable to install the Maxmind IPv6 data file:\n");
      else:
    		 $error_prefix = __("Warning; unable to update the Maxmind IPv6 data file:\n");
    	endif;
      $ipv6_result = $this->update_dat_file($max_ipv6download_url, $ipv6_gz, $ipv6_dat, $error_prefix);
 			if ($ipv6_result == 'done') :
			  $ipv6_result =  'IPv6 file updated successfully.';
				$this->maxmind_status['ipv6_file_date'] = time(); 
			else:
			  $files_written_ok = FALSE;  // overrides TRUE set by IPv4 success
			endif;
			 
			// ensure health status is set to the most critical of IP4 & IP6 file updates
			if ($temp_health == 'fail' || $this->maxmind_status['health'] == 'fail'):
			   $this->maxmind_status['health'] = 'fail';
			elseif ($temp_health == 'warn' || $this->maxmind_status['health'] == 'warn') :
			   $this->maxmind_status['health'] = 'warn';
			endif;

			$this->maxmind_status['result_msg'] = $ipv4_result . "<br />\n" . $ipv6_result;

  endif;

	if ($this->maxmind_status['health'] == 'warn' && $original_health == 'fail') : $this->maxmind_status['health'] = 'fail'; endif;
  if ($this->maxmind_status['health'] == 'ok') : $this->maxmind_status['result_msg'] .= __(" The last update was successful"); endif;
  update_option( 'cc_maxmind_status', $this->maxmind_status );
 
 
  // this function was called on plugin update the user might not open the settings form, so we'll report errors by email
  if ($plugin_update  && $this->maxmind_status['health'] == 'fail'):
     $subject = __("Error: site:") . get_bloginfo('url') . __(" unable to install Maxmind GeoIP files");
     $msg = str_replace('<br />', '' , $this->maxmind_status['result_msg']) . "\n\n" . __('Email sent by the Country Caching plugin ') . date(DATE_RFC2822);	
	  @wp_mail( get_bloginfo('admin_email'), $subject, $msg );
  endif;

	return $files_written_ok;
}  // END save_maxmind_data() 


//  This method retreives the "zips" from Maxmind and then calls other methods to do the rest of the work
function update_dat_file($file_to_upload, $zip_name, $extracted_name, $error_prefix) {

	$uploadedFile = CCA_MAXMIND_DATA_DIR . $zip_name;
	$extractedFile = CCA_MAXMIND_DATA_DIR . $extracted_name;

  // open file on server for overwrite by CURL
  if (! $fh = fopen($uploadedFile, 'wb')) :
		 $this->maxmind_status['health'] = 'warn';
		 return $error_prefix . __("Failed to fopen ") . $uploadedFile . __(" for writing: ") .  implode(' | ',error_get_last()) . "\n<br />";
  endif;
  // Get the "file" from Maxmind
  $ch = curl_init($file_to_upload);
  curl_setopt($ch, CURLOPT_FAILONERROR, TRUE);  // identify as error if http status code >= 400
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_USERAGENT, 'a UA string'); // some servers require a non empty or specific UA
  if( !curl_setopt($ch, CURLOPT_FILE, $fh) ):
		 $this->maxmind_status['health'] = 'warn';
		 return $error_prefix . __('curl_setopt(CURLOPT_FILE) fail for: "') . $uploadedFile . '"<br /><br />' . "\n\n";
	endif;
  curl_exec($ch);
  if(curl_errno($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200 ) :
  	fclose($fh);
		$curl_err = $error_prefix . __('File transfer (CURL) error: ') . curl_error($ch) . __(' for ') . $file_to_upload . ' (HTTP status ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ')';
    curl_close($ch);
		$this->maxmind_status['health'] = 'warn';
		return $curl_err;
  endif;
  curl_close($ch);
  fflush($fh);
  fclose($fh);

  if(filesize($uploadedFile) < 1) :
		$this->maxmind_status['health'] = 'warn';
		return $error_prefix . __("CURL file transfer completed but we have an empty or non-existent file to uncompress. (") . $uploadedFile . ').<br /><br />' . "\n\n";
  endif;

	$function_result = $this->gzExtractMax($uploadedFile, $extractedFile);

	if ($function_result != 'done'):  return $error_prefix . $function_result; endif;

  //  update appears to have been successful
  $this->maxmind_status['health'] = 'ok';
  return 'done';

}  // END  update_dat_file()


// extract file from gzip and write to folder
function gzExtractMax($uploadedFile, $extractedFile) {

  $buffer_size = 4096; // memory friendly bytes read at a time - good for most servers

  $fhIn = gzopen($uploadedFile, 'rb');
  if (is_resource($fhIn)) {
    $function_result = $this->backupMaxFile($extractedFile);
		if ($function_result != 'done' ) {
			$this->maxmind_status['health'] = 'warn';
			return $function_result;
    }
		$fhOut = fopen($extractedFile, 'wb');
    $writeSucceeded = TRUE;
    while(!gzeof($fhIn)) :
       $writeSucceeded = fwrite($fhOut, gzread($fhIn, $buffer_size)); // write from buffer
       if ($writeSucceeded === FALSE) break;
    endwhile;
    @fclose($fhOut);

    if ( ! $writeSucceeded ) {
			$this->maxmind_status['health'] = 'fail';
			$function_result = __('Error writing "') .  $extractedFile . '"<br />' ."\n" . __('Last reported error: ') .  implode(' | ',error_get_last());
			$function_result .= "<br />\n" . $this->revertToOld($extractedFile);
			return $function_result;
		}

  } else { 
	    $this->maxmind_status['health'] = 'warn';
		  $function_result = __('Unable to extract the file from the Maxmind gzip: ( ') . $uploadedFile . ")<br />\n" . __("Your existing data file has not been changed.");
		  return $function_result;
		}
  gzclose($fhIn);

  clearstatcache();
  if(filesize($extractedFile) < 1) {
	  $this->maxmind_status['health'] = 'fail';
	  $recoveryStatus = $this->revertToOld($extractedFile);
		$function_result = __('Failed to create a valid data file - it appears to be empty. Trying to revert to old version: ') . $recoveryStatus;
		return $function_result;
	}
	$this->maxmind_status['health'] = 'ok';
  return 'done';
}


// used to create a copy of the file (in same dir) before it is updated (replaces previous back-up)
function backupMaxFile($fileToBackup) {
  if (! file_exists($fileToBackup) || @copy($fileToBackup, $fileToBackup . '.bak') ) return 'done';
  return __('ABORTED - failed to back-up ') . $fileToBackup . __(' before replacing Maxmind file: ') .  implode(' | ',error_get_last()) . "\n" . __("<br />Your existing data file has not been changed.");
}


function revertToOld($fileToRollBack){
  $theBackup = $fileToRollBack . '.bak';
  if (! file_exists($theBackup) || filesize($theBackup) < 131072 || ! @copy($theBackup, $fileToRollBack) ) return __("NOTE: unable to revert to a previous version of ") . $fileToRollBack . ".<br />\n\n";
  $this->maxmind_status['health'] = 'warn';
  return __('It looks like we were able to revert to an old copy of the file.<br />');
}


} // end class


