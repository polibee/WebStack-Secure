# Changelog

本文件记录 WebStack-Secure 分支相对上游 [owen0o0/WebStack](https://github.com/owen0o0/WebStack)
的改动。版本号继承上游主题 `style.css` 的 `Version` 字段。

## [1.2025-secure.1] — 2026-11-26

首个安全加固版本。修正 3 处代码缺陷，其中 1 处高危（可导致服务器被完全控制）。

### 安全修复

#### 未授权文件上传 / 附件删除（高危）

- **影响**：原版全版本
- **对应 issue**：[#78](https://github.com/owen0o0/WebStack/issues/78)、[#76](https://github.com/owen0o0/WebStack/issues/76)
- **CWE**：CWE-862、CWE-352、CWE-434
- **文件**：`inc/ajax.php`、`inc/register.php`、`template-contribute.php`

原代码将上传/删除接口注册在 `wp_ajax_nopriv_*`（无需登录）上，且上传函数中定义的
扩展名白名单 `$extArr` 从未被使用。未认证攻击者可向 `wp-content/uploads/` 写入
任意扩展名文件（`.php` / `.phtml` / `.phar` 等），在允许 uploads 执行 PHP 的服务器上
可直接获取 webshell。

修复明细：

| 位置 | 原实现 | 修复后 |
|---|---|---|
| 钩子注册 | `wp_ajax_nopriv_img_upload` / `_img_remove` | 移除 nopriv，仅保留登录态钩子 |
| 权限 | 无 | `current_user_can('upload_files')` / `('delete_posts')` |
| CSRF | 无 | `check_ajax_referer()` + 前端注入 nonce |
| 扩展名 | `$extArr` 定义未使用 | `in_array()` 白名单，取 `strtolower()` 后缀 |
| 文件类型 | 信任 `$_FILES['type']` | `getimagesize()` 校验真实图片文件头 |
| 文件搬运 | `rename()` | `move_uploaded_file()` |
| 删除归属 | 任意附件 ID | 校验 `post_author`，管理员除外 |

前端配套改动：`inc/register.php` 向前端 `theme` 对象注入 `nonce_upload` / `nonce_remove`；
`template-contribute.php` 的上传与删除请求同步携带对应 nonce。

#### 投稿接口参数校验缺失

- **文件**：`inc/ajax.php`（`io_contribute()`）
- **说明**：该功能按设计对游客开放，故不加登录限制，改为强化入参校验。
  - `tougao_cat` 未做整数校验即写入分类关系 → 改用 `absint()`
  - `tougao_sites_ico` / `tougao_wechat_qr` 未限制协议 → 改用 `esc_url_raw()` 并强制 http(s)，
    阻断 `javascript:` / `data:` 伪协议存入数据库后被前端直接输出

#### REST API 禁用开关失效

- **影响**：原版全版本
- **对应 issue**：[#69](https://github.com/owen0o0/WebStack/issues/69)、[PR #77](https://github.com/owen0o0/WebStack/pull/77)
- **文件**：`inc/inc.php`

```diff
- add_filter('rest_enabled', '_return_false');
- add_filter('rest_jsonp_enabled', '_return_false');
+ add_filter('rest_enabled', '__return_false');
+ add_filter('rest_jsonp_enabled', '__return_false');
```

回调函数名少一个下划线，WordPress 中并不存在 `_return_false()`，导致主题选项
「禁用 REST API」实际上从未生效，`/wp-json/` 端点始终开放。

### 文档

- README 增加「安全修复」与「升级 / 迁移指南」两节，含 uploads 目录扫描命令与
  nginx 禁止执行 PHP 的配置片段
- 环境要求更正：原写「PHP 5.7+」为笔误（该版本不存在），改为「PHP 7.0+」，
  并补充 PHP 8.5 下的验证结论（74 个 PHP 文件语法检查全部通过）

### 核实但未改动的问题

以下 issue 经代码核查确认**不属于代码缺陷**，未做修改：

| Issue | 现象 | 核查结论 |
|---|---|---|
| #74 | 详情页二维码不显示 | 使用的是第三方 API `api.qrserver.com`，实测返回 HTTP 200 正常。属用户网络环境问题 |
| #29 | 内置图标源获取不到图标 | 默认 API `ico.mikelin.cn` 已停止服务，属第三方服务失效 |
| #55 | 点击跳到外链而非详情页 | 设计如此。`is_go` 选项控制「链接直达」按钮走 `/go/?url=` 跳转 |
| #47 | 菜单分类无法跳到文章 | 设计如此。菜单分类指向首页锚点 `#term-<id>` |
| #65 | 是否支持 PHP 8 | 支持。全项目语法检查通过，无 PHP 8 废弃函数 |
| #27 | 菜单重复出现 | `wp_nav_menu` 仅调用一次，代码层面无重复，需实际环境复现 |

### 未处理

上游 38 个 open issue 中，以下类型未在本分支处理：

- **功能请求**（12 个）：#62 分类显示开关、#53 投稿免审核、#52 手机端两列、
  #51 右侧一级分类、#50 标签、#43 备案信息位置、#42 天气国际化、#38 搜索、
  #24 批量录入、#60 友情链接、#56 自定义搜索引擎、#58 修改 `/site/` 路径
- **使用咨询**（11 个）：属配置与文档范畴，不涉及代码
- **无效内容**（3 个）：#75 正文为 `0000000`、#44 感谢信、
  #70 病毒指控（已核查：主题无远程 JS 加载、无出站请求，其所述现象应为 #78 漏洞被利用的后果）
- **需环境中复现**（3 个）：#67 WordPress 6.6.1 链接属性缺失、#54 字体大小调整位置、
  #66 二级页面导航

### 升级注意

**从原版升级时不要直接覆盖文件。** 先扫描 `wp-content/uploads/` 下是否已有被植入的
可执行文件，确认清理后再替换主题。具体步骤见 README「升级 / 迁移指南」。
