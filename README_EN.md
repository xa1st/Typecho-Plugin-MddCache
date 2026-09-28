<div align="center">

# 🐱 Cat Cache (MddCache)

[![Release Version](https://img.shields.io/github/v/release/xa1st/MddCache?style=flat-square)](https://github.com/xa1st/MddCache/releases/latest)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP Version](https://img.shields.io/badge/PHP-8.0+-4F5B93.svg?style=flat-square)
[![Required Typecho Version](https://img.shields.io/badge/Typecho-1.2+-167B94.svg?style=flat-square)](https://typecho.org)

**A lightweight Redis cache plugin for Typecho**  
**Built on phpredis, with persistent connections and SCAN-based cleanup, ready to use out of the box**

[简体中文](README.md) | [English](README_EN.md)

</div>

## ✨ Features

- 🐱 **Lightweight** - Depends only on the PHP Redis extension, works out of the box
- ⚡ **Redis Cache** - High-performance Redis caching with persistent connections and SCAN-based streaming cleanup, built for high concurrency
- 🔄 **Auto Invalidation** - Automatically invalidates related caches on post publish/update/delete and on new comments
- 🔒 **Safe & Reliable** - Comprehensive error handling with silent failures that never block your business logic
- 🔑 **Key Prefix Isolation** - A global key prefix avoids conflicts in multi-site setups. It is applied through phpredis `OPT_PREFIX`, with prefix-based batch cleanup
- 📡 **Redis SSL** - Optional SSL certificate path for encrypted connections
- ⚙️ **Easy Configuration** - Visual admin configuration panel

## Redis Cache

- Based on the native PHP Redis extension (phpredis)
- Uses `pconnect` persistent connections to reduce connection overhead
- The global key prefix is applied with `Redis::OPT_PREFIX`. `get` / `set` / `delete` / `has` / `flush` add it automatically, so callers pass keys without the prefix
- Batch cleanup uses `SCAN` in batches of up to 100 keys instead of `KEYS`, so it does not block the server. The match pattern is prefixed the same way
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
| **Update cache on post publish/delete** | Enabled | Automatically handles cache when publishing, editing or deleting posts |
| **Cache keys/prefixes to flush on post update** | Empty | Empty means flush all caches with the global prefix; separate multiple with `\|` |
| **Update cache on comment** | Disabled | Automatically handles cache when a comment is posted |
| **Cache keys/prefixes to flush on comment** | Empty | Empty means flush all caches with the global prefix; separate multiple with `\|` |

Cache key/prefix matching rules:
- With the `*` wildcard: batch-delete by prefix, e.g. `post_*` deletes all caches starting with `post_`
- Without wildcard: delete a single exact key, e.g. `post_123`
- Comment cleanup rules support a `{cid}` placeholder. When a comment is posted, it is replaced with the ID of the post that comment belongs to. For example, `commentsList_{cid}` deletes only that post's comment-list cache

## Usage

Call the static methods on `Plugin` directly. There is no cache instance to create. The Redis connection is created once per request. These methods throw if the Redis extension is missing, the connection fails, or the password is wrong. Automatic cleanup on post publish/delete and on new comments catches that exception and skips cleanup, so publishing and commenting still succeed.

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