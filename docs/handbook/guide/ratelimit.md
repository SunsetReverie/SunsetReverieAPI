# 限流

限流在**缓存**上做计数，所以要先有可用的缓存驱动（`file` / `redis` / `apcu`）。

::: tip 没有 CLI 命令
限流只有中间件和 `RateLimiter` 类，`php bin/sr` 里没有 `ratelimit:*` 命令。
清空计数用 `Cache::flush()` 或换 `prefix`。
:::

## 固定窗口，不是滑动窗口

这点必须先说清楚，否则你会对它的精度有错误预期。

源码：

```php
$windowStart = intdiv(time(), $decaySeconds) * $decaySeconds;
$cacheKey = $this->prefix . $key . ':' . $windowStart;
```

**窗口是对齐的**：

```text
decay_seconds = 60，
窗口在 12:00:00、12:01:00、12:02:00…… 切换

12:00:00–12:00:59  →  key: xxx:1793380800
12:01:00–12:01:59  →  key: xxx:1793380860
```

每个窗口一个独立计数器。

### 这个设计的缺陷：边界双倍流量

限制「每分钟 60 次」时：

```text
12:00:50  打 60 次  ← 窗口 A 用满
12:01:10  打 60 次  ← 窗口 B 用满

实际：20 秒内打了 120 次
```

**窗口交界处的实际速率可以是限制值的 2 倍**。

### 为什么还是用它

| 方案 | 精度 | 成本 |
| --- | --- | --- |
| **固定窗口**（当前） | 边界 2 倍 | 一次 `get` + 一次 `put` |
| 滑动窗口 | 准确 | 要存时间戳列表或多次计数 |
| 令牌桶 | 准确且平滑 | 要维护令牌状态 |

对「防爆破登录」「防刷接口」这类场景，**2 倍误差可以接受**——攻击者要卡准窗口边界才有用，而正常用户根本感知不到。

如果确实需要精确，把窗口调小（如 10 秒限 10 次），误差的绝对值跟着变小。

## 三种维度

```php
// config/ratelimit.php
'by' => 'ip',
```

| `by` | 计数键 | 含义 |
| --- | --- | --- |
| `ip`（默认） | `{ip}\|{route}` | **每个 IP 每个路由**独立配额 |
| `route` | `{route}` | 整个路由**全局共享**一个配额 |
| `global` | `global` | 所有路由共用一个配额 |

### 怎么选

| 想保护什么 | 用 |
| --- | --- |
| 防某个用户刷接口 | `ip` |
| 防后端服务被打挂（昂贵查询） | `route`——全站共用，谁打都算 |
| 全站总量控制 | `global` |

`route` 维度的一个例子：

```php
// 报表接口，一次查询要 3 秒，全站每分钟最多 10 次
Route::get('/reports/summary', ...)->middleware(['throttle:10,60']);
// 配上 'by' => 'route'，或者在这个规则里单独指定
```

## 用中间件

```php
// 每分钟 60 次（默认）
Route::get('/posts', ...)->middleware(['throttle']);

// 每分钟 300 次
Route::get('/posts', ...)->middleware(['throttle:300,1']);

// 每 5 分钟 10 次
Route::post('/login', ...)->middleware(['throttle:10,300']);
```

参数格式：`throttle:{次数},{窗口秒数}`。

::: tip 登录接口一定要限流
```php
Route::post('/login', [AuthController::class, 'login'], 'auth.login')
    ->middleware(['throttle:5,60']);
```

5 次/分钟。密码爆破的常见字典有上万条，限到 5 次/分钟意味着跑完要 33 小时——
足够让攻击者放弃。
:::

## 域级与路由级同时挂 throttle

`config/api.php` 可以给整个域配中间件，路由又能自己再挂一次。这里有个坑：

```php
// config/api.php
'admin' => ['middleware' => ['throttle:120,60']],      // 域级

// routes/admin/v1/auth.php
Route::post('/login', ...)->middleware(['throttle:5,60']);   // 路由级
```

**两个声明都会进管线**（域级通过 `Route::group()` 继承进每条路由的 `middlewareList()`）。

`ThrottleRequests` 的计数键默认是 `{ip}|{route}` —— 两个实例算**同一个 key**，
于是每次请求计数 **+2**：配 5 次/分钟实际约 2.5 次就 429。

::: tip 1.0.0-beta.2.4 起已修
`MiddlewareResolver` 现在按中间件类去重，**同一个类只保留最后一次声明**。
路由级的声明在列表后面，所以是「路由级覆盖域级」，不再双计数。

去重的代价：同一个中间件类**故意**挂两次（不同参数）的写法不再支持。
:::

但**只去重还不够**，参数优先级仍然要注意：

```php
// resolveRule() 的实际优先级
//   ① 路由上的参数（throttle:5,60）
//   ② config 的 ratelimit.routes
//   ③ config 的 ratelimit.default
```

所以如果域级写的是 `throttle:120,60`，`resolveRule()` 在 ① 就返回了，
**`ratelimit.routes` 里配的规则根本不会被读到**。

### 唯一正确的组合

```php
// config/api.php —— 域级挂 throttle，但**不带参数**
'admin' => [
    'middleware' => ['throttle'],
],

// config/ratelimit.php —— 具体规则全放这里
'routes' => [
    'admin.v1.auth.login' => ['max_attempts' => 5,   'decay_seconds' => 60],
    'admin.v1.auth.*'     => ['max_attempts' => 10,  'decay_seconds' => 60],
    'admin.*'             => ['max_attempts' => 120, 'decay_seconds' => 60],
],
```

| 写法 | 结果 |
| --- | --- |
| 域级 `throttle`（无参）+ config 配规则 | ✅ 规则生效，只计数一次 |
| 域级 `throttle:120,60` + config 配规则 | ❌ config 规则被忽略，全部走 120/60 |
| 域级和路由级都不挂 | ❌ **完全不限流**（config 规则没有实例去读） |
| 域级 `throttle:120,60` + 路由级 `throttle:5,60` | ⚠️ 去重后只用 5/60，但 config 规则仍被忽略 |

::: warning 第三种最容易忽略
`ratelimit.routes` 不是「自动生效的配置」，它需要一个 `throttle` 中间件实例来消费。

路由和域级都不挂 `throttle` 时，那份配置是**死配置**——一条都不会执行。
:::

## 按路由名配置

不想在每个路由上写参数时，写进配置：

```php
// config/ratelimit.php
'routes' => [
    // 精确匹配
    'admin.v1.auth.login' => ['max_attempts' => 5, 'decay_seconds' => 60],

    // 前缀通配（多个命中时取**最长**的那条）
    'admin.v1.auth.*' => ['max_attempts' => 10, 'decay_seconds' => 60],
    'admin.*' => ['max_attempts' => 120, 'decay_seconds' => 60],
],
```

**匹配优先级**：精确 > 长通配 > 短通配 > 默认规则。

::: warning 依赖路由名
所以路由**必须命名**，否则 `route.name` 是空的，通配符一条都匹配不到。

```php
Route::post('/login', ..., 'auth.login');    // ✅ 有名字
Route::post('/login', ...);                   // ❌ 名字是 null
```

（`beta.2` 起，未命名路由的 `name` 是 `null` 而不是退化成组前缀——见[路由](./routing#不传名字会怎样)。）
:::

### 规则里也能覆盖 `by`

```php
'routes' => [
    'admin.v1.reports.*' => [
        'max_attempts' => 10,
        'decay_seconds' => 60,
        'by' => 'route',       // 这个路由用全局配额
    ],
],
```

## 响应头

**每次请求都会带**（不超限也带）：

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 47
X-RateLimit-Reset: 1793380860
```

超限时（429）：

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 23
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1793380860

{"code":20005,"message":"请求过于频繁，请稍后再试"}
```

`Retry-After` 是**秒数**，客户端可以直接用来算退避时间。

## 手动限流

不适合用中间件的场景（比如按用户 ID 而不是 IP 限流）：

```php
use SunsetReverie\RateLimit\RateLimiter;

final class PostService
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function create(int $userId): array
    {
        // 每个用户每分钟最多发 5 篇
        $limit = $this->limiter->attempt('post:create:' . $userId, 5, 60);

        if (!$limit->allowed) {
            throw new HttpException(
                429,
                ErrorCode::TooManyRequests->value,
                sprintf('发帖太快了，%d 秒后再试', $limit->retryAfter)
            );
        }

        // ... 创建
    }
}
```

`RateLimiter` 的三个方法：

| 方法 | 说明 |
| --- | --- |
| `attempt($key, $max, $decay)` | 计数 +1，返回 `Limit` |
| `count($key, $decay)` | 只读当前计数，不增加 |
| `reset($key, $decay)` | 清零（比如登录成功后重置失败计数） |

`Limit` 的四个属性：

```php
$limit->limit;        // 上限（60）
$limit->remaining;    // 剩余（47）
$limit->retryAfter;   // 还有几秒窗口重置
$limit->allowed;      // 是否放行
```

### 登录成功后重置失败计数

这是常见需求：密码错 5 次锁定，但**登录成功后应该清零**。

```php
public function login(LoginRequest $request): array
{
    $key = 'login:' . $request->ip();

    $limit = $this->limiter->count($key, 300);

    if ($limit->remaining <= 0) {
        throw new HttpException(429, ErrorCode::TooManyRequests->value, '失败次数过多，请 5 分钟后再试');
    }

    $user = $this->users->verify($request->validated());

    if ($user === null) {
        $this->limiter->attempt($key, 5, 300);   // 只失败才计数
        throw new HttpException(401, ErrorCode::CredentialsInvalid->value, '账号或密码错误');
    }

    $this->limiter->reset($key, 300);            // 成功则清零

    return ['token' => Auth::jwt()->issue((string) $user['id'])];
}
```

**只累计失败次数**，比「所有尝试都算」更友好——正常用户不会因为登录几次就被锁。

## 配置

```php
// config/ratelimit.php
'enabled' => env('RATELIMIT_ENABLED', true),
'store' => env('RATELIMIT_STORE', ''),        // 留空 = 用 cache.default
'prefix' => 'rl:',
'by' => 'ip',

'default' => [
    'max_attempts' => 60,
    'decay_seconds' => 60,
],

'routes' => [...],
```

### `store` 建议用 `redis`

限流计数是**高频写**。用 `file` 驱动时每次都要 `fopen` + `fwrite` + `rename`，
实测 `file` 比 `redis` 慢约 1.6 倍（见[缓存](./cache#file-驱动比-redis-还慢)）。

高并发下调 `RATELIMIT_STORE=redis`。

### `enabled = false`

压测或本地开发时关掉：

```ini
RATELIMIT_ENABLED=false
```

**生产绝对不要关**——`enabled=false` 时中间件直接放行，等于没有限流。

## 多域隔离

每个域有独立的 `error_segment`，但**限流计数不按域隔离**（键里没有域名）。

同一个 IP 访问 `/admin/v1/login` 和 `/web/v1/login` 时，路由名不同
（`admin.v1.auth.login` vs `web.v1.auth.login`），所以**配额是分开的** ✓

但如果两个域有同名路由（比如都叫 `auth.login` 且没有域前缀），计数会混在一起——
不过这种情况在框架里不会发生，因为域名前缀是自动加的。

## 常见错误

### 限流完全不生效

| 检查 | 怎么看 |
| --- | --- |
| 中间件挂了吗 | `->middleware(['throttle'])` |
| `enabled` 是 true 吗 | `RATELIMIT_ENABLED` |
| 缓存能用吗 | 换个接口测 `Cache::put()` |
| 请求真的超了吗 | 响应头 `X-RateLimit-Remaining` |

**先看响应头**——有没有 `X-RateLimit-*` 就知道中间件跑没跑。

### 限流太严，正常用户被拦

```text
throttle:5,60    ← 每分钟 5 次
```

如果你的接口被轮询（比如前端每 5 秒拉一次状态），5 次/分钟必然误伤。

改成更符合实际的值，或者换 `by` 维度。

### 多个进程的计数不一致

`file` 驱动在多机部署时**不共享**——A 机的计数 B 机看不到。

多机必须用 `redis`：

```ini
RATELIMIT_STORE=redis
```

### 通配符规则不生效

路由没命名。检查：

```bash
php bin/sr route:list | grep login
```

「名称」列是 `-` 的话，`admin.v1.auth.*` 匹配不到。

## 下一步

- **[缓存](./cache)** —— 限流的底层存储
- **[认证](./auth)** —— 登录接口的限流组合
- **[错误处理](./error-handling)** —— `20005` 的语义
