<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Foundation\Application;
use Tests\TestCase;

/**
 * 文档与代码的一致性检查。
 *
 * 为什么需要这个测试：
 *
 * 写文档时反复出现同一类错误——凭印象写「有这个 API」「有这几个方法」，
 * 而实现早就变了。人工审核能发现一部分，但会反复漏。
 *
 * 这个测试把「能机械核对的部分」变成自动检查：
 *   - 文档提到的 CLI 命令是否存在
 *   - 错误码表格的值 / HTTP / symbol 是否与枚举一致
 *   - 文档提到的配置键是否存在
 *   - 文档提到的门面方法是否存在
 *
 * 不能自动检查的（叙述、示例的业务逻辑）仍需人工读。
 */
final class DocsConsistencyTest extends TestCase
{
    /** @var string 文档目录 */
    private string $docsDir;

    /**
     * 文档不存在时跳过——有些环境（如框架被 submodule 引用时）没有 docs/。
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->docsDir = dirname(__DIR__, 2) . '/docs/handbook';
    }

    /**
     * 文档目录是否存在。
     *
     * 不存在时各测试直接 return 跳过——框架被 submodule 引用时，
     * 使用者可能没有 docs/ 目录，那时这些检查没有意义。
     *
     * @return bool
     */
    private function hasDocs(): bool
    {
        return is_dir($this->docsDir);
    }

    /**
     * 文档里提到 `php bin/sr xxx` 的命令，必须真实存在。
     *
     * @return void
     */
    public function testDocumentedCommandsExist(): void
    {
        if (!$this->hasDocs()) {
            return;
        }

        $available = $this->realCommandNames();

        $this->assertTrue(count($available) >= 25, '命令表只解析到 ' . count($available) . ' 个，正则可能失效了');

        $bad = [];

        foreach ($this->markdownFiles() as $file => $content) {
            preg_match_all('/php bin\/sr ([a-z][a-z0-9:_-]*)/', $content, $m);

            foreach (array_unique($m[1]) as $cmd) {
                if (!in_array($cmd, $available, true)) {
                    $bad[] = sprintf('%s 里的 `php bin/sr %s`', $file, $cmd);
                }
            }
        }

        $this->assertSame([], $bad, "文档提到了不存在的命令：\n  " . implode("\n  ", $bad));
    }

    /**
     * error-codes.md 的表格必须与枚举完全一致（值 / HTTP / symbol）。
     *
     * @return void
     */
    public function testErrorCodeTableMatchesEnum(): void
    {
        if (!$this->hasDocs()) {
            return;
        }

        $path = $this->docsDir . '/guide/error-codes.md';

        if (!is_file($path)) {
            return;
        }

        $doc = (string) file_get_contents($path);

        // 表格行：| 10001 | `InternalError` | 500 | `SR.SERVER.INTERNAL_ERROR` | ...
        preg_match_all(
            '/\|\s*(\d{1,5})\s*\|\s*`(\w+)`\s*\|\s*(\d{3})\s*\|\s*`(SR\.[A-Z_.]+)`/',
            $doc,
            $rows,
            PREG_SET_ORDER
        );

        $this->assertTrue(count($rows) >= 35, '错误码表格行数异常（解析到 ' . count($rows) . ' 行），表格格式可能变了');

        $bad = [];

        foreach ($rows as $row) {
            [, $code, $name, $http, $symbol] = $row;

            if (!defined(ErrorCode::class . '::' . $name)) {
                $bad[] = sprintf('%s：枚举里没有 %s', $code, $name);

                continue;
            }

            /** @var ErrorCode $case */
            $case = constant(ErrorCode::class . '::' . $name);

            if ($case->value !== (int) $code) {
                $bad[] = sprintf('%s 的值不对：文档 %d / 枚举 %d', $name, (int) $code, $case->value);
            }

            if ($case->httpStatus() !== (int) $http) {
                $bad[] = sprintf('%s 的 HTTP 不对：文档 %d / 枚举 %d', $name, (int) $http, $case->httpStatus());
            }

            if ($case->symbol() !== $symbol) {
                $bad[] = sprintf('%s 的 symbol 不对：文档 %s / 枚举 %s', $name, $symbol, $case->symbol());
            }
        }

        $this->assertSame([], $bad, "错误码表格与枚举不一致：\n  " . implode("\n  ", $bad));
    }

    /**
     * 文档提到 `config('a.b')` 的配置键必须存在于 config/。
     *
     * @return void
     */
    public function testDocumentedConfigKeysExist(): void
    {
        if (!$this->hasDocs()) {
            return;
        }

        $keys = $this->realConfigKeys();

        if ($keys === []) {
            return;
        }

        $bad = [];

        foreach ($this->markdownFiles() as $file => $content) {
            preg_match_all("/config\\('([a-z_]+\\.[a-z0-9_.]+)'\\)/", $content, $m);

            foreach (array_unique($m[1]) as $key) {
                if (!in_array($key, $keys, true)) {
                    $bad[] = sprintf('%s 里的 config(%s)', $file, $key);
                }
            }
        }

        $this->assertSame([], $bad, "文档提到了不存在的配置键：\n  " . implode("\n  ", $bad));
    }

    /**
     * 文档提到 `Facade::method(` 时，方法必须在该门面的实现类上存在。
     *
     * 只检查**首字母大写**的门面名，避开 `$obj->method()` 这类实例调用。
     *
     * @return void
     */
    public function testDocumentedFacadeMethodsExist(): void
    {
        if (!$this->hasDocs()) {
            return;
        }

        $facadeDir = dirname(__DIR__, 2) . '/core/Support/Facades';

        if (!is_dir($facadeDir)) {
            return;
        }

        // 门面名 => 实现类全名
        $map = [];

        foreach (glob($facadeDir . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $source = (string) file_get_contents($file);

            if (preg_match('/return (\w+)::class;/', $source, $m) !== 1) {
                continue;
            }

            $class = $this->findClass($m[1]);

            if ($class !== null) {
                $map[$name] = $class;
            }
        }

        if ($map === []) {
            return;
        }

        $bad = [];

        foreach ($this->markdownFiles() as $file => $content) {
            foreach ($map as $facade => $class) {
                if (!class_exists($class)) {
                    continue;
                }

                preg_match_all('/\b' . $facade . '::(\w+)\(/', $content, $m);

                foreach (array_unique($m[1]) as $method) {
                    /*
                     * 实现类有 __call 时只能弱检查。
                     *
                     * CacheManager / LogManager 把未知方法转发给默认驱动 / 通道，
                     * 所以 method_exists() 查不到但调用是合法的（Cache::increment()）。
                     *
                     * 代价：写错方法名也查不出来（Cache::fakeMethod() 会放过）。
                     * 单纯为了抓这个而引入「转发目标类」的解析，容易误报，
                     * 收益不抵成本——人工 review 方法名比维护这套逻辑划算。
                     */
                    if (method_exists($class, '__call')) {
                        continue;
                    }

                    if (!method_exists($class, $method)) {
                        $bad[] = sprintf('%s 里的 %s::%s()', $file, $facade, $method);
                    }
                }
            }
        }

        $this->assertSame([], $bad, "文档提到了不存在的门面方法：\n  " . implode("\n  ", $bad));
    }

    /**
     * 所有 markdown 文件（相对路径 => 内容）。
     *
     * @return array<string, string>
     */
    private function markdownFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->docsDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }

            $rel = str_replace($this->docsDir . '/', '', $file->getPathname());
            $files[$rel] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }

    /**
     * 从 ConsoleKernel 的命令分发表里提取命令名。
     *
     * 不执行子进程（慢且依赖环境），直接读源码里的 match 分支：
     *   'route:list' => $this->routeList($args),
     *
     * @return list<string>
     */
    private function realCommandNames(): array
    {
        $path = dirname(__DIR__, 2) . '/core/Console/ConsoleKernel.php';

        if (!is_file($path)) {
            return [];
        }

        $source = (string) file_get_contents($path);

        /*
         * 只取 match 块——命令分发表在这里，别处也有单引号字符串（错误消息等）。
         *
         * 不能只匹配 `'cmd' => $this->xxx()`：多个命令名共享一个分支时是
         *   'make:controller', 'make:model', ... => $this->make(...)
         * 所以改成「match 块内所有小写命令名」。
         */
        $start = strpos($source, 'return match ($command) {');

        if ($start === false) {
            return [];
        }

        $end = strpos($source, '};', $start);

        if ($end === false) {
            return [];
        }

        $block = substr($source, $start, $end - $start);

        // 命令名：小写开头，可含冒号/下划线/连字符
        preg_match_all("/'([a-z][a-z0-9:_-]*)'/", $block, $m);

        // 排除只作为值出现的选项名（--help 之类不会被匹配，这里只处理纯字母）
        $skip = ['command', 'help'];

        return array_values(array_unique(array_filter(
            $m[1],
            // 命令必须带冒号，或在已知的无冒号命令白名单里
            static fn (string $c): bool => str_contains($c, ':')
                || in_array($c, ['new', 'serve', 'migrate', 'help', 'about', 'optimize'], true)
        )));
    }

    /**
     * skeleton/config 里真实存在的配置键（parent.child 形式）。
     *
     * @return list<string>
     */
    private function realConfigKeys(): array
    {
        $dir = dirname(__DIR__, 2) . '/skeleton/config';

        if (!is_dir($dir)) {
            return [];
        }

        $keys = [];

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $group = basename($file, '.php');
            $source = (string) file_get_contents($file);

            preg_match_all("/^\s{4}'([a-z_][a-z0-9_]*)'\s*=>/m", $source, $m);

            foreach ($m[1] as $key) {
                $keys[] = $group . '.' . $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * 在 core/ 与 components/ 里按类名找文件，返回完整类名。
     *
     * 不用硬编码映射：门面指向的类可能被重构到别的命名空间，
     * 硬编码会静默失效。
     *
     * @param string $short 类名（不带命名空间）
     * @return string|null 完整类名，找不到返回 null
     */
    private function findClass(string $short): ?string
    {
        $root = dirname(__DIR__, 2);

        foreach (['core', 'components'] as $base) {
            $directory = $root . '/' . $base;

            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getBasename('.php') !== $short) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1) {
                    return trim($m[1]) . '\\' . $short;
                }
            }
        }

        return null;
    }

    /**
     * 文档示例里 `DB::table(...)` 之后调用的方法必须真实存在。
     *
     * 这就是 FW-001 那类问题的自动化兜底：文档写了 `->find($id)`，
     * 而 Builder 里只有 `findOrFail`，照文档写必然 500，
     * 且报错是「方法不存在」——很容易被当成自己写错了。
     *
     * 只检查 `DB::table(` 开头的链：那是明确的 Builder 调用，
     * 不会把其他对象的方法误判进来。`$this->posts->find()` 这种
     * 变量接收者的调用无法静态判断类型，不在检查范围。
     *
     * @return void
     */
    public function testDocumentedBuilderMethodsExist(): void
    {
        if (!$this->hasDocs()) {
            return;
        }

        $builder = $this->buildersPublicMethods();

        if ($builder === []) {
            $this->fail('没解析到 Builder 的任何方法，源码路径可能变了');
        }

        /*
         * 这几个不是 Builder 的方法，但会出现在链里：
         *   ->toArray()   结果数组
         *   ->json()      响应
         * 逐个确认后加入白名单，避免误报。
         */
        $allowed = ['toArray', 'json', 'each', 'map', 'filter', 'count', 'isEmpty', 'method'];

        $bad = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->docsDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }

            /*
             * changelog.md 是 CHANGELOG.md 的自动镜像，里面会出现
             * 「举反例」的代码片段（比如展示某个曾经不存在的方法），
             * 不是真实的文档示例，跳过。
             */
            if ($file->getFilename() === 'changelog.md') {
                continue;
            }

            $lines = explode("\n", (string) file_get_contents($file->getPathname()));
            $rel = str_replace(dirname($this->docsDir) . '/', '', $file->getPathname());

            $inChain = false;
            $chain = '';

            foreach ($lines as $line) {
                // 链开始：本行出现 DB::table(
                if (!$inChain && str_contains($line, 'DB::table(')) {
                    $inChain = true;
                    $chain = '';
                }

                if (!$inChain) {
                    continue;
                }

                $chain .= $line . "\n";

                // 链结束：分号 / 行尾没有续行符
                if (str_contains($line, ';') || str_contains($line, '```')) {
                    $inChain = false;

                    /*
                     * 排除 `$this->method()`：那多半出现在链的参数里
                     * （比如 insert([... 'total' => $this->formatCents($x)])），
                     * 不是 Builder 的方法。
                     */
                    preg_match_all('/(?<!\$this)->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $chain, $m);

                    foreach (array_unique($m[1]) as $method) {
                        if (in_array($method, $allowed, true)) {
                            continue;
                        }

                        if (!in_array($method, $builder, true)) {
                            $bad[] = sprintf('%s 里的 ->%s()', $rel, $method);
                        }
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $bad,
            "文档示例调用了 Builder 上不存在的方法：\n  " . implode("\n  ", array_unique($bad))
            . "\n\n要么补上这个方法，要么改文档——照文档写会直接 500。"
        );
    }

    /**
     * Builder 的公开方法名列表。
     *
     * @return list<string>
     */
    private function buildersPublicMethods(): array
    {
        $path = dirname(__DIR__, 2) . '/components/database/src/Query/Builder.php';

        if (!is_file($path)) {
            return [];
        }

        preg_match_all(
            '/public function ([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
            (string) file_get_contents($path),
            $m
        );

        return array_values(array_unique($m[1]));
    }

    /**
     * 文档里写的测试用例数必须等于实际数量。
     *
     * 这个数字硬编码在 README、首页、install.md 里，每次加测试都会过期。
     * 之前连续两轮都漏改：349 停留了很久，改成 354 时又只改了一半。
     *
     * 用例数 == 测试方法数（本项目不用 dataProvider），所以直接数文件即可，
     * 不需要真的跑一遍套件。
     *
     * @return void
     */
    public function testDocsStateActualTestCount(): void
    {
        if (!$this->hasDocs()) {
            return;
        }

        $root = dirname(__DIR__, 2);

        // 统计所有测试方法
        $actual = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $actual += (int) preg_match_all('/^\s+public function test\w+\(/m', $source);
        }

        $this->assertTrue($actual > 0, '没统计到任何测试方法，正则可能失效了');

        /*
         * 只精确校验 README 一处。
         *
         * 首页和 install.md 之前也写了具体数字，但三处同时维护成本太高——
         * 加一个测试要改三个文件，还容易漏。现在那两处改成「近 400 用例」
         * 这类模糊表述，只有 README 保持精确（它是仓库门面，读者第一眼看到）。
         */
        $targets = [$root . '/README.md'];

        $bad = [];

        foreach ($targets as $path) {
            if (!is_file($path)) {
                continue;
            }

            $content = (string) file_get_contents($path);
            $rel = str_replace($root . '/', '', $path);

            /*
             * 匹配两种写法：
             *   tests-399%20passing      （shields 徽章）
             *   399 用例 / 399 个用例     （正文）
             *
             * PREG_UNMATCHED_AS_NULL 让没参与匹配的分组是 null 而不是不存在，
             * 否则 PHPStan 会抱怨「Offset 1 might not exist」。
             */
            preg_match_all(
                '/tests-(\d+)%20passing|(\d+)\s*个?用例/',
                $content,
                $m,
                PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
            );

            foreach ($m as $match) {
                $declared = (int) ($match[1] ?? $match[2] ?? 0);

                if ($declared !== $actual) {
                    $bad[] = sprintf('%s 里写着 %d', $rel, $declared);
                }
            }
        }

        $this->assertSame(
            [],
            $bad,
            sprintf(
                "文档里的测试用例数和实际（%d）不一致：\n  %s\n"
                . '加完测试记得同步这些位置——或者干脆不写具体数字。',
                $actual,
                implode("\n  ", array_unique($bad))
            )
        );
    }
}
