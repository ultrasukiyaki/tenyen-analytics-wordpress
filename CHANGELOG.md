English | [日本語](CHANGELOG.ja.md)

# Changelog

## 0.7.0 - 2026-08-25

### Added
- Added chunked CSV/JSON export for raw events, sessions, content, organizations, traffic sources, campaigns, and event summaries with current analytics, metadata, watchlist, tag, and exclusion filters.
- Added privacy-preserving omit/masked IP modes and an explicitly confirmed administrator-only decrypted-IP mode.
- Added unlimited/preset/custom retention, cleanup preview, bounded resumable WP-Cron deletion, overlap locking, and observable cleanup state.
- Added table/database size, event/session range, monthly-count, retention, and cleanup diagnostics.

### Security
- Added spreadsheet-formula neutralization, stable JSON field allowlists, bounded filters, capability and nonce checks, safe cleanup identifiers, and non-sensitive failure reporting.

### Compatibility
- No schema or index change; schema version remains 0.6.3. Existing analytics, annotations, tags, watchlists, saved views, exclusions, settings, GeoLite files, and keys remain valid.

## 0.6.3 - 2026-08-25

### Added
- Added administrator-only collection and analysis exclusion rules for network, request, geography, organization, environment, referrer, and campaign attributes.
- Added deterministic rule diagnostics with matched rule, precedence, action, and reason.

### Security
- Added strict type/scope allowlists, bounded plain-text values and notes, safe IPv4/IPv6 CIDR matching, safely encoded analysis predicates, REST capability checks, and nonce-protected administration.

### Compatibility
- Added one prefixed exclusion-rule table and a focused active-scope index.
- Existing v0.6.2 analytics and metadata rows remain unchanged. Collection exclusions are prospective; analysis exclusions hide matching history without deleting it.

## 0.6.2 - 2026-07-30

### Added
- Added administrator aliases, plain-text notes, reusable tags, ASN organization watchlists, and private saved views.
- Added a centralized Knowledge screen and administrator-only metadata REST API.

### Security
- Added allowlisted entity types, type-specific keys, bounded plain text, preset tag colors, prepared queries, and saved-view owner isolation.

### Compatibility
- Added four prefixed metadata tables and focused identity, watchlist, tag-relation, and owner/report indexes.
- Existing v0.6.1 analytics rows remain unchanged; uninstall continues to preserve plugin data.

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
