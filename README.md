# Post Views

一款轻量级 WordPress 文章浏览量统计插件。

## 功能特性

- 自动记录文章/页面的浏览次数
- 前端美观的卡片式浏览量展示（支持亮色/暗色模式）
- 支持缓存环境（WP_CACHE 开启时自动切换 AJAX 计数）
- 可过滤搜索引擎爬虫的访问
- 可配置计数对象（所有人 / 仅游客 / 仅注册用户）
- 按页面类型控制显示（首页、文章、页面、归档、搜索等）
- 提供最多/最少浏览文章列表（支持按分类、标签筛选）
- 经典 Widget 小工具
- `[views]` 短代码，可在任意位置插入浏览量
- REST API 支持（`views` 字段）
- 后台文章列表 Views 列（可排序）
- 自定义模板（支持 `%VIEW_COUNT%`、`%VIEW_COUNT_ROUNDED%` 等变量）

## 安装

1. 下载 `post-views.zip`
2. 在 WordPress 后台 → 插件 → 安装插件 → 上传插件
3. 上传 zip 文件并启用

## 使用方法

### 模板标签

在主题模板中调用：

```php
<?php if ( function_exists( 'the_views' ) ) { the_views(); } ?>
```

### 短代码

在文章或页面编辑器中插入：

```
[views]
```

指定文章 ID：

```
[views id="123"]
```

### 前端排序

通过 URL 参数按浏览量排序：

```
?v_sortby=views&v_orderby=desc
```

### 模板变量

单篇模板支持：

| 变量 | 说明 |
|------|------|
| `%VIEW_COUNT%` | 格式化的浏览次数 |
| `%VIEW_COUNT_ROUNDED%` | 缩写形式（如 1.2K、3.5M） |

列表模板额外支持：

| 变量 | 说明 |
|------|------|
| `%POST_TITLE%` | 文章标题 |
| `%POST_URL%` | 文章链接 |
| `%POST_DATE%` | 发布日期 |
| `%POST_TIME%` | 发布时间 |
| `%POST_EXCERPT%` | 文章摘要 |
| `%POST_CONTENT%` | 文章内容 |
| `%POST_THUMBNAIL%` | 缩略图 HTML |
| `%POST_THUMBNAIL_URL%` | 缩略图 URL |
| `%POST_CATEGORY_ID%` | 分类 ID |
| `%POST_AUTHOR%` | 作者名 |

## 设置

启用后在 WordPress 后台 → 设置 → PostViews 中配置：

- 计数来源（所有人 / 仅游客 / 仅注册用户）
- 是否排除爬虫
- 是否使用 AJAX 更新（缓存环境下）
- 浏览量显示模板
- 列表显示模板
- 各页面类型的显示规则

## 暗色模式

插件自动适配暗色模式，支持：

- 系统级：`prefers-color-scheme: dark`
- 主题级：`body.dark`、`body.dark-mode`、`body.night-mode`、`html.dark`、`[data-theme="dark"]`、`[data-color-scheme="dark"]`

## 兼容性

- WordPress 6.0+
- PHP 7.4+
- 兼容主流缓存插件（WP Super Cache、W3 Total Cache、LiteSpeed Cache 等）

## 作者

木木 — [blog.huzz.cn](https://blog.huzz.cn/)

## 许可证

GPLv2 or later
