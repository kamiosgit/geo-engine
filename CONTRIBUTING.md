# 参与贡献

感谢你愿意改进 GEO Engine。本文说明本地开发、提交规范与一条**不可协商**的边界。

## 环境

- PHP ≥ 7.4（开发推荐 8.1+）
- WordPress ≥ 6.2（本地调试建议用 `wp-env` 或任意 LAMP/MAMP 环境）
- Composer（仅用于拉取 PHPUnit）

```bash
git clone https://github.com/__GH_USER__/geo-engine.git
cd geo-engine
composer install
vendor/bin/phpunit
```

测试使用 `tests/bootstrap.php` 里的轻量 WordPress 桩，**不需要**完整 WordPress 就能跑；涉及数据库、HTTP 的路径请在真实站点手工验证（见 `README.md` 第十节的验收清单对应关系）。

## 目录约定

| 位置 | 存放内容 |
|---|---|
| `includes/class-*.php` | 一个类一个文件，类名 `GEO_` 前缀 |
| `assets/` | 后台/前台静态资源，无构建步骤 |
| `tests/` | `*Test.php` 会被 PHPUnit 自动发现 |
| `languages/` | `.pot` 与翻译文件 |

新增模块时，请同时把它加入 `includes/class-plugin.php` 的 `load_files()` 列表——插件刻意不使用 autoloader，文件清单本身就是可审计的契约。

## 代码规范

- 遵循 [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)（缩进用 **Tab**，Yoda 条件，`snake_case` 函数名）。
- 所有输出转义（`esc_html` / `esc_attr` / `esc_url`），所有 SQL 走 `$wpdb->prepare`。
- 所有 AJAX 与表单处理必须有 `nonce` + 权限校验。
- 面向用户的新字符串必须使用 `geo-engine` 文本域，并可用 `wp i18n make-pot` 重新生成模板。
- 不引入前端框架、不使用 CDN 资源、不做远程配置拉取。

## 提交

- 一个 PR 只做一件事；附带对应测试。
- Commit message 用祈使句，首行 ≤ 72 字符，例如：
  `Fix empty-content retry loop in FAQ generator`
- PR 描述请说明：动机、行为变化、如何验证（命令或步骤）。

## 安全

- **绝不提交任何真实 API Key**、站点凭证、`wp-config.php`。
- 若发现安全漏洞，请不要开公开 issue，直接通过 <https://geo.kamibaby.com> 联系维护者。
- 涉及 `class-llm-client.php`（端点策略）或 `class-key-vault.php`（加密）的改动，请特别说明威胁模型。

## 许可证边界（重要）

本项目是 clean-room 实现，采用 GPL-2.0-or-later。提交代码即表示你同意以同一许可证发布。

**请不要**从任何第三方 GEO/AEO 插件复制代码、注释、文案、函数命名组合或文件结构；也不要提交带有他人品牌、推广链接或授权校验逻辑的代码。功能想法、短代码格式、schema 结构与验收标准属于公开的接口与事实规范，可以自由参考；具体实现必须自己写。
