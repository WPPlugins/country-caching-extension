=== Plugin Name ===
Contributors: wrigs1
Donate link: http://means.us.com/
Tags: CometCach,Comet cache,Zencache, Zen Cache, caching, Country, GeoIp, Geo-Location
Requires at least: 3.3
Tested up to: 4.4.2
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Enables Comet/Zen Cache to cache by page/visitor country instead of just page. Solves "wrong country content" Geo-Location issues.

== Description ==

Allows Comet Cache to display the correct page/widgets content for a visitor's country when you are using geo-location; solves problems like these reported on [Wordpress.Org](https://wordpress.org/support/topic/quick-cache-geotargeting ) and [StackOverflow](http://stackoverflow.com/questions/21308405/geolocation-in-wordpress ).
If you need country caching with other caching plugins then see comments at the bottom of the page.

This plugin builds an extension script that enables Comet Cache to create separate snapshots (cache) for each page based on country location.
Separate snapshots can be restricted to specific countries.  E.g. if you are based in the US but customize some content for Canadian or Mexican visitors, you can restrict
separate caching to CA & MX visitors; and all other visitors will see the same cached ("US") content.

It works on both normal Wordpress and Multisite (see FAQ) installations.

**Comet Cache** is designed to work with add-on scripts and should work seamlessly with this plugin.

**Identification of visitor country for caching**

This product includes GeoLite data (both IPv4 and IPv6) created by MaxMind, available from http://www.maxmind.com .

If you use Cloudflare and have "switched on" their GeoLocation option ( see [Cloudflare's instructions](https://support.cloudflare.com/hc/en-us/articles/200168236-What-does-CloudFlare-IP-Geolocation-do- ) )
then it will be used to identify visitor country.  If not, then the Maxmind GeoLite Legacy Country Database, included with this plugin, will be used.

Note: not tested on IPv6 (my servers are IPv4), however feedback on Stackoverflow indicate the code should work fine for IPv6.

**Updating** The provided Maxmind Country/IP range data files will lose accuracy over time. If you wish to keep your IP data up to date, then installation of the Category Country Aware plugin 
([here on Wordpress.Org](https://wordpress.org/plugins/category-country-aware/ )) is recommended. The CCA plugin automatically updates Maxmind data every 3 weeks (even if you don't use any of its other features).

** ADVICE:**

I don't recommend you use ANY Caching plugin UNLESS you know how to use an FTP program (e.g. Filezilla). Caching plugins can result in "white screen" problems for some unlucky
users; sometimes the only solution is to manually delete files using FTP or OS command line.  Quick/Zen cache is no different; when I checked just the first page of 
its support forum included 3 [posts about this](https://wordpress.org/support/topic/blank-pages-site-after-upgrading-to-latest-versio ). The Country Caching plugin deletes files
on deactivation/delete, but in "white screen" situations you may have to resort to "manual" deletion - see FAQ for instructions.


**WP Super Cache:** is also designed to work with "add-ons" and an equivalent of this plugin is available in the Wordpress repository.

**W3 Total Cache** does not *currently* provide a suitable hook for plugin country caching. Others have [requested this facility](https://wordpress.org/support/topic/request-add-hook-to-allow-modification-of-the-cache-key ).



== Installation ==

The easiest way is direct from your WP Dashboard like any other widget:

Once installed go to: "Dashboard->Country Caching". Check the "*Enable ZC/QC Country Caching add-on*" box, and save settings.

If you want automatic "3 weekly" update of *Maxmind Country->IP range data* then also install the [Category Country Aware plugin (here on Wordpress.Org)](https://wordpress.org/plugins/category-country-aware/ ).


== Frequently Asked Questions ==

= Where can I find support/additional documentation =

Support questions should be posted on Wordpress.Org<br />
Additional documentation is provided at http://wptest.means.us.com/2015/02/quick-cache-and-geoip-enable-caching-by-pagevisitor-country-instead-of-just-page/


= How do I know its working =

See [these checks](http://wptest.means.us.com/2015/02/quick-cache-and-geoip-enable-caching-by-pagevisitor-country-instead-of-just-page/ ).

= How do I keep the Maxmind country/IP range data up to date =

Install the [Category Country Aware plugin](https://wordpress.org/plugins/category-country-aware/ ) from Wordpress.Org; it will update Maxmind data every 3 weeks.


= Will it work on Multisites =

Yes, it will be the same for all blogs (you can't have it on for Blog A, and off for Blog B).

On MultiSites, the Country Caching settings menu will be visible on the Network Admin Dashboard (only).


= How do I stop/remove Country Caching =

Deactivating the plugin will remove the Caching Extension. Then clear the QC cache (Dashboard->QuickCache->Clear)

If all else fails:

1.  Log into your site via FTP; e.g. with CoreFTP or FileZilla.
2.  Delete this file: /wp-content/ac-plugins/cca_qc_geoip_plugin.php
3.  Delete this directory: /wp-content/plugins/country-caching-extension/
4.  Then via your Wordpress Admin: Dashboard->QuickCache->Clear


== Screenshots ==

1. Simple set up. Dashboard->Settings->Country Caching


== Changelog ==

= 0.9.0 =  Modified for new Comet Cache. Previous version (0.8.0) still works, but this version has been modified to use the latest Comet Cache methods.
After update it is advisable to uncheck/save then check/save the "Enable CC" box on Country Caching Settings.  Quick Cache (very old) is no longer supported.

= 0.8.0 =  Added option to enable the same cache to be used for a specified group of countries e.g. the European Union

= 0.7.4 =  Modified to work with re-designed ZenCache v150626.  N.B. When ZC v150626 was first released it had a bug (patched in later downloads) if you are 
using this version of ZC clear your cache after installing Country Caching 0.7.4

= 0.7.2 =  Maxmind data files are now auto installed (when you first enable Country Caching)  in a shared directory for use by other plugins.
The data files are provided by Maxmind under Creative Commons license, but the Wordpress.org repository requires all files stored there should be licensed under GPL. The Plugin has been altered to comply. 

= 0.6.3 =  Compatability tweak for the new released Zen Cache - which turns out not to just be QC renamed but modified code. The add-on script generated by earlier versions of the Country Caching
 (CC) plugin DOES work with Zen Cache; however the CC Settings form on these versions was incorrectly stating Zen Cache wasn't present on your system.
 

= 0.6.2 =
Bugfix: 644 file permissions may be unsuitable for dedicated servers; now, if the plugin detects that other files on your server are set to 664 then the add-on script is granted 664 permissions
Improvements: More diagnostic information available.
Servers with "non standard permission" requirements - you will have option to save generated add-on script for your own FTP upload to directory

= 0.6.0 =
Bugfix: resolves compatibility issue with some GeoIP plugins.
If you are updating from version 0.5.0 you should uncheck/save then check/save the "Enable QC" box on Country Caching Settings and then clear cache via Quick Cache settings.

= 0.5.0 =
* First published version.

== Upgrade Notice ==

= 0.9.0 =  Modified for new Comet Cache. Previous version (0.8.0) still works, but this version has been modified to use the latest Comet Cache methods.
After update it is advisable to uncheck/save then check/save the "Enable CC" box on Country Caching Settings.  Quick Cache (very old) is no longer supported.

= 0.8.0 =  Added option to enable the same cache to be used for a specified group of countries e.g. the European Union

= 0.7.4 =  Modified to work with re-designed ZenCache v150626.  N.B. When ZC v150626 was first released it had a bug (patched in later downloads) if you are 
using this version of ZC clear your cache after installing Country Caching 0.7.4

= 0.7.2 =  Maxmind data files are now auto installed (when you first enable Country Caching)  in a shared directory for use by other plugins.
The data files are provided by Maxmind under Creative Commons license, but the Wordpress.org repository requires all files stored there should be licensed under GPL. The Plugin has been altered to comply. 

= 0.6.3 =  Compatability tweak for the new released Zen Cache - which turns out not to just be QC renamed but modified code. The add-on script generated by earlier versions of the Country Caching
 (CC) plugin DOES work with Zen Cache; however the CC Settings form on these versions was incorrectly stating Zen Cache wasn't present on your system.

= 0.6.2 =
Bugfix: 644 file permissions may be unsuitable for dedicated servers; now, if the plugin detects that other files on your server are set to 664 then the add-on script is granted 664 permissions
Improvements: More diagnostic information available.
Servers with "non standard permission" requirements - you will have option to save generated add-on script for your own FTP upload to directory

= 0.6.0 =
Bugfix: resolves compatibility issue with certain GeoIP plugins. 
If you are updating from version 0.5.0 you should uncheck/save then check/save the "Enable QC" box on Country Caching Settings and then clear cache via Quick Cache settings.


== License ==

This program is free software licensed under the terms of the [GNU General Public License version 2](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html) as published by the Free Software Foundation.

In particular please note the following:

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.