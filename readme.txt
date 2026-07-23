=== Tenyen Analytics for WordPress ===
Contributors: 10yendama
Tags: analytics, privacy, pageviews, sessions, geolocation
Requires at least: 6.2
Requires PHP: 8.1
Stable tag: 0.5.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-hosted analytics plugin with pageviews, sessions, engagement, GeoLite2, ASN insights, bot detection, and asynchronous reports.

== Description ==

Tenyen Analytics stores analytics in WordPress and provides pageview, estimated visitor, session, engagement, referrer, audience, organization, and bot reports. Raw IP addresses are encrypted and HMAC values support exact-match search.

GeoLite2 MMDB files are not included. Site administrators must obtain them under MaxMind's terms. ASN organization names identify the registered organization for an address range and do not prove visitor affiliation.

== Installation ==

1. Upload and activate the plugin.
2. Optionally obtain GeoLite2 City and ASN databases from MaxMind.
3. Place MMDB files in `wp-content/uploads/tenyen-analytics/` or configure their paths.
4. Review retention and proxy settings under Tenyen Analytics → Settings.

== Frequently Asked Questions ==

= Does uninstall delete analytics data? =

No. Analytics data is preserved unless an administrator removes it manually.

= Does it require Composer or an external service? =

No. The built-in MMDB reader and local analytics reports work without Composer or an external analytics service.

== Changelog ==

= 0.5.5 =

* Internationalized the admin interface and added bundled Japanese translations.
* Added an asynchronous WordPress Dashboard widget.
* Added plugin-page footer credit, release tooling, CI, and repository documentation.
* Renamed the main plugin class without changing database schema or stored data.

= 0.5.2 =

* Split reports into ten purpose-specific admin pages.
* Added a built-in MMDB reader and asynchronous access history.

== Upgrade Notice ==

= 0.5.5 =

No database migration is required. Existing analytics data and settings are preserved.
