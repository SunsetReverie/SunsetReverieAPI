<div align="center">

# SunsetReverie

**零依赖的 PHP 微服务框架 —— 不装 Composer 也能跑**

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![Version](https://img.shields.io/badge/version-1.0.0--beta.2.3-blue)](CHANGELOG.md)
[![Runtime deps](https://img.shields.io/badge/runtime%20deps-0-brightgreen)](core/Foundation/autoload.php)
[![Tests](https://img.shields.io/badge/tests-399%20passing-brightgreen)](tests/)
![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

多 API 域隔离 · 组件可替换 · 无 ORM —— 为「一个人维护的中型后端」而设计

</div>

```bash
git clone https://github.com/SunsetReverie/SunsetReverie.git SunsetReverie && cd SunsetReverie
php bin/sr new demo && cd demo
php bin/sr serve
```

三行启动，不需要 `composer install`。

> 没有 git？GitHub 的 **Code → Download ZIP** 也能用，
> 解压后直接 `php bin/sr new demo`——`bin/sr` 不依赖目录名。
> 详见[安装文档](docs/handbook/guide/install.md#方式四直接下载-zip简单但不适合长期项目)。

---

## 目录

- [它解决什么问题](#它解决什么问题)
- [快速开始](#快速开始)
- [核心特性](#核心特性)
- [写一个接口](#写一个接口)
- [当前状态](#当前状态)
- [刻意不做的事](#刻意不做的事)
- [文档](#文档)
- [关于这个项目](#关于这个项目)

---

## 它解决什么问题

### 多端并存不该意味着多套代码

PC 站、移动 App、小程序、后台——四个端的接口形态不同，业务规则却相同。

多数框架里这会演变成四套 controller 各写一遍，或者满屏 `if ($platform === ...)`。

**这个框架把「域」做成第一等公民**：每个域独立前缀、独立版本、独立中间件，共用同一批领域服务。token 跨域不通用——`web` 域的 token 访问 `admin` 会被直接拒绝。

### 零依赖不是口号

```bash
$ grep -r '^use [A-Z]' core/ | grep -v SunsetReverie
（无输出）
```

核心 56 个文件 + 10 个组件，全部只用 PHP 内置能力。

部署时不需要 `vendor/`，不需要 `composer.lock`，不需要处理「协作时 lock 文件冲突」——这些摩擦在小团队里占的精力比想象中多。

### 能一眼看出查了几次库

没有 ORM 是刻意的选择：

```php
$this->db->table('posts')->where('status', 1)->limit(20)->get();
```

SQL 在眼前明明白白。N+1 问题在写的时候就看得见，而不是等它变成慢查询再回来翻 `EXPLAIN`。

---

## 快速开始

### 1. 检查环境

```bash
php bin/sr env:check
```

需要 **PHP 8.3+** 和几个常见扩展（`pdo` `json` `mbstring` `fileinfo` `openssl`）。
不需要 Composer。

### 2. 生成项目

```bash
# 单域（只有一个 API 分组）
php bin/sr new myapp

# 多域（互相隔离的 API 域）
php bin/sr new myapp --domains=admin,web,app,miniapp
```

骨架包含：路由、控制器、领域服务、中间件、迁移、配置，全部可运行。

### 3. 建表并启动

```bash
cd myapp
php bin/sr migrate
php bin/sr serve
```

```bash
curl http://127.0.0.1:8000/health
# {"code":0,"message":"ok","data":{"status":"healthy"},...}
```

---

## 核心特性

| 能力 | 说明 |
| --- | --- |
| **零运行时依赖** | 核心与组件只用 PHP 内置能力，部署不需要 Composer |
| **多 API 域** | 域 → 版本 → 路由三层隔离，token 不跨域 |
| **组件可替换** | 缓存 4 驱动 · 数据库 3 种 · 图片 2 种 · 邮件 2 种，全部基于接口 |
| **契约优先** | 39 条错误码，HTTP 状态与业务码一一对应 |
| **可验证** | 399 用例 · PHPStan level 8 · 架构检查 8 项 · 覆盖率分层达标 |
| **真实项目验证** | 63 路由 / 20 表的生产形态项目跑通全部业务闭环 |

### 已提供的组件

| 组件 | 能力 |
| --- | --- |
| `database` | Query Builder · 迁移 · 三库方言（MySQL / PostgreSQL / SQLite） |
| `cache` | file / array / redis / apcu 四驱动 |
| `auth` | JWT · 域绑定 · 密码哈希 |
| `validation` | 规则校验 · FormRequest |
| `upload` | 扩展名 + MIME 双检 · 危险扩展名拦截 |
| `image` | GD / Imagick 双驱动 · 缩放裁切 · 格式转换 · 水印 · EXIF 方向 |
| `mail` | SMTP（明文）· 日志驱动 |
| `queue` | 数据库队列 · 重试退避 · 死信 |
| `log` | 分级日志 · 按天切分 |
| `ratelimit` | 滑动窗口 · 按域隔离 |

---

## 写一个接口

### 路由

`routes/web.php`：

```php
use App\Api\Web\Controller\PostController;
use SunsetReverie\Support\Facades\Route;

return function (Route $router): void {
    Route::group(['prefix' => '/v1'], function (Route $router): void {
        Route::get('/posts', [PostController::class, 'index'], 'post.index')
            ->middleware(['auth.optional']);

        Route::post('/posts/{id}/like', [PostController::class, 'like'], 'post.like')
            ->middleware(['auth']);
    });
};
```

### 控制器：只做参数转换

```php
final class PostController
{
    public function __construct(private readonly PostService $posts)
    {
    }

    public function like(Request $request, int $id): array
    {
        return $this->posts->like($id, (int) Auth::id());
    }
}
```

### 领域服务：写真正的规则

```php
final class PostService
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function like(int $postId, int $userId): array
    {
        // 幂等靠唯一索引兜底，不靠应用层「先查再插」——后者并发下会重复
        try {
            $this->db->transaction(function () use ($postId, $userId): void {
                $this->db->table('post_likes')->insert([
                    'post_id' => $postId,
                    'user_id' => $userId,
                ]);

                // 原子自增，不是「先读后写」——后者并发下会丢更新
                $this->db->table('posts')
                    ->where('id', $postId)
                    ->increment('like_count');
            });
        } catch (QueryException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
        }

        return $this->likeState($postId, $userId);
    }
}
```

两个细节值得注意：

- **唯一索引兜底**比「先查再插」可靠：查和插之间有时间窗口，并发时必然重复
- **`increment()` 是原子操作**：`UPDATE ... SET x = x + 1` 在数据库端完成，不需要事务隔离级别帮忙

---

## 当前状态

```text
版本     1.0.0-beta.2.3（功能完整，API 可能微调）
测试     399 用例 / 1059 断言
覆盖     core（除 CLI）83.2% · components 75.6%
静态分析 PHPStan level 8（最高级别）
架构检查 8 项
发布门禁 13 / 13
```

> **beta 意味着**：可以用，若发现设计问题会发 `beta.2` 而不是升大版本。
> 详见 [CHANGELOG](CHANGELOG.md)。

### 已验证的环境

| 项 | 版本 | 验证情况 |
| --- | --- | --- |
| PHP | **8.3.36 · 8.4.27 · 8.5.2** | 三版本各跑 399 个用例，全绿 |
| MySQL | 8.x | 方言契约 4/4 |
| PostgreSQL | 18.6 | 方言契约 4/4 |
| SQLite | 3.x | 测试默认后端 |
| 缓存 | file · array · redis · apcu | 各自有单测 |

三个 PHP 版本上**全部 399 个用例都执行**（无跳过）。8.3 / 8.4 上断言数少 47 个，
来自可选扩展（imagick / redis / apcu）缺失——每个测试都跑了，只是双驱动对照少一半分支。

### 实战验证

框架在一个真实项目里跑过完整业务闭环（63 条路由 · 20 张表 · 12 个领域服务）：

| 场景 | 验证内容 |
| --- | --- |
| 订单并发扣减 | 10 并发抢最后 1 件 → 只成功 1 个，库存归零 |
| 多字段加权搜索 | 标题 100 / 摘要 50 / 正文 10，SQL 端算分排序 |
| RBAC 角色继承 | 两级继承，环检测 0.66ms 返回无死循环 |
| 队列异步邮件 | 接口 5.6ms 返回，worker 异步发送 |
| 图片处理 | 1600×1200 → 三档缩略图，232ms |
| CSV 流式导出 | Generator + `Response::stream`，内存与行数无关 |

每次框架升级后跑全接口回归：

```text
读接口 32 条 + 写接口 15 项
通过 47 ｜ 可疑 3 ｜ 失败 0
```

这不是「单元测试通过」——是**真实 HTTP 请求走完整个框架链路**。

---

## 刻意不做的事

这些不是「没做完」，是**明确不做**：

| 不做 | 原因 |
| --- | --- |
| **ORM** | 隐式 SQL 与 N+1 在真实业务里更难排查。Query Builder + 领域服务够用，而且看得见查了几次库 |
| **Gate / Policy** | 完整授权体系是大工程。四档够用的做法：路由中间件 → 服务内判断 → 独立策略类 → 数据归属过滤 |
| **自动 CRUD 生成** | 生成的代码往往要改一半，不如一开始就手写清楚 |
| **内置前端 / 模板引擎** | 这是 API 框架，视图交给前端 |

---

## 文档

| 文档 | 回答什么问题 |
| --- | --- |
| [使用手册](docs/handbook/index.md) | 安装、第一个接口、设计取舍（VitePress 站点） |
| [升级指南](docs/handbook/guide/upgrade.md) | **我该怎么改我的代码**（破坏性变更 + 自检命令） |
| [变更记录](CHANGELOG.md) | 每个版本改了什么、修了哪些 bug |
| [安全策略](SECURITY.md) | 漏洞往哪报、已有哪些防护、上线检查清单 |

升级前先读升级指南——它管「怎么改」，CHANGELOG 只管「改了什么」。

完整的设计决策与内部规划（产品规划文档、交付清单）由维护者在本地保管，不随仓库分发。

本地预览手册：

```bash
cd docs && npm install && npm run dev   # http://localhost:5173
```

---

## 关于这个项目

这是一个**由单人维护**的框架项目。核心代码不接受外部 PR，但欢迎反馈：

- 发现问题 → 提 issue
- 安全漏洞 → 按 [SECURITY.md](SECURITY.md) 的渠道私密报告
- 用着不顺手 → 也是 issue，**beta 阶段改成本最低**

仓库只包含框架本体与测试代码。CI 配置、静态分析规则、内部设计文档在维护者本地运行——
这不是缺失，是刻意为之：在框架稳定之前，对外只提供使用者需要的那部分。

---

<div align="center">

[MIT](LICENSE) © SunsetReverie

</div>
