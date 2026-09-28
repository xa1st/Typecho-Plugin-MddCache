<?php

namespace TypechoPlugin\MddCache;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element;
use Widget\Options;
use Exception;

// 确保在 Typecho 环境中运行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 猫缓存 - 轻量级 Redis 缓存插件
 *
 * @package MddCache
 * @author 猫东东
 * @version 1.2.4
 * @link https://github.com/xa1st/MddCache
 */
class Plugin implements PluginInterface {
    /**
     * @var \Redis|null 当前请求复用的 phpredis 连接
     */
    private static ?\Redis $redis = null;

    /**
     * @var string 已应用到连接上的全局键前缀
     */
    private static string $prefix = 'typecho_';

    /**
     * 激活插件
     *
     * 注册文章发布、删除和评论发表的钩子，用于自动清理缓存。
     *
     * @return string 激活成功提示信息
     */
    public static function activate(): string {
        // 注册钩子：文章发布或修改完成时
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = __CLASS__ . '::onPostPublish';
        // 注册钩子：文章删除完成时
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishDelete = __CLASS__ . '::onPostDelete';
        // 注册钩子：评论发表完成时
        \Typecho\Plugin::factory('Widget_Feedback')->finishComment = __CLASS__ . '::onComment';

        return '猫缓存插件已激活';
    }

    /**
     * 禁用插件
     *
     * @return string 禁用成功提示信息
     */
    public static function deactivate(): string {
        return '猫缓存插件已禁用';
    }

    /**
     * 插件配置面板
     *
     * 构建所有配置项，包括 Redis 连接参数和自动清理策略。
     *
     * @param Form $form 配置表单实例
     * @return void
     */
    public static function config(Form $form): void {
        // 1. Redis 连接配置
        $form->addInput(new Element\Text('redisHost', null, '127.0.0.1', 'Redis 主机地址', 'Redis 服务器地址'));
        $form->addInput(new Element\Text('redisPort', null, '6379', 'Redis 端口', 'Redis 服务器端口'));
        $form->addInput(new Element\Text('redisPassword', null, '', 'Redis 密码', 'Redis 认证密码，留空表示无密码'));
        $form->addInput(new Element\Text('redisDb', null, '0', 'Redis 数据库', 'Redis 数据库索引，默认为 0'));
        $form->addInput(new Element\Text('redisSsl', null, '', 'Redis SSL证书路径(可选)', 'Redis 服务器端的SSL证书路径，如：/path/to/cert.pem'));
        // 2. 缓存键前缀配置
        $form->addInput(new Element\Text('prefix', null, 'typecho_', '缓存键前缀', '所有缓存键的统一前缀，避免多站点冲突。留空则默认为 typecho_'));
        // 3. 缓存更新策略部分
        $form->addInput(new Element\Hidden('strategySection', null, null, '<h4>缓存更新策略</h4>'));
        // 文章更新策略开关
        $form->addInput(new Element\Radio('flushOnPost', ['1' => '启用', '0' => '禁用'], '1', '发布/删除文章时更新缓存', '发布、修改或删除文章时自动处理缓存'));
        // 文章更新时清理的键名/前缀
        $form->addInput(new Element\Text('flushPostNames', null, null, '文章更新时指定的缓存键/前缀', '留空则清空所有带全局前缀的缓存。多个用竖线(|)分隔。<br>- 包含通配符(*): 按前缀删除，如 post_* 删除所有 post_ 开头的<br>- 不含通配符: 精确删除单个键，如 post_123'));
        // 评论更新策略开关
        $form->addInput(new Element\Radio('flushOnComment', ['1' => '启用', '0' => '禁用'], '0', '发表评论时更新缓存', '发表评论时自动处理缓存'));
        // 评论发表时清理的键名/前缀
        $form->addInput(new Element\Text('flushCommentList', null, null, '评论发表时指定的缓存键/前缀', '留空则清空所有带全局前缀的缓存。多个用竖线(|)分隔。<br>- 包含通配符(*): 按前缀删除，如 comment_* 删除所有 comment_ 开头的<br>- 不含通配符: 精确删除单个键，如 comment_123<br>- {cid} 会替换为当前评论文章的 ID，如 commentsList_{cid}'));
    }

    /**
     * 个人用户配置面板（Typecho 要求实现，但本插件无特定配置）
     *
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form): void {}

    /**
     * 读取缓存。未命中或读取失败时返回默认值。
     *
     * @param string $key 缓存键，不含全局前缀
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed {
        if ($key === '') return $default;
        try {
            $value = self::redis()->get($key);
            // phpredis 对不存在的键返回 false
            if ($value === false) return $default;
            $result = @unserialize($value);
            // serialize(false) 的结果是 b:0;，需要和反序列化失败区分开
            return ($result !== false || $value === 'b:0;') ? $result : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * 写入缓存。
     *
     * @param string $key 缓存键，不含全局前缀
     * @param mixed $value 会被 serialize 后存储
     * @param int $expire 过期秒数，0 表示不过期
     * @return bool
     */
    public static function set(string $key, mixed $value, int $expire = 0): bool {
        if ($key === '') return false;
        try {
            $redis = self::redis();
            $payload = serialize($value);
            return $expire > 0 ? $redis->setex($key, $expire, $payload) : $redis->set($key, $payload);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 删除一个缓存键。键不存在也视为成功。
     *
     * @param string $key 缓存键，不含全局前缀
     * @return bool
     */
    public static function delete(string $key): bool {
        if ($key === '') return false;
        try {
            return self::redis()->del($key) !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 判断缓存键是否存在。
     *
     * @param string $key 缓存键，不含全局前缀
     * @return bool
     */
    public static function has(string $key): bool {
        if ($key === '') return false;
        try {
            return (bool)self::redis()->exists($key);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 按前缀删除缓存。空字符串表示删除全部全局前缀下的键。
     *
     * 扫描时临时关闭 OPT_PREFIX，避免匹配模式和返回的完整键名被再加一次前缀。
     *
     * @param string $prefix 业务前缀，不含全局前缀，也不含通配符
     * @return bool
     */
    public static function flush(string $prefix): bool {
        try {
            $redis = self::redis();
            $pattern = self::$prefix . $prefix . '*';
            $redis->setOption(\Redis::OPT_PREFIX, '');
            try {
                $iterator = null;
                do {
                    $keys = $redis->scan($iterator, $pattern, 100);
                    if (is_array($keys) && $keys !== []) $redis->del($keys);
                } while ($iterator > 0);
            } finally {
                $redis->setOption(\Redis::OPT_PREFIX, self::$prefix);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 文章发布或修改后的钩子。方法名需保持不变，已启用的插件不会重新注册钩子。
     *
     * @param object $contents
     * @param object $edit
     * @return void
     */
    public static function onPostPublish($contents, $edit): void {
        self::flushIfEnabled('flushOnPost', 'flushPostNames');
    }

    /**
     * 文章删除后的钩子。
     *
     * @param object $contents
     * @param object $edit
     * @return void
     */
    public static function onPostDelete($contents, $edit): void {
        self::flushIfEnabled('flushOnPost', 'flushPostNames');
    }

    /**
     * 评论发表后的钩子。
     *
     * @param object $comment
     * @return void
     */
    public static function onComment($comment): void {
        $cid = (int)($comment->cid ?? 0);
        $replace = ['{cid}' => $cid > 0 ? (string)$cid : ''];
        self::flushIfEnabled('flushOnComment', 'flushCommentList', $replace);
    }

    /**
     * 按配置开关清理一组缓存键或前缀。连接失败时静默跳过。
     *
     * @param string $switch 开关配置名
     * @param string $targets 键列表配置名
     * @param array $replace 清理前替换的占位符，如 ['{cid}' => '12']
     * @return void
     */
    private static function flushIfEnabled(string $switch, string $targets, array $replace = []): void {
        try {
            $config = Options::alloc()->plugin('MddCache');
            if (($config->$switch ?? '0') != '1') return;
            $targetKeys = trim((string)($config->$targets ?? ''));
            if ($targetKeys === '') {
                self::flush('');
                return;
            }
            if ($replace !== []) $targetKeys = strtr($targetKeys, $replace);
            foreach (explode('|', $targetKeys) as $item) {
                $item = trim($item);
                if ($item === '') continue;
                if (str_contains($item, '*')) self::flush(rtrim($item, '*'));
                else self::delete($item);
            }
        } catch (Exception $e) {
            // 缓存清理失败不阻断文章发布和评论
        }
    }

    /**
     * 取得当前请求的 Redis 连接，没有连接时按插件配置建立。
     *
     * @return \Redis
     * @throws Exception
     */
    private static function redis(): \Redis {
        if (self::$redis instanceof \Redis) return self::$redis;
        if (!extension_loaded('redis')) throw new Exception('Redis 扩展未安装，请先安装并开启 phpredis 扩展');

        $config = Options::alloc()->plugin('MddCache');
        $redis = new \Redis();
        $context = null;
        // 只有填写了证书路径才启用 TLS，并校验服务端证书
        if (!empty($config->redisSsl)) $context = ['ssl' => ['verify_peer' => true, 'cafile' => $config->redisSsl]];

        $connected = $redis->pconnect(
            $config->redisHost ?? '127.0.0.1',
            (int)($config->redisPort ?? 6379),
            5.0,
            null,
            0,
            0.5,
            $context
        );
        if (!$connected) throw new Exception('Redis pconnect 连接建立失败，请检查网络或配置');
        if (!empty($config->redisPassword) && $redis->auth($config->redisPassword) === false) throw new Exception('Redis 密码认证失败');

        $redis->select((int)($config->redisDb ?? 0));
        self::$prefix = !empty($config->prefix) ? $config->prefix : 'typecho_';
        $redis->setOption(\Redis::OPT_PREFIX, self::$prefix);
        return self::$redis = $redis;
    }
}
