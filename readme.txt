=== WP Experience API ===
Contributors: Devindra Payment, loongchan, ctlt-dev
Tags:  xAPI, BadgeOS, Tincan, LRS, Experience API, Tin Can API
Requires at least: WordPress 3.5
Tested up to: 4.2.2
Stable tag: 1.0.6
License: GNU AGPLv3
License URI: http://www.gnu.org/licenses/agpl-3.0.html

Adds the ability for WordPress to send preset xAPI statements to a Learning Record Store

== Description ==

Sends xAPI statements to LRS (tested against LearningLocker and cloud.scorm.com).  Some features are enabled
ONLY if the dependent plugins have also been installed.  The plugin can be used as a MU plugin as well. 

It has been partially tested with:

* [SCORM Cloud](https://cloud.scorm.com)
* [Learning Locker](http://learninglocker.net/)

Statements that can be sent are:

* page views
* post status changes
* commenting
* earning badges(1)
* voting(2)

(1) requires

* [JSON API](https://wordpress.org/plugins/json-api/)
* [BadgeOS](https://wordpress.org/plugins/badgeos/)
* <https://github.com/ubc/open-badges-issuer-addon>

(2) currently only works with PulsePress theme (https://wordpress.org/themes/pulsepress/) when voting or starring

This plugin was developed at the UBC Centre for Teaching, Learning and Technology.

== Installation ==

Assumes you are using PHP version >= 5.4 (requirement of TinCanPHP Library that the plugin includes)

1. plunk folder into plugins
2. Activate the plugin "WP Experience API" through the "Plugins" menu in WordPress

= EXTRA NOTES FOR MU: =
If you want to install in wp-content/mu-plugins folder, the plugin uses a proxy loader file.

1. copy wp-experience-api directory to mu-plugins folder
2. copy wp-experience-api/wp-experience-api-mu-loader.php to directory one level up (same level as wp-experience-api itself AKA just under mu-plugins folder)
3. it should be installed!  Enjoy!

= EXTRA EXTRA NOTES: =
* now that the plugin uses the TinCanPHP library (http://rusticisoftware.github.io/TinCanPHP/), please make sure that it is updated regularly as well!  current version is 0.11.4

== Frequently Asked Questions ==

= How can I add more xAPI statements to the plugin? =
You can create your own plugin and use the plugin's hooks!

= How come nothing is being sent to the LRS after I activate the plugin? =
The settings are defaulted so that nothing is sent by default.  Please go to the dashboard and the WP xAPI settings page to configure what statements are sent.

= What is the queue for? =
The queue is used for when for some reason, LRS can't be reached, then statements meant to be sent will be added to the queue to be sent later in the admin screen.

== Upgrade Notice ==
Nothing yet.

== Screenshots ==

1. The network level administration screen for a Multisite WordPress installtion.
2. Site level administration page for users autorized to set the LRS at the site level.

== Changelog ==

= 1.0.6 =
* tweaked syntax to fit with wordpress better (got codesniffer to work on my ide again!)
* fixed bug where posts with empty body makes invalid statements. 

= 1.0.5 =
* tweaked the queueing system so that you click on a button on the admin pages to run the queue instead of trying to use wp-cron. 
* bug fixes (made timestamp follow iso8601 more strictly and fixed typo)

= 1.0.4 =
* added a queueing system.  Also setting timestamp field is done by the plugin.

= 1.0.3 =
* added additional options for whitelisted users access level.  Options are whitelisted users have full control or only control LRS info at the site level.

= 1.0.2 =
* changed verb for commented statements from created to commented

= 1.0.1 = 
* fixed bug found where statements are invalid if site tagline is left blank.  Now it will dispay 'n/a' for empty website taglines.
* updated readme formatting

= 1.0.0 =
* Initial public release
