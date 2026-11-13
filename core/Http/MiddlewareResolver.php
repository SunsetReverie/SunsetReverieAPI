<?php

declare(strict_types=1);

namespace SunsetReverie\Http;

use InvalidArgumentException;
use SunsetReverie\Container\Container;
use SunsetReverie\Http\Middleware\HasParameters;

/**
 * 中间件解析：把路由上声明的字符串（别名 / 类名 / 带参数形式）解析成实例。
 *
 * 支持三种写法：
 *   'auth'                          → 按别名表解析
 *   'throttle:300,1'                → 别名 + 参数（交给 HasParameters）
 *   \App\Api\Admin\Middleware\X::class  → 直接实例化
 *
 * 别名优先级：域级别名 > 全局别名 > 视为类名。
 * 这样同一个 `auth` 在 admin 域与 web 域可以指向完全不同的实现（见文档 6.7.3）。
 */
final class MiddlewareResolver
{
    /**
     * @param Container $container 容器（用于实例化中间件）
     * @param array<string, string> $aliases 全局别名表（别名 => 类名）
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $aliases = [],
    ) {
    }

    /**
     * 批量解析中间件声明。
     *
     * @param list<string|object|callable> $middleware 声明列表
     * @param array<string, string> $domainAliases 域级别名（覆盖全局）
     * @return list<object|callable> 实例列表
     * @throws InvalidArgumentException 无法解析时抛出
     */
    public function resolve(array $middleware, array $domainAliases = []): array
    {
        $resolved = [];

        /** @var array<class-string, int> 类名 => 在 $resolved 里的下标 */
        $positions = [];

        foreach ($middleware as $item) {
            $instance = is_string($item)
                ? $this->resolveOne($item, $domainAliases)
                : $item;

            /*
             * 闭包（callable）不参与去重：没有稳定的类名语义，
             * 两个闭包即使「看起来一样」也是不同的实例。
             */
            if (!is_object($instance) || $instance instanceof \Closure) {
                $resolved[] = $instance;

                continue;
            }

            $class = $instance::class;

            /*
             * 同一个中间件类只保留**最后一次**声明。
             *
             * 为什么必须去重：域级 middleware 会通过 Route::group() 继承进
             * 每条路由的 middlewareList()，而路由自己又可能再声明一次。
             * 于是 `throttle:120,60`（域级）+ `throttle:5,60`（路由级）
             * 会产生两个实例、都进管线、都执行。
             *
             * ThrottleRequests 的 resolveKey() 默认是 `{ip}|{route}`，
             * 两个实例计数的是同一个 key —— 一次请求 +2，实际可用额度减半。
             *
             * 后面的声明来自路由（更具体），保留它即「路由级覆盖域级」。
             *
             * 代价：同一个中间件类**故意**要挂两次（不同实例）的写法不再支持。
             * 这种情况在实际项目里没有合理场景，真要重复执行应该在中间件内部做。
             */
            if (isset($positions[$class])) {
                $resolved[$positions[$class]] = $instance;

                continue;
            }

            $positions[$class] = count($resolved);
            $resolved[] = $instance;
        }

        // array_values 保证返回 list（去重时用下标覆盖过，键可能不连续）
        return array_values($resolved);
    }

    /**
     * 解析单个中间件声明。
     *
     * @param string $spec 声明（别名 / 类名 / 带参数）
     * @param array<string, string> $domainAliases 域级别名
     * @return object 中间件实例
     * @throws InvalidArgumentException 无法解析时抛出
     */
    private function resolveOne(string $spec, array $domainAliases): object
    {
        [$name, $parameters] = $this->split($spec);

        $class = $domainAliases[$name] ?? $this->aliases[$name] ?? $name;

        if (!class_exists($class)) {
            $available = array_keys($domainAliases + $this->aliases);

            throw new InvalidArgumentException(sprintf(
                '中间件 [%s] 无法解析：既不是已注册别名，也不是存在的类。可用别名：%s',
                $name,
                $available === [] ? '（无）' : implode('、', $available)
            ));
        }

        $instance = $this->container->get($class);

        if ($parameters === []) {
            return $instance;
        }

        if (!$instance instanceof HasParameters) {
            throw new InvalidArgumentException(sprintf(
                '中间件 [%s] 不接受参数（声明为 %s），请去掉冒号后的内容',
                $class,
                $spec
            ));
        }

        return $instance->withParameters($parameters);
    }

    /**
     * 拆分 `name:arg1,arg2` 形式。
     *
     * @param string $spec 声明
     * @return array{0: string, 1: list<string>} 名称与参数
     */
    private function split(string $spec): array
    {
        if (!str_contains($spec, ':')) {
            return [$spec, []];
        }

        [$name, $argumentString] = explode(':', $spec, 2);

        $parameters = array_values(array_filter(
            array_map('trim', explode(',', $argumentString)),
            static fn (string $value): bool => $value !== ''
        ));

        return [$name, $parameters];
    }
}
