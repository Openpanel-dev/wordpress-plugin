# OpenPanel WordPress Plugin

A privacy-friendly analytics WordPress plugin that integrates [OpenPanel](https://openpanel.dev) with ad-blocker resistance.

## Features

- 🚀 **Ad-Blocker Resistant** - Serves scripts from your domain
- 🔒 **Privacy-Friendly** - Cookie-less tracking, no consent banners needed
- ⚡ **Performance Optimized** - Local script caching with CDN fallback
- 📊 **Real-Time Analytics** - Instant insights without delays

## Quick Start

1. Get your Client ID from [OpenPanel.dev](https://openpanel.dev)
2. Install the plugin in WordPress
3. Configure your Client ID in **Settings → OpenPanel**
4. Start tracking!

## Self-Hosted OpenPanel

Runs on your own infrastructure? The plugin ships with hosted endpoints by default
(`https://api.openpanel.dev` / `https://openpanel.dev/op1.js`), but both are configurable:

- **Settings → OpenPanel → API URL** – base URL of your self-hosted OpenPanel API
  (e.g. `https://analytics.example.com/api`)
- **Settings → OpenPanel → Tracking script URL** – optional, your own copy of `op1.js`

For code-level control, the effective endpoints follow filter > setting > default:

| Filter | Purpose |
| --- | --- |
| `openpanel_api_url` | Override the API base URL the proxy forwards to |
| `openpanel_script_url` | Override the URL `op1.js` is loaded from |
| `openpanel_proxy_allowed_hosts` | Extend the hostname whitelist the proxy may forward to |

```php
add_filter('openpanel_api_url', fn () => 'https://analytics.example.com/api');
```

## Documentation

For detailed installation instructions, FAQ, and full documentation, see [`readme.txt`](openpanel/readme.txt).

## License

GPLv2 or later - see [LICENSE](openpanel/LICENSE)

---

**Learn more:** [OpenPanel.dev](https://openpanel.dev)
