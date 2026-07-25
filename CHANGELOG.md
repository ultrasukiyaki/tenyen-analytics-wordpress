English | [日本語](CHANGELOG.ja.md)

# Changelog

## 0.6.1 - 2026-07-26

### Added
- Added traffic-channel/referrer-domain classification and first-touch UTM attribution.
- Added bounded click, download, button, form, 404, and custom events to session journeys.
- Added `window.TenyenAnalytics.trackEvent()` for safe custom integrations.

### Changed
- Reused the existing `outbound` and `download` tracker semantics and unified Human/Bot and administrator exclusions.
- Separated English and Japanese changelog history.

### Security
- Added strict event-name, metadata, UTM, URL, type, count, and length validation.

### Compatibility
- Adds nullable attribution and event columns with focused channel, campaign, and event-name indexes.
- Existing v0.6.0 events, session identifiers, visitor identifiers, encrypted IPs, and HMACs remain valid.

## 0.6.0 - 2026-07-25

### Added
- Added an asynchronous administrator-only session list with ordered raw-event journeys.
- Added anonymous browser visitor summaries and historical session navigation.
- Added content entries, exits, bounces, bounce rate, exit rate, sessions, and pageviews-per-session metrics.

### Changed
- Added access-history navigation to related session and anonymous visitor details.
- Added responsive long-value wrapping and keyboard-accessible detail dialogs.
- Updated English and standard-Japanese documentation and interface translations.

### Security
- Restricted all session and visitor analytics REST routes to users with `manage_options`.

### Compatibility
- No schema or index migration was required; existing v0.5.7 events and settings were preserved.
- Events without a stored session ID remained available in Access History and were excluded from session analytics.

## 0.5.7 - 2026-07-24

### Fixed
- Fixed PHP translation expressions appearing as literal text throughout Access History.
- Added a rendering regression check for the Access History interface.

## 0.5.6 - 2026-07-23

### Fixed
- Fixed long User-Agent, URL, and identifier values overlapping adjacent fields.
- Improved responsive wrapping in Access History details.

## 0.5.5

- Renamed the main plugin class and file.
- Converted the administration UI to English gettext source with bundled standard-Japanese translations.
- Added the asynchronous Dashboard widget, credits, documentation, licensing, CI, and reproducible release tooling.
- Preserved tables, options, event payloads, routes, classification, and uninstall behavior without migration.

## 0.5.2

- Split the long dashboard into purpose-specific WordPress submenus.
- Added dedicated reports, asynchronous Access History, and a built-in MMDB reader.
- Loaded only the administration assets required by each page.
- Improved fallback behavior when an optional Composer autoloader is incomplete.
- No database schema change.

## 0.5.0

- Changed detailed Access History to asynchronous REST loading.
- Added collapsing, compact display, paging, filters, saved display preferences, and expanded details.
- No database schema change.

## 0.4.1

- Fixed `%` conflicts between `wpdb::prepare()` and `DATE_FORMAT()`.
- Stabilized trend aggregation with date component expressions.
- No database schema change.

## 0.4.0

- Added safe links, date filters, period totals, trends, breakdown charts, and Human/Bot switching.
- Added local Canvas charts without external dependencies.
- No database schema change.

## 0.3.1

- Fixed an update issue where the old dashboard class remained active.
- Added cache-busting PHP filenames and a visible UI build badge.

## 0.3.0

- Added ASN organization classification, badges, notable organizations, recent browsing, rankings, raw-event details, and ASN overrides.

## 0.2.0

- Added paging, partial search, HMAC exact IP matching, event/Human/Bot/date filters.

## 0.1.0

- Initial WordPress release.
