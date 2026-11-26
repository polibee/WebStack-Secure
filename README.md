# WebStack-Secure

WordPress 版 WebStack 导航主题 —— **安全加固分支**。

本仓库 fork 自 [owen0o0/WebStack](https://github.com/owen0o0/WebStack)，在原主题基础上修复了
若干安全漏洞与代码缺陷。**如果你正在使用原版 WebStack，强烈建议换用本分支**：原版存在
未授权文件上传漏洞，可被直接上传 webshell 拿下服务器（详见下方「安全修复」）。

- 原主题演示：<a href="http://webstack.iotheme.cn/">webstack.iotheme.cn</a>
- 上游仓库：<a href="https://github.com/owen0o0/WebStack" target="_blank">owen0o0/WebStack</a>
- 本分支修复记录：<a href="CHANGELOG.md">CHANGELOG.md</a>

<br/>

## ⚠️ 安全修复

### 1. 未授权文件上传（高危，可致服务器被入侵）

**影响版本**：原版 WebStack 全版本
**对应 issue**：[#78](https://github.com/owen0o0/WebStack/issues/78)、[#76](https://github.com/owen0o0/WebStack/issues/76)
**CWE**：CWE-862（缺失授权）、CWE-352（CSRF）、CWE-434（危险类型文件上传）

原 `inc/ajax.php` 把图片上传接口注册在**未登录用户可见**的钩子上：

```php
add_action('wp_ajax_nopriv_img_upload', 'io_img_upload');  // nopriv = 无需登录
```

而上传函数里虽然定义了扩展名白名单 `$extArr`，**却从未使用**。任何人不登录即可向
`wp-content/uploads/` 写入任意扩展名的文件，包括 `.php` / `.phtml` / `.phar`。
若服务器允许 uploads 目录执行 PHP，攻击者可直接上传 webshell 接管服务器。

**修复内容**：

| 修复点 | 说明 |
|---|---|
| 移除 `nopriv` 钩子 | 上传/删除接口要求登录 |
| 权限校验 | `current_user_can('upload_files')` / `delete_posts` |
| Nonce 校验 | `check_ajax_referer()`，防 CSRF |
| 扩展名白名单 | 真正执行 `in_array()` 判断（jpg/png/jpeg/gif/webp） |
| 文件类型校验 | `getimagesize()` 验证真实图片文件头，防伪造 MIME 与双扩展名 |
| 改用 `move_uploaded_file()` | 替代 `rename()`，只搬运经 HTTP 上传的临时文件 |
| 删除接口加归属校验 | 只能删除自己上传的附件，管理员除外 |

### 2. 投稿接口参数校验缺失

**对应**：`io_contribute()`（公开投稿功能，设计上对游客开放）

- `category` 原样取自 `$_POST` 未做整数校验 → 改为 `absint()`
- `sites_ico` / `wechat_qr` 未限制协议，可存入 `javascript:` / `data:` → 限定 http(s)

### 3. REST API 禁用功能失效

**对应 issue**：[#69](https://github.com/owen0o0/WebStack/issues/69)、[PR #77](https://github.com/owen0o0/WebStack/pull/77)

```php
add_filter('rest_enabled', '_return_false');   // 错误：WordPress 无此函数
add_filter('rest_enabled', '__return_false');  // 正确
```

少一个下划线导致主题选项「禁用 REST API」从未生效，`wp-json` 始终可访问。

<br/>

## 升级 / 迁移指南

如果你从原版迁移过来，**不要直接覆盖文件**，按以下顺序操作：

1. 备份数据库与 `wp-content/uploads/` 目录
2. **扫描 uploads 目录下的可执行文件**：

   ```bash
   find wp-content/uploads -type f \( -iname "*.php*" -o -iname "*.pht*" \
     -o -iname "*.phar" -o -iname "*.phps" -o -iname "*.inc" \) -print
   ```

   正常站点这里应该是 0 个文件。有的话先移到隔离目录留证，再删除。
3. 替换主题文件
4. 在 nginx/apache 里禁止 uploads 目录执行 PHP（双保险）

   ```nginx
   location ~* ^/wp-content/uploads/.*\.(php|php[0-9]|phtml?|phar|phps|inc)$ {
       deny all;
       return 403;
   }
   ```

5. 检查 `wp-config.php` 与插件目录有无异常文件

<br/>

### 声明
本分支仅修复代码缺陷，不改变原主题功能与授权方式。使用本主题发布的内容由发布者自行负责。

“Webstack Pro”是作者在19年尝试的一个收费项目，现已不在维护支持，且与<a href="https://github.com/WebStackPage/WebStackPage.github.io" target="_blank">Viggo</a>无关，应当时名字欠缺考虑，给<a href="https://github.com/WebStackPage/WebStackPage.github.io" target="_blank">Viggo</a>带来了不必要的麻烦，在此道歉。<br/>
现今网络上流传的“Webstack Pro”版本均为盗用作者19年的内容，与作者和<a href="https://github.com/WebStackPage/WebStackPage.github.io" target="_blank">Viggo</a>无关。

### 首页截图
<br/>

![Thumbnail_index](https://owen0o0.github.io/ioStaticResources/webstack/01.png)
<br/>

### 环境要求
+ WordPress 4.4+
+ WordPress 伪静态
+ PHP 7.0+（已在 PHP 8.5 上验证：74 个 PHP 文件语法检查全部通过，无废弃函数）
<br/>

### 安装指南
+ 安装 WordPress ，教程百度
+ 设置伪静态（下方规则按自己服务器环境二选一）
```
# Nginx规则
location /
{
    try_files $uri $uri/ /index.php?$args;
}
rewrite /wp-admin$ $scheme://$host$uri/ permanent;

# Apache 规则
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
```
+ WordPress 后台「主题」栏目 -> 上传主题 -> 启用主题，或者在 /wp-content/themes 文件夹新建webstack文件夹，并上传所有文件
+ 果然点击地址出现404，请到WordPress 后台「设置」栏目 -> 固定链接 -> 保存更改
+ 反馈交♂流：<a href="https://www.iowen.cn" target="_blank">一为忆</a>

<br/>

### 主题使用
+ 在 WordPress 后台“网址”文章类型下添加内容
+ 分类最多两级，且父级不要添加内容
+ 可以不添加网址图片，主题会自动获取目标网址的 favicon 图标
+ 导航菜单栏标题前面的图标请在分类图像描述中填入（参考下图），图标样式请参考fontawesome
![Thumbnail_index](https://owen0o0.github.io/ioStaticResources/webstack/02.png)
+ 增加分类快速添加图标的方法
![Thumbnail_index](https://owen0o0.github.io/ioStaticResources/webstack/07.png)
+ 导航菜单栏下方可以添加自定义菜单，在后台的外观-->菜单里设置，在菜单的css类添加图标（参考下图），图标样式请参考fontawesome
![Thumbnail_index](https://owen0o0.github.io/ioStaticResources/webstack/03.png)
+ 如果菜单里没有css类，请按下图添加
![Thumbnail_index](https://owen0o0.github.io/ioStaticResources/webstack/04.jpg)
+ <a href="https://www.iotheme.cn/store/onenav.html" target="_blank">如果你有更多功能需求，点我-></a>
<br/>

### 后台截图
<br/>

![Thumbnail_index](https://owen0o0.github.io/ioStaticResources/webstack/05.jpg)
![Thumbnail_index](https://owen0o0.github.io/ioStaticResources/webstack/06.png)
<br/>

### 感谢
感谢 <a href="https://github.com/WebStackPage/WebStackPage.github.io" target="_blank">Viggo</a> 的前台设计
<br/>

### 更新
+ 本分支更新记录：<a href="CHANGELOG.md">CHANGELOG.md</a>
+ 上游更新记录：<a href="https://github.com/owen0o0/WebStack/releases" target="_blank">owen0o0/WebStack releases</a>
+ 更新方法为替换源文件，或者在 WordPress 后台删除主题，然后重新安装主题
+ **升级前务必阅读上方「升级 / 迁移指南」**，尤其是 uploads 目录的扫描步骤
