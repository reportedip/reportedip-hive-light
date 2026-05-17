=== ReportedIP Hive Light ===
Contributors: reportedip
Tags: security, login, brute-force, ip-blocking, firewall
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight brute-force login protection with optional community-powered IP reputation checks.

== Description ==

ReportedIP Hive Light protects WordPress logins against brute-force and password-spray attacks. It is intentionally focused: a per-IP attempt counter, a progressive block ladder, and an optional community lookup. No bloat, no dashboards, no upsell.

**Two operating modes**

* **Local Shield (default).** Counts failed logins per IP and blocks attackers based on configurable thresholds. The plugin makes zero outbound network requests in this mode — all data stays on your server.
* **Community Network (optional).** When you enter a free Community Access Key from reportedip.de, the plugin additionally checks the source IP against the reportedip.de community database during login attempts and shares blocked IPs back to the community. Both calls are clearly disclosed in the settings UI.

**How it works**

* `wp_login_failed` increments a per-IP counter using an atomic upsert (no race conditions under concurrent attacks).
* When the counter exceeds your threshold, the IP is blocked for a duration drawn from a progressive ladder (5 min → 15 min → 30 min → 24 h → 48 h → 7 days).
* `wp_authenticate_user` short-circuits known-bad IPs before the WordPress core authentication runs.
* Cache plugins (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed) are honoured via the HTTP 403 status plus explicit `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache` headers on the block page.

**Privacy**

* IP addresses are processed for the legitimate purpose of network security (GDPR Art. 6(1)(f)).
* Usernames are stored only as a SHA-256 hash, salted with `wp_salt()`. Plain-text usernames are never persisted or transmitted.
* In Local Shield mode, no data leaves your server. In Community Network mode, only the IP, hashed username, event type, and timestamp are sent — no domain, no contact details, no traffic data.

**For developers**

* Filters: `reportedip_hive_is_whitelisted`, `reportedip_hive_get_client_ip`, `reportedip_hive_event_category_map`, `reportedip_hive_api_endpoint`.
* Actions: `reportedip_hive_log`, `reportedip_hive_ip_blocked`, `reportedip_hive_report_queued`.

A free Community Access Key is available at reportedip.de. The plugin works without one in Local Shield mode.

== Installation ==

1. Upload the `reportedip-hive` folder to `/wp-content/plugins/`, or install via *Plugins → Add New*.
2. Activate the plugin from the WordPress *Plugins* screen.
3. Go to *ReportedIP Hive Light → Settings* and review the Connection / Protection / Privacy tabs.

The plugin is functional out of the box in Local Shield mode — no configuration required.

== Frequently Asked Questions ==

= How do I get a Community Access Key? =

Register at reportedip.de. The Community Access Key tier is free.

= Can I use the plugin without an access key? =

Yes. The default mode is Local Shield, which uses only your site's data and does not contact any external service. The plugin remains fully functional.

= Will the plugin lock me out of my own site? =

It might, if you fail logins repeatedly from your own IP. To recover, either wait until the block expires or delete the row from the `wp_reportedip_hive_blocked` database table (e.g. via phpMyAdmin or WP-CLI: `wp db query "DELETE FROM wp_reportedip_hive_blocked WHERE ip_address = 'YOUR_IP'"`).

= How do I unblock my own IP from the admin UI? =

Visit *ReportedIP Hive Light → Blocked IPs*, select the row, and choose "Unblock selected" from the bulk actions menu.

= What data does the plugin send to reportedip.de? =

In Community Network mode only: the IP address, a SHA-256 hash of the submitted username (salted with `wp_salt()`), an integer category ID for the event type, and an optional comment. Plain-text usernames, passwords, domains, or contact details are never transmitted. See the "External services" section for full details.

= Does the plugin protect Application Passwords? =

No. This release protects standard `wp-login.php` logins. Application Passwords use a separate authentication path that is not currently monitored.

= Does it work with WooCommerce login forms? =

Yes. WooCommerce uses the standard `wp_login_failed` action, which the plugin listens to. WooCommerce login attempts are counted alongside regular login attempts.

= My site is behind Cloudflare. Are real IPs detected? =

Set *Trusted Proxy Header* in *Settings → Connection* to `CF-Connecting-IP`. Only enable this when your reverse proxy reliably overrides the header on every incoming request — otherwise the header can be spoofed.

== Screenshots ==

1. Connection tab — operation mode, access key, reverse-proxy header.
2. Protection tab — thresholds and the progressive block ladder.
3. Privacy tab — cache durations, queue retention, uninstall behaviour.
4. Blocked IPs — admin list table with bulk-unblock action.
5. Empty state — what new installs see before any IPs are blocked.

== External services ==

This plugin can connect to the ReportedIP API at `https://reportedip.de`. All
external requests are **opt-in only** — they are made exclusively when (a) a
"Community Access Key" has been entered in the plugin settings and (b) the
"Operation Mode" is set to "Community Network". The default mode is "Local
Shield", which performs zero external requests.

= Endpoint 1: IP-reputation lookup =

* URL: `https://reportedip.de/wp-json/reportedip/v2/check?ip={ip}`
* HTTP verb: GET
* Auth header: `X-Key: {your-access-key}`
* Trigger: a login attempt reaches `wp_authenticate_user`
* Timeout: 2 seconds (fail-open — login proceeds when the API does not respond)
* Data sent: only the source IP address of the current login attempt
* Data NOT sent: usernames, passwords, cookies, server identifiers, domain name

= Endpoint 2: Blocked-IP report =

* URL: `https://reportedip.de/wp-json/reportedip/v2/report`
* HTTP verb: POST (JSON body)
* Auth header: `X-Key: {your-access-key}`
* Trigger: a brute-force / spray threshold has been exceeded; the report is
  queued in the database and dispatched by a 15-minute cron job
* Data sent: the offending IP, an integer category ID for the threat type,
  and a short human-readable comment (e.g. "5 failed logins in 15 minutes")
* Data NOT sent: usernames in plain text, passwords, full request bodies,
  domain name, contact information

= Endpoint 3: Access-key verification =

* URL: `https://reportedip.de/wp-json/reportedip/v2/verify-key`
* HTTP verb: GET
* Auth header: `X-Key: {entered-key}`
* Trigger: an administrator clicks "Test connection" in the plugin settings
* Data sent: only the access key under verification

= Hashing of submitted usernames =

When a brute-force attempt is detected and the failing username is recorded
locally, the plugin stores `sha256( username + wp_salt() )` only — never the
plain text. The salted hash is also what would be transmitted with a report,
preventing recipients from recovering the original username.

= Service provider =

* Operator: Patrick Schlesinger, Germany
* Service URL: [reportedip.de](https://reportedip.de)
* Legal notice (Impressum): [reportedip.de/impressum](https://reportedip.de/impressum/)
* Terms of use: [reportedip.de/nutzungsbedingungen](https://reportedip.de/nutzungsbedingungen/)
* Privacy policy: [reportedip.de/datenschutzerklaerung](https://reportedip.de/datenschutzerklaerung/)
* Source code: [github.com/reportedip/reportedip-hive-light](https://github.com/reportedip/reportedip-hive-light)
* Contact: [1@reportedip.de](mailto:1@reportedip.de)

You can switch back to Local Shield mode at any time in *Settings → ReportedIP
Hive → Connection*. Doing so stops all external traffic immediately.

== Bundled assets ==

This plugin ships every stylesheet and script it needs inside the plugin
folder. **No CDN, no Google Fonts, no remote stylesheets, no remote scripts
are loaded** — every asset URL begins with the plugin's own
`wp-content/plugins/reportedip-hive/` path.

The full list of bundled, locally-served assets:

* `assets/css/design-system.css` — design tokens and components used on
  every plugin admin page.
* `assets/css/admin.css` — admin-page overrides on top of the design
  system.
* `assets/css/wizard.css` — standalone styles for the first-run setup
  wizard.
* `assets/js/admin.js` — handles tab switching and the AJAX
  "Test connection" button. Its only network call is `fetch()` against
  WordPress' own `admin-ajax.php` (same origin); no third-party endpoint
  is contacted.
* Inline SVG icons (the shield logo, the menu icon, and trust-badge
  glyphs) are emitted from PHP via `wp_kses()` with an explicit allow-list
  — no `<img>` element points at an external host.

The complete list of files distributed in the WordPress.org ZIP is
visible at *Plugins → Plugin File Editor* once the plugin is installed.

== Third-party services and licences ==

* **GPLv2 (or later) licence text** is bundled with the plugin in the
  `LICENSE` file at the plugin root and is also referenced from the
  plugin header (`License URI: https://www.gnu.org/licenses/gpl-2.0.html`).
* **No third-party PHP, JavaScript, or CSS libraries are bundled** with
  the plugin. There is no Composer `vendor/` directory, no jQuery copy,
  no minified third-party bundle. WordPress itself supplies any global
  scripts (`jquery`, `wp-list-table`, etc.) and the plugin only depends
  on WordPress core APIs.
* The only external HTTP service the plugin can talk to is the
  `https://reportedip.de/wp-json/reportedip/v2/` API, and only when the
  administrator has explicitly enabled Community Network mode — see
  the "External services" section above for the full data flow.

== Privacy ==

* IP addresses are processed under GDPR Art. 6(1)(f) (legitimate interest in network security).
* Usernames are stored as a salted SHA-256 hash; plain-text values are never persisted or transmitted.
* In Local Shield mode (default) no data leaves your server.
* In Community Network mode the data listed above is sent to reportedip.de.
* Data retention is configurable in *Settings → Privacy*. The default attempt window is 15 minutes; the API queue retention is 7 days.
* Activate "Delete all data on uninstall" in *Settings → Privacy* to remove all plugin tables and options when the plugin is deleted.

== Disclaimer ==

ReportedIP Hive Light is provided "as is", without warranty of any kind, express or
implied, including but not limited to warranties of merchantability, fitness
for a particular purpose, and non-infringement. The author shall not be liable
for any claim, damages, or other liability arising from the use of this
software (this is the standard GPLv2-or-later disclaimer; see the LICENSE
file for the full text).

The plugin provides defense-in-depth against brute-force and password-spray
login attacks. It does **not** replace strong passwords, two-factor
authentication, server-level firewalls, or web-application firewalls. No
single security measure offers a 100 % guarantee against compromise. You
remain responsible for the overall security posture of your WordPress site.

The optional Community Network mode forwards data to the third-party service
operated at https://reportedip.de — see the "External services" section
above for the full data flow. Site operators that enable Community Network
mode are responsible for assessing the lawful basis under their applicable
data-protection regime (in the EU, GDPR Art. 6(1)(f) — legitimate interest
in network security — typically applies) and for updating their own privacy
policy accordingly.

== Changelog ==

= 1.3.3 =

* Add `.git` to `.distignore` so the GitHub-Actions deployment no longer
  copies the repository's Git metadata directory into the wp.org SVN.
  The 1.3.2 release accidentally shipped a `trunk/.git/` and
  `tags/1.3.2/.git/`; both have been removed from SVN.

= 1.3.2 =

* Rename the user-facing plugin title to **ReportedIP Hive Light** in the
  WordPress plugin listing, the wp.org listing, the admin menu, the setup
  wizard, the welcome notice, and the privacy-policy suggestion. Plugin
  slug, text domain, class prefix, option keys and database tables are
  unchanged — display-name only, no migration.
* Add a GitHub source-code link to the `Service provider` section of the
  readme and convert the existing reportedip.de URLs to Markdown links so
  the wp.org renderer turns them into clickable anchors.

= 1.3.1 =

* Remove the `DONOTCACHEPAGE` / `DONOTCACHEDB` / `DONOTCACHEOBJECT` defines
  from the block-response path. The HTTP 403 status plus explicit
  `Cache-Control: no-store` and `Pragma: no-cache` headers continue to
  instruct WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed not to
  cache the block page.
* Fix the legal-notice URLs in `readme.txt` to point at the canonical
  Impressum, Nutzungsbedingungen and Datenschutzerklaerung pages on
  reportedip.de.

= 1.3.0 =

* Add long-term "Defended attacks" statistics to the dashboard with
  rolling-window block counts for the last 24 hours, last 7 days, last
  30 days, and all time.

= 1.2.0 =

* Add a Dashboard landing page (Settings → ReportedIP Hive) with 4 stat
  cards (active blocks, blocks last 24 h, attempts last 24 h, whitelist
  size), an operation-mode summary, the report-queue status (pending /
  processing / completed / failed), and a recent-activity list.
* Track outbound API calls per rolling 1-hour window and visualise the
  quota with a progress bar on the dashboard. When the configured maximum
  is reached, IP-reputation lookups are skipped (login still proceeds —
  fail-open) until the window resets.
* Settings, Blocked IPs and Whitelist are now sub-pages of the new
  Dashboard.

= 1.1.0 =

* Add an IP whitelist with optional CIDR ranges, expiry, and a dedicated
  admin page (Settings → ReportedIP Hive → Whitelist).
* Add a 4-step setup wizard that runs on first activation (Welcome →
  Operation mode → Protection → Done).
* Replace the WordPress default "Settings saved" notice with a design-system
  alert rendered immediately under the page header.
* Polish admin chrome — branded shield logo, three iconed trust badges
  (Security Focused / GDPR Compliant / Made in Germany), better contrast on
  the welcome notice.
* Hide the Community Access Key card unless the operation mode is set to
  Community Network.
* Schema bumped to v1.1.0 (idempotent migration on activation).

= 1.0.0 =

* Initial release.

== Upgrade Notice ==

= 1.3.3 =
Hygiene fix: stops the GitHub-Actions deployment from copying the `.git` directory into the wp.org SVN. No functional changes.

= 1.3.2 =
Renames the user-facing plugin title to ReportedIP Hive Light. Slug, text domain, options and database tables are unchanged — no migration required.

= 1.3.1 =
Removes the DONOTCACHE* defines from the block-response path and fixes three legal-notice URLs in the readme.

= 1.3.0 =
Adds long-term defended-attacks statistics (24 h / 7 d / 30 d / all time) to the dashboard.

= 1.2.0 =
Adds a dashboard with stats, queue status, and a per-hour API quota tracker.

= 1.1.0 =
Adds an IP whitelist, a setup wizard, and design-system polish. Schema
migrates automatically on activation.

= 1.0.0 =
Initial release.
