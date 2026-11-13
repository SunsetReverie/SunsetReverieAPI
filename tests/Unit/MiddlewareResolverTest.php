<?php

declare(strict_types=1);

namespace Tests\Unit;

use SunsetReverie\Http\Middleware\MiddlewareInterface;
use SunsetReverie\Http\MiddlewareResolver;
use SunsetReverie\Http\Request;
use SunsetReverie\Http\Response;
use Closure;
use Tests\TestCase;

/**
 * 中间件解析：别名、参数、域级覆盖。
 */
final class MiddlewareResolverTest extends TestCase
{
    /**
     * 别名应解析成实例。
     *
     * @return void
     */
    public function testResolvesAlias(): void
    {
        $resolver = new MiddlewareResolver(
            $this->app(),
            ['demo' => StubMiddleware::class],
        );

        $resolved = $resolver->resolve(['demo']);

        $this->assertTrue($resolved[0] instanceof StubMiddleware);
    }

    /**
     * 域级别名应覆盖全局。
     *
     * @return void
     */
    public function testDomainAliasOverridesGlobal(): void
    {
        $resolver = new MiddlewareResolver(
            $this->app(),
            ['auth' => StubMiddleware::class],
        );

        $resolved = $resolver->resolve(['auth'], ['auth' => ParameterizedMiddleware::class]);

        $this->assertTrue($resolved[0] instanceof ParameterizedMiddleware, '域级别名应优先');
    }

    /**
     * 带参数的别名应把参数交给 HasParameters。
     *
     * @return void
     */
    public function testResolvesParameters(): void
    {
        $resolver = new MiddlewareResolver($this->app(), ['throttle' => ParameterizedMiddleware::class]);

        $resolved = $resolver->resolve(['throttle:300,60']);

        // 用 early-return 而不是 assertTrue：assertTrue 不会让静态分析收窄类型，
        // 后续访问 ->parameters 会被判为「在 callable|object 上访问属性」
        if (!$resolved[0] instanceof ParameterizedMiddleware) {
            $this->fail('应解析成 ParameterizedMiddleware 实例');
        }

        $this->assertSame(['300', '60'], $resolved[0]->parameters);
    }

    /**
     * 类名可直接使用（不需要别名）。
     *
     * @return void
     */
    public function testResolvesClassNameDirectly(): void
    {
        $resolver = new MiddlewareResolver($this->app());

        $resolved = $resolver->resolve([StubMiddleware::class]);

        $this->assertTrue($resolved[0] instanceof StubMiddleware);
    }

    /**
     * 闭包与实例原样透传。
     *
     * @return void
     */
    public function testPassesThroughNonStrings(): void
    {
        $closure = static fn (): null => null;
        $instance = new StubMiddleware();
        $resolver = new MiddlewareResolver($this->app());

        $resolved = $resolver->resolve([$closure, $instance]);

        $this->assertSame($closure, $resolved[0]);
        $this->assertSame($instance, $resolved[1]);
    }

    /**
     * 未知别名应报错并列出可用别名。
     *
     * @return void
     */
    public function testUnknownAliasThrows(): void
    {
        $resolver = new MiddlewareResolver($this->app(), ['demo' => StubMiddleware::class]);

        $e = $this->assertThrows(\InvalidArgumentException::class, static fn () => $resolver->resolve(['nope']));

        $this->assertStringContains('可用别名：demo', $e->getMessage());
    }

    /**
     * 给不支持参数的中间件传参应报错（而不是静默忽略）。
     *
     * @return void
     */
    public function testParametersOnUnsupportedMiddlewareThrows(): void
    {
        $resolver = new MiddlewareResolver($this->app(), ['demo' => StubMiddleware::class]);

        $e = $this->assertThrows(\InvalidArgumentException::class, static fn () => $resolver->resolve(['demo:1,2']));

        $this->assertStringContains('不接受参数', $e->getMessage());
    }

    /**
     * 构建最小容器（持有本测试用到的类）。
     *
     * @return \SunsetReverie\Container\Container 容器
     */
    /**
     * 同一个中间件类只保留最后一次声明。
     *
     * 域级 middleware 会通过 Route::group() 继承进每条路由的 middlewareList()，
     * 路由自己又可能再声明一次。加去重前会得到两个实例、都进管线：
     *
     *   config/api.php  'middleware' => ['throttle:120,60']
     *   routes/...      ->middleware(['throttle:5,60'])
     *
     * ThrottleRequests 的 key 是 `{ip}|{route}`，两个实例算同一个 key，
     * 于是每次请求计数 +2 —— 配 5 次/分钟实际 2.5 次就 429。
     *
     * @return void
     */
    public function testSameMiddlewareClassIsDeduplicated(): void
    {
        $resolver = new MiddlewareResolver($this->app(), ['throttle' => ParameterizedMiddleware::class]);

        $resolved = $resolver->resolve(['throttle:120,60', 'throttle:5,60']);

        $this->assertSame(1, count($resolved), '同一中间件类应只保留一个实例');

        $first = $resolved[0];

        if (!$first instanceof ParameterizedMiddleware) {
            $this->fail('保留的实例类型不对');
        }

        $this->assertSame(
            ['5', '60'],
            $first->parameters,
            '应保留最后一次（路由级）的参数，即路由级覆盖域级'
        );
    }

    /**
     * 去重不能误伤：不同中间件类都要保留，顺序不变。
     *
     * @return void
     */
    public function testDifferentMiddlewareClassesAreKept(): void
    {
        $resolver = new MiddlewareResolver($this->app(), [
            'stub' => StubMiddleware::class,
            'throttle' => ParameterizedMiddleware::class,
        ]);

        $resolved = $resolver->resolve(['stub', 'throttle:5,60', 'stub']);

        $this->assertSame(2, count($resolved), '两个不同类应都保留');
        $this->assertTrue($resolved[0] instanceof StubMiddleware);
        $this->assertTrue($resolved[1] instanceof ParameterizedMiddleware);
    }

    private function app(): \SunsetReverie\Container\Container
    {
        $container = new \SunsetReverie\Container\Container();
        $container->singleton(StubMiddleware::class);
        $container->singleton(ParameterizedMiddleware::class);

        return $container;
    }
}

/**
 * 无参数中间件。
 */
final class StubMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}

/**
 * 支持参数的中间件。
 */
final class ParameterizedMiddleware implements MiddlewareInterface, \SunsetReverie\Http\Middleware\HasParameters
{
    /** @var list<string> */
    public array $parameters = [];

    public function withParameters(array $parameters): static
    {
        $clone = clone $this;
        $clone->parameters = $parameters;

        return $clone;
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
