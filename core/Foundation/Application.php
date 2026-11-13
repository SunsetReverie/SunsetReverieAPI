<?php

declare(strict_types=1);

namespace SunsetReverie\Foundation;

use Closure;
use Throwable;
use SunsetReverie\Config\Config;
use SunsetReverie\Routing\Exception\RouteRegistrationException;
use SunsetReverie\Console\ConsoleKernel;
use SunsetReverie\Config\Env;
use SunsetReverie\Config\EnvLoader;
use SunsetReverie\Auth\AuthManager;
use SunsetReverie\RateLimit\Middleware\ThrottleRequests;
use SunsetReverie\Auth\Middleware\Authenticate;
use SunsetReverie\Auth\Middleware\OptionalAuth;
use SunsetReverie\Auth\AuthServiceProvider;
use SunsetReverie\Api\ApiDomain;
use SunsetReverie\Api\DomainContext;
use SunsetReverie\Api\DomainRegistry;
use SunsetReverie\Api\DomainResolver;
use SunsetReverie\Api\Middleware\ResolveDomain;
use SunsetReverie\Cache\CacheManager;
use SunsetReverie\Cache\CacheServiceProvider;
use SunsetReverie\Cache\CacheStoreInterface;
use SunsetReverie\Database\Connection;
use SunsetReverie\Database\ConnectionManager;
use SunsetReverie\Database\DatabaseServiceProvider;
use SunsetReverie\Container\Container;
use SunsetReverie\Exception\ErrorCode;
use SunsetReverie\Exception\ErrorHandler;
use SunsetReverie\Exception\HttpException;
use SunsetReverie\Http\Controller\HealthController;
use SunsetReverie\Http\Envelope;
use SunsetReverie\Http\Middleware\HandleCors;
use SunsetReverie\Http\Middleware\SecurityHeaders;
use SunsetReverie\Http\Middleware\TraceRequest;
use SunsetReverie\Http\MiddlewareResolver;
use SunsetReverie\Http\Pipeline;
use SunsetReverie\Http\Request;
use SunsetReverie\Log\LogManager;
use SunsetReverie\Log\Middleware\LogRequest;
use SunsetReverie\Log\LogServiceProvider;
use SunsetReverie\Http\Response;
use SunsetReverie\Image\ImageManager;
use SunsetReverie\Image\ImageServiceProvider;
use SunsetReverie\Mail\MailManager;
use SunsetReverie\Mail\MailServiceProvider;
use SunsetReverie\Queue\QueueManager;
use SunsetReverie\Queue\QueueServiceProvider;
use SunsetReverie\RateLimit\RateLimitServiceProvider;
use SunsetReverie\Support\Facade;
use SunsetReverie\Routing\Router;
use SunsetReverie\Upload\Controller\FileController;
use SunsetReverie\Upload\UploadManager;
use SunsetReverie\Upload\UploadServiceProvider;
use SunsetReverie\Validation\ValidationServiceProvider;
use SunsetReverie\Validation\FormRequest;
use SunsetReverie\Validation\Validator;

/**
 * 应用引导与请求分发。
 *
 * v0.2 在 v0.1 基础上增加：
 * - 配置加载（.env + config/*.php + 环境覆盖 + 缓存）；
 * - 全局与路由级中间件管道；
 * - 统一异常渲染（debug / 生产两套策略）；
 * - route:cache / config:cache 等运维命令。
 */
final class Application
{
    /** 框架版本 */
    public const VERSION = '1.0.0-beta.2.4';

    private static ?self $instance = null;

    private Container $container;

    private Router $router;

    private Pipeline $pipeline;

    private Config $config;

    private ErrorHandler $errorHandler;

    /** @var Request|null 当前请求（包络中的 request_id 来源） */
    private ?Request $currentRequest = null;

    /** @var list<class-string|Closure> 全局中间件 */
    private array $globalMiddleware = [];

    private ProviderRepository $providers;

    private DomainRegistry $domains;

    /** @var list<Closure> 请求结束回调（逆序执行） */
    private array $terminatingCallbacks = [];

    private DomainContext $domainContext;

    /**
     * @param string $basePath 项目根目录
     */
    private function __construct(private readonly string $basePath)
    {
        $this->container = new Container();
        $this->router = new Router();
        $this->pipeline = new Pipeline($this->container);
        $this->config = new Config();
        $this->errorHandler = new ErrorHandler();
        $this->providers = new ProviderRepository($this->container);
        $this->domainContext = new DomainContext();
    }

    /**
     * 引导应用（幂等：重复调用返回同一实例）。
     *
     * @param string $basePath 项目根目录
     * @return self 应用实例
     */
    public static function boot(string $basePath): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $app = new self(rtrim($basePath, '/'));
        $app->bootstrap();
        self::$instance = $app;

        return $app;
    }

    /**
     * 获取已引导的实例。
     *
     * @return self 应用实例
     * @throws \RuntimeException 尚未引导时抛出
     */
    public static function getInstance(): self
    {
        if (!self::$instance instanceof self) {
            throw new \RuntimeException('应用尚未引导，请先调用 Application::boot()');
        }

        return self::$instance;
    }

    /**
     * 重置单例（仅供测试使用）。
     *
     * @return void
     */
    public static function reset(): void
    {
        Facade::clearContainer();

        self::$instance = null;
    }

    /**
     * 获取容器。
     *
     * @return Container 容器实例
     */
    public function container(): Container
    {
        return $this->container;
    }

    /**
     * 获取路由器。
     *
     * @return Router 路由器实例
     */
    public function router(): Router
    {
        return $this->router;
    }

    /**
     * 获取配置仓库。
     *
     * @return Config 配置实例
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * 获取数据库连接（懒建立）。
     *
     * @param string|null $name 连接名，null 表示默认连接
     * @return Connection 连接实例
     */
    public function db(?string $name = null): Connection
    {
        return $this->container->get(ConnectionManager::class)->connection($name);
    }

    /**
     * 获取缓存驱动。
     *
     * @param string|null $name 驱动名，null 表示默认驱动
     * @return CacheStoreInterface 缓存驱动
     */
    public function cache(?string $name = null): CacheStoreInterface
    {
        return $this->container->get(CacheManager::class)->store($name);
    }

    /**
     * 获取当前域上下文（错误码段位 / 缓存前缀 / 上传目录）。
     *
     * @return DomainContext 域上下文
     */
    public function domain(): DomainContext
    {
        return $this->domainContext;
    }

    /**
     * 获取图片管理器。
     *
     * @return ImageManager 图片管理器
     */
    public function image(): ImageManager
    {
        return $this->container->get(ImageManager::class);
    }

    /**
     * 获取邮件管理器。
     *
     * @return MailManager 邮件管理器
     */
    public function mail(): MailManager
    {
        return $this->container->get(MailManager::class);
    }

    /**
     * 获取队列管理器。
     *
     * @return QueueManager 队列管理器
     */
    public function queue(): QueueManager
    {
        return $this->container->get(QueueManager::class);
    }

    /**
     * 获取上传管理器。
     *
     * @return UploadManager 上传管理器
     */
    public function upload(): UploadManager
    {
        return $this->container->get(UploadManager::class);
    }

    /**
     * 获取认证管理器。
     *
     * @return AuthManager 认证管理器
     */
    public function auth(): AuthManager
    {
        return $this->container->get(AuthManager::class);
    }

    /**
     * 获取校验器。
     *
     * @return Validator 校验器实例
     */
    public function validator(): Validator
    {
        return $this->container->get(Validator::class);
    }

    /**
     * 获取 Provider 注册表（诊断用）。
     *
     * @return ProviderRepository Provider 注册表
     */
    public function providers(): ProviderRepository
    {
        return $this->providers;
    }

    /**
     * 拼接项目内绝对路径。
     *
     * @param string $path 相对路径
     * @return string 绝对路径
     */
    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /**
     * 编译缓存目录（配置 / 路由 / 类映射产物）。
     *
     * @param string $file 可选文件名
     * @return string 绝对路径
     */
    public function compiledPath(string $file = ''): string
    {
        $directory = $this->basePath('storage/compiled');

        return $file === '' ? $directory : $directory . '/' . $file;
    }

    /**
     * 追加全局中间件。
     *
     * @param class-string|Closure|list<class-string|Closure> $middleware 中间件
     * @return self 应用实例
     */
    public function middleware(string|Closure|array $middleware): self
    {
        foreach ((array) $middleware as $item) {
            $this->globalMiddleware[] = $item;
        }

        return $this;
    }

    /**
     * 获取全局中间件列表。
     *
     * @return list<class-string|Closure> 中间件
     */
    public function globalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * 处理 HTTP 请求。
     *
     * @param Request|null $request 请求对象；null 表示从运行环境捕获
     * @return Response 响应对象
     */
    public function handleRequest(?Request $request = null): Response
    {
        $request ??= Request::capture();
        $this->currentRequest = $request;

        // 当前请求写入容器：Auth::user() / 日志等无参调用靠它取上下文
        $this->container->instance(Request::class, $request);

        $matched = null;
        $dispatchError = null;

        try {
            $matched = $this->router->dispatch($request->method, $request->path);
        } catch (Throwable $e) {
            $dispatchError = $e;
        }

        if ($matched !== null) {
            $request = $request
                ->withAttribute('route.name', $matched['route']->name)
                ->withAttribute('route.uri', $matched['route']->uri);
        }

        // 字符串声明（别名 / 带参数）在此解析成实例；域级别名覆盖全局
        $middleware = (new MiddlewareResolver($this->container, $this->middlewareAliases()))
            ->resolve(
                [
                    ...$this->globalMiddleware,
                    ...($matched === null ? [] : $matched['route']->middlewareList()),
                ],
                // Route::$domain 可空（未绑定域的普通路由）
                // @phpstan-ignore nullsafe.neverNull
                $matched === null ? [] : ($matched['route']->domain?->middlewareAliases ?? []),
            );

        $response = null;

        /** @var list<class-string<\SunsetReverie\Http\Middleware\MiddlewareInterface>|\SunsetReverie\Http\Middleware\MiddlewareInterface|\Closure> $middleware */
        try {
            $response = $this->pipeline
                ->send($request)
                ->through($middleware)
                ->then(function (Request $request) use ($matched, $dispatchError): Response {
                    if ($dispatchError !== null) {
                        return $this->errorHandler->render($dispatchError, $request);
                    }

                    try {
                        $result = $this->invoke($matched['route']->handler, $matched['params'], $request);

                        return $this->normalize($result);
                    } catch (Throwable $e) {
                        return $this->errorHandler->render($e, $request);
                    }
                });
        } catch (Throwable $e) {
            // 中间件自身抛出的异常（如认证失败）：此时外层中间件的后置逻辑不会执行，
            // 因此这里补上基础的追踪与安全头，保证错误响应与正常响应同样安全。
            $response = $this->errorHandler->render($e, $request)
                ->withHeader('X-Request-Id', $request->requestId)
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('X-Frame-Options', 'DENY');
        }

        // HTTP 语义：HEAD 与 GET 返回同样的状态码与响应头，但不带响应体
        if ($request->method === 'HEAD') {
            $response = new Response('', $response->status(), $response->headers());
        }

        return $response;
    }

    /**
     * 执行 CLI 命令。
     *
     * @param list<string> $argv 命令行参数
     * @return int 退出码（0 成功 / 1 失败 / 2 用法错误）
     */
    /**
     * 执行 CLI 命令（委托给 ConsoleKernel）。
     *
     * @param list<string> $argv 命令行参数
     * @return int 退出码（0 成功 / 1 失败 / 2 用法错误）
     */
    public function runConsole(array $argv): int
    {
        return (new ConsoleKernel($this))->run($argv);
    }

    /**
     * 构造成功响应（统一包络）。
     *
     * @param mixed $data 业务数据
     * @param string $message 提示消息
     * @return Response 响应实例
     */
    public function success(mixed $data = null, string $message = 'success'): Response
    {
        return Response::json(Envelope::success($data, $message, $this->currentRequestId()));
    }

    /**
     * 构造失败响应（统一包络）。
     *
     * @param int $code 业务错误码
     * @param string $message 提示消息
     * @param int $status HTTP 状态码
     * @param array<string, list<string>>|null $errors 字段级错误
     * @return Response 响应实例
     */
    public function fail(int $code, string $message, int $status = 400, ?array $errors = null): Response
    {
        $payload = Envelope::error($code, $message, $this->currentRequestId(), $errors);

        return Response::json($payload, $status);
    }

    /**
     * 引导流程：环境 → 配置 → 绑定 → 中间件 → 路由。
     *
     * @return void
     */
    private function bootstrap(): void
    {
        // 项目依赖最先加载：配置文件里也可能用到第三方类
        $this->loadVendorDependencies();

        $this->loadEnvironment();
        $this->registerAppNamespace();
        $this->loadConfiguration();
        $this->loadDomainRegistry();

        // 先绑定基础设施：路由文件里可能直接写 Route::get() / DB::table()
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(DomainRegistry::class, $this->domains);
        $this->container->instance(DomainContext::class, $this->domainContext);
        $this->container->instance(
            DomainResolver::class,
            new DomainResolver(
                $this->domains,
                (bool) $this->config->get('api.allow_header_detection', false),
            ),
        );

        // 门面在此挂载：早于 Provider 与路由加载，让配置文件之外的地方都能用静态调用
        Facade::setContainer($this->container);

        $this->registerProviders();
        $this->loadRoutes();

        $this->errorHandler = new ErrorHandler($this->isDebug());
        $this->globalMiddleware = $this->resolveGlobalMiddleware();
    }

    /**
     * 注册并启动服务提供者。
     *
     * 默认只启用数据库组件；其余组件在 config/app.php 的 providers 中显式追加。
     *
     * @return void
     */
    private function registerProviders(): void
    {
        // 默认组件始终注册；config/app.php 的 providers 只做追加
        $providers = [
            DatabaseServiceProvider::class,
            CacheServiceProvider::class,
            ValidationServiceProvider::class,
            AuthServiceProvider::class,
            RateLimitServiceProvider::class,
            LogServiceProvider::class,
            UploadServiceProvider::class,
            QueueServiceProvider::class,
            MailServiceProvider::class,
            ImageServiceProvider::class,
        ];

        $configured = $this->config->get('app.providers');

        if (is_array($configured)) {
            $providers = array_values(array_unique([...$providers, ...$configured]));
        }

        $this->providers->register($providers);
        $this->providers->boot();
    }

    /**
     * 加载项目依赖（Composer 的 vendor/autoload.php）。
     *
     * 框架自身零依赖，但业务项目很可能装了第三方库。
     * 这里"存在才加载"，避免无 vendor 的环境报错；
     * 与 core/Foundation/autoload.php 里的 SR_BASE_PATH 分支配合，
     * 两处都 require_once，不会重复加载。
     *
     * @return void
     */
    private function loadVendorDependencies(): void
    {
        $autoload = $this->basePath('vendor/autoload.php');

        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    /**
     * 加载 .env（存在才加载；真实环境变量优先）。
     *
     * @return void
     */
    private function loadEnvironment(): void
    {
        $file = $this->basePath('.env');

        if (is_file($file)) {
            EnvLoader::load($file);
        }
    }

    /**
     * 加载配置：优先读取缓存，其次读取 config 目录。
     *
     * @return void
     */
    private function loadConfiguration(): void
    {
        $cacheFile = $this->compiledPath('config.php');

        if ($this->shouldPreferCache() && is_file($cacheFile)) {
            $this->config = Config::fromCache($cacheFile);

            return;
        }

        $this->config = Config::fromDirectory(
            $this->basePath('config'),
            Env::string('APP_ENV', 'local'),
        );
    }

    /**
     * 解析全局中间件（配置优先，否则使用框架默认）。
     *
     * @return list<class-string|Closure> 中间件列表
     */
    private function resolveGlobalMiddleware(): array
    {
        $configured = $this->config->get('app.middleware');

        if (is_array($configured)) {
            return array_values($configured);
        }

        // 顺序很重要：安全头在最外（保证错误响应也带头），追踪其次，CORS 最后
        // 这样预检 204 也能拿到 X-Request-Id 与安全头
        // 顺序：安全头最外（错误响应也带头）→ 追踪 → 域解析（日志要用）→ 日志 → CORS（预检最后短路）
        return [
            SecurityHeaders::class,
            TraceRequest::class,
            ResolveDomain::class,
            LogRequest::class,
            HandleCors::class,
        ];
    }

    /**
     * 构建域注册表（未配置 api.domains 时为空表，走单域模式）。
     *
     * @return void
     */
    private function loadDomainRegistry(): void
    {
        $this->domains = new DomainRegistry((array) $this->config->get('api', []));
    }

    /**
     * 中间件别名表：内置默认 + 配置追加（配置可覆盖，见文档 6.7.3）。
     *
     * 放在 Application 而不是 core/Http，是因为内置别名指向组件类，
     * 只有组装层才允许依赖组件（见 ARCH-4）。
     *
     * @return array<string, string> 别名 => 类名
     */
    private function middlewareAliases(): array
    {
        $defaults = [
            'auth' => Authenticate::class,
            'auth.optional' => OptionalAuth::class,
            'throttle' => ThrottleRequests::class,
            'body.size' => \SunsetReverie\Http\Middleware\EnsureBodySize::class,
            'cors' => HandleCors::class,
            'log' => LogRequest::class,
            'domain' => ResolveDomain::class,
            'trace' => TraceRequest::class,
            'security' => SecurityHeaders::class,
        ];

        $configured = (array) $this->config->get('api.middleware_aliases', []);

        return array_merge($defaults, array_map('strval', $configured));
    }

    /**
     * 加载路由：生产环境优先使用缓存，其余情况现场注册。
     *
     * @return void
     */
    private function loadRoutes(): void
    {
        $cacheFile = $this->compiledPath('routes.php');

        if ($this->shouldPreferCache() && is_file($cacheFile)) {
            $this->router = Router::fromCacheFile($cacheFile);

            return;
        }

        $this->registerDefaultRoutes();
        $this->registerFileRoutes();
    }

    /**
     * 注册业务路由文件（routes/api.php 返回一个接收 Router 的闭包）。
     *
     * @return void
     */
    private function registerFileRoutes(): void
    {
        // 多域：每个域一个入口文件，注册时套上「域前缀 + 域中间件 + 域名前缀」
        if (!$this->domains->isEmpty()) {
            $this->registerDomainRoutes();

            return;
        }

        // 单域：routes/api.php
        $file = $this->basePath('routes/api.php');

        if (!is_file($file)) {
            return;
        }

        $registrar = require $file;

        if (is_callable($registrar)) {
            $registrar($this->router);
        }
    }

    /**
     * 按域加载路由文件（routes/{domain}.php）。
     *
     * @return void
     */
    private function registerDomainRoutes(): void
    {
        $mode = (string) ($this->config->get('api.routing_mode') ?? 'single');

        if (!in_array($mode, ['single', 'directory'], true)) {
            throw new RouteRegistrationException(sprintf(
                'api.routing_mode 只能是 single 或 directory，当前为 [%s]',
                $mode
            ));
        }

        foreach ($this->domains->all() as $domain) {
            if ($mode === 'single') {
                $this->registerSingleDomainRoutes($domain);

                continue;
            }

            $this->registerDirectoryDomainRoutes($domain);
        }
    }

    /**
     * single 模式：每域一个 routes/{域名}.php。
     *
     * 存在同名目录时报错——两种组织方式混用会让「哪个文件生效」变得不可预测，
     * 而这正是 routing_mode 要避免的。
     *
     * @param ApiDomain $domain 域定义
     * @return void
     * @throws RouteRegistrationException 存在同名目录时抛出
     */
    private function registerSingleDomainRoutes(ApiDomain $domain): void
    {
        $file = $this->basePath('routes/' . $domain->name . '.php');
        $directory = $this->basePath('routes/' . $domain->name);

        if (is_dir($directory)) {
            throw new RouteRegistrationException(sprintf(
                'routing_mode=single，但存在目录 routes/%s/。'
                . '两种组织方式只能二选一：删掉目录，或把 config/api.php 的 routing_mode 改为 directory。',
                $domain->name
            ));
        }

        if (!is_file($file)) {
            // 域声明了但还没写路由文件，允许（渐进式开发）
            return;
        }

        $registrar = require $file;

        if (!is_callable($registrar)) {
            throw new RouteRegistrationException(sprintf(
                '路由文件必须 return 一个闭包：routes/%s.php',
                $domain->name
            ));
        }

        $this->router->group($this->domainGroupAttributes($domain), static function (Router $router) use ($registrar): void {
            $registrar($router);
        });
    }

    /**
     * directory 模式：routes/{域名}/*.php + routes/{域名}/{版本}/*.php。
     *
     * 文件名不进 URL——根目录下的文件直接挂在 /{域前缀} 下，
     * 版本子目录额外加 /{版本} 前缀。
     *
     * @param ApiDomain $domain 域定义
     * @return void
     * @throws RouteRegistrationException 结构不合规时抛出
     */
    private function registerDirectoryDomainRoutes(ApiDomain $domain): void
    {
        $file = $this->basePath('routes/' . $domain->name . '.php');
        $directory = $this->basePath('routes/' . $domain->name);

        if (is_file($file)) {
            throw new RouteRegistrationException(sprintf(
                'routing_mode=directory，但存在单文件 routes/%s.php。'
                . '两种组织方式只能二选一：删掉文件，或把 config/api.php 的 routing_mode 改为 single。',
                $domain->name
            ));
        }

        if (!is_dir($directory)) {
            throw new RouteRegistrationException(sprintf(
                'routing_mode=directory，但缺少目录 routes/%s/。'
                . '要么创建它并放入路由文件，要么把这个域从 config/api.php 的 domains 里去掉。',
                $domain->name
            ));
        }

        // 版本目录必须已在 domains.{name}.versions 里声明
        $this->assertVersionDirectoriesDeclared($domain, $directory);

        $this->router->group($this->domainGroupAttributes($domain), function (Router $router) use ($domain, $directory): void {
            /*
             * withoutGroupPrefix 只包住「执行子文件」这一步，不包框架自己的 group。
             *
             * 否则框架给版本目录加的 group(['prefix' => $version]) 也会被拦下——
             * 这个 bug 是实测发现的（第一版写反了，directory 模式直接起不来）。
             *
             * 用标志位而不是「传受限注册器」：文件里可能用 Route 门面（静态调用），
             * 只限制实例参数挡不住。
             */

            // 1) 根目录下的文件：无版本前缀
            $router->withoutGroupPrefix(function () use ($router, $directory): void {
                foreach ($this->routeFilesIn($directory) as $file) {
                    $this->requireRouteFile($router, $file);
                }
            });

            // 2) 已声明的版本目录：框架加 /{版本} 与 {版本}.，文件里禁止再加
            foreach ($domain->versions as $version) {
                $versionDir = $directory . '/' . $version;

                if (!is_dir($versionDir)) {
                    // 声明了版本但还没建目录，允许（渐进式开发）
                    continue;
                }

                $router->group(['prefix' => $version, 'as' => $version . '.'], function (Router $scoped) use ($versionDir): void {
                    $scoped->withoutGroupPrefix(function () use ($scoped, $versionDir): void {
                        foreach ($this->routeFilesIn($versionDir) as $file) {
                            $this->requireRouteFile($scoped, $file);
                        }
                    });
                });
            }
        });
    }

    /**
     * 域名已被声明的版本目录，反查配置。
     *
     * @param ApiDomain $domain 域定义
     * @param string $directory 域目录
     * @return void
     * @throws RouteRegistrationException 存在未声明的版本目录时抛出
     */
    private function assertVersionDirectoriesDeclared(ApiDomain $domain, string $directory): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!is_dir($directory . '/' . $entry)) {
                continue;
            }

            if (!in_array($entry, $domain->versions, true)) {
                throw new RouteRegistrationException(sprintf(
                    'routes/%s/%s/ 不是已声明的版本。'
                    . '请在 config/api.php 的 domains.%s.versions 里加上 [%s]，或删掉这个目录。'
                    . '当前已声明：%s',
                    $domain->name,
                    $entry,
                    $domain->name,
                    $entry,
                    $domain->versions === [] ? '（空）' : implode(', ', $domain->versions)
                ));
            }
        }
    }

    /**
     * 域级 group 的属性。
     *
     * @param ApiDomain $domain 域定义
     * @return array<string, mixed> group 属性
     */
    private function domainGroupAttributes(ApiDomain $domain): array
    {
        return [
            'prefix' => $domain->prefix,
            'as' => $domain->name . '.',
            'middleware' => $domain->middleware,
            // 让路由记住自己属于哪个域，供中间件别名解析使用
            'domain' => $domain,
        ];
    }

    /**
     * 目录下的路由文件（字母序）。
     *
     * 显式 sort 而不是依赖 glob 的返回顺序：
     * 文件加载顺序会影响「静态路由 vs 动态路由」谁先注册，
     * 字母序至少是可预测的（重命名会改变顺序，文档里说明）。
     *
     * @param string $directory 目录
     * @return list<string> 文件路径
     */
    private function routeFilesIn(string $directory): array
    {
        $files = glob($directory . '/*.php') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * 加载一个路由文件。
     *
     * @param Router $router 路由器
     * @param string $file 文件路径
     * @return void
     * @throws RouteRegistrationException 文件没返回闭包时抛出
     */
    private function requireRouteFile(Router $router, string $file): void
    {
        /*
         * 两种写法都支持：
         *
         * 1) 直接写路由（推荐，更简洁）
         *      Route::get('/posts', [C::class, 'index'], 'posts');
         *
         * 2) return 一个闭包（单文件模式必须这样，因为框架要判断内容）
         *      return function (Router $router): void { ... };
         *
         * 目录模式支持第 1 种：require 时文件立即执行，Route 门面已经注册到
         * 当前 Router 上，不需要再传实例进去。
         */
        $registrar = require $file;

        if (is_callable($registrar)) {
            $registrar($router);
        }
    }

    /**
     * 注册 App\ 命名空间到项目根 app/ 目录。
     *
     * 框架位于项目子目录时（如 sunsetreverie/），框架自身无法推断项目位置，
     * 因此由 Application 在引导时注册，而不是写死在 autoload.php 里。
     *
     * @return void
     */
    private function registerAppNamespace(): void
    {
        $appPath = $this->basePath('app');

        if (!is_dir($appPath)) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($appPath): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }

            $file = $appPath . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }

    /**
     * 是否优先使用编译缓存（仅生产环境）。
     *
     * 开发环境忽略缓存，避免「改了路由/配置却不生效」的困惑。
     *
     * @return bool 使用缓存返回 true
     */
    private function shouldPreferCache(): bool
    {
        return Env::string('APP_ENV', 'local') === 'production';
    }

    /**
     * 当前运行环境（local / test / staging / production）。
     *
     * @return string 环境名
     */
    public function environment(): string
    {
        return Env::string('APP_ENV', 'local');
    }

    /**
     * 注册请求结束回调。
     *
     * @param Closure(Application): void $callback 回调
     * @return void
     */
    public function terminating(Closure $callback): void
    {
        $this->terminatingCallbacks[] = $callback;
    }

    /**
     * 请求收尾：逆序执行终止回调。
     *
     * 由入口在**响应发送之后**调用（`Response::send()` 之后），
     * 这样回调里的失败不会影响已经发出的响应。
     *
     * @return void
     */
    public function terminate(): void
    {
        foreach (array_reverse($this->terminatingCallbacks) as $callback) {
            try {
                $callback($this);
            } catch (Throwable) {
                // 收尾阶段的异常不应影响已完成的响应
            }
        }
    }

    /**
     * 是否处于调试模式。
     *
     * @return bool 调试返回 true
     */
    private function isDebug(): bool
    {
        return Env::bool('APP_DEBUG', false);
    }

    /**
     * 注册内置路由。
     *
     * @return void
     */
    private function registerDefaultRoutes(): void
    {
        $this->router->get('/health', [HealthController::class, 'index'], 'framework.health');

        // 私有文件访问入口（签名 URL 的验证端，见 upload.signedUrl）
        $this->router->get('/files', [FileController::class, 'show'], 'framework.file');
    }

    /**
     * 调用处理器。
     *
     * @param mixed $handler 处理器（Closure / [class, method] / 'class@method'）
     * @param array<string, string> $params 路由参数
     * @param Request $request 当前请求
     * @return mixed 处理器返回值
     * @throws HttpException 处理器不可调用时抛出
     */
    private function invoke(mixed $handler, array $params, Request $request): mixed
    {
        [$target, $reflection] = $this->resolveHandler($handler);
        $arguments = $this->resolveArguments($reflection->getParameters(), $params, $request);

        return $target(...$arguments);
    }

    /**
     * 把处理器解析成可调用对象与反射信息。
     *
     * @param mixed $handler 处理器（Closure / [class, method] / 'class@method'）
     * @return array{0: callable, 1: \ReflectionFunctionAbstract} 目标与反射
     * @throws HttpException 处理器不可调用时抛出
     */
    private function resolveHandler(mixed $handler): array
    {
        if ($handler instanceof Closure) {
            return [$handler, new \ReflectionFunction($handler)];
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = is_object($class) ? $class : $this->container->get((string) $class);

            return $this->reflectMethod($instance, (string) $method);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            return $this->reflectMethod($this->container->get($class), $method);
        }

        throw new HttpException(500, ErrorCode::InternalError->value, '路由处理器不可调用');
    }

    /**
     * 反射控制器方法并校验可调用性。
     *
     * @param object $instance 控制器实例
     * @param string $method 方法名
     * @return array{0: callable, 1: \ReflectionMethod} 目标与反射
     * @throws HttpException 方法不存在或不可调用时抛出
     */
    private function reflectMethod(object $instance, string $method): array
    {
        if (!method_exists($instance, $method)) {
            throw new HttpException(500, ErrorCode::InternalError->value, sprintf(
                '控制器方法 [%s::%s] 不存在',
                $instance::class,
                $method
            ));
        }

        $reflection = new \ReflectionMethod($instance, $method);

        if (!$reflection->isPublic()) {
            throw new HttpException(500, ErrorCode::InternalError->value, sprintf(
                '控制器方法 [%s::%s] 必须是 public',
                $instance::class,
                $method
            ));
        }

        /*
         * 用 getClosure 而不是 [[$obj, $method]] 或 Closure::fromCallable：
         * - 前者是数组，静态分析不认作 callable
         * - Closure::fromCallable 要求 callable()（无参签名），而控制器方法有参数
         * getClosure 直接返回绑定好的 Closure，类型正确且无需额外断言。
         */
        return [$reflection->getClosure($instance), $reflection];
    }

    /**
     * 按类型与名称解析处理器参数。
     *
     * 解析优先级：
     * 1. 类类型：Request 注入当前请求，其余从容器解析（支持任意服务）；
     * 2. 与路由参数同名的标量：取路由参数并做类型转换；
     * 3. 参数默认值；
     * 4. 可空参数注入 null；
     * 5. 否则报错（不猜测）。
     *
     * @param list<\ReflectionParameter> $parameters 参数列表
     * @param array<string, string> $params 路由参数
     * @param Request $request 当前请求
     * @return list<mixed> 实参数组
     * @throws HttpException 无法解析时抛出
     */
    private function resolveArguments(array $parameters, array $params, Request $request): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $class = $type->getName();

                if ($class === Request::class || $request instanceof $class) {
                    $arguments[] = $request;

                    continue;
                }

                // FormRequest 子类：实例化后立即校验，失败直接 422
                if (is_subclass_of($class, FormRequest::class)) {
                    /** @var FormRequest $form */
                    $form = $this->container->get($class);
                    $form->validateResolved($request, $this->validator());
                    $arguments[] = $form;

                    continue;
                }

                $arguments[] = $this->container->get($class);

                continue;
            }

            $name = $parameter->getName();

            if (array_key_exists($name, $params)) {
                $arguments[] = $this->coerceScalar($params[$name], $type);

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new HttpException(500, ErrorCode::InternalError->value, sprintf(
                '无法解析控制器参数 [$%s]：标量参数需与路由参数同名或提供默认值',
                $name
            ));
        }

        return $arguments;
    }

    /**
     * 路由参数类型转换（路由匹配结果永远是字符串）。
     *
     * @param string $value 原始值
     * @param \ReflectionType|null $type 目标类型
     * @return mixed 转换后的值
     */
    private function coerceScalar(string $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            default => $value,
        };
    }

    /**
     * 规范化处理器返回值。
     *
     * @param mixed $result 返回值
     * @return Response 响应实例
     */
    private function normalize(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result === null) {
            return Response::noContent();
        }

        if (is_string($result)) {
            return Response::text($result);
        }

        return $this->success($result);
    }

    /**
     * 获取当前请求 ID（无请求时生成临时值）。
     *
     * @return string 请求 ID
     */
    private function currentRequestId(): string
    {
        // CLI 场景没有当前请求，?-> 必需
        // @phpstan-ignore nullsafe.neverNull
        return $this->currentRequest?->requestId ?? \SunsetReverie\Support\Str::uuid7();
    }
}
