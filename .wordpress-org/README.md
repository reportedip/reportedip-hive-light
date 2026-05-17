# wordpress.org listing assets

This folder is uploaded to the SVN `assets/` directory by the
`10up/action-wordpress-plugin-deploy` action on every tag push. Files placed
here are **not** included in the plugin ZIP — they are the wordpress.org
plugin-page graphics only.

## Required files (replace the placeholders before the first release)

| File | Dimensions | Purpose |
|---|---|---|
| `icon-256x256.png`     | 256 x 256 px PNG | Plugin icon (high-DPI variant) |
| `icon-128x128.png`     | 128 x 128 px PNG | Plugin icon (legacy variant) |
| `banner-1544x500.png`  | 1544 x 500 px PNG | Plugin-page header banner (high-DPI) |
| `banner-772x250.png`   | 772 x 250 px PNG | Plugin-page header banner (legacy) |
| `screenshot-1.png`     | 1280 x 720 px PNG | Connection tab |
| `screenshot-2.png`     | 1280 x 720 px PNG | Protection tab |
| `screenshot-3.png`     | 1280 x 720 px PNG | Privacy tab |
| `screenshot-4.png`     | 1280 x 720 px PNG | Blocked IPs list |
| `screenshot-5.png`     | 1280 x 720 px PNG | Empty state |

Captions live in `readme.txt` under `== Screenshots ==`.

## Design rules

- Use only the `--rip-primary` indigo (#4F46E5) and the design-system tokens.
- No "Pro", "Premium", "Free Edition", or pricing visuals.
- Use RFC 5737 demonstration IPs (`192.0.2.x`, `198.51.100.x`) in any
  IP-address screenshots — never real customer data.
