=== ReportedIP Hive Light ===
Contributors: reportedip
Tags: limit login attempts, brute force, login security, security, firewall
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Limit login attempts, stop brute-force and password-spray attacks with progressive IP blocking. Free, GDPR-friendly, no external calls by default.

== Description ==

**ReportedIP Hive Light is a free WordPress plugin that limits login attempts and blocks brute-force and password-spray attacks on wp-login.php.** It counts failed logins per IP address, locks attackers out for a growing period of time and, if you want, checks every login attempt against the reportedip.com community blocklist. It works out of the box, needs no account and sends no data anywhere unless you switch that on.

The plugin is deliberately focused on one job: keeping attackers away from your login form. No bloat, no tracking, no nag screens.

**Why site owners choose ReportedIP Hive Light**

* **Works in 60 seconds.** Activate it, and the default settings protect your login right away. A four-step setup wizard explains every choice.
* **Free and complete.** Every feature in this plugin is free. Nothing is locked, there is no trial and no account is needed.
* **Privacy by default.** In the default Local Shield mode the plugin makes zero outbound network requests. All data stays in your own database.
* **GDPR-friendly by design.** Usernames are only stored as a salted SHA-256 hash and never leave your server. IP processing is based on GDPR Art. 6(1)(f). A ready-made text for your privacy policy is added to *Settings → Privacy*.
* **Lightweight.** Nine core classes, no third-party libraries, no CDN, no Google Fonts, no remote scripts. The check runs only on login requests, so front-end page speed is not affected.
* **Stops password spraying, not only brute force.** Attackers who try many different usernames from one IP are detected separately from classic password guessing.
* **Escalating blocks.** Repeat offenders are locked out for longer each time: 5 min → 15 min → 30 min → 24 h → 48 h → 7 days.
* **Optional community protection.** With a free Community Access Key, an IP that already attacked other sites can be refused before its first password attempt on yours.
* **Never locks out real users by accident.** Community lookups have a two-second timeout and fail open, and a whitelist protects your own office or agency IPs.
* **Made in Germany.** Developed and operated by Patrick Schlesinger in Germany, open source under GPLv2.

**Features at a glance**

* Limit login attempts per IP with a configurable threshold and time window (default: 5 failed logins in 15 minutes)
* Password-spray detection with its own threshold and time window (default: 5 usernames in 10 minutes)
* Progressive block ladder with configurable steps; the ladder starts over once an IP has not been blocked for 30 days
* Report-only mode that detects and reports attacks without blocking, useful for staging sites
* IP whitelist for single IPv4 and IPv6 addresses and IPv4 CIDR ranges, with an optional expiry date
* Blocked IPs list with bulk unblock
* Dashboard with active blocks, attempts and blocks in the last 24 hours, defended attacks for 24 h, 7 days, 30 days and all time, and a list of recent activity
* Real client IP detection behind Cloudflare, load balancers and reverse proxies (CF-Connecting-IP, X-Forwarded-For, X-Real-IP, True-Client-IP)
* Optional Community Network mode: IP reputation lookup at login plus an asynchronous report queue with deduplication, retry and a configurable hourly API limit
* Block page with HTTP 403 and no-cache headers, so cache plugins never store it
* Clean uninstall: optionally removes all tables and options, also on multisite
* Translation ready, with German, French and Spanish translations

**Two operating modes**

* **Local Shield (default).** Counts failed logins per IP and blocks attackers based on your thresholds. The plugin makes zero outbound network requests in this mode, all data stays on your server.
* **Community Network (optional).** When you enter a free Community Access Key from reportedip.com, the plugin also checks the source IP against the reportedip.com community database during login attempts and shares blocked IPs back to the community. Both calls are clearly disclosed in the settings and in the "External services" section below.

**How it works**

* `wp_login_failed` increments a per-IP counter with an atomic upsert, so there are no race conditions under concurrent attacks.
* When the counter exceeds your threshold, the IP is blocked for the next duration on the progressive ladder.
* `wp_authenticate_user` stops known-bad IPs before WordPress core checks the password.
* The block response is an HTTP 403 with `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache`, which WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed Cache respect.

**Compatibility**

* WordPress 6.1 to 7.1, PHP 8.1 or newer
* WooCommerce "My Account" logins and every other login form that uses the standard `wp_login_failed` action
* Cache plugins: WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache
* Cloudflare and other reverse proxies via the trusted proxy header setting

**For developers**

* Filters: `reportedip_hive_is_whitelisted`, `reportedip_hive_get_client_ip`, `reportedip_hive_api_endpoint`.
* Actions: `reportedip_hive_log`, `reportedip_hive_ip_blocked`, `reportedip_hive_report_queued`.
* Source code and issue tracker: [github.com/reportedip/reportedip-hive-light](https://github.com/reportedip/reportedip-hive-light)

**Looking for more? ReportedIP Hive Full Edition and Hive PRO**

Hive Light is complete on its own and stays free, nothing in this plugin is locked or limited. If you need more than login protection, the separate [Full Edition](https://reportedip.com/products/wordpress-plugin/) (also free and GPL-2.0, distributed via GitHub) adds 16 attack sensors including a Web Application Firewall, four 2FA methods (authenticator app, e-mail, SMS, passkeys) and multisite support. The optional Hive PRO subscription on top of the Full Edition connects your sites to a managed, EU-hosted 2FA relay for SMS and e-mail codes (no Twilio account needed) and covers three domains with one licence.

== Installation ==

1. In your WordPress admin go to *Plugins → Add New*, search for "ReportedIP Hive Light" and click *Install Now*. Alternatively upload the `reportedip-hive` folder to `/wp-content/plugins/`.
2. Activate the plugin on the *Plugins* screen.
3. Follow the setup wizard, or go to *ReportedIP Hive Light → Settings* and review the Connection, Protection and Privacy tabs.

The plugin protects your login right after activation in Local Shield mode. No configuration is required.

== Frequently Asked Questions ==

= How does ReportedIP Hive Light limit login attempts? =

It counts failed logins per IP address. With the default settings, an IP that fails 5 logins within 15 minutes is blocked, first for 5 minutes, then for longer periods up to 7 days if it keeps attacking. You can change the threshold, the time window and every step of the ladder in *Settings → Protection*.

= What is a password-spray attack? =

Instead of guessing many passwords for one account, the attacker tries one or two common passwords against many usernames. A plain login limiter often misses this. Hive Light counts the distinct usernames tried from one IP and blocks it when it crosses its own threshold (default: 5 usernames in 10 minutes).

= Do I need an account or an access key? =

No. The default mode is Local Shield, which uses only your site's data and does not contact any external service. The plugin is fully functional without a key.

= How do I get a Community Access Key? =

Register at reportedip.com. The Community Access Key is free.

= Is the plugin GDPR compliant? =

It is built for GDPR compliance. IP addresses are processed on the basis of legitimate interest in network security (Art. 6(1)(f)), usernames are only stored as a salted hash and never transmitted, and Local Shield mode sends no data at all. The plugin adds a suggested text to the WordPress privacy policy guide. If you enable Community Network mode, mention reportedip.com in your privacy policy.

= Will the plugin slow down my site? =

No. It only runs on login requests and in a cron job every 15 minutes. Normal page views are not touched, and no scripts or styles are loaded on the front end.

= Will the plugin lock me out of my own site? =

It can, if you fail several logins from your own IP. Add your IP to the whitelist to prevent this. To recover, wait until the block expires or delete the row from the `wp_reportedip_hive_blocked` table, for example with WP-CLI: `wp db query "DELETE FROM wp_reportedip_hive_blocked WHERE ip_address = 'YOUR_IP'"`.

= How do I unblock an IP from the admin? =

Go to *ReportedIP Hive Light → Blocked IPs*, select the row and choose "Unblock selected" from the bulk actions menu.

= Can I whitelist my office or agency IP? =

Yes. *ReportedIP Hive Light → Whitelist* accepts single IPv4 and IPv6 addresses and IPv4 CIDR ranges such as `203.0.113.0/24`, each with an optional reason and expiry date.

= What data does the plugin send to reportedip.com? =

Only in Community Network mode: the IP address, an integer category ID for the event type and a short comment such as "5 failed logins in 15 minutes". Usernames (plain or hashed), passwords, domains and contact details are never transmitted. See the "External services" section for details.

= Does it work with WooCommerce login forms? =

Yes. WooCommerce uses the standard `wp_login_failed` action, which the plugin listens to. WooCommerce login attempts are counted together with regular logins.

= My site is behind Cloudflare. Are real visitor IPs detected? =

Yes. Set *Trusted Proxy Header* in *Settings → Connection* to `CF-Connecting-IP`. Only enable a proxy header when your reverse proxy reliably overwrites it on every request, otherwise attackers can spoof it.

= Does it work with page cache plugins? =

Yes. The block page is sent with HTTP status 403 and explicit no-cache headers, so WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed Cache do not store it.

= Does the plugin protect Application Passwords and XML-RPC? =

No. This release protects the standard login through `wp-login.php` and every form that uses it. Application Passwords and XML-RPC use separate authentication paths that are not monitored.

= Which languages are available? =

English, German (informal, formal and Austrian), French and Spanish. Translations are managed on translate.wordpress.org, where you can help with other languages.

= Is there a Pro version? =

Hive Light itself has no paid tier, everything you see is free and fully functional. There is a separate Full Edition (free, GPL-2.0, on GitHub) with a WAF, 2FA and multisite support, and an optional Hive PRO subscription that adds a managed EU 2FA relay and multi-domain cover. See https://reportedip.com/products/wordpress-plugin/ for the comparison.

== Screenshots ==

1. Connection tab: operation mode, access key and reverse-proxy header.
2. Protection tab: thresholds and the progressive block ladder.
3. Privacy tab: cache durations, queue retention and uninstall behaviour.
4. Blocked IPs: admin list with bulk unblock.
5. Empty state: what new installs see before any IP is blocked.

== External services ==

This plugin can connect to the ReportedIP API at `https://reportedip.com`. All external requests are **opt-in only**. They are made exclusively when (a) a "Community Access Key" has been entered in the plugin settings and (b) the "Operation Mode" is set to "Community Network". The default mode is "Local Shield", which performs zero external requests.

= Endpoint 1: IP-reputation lookup =

* URL: `https://reportedip.com/wp-json/reportedip/v2/check?ip={ip}`
* HTTP verb: GET
* Auth header: `X-Key: {your-access-key}`
* Trigger: a login attempt reaches `wp_authenticate_user`
* Timeout: 2 seconds (fail-open, the login proceeds when the API does not respond)
* Data sent: only the source IP address of the current login attempt
* Data NOT sent: usernames, passwords, cookies, server identifiers, domain name

= Endpoint 2: Blocked-IP report =

* URL: `https://reportedip.com/wp-json/reportedip/v2/report`
* HTTP verb: POST (JSON body)
* Auth header: `X-Key: {your-access-key}`
* Trigger: a brute-force or spray threshold has been exceeded; the report is queued in the database and sent by a 15-minute cron job
* Data sent: the offending IP, an integer category ID for the threat type and a short comment (e.g. "5 failed logins in 15 minutes")
* Data NOT sent: usernames, passwords, request bodies, domain name, contact information

= Endpoint 3: Access-key verification =

* URL: `https://reportedip.com/wp-json/reportedip/v2/verify-key`
* HTTP verb: GET
* Auth header: `X-Key: {entered-key}`
* Trigger: an administrator clicks "Test connection" in the plugin settings
* Data sent: only the access key under verification

= Hashing of submitted usernames =

When a failed login is recorded locally, the plugin stores `sha256( username + wp_salt() )` only, never the plain text. The hash stays on your server; reports do not contain it.

= Service provider =

* Operator: Patrick Schlesinger, Germany
* Service URL: [reportedip.com](https://reportedip.com)
* Legal notice (Impressum): [reportedip.com/impressum](https://reportedip.com/impressum/)
* Terms of use: [reportedip.com/nutzungsbedingungen](https://reportedip.com/nutzungsbedingungen/)
* Privacy policy: [reportedip.com/datenschutzerklaerung](https://reportedip.com/datenschutzerklaerung/)
* Source code: [github.com/reportedip/reportedip-hive-light](https://github.com/reportedip/reportedip-hive-light)
* Contact: [1@reportedip.com](mailto:1@reportedip.com)

You can switch back to Local Shield mode at any time in *ReportedIP Hive Light → Settings → Connection*. This stops all external traffic immediately.

== Bundled assets and licences ==

Every stylesheet and script ships inside the plugin folder: `assets/css/design-system.css`, `assets/css/admin.css`, `assets/css/wizard.css` and `assets/js/admin.js` (tab switching and the "Test connection" button, which only calls WordPress' own `admin-ajax.php`). Icons are inline SVG. **No CDN, no Google Fonts, no remote stylesheets or scripts are loaded**, and no third-party PHP, JavaScript or CSS library is bundled. The GPLv2 licence text is included in the `LICENSE` file.

== Privacy ==

* IP addresses are processed under GDPR Art. 6(1)(f) (legitimate interest in network security).
* Usernames are stored locally as a salted SHA-256 hash and are never transmitted, neither in plain text nor hashed.
* In Local Shield mode (default) no data leaves your server.
* In Community Network mode the data listed under "External services" is sent to reportedip.com.
* Data retention is configurable in *Settings → Privacy*. The default attempt window is 15 minutes; the API queue retention is 7 days.
* Activate "Delete all data on uninstall" in *Settings → Privacy* to remove all plugin tables and options when the plugin is deleted.

== Disclaimer ==

ReportedIP Hive Light is provided "as is", without warranty of any kind (see the LICENSE file for the full GPLv2 text). It adds a layer of defence against brute-force and password-spray login attacks but does not replace strong passwords, two-factor authentication or server-level firewalls. No single security measure is a 100 % guarantee. Site operators who enable Community Network mode are responsible for assessing the lawful basis under their data-protection regime and for updating their privacy policy.

== Changelog ==

= 1.3.9 =

* German (also formal and Austrian), French and Spanish translations
  are now bundled with the plugin.
* The plugin now loads its bundled translations from the `languages`
  folder. Language packs from translate.wordpress.org still take
  precedence.
* Rewritten plugin description and FAQ: every feature is now listed,
  and the documentation no longer mentions a filter that does not exist.

= 1.3.8 =

* Privacy texts corrected. The readme, the settings page and the privacy
  policy suggestion said a report carries a hashed username and a
  timestamp. It never did: a report contains only the IP address, the
  category ID and a short comment. The username hash stays on your server.
* Tested up to WordPress 7.1.

= 1.3.7 =

* The ReportedIP service has moved from reportedip.de to reportedip.com.
  All plugin URLs (product page, legal pages, API endpoint default) and
  the support contact address now point at the new domain.
* Existing installations that still use the former default API endpoint
  are migrated automatically on upgrade; custom endpoint values are left
  untouched. Schema/data migrations now also run on plugin updates, not
  only on activation.

= 1.3.6 =

* Update the plugin homepage to point at the product page on
  reportedip.de instead of the wp.org listing itself.
* Document the relationship to the free Full Edition and the optional
  Hive PRO subscription in the readme description and FAQ.
* Add a Full Edition & PRO link to the plugin's row in the Plugins list
  and a static footer card on the plugin's own admin pages. No
  protection-logic changes.
* Declare compatibility with WordPress 7.0 and raise the minimum
  supported version to 6.1 (required by the `wp_cache_flush_group()`
  call in the uninstall routine).

= 1.3.5 =

* Add wp.org plugin-directory listing assets: 128x128 + 256x256 icons
  (static PNG and animated GIF with a heartbeat-pulse loop), 1544x500
  + 772x250 hero banners, and five 1280x720 screenshots covering the
  Connection, Protection and Privacy tabs, the Blocked IPs list view
  and the empty state. No code change in this release.

= 1.3.4 =

* Restore the design-system CSS frame on the Settings, Blocked IPs and
  Whitelist sub-pages. The 1.3.2 display rename changed the WordPress
  submenu hook suffix from `reportedip-hive_page_*` to
  `reportedip-hive-light_page_*`, but the enqueue gate still matched the
  old prefix and silently skipped the asset enqueue. Hook suffixes are
  now captured from the menu-API return values so the gate stays
  correct regardless of the menu title.
* Stop the API report queue from filling with duplicates during a
  sustained brute-force. `wp_login_failed` now short-circuits when the
  source IP is already blocked, and `queue_api_report` deduplicates
  against any open (`pending` or `processing`) report for the same IP.
  One incident yields exactly one outbound community report instead of
  one per retry; the block-escalation ladder no longer steps on every
  attempt against an already-locked door.

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
  unchanged. Display name only, no migration.
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
  is reached, IP-reputation lookups are skipped (login still proceeds,
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
* Polish admin chrome: branded shield logo, three iconed trust badges
  (Security Focused / GDPR Compliant / Made in Germany), better contrast on
  the welcome notice.
* Hide the Community Access Key card unless the operation mode is set to
  Community Network.
* Schema bumped to v1.1.0 (idempotent migration on activation).

= 1.0.0 =

* Initial release.

== Upgrade Notice ==

= 1.3.9 =
Adds German, French and Spanish translations. No protection-logic changes.

= 1.3.8 =
Privacy texts now match what the plugin sends: IP address, category ID and a short comment, no username hash. No protection-logic changes.

= 1.3.7 =
The ReportedIP service moved to reportedip.com. All URLs and the default API endpoint are updated; installations on the former default endpoint migrate automatically. No protection-logic changes.

= 1.3.6 =
Updated plugin homepage; readme and admin pages now document the relationship to the Full Edition and Hive PRO. No functional changes.

= 1.3.4 =
Bug fixes: restores the design-system header/footer on the Settings, Blocked IPs and Whitelist sub-pages (regression from 1.3.2) and stops the API report queue from filling with duplicates during a sustained brute-force.

= 1.3.3 =
Hygiene fix: stops the GitHub-Actions deployment from copying the `.git` directory into the wp.org SVN. No functional changes.

= 1.3.2 =
Renames the user-facing plugin title to ReportedIP Hive Light. Slug, text domain, options and database tables are unchanged (no migration required).

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
