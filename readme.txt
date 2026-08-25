=== Tenyen Analytics for WordPress ===
Contributors: 10yendama
Tags: analytics, privacy, pageviews, sessions, geolocation
Requires at least: 6.2
Requires PHP: 8.1
Stable tag: 0.6.3
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

= 0.6.3 =

* Added collection and non-destructive analysis exclusion rules for IP, path, administrator, Bot, geography, organization, environment, referrer, and UTM attributes.
* Added an administrator-only rule manager and deterministic diagnostic output.
* Added one exclusion-rule table without changing historical analytics or metadata rows.

= 0.6.2 =

* Added administrator aliases, notes, reusable tags, ASN watchlists, private saved views, and a centralized Knowledge screen.
* Added four separate metadata tables without changing historical analytics rows.

= 0.6.1 =

* Added traffic channels, first-touch UTM campaign attribution, and event metadata.
* Added external, download, optional internal, button, form, 404, and custom event collection.
* Added event details to existing session journeys and separated English/Japanese changelogs.

= 0.6.0 =

* Added administrator-only asynchronous session lists, ordered journey details, and anonymous browser visitor history.
* Added entry, exit, bounce, bounce-rate, exit-rate, session, and pageviews-per-session content metrics.
* Added links from access history to related session and anonymous visitor details.
* Added responsive long-value wrapping and accessible journey dialogs.

= 0.5.7 =

* Fixed PHP translation expressions appearing as literal text throughout the access-history screen.
* Added a rendering regression check for the access-history interface.

= 0.5.6 =

* Fixed long User-Agent, URL, and identifier values overlapping adjacent fields in expanded access-history details.
* Improved responsive wrapping for access-history detail content.

= 0.5.5 =

* Internationalized the admin interface and added bundled Japanese translations.
* Added an asynchronous WordPress Dashboard widget.
* Added plugin-page footer credit, release tooling, CI, and repository documentation.
* Renamed the main plugin class without changing database schema or stored data.

= 0.5.2 =

* Split reports into ten purpose-specific admin pages.
* Added a built-in MMDB reader and asynchronous access history.

== Upgrade Notice ==

= 0.6.3 =

Adds a separate exclusion-rule table. Existing v0.6.2 analytics and metadata remain valid; exclusion rules never delete historical rows.

= 0.6.2 =

Adds separate metadata tables. Existing v0.6.1 analytics data and identifiers remain valid.

= 0.6.1 =

Adds nullable attribution and event columns plus focused indexes. Existing v0.6.0 rows remain valid.

= 0.6.0 =

No database migration is required. Existing v0.5.7 analytics data and settings are preserved. Legacy events without a session ID remain in access history but are excluded from session analytics.

= 0.5.7 =

No database migration is required. Existing analytics data and settings are preserved.

= 0.5.6 =

No database migration is required. Existing v0.5.5 analytics data and settings are preserved.

= 0.5.5 =

No database migration is required. Existing analytics data and settings are preserved.
