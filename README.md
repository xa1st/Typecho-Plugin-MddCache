<div align="center">

# MddCache

[![Release Version](https://img.shields.io/github/v/release/xa1st/Typecho-Plugin-MddCache?style=flat-square)](https://github.com/xa1st/Typecho-Plugin-MddCache/releases/latest)
[![License](https://img.shields.io/badge/License-MulanPSL2-red.svg?style=flat-square)](https://license.coscl.org.cn/MulanPSL2)
[![Typecho 1.2+](https://img.shields.io/badge/Typecho-1.2%2B-167B94?style=flat-square)](https://typecho.org/)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square)](https://www.php.net/)
[![Redis](https://img.shields.io/badge/Cache-Redis-DC382D?style=flat-square)](https://redis.io/)

为 Typecho 的 Archive 查询增加 Redis 缓存，并在文章、页面、评论变化后自动清理相关缓存的轻量插件。

</div>

## 功能概览

- 挂载 `Widget_Archive->query`，为常见前台归档查询增加缓存层
- 自动缓存首页、单篇、日期归档、Feed 及其他 Archive 类型页面结果
- 命中缓存时恢复文章列表、分页总数，以及单篇页评论依赖的 `row` / `cid`
- 在文章/页面发布、删除，以及评论发表后自动清理相关页面缓存
- 支持配置全局缓存前缀、默认 TTL，以及自定义清理目标
- 支持用 `|` 分隔多个清理规则，并用 `*` 做前缀匹配批量删除
- Redis 不可用时自动降级，不会阻断页面正常查询

## 运行要求

- Typecho `1.2+`
- PHP `8.0+`
- 已安装 `phpredis` 扩展
- 可访问的 Redis 服务

## 缓存范围与键规则

当前版本会基于页面类型和请求路径生成缓存键，主要包括：

- 首页 / 列表页：`cache:index:{pathHash}`
- 单篇页：`cache:single:{cid}:{pathHash}`
- 日期归档：`cache:date:{year}:{pathHash}`
- Feed：`cache:feed:{pathHash}`
- 其他 Archive 类型：`cache:{type}:{pathHash}`

补充说明：

- 搜索请求不会进入缓存逻辑
- 如果配置了“缓存键前缀”，实际写入 Redis 的键会自动再拼接此前缀
- 缓存内容会以序列化后的数组形式写入 Redis，包含 `rows` 和 `total`

## 安装

1. 将插件目录放到 `usr/plugins/MddCache/`
2. 进入 Typecho 后台启用 `MddCache`
3. 在插件设置中填写 Redis 连接信息
4. 将“启用缓存”切换为启用，并按需设置缓存前缀、过期时间、自动清理规则

## 配置说明

### 基础配置

- `启用缓存`：关闭时插件不会实际使用 Redis 缓存
- `Redis 主机地址`：默认 `127.0.0.1`
- `Redis 端口`：默认 `6379`
- `Redis 密码`：留空表示无密码
- `Redis 数据库`：默认 `0`
- `Redis SSL证书路径(可选)`：配置项已预留，当前实现会尝试设置 `Redis::OPT_SSL`
- `缓存键前缀`：默认留空，代码中建议值为 `typecho_`
- `缓存过期时间`：默认 `3600` 秒

### 自动清理规则

插件提供两个可填写的清理规则输入框，均支持以下格式：

- 多个目标使用 `|` 分隔
- 不带 `*` 时按完整键精确删除
- 带 `*` 时按前缀扫描删除，例如 `cache:index:*`

字段说明：

- `文章更新时指定的缓存键/前缀`
- `评论发表时指定的缓存键/前缀`

此外，插件还会自动补充一些内置清理目标：

- 文章/页面发布时：额外清理 `cache:index:*`、`cache:single:{cid}:*`、`cache:feed:*`
- 文章/页面删除时：额外清理 `cache:index:*`、`cache:single:{cid}:*`、`cache:feed:*`
- 评论发表时：额外清理 `cache:index:*`、`cache:single:{cid}:*`

## 使用说明

### 默认工作流程

在“启用缓存”开启且 Redis 连接正常时：

1. 访问前台 Archive 页面时，插件会先根据当前请求生成缓存键
2. 如果 Redis 中已有缓存，则直接回填查询结果到 `Widget_Archive`
3. 如果缓存未命中，则继续执行数据库查询
4. 查询完成后，将结果和分页总数写入 Redis
5. 当文章、页面、评论发生变化时，按规则清理相关缓存

### 在主题或其他插件中主动使用

当前版本公开了 3 个静态方法，可直接复用同一套缓存连接与前缀规则：

```php
use TypechoPlugin\MddCache\Plugin;

// 写入缓存，第三个参数为 TTL（秒）
Plugin::setCacheData('demo:key', ['foo' => 'bar'], 600);

// 读取缓存
$data = Plugin::getCacheData('demo:key');

// 精确删除单个键
Plugin::clearCache('demo:key');

// 按前缀批量删除
Plugin::clearCache('cache:index:*|cache:feed:*');
```

## 注意事项

1. 当前实现依赖 `phpredis` 扩展；如果服务器未安装扩展或 Redis 连接失败，插件会自动降级为不缓存。
2. 搜索页不会被缓存，这属于代码中的显式排除逻辑。
3. 当前仓库实际代码只有 `Plugin.php` 一个主要实现文件，没有 README 旧版里提到的本地文件缓存驱动。
4. `评论发表时指定的缓存键/前缀` 虽然在配置面板中存在，但当前代码里的评论清理逻辑实际仍复用了 `flushPostNames`，如果你依赖评论专属规则，建议后续再修正实现。
5. `Redis SSL证书路径(可选)` 目前只是透传到 `Redis::OPT_SSL`，不同 phpredis 版本和 Redis 服务端环境的兼容性需要自行验证。
6. 缓存删除使用 Redis `SCAN` + `DEL`，适合常规站点；如果你的前缀下键数量非常大，批量清理时仍应关注 Redis 负载。

## 项目结构

```text
MddCache/
|-- Plugin.php          # 插件入口、查询拦截、Redis 读写与自动清理逻辑
|-- README.md           # 项目说明
`-- LICENSE             # 木兰宽松许可证，第 2 版
```

## 许可证

[MulanPSL v2](LICENSE)

## 作者

猫东东 / Alex Xu

## 链接

- GitHub: https://github.com/xa1st/Typecho-Plugin-MddCache
- Issues: https://github.com/xa1st/Typecho-Plugin-MddCache/issues
