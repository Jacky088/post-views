# Post Views

一款轻量级 WordPress 文章浏览量统计插件。

## 功能特性

- 自动记录文章/页面的浏览次数
- 前端美观的卡片式浏览量展示（支持亮色/暗色模式）
- 自动在文章底部追加浏览量，无需修改主题模板
- 支持缓存环境（WP_CACHE 开启时自动切换 AJAX 计数）
- 可过滤搜索引擎爬虫的访问
- 可配置计数对象（所有人 / 仅游客 / 仅注册用户）
- 按页面类型控制显示（首页、文章、页面、归档、搜索等）
- 提供最多/最少浏览文章列表（支持按分类、标签筛选）
- 经典 Widget 小工具
- `[views]` 短代码，可在任意位置插入浏览量
- REST API 支持（`views` 字段）
- 后台文章列表浏览量列（可排序）
- 自定义模板（支持 `%VIEW_COUNT%`、`%VIEW_COUNT_ROUNDED%` 等变量）
- 随机写入浏览数据（测试功能）

## 安装

1. 下载 `post-views.zip`
2. 在 WordPress 后台 → 插件 → 安装插件 → 上传插件
3. 上传 zip 文件并启用

## 使用方法

### 自动显示

插件启用后会自动在文章/页面底部显示浏览量卡片，无需额外配置。

### 模板标签

在主题模板中手动调用：

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

启用后在 WordPress 后台 → 设置 → 浏览量 中配置：

- 统计来源（所有人 / 仅游客 / 仅注册用户）
- 是否排除爬虫
- 是否使用 AJAX 更新（缓存环境下）
- 浏览量显示模板
- 热门列表模板
- 各页面类型的显示规则

## 随机写入浏览数据

在设置页面底部可为所有浏览量为 0 的已发布文章随机写入浏览数据：

- 默认随机范围：100 ~ 1000
- 支持自定义最小值和最大值
- 已有浏览量大于 0 的文章不会被修改

> 注意：该功能为测试功能。

## 暗色模式

插件自动适配暗色模式，支持：

- 系统级：`prefers-color-scheme: dark`
- 主题级：`body.dark`、`body.dark-mode`、`body.night-mode`、`html.dark`、`[data-theme="dark"]`、`[data-color-scheme="dark"]`

## 兼容性

- WordPress 6.0+
- PHP 7.4+
- 兼容主流缓存插件（WP Super Cache、W3 Total Cache、LiteSpeed Cache 等）

## 更新日志

### 1.0.2
- 移除同步主题数据功能（避免误操作清零）
- 新增随机写入浏览数据功能（支持自定义范围）
- 回退逻辑改为只读不写，不修改任何已有 meta 数据

### 1.0.1
- 后台界面全部汉化
- 修复同步逻辑，支持多种主题 meta key
- 同步功能标注为测试功能

### 1.0.0
- 初始版本
- 美化前端浏览量展示（卡片式）
- 暗色模式适配
- 自动在文章底部追加浏览量
- 屏蔽 WordPress.org 更新检查

## 作者

木木 — [blog.huzz.cn](https://blog.huzz.cn/)

## 许可证

GPLv2 or later
