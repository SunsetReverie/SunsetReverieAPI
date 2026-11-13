# 数据库

没有 ORM。写什么就是什么 SQL——这是刻意的，见[介绍](./introduction)。

```php
use SunsetReverie\Support\Facades\DB;
```

`DB` 是**静态门面**，所有方法转发到默认连接。不需要注入 `Connection`，
也不需要在 Provider 里注册——直接调就行。

### 两种写法都能用

门面是推荐写法，但**构造函数注入 `Connection` 也能正常工作**（容器已绑定它）：

```php
// ✅ 推荐：门面
final class PostService
{
    public function count(): int
    {
        return DB::table('posts')->count();
    }
}

// ✅ 也可以用：注入
final class PostService
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function count(): int
    {
        return $this->db->table('posts')->count();
    }
}
```

**两者指向同一个连接实例**，行为完全一致。

### 什么时候该用注入

| 场景 | 用哪个 |
| --- | --- |
| 普通业务查询 | 门面（代码短，少一行构造函数） |
| **测试时要替换连接** | 注入（能注入 mock 或内存库） |
| 多库项目，一个服务要操作**非默认库** | 注入（传别的 Connection 实例） |
| 需要在事务回调里保证用同一个连接 | 两者都行（门面在事务内也指向同一个） |

::: tip 为什么文档统一用门面
不是「注入不好」，是**一致性**——同一份文档里两种写法混着出现，
读者会反复确认「这里为什么不一样」。

新代码建议门面；已有代码用注入也不用改，能跑就不用动。
:::

## 三种取数方式

### 1. Query Builder（绝大多数场景）

```php
$posts = DB::table('posts')
    ->select('id', 'title', 'published_at')
    ->where('status', 1)
    ->orderBy('published_at', 'desc')
    ->limit(20)
    ->get();
```

### 2. 原生 SQL（复杂查询）

```php
$rows = DB::select(
    'SELECT p.id, p.title, COUNT(c.id) AS comment_count
       FROM posts p
       LEFT JOIN comments c ON c.post_id = p.id
      WHERE p.status = ?
      GROUP BY p.id, p.title
      HAVING COUNT(c.id) > ?',
    [1, 5]
);
```

Binding 永远是**位置参数**（`?`），不用命名占位符。

### 3. 单个标量

```php
$count = DB::scalar('SELECT COUNT(*) FROM posts WHERE status = ?', [1]);
$name = DB::scalar('SELECT name FROM users WHERE id = ?', [$id]);
```

没查到返回 `null`。

## 按主键取一条

两个方法，区别只在「查不到怎么办」：

```php
$post = DB::table('posts')->find($id);        // ?array，查不到返回 null
$post = DB::table('posts')->findOrFail($id);  // array，查不到抛 404
```

| 方法 | 查不到时 | 适合 |
| --- | --- | --- |
| `find($id, $primaryKey = 'id')` | 返回 `null` | 调用方要自己处理「不存在」 |
| `findOrFail($id, $primaryKey = 'id')` | 抛 `HttpException`（404 + 错误码 `40001`） | 不存在就该 404 的接口 |

```php
// 展示页：不存在时给自定义文案
$post = DB::table('posts')->find($id);

if ($post === null) {
    throw new PostException('文章不存在或已删除', 404);
}

// 管理接口：422/404 由框架统一出
$post = DB::table('posts')->findOrFail($id);
```

主键列名不是 `id` 时传第二个参数：

```php
DB::table('users')->find($uuid, 'uuid');
```

::: tip 为什么有 `find` 还需要 `findOrFail`
不是重复。`findOrFail` 把「不存在」当成错误直接抛 404，
而很多场景下「查不到」是正常分支（比如「你已经收藏过吗」），
用 try/catch 反而绕。
:::

## 查询条件

### `where` 只认 9 个运算符

```php
DB::table('t')->where('id', 5);                   // 两参：id = 5
DB::table('t')->where('id', '>', 5);             // 三参：id > 5
DB::table('t')->where('title', 'LIKE', '%php%');
```

可用运算符：`=` `!=` `<>` `<` `>` `<=` `>=` `like` `not like`

**不在这 9 个里的会直接抛异常**：

```text
不支持的运算符 [in]，可用：= != <> < > <= >= like not like
```

所以下面这些要用专门的方法：

| 想写 | 用什么 |
| --- | --- |
| `IN (...)` | `whereIn($col, $array)` |
| `IS NULL` | `whereNull($col)` |
| `IS NOT NULL` | `whereNotNull($col)` |
| `BETWEEN` | `whereBetween($col, [$min, $max])` |
| `(a OR b)` | `whereGroup($closure)` |

::: tip 为什么只支持 9 个
运算符是**直接拼进 SQL** 的（`sprintf('%s %s ?', $column, $operator)`），
所以必须白名单。`in` 之类需要生成不同结构，硬塞进这个模板会出错。
:::

### 条件家族

```php
->where('status', 1)
->orWhere('is_featured', 1)
->whereIn('category_id', [1, 2, 3])
->whereNull('deleted_at')
->whereNotNull('published_at')
->whereBetween('created_at', ['2026-01-01', '2026-12-31'])
```

`whereBetween` 接受**一个数组**，不是两个参数：

```php
->whereBetween('created_at', [$start, $end])     // ✅
->whereBetween('created_at', $start, $end)       // ❌ 参数数量错
```

### `when`：条件拼接

```php
DB::table('posts')
    ->when($keyword !== '', fn ($q) => $q->where('title', 'LIKE', "%{$keyword}%"))
    ->when($categoryId > 0, fn ($q) => $q->where('category_id', $categoryId))
    ->get();
```

**只有两个参数**（条件和回调），**没有 else 分支**。要 else 就写两次 `when`：

```php
->when($a, fn ($q) => $q->where('x', 1))
->when(!$a, fn ($q) => $q->where('y', 2))
```

## `select` 的三个坑

### 坑 1：`select('*')` 会抛异常

```php
->select('*')      // ❌ 非法标识符 [*]：只允许字母、数字、下划线与单个点（防注入）
```

**`*` 是默认值，不需要显式写**：

```php
->select()         // ✅ SELECT *
->select('id')     // ✅ SELECT id
```

### 坑 2：`select()` 是替换不是追加

```php
->select('a')->select('b')     // → SELECT b（a 被替换掉）
```

要多个列一次传完：

```php
->select('a', 'b', 'c')
```

### 坑 3：`selectRaw` 会清掉默认的 `*`

这是 v0.41 的修复。之前：

```sql
SELECT *, COUNT(*) AS n FROM t GROUP BY x
```

MySQL 在 `ONLY_FULL_GROUP_BY` 关闭时容忍，**PostgreSQL 直接报错**：

```text
column "t.id" must appear in the GROUP BY clause
```

现在 `selectRaw()` 首次追加时会清掉默认通配符。行为：

```php
->selectRaw('COUNT(*) AS n')->groupBy('x')
// → SELECT COUNT(*) AS n FROM t GROUP BY x    ✅
```

**如果要 `*` 加聚合**，显式写：

```php
->selectRaw('*', 'COUNT(*) AS n')
// 但 PG 仍会拒绝，这是方言限制，不是框架问题
```

## 聚合

```php
DB::table('posts')->count();                      // int
DB::table('posts')->sum('view_count');            // int|float
DB::table('posts')->avg('view_count');            // int|float|null
DB::table('posts')->max('view_count');            // int|float|null
DB::table('posts')->min('view_count');            // int|float|null
DB::table('posts')->exists();                     // bool
```

**返回类型是 `int|float`，不是 `string`**。

MySQL 的 `SUM()` 通过 PDO 取回来是字符串，框架做了转换。如果你的代码里还留着 `(int)` 强转，不会出错但也没必要了。

::: warning DECIMAL 列不转换
`DECIMAL(10,2)` 取回来**仍是字符串**，这是有意的——转 float 会丢精度。

```php
$order['total_amount']   // "19.99"（string）
```

金额计算走整数「分」，见[领域服务](./domain-service)。
:::

## 分页

### 用 `paginate()`

```php
$result = DB::table('posts')
    ->where('status', 1)
    ->paginate(20, $page);

// [
//   'items' => [...],
//   'total' => 42,
//   'page' => 1,
//   'per_page' => 20,
//   'last_page' => 3,
// ]
```

签名：`paginate(int $perPage = 15, int $page = 1)`

::: warning perPage 上限 100
`paginate()` 内部会夹到 100，但**控制器里还是应该用 `$request->perPage()`**——
它读 `config/pagination.max_per_page`（默认 50），和全站配置一致。
:::

### 手动分页（需要算分排序时）

```php
$total = DB::table('posts')->where('status', 1)->count();

$rows = DB::table('posts')
    ->where('status', 1)
    ->orderBy('score', 'desc')
    ->limit($perPage)
    ->offset(($page - 1) * $perPage)
    ->get();
```

`count()` 不改构造器状态，但 `select` / `orderBy` 只作用于列表查询时，还是分开写两个构造器更清楚。

## `whereGroup`：最重要的坑

```php
// ❌ 错的
->where('status', 1)
->where('title', 'LIKE', $kw)
->orWhere('content', 'LIKE', $kw)

// 生成的 SQL
WHERE status = 1 AND title LIKE ? OR content LIKE ?
```

因为 `AND` 优先级高于 `OR`，实际等价于：

```sql
WHERE (status = 1 AND title LIKE ?) OR content LIKE ?
```

**草稿只要正文命中就会被搜出来。**

正确写法：

```php
$filter = static function ($q) use ($keywords): void {
    $first = true;

    foreach ($keywords as $kw) {
        $first ? $q->where('title', 'LIKE', $kw) : $q->orWhere('title', 'LIKE', $kw);
        $first = false;
    }
};

DB::table('posts')
    ->where('status', 1)
    ->whereGroup($filter)          // → WHERE status = 1 AND (title LIKE ? OR ...)
```

这是 xi-blog 搜索模块的真实代码结构（`PostService::search`）。

## 写操作

### `insert` / `insertMany` / `insertGetId`

```php
$id = DB::table('posts')->insertGetId([...]);      // 返回 int
DB::table('posts')->insert([...]);                  // 返回 bool
DB::table('notifications')->insertMany([...]);      // 返回 int（行数）
```

**`insertGetId()` 返回 `int`**（v0.15 起的约定），不是字符串。

`insertMany()` 在真实数据库上是**数量级**的差异：

| 数据库 | 1000 条循环 `insert` | `insertMany` | 提速 |
| --- | --- | --- | --- |
| SQLite（内存） | 3.6 ms | 0.9 ms | 3.9× |
| MySQL 8.0 | 141 ms | 4.5 ms | 31–40× |
| PostgreSQL 18 | 116 ms | 3.1 ms | 36.9× |

数据来自框架仓库的基准测试脚本 （三库横向对比，同一台机器）。

### `update` / `delete`

```php
$affected = DB::table('posts')->where('id', $id)->update(['title' => '新标题']);
$deleted = DB::table('posts')->where('id', $id)->delete();
```

**都返回影响行数 `int`**。`0` 说明没匹配到行——不要当成失败，也别当成成功。

### 原子自增/自减

```php
DB::table('posts')->where('id', $id)->increment('like_count');
DB::table('posts')->where('id', $id)->decrement('stock', 2);
```

生成的是 `UPDATE ... SET like_count = like_count + 1`——**数据库端计算**，不是「先读再写」。

并发扣库存的标准写法：

```php
$affected = DB::table('products')
    ->where('id', $id)
    ->where('stock', '>=', $qty)
    ->decrement('stock', $qty);

if ($affected === 0) {
    throw new OrderException('库存不足');
}
```

x-blog 里 10 并发抢最后 1 件，只有 1 个成功——靠的就是这个。

## 调试

```php
$query = DB::table('posts')->where('status', 1)->limit(10);

echo $query->toSql();          // SELECT * FROM posts WHERE status = ?
print_r($query->getBindings()); // [1]
```

::: warning `toSql()` 不改状态
它可以反复调用。但**不要把 `toSql()` 的结果直接拿去执行**——
里面有 `?` 占位符，得配合 `getBindings()`。
:::

## 常见错误

### `非法标识符 [*]`

`select('*')` 被拒。删掉这个调用，`*` 是默认值。

### `不支持的运算符 [in]`

用 `whereIn()`，不要 `where('id', 'in', [...])`。

### 查询结果为空但数据存在

三个常见原因：

1. `whereGroup` 没加，`OR` 条件被 `AND` 吃掉
2. 列名拼错（框架不校验列是否存在，交给数据库报错）
3. `DECIMAL` 和字符串比较（`where('amount', '19.99')` 在 PG 上可能不匹配）

用 `toSql()` + `getBindings()` 拼出完整 SQL，直接在数据库里跑一遍。

### `insertGetId()` 拿到 0

- 表没有自增主键
- 或者用了 `insert()` 而不是 `insertGetId()`

### 分页数字对不上

`total` 和 `items` 用了两个不同的构造器时，检查 `where` 条件是否一致。

## 下一步

- **[迁移](./migration)** —— 建表和索引
- **[事务](./transaction)** —— 嵌套、保存点、并发
- **[多数据库](./multi-database)** —— 三库方言差异
