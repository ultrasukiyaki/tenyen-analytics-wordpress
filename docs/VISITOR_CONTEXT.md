# Visitor context and hostname display (v0.9.0)

Dashboard recent views and realtime rows show stored country code/name, region, and city alongside ASN and registered organization. Session lists, session details, and anonymous visitor details use the same formatting. Missing values appear as `—`. ASN registrations and PTR hostnames describe network infrastructure; neither verifies a visitor's employer or identity.

Access history displays a reverse-DNS hostname instead of the decrypted IP, including expanded details. If no cached result exists, the UI shows “Awaiting hostname lookup”; negative or failed results show “Hostname unavailable”. Existing browser column preferences are retained, and a previously visible IP column is migrated to Hostname. Location follows the saved visibility preference.

## Hostname refresh

WordPress schedules `tya_hostname_refresh` through WP-Cron. Each run processes at most 20 unique IP hashes, one at a time, with a 500 ms DNS timeout and a 15-second processing budget. A short option lock prevents overlapping workers. Recent events take priority. Successful results refresh after 24 hours, missing PTR records after six hours, and resolver failures after one hour.

WP-Cron is traffic-driven. Sites with disabled WP-Cron should invoke `wp-cron.php` from their hosting scheduler at least once per minute if prompt hostname refresh is required.

The worker uses the first valid nameserver in `/etc/resolv.conf` and never calls an external lookup API. Queries send only public IPs to that resolver. Private, loopback, link-local, documentation, and other non-public addresses are skipped. Collection and administrator page requests do not perform reverse DNS.

## Upgrade and retention

The normal idempotent schema migration adds the prefixed `tya_hostname_cache` table, keyed by the existing IP hash with an expiry index. No existing event columns are changed. Raw-log cleanup also removes cache entries with no remaining source events. Hostnames remain administrator-only and are not added to existing exports.
