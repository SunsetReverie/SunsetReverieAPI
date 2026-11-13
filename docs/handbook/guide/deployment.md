# 部署

## 目录结构（生产）

```text
/var/www/myapp/
├── .env                  ← 600 权限，不进 git
├── app/
├── bin/sr
├── config/
├── database/
├── public/               ← Nginx 的 root 指向这里
│   └── index.php         唯一入口
├── routes/
├── storage/              ← 必须可写
│   ├── cache/
│   ├── compiled/
│   ├── logs/
│   └── uploads/
└── sunsetreverie/        ← 框架（submodule 或复制）
```

**关键**：`root` 指向 `public/`，不是项目根。

这样 `.env`、`core/`、`storage/` 都无法通过 URL 直接访问。

## Nginx

```nginx
server {
    listen 80;
    server_name api.example.com;

    # 必须指向 public/
    root /var/www/myapp/public;
    index index.php;

    charset utf-8;
    client_max_body_size 10m;      # 与 config/upload.php 的 max_size 对齐

    # 单入口：所有不存在的路径都交给 index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # 透传真实 IP（限流按 IP 计数，不传的话全是 127.0.0.1）
        fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
        fastcgi_param HTTP_X_REAL_IP $remote_addr;

        # Authorization 头默认不透传，JWT 拿不到
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    }

    # 上传目录：禁止执行 PHP
    location ^~ /uploads/ {
        try_files $uri =404;

        location ~ \.php$ {
            deny all;
        }
    }

    # 静态资源缓存
    location ~* \.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2)$ {
        expires 30d;
        access_log off;
    }

    # 隐藏文件（.env、.git）
    location ~ /\. {
        deny all;
    }
}
```

::: danger 三个必须做的
1. **`root` 指向 `public/`** —— 否则 `.env` 能被下载，数据库密码泄露
2. **`/uploads/` 里 `deny all` PHP** —— 否则上传一个 `.php` 就能执行
3. **透传 `Authorization` 头** —— 否则所有需要登录的接口返回 401

第 3 条尤其容易漏，且症状诡异：**本地好好的，线上全 401**。
:::

### HTTPS

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

    ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    # 其余同上
}

server {
    listen 80;
    server_name api.example.com;
    return 301 https://$host$request_uri;
}
```

**纯 API 也必须上 HTTPS**——Bearer Token 明文传输等于没有认证。

## PHP-FPM

```ini
; /etc/php/8.5/fpm/pool.d/www.conf
[www]
user = www-data
group = www-data

; 进程数：按内存算
; 每个 PHP 进程约占 30-50MB
pm = dynamic
pm.max_children = 20        ; 2GB 内存的机器
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 8
pm.max_requests = 500       ; 防内存泄漏

; 超时
request_terminate_timeout = 30s

; 慢日志（排查性能问题用）
request_slowlog_timeout = 5s
slowlog = /var/log/php-fpm-slow.log
```

### `pm.max_children` 怎么算

```text
可用内存 ÷ 单进程占用 = max_children

例：2GB 内存，每进程 40MB
  2 × 1024 ÷ 40 ≈ 51
  留 30% 余量 → 35

但还要看数据库连接池上限——20 个进程各开 1 个连接，DB 要能撑 20+。
```

**设太小**：请求排队，`502`。
**设太大**：内存耗尽，OOM Killer 杀进程。

### php.ini

```ini
memory_limit = 256M
max_execution_time = 30
post_max_size = 12M
upload_max_filesize = 10M
opcache.enable = 1
opcache.validate_timestamps = 0    ; 生产关闭，改代码后手动 reload
```

::: warning `opcache.validate_timestamps = 0`
性能最好，但**改了代码不生效**——必须：

```bash
killall -USR2 php-fpm
```

忘了这步，会出现「代码明明改了，线上还是旧行为」。
:::

三者的大小关系：

```text
post_max_size ≥ upload_max_filesize ≥ 框架的 max_size
     12M      ≥        10M          ≥        10M
```

`client_max_body_size`（Nginx）要 ≥ `post_max_size`。

## 队列 worker

用 supervisor 托管：

```ini
; /etc/supervisor/conf.d/queue-worker.conf
[program:queue-worker]
command=php /var/www/myapp/bin/sr queue:work
directory=/var/www/myapp
user=www-data
autostart=true
autorestart=true
numprocs=2
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/queue-worker.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl status
```

::: warning worker 是常驻进程，不会自动加载新代码
改完任务类必须重启：

```bash
supervisorctl restart queue-worker
```

这和 `opcache` 是同一类坑——**改了没生效**，但原因在另一个地方。
:::

`numprocs=2` 意味着两个 worker 并发消费。队列组件的令牌抢占机制保证同一个任务不会被取两次。

## 定时任务

```cron
# 每分钟跑一次调度器
* * * * * cd /var/www/myapp && php bin/sr schedule:run >> /dev/null 2>&1

# 每天凌晨清理过期分片
0 3 * * * cd /var/www/myapp && php bin/sr upload:prune >> /dev/null 2>&1
```

::: danger crontab 里的 `cd` 不能少
`bin/sr` 靠**当前工作目录**定位项目（读 `.env`、`config/`）。

不 `cd` 的话会读不到配置，命令报错或用了默认值。
:::

用 `www-data` 的 crontab：

```bash
sudo crontab -u www-data -e
```

## 权限

```bash
# 代码归 root，Web 用户只读
chown -R root:www-data /var/www/myapp
find /var/www/myapp -type d -exec chmod 755 {} \;
find /var/www/myapp -type f -exec chmod 644 {} \;

# storage 可写
chown -R www-data:www-data /var/www/myapp/storage
chmod -R 775 /var/www/myapp/storage

# .env 只有 owner 能读
chmod 600 /var/www/myapp/.env
chown root:www-data /var/www/myapp/.env
```

::: warning `.env` 权限 600 + 属主 root
`www-data` 读不到 `.env`？

PHP-FPM 进程需要读它。两个选择：

| 做法 | 说明 |
| --- | --- |
| `chown www-data:www-data .env && chmod 600` | 进程可读，其他用户不可读 |
| 用环境变量注入（Docker / K8s） | 不落盘，最安全 |

`chmod 644` 是**错的**——同机器其他用户都能读到数据库密码。
:::

## 部署流程

```bash
cd /var/www/myapp

# 1. 拉代码
git pull

# 2. 依赖（如果有 submodule）
git submodule update --init --recursive

# 3. 环境检查
php bin/sr env:check

# 4. 数据库迁移
php bin/sr migrate

# 5. 清缓存 + 重建（顺序不能反）
php bin/sr optimize:clear
php bin/sr optimize

# 6. 重载 PHP
killall -USR2 php-fpm

# 7. 重启 worker（如果改了 Job）
supervisorctl restart queue-worker

# 8. 确认
php bin/sr about
curl -I https://api.example.com/health
```

::: tip 为什么先 `optimize:clear` 再 `optimize`
`optimize` 会生成配置和路由缓存。如果直接覆盖，旧缓存可能引用已被删除的类
——报「类找不到」，而代码明明在。

先清后建，永远不会踩这个。
:::

## 健康检查

框架内置 `/health`：

```bash
curl https://api.example.com/health
```

```json
{
  "code": 0,
  "message": "success",
  "data": {
    "status": "healthy",
    "version": "1.0.0-beta.2.4",
    "time": "2026-10-31T15:23:01+08:00"
  }
}
```

三个字段：`status`（固定 `healthy`）、`version`（框架版本）、`time`（ISO 8601）。

适合放在负载均衡的健康检查里。

**不检查数据库**——它只确认「PHP 跑得起来、路由注册成功」。
要检查 DB 连通性，得自己写一个接口。

## 检查清单

上线前逐条确认：

| # | 项 | 怎么验 |
| --- | --- | --- |
| 1 | `APP_ENV=production` | `php bin/sr about` |
| 2 | `APP_DEBUG=false` | `env:check` 会报 |
| 3 | `APP_KEY` 已设置 | `env:check` 会报 |
| 4 | `JWT_SECRET` 已设置 | 查 `.env` |
| 5 | `MAIL_MAILER=smtp` | 默认是 `log`，不会真发 |
| 6 | `LOG_LEVEL` 不是 `debug` | 否则写满磁盘 |
| 7 | `opcache` 已开启 | `php -i \| grep opcache.enable` |
| 8 | `storage/` 可写 | `ls -ld storage` |
| 9 | Nginx `root` 指向 `public/` | 访问 `/.env` 应 403/404 |
| 10 | `/uploads/` 禁 PHP | 传个 `.php` 试（应 403） |
| 11 | HTTPS 已配 | `curl -I http://` 应 301 |
| 12 | cron 已配 | `crontab -l` |
| 13 | worker 在跑 | `supervisorctl status` |
| 14 | `optimize` 已执行 | `ls storage/compiled/` |

::: danger 第 9 条一定要亲手验
```bash
curl https://api.example.com/.env
```

返回 200 就意味着**数据库密码已泄露**。

Nginx 的 `root` 配成项目根（而不是 `public/`）是最常见的部署事故。
:::

## 常见问题

### 502 Bad Gateway

PHP-FPM 没跑，或者 socket 路径不对。

```bash
systemctl status php8.5-fpm
ls -l /run/php/php8.5-fpm.sock
```

Nginx 里的 `fastcgi_pass` 要和实际 socket 一致。

### 所有接口 401

`Authorization` 头没透传。检查 Nginx 配置：

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

### 上传大文件 413

三层都要改：

| 层 | 配置 |
| --- | --- |
| Nginx | `client_max_body_size` |
| PHP | `upload_max_filesize` / `post_max_size` |
| 框架 | `config/upload.php` 的 `max_size` + `HTTP_MAX_BODY_SIZE` |

### 改了代码不生效

按顺序查：

```bash
php bin/sr config:clear       # 配置缓存
php bin/sr route:clear        # 路由缓存
killall -USR2 php-fpm         # opcache
supervisorctl restart queue-worker   # 如果改的是 Job
```

四个地方都会缓存，**逐个排除**。

### 日志写不进去

```bash
ls -ld storage/logs
sudo -u www-data touch storage/logs/test.log
```

`www-data` 必须能写。注意 cron 跑的命令用的是另一个用户。

---

## 下一步

- **[命令行](./commands)** —— 部署流程里用到的命令
- **[配置](./config)** —— 生产必改的 6 项
- **[日志](./log)** —— 上线后怎么看问题
