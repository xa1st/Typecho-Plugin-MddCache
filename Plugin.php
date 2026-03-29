<?php

namespace TypechoPlugin\MddCache;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Widget\Options;
use Typecho\Exception;
use Typecho\Db;

// 确保在 Typecho 环境中运行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 猫缓存 - 轻量级缓存驱动插件
 *
 * @package MddCache
 * @author 猫东东
 * @version 1.0.0
 * @link https://github.com/xa1st/Typecho-Plugin-MddCache
 * @license MulanPSL v2
 */
class Plugin implements PluginInterface {

    /**
     * 缓存驱动实例
     *
     * @var Driver\Base
     */
    private static $cacheInstance = null;

    /** 缓存数据 */
    private static $cacheData = [];

    /**
     * 激活插件
     *
     * 注册文章发布、删除和评论发表的钩子，用于自动清理缓存。
     *
     * @return string 激活成功提示信息
     */
    public static function activate(): string {
        // 渲染时的查询钩子
        \Typecho\Plugin::factory('Widget_Archive')->query = [__CLASS__, 'queryHandler'];
        // 发布清空缓存
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = [__CLASS__, 'onPublish'];
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = [__CLASS__, 'onPublish'];
        // 删除清空缓存
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishDelete = [__CLASS__, 'onRemove'];
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->finishDelete = [__CLASS__, 'onRemove'];
        // 评论发表时
        \Typecho\Plugin::factory('Widget_Feedback')->finishComment = [__CLASS__, 'onComment'];
        // 返回成功提示
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
     * 构建所有配置项，包括缓存驱动选择、连接参数和自动清理策略。
     *
     * @param Form $form 配置表单实例
     * @return void
     */
    public static function config(Form $form): void {

        // 1. 缓存是否启用
        $cacheEnable = new Radio('cacheEnable', ['1' => '启用', '0' => '禁用'], '0', '启用缓存', '是否启用缓存功能，禁用后将不使用缓存但保留配置。');
        $form->addInput($cacheEnable);

        // 2. Redis 连接配置
        $redisHost = new Text('redisHost', null, '127.0.0.1', 'Redis 主机地址', 'Redis 服务器地址');
        $form->addInput($redisHost);
        
        // 3. Redis 连接参数配置
        $redisPort = new Text('redisPort', null, '6379', 'Redis 端口', 'Redis 端口号，默认为 6379');
        $form->addInput($redisPort);

        // 4. Redis 密码配置
        $redisPassword = new Text('redisPassword', null, '', 'Redis 密码', 'Redis 认证密码，留空表示无密码');
        $form->addInput($redisPassword);

        // 5. Redis 库选择
        $redisDb = new Text('redisDb', null, '0', 'Redis 数据库', 'Redis 数据库索引，默认为 0');
        $form->addInput($redisDb);

        // 6. Redis SSL证书配置
        $redisSsl = new Text('redisSsl', null, '', 'Redis SSL证书路径(可选)', 'Redis 认证密码，如：/path/to/cert.pem');
        $form->addInput($redisSsl);
        
        // 7. 缓存键前缀配置
        $cachePrefix = new Text('cachePrefix', null, null, '缓存键前缀', '所有缓存键的统一前缀，避免多站点冲突。留空则默认为 typecho_');
        $form->addInput($cachePrefix);

        // 8. 缓存时间
        $cacheTtl = new Text('cacheTtl', null, '3600', '缓存过期时间', '缓存的默认过期时间，单位为秒，默认为 3600 秒（1小时）');
        $form->addInput($cacheTtl);

        // 9. 文章更新时清理的键名/前缀
        $flushPostNames = new Text('flushPostNames', null, null, '文章更新时指定的缓存键/前缀', '留空则清空所有带全局前缀的缓存。多个用竖线(|)分隔。<br>- 包含通配符(*): 按前缀删除，如 post_* 删除所有 post_ 开头的<br>- 不含通配符: 精确删除单个键，如 post_123');
        $form->addInput($flushPostNames);

        // 10. 评论发表时清理的键名/前缀
        $flushCommentNames = new Text('flushCommentNames', null, null, '评论发表时指定的缓存键/前缀', '留空则清空所有带全局前缀的缓存。多个用竖线(|)分隔。<br>- 包含通配符(*): 按前缀删除，如 comment_* 删除所有 comment_ 开头的<br>- 不含通配符: 精确删除单个键，如 comment_123');
        $form->addInput($flushCommentNames);
    }

    /**
     * 个人用户配置面板（Typecho 要求实现，但本插件无特定配置）
     *
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form): void {}


    /**
     * 核心查询拦截器
     * 挂载点：Widget_Archive 的 query 钩子
     * * 功能：拦截数据库 SQL 查询，优先从 Redis 读取缓存数据。
     * 解决痛点：1. 数据库 I/O 压力 2. 分页丢失 3. 详情页评论失效 4. 递归死循环
     * @param \Widget\Archive $archive 文单档对象实例
     * @param \Typecho\Db\Query $select 查询构建器
     * @return ?bool 返回 null 表示继续执行原方法，返回非 null 的布尔值表示已处理并拦截原方法
     */
    public static function queryHandler($archive, $select): ?bool {
        // 获取插件配置
        $config = Options::alloc()->plugin('MddCache');
        // 插件禁用时，返回 false，让 Typecho 正常执行数据库查询
        if (!$config->cacheEnable) {
            \Typecho\Db::get()->fetchAll($select, [$archive, 'push']);
            return true;
        };
        // --- 1. 防死循环保护 (重入锁) ---
        // 静态变量在脚本执行期间常驻。如果后续 fetchAll 触发了其他插件钩子再次调用本方法，
        // 我们通过此锁直接跳过，防止无限递归导致 PHP 内存溢出或卡死。
        static $isInternalQuery = false;
        // 如果当前正在执行内部查询，则直接返回 null，放行给 Typecho 正常查库，避免死循环
        if ($isInternalQuery) return null;
        // --- 2. 缓存键（Key）生成 ---
        // 调用私有方法根据当前页面属性（首页、文章、日期等）生成唯一标识
        $cacheKey = self::generateArchiveKey($archive);
        // 如果生成失败（如搜索页），则放弃缓存逻辑，走原生查库
        if ($cacheKey) {
            // 从 Redis 获取序列化后的数据包
            $cached = self::getCacheData($cacheKey);
            // 判定：只有当缓存包结构完整（包含行数据）时才视为“命中”
            if (!empty($cached['rows'])) {
                // 如果不执行 setTotal，Typecho 会认为总数为 0，导致页面不显示分页导航条。
                if (isset($cached['total'])) $archive->setTotal($cached['total']);
                // 在文章详情页（single），Typecho 依赖 $archive->row 和 $archive->cid 来加载评论。
                // 仅 push 数据是不够的，必须手动给这些核心属性赋值，否则评论区会消失。
                if ($archive->is('single')) {
                    $archive->row = $cached['rows'][0];
                    $archive->cid = $archive->row['cid'];
                }
                // 将缓存的行数据逐一推入（Push）到 Archive 对象中，供模板循环输出
                foreach ($cached['rows'] as $row) {
                    // 调试模式：在标题后加标识，确认数据来自缓存
                    // $row['title'] .= ' [Cached]'; 
                    $archive->push($row);
                }
                // 兼容性修复：处理一些极端情况下 Typecho 内部 countSql 未定义报错的问题
                self::fixCountSql($archive);
                // 记录日志或直接返回（返回非 null 的布尔值即可拦截 Typecho 原生查询）
                return true;
            }
        }
        // --- 3. 缓存未命中：执行数据库查询并存入缓存 ---
        // 开启锁，标记当前正在执行内部真实查询
        $isInternalQuery = true; 
        try {
            $db = \Typecho\Db::get();
            // 使用 Typecho 原生回调方式 [$archive, 'push']。
            // 这种方式最稳妥，因为它会自动处理 Archive 对象内部的状态转换。
            $rows = $db->fetchAll($select, [$archive, 'push']);
            // 兼容性修复：处理一些极端情况下 Typecho 内部 countSql 未定义报错的问题
            self::fixCountSql($archive);
            // 构造需要存入 Redis 的数据结构
            $cachePackage = [
                'rows'  => $rows,               // 文章数据数组
                'total' => $archive->getTotal() // 记录总数（用于分页导航）
            ];
            // 异步写入缓存（有效期 默认：3600 秒）
            if ($cacheKey) self::setCacheData($cacheKey, $cachePackage, intval($config->cacheTtl) ?? 3600);
        } finally {
            // 无论查询是否成功，必须在最后释放锁，否则当前进程后续的查询都会被封锁
            $isInternalQuery = false;
        }
        // 返回 true 告知 Typecho：我已经手动 fetch 过数据并 push 进对象了，你不用再查了。
        return true;
    }


    /**
     * 评论发表时的钩子
     *
     * 如果在配置中启用了自动清理，则调用清理逻辑。
     *
     * @param object $comment 评论对象
     * @return void
     */
    public static function onComment($comment): void {
        // 获取插件配置
        $config = Options::alloc()->plugin('MddCache');
        // 如果缓存未启用，直接返回，不执行任何清理操作
        if (!$config->cacheEnable) return;
        // 这里应该要删除 index, single页页的缓存，因为评论的更新会影响到这些页面的内容展示，如果有再多可以在后台添加
        $targets = ltrim($config->flushPostNames . "|cache:index:*|cache:single:{$comment->cid}:*", '|');
        // 检查配置是否启用评论更新清理，并执行清理
        self::clearCache($targets);
    }

    /**
     * 文章更新或独立页发布时的清理钩子
     * 挂载点建议：Widget_Contents_Post_Edit 的 finishPublish 或 Widget_Contents_Page_Edit 的 finishPublish
     * @param array $contents 正在处理的内容数据数组
     * @param object $obj 当前 Widget 实例 (Post/Page 编辑挂件)
     */
    public static function onPublish(array $contents, $obj) {
        // 状态过滤：只同步前台可见且未加密的内容
        if ($contents['visibility'] != 'publish' || !empty($contents->password) || !$obj->cid) return;
        // 获取插件配置
        $config = Options::alloc()->plugin('MddCache');
        // 如果缓存未启用，直接返回，不执行任何清理操作
        if (!$config->cacheEnable) return;
        // 这里应该要删除 index, single, Feed页的缓存，因为评论的更新会影响到这些页面的内容展示，如果有再多可以在后台添加
        $targets = ltrim($config->flushPostNames . "|cache:index:*|cache:single:{$obj->cid}:*|cache:feed:*", '|');
        // 检查配置是否启用评论更新清理，并执行清理
        self::clearCache($targets);
    }

    /**
     * 处理删除操作时的缓存清理
     * 当有内容被删除时，清理相关的页面缓存以确保内容展示的一致性
     *
     * @param array $contents 包含删除内容信息的数组
     * @param object $obj 包含cid等对象信息的对象
     * @return void
     */
    public static function onRemove(int $cid) {
        // 获取插件配置
        $config = Options::alloc()->plugin('MddCache');
        // 如果缓存未启用，直接返回，不执行任何清理操作
        if (!$config->cacheEnable) return;
        // 这里应该要删除 index, single页页的缓存，因为评论的更新会影响到这些页面的内容展示，如果有再多可以在后台添加
        $targets = ltrim($config->flushPostNames . "|cache:index:*|cache:single:{$cid}:*|cache:feed:*", '|');
        // 检查配置是否启用评论更新清理，并执行清理
        self::clearCache($targets);
    }
    
    /**
     * 获取缓存实例
     *
     * 根据配置面板选择的驱动类型 (Local/Redis) 创建并连接对应的缓存驱动实例。
     *
     * @return \Redis|null 返回已连接的缓存驱动实例
     */
    private static function getCacheInstance(): ?\Redis {
        // 存在则返回
        if (self::$cacheInstance) return self::$cacheInstance;
        // 如果不支持Redis则直接返回null
        if (!class_exists('Redis')) return null;
        // 获取插件配置
        $config = Options::alloc()->plugin('MddCache');
        // 创建Redis实例
        try{
            // 初始化redis
            $cache = new \Redis();
            // 连接Redis服务器
            $cache->connect($config->redisHost, $config->redisPort);
            // 认证Redis
            if ($config->redisPassword) $cache->auth($config->redisPassword);
            // 配置SSL证书
            if ($config->redisSsl) $cache->setOption(\Redis::OPT_SSL, $config->redisSsl);
            // 选择数据库
            $cache->select($config->redisDb);
            // 存储实例
            self::$cacheInstance = $cache;
        } catch (\Exception $e) {
            // 错误就直接返回null
            self::$cacheInstance = $cache = null;
        }
        // 返回实例
        return $cache;
    }

    /**
     * 获取缓存数据
     * 
     * @param string $cacheKey 缓存键名
     * @return mixed 缓存的数据内容，如果不存在或为空则返回null
     */
    public static function getCacheData(string $cacheKey): mixed {
        // 获取缓存实例，确保连接已建立
        $cache = self::getCacheInstance();
        // 不存在缓存实例则返回 null，表示获取失败
        if (!$cache) return null;
        // 读取插件配置
        $options = Options::alloc()->plugin('MddCache');
        // 如果缓存前缀配置了，就加上前缀
        if (!empty($options->cachePrefix)) $cacheKey = $options->cachePrefix . $cacheKey;
        // 尝试获取缓存数据
        $cachedData = unserialize($cache->get($cacheKey));
        // 返回数据，如果缓存存在且是数组则返回，否则返回 null
        return !empty($cachedData) ? $cachedData : null;
    }

    /**
     * 写入缓存的函数
     * 
     * @param string $cacheKey 缓存键名
     * @param mixed $data 要缓存的数据
     * @param int $ttl 缓存过期时间（秒），默认3600秒
     * @return bool 成功返回true，失败返回false
     */
    public static function setCacheData(string $cacheKey, mixed $data, int $ttl = 3600): bool {
        // 获取缓存实例，确保连接已建立
        $cache = self::getCacheInstance();
        // 不存在缓存实例则返回 false，表示写入失败
        if (!$cache) return false;
        // 读取插件配置
        $options = Options::alloc()->plugin('MddCache');
        // 如果缓存前缀配置了，就加上前缀
        if (!empty($options->cachePrefix)) $cacheKey = $options->cachePrefix . $cacheKey;
        // 将数据序列化后存入缓存
        return $cache->set($cacheKey, serialize($data), $ttl);
    }

    /**
     * 清理缓存的函数
     * 支持格式： "post:*|index:page:1|comment:*"
     * * @param string $targetKeys 需要清理的键或前缀，以 | 分隔
     * @return void
     */
    public static function clearCache(string $targetKeys): void {
        // 获取缓存实例，确保连接已建立
        $cache = self::getCacheInstance();
        if (!$cache) return;
        // 没有键则不能清理，防止误操作导致全站缓存被清空
        if (!$targetKeys) return;
        //  获取全局前缀配置
        $options = \Widget\Options::alloc()->plugin('MddCache');
        $globalPrefix = $options->cachePrefix ?? '';
        // 如果没有传入特定键，通常逻辑是清理所有插件相关的缓存（即前缀*）
        if (empty($targetKeys)) $targetKeys = '*'; 
        // 拆分并去重
        $items = array_filter(array_unique(explode('|', $targetKeys)));
        // 遍历每个用户指定的键或前缀，执行相应的删除操作
        foreach ($items as $item) {
            // 去空格
            $item = trim($item);
            if (empty($item)) continue;
            // 补全全局前缀
            $pattern = $globalPrefix . $item;
            // 策略 A：精确删除（不含通配符 *）
            if (strpos($item, '*') === false) {
                $cache->del($pattern);
                continue;
            }
            // 策略 B：模糊匹配删除（含通配符 *，使用 SCAN）
            $it = NULL; // 初始化游标
            // 每次扫描 100 个 Key，防止阻塞
            while ($keys = $cache->scan($it, $pattern, 100)) {
                // 使用 splat 运算符或直接传数组（取决于你的 Redis 驱动，PhpRedis 支持数组）
                if (!empty($keys)) $cache->del($keys);  
                // 某些 Redis 驱动在迭代结束时会把 $it 置为 0 或 false
                if ($it == 0) break;
            }
        }
    }

    /**
     * 生成规范化的缓存键名 (Private)
     */
    private static function generateArchiveKey($archive): ?string {
        // 当前页面类型
        $type = $archive->getArchiveType() ?? 'unknown';
        // 排除搜索页
        if ($archive->is('index') && $archive->request->is('s')) return null;
        // 获取页面路径做hash值
        $pathHash = md5($archive->request->getPathinfo());
        // 获取文章ID
        $cid = $archive->request->get('slug') ?? $archive->request->filter('int')->get('id');
        // 如果有文章ID但无法识别为单页，说明是无效的请求，不生成缓存键
        if ($archive->is('single') && !$cid) return null; 
        // 按照你设想的层级结构组织 Key`
        return match (true) {
            $archive->is('index')  => "cache:index:{$pathHash}",
            $archive->is('single') => "cache:single:{$cid}:{$pathHash}",
            $archive->is('date')   => "cache:date:" . $archive->request->filter('int')->get('year') . ":{$pathHash}",
            $archive->is('feed')   => "cache:feed:{$pathHash}",
            default                => "cache:{$type}:{$pathHash}"
        };
    }

    /**
     * 反射修复 countSql
     * 解决拦截查询后，Typecho 在计算分页时可能出现的属性未初始化错误
     */
    private static function fixCountSql(&$archive): void {
        try {
            $ref = new \ReflectionProperty(get_class($archive), 'countSql');
            $ref->setAccessible(true);
            // 如果属性没有被初始化，给它塞一个空的 Select 对象占位
            if (!$ref->isInitialized($archive) || is_null($ref->getValue($archive))) {
                $ref->setValue($archive, \Typecho\Db::get()->select());
            }
        } catch (\Throwable $e) {
            // 静默处理，不干扰主流程
        }
    }
}
