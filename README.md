# GEO Engine

**让 WordPress 站点对 AI 友好。** 一个插件、零外部依赖，为全站内容提供机器可读索引（`llms.txt`、Markdown 端点）、结构化数据（JSON-LD），并可选地用**你自己配置的 LLM** 为每篇内容生成文末 FAQ。

[![CI](https://github.com/__GH_USER__/geo-engine/actions/workflows/ci.yml/badge.svg)](https://github.com/__GH_USER__/geo-engine/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-7.4%2B-777bb4.svg)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/wordpress-6.2%2B-21759b.svg)](https://wordpress.org/)

- 版本：1.0.6
- 许可证：GPL-2.0-or-later
- 环境：WordPress ≥ 6.2，PHP ≥ 7.4（推荐 8.0+，加密模块需 `openssl`）
- 项目主页：<https://geo.kamibaby.com>

---

## 一、三层能力

| 层 | 能力 | 需要 Key |
|---|---|---|
| **A 层** | `/llms.txt`、`/llms-full.txt`、每篇 `.md` 端点、robots 规则、`<link rel="alternate">` | 否 |
| **B 层** | 用自有 LLM 生成 FAQ，写回正文，前台渲染可见手风琴 | 是 |
| **C 层** | `WebSite` / `Organization` / `WebPage` / `Article` / `BreadcrumbList` / `FAQPage` JSON-LD | 否 |

**关键设计**：A 层与 C 层**启用即生效**，存量内容无需逐篇处理；schema 每次前台渲染时从正文实时解析，不落库、无需批处理。FAQ 只追加在文末，**原文其余字符逐字不变**。

---

## 二、安装

### 方式一：上传压缩包（推荐）

1. 后台上传 `geo-engine.zip` → 启用插件。
2. 打开 **设置 → 固定链接**，点一次「保存更改」（刷新 rewrite 规则；启用插件时已自动执行一次，若 `/llms.txt` 404 请手动再点一次）。
3. 访问 `https://你的域名/llms.txt`，应看到纯文本索引。
4. 进入 **设置 → GEO Engine** 开始配置。

### 方式二：从源码

```bash
cd wp-content/plugins
git clone https://github.com/__GH_USER__/geo-engine.git
```

然后在后台启用 **GEO Engine**。源码目录名必须是 `geo-engine`，否则插件内的相对路径会失效。

> 若站点使用「朴素」固定链接（`?p=123`），端点会退化为 `/?geo_engine_feed=llms` 形式。**建议开启伪静态**，`llms.txt` 才能位于根路径。

---

## 三、三步跑通

### 1. 常规设置

- **启用的内容类型**：默认 `post` + `page`，可勾选自定义文章类型。
- **每篇 FAQ 条数**：2–5，默认 3。少于 2 条不会写回（FAQPage 需要多条目才完整）。
- **最短正文长度**：默认 200 字，过短的内容直接跳过。
- **AI 爬虫规则**：六大爬虫逐项 `Allow` / `Disallow`，默认全部 Allow。

### 2. LLM 配置

选择服务商 → 填 API Key → 点「测试连接」→ 保存。

| 预设 | API Base | 默认模型 | 协议 |
|---|---|---|---|
| 硅基流动 SiliconFlow | `https://api.siliconflow.cn/v1` | `deepseek-ai/DeepSeek-V3.2` | openai-chat |
| DeepSeek | `https://api.deepseek.com/v1` | `deepseek-chat` | openai-chat |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` | openai-chat |
| 月之暗面 Moonshot | `https://api.moonshot.cn/v1` | `moonshot-v1-8k` | openai-chat |
| 智谱 GLM | `https://open.bigmodel.cn/api/paas/v4` | `glm-4-flash` | openai-chat |
| 阿里通义（兼容模式） | `https://dashscope.aliyuncs.com/compatible-mode/v1` | `qwen-plus` | openai-chat |
| Anthropic Claude | `https://api.anthropic.com/v1` | `claude-3-5-haiku-latest` | anthropic-messages |
| 本地 Ollama | `http://localhost:11434/v1` | `qwen2.5:7b` | openai-chat |
| 自定义 / 自建代理 | 自填 | 自填 | openai-chat |

**要点**

- **模型名可自由输入**，不受预设限制；服务商改名或上架新模型都不需要改代码。
- 预设的端点与模型名是**起点**，请以服务商官方文档为准。
- 切到 Anthropic 时协议自动变为 `anthropic-messages`，并附带 `anthropic-version: 2023-06-01` 请求头；该协议**不支持 `response_format`**，JSON 由提示词与解析器保证。
- 用 Ollama 等本地服务时，需勾选「允许本地 / 内网端点」，否则会被安全策略拦截；密钥可留空。
- 用自建代理时，需勾选「允许预设白名单之外的域名」。
- 「响应格式策略」若设为 `auto`/`force` 后服务商返回 400 并提示不支持 `response_format`，改为 `off` 即可。

### 3. 生成 FAQ

- **单篇**：文章列表行内「生成 GEO FAQ」，或编辑页侧边栏「GEO FAQ」框。
- **自动**：常规设置里开启「新内容首次发布时自动生成 FAQ」，发布后延迟 10 秒在后台执行，不阻塞发布。
- **批量**：批量生成页设置每批篇数与间隔，点「开始」，进度实时更新；刷新或关掉浏览器都不会丢进度，可「继续」。

**跳过规则**（不会浪费 token）

| 情况 | 原因码 |
|---|---|
| 正文已有 ≥2 条 FAQ | `has_faq` |
| 检测到 Yoast / Rank Math / AIOSEO 的 FAQ 区块 | `seo_plugin` |
| 正文短于阈值 | `too_short` |
| 该内容类型未启用 | `post_type` |
| 该篇正在处理中 | `locked` |

---

## 四、API Key 的存储方式

- 数据库中存的是 **AES-256-CBC 密文**（`openssl_encrypt`，每次随机 IV，密文+IV 后 base64）。
- 加密密钥**不入库**：由 `wp-config.php` 中已有的 `LOGGED_IN_KEY` / `LOGGED_IN_SALT` / `AUTH_KEY` 等常量经 `sha256` 派生。把数据库拷到别处也无法还原明文。
- 页面与 AJAX 响应中，Key 恒为掩码（前 4 位 + `••••` + 后 4 位），**明文永不回显**。
- 若服务器没有 `openssl` 扩展，插件仍可运行，但会降级为 base64 可逆编码，并在设置页显示醒目警告——**这一降级不会被静默处理**。
- 迁移站点后若 `wp-config.php` 的盐值变化，需要重新填写 Key。

**导出/导入**：导出的 JSON 中 `api_key` 恒为空，只带服务商与参数，便于多站点部署时逐站填 Key。

---

## 五、FAQ 短代码规范

```text
[geo_faqs]
[geo_faq q="问题一？"]答案文本[/geo_faq]
[geo_faq q="问题二？"]答案文本[/geo_faq]
[/geo_faqs]
```

自研格式规则（与任何第三方插件无关）：

1. 属性名固定为 `q`，值用英文双引号或单引号包裹；
2. `[geo_faq` 后必须有空格再接属性；
3. 答案去除标签与短代码后必须非空；
4. **每篇至少 2 条 item**，不足则不写回；
5. 全篇**恰好一对 wrapper**；已有 wrapper 时新条目插入 `[/geo_faqs]` 之前，绝不新建第二对；
6. Gutenberg 文章会用 `<!-- wp:shortcode -->` 包裹；
7. 不要手动加 `<h2>常见问题</h2>`，前台渲染器会输出标题并对重复做去重；
8. 问题中的 `"`、`[`、`]` 会被转义为实体存储，避免破坏解析。

前台输出为原生 `<details>/<summary>` 手风琴，**不依赖 JavaScript**，爬虫可直接读取；样式可通过「加载插件自带的 FAQ 轻量样式」关闭以适配主题。

---

## 六、机器可读端点

| 端点 | 内容 |
|---|---|
| `/llms.txt` | 站点名、描述、按内容类型分组的全部已发布内容（**无条数上限**），每条含标题、链接、一句描述 |
| `/llms-full.txt` | 同上，每条附正文纯文本（默认 1500 字，条数上限默认 500，超限会注明截断） |
| `/geo-md/{post_type}/{slug}.md` | 单篇 Markdown：标题、URL、时间、作者、正文 Markdown、FAQ 段落 |
| `/robots.txt` | 追加 AI 爬虫 `Allow`/`Disallow` 段 |

`/llms.txt` 与 `/llms-full.txt` 带 1 小时 transient 缓存，发布/删除/改设置时自动失效，命中缓存响应通常在 100ms 内。

页面 `<head>` 会为每篇内容输出：

```html
<link rel="alternate" type="text/markdown" href="https://example.com/geo-md/post/hello.md" />
```

---

## 七、结构化数据

单个 `<script type="application/ld+json">`，用 `@graph` + `@id` 组织：

- `WebSite`（含 `SearchAction`）、`Organization`（可填名称、Logo、`sameAs`）
- `WebPage`、`Article`/`BlogPosting`、`BreadcrumbList`
- `FAQPage`：条目数 = 正文中合法 item 数，无合法 item 则不输出

**与 SEO 插件共存**：检测到 Yoast / Rank Math / AIOSEO / SEOPress 时，默认策略是**让出同类 schema**（避免重复），只补它们没有的部分；每种类型都可在设置页改为「强制输出」或「关闭」。含 SEO 插件 FAQ 块的文章，B 层会直接跳过，避免两套 FAQ 打架。

---

## 八、数据与卸载

| 存储 | 键名 |
|---|---|
| 设置 | `geo_engine_settings` |
| 密钥密文 | `geo_engine_api_key_cipher` |
| 批量进度 | `geo_engine_batch_state` |
| 日志表 | `{prefix}geo_engine_log` |
| Post meta | `_geo_engine_faq_generated`、`_geo_engine_faq_count`、`_geo_engine_generated_at`、`_geo_engine_last_error`、`_geo_engine_skip_reason` |

**删除插件时**：设置、日志表、post meta、transient、定时任务全部清除；正文中的 FAQ 短代码**默认保留**（属站点内容资产）。若在设置页勾选「同时从所有文章正文中移除已生成的 FAQ 区块」，卸载时会一并清理。

日志表只记录 `post_id / action / status / message / tokens`，**不记录 Key、请求头、正文**，并对疑似密钥串做脱敏，最多保留 5000 行。

---

## 九、安全说明

- 不请求任何远程配置，不内置更新器，不含任何第三方推广链接或统计代码，代码中**没有任何硬编码凭证**。
- 所有 AJAX / 表单操作：`nonce` 校验 + 权限校验（生成类 `edit_posts`，设置类 `manage_options`）。
- 所有数据库写入走 `$wpdb->prepare`，所有输出转义。
- **SSRF 防护**：`api_base` 必须 `https`；预设服务商在 host 白名单内；本地/内网地址与自定义域名需显式开关；URL 中不允许携带账号密码；`extra_headers` 会丢弃 `Host`/`Content-Length`/`Transfer-Encoding` 等危险头；请求不跟随重定向。
- 正文摘录只发送给**管理员自己配置**的服务商。

---

## 十、开发

```bash
git clone https://github.com/__GH_USER__/geo-engine.git
cd geo-engine
composer install
vendor/bin/phpunit
```

测试用轻量 WordPress 桩（`tests/bootstrap.php`），不需要完整 WP 环境，也不需要数据库，覆盖短代码解析、JSON 容错、条数校验、去重、加密往返、两个协议驱动的请求构造与响应解析、写回插入/替换、A 层格式、端点安全策略、schema 构造、批量进度与断点续跑等 **109 个用例**。CI 会在 PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3 上各跑一遍。

```
geo-engine/
├── geo-engine.php
├── uninstall.php
├── includes/
│   ├── class-plugin.php            模块装配
│   ├── class-activator.php         激活/停用/升级
│   ├── class-settings.php          设置框架与四个标签页
│   ├── class-settings-llm.php      LLM 配置页、预设、导入导出
│   ├── class-key-vault.php         Key 加解密
│   ├── class-llm-driver-interface.php
│   ├── class-driver-base.php       共享鉴权与错误映射
│   ├── class-driver-openai-chat.php
│   ├── class-driver-anthropic.php
│   ├── class-llm-client.php        门面：策略、重试、退避
│   ├── class-faq-generator.php     输入构建、提示词、解析、校验
│   ├── class-shortcode.php         短代码与前台渲染
│   ├── class-writeback.php         写回与递归保护
│   ├── class-automation.php        手动/自动触发、meta box
│   ├── class-schema.php            JSON-LD 与冲突避让
│   ├── class-llms-feed.php         llms.txt / .md 端点
│   ├── class-robots.php            robots 与 alternate link
│   └── class-bulk.php              批量与断点续传
├── assets/{css,js}
├── languages/
└── tests/
```

---

## 十一、常见问题

**访问 `/llms.txt` 返回 404**
去「设置 → 固定链接」保存一次；确认站点不是「朴素」固定链接；若用了 CDN/反向代理，确认没有拦截 `.txt`。

**测试连接报 401 / 403**
Key 无效或该 Key 没有所选模型的权限；部分服务商需先实名或充值。

**生成报「返回了空内容」**
推理类模型（R1、思考版 GLM 等）会把 token 先用在思考上：关闭「思考模式」，或换非推理模型，或调高 `max_tokens`。

**提示 `response_format` 不支持**
把「响应格式策略」改为 `off`，插件会改用提示词强约束 + 容错解析器。

**和 Yoast 一起用会不会重复 schema**
不会。默认会自动让出 `Article`/`WebPage`/`Breadcrumb`/`Organization`/`WebSite`，只补 `FAQPage`。想让本插件接管，把对应类型改为「强制输出」，同时关掉 SEO 插件里的同类输出。

**卸载插件后 FAQ 还在正文里？**
这是默认行为（内容属站点资产）。可在卸载前勾选「同时移除已生成的 FAQ 区块」。

**能用在多站点吗**
可以。每个站点的配置独立存储在各自的 options 中；删除插件时会遍历所有子站清理。

---

## 十二、许可证与来源声明

GPL-2.0-or-later，全文见 `LICENSE`。

本插件为 **clean-room 重写**：功能范围、短代码格式、schema 结构与验收标准参考了公开的产品资料与 schema.org / llms.txt 通行规范，**代码全部独立实现**，不含任何第三方 GEO 插件的源码、注释、文案或品牌元素。文中提及的第三方产品名称仅用于说明兼容性，商标归各自所有者。
