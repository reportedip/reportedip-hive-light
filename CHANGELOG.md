# Changelog

All notable changes to ReportedIP Hive are documented in this file. Format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.1] — 2026-05-12

### Changed

- `ReportedIP_Hive::emit_block_response_headers()` no longer defines the
  `DONOTCACHEPAGE`, `DONOTCACHEDB` or `DONOTCACHEOBJECT` constants. The
  block response now relies exclusively on the HTTP 403 status plus
  `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and
  `Pragma: no-cache` headers, which all major caching plugins (WP Rocket,
  W3 Total Cache, WP Super Cache, LiteSpeed) honour. This removes the
  process-wide constant define that the wp.org reviewer flagged as a
  global behaviour change.

### Documentation

- `readme.txt` "External services" → "Service provider" subsection now
  points at the canonical legal-notice URLs on reportedip.de
  (`/impressum/`, `/nutzungsbedingungen/`, `/datenschutzerklaerung/`).
  The previously listed `/terms` and `/privacy` URLs were stubs that
  returned 404.
- `readme.txt` "How it works" bullet about cache-plugin compatibility
  rewritten to match the new header-only approach.

## [1.3.0] — 2026-05-04

### Added

- Long-term "Defended attacks" stats card on the Dashboard with rolling
  windows for the last 24 hours, last 7 days, last 30 days, and all-time
  block counts.

### Changed

- `get_blocked_rows()` rewritten with an inline `match`-prepare pattern
  so that no variable enters the SQL string. This eliminates the last
  remaining `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` /
  `PluginCheck.Security.DirectDB.UnescapedDBParameter` warning — the
  wp.org Plugin Check verdict is now 0 errors **and 0 warnings**.

### Documentation

- `LICENSE` now ships the full GPLv2 text (verbatim from
  https://www.gnu.org/licenses/gpl-2.0.txt) alongside the plugin
  copyright header.
- `readme.txt` adds a `== Bundled assets ==` section enumerating every
  locally-served stylesheet/script and confirming the plugin loads no
  CDN, no Google Fonts, no third-party JS bundles.
- `readme.txt` adds a `== Third-party services and licences ==` section
  confirming no bundled third-party PHP/JS/CSS libraries and recapping
  that the only opt-in external service is the reportedip.de API.

## [1.2.0] — TBD

### Added

- Dashboard landing page (Settings → ReportedIP Hive) with 4 stat cards
  (active blocks, blocks last 24 h, attempts last 24 h, whitelist size),
  an operation-mode summary, the report-queue status (pending /
  processing / completed / failed), and a recent-activity list.
- Per-hour outbound-call quota tracking (rolling 1-hour transient) with a
  progress bar on the dashboard. When exhausted, IP-reputation lookups
  are skipped (login still proceeds — fail-open).

### Changed

- Settings, Blocked IPs and Whitelist are now sub-pages of the new
  Dashboard.

## [1.1.0] — TBD

### Added

- IP whitelist with optional CIDR ranges and expiry, exposed at *Settings →
  ReportedIP Hive → Whitelist*. CIDR matching is IPv4-only.
- 4-step setup wizard on first activation (Welcome → Operation mode →
  Protection → Done). Activation transient drives the redirect; the wizard
  is idempotent and can be skipped at any step.
- The "Community Access Key" card is now hidden until the operation mode is
  set to Community Network (server-side data attribute + JS toggle).

### Changed

- "Settings saved" is now rendered as a design-system `.rip-alert--success`
  immediately under the page header instead of the WordPress default yellow
  notice (better contrast, matches the rest of the admin UI).
- Welcome notice on dashboard pages is rendered as `.rip-alert--info` with a
  tinted dismiss button — same visual language as the rest of the plugin.
- Trust badges in the page footer now carry inline SVG icons that match the
  brand palette.

### Internal

- Schema bumped to v1.1.0; idempotent migration on activation adds the
  `wp_reportedip_hive_whitelist` table.
- 4 additional unit tests covering CIDR matching.

## [1.0.0] — TBD

### Added

- Initial release.
- `wp_login_failed` brute-force counter with atomic upsert (race-safe under
  concurrent attacks).
- `wp_authenticate_user` pre-auth check for blocked IPs.
- Password-spray detection via distinct hashed-username sampling.
- Progressive block-duration ladder (5 min → 15 min → 30 min → 24 h → 48 h →
  7 days), configurable via the *Protection* tab.
- Optional Community Network mode with IP-reputation lookup
  (`GET /check`, 2-second timeout, fail-open) and queued failure reports
  (`POST /report`).
- Circuit breaker (3 failures within 5 calls → 5-minute pause) that prevents
  upstream outages from slowing the login path.
- Trusted-proxy header whitelist (X-Forwarded-For, CF-Connecting-IP, X-Real-IP,
  True-Client-IP, X-Cluster-Client-IP).
- Three-tab settings page (Connection / Protection / Privacy) and a
  *Blocked IPs* list table with bulk-unblock.
- Multisite-aware `uninstall.php` with optional data deletion.
- Privacy-policy suggestion via `wp_add_privacy_policy_content()`.
