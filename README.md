<div align="center">

# 🐱 猫缓存 (MddCache)

[![Release Version](https://img.shields.io/github/v/release/xa1st/MddCache?style=flat-square)](https://github.com/xa1st/MddCache/releases/latest)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP Version](https://img.shields.io/badge/PHP-8.0+-4F5B93.svg?style=flat-square)
[![Required Typecho Version](https://img.shields.io/badge/Typecho-1.2+-167B94.svg?style=flat-square)](https://typecho.org)

**轻量级 Typecho Redis 缓存插件**  
**基于 phpredis,持久连接 + SCAN 清理,开箱即用**

[简体中文](README.md) | [English](README_EN.md)

</div>

## ✨ 功能特性

- 🐱 **轻量简洁** - 仅依赖 PHP Redis 扩展,开箱即用
- ⚡ **Redis 缓存** - 高性能 Redis 缓存,持久连接 + SCAN 流式清理,适合高并发场景
- 🔄 **自动清理** - 文章发布/修改/删除、评论发表时自动失效相关缓存
- 🔒 **安全可靠** - 完善的错误处理与异常保护,静默失败不阻断业务
- 🔑 **键前缀隔离** - 全局键前缀避免多站点冲突,由 phpredis `OPT_PREFIX` 自动附加,支持按前缀批量清理
- 📡 **Redis SSL** - 支持配置 SSL 证书路径,加密传输
- ⚙️ **易于配置** - 后台可视化配置界面

## Redis 缓存

- 基于原生 PHP Redis 扩展(phpredis)
- 使用 `pconnect` 持久连接,减少连接开销
- 全局键前缀通过 `Redis::OPT_PREFIX` 交给扩展处理,`get` / `set` / `delete` / `has` / `flush` 会自动附加前缀,调用时只需传入不含前缀的键
- 批量清理使用 `SCAN` 分批迭代(每批最多 100 条),避免 `KEYS` 阻塞服务器;匹配模式同样会叠加上全局前缀
- 缓存值使用 PHP `serialize` 存储,支持数组、对象以及布尔值 `false`
- 支持密码认证、数据库选择与 SSL 加密连接;填写证书路径后启用,并校验服务端证书

## 安装

1. 下载插件到 Typecho 的插件目录:`usr/plugins/MddCache/`
2. 在 Typecho 后台「控制台」->「插件」中激活插件
3. 点击「设置」配置 Redis 连接与缓存清理策略

## 配置

### 基本配置

1. **缓存键前缀**:所有缓存键的统一前缀,默认 `typecho_`,留空则自动使用默认值

### Redis 连接配置

- **Redis 主机地址**:Redis 服务器地址,默认 `127.0.0.1`
- **Redis 端口**:默认 `6379`
- **Redis 密码**:认证密码,留空表示无密码
- **Redis 数据库**:数据库索引,默认 `0`
- **Redis SSL 证书路径**(可选):服务器端 SSL 证书路径,如 `/path/to/cert.pem`,配置后启用加密连接

> **注意**:需要安装 PHP Redis 扩展(phpredis)

### 缓存更新策略

| 配置项 | 默认值 | 说明 |
|-------|-------|------|
| **发布/删除文章时更新缓存** | 启用 | 发布、修改或删除文章时自动处理缓存 |
| **文章更新时指定的缓存键/前缀** | 留空 | 留空则清空所有带全局前缀的缓存,多个用 `\|` 分隔 |
| **发表评论时更新缓存** | 禁用 | 发表评论时自动处理缓存 |
| **评论发表时指定的缓存键/前缀** | 留空 | 留空则清空所有带全局前缀的缓存,多个用 `\|` 分隔 |

缓存键/前缀的匹配规则:
- 包含通配符 `*`:按前缀批量删除,如 `post_*` 删除所有 `post_` 开头的缓存
- 不含通配符:精确删除单个键,如 `post_123`
- 评论清理规则支持 `{cid}` 占位符,发表评论时替换为该评论所属文章的 ID。例如 `commentsList_{cid}` 只会删除当前文章的评论列表缓存

## 使用

直接调用 `Plugin` 的静态方法,不需要先获取实例。同一请求内连接只建立一次。Redis 扩展缺失、连接失败或密码错误时,这些方法会抛出异常。文章发布/删除和评论发表触发的自动清理会捕获异常并跳过,不影响正常流程。

### 常用 API

```php
use TypechoPlugin\MddCache\Plugin;

// 设置缓存(缓存 1 小时)
Plugin::set('mykey', $data, 3600);

// 获取缓存
$data = Plugin::get('mykey', $default);

// 删除缓存
Plugin::delete('mykey');

// 检查缓存是否存在且未过期
if (Plugin::has('mykey')) {
    // ...
}

// 清空所有带全局前缀的缓存
Plugin::flush('');

// 按前缀批量清理(全局前缀 + 自定义前缀)
Plugin::flush('sidebar_');
```

`flush()` 前缀匹配规则(假设全局前缀为 `typecho_`):

```php
Plugin::flush('');         // 删除所有 'typecho_*' 的缓存
Plugin::flush('post_');    // 删除所有 'typecho_post_*' 的缓存
Plugin::flush('user_123'); // 删除所有 'typecho_user_123*' 的缓存
```

说明:

- 传入的键名不要包含全局前缀。前缀由 phpredis `OPT_PREFIX` 自动附加
- `get()` 未命中时返回 `$default`。缓存值本身如果是 `false`,会原样返回,不会被当成未命中

### 在主题中使用

```php
use TypechoPlugin\MddCache\Plugin;

// 获取侧边栏数据并缓存
function getSidebarData() {
    $cacheKey = 'sidebarData';

    $data = Plugin::get($cacheKey);
    if ($data !== null) return $data;

    // 查询数据...
    $data = ['categories' => $categories, 'tags' => $tags];

    Plugin::set($cacheKey, $data, 3600);
    return $data;
}
```

### 在 functions.php 中使用

```php
use TypechoPlugin\MddCache\Plugin;

Plugin::set('mykey', $data, 3600);   // 缓存 1 小时
$data = Plugin::get('mykey', $default);
```

## 技术架构

```
MddCache/
├── Plugin.php                  # 插件入口、Redis 连接与缓存操作
├── CHANGELOG.md                # 更新日志
├── LICENSE                     # 开源协议
├── README.md                   # 说明文档
└── README_EN.md                # English documentation
```

## 系统要求

- Typecho 1.2+
- PHP 8.0+
- PHP Redis 扩展(phpredis)
- 可访问的 Redis 服务

## 更新日志

### v1.2.4
- 💬 **评论键占位符**:评论清理规则支持 `{cid}`,发表评论时替换为所属文章 ID

完整历史见 [CHANGELOG.md](CHANGELOG.md)。

## 开源协议

[MIT License](LICENSE)

## 作者

猫东东 (xa1st) <xa1st@outlook.com>

## 链接

- [GitHub 仓库](https://github.com/xa1st/MddCache)
- [问题反馈](https://github.com/xa1st/MddCache/issues)
- [Typecho](https://typecho.org/)