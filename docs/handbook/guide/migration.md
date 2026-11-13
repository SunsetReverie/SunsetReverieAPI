# 迁移

迁移是**用代码描述表结构**，让"建表"这件事可版本化、可回滚、可在多台机器上复现。

文件放在 `database/migrations/`，命名格式 `YYYY_MM_DD_HHMMSS_描述.php`。

## 创建迁移

```bash
php bin/sr make:migration create_posts_table
```

生成：

```php
<?php

declare(strict_types=1);

use SunsetReverie\Database\Migration\Migration;
use SunsetReverie\Database\Schema\Blueprint;
use SunsetReverie\Database\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('posts');
    }
};
```

::: tip 不需要配置数据库
`make:migration` 只生成文件，不连库。新项目还没配 `.env` 时也能用。

（早期版本这里会报「未找到数据库连接配置」，v0.42 修了。）
:::

## 标识符加引号

表名、列名、索引名在生成 SQL 时**自动按驱动加引号**：

| 驱动 | 引号 |
| --- | --- |
| MySQL | 反引号 |
| PostgreSQL / SQLite | 双引号（SQL 标准） |

```php
$schema->create('settings', static function (Blueprint $table): void {
    $table->id();
    $table->string('key', 64)->unique();
    $table->string('group', 32)->index();
    $table->integer('order')->default(0);
});
```

生成：

```sql
-- mysql
CREATE TABLE `settings` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(64) NOT NULL, `group` VARCHAR(32) NOT NULL,
  `order` INT NOT NULL DEFAULT 0, UNIQUE (`key`))
CREATE INDEX `settings_group_index` ON `settings` (`group`)

-- pgsql
CREATE TABLE "settings" ("id" BIGSERIAL PRIMARY KEY,
  "key" VARCHAR(64) NOT NULL, "group" VARCHAR(32) NOT NULL,
  "order" INTEGER NOT NULL DEFAULT 0, UNIQUE ("key"))
```

所以**列名可以是 SQL 保留字**（`key` / `group` / `order` / `before` …），不需要改名。

DML 侧同样加了引号：

```php
DB::table('settings')->where('key', 'site_name')->first();
// → SELECT * FROM "settings" WHERE "key" = ?
```

::: tip 只修 DDL 不修 DML 更危险
`beta.2` 及之前两侧都不加引号，列名用保留字会**建表就失败**——立刻发现。

如果只修 DDL，就变成**建表成功、插入才失败**：

```text
near "group": syntax error   ← 报错发生在 INSERT，很容易误判成 SQL 写错了
```

所以两侧必须一起处理。`beta.2.1` 起都已加引号。
:::

::: warning `selectRaw()` 里的标识符要自己加
```php
// ❌ selectRaw 是原始表达式，框架不解析里面的内容
->selectRaw('group, COUNT(*) as c')

// ✅ 自己按驱动加引号；或者用 select() + groupBy()
->selectRaw('"group", COUNT(*) as c')->groupBy('group')
```

`selectRaw()` / `whereRaw()` 这类方法接收的是**原样拼进 SQL 的表达式**，框架不做任何改写。
:::

## 列类型

16 个方法：

| 方法 | 参数 | MySQL 类型 | 说明 |
| --- | --- | --- | --- |
| `id()` | — | BIGINT AUTO_INCREMENT | 自增主键 |
| `string($name, $length = 255)` | 列名、长度 | VARCHAR | |
| `text($name)` | 列名 | **TEXT（64KB）** | 短文本 |
| `mediumText($name)` | 列名 | **MEDIUMTEXT（16MB）** | 文章正文、邮件模板 |
| `longText($name)` | 列名 | **LONGTEXT（4GB）** | 超大文本 |
| `integer($name)` | 列名 | INT | |
| `bigInteger($name)` | 列名 | BIGINT | |
| `smallInteger($name)` | 列名 | SMALLINT | 状态位、评分 |
| `unsignedInteger($name)` | 列名 | INT UNSIGNED | 计数类 |
| `unsignedBigInteger($name)` | 列名 | BIGINT UNSIGNED | 外部 ID、雪花 ID |
| `boolean($name)` | 列名 | TINYINT(1) | 三库映射不同（见下） |
| `json($name)` | 列名 | JSON | PG `JSONB` / SQLite `TEXT` |
| `timestamp($name)` | 列名 | DATETIME | |
| `date($name)` | 列名 | DATE | |
| `decimal($name, $precision = 10, $scale = 2)` | 精度、小数位 | DECIMAL | 金额用它 |
| `timestamps()` | — | — | 一次加 `created_at` + `updated_at` |

::: warning `text()` 在 MySQL 只有 64KB
`TEXT` 的上限是 65535 字节，中文按 UTF-8 约 3 字节/字——**两万字左右就满了**。

超长时 MySQL 严格模式直接报错：

```text
Data too long for column 'content' at row 1
```

文章正文、富文本、邮件模板这类字段用 `mediumText()`（16MB）。
PostgreSQL / SQLite 的 `TEXT` 本身没有长度上限，三者都会映射成 `TEXT`，不需要区分。
:::

::: tip 无符号只在 MySQL 生效
`unsignedInteger()` / `unsignedBigInteger()` 在 PostgreSQL / SQLite 上**退化成普通整数**——
那两个数据库本来就没有无符号类型。

需要「不允许负数」的强约束时，得在应用层校验，或者用原生 SQL 加 `CHECK` 约束。
:::

### 三库的类型映射

`boolean` 和 `json` 在三种数据库上完全不同，框架自动处理：

| Blueprint | MySQL | PostgreSQL | SQLite |
| --- | --- | --- | --- |
| `boolean()` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` |
| `json()` | `JSON` | `JSONB` | `TEXT` |
| `id()` | `BIGINT AUTO_INCREMENT` | `BIGSERIAL` | `INTEGER AUTOINCREMENT` |
| `mediumText()` / `longText()` | `MEDIUMTEXT` / `LONGTEXT` | `TEXT` | `TEXT` |
| `smallInteger()` | `SMALLINT` | `SMALLINT` | `INTEGER` |
| `unsignedInteger()` | `INT UNSIGNED` | `INTEGER` | `INTEGER` |
| `unsignedBigInteger()` | `BIGINT UNSIGNED` | `BIGINT` | `INTEGER` |

详见[多数据库](./multi-database)。

## 修饰符

```php
$table->string('title', 200);                    // NOT NULL
$table->string('summary', 500)->nullable();      // 允许 NULL
$table->integer('status')->default(0);           // 默认值
$table->decimal('price', 10, 2)->default('0.00');
$table->timestamp('published_at')->nullable();
```

`nullable()` 和 `default()` 都作用于**最近定义的那一列**（链式）。

::: warning MySQL 的 JSON 列不能有非 NULL 默认值
```php
$table->json('snapshot')->default('{}');    // ❌ MySQL 报错
$table->json('snapshot')->nullable();       // ✅
```

所以 `json()` 通常配 `nullable()`。
:::

## 索引

### `unique()` 有两种用法

**链式**（不传参，修饰最近那列）：

```php
$table->string('slug', 200)->unique();
// → UNIQUE KEY posts_slug_unique (slug)
```

**表级**（传列名）：

```php
$table->unique('slug');                    // 单列
$table->unique(['post_id', 'user_id']);    // 复合
$table->unique('slug', 'custom_name');     // 自定义索引名
```

::: warning 一个历史坑（0.36.1 修了）
`unique()` 的早期签名是 `unique(): self`——**不接受参数**。

而 PHP 会静默忽略多余参数，所以：

```php
$table->timestamps();      // created_at, updated_at
$table->unique('sku');     // 参数被忽略！唯一索引加到了 updated_at 上
```

后果：迁移成功，插第一条正常，**插第二条报 `Duplicate entry '2026-10-30 07:59:25' for key 'updated_at'`**。

报错完全指不到真正原因。现在传列名是安全的，写错列名会直接抛：

```text
索引引用了不存在的列 [nope]（表 t3）
```
:::

### `index()` 和 `unique()`

```php
$table->index('category_id');              // 按列名
$table->index(['status', 'published_at']); // 复合索引
$table->string('domain', 120)->index();    // 链式：修饰最近那一列
```

`index()` 和 `unique()` **现在完全对称**，两种写法都支持。

::: warning 曾经不对称（`beta.2.1` 修正）
`index()` 早期的签名是 `index(array|string $columns)`——**不接受 null**，而兄弟方法
`unique()` 两种用法都有。使用者会自然假设两者一致：

```php
$table->string('domain', 120)->index();
// Too few arguments to function Blueprint::index(), 0 passed and at least 1 expected
```

报错虽然明确，但第一次写迁移必踩一次。现已对齐。
:::

`uniqueIndex()` 是另一回事——它**只接受列名**：

```php
$table->uniqueIndex(['post_id', 'version']);   // ✅
$table->uniqueIndex();                         // ❌ 参数必填
```

名字里带 `Index`，语义就是「按列建一个索引」，不修饰最近列。想链式就用 `unique()`。

### 索引命名

不指定名字时自动生成：

```text
{表名}_{列1}_{列2}_unique      唯一索引
{表名}_{列1}_{列2}_index       普通索引
```

超过数据库的长度限制时（MySQL 64 字符）会被截断，建议长列名时显式指定。

## 执行

```bash
php bin/sr migrate              # 执行未运行的迁移
php bin/sr migrate:status       # 看哪些跑了、哪些没跑
php bin/sr migrate:rollback     # 回滚最近一批
php bin/sr migrate:rollback --step=2   # 回滚最近 2 批
php bin/sr migrate:fresh --force       # 删全部表重建（开发用，会丢数据）
```

### 回滚必须写 `down()`

```php
public function down(Schema $schema): void
{
    $schema->dropIfExists('posts');
}
```

`dropIfExists()` 而不是 `drop()`——回滚时表不存在不应该报错（可能被手工删了）。

**回滚顺序是反的**：最后执行的迁移最先回滚。

## 迁移失败后的行为

**不要假设「失败 = 数据库回到干净状态」。**

### 三条明确的行为

| 项 | 实际 |
| --- | --- |
| 事务 | **不包**。MySQL 的 DDL 会隐式提交，事务对建表无效 |
| 失败处理 | **立即停止**，后面的迁移不再执行 |
| 已成功的迁移 | **记录保留**在 `migrations` 表里，不会自动回滚 |

所以一次失败的 `migrate` 可能留下：

```text
第 1 个迁移  ✅ 已执行，记录在表里
第 2 个迁移  ❌ 执行到一半失败
第 3 个迁移  ⏸ 没开始
```

### 恢复姿势

```bash
# 1. 看清楚停在哪
php bin/sr migrate:status

# 2. 修掉第 2 个迁移里的问题

# 3. 重跑——只会执行剩下的（第 1 个不会重复跑）
php bin/sr migrate
```

**如果第 2 个迁移已经建了部分表**（比如一个文件里建三张表，第二张失败），
需要手工删掉已建的那张，或者干脆：

```bash
php bin/sr migrate:fresh --force     # ⚠️ 删全部表重建，只在开发/测试库用
```

### 定位是哪条语句失败

| 错误类型 | 报错里有什么 |
| --- | --- |
| SQL 层（语法错误、字段冲突） | **完整的 SQL 语句**（`QueryException` 自带） |
| PHP 层（参数不对、方法不存在） | 只有错误信息 + 一条定位提示 |

SQL 层的例子：

```text
迁移 2026_11_02_000002_create_sites_table.php 执行 up 失败：
SQL 执行失败：SQLSTATE[42000]: Syntax error ...（CREATE TABLE `settings` (`key` VARCHAR(64))）
                                                                            ↑ 就是这句
```

PHP 层没有 SQL 可打印（还没走到执行那一步），所以给了提示：

```text
定位方式：在迁移里用 Blueprint::toSql($driver) 打印生成的语句，或把该迁移拆成多次单表操作以缩小范围。
```

手动打印：

```php
public function up(Schema $schema): void
{
    $blueprint = new Blueprint('settings');
    $blueprint->string('key', 64);

    var_dump($blueprint->toSql('mysql'));   // ← 临时加，看完删掉
}
```

::: tip 一个迁移只管一张表
这是最简单也最有效的定位手段——失败时能直接看出是哪张表。
一个文件建五张表看起来省事，出错时得挨个试。
:::

## 一个完整的例子

```php
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('post_versions', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('post_id');
            $table->integer('version')->default(1);
            // 快照用 JSON：以后加字段不用改表结构
            $table->json('snapshot')->nullable();
            $table->bigInteger('editor_id')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            // 同一文章的同版本号唯一——防重复写入
            $table->uniqueIndex(['post_id', 'version']);
            // 按文章查列表用这个
            $table->index('post_id');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('post_versions');
    }
};
```

## 常见错误

### 迁移成功但表结构不对

`unique('col')` 加错位置的经典坑，见上文。用 `db:show` 或直接看数据库确认：

```sql
SHOW INDEX FROM your_table WHERE Non_unique = 0;
```

### `Duplicate entry` 但数据确实不重复

唯一索引加在了意料之外的列上。同上，查 `SHOW INDEX`。

### `migrate:fresh` 报需要 `--force`

```bash
php bin/sr migrate:fresh --force
```

这是保护：它会删掉所有表。

### 改了迁移文件但没生效

迁移**跑过就记在 `migrations` 表里**，改了文件不会重跑。

```bash
php bin/sr migrate:rollback    # 回滚
php bin/sr migrate             # 再跑
```

或者 `migrate:fresh --force`（会丢数据）。

### 三库里有一张表建不出来

大概率是方言问题。用 `Blueprint::toSql($driver)` 打印三种驱动的 SQL 对比：

```php
$b = new Blueprint('t');
$b->id();
$b->json('data')->nullable();

foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
    echo "[$driver]\n";
    foreach ($b->toSql($driver) as $sql) {
        echo "  $sql\n";
    }
}
```

## 下一步

- **[事务](./transaction)** —— 迁移之外的事务用法
- **[多数据库](./multi-database)** —— 三库方言差异全表
- **[数据库](./database)** —— Query Builder
