<div align="center">

# 🐱 Cat Cache (MddCache)

[![Release Version](https://img.shields.io/github/v/release/xa1st/MddCache?style=flat-square)](https://github.com/xa1st/MddCache/releases/latest)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP Version](https://img.shields.io/badge/PHP-8.0+-4F5B93.svg?style=flat-square)
[![Required Typecho Version](https://img.shields.io/badge/Typecho-1.2+-167B94.svg?style=flat-square)](https://typecho.org)

**A lightweight Redis cache plugin for Typecho**  
**Built on phpredis, with per-request connections and SCAN-based cleanup, ready to use out of the box**

[简体中文](README.md) | [English](README_EN.md)

</div>

## ✨ Features

- 🐱 **Lightweight** - Depends only on the PHP Redis extension, works out of the box
- ⚡ **Redis Cache** - High-performance Redis caching. One connection is reused per request, and multiple prefixes are cleared with a single SCAN
- 🔄 **Auto Invalidation** - Invalidates related caches when posts or pages are published, updated, or deleted, and when comments are posted, replied to, edited, moderated, or deleted
- 🔒 **Safe & Reliable** - Comprehensive error handling with silent failures that never block your business logic
- 🔑 **Key Prefix Isolation** - A global key prefix avoids conflicts in multi-site setups. It is applied through phpredis `OPT_PREFIX`, with prefix-based batch cleanup
- 📡 **Redis SSL** - Optional SSL certificate path for encrypted connections
- ⚙️ **Easy Configuration** - Visual admin configuration panel

## Redis Cache

- Based on the native PHP Redis extension (phpredis)
- Uses `connect`. The connection is created once per request and then reused
- The global key prefix is applied with `Redis::OPT_PREFIX`. `get` / `set` / `delete` / `has` / `flush` add it automatically, so callers pass keys without the prefix
- Batch cleanup uses `SCAN` with a `COUNT` hint of 1000 instead of `KEYS`. Every prefix in one cleanup is matched during that single scan, and matched keys are removed with `UNLINK`
- Values are stored with PHP `serialize`, including arrays, objects, and boolean `false`
- Supports password authentication, database selection, and SSL. SSL is enabled when a certificate path is set, and the server certificate is verified

## Installation

1. Download the plugin to your Typecho plugins directory: `usr/plugins/MddCache/`
2. Activate the plugin under **Console** -> **Plugins** in the Typecho admin panel
3. Click **Settings** to configure the Redis connection and cache cleanup rules

## Configuration

### Basic Configuration

1. **Cache Key Prefix**: A unified prefix for all cache keys, default `typecho_` (falls back to the default if left empty)

### Redis Connection Settings

- **Redis Host**: Redis server address, default `127.0.0.1`
- **Redis Port**: Default `6379`
- **Redis Password**: Authentication password, leave empty for no password
- **Redis Database**: Database index, default `0`
- **Redis SSL Certificate Path** (optional): Server-side SSL certificate path, e.g. `/path/to/cert.pem`. Enables encrypted connections once configured

> **Note**: The PHP Redis extension (phpredis) is required

### Cache Update Strategy

| Setting | Default | Description |
|-------|-------|------|
| **Update cache on content publish/delete** | Enabled | Handles cache when publishing, editing, or deleting posts and pages. Saving a draft does not trigger it |
| **Cache keys/prefixes to flush on content update** | Empty | Empty means flush all caches with the global prefix; separate multiple with `\|` |
| **Update cache on comment changes** | Disabled | Handles cache when a comment is posted, replied to, edited, moderated, or deleted |
| **Cache keys/prefixes to flush on comment changes** | Empty | Empty means flush all caches with the global prefix; separate multiple with `\|` |

Cache key/prefix matching rules:
- Only one trailing `*` is allowed: `post_*` deletes every key starting with `post_`
- Without `*`: delete one exact key, e.g. `post_123`
- Patterns such as `*sidebar` or `cache_*_html` are skipped so they cannot wipe the whole prefix
- Comment rules support `{cid}`, replaced with the ID of the content that comment belongs to. `commentsList_{cid}` deletes only that content's comment-list cache
- If the content ID is unavailable, only the rule containing `{cid}` is skipped. The other rules still run

### Automatic Cleanup Coverage

The plugin does not clear cache during normal front-end browsing. Content and comments are controlled by their own switches. Cleanup is skipped when a switch is off or Redis is unavailable, without affecting the original operation.

| Action | Clears cache | Rules used |
|---|---|---|
| Publish, republish, or delete a post | Yes | Content rules |
| Publish, republish, or delete a page | Yes | Content rules |
| Post a comment on the front end | Yes | Comment rules |
| Reply to, edit, approve, mark waiting, mark spam, or delete a comment in the admin | Yes | Comment rules |
| Save a post or page draft | No | — |
| Change only a post or page status (public, hidden, private, waiting) | No | — |
| Upload, modify, or delete an attachment | No | — |
| Trackback or Pingback | No | — |
| Change categories, tags, user profiles, or plugin settings | No | — |
| Normal front-end browsing | No | — |

If a comment's new status is the same as its old status, Typecho does not fire the moderation hook, so nothing is cleared. Rules are not limited to the content being changed: a content rule of `post_*` deletes every `post_` cache when any post is published. After upgrading, disable and re-enable the plugin so the new hooks are registered.

## Usage

Call the static methods on `Plugin` directly. There is no cache instance to create. The Redis connection is created once per request. If the Redis extension is missing, the connection fails, or the password is wrong, `get` / `set` / `delete` / `has` / `flush` return the default value or `false` instead of throwing. Automatic cleanup catches the same failures and skips itself, so publishing and commenting still succeed.

### Common API

```php
use TypechoPlugin\MddCache\Plugin;

// Set a cache (expires in 1 hour)
Plugin::set('mykey', $data, 3600);

// Get a cached value
$data = Plugin::get('mykey', $default);

// Delete a cache
Plugin::delete('mykey');

// Check whether a cache exists and has not expired
if (Plugin::has('mykey')) {
    // ...
}

// Flush all caches with the global prefix
Plugin::flush('');

// Batch cleanup by prefix (global prefix + custom prefix)
Plugin::flush('sidebar_');
```

`flush()` prefix matching rules (assuming a global prefix of `typecho_`):

```php
Plugin::flush('');         // Deletes all 'typecho_*' caches
Plugin::flush('post_');    // Deletes all 'typecho_post_*' caches
Plugin::flush('user_123'); // Deletes all 'typecho_user_123*' caches
```

Notes:

- Do not include the global prefix in key names. phpredis `OPT_PREFIX` adds it automatically
- `get()` returns `$default` on a miss. A cached value of `false` is returned as-is and is not treated as a miss

### In Your Theme

```php
use TypechoPlugin\MddCache\Plugin;

// Get and cache sidebar data
function getSidebarData() {
    $cacheKey = 'sidebarData';

    $data = Plugin::get($cacheKey);
    if ($data !== null) return $data;

    // Query data...
    $data = ['categories' => $categories, 'tags' => $tags];

    Plugin::set($cacheKey, $data, 3600);
    return $data;
}
```

### In functions.php

```php
use TypechoPlugin\MddCache\Plugin;

Plugin::set('mykey', $data, 3600);   // Cache for 1 hour
$data = Plugin::get('mykey', $default);
```

## Technical Architecture

```
MddCache/
├── Plugin.php                  # Plugin entry, Redis connection, and cache operations
├── CHANGELOG.md                # Changelog
├── LICENSE                     # License
├── README.md                   # Documentation (Chinese)
└── README_EN.md                # Documentation (English)
```

## System Requirements

- Typecho 1.2+
- PHP 8.0+
- PHP Redis extension (phpredis)
- A reachable Redis server

## Changelog

### v1.2.6
- 🔄 **Invalidation coverage**: page publish/delete and comment replies, edits, moderation, and deletion now trigger cleanup
- 🔑 **Stricter wildcards**: only one trailing `*` is accepted; a missing `{cid}` skips only that one rule
- ⚡ **Combined cleanup**: all prefixes in one cleanup share a single `SCAN`, and matches are removed with `UNLINK`
- 📝 **Cleanup coverage**: documents which actions clear cache. Drafts, content status changes, attachments, and trackbacks do not

### v1.2.4
- 💬 **Comment key placeholder**: comment cleanup rules support `{cid}`, replaced with the commented post's ID when a comment is posted

See [CHANGELOG.md](CHANGELOG.md) for the full history.

## License

[MIT License](LICENSE)

## Author

Cat DongDong (猫东东) (xa1st) <xa1st@outlook.com>

## Links

- [GitHub Repository](https://github.com/xa1st/MddCache)
- [Issue Tracker](https://github.com/xa1st/MddCache/issues)
- [Typecho](https://typecho.org/)