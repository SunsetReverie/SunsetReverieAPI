<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Query;

use Closure;
use InvalidArgumentException;
use SunsetReverie\Database\Connection;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Database\Exception\QueryException;

/**
 * 查询构造器（v0.3 范围：单表 CRUD）。
 *
 * 安全设计：
 * - 所有**值**一律参数绑定；
 * - 所有**标识符**（表名 / 字段 / 排序方向）走白名单校验，非法立即抛异常；
 * - `update()` / `delete()` 无 where 条件时直接拒绝，避免误伤全表。
 *
 * 暂不提供：join、子查询、union、批量插入（按需再加，避免过度设计）。
 */
final class Builder
{
    /** @var list<string> 查询字段 */
    private array $columns = ['*'];

    /** @var list<array{sql: string, bindings: list<mixed>, boolean: string}> where 片段 */
    private array $wheres = [];

    /** @var list<string> 分组列 */
    private array $groups = [];

    /** @var list<mixed> SELECT 子句里的绑定（selectRaw 的 ? 参数） */
    private array $selectBindings = [];

    /** @var list<array{column: string, direction: string}> 排序 */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /**
     * @param Connection $connection 连接
     * @param string $table 表名
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
        $this->assertIdentifier($table);
    }

    /**
     * 指定查询字段。
     *
     * @param string ...$columns 字段名
     * @return self 当前构造器
     */
    public function select(string ...$columns): self
    {
        if ($columns !== []) {
            foreach ($columns as $column) {
                $this->assertIdentifier($column);
            }

            $this->columns = array_map($this->q(...), array_values($columns));
        }

        return $this;
    }

    /**
     * 添加 where 条件。
     *
     * @param string $column 字段
     * @param mixed $operator 运算符（两参数调用时作为值）
     * @param mixed $value 值
     * @return self 当前构造器
     * @throws InvalidArgumentException 运算符不支持时抛出
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        $argumentCount = func_num_args();

        if ($argumentCount === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = strtolower(trim((string) $operator));

        if (!in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like'], true)) {
            throw new InvalidArgumentException(sprintf(
                '不支持的运算符 [%s]，可用：= != <> < > <= >= like not like',
                $operator
            ));
        }

        $this->assertIdentifier($column);
        $this->wheres[] = [
            'sql' => sprintf('%s %s ?', $this->q($column), $operator),
            'bindings' => [$value],
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * 添加 or where 条件。
     *
     * @param string $column 字段
     * @param mixed $operator 运算符
     * @param mixed $value 值
     * @return self 当前构造器
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        $before = count($this->wheres);
        $this->where($column, $operator, $value);

        if (count($this->wheres) > $before) {
            $this->wheres[$before]['boolean'] = 'or';
        }

        return $this;
    }

    /**
     * where in 条件。
     *
     * @param string $column 字段
     * @param list<mixed> $values 值列表
     * @return self 当前构造器
     * @throws InvalidArgumentException 列表为空时抛出
     */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('whereIn 的值列表不能为空');
        }

        $this->assertIdentifier($column);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = [
            'sql' => sprintf('%s IN (%s)', $this->q($column), $placeholders),
            'bindings' => array_values($values),
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * where null 条件。
     *
     * @param string $column 字段
     * @return self 当前构造器
     */
    public function whereNull(string $column): self
    {
        $this->assertIdentifier($column);
        $this->wheres[] = ['sql' => sprintf('%s IS NULL', $this->q($column)), 'bindings' => [], 'boolean' => 'and'];

        return $this;
    }

    /**
     * 排序。
     *
     * @param string $column 字段
     * @param string $direction 方向（asc / desc）
     * @return self 当前构造器
     * @throws InvalidArgumentException 方向非法时抛出
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->assertIdentifier($column);
        $direction = strtolower($direction);

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(sprintf('排序方向只能是 asc 或 desc，收到 [%s]', $direction));
        }

        $this->orders[] = ['column' => $this->q($column), 'direction' => strtoupper($direction)];

        return $this;
    }

    /**
     * 限制条数。
     *
     * @param int $value 条数
     * @return self 当前构造器
     * @throws InvalidArgumentException 负数时抛出
     */
    public function limit(int $value): self
    {
        if ($value < 0) {
            throw new InvalidArgumentException('limit 不能为负数');
        }

        $this->limit = $value;

        return $this;
    }

    /**
     * 跳过条数（需配合 limit）。
     *
     * @param int $value 偏移量
     * @return self 当前构造器
     * @throws InvalidArgumentException 负数时抛出
     */
    public function offset(int $value): self
    {
        if ($value < 0) {
            throw new InvalidArgumentException('offset 不能为负数');
        }

        $this->offset = $value;

        return $this;
    }

    /**
     * 执行查询。
     *
     * @return list<array<string, mixed>> 结果集
     * @throws QueryException 执行失败时抛出
     */
    public function get(): array
    {
        return $this->connection->select($this->toSelectSql(), $this->bindings());
    }

    /**
     * 获取首行。
     *
     * @return array<string, mixed>|null 首行或 null
     * @throws QueryException 执行失败时抛出
     */
    public function first(): ?array
    {
        $this->limit(1);

        return $this->connection->selectOne($this->toSelectSql(), $this->bindings());
    }

    /**
     * 获取单个字段值。
     *
     * @param string $column 字段名
     * @return mixed 字段值，无结果返回 null
     * @throws QueryException 执行失败时抛出
     */
    public function value(string $column): mixed
    {
        $this->assertIdentifier($column);
        $this->columns = [$this->q($column)];
        $this->limit(1);

        return $this->connection->scalar($this->toSelectSql(), $this->bindings());
    }

    /**
     * 统计行数。
     *
     * @return int 行数
     * @throws QueryException 执行失败时抛出
     */
    public function count(): int
    {
        $sql = sprintf('SELECT COUNT(*) AS aggregate FROM %s%s', $this->table, $this->toWhereSql());

        return (int) $this->connection->scalar($sql, $this->whereBindings());
    }

    /**
     * 分页查询（一次返回数据与分页元信息）。
     *
     * @param int $perPage 每页条数（上限 100）
     * @param int $page 页码（从 1 开始）
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $perPage = min(100, max(1, $perPage));
        $page = max(1, $page);

        $total = $this->count();
        $items = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
        ];
    }

    /**
     * 按主键查一条，查不到返回 null。
     *
     * 与 findOrFail() 对称：后者查不到抛 404，这个静默返回 null，
     * 由调用方决定怎么处理（展示「不存在」或转成别的错误码）。
     *
     * 之前没有这个方法，但文档两处示例（controller.md、cache.md）
     * 都写了 `->find($id)` —— 照文档写必然 500，而「方法不存在」
     * 的错误很容易被误判成自己写错了。
     *
     * @param int|string $id 主键值
     * @param string $primaryKey 主键列名
     * @return array<string, mixed>|null 找到返回行，否则 null
     */
    public function find(int|string $id, string $primaryKey = 'id'): ?array
    {
        return $this->where($primaryKey, $id)->first();
    }

    /**
     * 按主键查一条，查不到抛 404。
     *
     * @param int|string $id 主键值
     * @param string $primaryKey 主键列名
     * @return array<string, mixed> 记录
     * @throws HttpException 记录不存在时抛出
     */
    public function findOrFail(int|string $id, string $primaryKey = 'id'): array
    {
        $row = $this->where($primaryKey, $id)->first();

        if ($row === null) {
            throw new HttpException(404, ErrorCode::NotFound->value, sprintf(
                '记录不存在（%s = %s）',
                $primaryKey,
                (string) $id
            ));
        }

        return $row;
    }

    /**
     * `IS NOT NULL` 条件。
     *
     * RBAC / 订单这类模块常用："已支付"= paid_at 非空，
     * 而 without whereNotNull 只能写成原生 SQL。
     *
     * @param string $column 列名
     * @return self 当前构造器
     */
    public function whereNotNull(string $column): self
    {
        $this->assertIdentifier($column);
        $this->wheres[] = [
            'sql' => sprintf('%s IS NOT NULL', $this->q($column)),
            'bindings' => [],
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * 条件分组（加括号）。
     *
     * 为什么必须有它：SQL 里 AND 的优先级高于 OR。
     *
     *   ->where('status', 1)
     *     ->where('title', 'LIKE', '%x%')
     *     ->orWhere('content', 'LIKE', '%x%')
     *
     * 生成的是 `status = ? AND title LIKE ? OR content LIKE ?`，
     * 等价于 `(status = ? AND title LIKE ?) OR content LIKE ?` ——
     * **未发布的内容只要正文匹配就会被查出来**，这在搜索场景是数据泄露。
     *
     * 用 whereGroup 包起来才对：
     *
     *   ->where('status', 1)
     *     ->whereGroup(fn ($q) => $q->where('title', 'LIKE', '%x%')->orWhere('content', 'LIKE', '%x%'))
     *
     * 生成 `status = ? AND (title LIKE ? OR content LIKE ?)`。
     *
     * @param Closure(self): mixed $callback 分组内的条件（返回值被忽略）
     * @return self 当前构造器
     */
    public function whereGroup(Closure $callback): self
    {
        return $this->addGroup($callback, 'and');
    }

    /**
     * 或条件分组（加括号，用 OR 连接）。
     *
     * @param Closure(self): mixed $callback 分组内的条件（返回值被忽略）
     * @return self 当前构造器
     */
    public function orWhereGroup(Closure $callback): self
    {
        return $this->addGroup($callback, 'or');
    }

    /**
     * 构造一个嵌套条件分组。
     *
     * @param Closure(self): mixed $callback 分组内的条件（返回值被忽略）
     * @param string $boolean and / or
     * @return self 当前构造器
     */
    private function addGroup(Closure $callback, string $boolean): self
    {
        $nested = new self($this->connection, $this->table);
        $callback($nested);

        if ($nested->wheres === []) {
            return $this;
        }

        $this->wheres[] = [
            'sql' => '(' . $nested->toWhereSqlFragment() . ')',
            'bindings' => $nested->bindings(),
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * 条件成立时才应用额外约束。
     *
     * @param bool $condition 条件
     * @param Closure(self): mixed $callback 约束回调（返回值被忽略）
     * @return self 当前构造器
     */
    public function when(bool $condition, Closure $callback): self
    {
        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    /**
     * BETWEEN 条件（闭区间）。
     *
     * @param string $column 字段
     * @param array{0: mixed, 1: mixed} $range 两个边界
     * @return self 当前构造器
     * @throws InvalidArgumentException 边界数量不对时抛出
     */
    public function whereBetween(string $column, array $range): self
    {
        $values = array_values($range);

        if (count($values) !== 2) {
            throw new InvalidArgumentException('whereBetween 需要恰好两个边界值');
        }

        $this->assertIdentifier($column);
        $this->wheres[] = [
            'sql' => sprintf('%s BETWEEN ? AND ?', $this->q($column)),
            'bindings' => [$values[0], $values[1]],
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * 追加原始 SELECT 表达式（用于聚合列）。
     *
     * **只应传入代码里写死的表达式**，不要拼接用户输入——
     * 这个方法跳过标识符校验，是唯一的注入入口。
     *
     * @param string ...$expressions 表达式（如 `COUNT(*) AS total`）
     * @return self 当前构造器
     */
    public function selectRaw(string ...$expressions): self
    {
        $this->dropDefaultWildcard();

        foreach ($expressions as $expression) {
            $this->columns[] = $expression;
        }

        return $this;
    }

    /**
     * 首次追加选择列时清掉默认的通配符。
     *
     * 默认 `$columns = ['*']`，而 selectRaw() 是追加语义，于是：
     *
     *   table('t')->selectRaw('COUNT(*) AS n')->groupBy('x')
     *   → SELECT *, COUNT(*) AS n FROM t GROUP BY x
     *
     * MySQL 在 ONLY_FULL_GROUP_BY 关闭时容忍，**PostgreSQL 直接报**
     * `column "t.id" must appear in the GROUP BY clause`。
     * 这个差异只有真跑 PG 才会暴露（跨库契约测试抓到的第一例）。
     *
     * @return void
     */
    private function dropDefaultWildcard(): void
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
    }

    /**
     * 追加带绑定的 SELECT 表达式。
     *
     * `selectRaw()` 不能带参数，而搜索排序需要：
     *
     *   selectRawWith(
     *       '(CASE WHEN title LIKE ? THEN 100 ELSE 0 END) + (CASE WHEN content LIKE ? THEN 10 ELSE 0 END) AS score',
     *       ['%kw%', '%kw%']
     *   )
     *
     * 绑定值仍走 PDO 占位符，不能拼进 SQL 字符串——那是注入入口。
     *
     * @param string $expression 表达式（含 ? 占位符）
     * @param list<mixed> $bindings 绑定值（数量必须与占位符一致）
     * @return self 当前构造器
     * @throws InvalidArgumentException 占位符与绑定数量不符时抛出
     */
    public function selectRawWith(string $expression, array $bindings = []): self
    {
        $placeholders = substr_count($expression, '?');

        if ($placeholders !== count($bindings)) {
            throw new InvalidArgumentException(sprintf(
                'selectRawWith 的占位符数量（%d）与绑定值数量（%d）不一致',
                $placeholders,
                count($bindings)
            ));
        }

        $this->dropDefaultWildcard();
        $this->columns[] = $expression;
        $this->selectBindings = [...$this->selectBindings, ...array_values($bindings)];

        return $this;
    }

    /**
     * 分组。
     *
     * 注意：`paginate()` 内部的 count 不感知 GROUP BY，
     * 分组查询请用 `get()` 自己处理分页。
     *
     * @param string ...$columns 分组列
     * @return self 当前构造器
     */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->assertIdentifier($column);
            $this->groups[] = $this->q($column);
        }

        return $this;
    }

    /**
     * 按表达式分组（如 `DATE(created_at)`）。
     *
     * 同样**只接受代码里写死的表达式**，不要拼接用户输入。
     *
     * @param string ...$expressions 表达式
     * @return self 当前构造器
     */
    public function groupByRaw(string ...$expressions): self
    {
        foreach ($expressions as $expression) {
            $this->groups[] = $expression;
        }

        return $this;
    }

    /**
     * 求和（终结方法，直接返回结果）。
     *
     * @param string $column 列名
     * @return int|float 合计（无数据时为 0）
     */
    public function sum(string $column): int|float
    {
        $value = $this->aggregate('SUM', $column);

        // PDO 对 SUM/AVG 一律返回字符串（即使列是整数），必须显式转换，
        // 否则声明 int|float 的方法会抛 TypeError
        return $value === null ? 0 : $value + 0;
    }

    /**
     * 求平均（终结方法）。
     *
     * @param string $column 列名
     * @return int|float 均值（无数据时为 0）
     */
    public function avg(string $column): int|float
    {
        $value = $this->aggregate('AVG', $column);

        return $value === null ? 0 : round((float) $value, 2);
    }

    /**
     * 求最大值（终结方法）。
     *
     * @param string $column 列名
     * @return mixed 最大值（无数据时为 null）
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * 求最小值（终结方法）。
     *
     * @param string $column 列名
     * @return mixed 最小值（无数据时为 null）
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * 执行聚合函数。
     *
     * @param string $function 聚合函数名（内部常量）
     * @param string $column 列名
     * @return mixed 结果
     */
    private function aggregate(string $function, string $column): mixed
    {
        $this->assertIdentifier($column);

        $sql = sprintf(
            'SELECT %s(%s) FROM %s%s',
            $function,
            $column,
            $this->table,
            $this->toWhereSql()
        );

        return $this->connection->scalar($sql, $this->bindings());
    }

    /**
     * 原子自增（`SET col = col + ?`）。
     *
     * 为什么必须走 SQL 而不是"先读再写"：并发下读改写会丢更新
     * （两个请求都读到 10，各自写 11，最终 11 而非 12）。
     *
     * @param string $column 列名
     * @param int $step 步长
     * @param array<string, mixed> $extra 同时更新的其他列
     * @return int 受影响行数
     */
    public function increment(string $column, int $step = 1, array $extra = []): int
    {
        return $this->applyDelta($column, $step, $extra);
    }

    /**
     * 原子自减。
     *
     * @param string $column 列名
     * @param int $step 步长
     * @param array<string, mixed> $extra 同时更新的其他列
     * @return int 受影响行数
     */
    public function decrement(string $column, int $step = 1, array $extra = []): int
    {
        return $this->applyDelta($column, -$step, $extra);
    }

    /**
     * 执行 `SET col = col ± ?`。
     *
     * @param string $column 列名
     * @param int $delta 增量（负数为减）
     * @param array<string, mixed> $extra 同时更新的其他列
     * @return int 受影响行数
     */
    private function applyDelta(string $column, int $delta, array $extra): int
    {
        $this->assertIdentifier($column);

        $quoted = $this->q($column);
        $sets = [sprintf('%s = %s + ?', $quoted, $quoted)];
        $bindings = [$delta];

        foreach ($extra as $name => $value) {
            $this->assertIdentifier((string) $name);
            $sets[] = $this->q((string) $name) . ' = ?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->q($this->table),
            implode(', ', $sets),
            $this->toWhereSql()
        );

        // SET 的绑定在前，WHERE 的绑定在后
        return $this->connection->affect($sql, [...$bindings, ...$this->whereBindings()]);
    }

    /**
     * 是否存在匹配行。
     *
     * @return bool 存在返回 true
     * @throws QueryException 执行失败时抛出
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * 插入一行。
     *
     * @param array<string, mixed> $data 字段 => 值
     * @return int 受影响行数
     * @throws InvalidArgumentException 数据为空时抛出
     * @throws QueryException 执行失败时抛出
     */
    public function insert(array $data): int
    {
        return $this->connection->affect($this->toInsertSql($data), array_values($data));
    }

    /**
     * 批量插入（单条 SQL，多值行）。
     *
     * 为什么需要它：给 1000 个用户发通知时，循环 `insert()` 会产生 1000 次
     * 数据库往返（本地约 1ms/次，跨网络可能 5ms 以上）。多值 INSERT 一次搞定。
     *
     * SQLite 的绑定变量上限是 999，MySQL 也有限制，因此按 500 行分批。
     *
     * @param list<array<string, mixed>> $rows 行数据（每行的列必须一致）
     * @return int 插入行数
     * @throws InvalidArgumentException 数据为空或列不一致时抛出
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);

        if ($columns === []) {
            throw new InvalidArgumentException('insertMany() 的行不能为空数组');
        }

        foreach ($columns as $column) {
            $this->assertIdentifier((string) $column);
        }

        $inserted = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            // 同一批次的列必须一致，否则占位符数量对不上
            foreach ($chunk as $row) {
                if (array_keys($row) !== $columns) {
                    throw new InvalidArgumentException('insertMany() 的所有行必须拥有相同的列');
                }
            }

            $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $values = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $values[] = $placeholder;

                foreach ($columns as $column) {
                    $bindings[] = $row[$column] ?? null;
                }
            }

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->table,
                implode(', ', $columns),
                implode(', ', $values)
            );

            $inserted += $this->connection->affect($sql, $bindings);
        }

        return $inserted;
    }

    /**
     * 插入并返回自增主键。
     *
     * @param array<string, mixed> $data 字段 => 值
     * @param string $primaryKey 主键字段（PostgreSQL 使用 RETURNING）
     * @return int 主键值（自增 ID 一定是整数）
     * @throws QueryException 执行失败时抛出
     */
    public function insertGetId(array $data, string $primaryKey = 'id'): int
    {
        if ($this->connection->driver() === 'pgsql') {
            $this->assertIdentifier($primaryKey);
            $sql = $this->toInsertSql($data) . sprintf(' RETURNING %s', $primaryKey);
            $value = $this->connection->scalar($sql, array_values($data));

            return (int) $value;
        }

        $this->connection->affect($this->toInsertSql($data), array_values($data));

        return (int) $this->connection->lastInsertId();
    }

    /**
     * 更新满足条件的行。
     *
     * @param array<string, mixed> $data 字段 => 新值
     * @return int 受影响行数
     * @throws InvalidArgumentException 无 where 条件或数据为空时抛出
     * @throws QueryException 执行失败时抛出
     */
    public function update(array $data): int
    {
        if ($this->wheres === []) {
            throw new InvalidArgumentException('update() 缺少 where 条件，已拒绝执行（防止全表更新）');
        }

        if ($data === []) {
            throw new InvalidArgumentException('update() 的更新数据不能为空');
        }

        $assignments = [];

        foreach (array_keys($data) as $column) {
            $this->assertIdentifier((string) $column);
            $assignments[] = sprintf('%s = ?', $this->q((string) $column));
        }

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->q($this->table),
            implode(', ', $assignments),
            $this->toWhereSql()
        );

        return $this->connection->affect($sql, [...array_values($data), ...$this->whereBindings()]);
    }

    /**
     * 删除满足条件的行。
     *
     * @return int 受影响行数
     * @throws InvalidArgumentException 无 where 条件时抛出
     * @throws QueryException 执行失败时抛出
     */
    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new InvalidArgumentException('delete() 缺少 where 条件，已拒绝执行（防止清空表）');
        }

        $sql = sprintf('DELETE FROM %s%s', $this->q($this->table), $this->toWhereSql());

        return $this->connection->affect($sql, $this->bindings());
    }

    /**
     * 生成查询 SQL（调试用）。
     *
     * @return string SQL
     */
    public function toSql(): string
    {
        return $this->toSelectSql();
    }

    /**
     * 获取全部绑定值（调试用）。
     *
     * @return list<mixed> 绑定值
     */
    public function getBindings(): array
    {
        return $this->bindings();
    }

    /**
     * 生成 SELECT 语句。
     *
     * @return string SQL
     */
    private function toSelectSql(): string
    {
        $sql = sprintf(
            'SELECT %s FROM %s%s%s%s',
            implode(', ', $this->columns),
            $this->q($this->table),
            $this->toWhereSql(),
            $this->groups === [] ? '' : ' GROUP BY ' . implode(', ', $this->groups),
            $this->toOrderSql()
        );

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;

            if ($this->offset !== null) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        } elseif ($this->offset !== null) {
            throw new InvalidArgumentException('offset 必须配合 limit 使用（不同数据库默认语义不一致）');
        }

        return $sql;
    }

    /**
     * 生成 INSERT 语句。
     *
     * @param array<string, mixed> $data 数据
     * @return string SQL
     * @throws InvalidArgumentException 数据为空时抛出
     */
    private function toInsertSql(array $data): string
    {
        if ($data === []) {
            throw new InvalidArgumentException('insert() 的数据不能为空');
        }

        foreach (array_keys($data) as $column) {
            $this->assertIdentifier((string) $column);
        }

        $quotedColumns = array_map(
            fn (string|int $c): string => $this->q((string) $c),
            array_keys($data)
        );

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->q($this->table),
            implode(', ', $quotedColumns),
            implode(', ', array_fill(0, count($data), '?'))
        );
    }

    /**
     * 生成 WHERE 子句。
     *
     * @return string 形如 " WHERE a = ? AND b = ?"
     */
    private function toWhereSql(): string
    {
        $fragment = $this->toWhereSqlFragment();

        return $fragment === '' ? '' : ' WHERE ' . $fragment;
    }

    /**
     * 条件片段（不含 WHERE 前缀）。
     *
     * 单独抽出来是为了让嵌套分组能复用同一套拼装逻辑——
     * 否则括号里的 OR 连接规则会和外层不一致。
     *
     * @return string 条件片段
     */
    private function toWhereSqlFragment(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $parts = [];

        foreach ($this->wheres as $index => $where) {
            $parts[] = ($index === 0 ? '' : strtoupper($where['boolean']) . ' ') . $where['sql'];
        }

        return implode(' ', $parts);
    }

    /**
     * 生成 ORDER BY 子句。
     *
     * @return string 形如 " ORDER BY id DESC"
     */
    private function toOrderSql(): string
    {
        if ($this->orders === []) {
            return '';
        }

        $parts = array_map(
            static fn (array $order): string => $order['column'] . ' ' . $order['direction'],
            $this->orders
        );

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * 汇总绑定值。
     *
     * @return list<mixed> 绑定值
     */
    private function bindings(): array
    {
        // SELECT 在 SQL 里位于 WHERE 之前，绑定顺序必须一致
        return [...$this->selectBindings, ...$this->whereBindings()];
    }

    /**
     * 仅 WHERE 的绑定。
     *
     * COUNT / UPDATE / DELETE 会重写 SELECT 部分，
     * 不能再带上 selectBindings —— 否则绑定数多于占位符会直接报错。
     *
     * @return list<mixed> 绑定值
     */
    private function whereBindings(): array
    {
        $bindings = [];

        foreach ($this->wheres as $where) {
            $bindings = [...$bindings, ...$where['bindings']];
        }

        return $bindings;
    }

    /**
     * 标识符白名单校验（表名、字段名、排序方向）。
     *
     * @param string $identifier 标识符
     * @return void
     * @throws InvalidArgumentException 非法标识符时抛出
     */
    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '非法标识符 [%s]：只允许字母、数字、下划线与单个点（防注入）',
                $identifier
            ));
        }
    }

    /**
     * 按驱动引用标识符。
     *
     * MySQL 用反引号，PostgreSQL 与 SQLite 用双引号。
     *
     * 不加引号时，列名一旦是 SQL 保留字（key / group / order / before…），
     * DML 会直接语法错误，而报错只有数据库的通用提示，很难定位。
     *
     * 支持 `table.column` 形式：拆开后分别引用，点号保留。
     * 标识符内部若已含引号，按 SQL 标准双写转义。
     *
     * @param string $identifier 标识符（列名 / 表名 / 别名.列名）
     * @return string 引用后的标识符
     */
    private function q(string $identifier): string
    {
        $quote = $this->connection->driver() === 'mysql' ? '`' : '"';

        $parts = array_map(
            static fn (string $part): string => $quote . str_replace($quote, $quote . $quote, $part) . $quote,
            explode('.', $identifier)
        );

        return implode('.', $parts);
    }
}
