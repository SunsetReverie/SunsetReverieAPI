# 认证

框架只提供 **token 的签发与校验**，不提供用户表、不改密码、不管注册流程。

::: warning 没有密码哈希封装
框架**不封装** `password_hash()`——用 PHP 原生的就行：

```php
// 注册
$hash = password_hash($password, PASSWORD_DEFAULT);

// 登录
if (!password_verify($password, $user['password_hash'])) {
    throw new HttpException(401, ErrorCode::CredentialsInvalid->value, '账号或密码错误');
}
```

理由：这两个函数是 PHP 内置的，再包一层只是多一层间接。
:::

## 三种状态

| 中间件 | 无 token | 有 token 但无效 | 有效 token |
| --- | --- | --- | --- |
| 无 | 放行，`Auth::id()` 为 `null` | 放行，`Auth::id()` 为 `null` | 解析，`Auth::id()` 有值 |
| `auth.optional` | 放行，`null` | **放行**，`null` | 解析，有值 |
| `auth` | **401** | **401** | 解析，有值 |

```php
// 完全不关心登录状态
Route::get('/health', ...);

// 游客也能看，登录了多返回点东西
Route::get('/posts', ...)->middleware(['auth.optional']);

// 必须登录
Route::post('/posts', ...)->middleware(['auth']);
```

## API

```php
use SunsetReverie\Support\Facades\Auth;

Auth::id();        // ?string —— 未登录返回 null
Auth::user();      // ?AuthenticatedUser
Auth::check();     // bool
Auth::guard();     // 当前 Guard 实例
Auth::jwt();       // JwtGuard（签发 token 用）
```

::: warning `id()` 返回 `string` 不是 `int`
```php
Auth::id();              // "42"（string）或 null
(int) Auth::id();        // 42 —— 需要整数时要自己转
Auth::id() ?? '';        // ⚠️ 未登录时是 ''，不是 '0'
```

`(int) null === 0`，所以常见写法：

```php
$userId = (int) (Auth::id() ?? 0);     // 0 表示游客
```

**注意 v0.31 的行为变更**：之前 `Auth::id()` 恒为 `null`（bug），
如果你的代码里有 `Auth::id() ?? 默认用户` 这种兜底，升级后会走另一条分支。
见[升级指南](./upgrade)。
:::

## 签发 token

```php
public function login(LoginRequest $request): array
{
    $user = $this->users->findByEmail($request->validated()['email']);

    if ($user === null || !password_verify($request->validated()['password'], $user['password_hash'])) {
        throw new HttpException(401, ErrorCode::CredentialsInvalid->value, '账号或密码错误');
    }

    return [
        'token' => Auth::jwt()->issue((string) $user['id']),
        'expires_in' => 7200,
    ];
}
```

`issue()` 的两个参数：

```php
issue(string $subject, array $claims = []): string
```

- `$subject` 写进 `sub` 声明（通常是用户 ID）
- `$claims` 是额外的自定义声明

```php
Auth::jwt()->issue('42', [
    'name' => '夕梦',
    'roles' => ['admin'],
]);
```

## 双 Token（推荐）

单个 `issue()` 只发 access token（默认 2 小时）。用户要么频繁重新登录，要么把
`ttl` 调得很长——前者体验差，后者泄露后窗口太大。

标准做法是**短 access + 长 refresh**：

```php
public function login(LoginRequest $request): array
{
    $user = $this->users->findByEmail($request->validated()['email']);

    if ($user === null || !password_verify($request->validated()['password'], $user['password_hash'])) {
        throw new HttpException(401, ErrorCode::CredentialsInvalid->value, '账号或密码错误');
    }

    return Auth::jwt()->issueTokens((string) $user['id'], ['name' => $user['name']]);
}
```

返回结构可以直接当响应体的 `data`：

```json
{
  "access_token": "eyJ...",
  "refresh_token": "eyJ...",
  "token_type": "Bearer",
  "expires_in": 7200
}
```

### 刷新接口

```php
public function refresh(Request $request): array
{
    $token = (string) $request->json('refresh_token', '');

    return Auth::jwt()->refresh($token);
}
```

返回的是**新的一对**，旧 refresh token 同时作废（轮换）。

::: warning 刷新失败要跳登录
`refresh()` 在 token 过期、已被使用过（超出宽限期）、或已登出时抛
`AuthenticationException`（20002）。

前端遇到 20002 就清掉本地 token 跳登录页——不要重试。
:::

### 轮换与宽限期

| 机制 | 作用 |
| --- | --- |
| **轮换** | 每次刷新生成新的 refresh token，旧的立即删除——被窃取后最多用一次 |
| **宽限期** | 并发刷新时（多个请求同时带同一个 token），期内重放返回**同一对**，不会把用户踢下线 |

宽限期默认 30 秒（`JWT_REFRESH_GRACE`）。关掉的话前端必须自己做「单飞」
（同一时刻只发一个刷新请求），否则并发会失败。

### 登出

```php
public function logout(Request $request): array
{
    Auth::jwt()->logout((string) $request->json('refresh_token', ''));

    return $this->success(null, '已退出');
}
```

只撤销 refresh token，access token 会自然过期（默认 2 小时内仍有效）。

**登出是幂等的**：token 无效或已过期也不抛异常——否则「token 过期后点登出」会报错。

### 强制下线（改密码 / 封号）

```php
// 改密码成功后
Auth::jwt()->revokeAll((string) $user['id']);
```

::: warning 需要开启 `enforce_version`
```ini
JWT_ENFORCE_VERSION=true
```

开启后每个带认证的请求会多一次缓存读取（比对 Token 里的 `ver` 与当前版本号）。

**不开启时 `revokeAll()` 对已签发的 access token 无效**——这是 JWT 无状态的本性，
不是 bug。不开的话就只能等 access 自然过期（2 小时内），或者把 ttl 调短。
:::

### 两种 Token 不能混用

| Token | 能用在哪 |
| --- | --- |
| access | 访问接口（`Authorization: Bearer`） |
| refresh | **只能**调刷新接口 |

拿 refresh token 去访问接口会 401（`Token 类型不匹配`）——
否则 30 天有效期的 refresh 就等于 30 天的访问权限，「短 access」的设计完全失效。

### 存储要求

refresh token 要能撤销、能轮换，所以存在**缓存**里（存的是 sha256 摘要，不是原文）。

| 缓存驱动 | 是否可用 |
| --- | --- |
| `file` / `redis` / `apcu` | ✅ |
| `array` | ❌ 进程结束就没了，刷新必然失败 |

缓存不可用时 `issueTokens()` 会抛明确异常，而 `issue()`（单 access）照常工作。

### 前端怎么处理

```ts
// 请求拦截：带上 access token
config.headers.Authorization = `Bearer ${accessToken}`

// 响应拦截：401 + code 20002 时尝试刷新
if (code === 20002) {
  const pair = await axios.post('/auth/refresh', { refresh_token: refreshToken })
  // 存新的两个 token，重放原请求
}
```

::: tip 并发请求的坑
页面同时发 5 个请求，全部 401，会触发 5 次刷新。

宽限期能兜住（都返回同一对），但更稳妥的做法是**前端单飞**：
用一个 promise 挡住后续刷新请求，第一个完成后大家共用结果。
:::

## 在中间件里用

```php
Route::get('/me', [UserController::class, 'me'])
    ->middleware(['auth']);          // ✅
```

`auth` 中间件校验通过后把用户挂到请求上：

```php
public function me(Request $request): Response
{
    $user = $request->attribute('auth.user');   // ?AuthenticatedUser

    return $this->success(['id' => $user?->id]);
}
```

控制器里也可以用门面：`Auth::id()` / `Auth::user()`。

不需要登录、但想「有 token 就识别」的接口用 `optional.auth`：

```php
Route::get('/posts', [PostController::class, 'index'])
    ->middleware(['optional.auth']);   // 匿名也能看，登录则带上用户
```

::: warning `auth` 不接受参数
```php
->middleware(['auth:admin']);    // ❌ 直接抛异常
```

```text
中间件 [...Authenticate] 不接受参数（声明为 auth:admin），请去掉冒号后的内容
```

**guard 只能从配置来**，不能靠路由参数切换。`Authenticate` 的构造函数是：

```php
public function __construct(
    private readonly AuthManager $auth,
    private readonly ?string $guard = null,   // ← 由容器注入
) {}
```

多域项目的鉴权区分不靠 `auth:admin`，靠**域绑定**（下一节）：

```php
// config/api.php
'domains' => [
    'admin' => ['middleware' => ['auth']],
    'web'   => ['middleware' => ['optional.auth']],
],
```

每个域挂自己的鉴权中间件，token 的 `aud` 会拦住跨域使用。
:::

## 域绑定（多域项目必读）

**同一个 token 不能跨域使用。**

### 怎么实现的

签发时把**当前域名**写进 `aud`：

```php
$audience = $this->domainContext?->name() ?? '';

if ($audience !== '' && $this->enforceAudience && !isset($claims['aud'])) {
    $claims['aud'] = $audience;
}
```

校验时比对：

```text
token.aud = 'web'  →  请求 /admin/...  →  aud 不匹配  →  401 / 20010
```

### 没有 aud 的 token 同样拒绝

```php
/*
 * 多域项目**没有 aud 的 Token 同样拒绝**，
 * 否则等于留下「去掉 aud 就能跨域使用」的后门。
 */
```

所以自己手工构造 token（不带 `aud`）在**多域项目里用不了**。

### 单域项目不校验

`domains` 为空时不校验 `aud`——单域没有「跨域」的概念。

## AuthenticatedUser

```php
final class AuthenticatedUser
{
    public function __construct(
        public readonly string $id,          // sub 声明
        private readonly array $claims = [], // 全部声明
    ) {
    }

    public function claim(string $key, mixed $default = null): mixed;
    public function claims(): array;
}
```

```php
$user = Auth::user();

$user->id;                    // "42"
$user->claim('name');         // "夕梦"
$user->claim('roles', []);    // ['admin']
$user->claims();              // 全部声明
```

::: danger 它不含用户资料，也不查数据库
类注释写得很明确：

> 直接从 Token 载荷构造，**不做数据库查询**；
> 需要完整用户信息时，业务在控制器里用 id 自行查询。

所以 `Auth::user()->email` **不存在**——token 里没这个字段。

```php
// ✅ 需要资料就自己查
$profile = $this->users->findById((int) Auth::id());

// ❌ 不要期待 token 里带全部字段
Auth::user()->email;
```
:::

**为什么这么设计**：token 里的信息越多，越长、越容易过期不一致
（用户改了昵称，旧 token 里还是旧的）。只放 ID，资料用 ID 查。

## 配置

```php
// config/auth.php
'default' => env('AUTH_GUARD', 'jwt'),

'guards' => [
    'jwt' => [
        'driver' => 'jwt',
        'secret' => env('JWT_SECRET', ''),
        'ttl' => (int) env('JWT_TTL', 7200),      // 秒
        'leeway' => 30,                            // 时钟偏移容忍（秒）
        'claim_key' => 'sub',                      // 从哪个声明取用户 ID
    ],
],
```

### `secret` 必须设置

```bash
php bin/sr jwt:secret     # 生成并写入 .env
```

::: danger 换 secret 会让所有已发 token 立即失效
用户会全体掉线。

换之前想清楚：是**所有** token 都有安全问题，还是只换某几个用户？
后者应该做的是「让那几个用户的 token 失效」，不是换全局密钥。
:::

### `refresh_ttl` 决定多久要重新登录

`refresh_ttl`（默认 30 天）是「用户多久不活跃就被踢出」的实际值。

它要求缓存可用——`array` 驱动进程结束就没了，不能用。

### `enforce_version` 的代价

开启后每个带认证的请求会多一次缓存读取（比对 Token 里的 `ver`）。

换来的是 `revokeAll()` 能**立即**让某用户所有旧 Token 失效（改密码、封号、强制下线）。

不开启的话，只能等 access 自然过期（`ttl` 秒内），或者把 `ttl` 调短。

### `leeway` 是给分布式部署的

多台机器时间不完全同步时，`exp` 判断会误杀。`30` 秒的容忍足够。

## 中间件

```php
Route::get('/me', ...)->middleware(['auth']);
Route::get('/posts', ...)->middleware(['auth.optional']);
```

`Authenticate` 中间件做的事：

```text
1. 从 Authorization: Bearer <token> 取 token
2. 无 token → 401 / 20001（未登录）
3. decode 失败 → 401 / 20002（token 无效或过期）
4. 多域时校验 aud → 不匹配 401 / 20010
5. 把 AuthenticatedUser 写进 request attribute（auth.user）
```

`OptionalAuth` 的差别：前两步失败时**不抛异常**，继续往下走。

## 常见错误

### `Auth::id()` 一直是 null

四个可能：

| 原因 | 检查 |
| --- | --- |
| 路由没挂 `auth` 或 `auth.optional` | 中间件没跑，`auth.user` 没写入 |
| `Authorization` 头没传 | `Bearer <token>`，注意空格 |
| 多域项目 token 没 `aud` | 自己手工造的 token |
| `JWT_SECRET` 两边不一致 | 签发和校验用了不同的 key |

### `token 无效` 但刚签发的

```text
JWT_TTL=7200
```

如果服务器时间比签发时**快**了超过 `leeway`，会立刻判过期。查一下两边的 `date`。

### 跨域请求 401 / 20010

`aud` 不匹配。要么用目标域重新登录，要么这个接口不该要求 `auth`。

### 想「放行所有 token」

**不要**。`auth.optional` 已经满足「有就解析、没有也放行」；
如果连**无效** token 都想放行，那是设计问题，不是配置问题。

## 下一步

- **[控制器](./controller)** —— `Auth::id()` 在控制器里的用法
- **[中间件](./middleware)** —— 写「要求邮箱已验证」这类中间件
- **[限流](./ratelimit)** —— 登录接口防爆破（`throttle:5,60`）
