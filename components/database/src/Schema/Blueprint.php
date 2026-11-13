<?php

declare(strict_types=1);

namespace SunsetReverie\Database\Schema;

use InvalidArgumentException;

/**
 * 表结构定义（最小可用集）。
 *
 * 只覆盖建表时最常用的列与索引；复杂需求（外键级联、分区、生成列）
 * 直接在迁移里写原生 SQL，不硬塞进 DSL。
 */
final class Blueprint
{
    /** @var list<string> 列定义片段 */
    private array $columns = [];

    /** @var list<string> 表级约束（主键 / 唯一） */
    private array $constraints = [];

    /** @var list<array{name: string, columns: list<string>, unique: bool}> 索引 */
    private array $indexes = [];

    /**
     * 类型占位符 → 各驱动的真实类型。
     *
     * 顺序必须与 toSql() 里三个替换数组严格一致。抽成常量是因为
     * 之前这些字面量在三个分支里各写一遍，加一个类型要改三处，容易漏。
     *
     * @var list<string>
     */
    private const TYPE_PLACEHOLDERS = [
        '__BOOL__',
        '__TIMESTAMP__',
        '__JSON__',
        '__MEDIUMTEXT__',
        '__LONGTEXT__',
        '__SMALLINT__',
        '__UNSIGNED_INT__',
        '__UNSIGNED_BIGINT__',
    ];

    /**
     * @param string $table 表名
     */
    public function __construct(private readonly string $table)
    {
    }

    /**
     * 自增主键。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function id(string $name = 'id'): self
    {
        $this->columns[] = sprintf('__ID__:%s', $name);

        return $this;
    }

    /**
     * 字符串列。
     *
     * @param string $name 列名
     * @param int $length 长度
     * @return self 当前实例
     */
    public function string(string $name, int $length = 255): self
    {
        return $this->addColumn($name, sprintf('VARCHAR(%d)', $length));
    }

    /**
     * 长文本列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function text(string $name): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    /**
     * 中等长度文本。
     *
     * MySQL 的 `TEXT` 只有 64KB，文章正文、邮件模板很容易超过——
     * 严格模式下写入会直接失败。`MEDIUMTEXT` 是 16MB。
     *
     * PostgreSQL / SQLite 的 `TEXT` 本身没有长度上限，映射成 `TEXT` 即可。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function mediumText(string $name): self
    {
        return $this->addColumn($name, '__MEDIUMTEXT__');
    }

    /**
     * 长文本（MySQL `LONGTEXT`，4GB）。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function longText(string $name): self
    {
        return $this->addColumn($name, '__LONGTEXT__');
    }

    /**
     * 整数列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function integer(string $name): self
    {
        return $this->addColumn($name, 'INTEGER');
    }

    /**
     * 大整数列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function bigInteger(string $name): self
    {
        return $this->addColumn($name, 'BIGINT');
    }

    /**
     * 小整数（MySQL `SMALLINT`，±32767）。
     *
     * 适合状态位、评分、数量这类不会大的字段——比 `INTEGER` 省一半空间。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function smallInteger(string $name): self
    {
        return $this->addColumn($name, '__SMALLINT__');
    }

    /**
     * 无符号整数（MySQL `INT UNSIGNED`）。
     *
     * 注意：只有 MySQL 支持无符号。PostgreSQL / SQLite 会退化成普通整数，
     * 需要非负约束时应在应用层校验或加 CHECK。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function unsignedInteger(string $name): self
    {
        return $this->addColumn($name, '__UNSIGNED_INT__');
    }

    /**
     * 无符号大整数（MySQL `BIGINT UNSIGNED`）。
     *
     * 适合外部 ID、雪花 ID 这类只增不减的值。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function unsignedBigInteger(string $name): self
    {
        return $this->addColumn($name, '__UNSIGNED_BIGINT__');
    }

    /**
     * 布尔列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function boolean(string $name): self
    {
        return $this->addColumn($name, '__BOOL__');
    }

    /**
     * JSON 列。
     *
     * 三种数据库的对应类型不同：
     * - MySQL：`JSON`（5.7+，有原生校验）
     * - PostgreSQL：`JSONB`（二进制存储，可建索引、查询更快）
     * - SQLite：`TEXT`（无原生类型，靠 JSON1 扩展的函数读写）
     *
     * 语义上"存一段结构化数据"，不保证跨库的查询能力一致——
     * 需要按 JSON 内部字段过滤时，MySQL/PG 能用 `->>`，SQLite 要 `json_extract`，
     * 这类查询建议放原生 SQL 而不是硬塞进 DSL。
     *
     * 默认 NULL：MySQL 的 JSON 列不允许非 NULL 字面量默认值。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function json(string $name): self
    {
        return $this->addColumn($name, '__JSON__');
    }

    /**
     * 时间戳列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function timestamp(string $name): self
    {
        return $this->addColumn($name, '__TIMESTAMP__');
    }

    /**
     * 日期列。
     *
     * @param string $name 列名
     * @return self 当前实例
     */
    public function date(string $name): self
    {
        return $this->addColumn($name, 'DATE');
    }

    /**
     * 数值列。
     *
     * @param string $name 列名
     * @param int $precision 总位数
     * @param int $scale 小数位
     * @return self 当前实例
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): self
    {
        return $this->addColumn($name, sprintf('DECIMAL(%d,%d)', $precision, $scale));
    }

    /**
     * 创建时间与更新时间列。
     *
     * @return self 当前实例
     */
    public function timestamps(): self
    {
        return $this->timestamp('created_at')->timestamp('updated_at');
    }

    /**
     * 允许为空。
     *
     * @return self 当前实例
     */
    public function nullable(): self
    {
        return $this->modifyLast(' NULL');
    }

    /**
     * 默认值。
     *
     * @param mixed $value 默认值
     * @return self 当前实例
     */
    public function default(mixed $value): self
    {
        $literal = match (true) {
            // TRUE / FALSE 是三种数据库都能接受的写法；
            // 用 1 / 0 会让 PostgreSQL 的 BOOLEAN 列报类型不匹配
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value) => (string) $value,
            $value === null => 'NULL',
            default => "'" . str_replace("'", "''", (string) $value) . "'",
        };

        return $this->modifyLast(' DEFAULT ' . $literal);
    }

    /**
     * 唯一约束（单列）。
     *
     * @return self 当前实例
     */
    /**
     * 唯一索引。
     *
     * 两种用法：
     *
     *   // 1) 链式：修饰最近定义的那一列（历史的写法）
     *   $table->string('slug', 200)->unique();
     *
     *   // 2) 按列名：与 index() 一致，可指定任意列或复合列
     *   $table->unique('sku');
     *   $table->unique(['post_id', 'user_id']);
     *
     * 第二种是后来补的。之前只支持链式，而 `unique('sku')` 这种写法
     * **不会报错**（PHP 忽略多余参数），但会把唯一索引加到"最后一列"上——
     * 实测中 `timestamps()` 之后的 `unique('sku')` 让 `updated_at` 变成唯一列，
     * 插入第二条数据就冲突。这类静默错误必须在接口层消灭。
     *
     * @param string|list<string>|null $columns 列名（null 表示修饰最后一列）
     * @param string|null $name 索引名
     * @return self 当前实例
     */
    public function unique(string|array|null $columns = null, ?string $name = null): self
    {
        if ($columns === null) {
            return $this->modifyLast('__UNIQUE__');
        }

        $columns = array_values((array) $columns);

        foreach ($columns as $column) {
            $this->assertColumnExists((string) $column);
        }

        $this->indexes[] = [
            'name' => $name ?? $this->table . '_' . implode('_', $columns) . '_unique',
            'columns' => $columns,
            'unique' => true,
        ];

        return $this;
    }

    /**
     * 校验列存在。
     *
     * 列名写错时直接报错，而不是等到执行 DDL 才由数据库抛"未知列"——
     * 后者在迁移批量执行时很难定位是哪一行写错了。
     *
     * @param string $column 列名
     * @return void
     * @throws InvalidArgumentException 列不存在时抛出
     */
    private function assertColumnExists(string $column): void
    {
        foreach ($this->columns as $definition) {
            if (str_starts_with($definition, $column . ' ')
                || str_starts_with($definition, $column . '\x00')
                || $definition === $column
                || str_starts_with($definition, $column . '__')) {
                return;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '索引引用了不存在的列 [%s]（表 %s）',
            $column,
            $this->table
        ));
    }

    /**
     * 添加复合唯一索引（如 `(post_id, user_id)` 保证同一用户对同一文章只点一次）。
     *
     * @param list<string>|string $columns 列（或多列）
     * @param string|null $name 索引名
     * @return self 当前实例
     */
    public function uniqueIndex(array|string $columns, ?string $name = null): self
    {
        $columns = array_values((array) $columns);
        $this->indexes[] = [
            'name' => $name ?? $this->table . '_' . implode('_', $columns) . '_unique',
            'columns' => $columns,
            'unique' => true,
        ];

        return $this;
    }

    /**
     * 添加普通索引。
     *
     * 两种用法（与 unique() 对齐）：
     *
     *   // 1) 链式：修饰最近定义的那一列
     *   $table->string('domain', 120)->index();
     *
     *   // 2) 按列名：可指定任意列或复合列
     *   $table->index('domain');
     *   $table->index(['post_id', 'created_at']);
     *
     * 早期版本只支持第二种，而 unique() 两种都支持——两个兄弟方法不对称，
     * 使用者会自然假设 `->index()` 也能链式，第一次写迁移必踩一次。
     *
     * @param string|list<string>|null $columns 列名（null 表示修饰最后一列）
     * @param string|null $name 索引名
     * @return self 当前实例
     */
    public function index(string|array|null $columns = null, ?string $name = null): self
    {
        if ($columns === null) {
            return $this->modifyLast('__INDEX__');
        }

        $columns = array_values((array) $columns);

        foreach ($columns as $column) {
            $this->assertColumnExists((string) $column);
        }

        $this->indexes[] = [
            'name' => $name ?? $this->table . '_' . implode('_', $columns) . '_index',
            'columns' => $columns,
            'unique' => false,
        ];

        return $this;
    }

    /**
     * 生成建表 SQL。
     *
     * @param string $driver 数据库驱动
     * @return list<string> SQL 语句列表（建表 + 索引）
     */
    public function toSql(string $driver): array
    {
        $definitions = [];
        $uniqueColumns = [];
        $chainIndexColumns = [];

        foreach ($this->columns as $column) {
            if (str_starts_with($column, '__ID__:')) {
                $name = substr($column, 7);
                $definitions[] = $this->idDefinition($driver, $name);

                continue;
            }

            $isUnique = str_ends_with($column, '__UNIQUE__');
            $isIndex = str_ends_with($column, '__INDEX__');
            $isNullable = str_contains($column, ' NULL');

            $column = str_replace(['__UNIQUE__', '__INDEX__'], '', $column);

            $column = match ($driver) {
                'pgsql' => str_replace(
                    self::TYPE_PLACEHOLDERS,
                    ['BOOLEAN', 'TIMESTAMP', 'JSONB', 'TEXT', 'TEXT', 'SMALLINT', 'INTEGER', 'BIGINT'],
                    $column
                ),
                'sqlite' => str_replace(
                    self::TYPE_PLACEHOLDERS,
                    ['INTEGER', 'DATETIME', 'TEXT', 'TEXT', 'TEXT', 'INTEGER', 'INTEGER', 'INTEGER'],
                    $column
                ),
                default => str_replace(
                    self::TYPE_PLACEHOLDERS,
                    ['TINYINT(1)', 'DATETIME', 'JSON', 'MEDIUMTEXT', 'LONGTEXT', 'SMALLINT', 'INT UNSIGNED', 'BIGINT UNSIGNED'],
                    $column
                ),
            };

            if (!$isNullable) {
                $column .= ' NOT NULL';
            }

            /*
             * 列名要加引号；这里拆出「列名」和「其余部分（类型 + 约束）」。
             *
             * 用 explode 而不是 strtok：strtok 返回 string|false，
             * 静态分析要额外的分支判断，而且它对空字符串的行为不直观。
             */
            $parts = explode(' ', trim($column), 2);
            $columnName = $parts[0];
            $rest = isset($parts[1]) ? ' ' . $parts[1] : '';

            $definitions[] = $this->quoteIdentifier($columnName, $driver) . $rest;

            if ($isUnique) {
                $uniqueColumns[] = $columnName;
            }

            if ($isIndex) {
                $chainIndexColumns[] = $columnName;
            }
        }

        foreach ($uniqueColumns as $columnName) {
            $definitions[] = sprintf('UNIQUE (%s)', $this->quoteIdentifier($columnName, $driver));
        }

        $definitions = [...$definitions, ...$this->constraints];

        $sql = sprintf(
            'CREATE TABLE %s (%s)',
            $this->quoteIdentifier($this->table, $driver),
            implode(', ', $definitions)
        );

        if ($driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }

        $statements = [$sql];

        // 链式 index() 收集到的列 —— 单独建索引（三库通用）
        foreach ($chainIndexColumns as $columnName) {
            $statements[] = sprintf(
                'CREATE INDEX %s ON %s (%s)',
                $this->quoteIdentifier($this->table . '_' . $columnName . '_index', $driver),
                $this->quoteIdentifier($this->table, $driver),
                $this->quoteIdentifier($columnName, $driver)
            );
        }

        foreach ($this->indexes as $index) {
            $quotedColumns = array_map(
                fn (string $c): string => $this->quoteIdentifier($c, $driver),
                $index['columns']
            );

            $statements[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index['unique'] ? 'UNIQUE ' : '',
                $this->quoteIdentifier($index['name'], $driver),
                $this->quoteIdentifier($this->table, $driver),
                implode(', ', $quotedColumns)
            );
        }

        return $statements;
    }

    /**
     * 按驱动引用标识符（表名 / 列名 / 索引名）。
     *
     * MySQL 用反引号，PostgreSQL 与 SQLite 用双引号。
     * 不加引号时，列名一旦是 SQL 保留字（key / group / before / order…），
     * DDL 直接语法错误，而报错只有数据库的通用语法提示，定位成本很高。
     *
     * 标识符内部若已含引号，按 SQL 标准双写转义。
     *
     * @param string $name 标识符
     * @param string $driver 驱动
     * @return string 引用后的标识符
     */
    private function quoteIdentifier(string $name, string $driver): string
    {
        $quote = $driver === 'mysql' ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $name) . $quote;
    }

    /**
     * 主键定义（按方言生成）。
     *
     * @param string $driver 驱动
     * @param string $name 列名
     * @return string 列定义
     */
    private function idDefinition(string $driver, string $name): string
    {
        $quoted = $this->quoteIdentifier($name, $driver);

        return match ($driver) {
            'pgsql' => sprintf('%s BIGSERIAL PRIMARY KEY', $quoted),
            'sqlite' => sprintf('%s INTEGER PRIMARY KEY AUTOINCREMENT', $quoted),
            default => sprintf('%s BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', $quoted),
        };
    }

    /**
     * 追加列定义。
     *
     * @param string $name 列名
     * @param string $type 类型片段
     * @return self 当前实例
     */
    private function addColumn(string $name, string $type): self
    {
        $this->columns[] = $name . ' ' . $type;

        return $this;
    }

    /**
     * 修改最后一个列定义（nullable / default / unique）。
     *
     * @param string $suffix 追加片段
     * @return self 当前实例
     */
    private function modifyLast(string $suffix): self
    {
        $index = count($this->columns) - 1;

        if ($index >= 0) {
            /*
             * 不写成 `$this->columns[$index] .= $suffix`：
             * 那种写法在静态分析看来会把 list<string> 变成
             * 「可能稀疏的 non-empty-array」，破坏 list 语义。
             * 弹出再追加能保持 list。
             */
            $last = array_pop($this->columns);
            $this->columns[] = $last . $suffix;
        }

        return $this;
    }
}
