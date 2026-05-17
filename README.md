# ReportedIP Hive Light

Lightweight brute-force login protection for WordPress, with optional
community-powered IP reputation checks via reportedip.de.

This is the public source repository. The user-facing description, FAQ and
screenshots live in [readme.txt](readme.txt) for the
[wordpress.org/plugins/reportedip-hive](https://wordpress.org/plugins/reportedip-hive/)
listing.

## Highlights

- `wp_login_failed` brute-force counter with atomic upsert
- Progressive block-duration ladder (5 min → 7 d)
- Optional Community Network mode with circuit-breaker-protected reputation
  lookup and asynchronous report queue
- Trusted-proxy header whitelist for sites behind Cloudflare/AWS/NGINX
- Three-tab settings page (Connection / Protection / Privacy)
- WordPress Coding Standards, PHPStan level 5, WordPress.org Plugin Check —
  all green in CI

## Requirements

- PHP 8.1+
- WordPress 6.0+
- MySQL/MariaDB with InnoDB

## Install

From wordpress.org (*Plugins → Add New → search "ReportedIP Hive Light"*) or by
uploading the ZIP from the latest GitHub release. Activate from the *Plugins*
screen, then visit *ReportedIP Hive Light → Settings*.

## Development

The development workspace (Docker stack, lint/test/build helpers) lives one
level up. From this `dev/` directory you can run:

```bash
composer install
vendor/bin/phpcs
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite unit
```

The full QA pipeline (`./run.sh check-all` from the workspace root) adds the
WordPress.org Plugin Check on top.

## License

GPLv2 or later. See [LICENSE](LICENSE).
