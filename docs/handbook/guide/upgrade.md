# 升级指南

> 适用版本：**1.0.0-beta.2.3**（覆盖 0.15 ～ 1.0.0-beta.2.3 的全部破坏性变更）
>
> CHANGELOG 回答「改了什么」，这份文档回答「**我该怎么改我的代码**」。
> 只记录会影响已有业务代码的变更——新增能力不在此列。

::: tip 1.0.0-beta.2.3 没有破坏性变更
三项都是**新增能力**，已有代码不用改：

| 新增 | 需要做什么 |
| --- | --- |
| SMTP 支持 STARTTLS / SMTPS | 不改就是原来的明文行为；要用加密才设 `MAIL_ENCRYPTION` |
| JWT 双 Token | `issue()` 行为不变；要用无感刷新才换 `issueTokens()` |
| `storage:link` 命令 | 纯新增 |

从 `beta.2` 及更早版本升上来：直接替换框架目录即可。

细节见 [CHANGELOG](https://github.com/SunsetReverie/SunsetReverieAPI/blob/main/CHANGELOG.md)。
:::

---

## 怎么用这份文档

1. 先看下方的**破坏性变更清单**，按「你会不会中招」判断
2. 中招的，按每节的「怎么改」调整代码
3. 每节末尾有**自检命令**，改完跑一遍

升级前建议先跑：

```bash
php bin/sr env:check          # 环境与配置
php bin/sr lint:architecture  # 依赖方向有没有被新代码破坏
php tests/run.php             # 你自己的测试
```

---

## 破坏性变更清单

| # | 变更 | 影响版本 | 影响面 | 中招信号 |
| --- | --- | --- | --- | --- |
| 1 | `selectRaw()` 不再自带 `*` | 0.41.0 | 统计/聚合查询 | 查询结果少了 `id` 等列 |
| 2 | `unique('col')` 语义改变 | 0.36.1 | 迁移文件 | 迁移建表后索引加在了奇怪的位置 |
| 3 | 路由参数自动 urldecode | 0.28.1 | 收到过编码参数的接口 | 中文/特殊字符参数被解码两次 |
| 4 | 错误码值变更 | 0.21.0 | 用码值做判断的前端/调用方 | 上传、数据库类错误码对不上 |
| 5 | `insertGetId()` 返回 int | 0.15.0 | 主键类型比较 | 严格比较 `'1' === $id` 失败 |
| 6 | 缓存驱动必须显式配置 | 0.23.0 | `config/cache.php` | `store('redis')` 报「未配置」 |
| 7 | `Auth::id()` 开始有值 | 0.31.0 | 依赖「取不到用户」的分支 | 原本走游客逻辑的地方走了登录逻辑 |

---

## 1. `selectRaw()` 不再自带 `*`（0.41.0）

### 现象

升级后统计查询返回的行里**没有 `id`、`created_at` 等列**：

```php
$rows = DB::table('orders')
    ->selectRaw('COUNT(*) AS total')
    ->groupBy('status')
    ->get();

// 升级前：$row = ['id' => 1, 'status' => 1, 'total' => 3, ...]
// 升级后：$row = ['total' => 3]        ← 只有显式选择或聚合的列
```

### 原因

`Builder` 的默认选择是 `['*']`，而 `selectRaw()` 是**追加**语义：

```sql
-- 升级前生成的 SQL（MySQL 容忍，PostgreSQL 拒绝）
SELECT *, COUNT(*) AS total FROM orders GROUP BY status
```

PostgreSQL 会直接报：

```text
ERROR: column "orders.id" must appear in the GROUP BY clause or be used in an aggregate function
```

现在 `selectRaw()` 首次调用会清掉默认的 `*`，语义变成「我显式指定了要什么列」。

### 怎么改

**情况 A：确实只要聚合结果**（多数情况）

不用改。`$row['total']` 照常可读。

**情况 B：需要同时拿到明细列**

显式写出来：

```php
DB::table('orders')
    ->select('status', 'order_no')       // 显式列出
    ->selectRaw('COUNT(*) AS total')
    ->groupBy('status');
```

**情况 C：确实想要 `*` 加聚合**（MySQL 专用写法）

`SELECT *` + `GROUP BY` 本身是方言相关的不安全写法，建议改写。
如果非要用：

```php
DB::table('orders')
    ->selectRaw('*', 'COUNT(*) AS total')   // 显式写 * 就不会被清掉
    ->groupBy('status');
```

注意这仍然会在 PostgreSQL 上报错。

### 自检

```bash
# 找出项目里所有 selectRaw 调用，确认都不依赖默认的 *
grep -rn 'selectRaw(' app/ | grep -v "'\*'"
```

---

## 2. `unique('col')` 语义改变（0.36.1）

### 现象

升级后迁移建出来的表，唯一索引加在了**不是你以为的那一列**。

### 原因

`Blueprint::unique()` 原本只能链式使用（修饰最近定义的一列）：

```php
$table->string('slug', 200)->unique();   // 正确：修饰 slug
```

但很多人（包括框架自己）会写成：

```php
$table->timestamps();
$table->unique('sku');      // 参数被静默忽略！
```

PHP 会**忽略多余参数**，所以 `unique('sku')` 不报错，而是把唯一索引加到了
「最后一列」——也就是 `updated_at` 上。

后果：迁移成功、表建出来，但**插入第二条数据就报 Duplicate entry**，
且报错信息指向 `updated_at`，完全看不出真正原因。

0.36.1 起 `unique()` 接受列名参数，两种写法都支持。

### 怎么改

**已经在 0.36.1 之前建过表的**，检查索引是不是加错了：

```sql
-- MySQL：看看唯一索引在哪些列上
SHOW INDEX FROM your_table WHERE Non_unique = 0;
```

如果发现唯一索引在 `updated_at` 上：

```sql
ALTER TABLE your_table DROP INDEX updated_at, ADD UNIQUE KEY your_table_sku_unique (sku);
```

或者干脆重跑迁移（开发环境）。

**新写的迁移**，两种写法都对：

```php
$table->string('slug', 200)->unique();          // 链式
$table->unique('sku');                           // 按列名
$table->unique(['post_id', 'user_id']);          // 复合
```

列名写错会立刻抛异常（不再静默）：

```text
InvalidArgumentException: 索引引用了不存在的列 [nope]（表 t3）
```

### 自检

```bash
# 所有表都检查一遍唯一索引的位置是否合理
mysql -e "SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME) cols
          FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
          GROUP BY TABLE_NAME, INDEX_NAME;"
```

---

## 3. 路由参数自动 urldecode（0.28.1）

### 现象

参数里出现 `%` 或空格被转成 `+`，或中文变成了乱码。

### 原因

0.28.1 之前，路由参数**保留编码形式**原样传给控制器：

```php
// 请求 /posts/%E6%96%B0%E5%86%99%E7%9A%84%E6%96%87%E7%AB%A0
Route::get('/posts/{slug}', ...);

// 0.28.1 之前：$slug = '%E6%96%B0%E5%86%99%E7%9A%84%E6%96%87%E7%AB%A0'  ← 拿去查库必然查不到
// 0.28.1 之后：$slug = '新写的文章'                                        ← 正常
```

现在框架统一 `rawurldecode`（用 `rawurldecode` 而非 `urldecode`，因为路径里的 `+` 是字面加号）。

### 怎么改

**如果你的代码里自己做过解码**，需要删掉，否则会解码两次：

```php
// 旧代码（会双重解码，中文变乱码）
public function show(string $slug): array
{
    $slug = rawurldecode($slug);      // ← 删掉这行
    return $this->posts->detail($slug);
}
```

**如果参数可能是 `+`**，注意现在 `+` 保持字面含义：

```php
// 请求 /tags/c%2B%2B
// 得到 'c++'（不是 'c  '）
```

### 自检

```bash
# 找出还在手动解码路由参数的地方
grep -rn 'rawurldecode\|urldecode' app/ | grep -v 'query('
```

---

## 4. 错误码值变更（0.21.0）

### 现象

前端按错误码做的提示不生效，或者提示文不对。

### 原因

0.21.0 把错误码从 18 条补齐到 39 条（与文档 14.2 一致），并修正了一批用错的码：

| 场景 | 旧码 | 新码 |
| --- | --- | --- |
| 上传文件超大小 | `30001`（参数校验失败） | `40007`（请求体过大） |
| 上传文件类型不允许 | `30001` | `30005`（不支持的内容类型） |
| 上传文件结构损坏 | `30001` | `30001`（不变，语义是参数问题） |
| 数据库操作失败 | `10001`（内部错误） | `60001`（数据库操作失败） |
| 缓存服务异常 | `10001` | `60003` |
| 请求的 API 域不存在 | 无码 | `40008` |

**HTTP 状态码没变**，只有业务码变了。

### 怎么改

**用 HTTP 状态码判断的**：不用改。

**用业务码判断的**：按上表调整。

**更稳妥的做法**：改用语义化的码常量，而不是硬编码数字。框架侧：

```php
use SunsetReverie\Exception\ErrorCode;

if ($e->errorCode === ErrorCode::PayloadTooLarge->value) {
    // ...
}
```

### 自检

```bash
# 找出硬编码的错误码
grep -rn '30001\|40007\|30005\|60001' app/ resources/ 2>/dev/null
```

---

## 5. `insertGetId()` 返回 int（0.15.0）

### 现象

```php
$id = DB::table('posts')->insertGetId([...]);

var_dump($id === 1);        // 升级前 bool(true)  升级后 bool(true)
var_dump($id === '1');      // 升级前 bool(true)  升级后 bool(false) ← 变了
```

### 原因

PDO 的 `lastInsertId()` 返回字符串，0.15.0 起框架统一转成 `int`
（PostgreSQL 走 `RETURNING` 分支本来就返回 int，这样三库行为一致）。

### 怎么改

把 `(string)` 转换或字符串比较改掉：

```php
// 旧
$orderNo = 'ORD' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);

// 新（可以省掉转换，但写了也不错）
$orderNo = 'ORD' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
```

真正的坑是**严格比较**：

```php
// 旧
if ($id === $expectedStringId) { ... }

// 新
if ($id === (int) $expectedStringId) { ... }
```

### 自检

```bash
grep -rn "insertGetId" app/ | head -20
# 逐个看返回值有没有被当字符串用
```

---

## 6. 缓存驱动必须显式配置（0.23.0）

### 现象

```text
CacheException: 未找到缓存驱动配置 [redis]
```

### 原因

0.23.0 移除了 `redis` / `apcu` 的**内置默认配置**。

原因：如果默认配置存在，`store('redis')` 在**没有配置的情况下也会连 localhost:6379**——
开发机上看起来一切正常，上线后连错实例才发现。

现在只有 `array` / `file` 有默认值，其余必须写进 `config/cache.php`。

### 怎么改

在 `config/cache.php` 里显式声明：

```php
'stores' => [
    'file' => ['driver' => 'file', 'path' => 'storage/cache'],

    'redis' => [
        'driver' => 'redis',
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', ''),
        'database' => (int) env('REDIS_DB', 0),
        'timeout' => 1.5,
    ],
],
```

### 另一个相关的变化

`RedisStore::flush()` 在**前缀为空**时会抛异常而不是清空整个库：

```text
CacheException: 拒绝执行 flush：缓存前缀为空（会清空整个 Redis 库，含其他应用的数据）
```

确保 `config/cache.php` 里有 `'prefix' => 'sr:'`（骨架默认已配）。

### 自检

```bash
php -r '
require "vendor/autoload.php";  // 或框架的 autoload
$c = \SunsetReverie\Foundation\Application::boot(getcwd())->config();
echo "prefix: ", var_export($c->get("cache.prefix"), true), PHP_EOL;
foreach (array_keys((array) $c->get("cache.stores", [])) as $s) { echo "  store: $s\n"; }
'
```

---

## 7. `Auth::id()` 开始有值（0.31.0）

### 现象

原本走「游客」分支的逻辑，升级后走了「登录用户」分支。

### 原因

0.31.0 修复了一个静默 bug：`Request` 是不可变的，中间件用 `withAttribute()`
产生的是 clone，而容器里存的是进管道前的旧实例。

结果：认证中间件写入的 `auth.user` **永远读不到**，`Auth::id()` 恒为 `null`。

修复后该值正常了——如果你的业务代码里原本有「取不到用户」的兜底分支，
那些分支现在不会执行了。

### 怎么改

**这是修复，不是破坏**。但要检查原本的兜底逻辑是不是掩盖了问题：

```php
// 升级前一直走这里（因为 id 恒为 null）
$authorId = (int) (Auth::id() ?? 0);
if ($authorId === 0) {
    $authorId = 1;      // 临时兜底
}

// 升级后正常走这里
$authorId = (int) Auth::id();
```

建议把临时兜底去掉，并确认一下**历史数据里有没有 `author_id = 0` 的脏数据**：

```sql
SELECT COUNT(*) FROM posts WHERE author_id = 0;
-- 如果有，说明当年这个 bug 已经污染了数据，需要手工修
```

### 自检

```bash
# 找有没有依赖「取不到用户」的逻辑
grep -rn 'Auth::id() ??\|Auth::user() ??' app/
```

---

## 8. 新增能力（0.43.0，可直接用）

这几项不是「必须改」，是新东西。

### 统一的分页上限

以前各服务自己写 `min(50, ...)`，各写各的数字。现在：

```php
public function index(Request $request): array
{
    return $this->posts->paginate(
        page: $request->page(),          // 保证 ≥ 1
        perPage: $request->perPage(),    // 读 config('pagination.max_per_page')，默认 50
    );
}
```

上限改配置就能全局生效，不会漏改某一处而被拉出 10 万条。

### 请求体大小限制

```php
// config/http.php
'max_body_size' => 8 * 1024 * 1024,
```

路由上加 `body.size` 中间件即可：

```php
Route::post('/uploads', ...)->middleware(['auth', 'body.size']);
```

只看 `Content-Length`，超限直接 413，**不读 body**——读完再判断正是要避免的内存问题。
分块传输（无 `Content-Length`）放行，由 SAPI 的 `post_max_size` 兜底。

### 响应头会清洗换行

```php
->withHeader('X-User', $request->query('name'))
// 传 "alice\r\nSet-Cookie: admin=1" 会被剥成 "aliceSet-Cookie: admin=1"
```

如果你的代码里正好靠 `\r\n` 在头里做多行拼接（很少见），需要改。

---

## 附录：变更与版本对照

| 版本 | 变更类型 | 一句话 |
| --- | --- | --- |
| 0.45.0 | 内部 | PHPStan level 8（类型精度，无行为变化） |
| 0.44.0 | 内部 | PHPStan level 7 |
| 0.43.0 | 新增 + 加固 | `Request::perPage()` / `body.size` 中间件 / 响应头清洗 CRLF |
| 0.42.0 | 修复 | `make:migration` 不再要求数据库配置 |
| 0.41.0 | 破坏性 | `selectRaw()` 不再自带 `*` |
| 0.40.0 | 内部 | 架构检查 8 项（ARCH-3/4/8 语义调整） |
| 0.39.0 | 内部 | PHPStan level 6 全绿 |
| 0.38.0 | 新增 | `Blueprint::json()` |
| 0.37.0 | 新增 | `selectRawWith()` 支持绑定 |
| 0.36.1 | 破坏性 | `unique('col')` 语义修正 |
| 0.36.0 | 新增 | `whereGroup()` / `whereNotNull()` |
| 0.35.0 | 新增 | 图片组件（GD / Imagick） |
| 0.33.0 | 新增 | 邮件组件 |
| 0.32.0 | 新增 | 队列组件 |
| 0.31.0 | **行为修正** | `Auth::id()` 不再恒为 null |
| 0.30.0 | 新增 | `Response::stream()` 流式响应 |
| 0.29.0 | 新增 | 聚合查询（`groupBy` / `sum` 等） |
| 0.28.1 | 破坏性 | 路由参数自动 urldecode |
| 0.28.0 | 新增 | `OptionalAuth` / `increment()` / `uniqueIndex()` |
| 0.27.0 | 修复 | `RedisStore::flush()` 拒绝空前缀 |
| 0.26.0 | 修复 | PostgreSQL 方言（`DATETIME` / `BOOLEAN DEFAULT`） |
| 0.23.0 | 破坏性 | 缓存驱动必须显式配置 |
| 0.21.0 | 破坏性 | 错误码 18 → 39 条，多值修正 |
| 0.15.0 | 破坏性 | `insertGetId()` 返回 int |

---

## 保持更新的建议

- 升级前先读 CHANGELOG 对应版本段
- 本文档与 CHANGELOG 同版本发布，**两者不一致时以本文档为准**（它才是「怎么改」）
- 遇到本文档没覆盖的破坏性变更，说明文档漏了，应该补上
