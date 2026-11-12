<div align="center">

# 夕梦 · SunsetReverie

**一个人的技术栈：零依赖后端框架 + 轻量前端组件库**

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![Vue](https://img.shields.io/badge/Vue-3.4%2B-4FC08D?logo=vuedotjs&logoColor=white)](https://vuejs.org/)
[![Nuxt](https://img.shields.io/badge/Nuxt-4.x-00DC82?logo=nuxtdotjs&logoColor=white)](https://nuxt.com/)
[![License](https://img.shields.io/badge/license-MIT-blue)](https://github.com/SunsetReverie/SunsetReverie/blob/main/LICENSE)

把「一个人能维护」当成硬约束，而不是事后妥协

</div>

---

## 项目

| 项目 | 定位 | 规模 | 入口 |
| --- | --- | --- | --- |
| **SunsetReverie** | 零依赖 PHP 微服务框架 | PHP 8.3+ · 运行时 0 依赖 · 399 个测试 | [仓库](https://github.com/SunsetReverie/SunsetReverie) · [文档](https://sunsetreverie.github.io/SunsetReverie/) |
| **SunsetReverie-UI** | Vue 3 / Nuxt 4 组件库 | 71 个组件 · 1280 个测试 · 全量 312KB gzip | [仓库](https://github.com/SunsetReverie/SunsetReverieUI) · [文档](https://sunsetreverie.github.io/SunsetReverieUI/) |

---

## SunsetReverie · 零依赖 PHP 微服务框架

不装 Composer 也能跑。多 API 域隔离、组件可替换、没有 ORM —— 为「一个人维护的中型后端」而设计。

```bash
git clone https://github.com/SunsetReverie/SunsetReverie.git SunsetReverie && cd SunsetReverie
php bin/sr new demo && cd demo
php bin/sr serve
```

三行启动。没有 `composer install`，部署不需要 `vendor/`。

**为什么零依赖**：核心 56 个文件 + 10 个组件，全部只用 PHP 内置能力。

```bash
$ grep -r '^use [A-Z]' core/ | grep -v SunsetReverie
（无输出）
```

**为什么把「域」做成一等公民**：PC 站、移动 App、小程序、后台的接口形态不同，业务规则却相同。每个域独立前缀、独立版本、独立中间件，共用同一批领域服务；`web` 域的 token 访问 `admin` 会被直接拒绝。

全部能力见[项目文档](https://sunsetreverie.github.io/SunsetReverie/)。

## SunsetReverie-UI · Vue 3 / Nuxt 4 组件库

71 个组件，三层 Token 驱动主题，玻璃拟态与扁平双风格可切，SSR 首帧不闪白。

```bash
npm i https://github.com/SunsetReverie/SunsetReverieUI/releases/download/v1.0.0-beta.4/sunset-reverie-ui-1.0.0-beta.4.tgz
```

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { SrButton, SrInput } from '@sunset-reverie/ui'

const keyword = ref('')
</script>

<template>
  <SrInput v-model="keyword" placeholder="搜索" />
  <SrButton variant="primary">查询</SrButton>
</template>
```

图表与中国地图是纯 SVG 实现，不引第三方图表库；地图数据独立子路径，不用就不下载。组件清单与用法见[组件文档](https://sunsetreverie.github.io/SunsetReverieUI/components/)。

---

## 取舍

- **零依赖优先于功能齐全**：少一个依赖，就少一次「协作时 lock 文件冲突」
- **可读性优先于抽象层次**：`$this->db->table('posts')->where('status', 1)->get()` 比任何 Query Builder 都直观
- **一个人维护优先于社区热度**：不为假设中的贡献者写文档，只为自己三个月后还能看懂
- **不做大而全**：组件库只封装中后台真正会用的 71 个，框架只覆盖 API 服务

## 技术栈

PHP · MySQL · Redis · Vue 3 · TypeScript · Nuxt 4 · Vite · Less · pnpm

## 链接

- GitHub：[@SunsetReverie](https://github.com/SunsetReverie)
- Gitee：[gitee.com/SunsetReverie](https://gitee.com/SunsetReverie)
- 框架文档：<https://sunsetreverie.github.io/SunsetReverie/>
- 组件库文档：<https://sunsetreverie.github.io/SunsetReverieUI/>

<div align="center">

<sub>代码写给人看，顺便让机器执行</sub>

</div>
