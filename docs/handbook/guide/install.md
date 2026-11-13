# 安装

三分钟跑起来。不需要 Composer。

## 环境要求

### PHP 8.3 或更高

框架用到了 `readonly` 属性、`never` 返回类型、枚举——这些在 8.3 之前不存在或不完整。

```bash
php -v
# PHP 8.3.x 或更高
```

::: tip 已验证的版本
8.3.36 / 8.4.27 / 8.5.2 三个版本各跑过完整测试套件。

如果你在 8.3 上遇到问题，很可能是用了 8.4+ 才有的语法——框架本身没有。
:::

### 必需的扩展

| 扩展 | 用途 | 能省吗 |
| --- | --- | --- |
| `pdo` | 所有数据库访问 | 不能 |
| `json` | 请求/响应、缓存序列化 | 不能 |
| `mbstring` | 中文截断、大小写转换 | 不能 |
| `fileinfo` | 上传文件的真实 MIME 检测 | 上传功能需要 |
| `openssl` | JWT 签名、密码哈希 | 认证功能需要 |
| `pdo_sqlite` | 测试默认后端 | 跑测试需要 |

### 可选的扩展

| 扩展 | 启用的能力 |
| --- | --- |
| `pdo_mysql` / `pdo_pgsql` | 对应的数据库驱动 |
| `gd` / `imagick` | 图片处理（`auto` 优先 GD，快 2 倍） |
| `redis` / `apcu` | 对应的缓存驱动 |
| `pcov` | 覆盖率统计 |

**一个都不装也能跑**——缺哪个，对应的测试分支会自动跳过。

## 获取框架

两个仓库，内容完全同步：

| 平台 | 地址 |
| --- | --- |
| GitHub | `https://github.com/SunsetReverie/SunsetReverieAPI.git` |
| Gitee（国内更快） | `https://gitee.com/SunsetReverie/sunset-reverie-api.git` |

::: tip clone 时指定目录名
两个仓库的路径大小写风格不同：

| 平台 | 路径 |
| --- | --- |
| GitHub | `SunsetReverie/SunsetReverieAPI` |
| Gitee | `SunsetReverie/sunset-reverie-api`（仓库名全小写带横线） |

```bash
git clone <地址> SunsetReverieAPI      # 显式指定目录名，两边结果一致
```

不指定的话，Gitee 会 clone 出 `sunset-reverie-api/` 目录。
:::

三种方式，按场景选。

### 方式一：用 CLI 生成项目（推荐）

框架自带 `sr new`，会生成一个可直接运行的项目骨架：

```bash
git clone https://github.com/SunsetReverie/SunsetReverieAPI.git SunsetReverieAPI
cd SunsetReverieAPI
php bin/sr new myapp
cd myapp
```

生成的 `myapp/` 里已经包含：路由、控制器、领域服务、中间件、迁移、配置。**全部可运行**，不需要你再补任何东西。

### 方式二：作为 submodule 引入现有项目

适合「已有项目，想接入框架」：

```bash
cd your-project
git submodule add https://github.com/SunsetReverie/SunsetReverieAPI.git sunsetreverie
```

然后在 `your-project/bin/sr` 放一个转发脚本：

```php
#!/usr/bin/env php
<?php
// 告诉框架「项目根在哪」，其余交给框架
putenv('SR_BASE_PATH=' . dirname(__DIR__));
require __DIR__ . '/sunsetreverie/bin/sr';
```

::: warning submodule 的 file 协议限制
如果你的 submodule 指向**本地路径**（开发期常见），Git 2.38.1 起会拒绝：

```text
fatal: transport 'file' not allowed
```

这是因为 CVE-2022-39253 的修复。临时放行：

```bash
git -c protocol.file.allow=always submodule update --remote sunsetreverie
```

指向 https URL 时不需要这个参数。
:::

### 方式三：直接 clone 看源码

只是想读代码或跑测试：

```bash
git clone https://github.com/SunsetReverie/SunsetReverieAPI.git SunsetReverieAPI
cd SunsetReverieAPI
php tests/run.php
```

### 方式四：直接下载 ZIP（简单，但不适合长期项目）

不想装 git，或者内网环境配不了凭据？GitHub 的 **Code → Download ZIP** 也能用。

```bash
# 1. 浏览器下载后解压，得到一个 SunsetReverie-main/ 目录
unzip SunsetReverie-main.zip

# 2. 建议重命名——去掉 -main 后缀，命令行里看着清楚
mv SunsetReverie-main sunsetreverie

# 3. 验证完整性（ZIP 偶尔会下不完整）
cd sunsetreverie
php bin/sr help        # 能看到 40 条命令 = 完整
php bin/sr about       # 能看到版本号 = 核心文件齐了

# 4. 之后和 clone 的用法完全一样
php bin/sr new myapp
```

::: tip 目录名可以随便改
`bin/sr` 用 `dirname(__DIR__)` 定位框架根，**不依赖目录名**。叫 `sunsetreverie`、`sr`、`framework` 都能跑。

改名的唯一影响是：`sr new` 生成的 shim 里记的相对路径会跟着变。
:::

#### ZIP 方式的三个限制

| 限制 | 后果 | 应对 |
| --- | --- | --- |
| **没有 `.git` 目录** | 无法 `git pull` 更新，也无法作为 submodule | 长期项目请改用 clone |
| **更新要重新下载覆盖** | 会**覆盖你对框架代码的任何修改** | 不要改框架代码；非要改，先 fork 或 clone |
| **没有版本历史** | 出问题查不到是哪次改动引入的 | 记录下载时的版本号（`sr about` 里有） |

#### 怎么更新（ZIP 方式）

```bash
# 1. 记下当前版本
php bin/sr about | grep 版本

# 2. 下载新版 ZIP，解压到临时目录
#    注意：解压出来的目录名取决于下载来源
#    （GitHub 的 main 分支是 SunsetReverie-main，tag 是小写的 sunsetreverie-1.0.0-beta.2.4）
unzip SunsetReverie-*.zip -d /tmp/sr-new

# 3. 用通配符找出实际目录，避免写死名字
NEW=$(ls -d /tmp/sr-new/*/ | head -1)
echo "新版在：$NEW"

# 4. 先确认自己没改过框架代码（有差异就先备份）
diff -rq sunsetreverie/core "${NEW}core"

# 5. 只覆盖三个代码目录
rm -rf sunsetreverie/core sunsetreverie/components sunsetreverie/skeleton
cp -R "${NEW}core" "${NEW}components" "${NEW}skeleton" sunsetreverie/

# 6. 验证
php bin/sr about     # 版本号应该变了
php tests/run.php    # 398 个用例（可选，但建议）
```

::: warning 不要覆盖 bin/
第 4 步只覆盖 `core` / `components` / `skeleton`。

`bin/` 里可能有你的 shim（业务项目转发脚本），覆盖会把自定义的 `SR_BASE_PATH` 冲掉。

同理 `config/`（如果有本地改动）也不要覆盖。
:::

#### 什么时候 ZIP 就够用

| 场景 | 够用 |
| --- | --- |
| 只想跑一次看看效果 | ✅ |
| 教学、演示、离线环境 | ✅ |
| 内网服务器，不想配 git 凭据 | ✅ |
| 需要跟着框架更新 | ❌ 用 clone |
| 要提 issue 反馈问题 | ❌ 需要能给出 commit 版本 |

## 四种方式怎么选

| 场景 | 用哪个 |
| --- | --- |
| 新项目，从零开始 | 方式一（`sr new`） |
| 已有项目，增量接入 | 方式二（submodule） |
| 想读源码 / 参与框架开发 | 方式三（clone） |
| 多个项目共用一个框架副本 | 方式二（submodule） |
| 不想装 git / 内网环境 | 方式四（ZIP） |
| 需要跟版本更新 | 方式一或二（有 `.git`） |

::: tip 一句话选择
**有 git 就用 git**。ZIP 只解决「没有 git 也能用」，代价是更新麻烦、改不动代码。
:::

## 检查环境

生成项目后，先跑一次自检：

```bash
cd myapp
php bin/sr env:check
```

输出示例：

```text
环境自检

[PHP]
  ✓ 8.5.2（要求 ≥ 8.3）

[扩展]
  ✓ pdo
  ✓ json
  ✓ mbstring
  ✓ fileinfo
  ✓ openssl
  ✓ pdo_sqlite

[目录]
  ✓ storage/
  ✓ storage/logs/ 可写
  ✓ storage/cache/ 可写

[环境变量]
  ✓ APP_KEY 已设置
  ✓ APP_ENV=local

结果：0 个错误，0 个提示
环境检查通过
```

**"提示"和"错误"不同**：错误必须修（比如目录不可写），提示只是建议（比如 `APP_DEBUG=true` 在生产环境不合适）。

## 配置

### `.env` 文件

`sr new` 会自动生成 `.env` 和一个随机的 `APP_KEY`。如果没有：

```bash
php bin/sr key:generate
```

这个命令会写入 `APP_KEY`。**生产环境更换 `APP_KEY` 会导致已加密的数据无法解密**，换之前要想清楚。

### 最小配置

```ini
APP_NAME=myapp
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:...            # sr key:generate 生成

# 数据库
DB_DRIVER=sqlite              # sqlite / mysql / pgsql
DB_DATABASE=storage/database.sqlite
```

生产环境换成 MySQL 或 PostgreSQL：

```ini
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=root
DB_PASSWORD=secret
```

### 配置的加载顺序

```text
.env 中的环境变量
  ↓ 覆盖
config/*.php 中 env('XXX', '默认值') 的默认值
```

所以 `config/database.php` 里写 `env('DB_DRIVER', 'sqlite')`，`.env` 里没有 `DB_DRIVER` 时就用 `sqlite`。

## 建表并启动

```bash
php bin/sr migrate       # 执行迁移
php bin/sr serve         # 启动开发服务器
```

```bash
curl http://127.0.0.1:8000/health
```

应该返回：

```json
{
  "code": 0,
  "message": "success",
  "data": { "status": "healthy" },
  "request_id": "01a18f94-...",
  "timestamp": 1793380598
}
```

::: tip 关于响应格式
所有响应都是这个结构：

```json
{ "code": 0, "message": "success", "data": ..., "request_id": "...", "timestamp": ... }
```

`code` 是**业务码**（0 表示成功），和 HTTP 状态码分开。
这样前端可以用一套逻辑处理所有成功响应，不必关心 HTTP 状态。
:::

## 目录结构

`sr new` 生成两类结构，取决于有没有 `--domains`。

### 单域（`php bin/sr new myapp`）

```text
myapp/
├── .env                      环境变量（已生成随机 APP_KEY）
├── .env.example
├── .gitignore
├── app/
│   ├── Console/
│   │   └── Schedule.php      定时任务定义
│   └── Controller/
│       └── HomeController.php
├── bin/sr                    转发到框架的命令行入口
├── config/                   15 个配置文件
│   ├── api.php               域、中间件别名
│   ├── app.php
│   ├── auth.php
│   ├── cache.php
│   ├── cors.php
│   ├── database.php
│   ├── http.php              请求体大小上限
│   ├── image.php
│   ├── log.php
│   ├── mail.php
│   ├── pagination.php        分页上限
│   ├── queue.php
│   ├── ratelimit.php
│   ├── upload.php
│   └── validation.php
├── database/
│   └── migrations/           迁移文件（预置 create_jobs_table）
├── public/
│   └── index.php             唯一入口（Nginx 的 root 指向这里）
├── routes/
│   └── api.php               单域模式的路由文件
└── storage/
    ├── cache/                文件缓存、配置缓存
    ├── compiled/             路由缓存
    ├── logs/
    └── uploads/
```

### 多域（`php bin/sr new myapp --domains=admin,web`）

只变了 `app/` 和 `routes/`：

```text
myapp/
├── app/
│   ├── Api/
│   │   ├── Admin/
│   │   │   └── Controller/
│   │   │       └── HomeController.php
│   │   └── Web/
│   │       └── Controller/
│   │           └── HomeController.php
│   └── Console/
│       └── Schedule.php
├── routes/
│   ├── admin.php
│   └── web.php
└── （其余同单域）
```

`app/Controller/` 会被删除，`routes/api.php` 也不生成。

::: warning 骨架不预置的目录
下面这些**都需要你自己建**（框架不要求，按需分层）：

| 目录 | 什么时候建 |
| --- | --- |
| `app/Domain/` | 业务规则要多处复用、或需要事务边界时，见[领域服务](./domain-service) |
| `app/Http/Middleware/` | 写自定义中间件时 |
| `app/Api/{域}/Request/` | 用 `make:request` 时自动建 |
| `app/Jobs/` | 写队列任务时 |
| `app/Provider/` | 需要注册单例依赖时 |
| `database/seeders/` | 需要种子数据时 |

**骨架只给最小可运行结构**——没有这些目录不是缺陷，是避免一开始就塞一堆空目录。
:::

### 三层职责

| 层 | 该写什么 | 不该写什么 |
| --- | --- | --- |
| `Api/*/Controller` | 参数转换、调用领域服务、返回结果 | 业务规则、SQL |
| `Domain/*/Service` | 业务规则、事务边界、数据一致性 | HTTP 相关（`Request` / `Response`） |
| `Provider` | 注册依赖、绑定接口到实现 | 任何业务逻辑 |

这个划分的用意：**领域服务不认识 HTTP**。同一个 `PostService` 可以被 API、CLI 命令、队列任务共用，不需要为每一处都写一遍。

## 常见问题

### `env:check` 报目录不可写

```bash
chmod -R 775 storage/
```

如果是 Docker / 容器环境，还要确认运行用户与目录所有者一致。

### 访问接口返回 500，但没有错误信息

`APP_DEBUG=false` 时生产环境不返回堆栈。查日志：

```bash
tail -f storage/logs/app-$(date +%Y-%m-%d).log
```

每条响应都带 `request_id`，可以和日志里的同名字段对上。

### 改了代码不生效

PHP-FPM 的 opcache 会缓存已编译的文件。reload 一下：

```bash
killall -USR2 php-fpm      # macOS / Linux
# 或
brew services restart php
```

::: tip 用 `sr serve` 时不需要
内置开发服务器每次请求都重新加载文件，改完刷新即可见。
:::

### `sr` 命令报「未知命令」

```bash
php bin/sr help      # 看完整命令列表
```

如果提示「找不到框架」，检查 `bin/sr` 里的 `SR_BASE_PATH` 是否指向正确的项目根。

## 下一步

- **[第一个接口 →](./first-api)** —— 从路由到返回 JSON 的完整流程
- **[它解决什么问题 →](./introduction)** —— 设计取舍与边界
