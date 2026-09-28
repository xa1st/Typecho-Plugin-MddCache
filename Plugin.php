<?php

// 插件类必须放在 TypechoPlugin\插件目录名 这个命名空间下，Typecho 才会自动加载
namespace TypechoPlugin\MddCache;

// 插件必须实现的接口：activate / deactivate / config / personalConfig
use Typecho\Plugin\PluginInterface;
// 后台配置表单的容器，由 Typecho 创建后传给 config()
use Typecho\Widget\Helper\Form;
// 文本框、单选框等具体表单控件
use Typecho\Widget\Helper\Form\Element;
// 读取后台保存的插件配置
use Widget\Options;
// Redis 连接、认证、扫描失败时抛出的异常基类
use Exception;

// 没有经过 Typecho 入口时这个常量不存在，直接退出，避免文件被单独请求执行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 猫缓存 - 轻量级 Redis 缓存插件
 *
 * 这不是整页缓存。主题或别的插件调用 get/set 存取数据，
 * 文章、页面、评论发生变化时，再按后台规则删除对应的键。
 *
 * @package MddCache
 * @author 猫东东
 * @version 1.2.6
 * @link https://github.com/xa1st/MddCache
 */
class Plugin implements PluginInterface {
    /**
     * 当前请求里已经建好的 Redis 连接。
     * 静态变量只在同一次 PHP 请求内复用，请求结束就释放，不是跨请求的持久连接。
     *
     * @var \Redis|null
     */
    private static ?\Redis $redis = null;

    /**
     * 所有缓存键统一附加的前缀。
     * 真正写到 Redis 时由 phpredis 的 OPT_PREFIX 自动补上，调用方不要自己再加。
     *
     * @var string
     */
    private static string $prefix = 'typecho_';

    /**
     * 插件激活时注册缓存失效钩子。
     *
     * Typecho 会把这里的赋值保存下来，之后每次请求都回调对应方法。
     * 改了钩子以后，已启用的站点必须禁用再启用一次才会生效。
     *
     * @return string 激活成功后展示给管理员的提示文本
     */
    public static function activate(): string {
        // 文章发布、更新走 Post_Edit::finishPublish，参数是内容数组和编辑 Widget
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = __CLASS__ . '::onPostPublish';
        // 删除文章走 Post_Edit::finishDelete，第一个参数实际是文章 cid 整数
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishDelete = __CLASS__ . '::onPostDelete';
        // 独立页面使用另一个 Widget，不会触发上面的文章钩子，所以要单独注册
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = __CLASS__ . '::onPostPublish';
        // 删除页面同样只触发 Page_Edit 自己的 finishDelete
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->finishDelete = __CLASS__ . '::onPostDelete';

        // 前台访客发表评论。参数是评论 Widget，cid 就是所属文章或页面
        \Typecho\Plugin::factory('Widget_Feedback')->finishComment = __CLASS__ . '::onComment';
        // 后台回复评论成功后触发，参数同样是评论 Widget
        \Typecho\Plugin::factory('Widget_Comments_Edit')->finishComment = __CLASS__ . '::onComment';
        // 后台修改评论内容成功后触发，参数也是评论 Widget
        \Typecho\Plugin::factory('Widget_Comments_Edit')->finishEdit = __CLASS__ . '::onComment';
        // 后台删除评论。第一个参数是评论行数组，不是 Widget，所以用单独的方法取 cid
        \Typecho\Plugin::factory('Widget_Comments_Edit')->finishDelete = __CLASS__ . '::onCommentDelete';
        // 通过、待审、垃圾都走 mark。它是过滤钩子，回调必须把原状态返回
        \Typecho\Plugin::factory('Widget_Comments_Edit')->mark = __CLASS__ . '::onCommentMark';

        // 返回值会显示在后台插件页面
        return '猫缓存插件已激活';
    }

    /**
     * 插件禁用时的回调。
     *
     * Typecho 会自行移除已注册的钩子，这里只丢掉当前请求中的连接对象。
     *
     * @return string 禁用成功后的提示文本
     */
    public static function deactivate(): string {
        // 置空后，本请求里后续代码不能再复用这条连接
        self::$redis = null;
        // 返回值会显示在后台插件页面
        return '猫缓存插件已禁用';
    }

    /**
     * 构造后台插件设置表单。
     *
     * 控件 name 就是 Options::plugin('MddCache') 上的属性名。
     *
     * @param Form $form Typecho 自动传入的表单对象
     * @return void
     */
    public static function config(Form $form): void {
        // Redis 地址。留空时连接阶段会回退到 127.0.0.1
        $form->addInput(new Element\Text('redisHost', null, '127.0.0.1', 'Redis 主机地址', 'Redis 服务器 IP 或域名，如：127.0.0.1'));
        // 端口以字符串保存，连接时再转成整数
        $form->addInput(new Element\Text('redisPort', null, '6379', 'Redis 端口', 'Redis 服务监听端口，默认为 6379'));
        // 空字符串表示 Redis 没有开启 AUTH
        $form->addInput(new Element\Text('redisPassword', null, '', 'Redis 密码', 'Redis 认证密码，若 Redis 未开启 AUTH 请留空'));
        // 数据库编号，连接时转成整数；非法数字会变成 0
        $form->addInput(new Element\Text('redisDb', null, '0', 'Redis 数据库索引', 'Redis 数据库编号 (0-15)，默认为 0'));
        // 证书文件不存在时会静默退回普通连接，不会在这里报错
        $form->addInput(new Element\Text('redisSsl', null, '', 'SSL/CA 证书本地路径 (可选)', '仅在启用了 TLS/SSL 加密的 Redis 环境下填写，如：/etc/ssl/certs/redis-ca.pem'));

        // 多站点共用一个 Redis 库时必须改成不同前缀，否则清理会互相删到
        $form->addInput(new Element\Text('prefix', null, 'typecho_', '缓存键前缀', '全局 key 前缀，多站点共享 Redis 时务必区分，如 site1_'));

        // Hidden 只用来输出分组标题，不保存有意义的配置值
        $form->addInput(new Element\Hidden('strategySection', null, null, '<h4>缓存更新策略</h4>'));

        // 默认开启。覆盖文章和独立页面的发布、更新、删除，不覆盖保存草稿
        $form->addInput(new Element\Radio('flushOnPost', ['1' => '启用', '0' => '禁用'], '1', '发布/删除内容时更新缓存', '文章或独立页面发布、更新或删除时清理对应缓存。保存草稿不会触发'));
        // 留空表示清空整个全局前缀；具体语法由 flushIfEnabled() 解析
        $form->addInput(new Element\Text('flushPostNames', null, null, '内容更新时指定的缓存键/前缀', '多个规则用竖线 | 分隔。<br>- 留空：清空当前全局前缀下的所有缓存；<br>- 仅末尾一个 *：按前缀批量删除，如 post_*；<br>- 不含 *：按完整键名精确删除，如 home_page。<br>中间带 * 或以 * 开头的规则会被跳过，避免误清全站'));

        // 默认关闭，避免每条评论都触发清理。开启后前台和后台的评论变更共用这一套规则
        $form->addInput(new Element\Radio('flushOnComment', ['1' => '启用', '0' => '禁用'], '0', '评论变更时更新缓存', '前台发表、后台回复、编辑、通过、标为待审/垃圾或删除评论时清理对应缓存'));
        // {cid} 会在 flushIfEnabled() 里替换成评论所属内容的数字 ID
        $form->addInput(new Element\Text('flushCommentList', null, null, '评论变更时指定的缓存键/前缀', '多个规则用竖线 | 分隔。<br>- 允许使用 <b>{cid}</b>，替换为评论所属文章 ID（如 comment_article_{cid}）；<br>- 拿不到文章 ID 时只跳过含 {cid} 的那一条，其余规则仍会执行'));
    }

    /**
     * 个人用户配置面板。
     *
     * Typecho 要求每个插件都实现这个方法。本插件只有全站配置，所以留空。
     *
     * @param Form $form 当前登录用户的个人配置表单
     * @return void
     */
    public static function personalConfig(Form $form): void {}

    /**
     * 读取一个缓存值。
     *
     * 键名不要包含全局前缀。未命中、连接失败、数据损坏都返回 $default，不把异常抛给主题。
     *
     * @param string $key 业务键名（不包含全局 prefix 前缀，phpredis 会自动附加前缀）
     * @param mixed $default 缓存未命中（Key不存在）或读取出错时返回的默认兜底值
     * @return mixed 返回反序列化后的原始数据（数组、标量等）
     */
    public static function get(string $key, mixed $default = null): mixed {
        // 空键没有意义，也避免向 Redis 发送一次无用命令
        if ($key === '') return $default;

        try {
            // redis() 负责连接；OPT_PREFIX 会自动把 $key 变成“前缀 + key”
            $value = self::redis()->get($key);

            // phpredis 的 get() 在键不存在或过期时返回 false，不能把它交给 unserialize
            if ($value === false) return $default;

            // allowed_classes 关闭类实例化，避免 Redis 里的脏数据触发 PHP 对象注入
            $result = @unserialize($value, ['allowed_classes' => false]);

            // unserialize 失败也返回 false；只有原文正好是 b:0; 时，false 才是合法缓存值
            return ($result !== false || $value === 'b:0;') ? $result : $default;
        } catch (Exception $e) {
            // 连接超时、认证失败等都降级成未命中，让调用方继续查询数据库
            return $default;
        }
    }

    /**
     * 写入一个缓存值。
     *
     * 所有类型都先 serialize。对象可以写入，但 get() 会拒绝恢复对象，所以只应保存数组和标量。
     *
     * @param string $key 业务键名（不含全局前缀）
     * @param mixed $value 需要缓存的数据（会自动进行 serialize 序列化）
     * @param int $expire 缓存过期时间（秒），0 表示永久存留（不过期）
     * @return bool 写入成功返回 true，失败返回 false
     */
    public static function set(string $key, mixed $value, int $expire = 0): bool {
        // 拒绝空键，避免生成一个只有前缀、没有业务名的缓存
        if ($key === '') return false;

        try {
            // 同一请求内重复 set 会复用这条连接
            $redis = self::redis();
            // serialize 保留数组、布尔值和 null；false 会变成字符串 b:0;
            $payload = serialize($value);

            // setex 在一条命令里同时写值和过期时间，避免写成功后进程中断导致永不过期
            return $expire > 0 ? $redis->setex($key, $expire, $payload) : $redis->set($key, $payload);
        } catch (Exception $e) {
            // 缓存写失败不应影响页面继续输出未缓存的数据
            return false;
        }
    }

    /**
     * 精确删除一个缓存键。
     *
     * 键本身不存在也算成功，因为目标状态已经达到。只有命令抛异常才返回 false。
     *
     * @param string $key 业务键名（不含全局前缀）
     * @return bool 删除成功（或 Key 本就不存在）返回 true，发生异常返回 false
     */
    public static function delete(string $key): bool {
        // 空键直接失败，防止误删异常键名
        if ($key === '') return false;

        try {
            // del() 返回删除数量；键不存在时是 0，只有驱动错误才是 false
            return self::redis()->del($key) !== false;
        } catch (Exception $e) {
            // 删除失败交给调用方决定是否忽略
            return false;
        }
    }

    /**
     * 判断缓存键是否存在且未过期。
     *
     * 只需要判断有无时才用它。已经要读取内容时直接 get()，不要先 has() 再 get()。
     *
     * @param string $key 业务键名（不含全局前缀）
     * @return bool 存在返回 true，不存在或报错返回 false
     */
    public static function has(string $key): bool {
        // 空键一律视为不存在
        if ($key === '') return false;

        try {
            // exists() 返回匹配数量，转成布尔值即可
            return (bool)self::redis()->exists($key);
        } catch (Exception $e) {
            // 连接异常按不存在处理，避免调用方误判命中
            return false;
        }
    }

    /**
     * 按业务前缀批量删除缓存。
     *
     * 传入 post_ 会删除“全局前缀 + post_”开头的全部键。
     * 传入空字符串会删除当前全局前缀下的全部键。不要在生产环境改用 KEYS。
     *
     * @param string $prefix 业务前缀（不含全局前缀）。若传入空字符串 ''，则清空当前全局前缀下的“所有”缓存。
     * @return bool 清理完成返回 true，连接或扫描失败返回 false
     */
    public static function flush(string $prefix): bool {
        // 单前缀也走统一实现，保证和多条规则使用同样的扫描、匹配、删除流程
        return self::flushPatterns([$prefix]);
    }

    /**
     * 用一次全库 SCAN 同时匹配多个前缀。
     *
     * SCAN 不能把多个模式交给 Redis 一次过滤，所以这里取回一批键后在 PHP 里判断。
     * 这样多条规则只遍历一次数据库，而不是每条规则都把游标走完。
     *
     * @param string[] $prefixes 不含全局前缀。空字符串表示清空该前缀下的全部缓存。
     * @return bool 清理完成返回 true，中途失败返回 false
     */
    private static function flushPatterns(array $prefixes): bool {
        // 收集已经补上全局前缀、并且以 * 结尾的完整匹配模式
        $patterns = [];
        // 逐个把业务前缀转换成完整模式
        foreach ($prefixes as $prefix) {
            // 调用方可能传入数字等标量，先统一成字符串
            $prefix = (string)$prefix;
            // 空前缀代表整个全局命名空间，没有必要再保留更窄的模式
            if ($prefix === '') {
                // 用单独一个模式替换全部模式，例如 typecho_*
                $patterns = [self::$prefix . '*'];
                // 后续前缀都被这个模式覆盖，直接结束组装
                break;
            }
            // 非空前缀只在末尾追加一个星号，不处理中间位置的通配
            $patterns[] = self::$prefix . $prefix . '*';
        }
        // 去掉重复模式，并重新编号，避免后面遍历出现空洞
        $patterns = array_values(array_unique($patterns));
        // 没有任何模式时不连接 Redis，直接视为清理完成
        if ($patterns === []) return true;

        // 提前声明，finally 里才能判断连接是否已经创建
        $redis = null;
        // 只有成功清空 OPT_PREFIX 后，才需要在结束时恢复它
        $prefixCleared = false;
        try {
            // 获取或创建当前请求的 Redis 连接
            $redis = self::redis();

            // 清空客户端前缀。否则 SCAN 返回的完整键再传给 unlink/del 时会被加上第二次前缀
            $redis->setOption(\Redis::OPT_PREFIX, '');
            // 标记前缀已经改变，无论后面成功还是失败都要尝试恢复
            $prefixCleared = true;

            // phpredis 要求传入变量引用；第一次传 null，之后 Redis 会改写这个游标
            $iterator = null;
            do {
                // 第三个参数是 COUNT 提示，不是返回数量上限；1000 用来减少大库的网络往返
                $keys = $redis->scan($iterator, null, 1000);
                // 返回 false 表示这次迭代失败，不能继续使用已经变化的游标
                if ($keys === false) {
                    // 抛给下面的 catch，最终向调用方返回 false
                    throw new Exception('Redis SCAN 失败');
                }
                // 这一批没有键时继续下一轮，直到游标回到 0
                if (!is_array($keys) || $keys === []) continue;

                // 收集这一批里真正命中清理规则的完整键名
                $matched = [];
                // SCAN 没传 MATCH，所以这里要自行过滤当前库返回的所有键
                foreach ($keys as $key) {
                    // 一个键只要命中任意一个模式就可以删除
                    foreach ($patterns as $pattern) {
                        // 强制转字符串，避免驱动返回非字符串时传入匹配函数报错
                        if (self::matchPattern((string)$key, $pattern)) {
                            // 保存 Redis 返回的原始完整键，删除时不能再加前缀
                            $matched[] = $key;
                            // 已命中就不用再比较剩余模式
                            break;
                        }
                    }
                }
                // 这一批有命中才发送删除命令，避免空参数调用
                if ($matched !== []) {
                    // 新版本 phpredis 提供 unlink，Redis 可以异步释放内存
                    if (method_exists($redis, 'unlink')) {
                        // 一次删除这一批的全部命中键
                        $redis->unlink($matched);
                    } else {
                        // 老版本扩展没有 unlink 时退回同步删除
                        $redis->del($matched);
                    }
                }
            // 游标为 0 或 '0' 时整库扫描结束；转成整数后不再进入下一轮
            } while ((int)$iterator > 0);

            // 扫描和删除都没有抛异常，返回成功
            return true;
        } catch (Exception $e) {
            // 连接、扫描、删除任一失败都返回 false，由上层决定是否静默忽略
            return false;
        } finally {
            // 只有前缀确实被清空、连接对象也存在时才恢复
            if ($prefixCleared && $redis instanceof \Redis) {
                try {
                    // 恢复到本次请求开始时确定的全局前缀
                    $redis->setOption(\Redis::OPT_PREFIX, self::$prefix);
                } catch (Exception $e) {
                    // 恢复失败后这条连接不能再用，否则后续 get/set 会写到没有前缀的裸键
                    self::$redis = null;
                }
            }
        }
    }

    /**
     * 判断完整键名是否符合模式。
     *
     * 只支持两种形式：没有星号的完整相等，或末尾恰好一个星号的前缀匹配。
     * 中间包含星号的模式一律不匹配，避免把任意位置通配误实现成全量删除。
     *
     * @param string $key 含全局前缀的完整 Redis 键名
     * @param string $pattern 含全局前缀的匹配模式
     * @return bool 命中返回 true
     */
    private static function matchPattern(string $key, string $pattern): bool {
        // 模式不以星号结尾时，只允许键名完全一致
        if (!str_ends_with($pattern, '*')) return $key === $pattern;
        // 去掉最后一个星号，剩下的部分就是必须命中的固定前缀
        $prefix = substr($pattern, 0, -1);
        // 固定部分仍含星号说明原模式不是“末尾单星”，直接拒绝
        if (str_contains($prefix, '*')) return false;
        // 只比较开头，不使用不必要的正则
        return str_starts_with($key, $prefix);
    }

    /**
     * 文章或独立页面发布、更新完成后的钩子。
     *
     * 两个 Widget 的参数顺序相同，但当前清理规则不区分具体内容，所以参数暂不使用。
     *
     * @param mixed $contents 发布后的内容数据
     * @param mixed $edit 对应的编辑 Widget
     * @return void
     */
    public static function onPostPublish($contents, $edit): void {
        // 是否清理、清理哪些键，全部由后台的内容更新策略决定
        self::flushIfEnabled('flushOnPost', 'flushPostNames');
    }

    /**
     * 文章或独立页面删除完成后的钩子。
     *
     * Post_Edit 传入的是 cid，Page_Edit 传入的是页面数据，两者目前共用同一套规则。
     *
     * @param mixed $contents 被删除的内容标识或数据
     * @param mixed $edit 对应的编辑 Widget
     * @return void
     */
    public static function onPostDelete($contents, $edit): void {
        // 删除和发布共用 flushOnPost，避免后台出现两套容易配不一致的规则
        self::flushIfEnabled('flushOnPost', 'flushPostNames');
    }

    /**
     * 前台发表、后台回复或后台编辑评论完成后的钩子。
     *
     * 这三处传入的都是评论 Widget，可以直接读取 cid 属性。
     *
     * @param mixed $comment 评论 Widget
     * @return void
     */
    public static function onComment($comment): void {
        // cid 不存在时用 0，后面会保留不含占位符的规则并跳过 {cid} 规则
        self::flushComment($comment->cid ?? 0);
    }

    /**
     * 后台删除评论完成后的钩子。
     *
     * Comments_Edit::finishDelete 的第一个参数是数据库里的评论行数组。
     *
     * @param mixed $comment 评论行数组；兼容意外传入的对象
     * @return void
     */
    public static function onCommentDelete($comment): void {
        // 数组取 cid 字段；如果未来钩子参数变成对象，则退回读取 cid 属性
        $cid = is_array($comment) ? ($comment['cid'] ?? 0) : ($comment->cid ?? 0);
        // 复用评论清理入口，保证删除和发表使用同一套配置
        self::flushComment($cid);
    }

    /**
     * 后台改变评论状态时的过滤钩子。
     *
     * Typecho 的过滤钩子会把回调返回值传给下一个回调。
     * 这里不能返回 void，否则原本的审核状态会变成 null。
     *
     * @param mixed $comment 评论行数组
     * @param mixed $edit 评论编辑 Widget，当前用不到
     * @param mixed $status 本次要写入的评论状态
     * @return mixed 原样返回 $status
     */
    public static function onCommentMark($comment, $edit, $status) {
        // mark 钩子在状态确实发生变化前触发，参数同样可能是评论数组
        $cid = is_array($comment) ? ($comment['cid'] ?? 0) : ($comment->cid ?? 0);
        // 审核通过、改回待审、标为垃圾都会影响前台评论列表，因此同样清理
        self::flushComment($cid);
        // 必须原样返回，不能返回清理函数的结果
        return $status;
    }

    /**
     * 按评论所属内容执行评论缓存规则。
     *
     * cid 小于等于 0 时不生成替换表，带 {cid} 的单条规则会被跳过。
     *
     * @param mixed $cid 评论所属文章或页面的 ID
     * @return void
     */
    private static function flushComment(mixed $cid): void {
        // Widget 或数据库读出的 cid 可能是字符串，统一转成整数
        $cid = (int)$cid;
        // 只有合法的正数 ID 才替换占位符，0 和负数都不能拼进键名
        $replace = $cid > 0 ? ['{cid}' => (string)$cid] : [];
        // 开关和规则都来自评论策略，不复用文章发布策略
        self::flushIfEnabled('flushOnComment', 'flushCommentList', $replace);
    }

    /**
     * 读取一组后台规则并执行清理。
     *
     * 规则用竖线分隔：
     * - 空配置：清空整个全局前缀；
     * - post_*：只删除 post_ 前缀；
     * - home：精确删除 home；
     * - comments_{cid}：替换成 comments_42 这类精确键；
     * - *sidebar、cache_*_html：格式不合法，跳过。
     *
     * @param string $switch 后台策略开关配置名（如：flushOnPost）
     * @param string $targets 后台指定的键名/通配符列表配置名（如：flushPostNames）
     * @param array $replace 占位符替换字典（如：['{cid}' => '42']）
     * @return void
     */
    private static function flushIfEnabled(string $switch, string $targets, array $replace = []): void {
        try {
            // 每次都读取当前保存的配置，避免请求中途改配置后仍使用旧规则
            $config = Options::alloc()->plugin('MddCache');

            // 单选框保存的是字符串 1/0；缺配置时默认关闭，避免误清缓存
            if (($config->$switch ?? '0') != '1') return;

            // 去掉首尾空白。配置项不存在时按空规则处理
            $targetKeys = trim((string)($config->$targets ?? ''));

            // 管理员留空表示无法列举具体键，采用最安全的全前缀清理
            if ($targetKeys === '') {
                // 空字符串在 flush() 里表示“全局前缀 + 任意键”
                self::flush('');
                // 全量清理已经覆盖所有更细的规则
                return;
            }

            // 不含星号、可以直接 del 的业务键
            $exact = [];
            // 末尾单星规则去掉星号后的业务前缀
            $prefixes = [];

            // 竖线分隔多条规则，逐条判断，一条非法不影响其他条
            foreach (explode('|', $targetKeys) as $item) {
                // 允许管理员在竖线两侧写空格
                $item = trim($item);
                // 连续竖线会产生空项，直接忽略
                if ($item === '') continue;

                // 占位符按单条判断，不能因为整串里有 {cid} 就放弃全部规则
                if (str_contains($item, '{cid}')) {
                    // 当前操作没有可用 cid 时，只跳过这一条
                    if (!isset($replace['{cid}'])) continue;
                    // 把 comments_{cid} 替换成 comments_42，替换结果继续按普通规则分类
                    $item = strtr($item, $replace);
                }

                // 只有“末尾恰好一个星号”才是合法前缀规则
                if (str_ends_with($item, '*') && substr_count($item, '*') === 1) {
                    // 去掉星号后交给 flushPatterns()，那里会补全局前缀并重新加星号
                    $prefixes[] = substr($item, 0, -1);
                    // 已归入批量清理，不再当作精确键
                    continue;
                }

                // 剩余规则只有完全不含星号才精确删除；其他写法一律跳过
                if (!str_contains($item, '*')) {
                    // 收集业务键，实际删除前还会去重
                    $exact[] = $item;
                }
            }

            // 相同精确键只删除一次，减少重复的 Redis 往返
            foreach (array_unique($exact) as $key) {
                // delete() 自己会补前缀并吞掉连接异常
                self::delete($key);
            }
            // 有前缀规则时合并成一次扫描；没有时不要空跑 SCAN
            if ($prefixes !== []) {
                // 多个前缀在一次全库游标遍历中同时匹配
                self::flushPatterns($prefixes);
            }
        } catch (Exception $e) {
            // 自动清理属于附加动作，任何异常都不能回滚或阻断发布、评论等主操作
        }
    }

    /**
     * 获取当前请求可复用的 Redis 连接。
     *
     * 第一次调用时完成扩展检查、TCP/SSL 连接、认证、选库和前缀设置。
     * 之后的 get/set/delete/flush 都返回同一个对象。
     *
     * @return \Redis 返回准备就绪的 phpredis 连接实例
     * @throws Exception 当扩展丢失、连接失败或 Auth 失败时抛出异常
     */
    private static function redis(): \Redis {
        // instanceof 同时排除 null 和意外写入的其他类型
        if (self::$redis instanceof \Redis) return self::$redis;

        // 没有 phpredis 时无法提供缓存，抛异常给各公开方法统一降级
        if (!extension_loaded('redis')) {
            // 提示中写明扩展名，方便区分 Redis 服务本身没有启动
            throw new Exception('Redis 扩展未安装，请先安装 phpredis 扩展');
        }

        // 读取后台保存的主机、端口、密码、数据库、证书和前缀
        $config = Options::alloc()->plugin('MddCache');
        // 创建客户端；SSL 上下文默认关闭
        $redis = new \Redis();
        $context = null;

        // 证书路径为空或文件不存在时保持 $context 为 null，后续建立普通连接
        if (!empty($config->redisSsl) && file_exists($config->redisSsl)) {
            // 开启对端证书校验，并用指定 CA 文件验证 Redis 服务端
            $context = ['ssl' => ['verify_peer' => true, 'cafile' => $config->redisSsl]];
        }

        // connect 建立本次请求使用的短连接。参数依次是主机、端口、连接超时、保留参数、重试间隔、读写超时、SSL 上下文
        $connected = $redis->connect(
            // 后台没保存主机时连接本机
            $config->redisHost ?? '127.0.0.1',
            // 端口配置是字符串，非数字会被转换成 0
            (int)($config->redisPort ?? 6379),
            // 两秒内连不上就失败，避免后台页面长时间卡住
            2.0,
            // 保留参数按 phpredis 签名传 null
            null,
            // 不在连接阶段自动重试
            0,
            // 读写超过 0.3 秒就放弃，缓存操作应远快于回源查询
            0.3,
            // null 表示不启用 TLS；数组表示使用上面生成的 SSL 上下文
            $context
        );

        // connect 返回 false 表示地址、端口或网络不可用
        if (!$connected) {
            // 抛异常而不是保存这个失败对象，下一操作才有机会重新连接
            throw new Exception('Redis 服务连接失败，请检查主机或端口配置');
        }

        // 只在管理员填写了密码时认证；auth 返回 false 不能继续使用这条连接
        if (!empty($config->redisPassword) && $redis->auth($config->redisPassword) === false) {
            // 密码错误属于配置问题，同样交给调用方降级
            throw new Exception('Redis 密码认证失败');
        }

        // 选择业务数据库。越界等驱动错误会抛异常，不会把错误库上的结果当成缓存
        $redis->select((int)($config->redisDb ?? 0));

        // 空白前缀没有隔离作用，回退到默认前缀；合法前缀则保存到静态变量供 SCAN 恢复使用
        self::$prefix = !empty($config->prefix) ? $config->prefix : 'typecho_';
        // 之后这个连接上的 get/set/del 都会自动附加前缀
        $redis->setOption(\Redis::OPT_PREFIX, self::$prefix);

        // 先保存再返回，确保同一次表达式里的后续调用能复用它
        return self::$redis = $redis;
    }
}
