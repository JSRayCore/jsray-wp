<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://jsray.org/assets/brand/jsray-logo-hero-dark.svg">
    <img src="https://jsray.org/assets/brand/jsray-logo-hero-light.svg" alt="JSRay" width="420">
  </picture>
</p>

[English](README.md) · **简体中文**

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.0.1--beta-blue)](CHANGELOG.md)
[![Channel](https://img.shields.io/badge/channel-%E5%85%AC%E5%BC%80%E6%B5%8B%E8%AF%95%E7%89%88-blue)](CHANGELOG.md)
[![Core](https://img.shields.io/badge/JSRay%20Core-0.0.1--beta.5-success)](https://github.com/JSRayCore/JSRay)
[![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-blue)](readme.txt)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4)](jsray.php)

> 为 WordPress 代码块提供 JSRay Core 渲染 · Gutenberg 区块 · 短代码 · 兼容模式

<sub>内部测试版 · 尚未发布公开测试版 · 内置 JSRay Core 快照</sub>

---

当前仓库是围绕 [JSRay Core](https://github.com/JSRayCore/JSRay) 的独立 **WordPress 插件**项目——JSRay 生态中的官方开源集成,拥有自己的版本号与更新日志。

它**内置 Core 的快照**,而不是在运行时依赖 Core。因此在你主动执行同步之前,插件的行为与发布当天完全一致。

推荐的写作方式是自定义的 **JSRay Code** 区块。原生 WordPress 代码块作为兼容路径继续支持。
## 内核完整性校验

插件**内置** JSRay Core 的快照,而不是运行时依赖它 —— 这意味着真正渲染访客代码的那个文件就躺在磁盘上,主机商、主题或攻击者都可能替换它。

`tools/sync-core.sh` 会把 Core 为该版本发布的摘要写入 `core-integrity.json`,插件则对每个内置资源做哈希比对。**设置 > JSRay** 会显示校验结果,一旦内置渲染器与官方构建不符,后台会出现警告。**有意**更换渲染引擎是被支持的 —— 走下面的适配器钩子,而不是改文件。

## 自定义配色

**设置 > JSRay > 自定义颜色** 接受 [JSRay 主题工作台](https://jsray.org/studio.html) 导出的调色板 JSON:

```json
{"themes":{"dark":{"background":"#0B0E14","tokens":{
  "keyword": {"color":"#FF6B9D","fontStyle":"bold italic"},
  "string":  {"color":"#7FE787"}
}}}}
```

没写的部分沿用所选调色板的取值,所以想改一个 token 只需两行。键名会按 `vocabulary.json`(随内置 Core 同步的 token 词表)校验,取值必须是真实颜色 —— 因此调色板无法注入 CSS。来自更新版 Core 的 token 会被忽略而非拒绝,这让调色板可以跨版本通用。

## 兼容范围

`readme.txt` 声明的上下限**两端都实测过**,不只是最新版:

| | WordPress | PHP |
|---|---|---|
| 下限 | 6.0 | 7.4 |
| 当前 | 7.1 | 8.3 |

`npm run test:compat` 会把两套环境都起起来,各自跑一遍 PHP 测试和真实页面渲染,出现任何警告或弃用提示即失败。

## 渲染器边界

插件默认使用 JSRay Core,但内部并未锁死:渲染器适配器可以扩展语言、替换前端资源,或过滤区块与短代码最终输出的 HTML。

可用钩子:

```php
jsray_wp_supported_languages
jsray_wp_enqueue_core_assets
jsray_wp_loader_dependencies
jsray_wp_frontend_config
jsray_wp_rendered_block_html
jsray_wp_rendered_shortcode_html
jsray_wp_palettes
```

前端适配器可以提供:

```js
window.JSRayWP.renderer = {
  highlight(code, language) {},
  highlightElement(element) {}
};
```

## 安装

把插件目录复制到:

```text
wp-content/plugins/jsray
```

然后在 WordPress 后台启用 **JSRay**。

## 配置

打开 **设置 > JSRay**:

- **主题模式**:`Dark`、`Light` 或 `跟随站点主题`。
- **回退语言**:代码块没有语言 class 且自动识别不确定时使用的语言(可选)。
- **前端资源**:控制是否在前台页面加载 JSRay 的 CSS 与 JavaScript。

## 使用

### JSRay Code 区块

在编辑器中插入 **JSRay Code**,该区块提供:

- 语言选择
- 默认开启的自动语言识别
- 可选文件名
- 复制按钮开关
- 行号开关
- 实时 JSRay 预览

前台输出的标记干净整洁:

```html
<pre><code>const value = 42;</code></pre>
```

### 兼容模式

对于 Gutenberg 原生代码块,你可以不做标注、交给 JSRay 自动识别,也可以在 **高级 > 附加 CSS class** 中添加语言 class:

```text
language-js
```

经典编辑器与自定义 HTML 可以直接写:

```html
<pre><code class="language-js">const value = 42;</code></pre>
```

短代码:

```text
[jsray lang="js" filename="app.js" line-numbers="true" highlight="2,4-5"]
const value = 42;
function important(x) {
  return x + value;
}
const done = true;
[/jsray]
```

| 属性 | 含义 |
|---|---|
| `lang` / `language` | 语言 id;不填则用后备语言,再不行走自动检测 |
| `filename` | 显示在标题栏,替代语言标签 |
| `copy` | `false` 隐藏复制按钮(默认显示) |
| `line-numbers` | `true` 显示行号栏 |
| `highlight` | 要标记的行,如 `3` 或 `2,4-5`;指定后会自动打开行号栏 |
| `class` | 加在外层容器上的额外 class |

短代码与区块走同一套渲染代码,因此产出的标记与支持的选项完全一致。

插件还会把 `<pre>` 上的 `language-*` / `lang-*` class 复制到内层 `<code>`,使 Gutenberg 的包装 class 能被 JSRay 运行时识别。

没有语言 class 时,前端加载器会调用 JSRay Core 从代码文本中识别语言,覆盖常见的 WordPress 代码块与普通 `<pre><code>` 片段。

## 支持的语言

JavaScript、TypeScript、JSX、TSX、Python、PHP、Go、Swift、Kotlin、Dart、Lua、Java、C、C++、C#、Ruby、Rust、HTML、XML、SVG、Vue、CSS、SCSS、JSON、JSONC、Shell、Bash、Zsh、Markdown、SQL、YAML、Scala、Objective-C、R、Perl、PowerShell、Elixir、Haskell、GraphQL、TOML、INI、Dockerfile、Makefile、Diff。

## 同步 Core 资源

修改 Core 项目后,先在 Core 中重建 `dist/`(执行 `sh build.sh`),再刷新内置快照:

```sh
sh tools/sync-core.sh                         # 默认在 ../jsray 寻找 Core
JSRAY_CORE_DIR=/path/to/jsray sh tools/sync-core.sh
```

该脚本复制三个 Core 资源,并更新 `version.json` 中的 `bundledCore.version`:

```text
../jsray/dist/jsray.js           -> assets/js/jsray.js
../jsray/dist/jsray.css          -> assets/css/jsray.css
../jsray/dist/themes/default.css -> assets/css/themes/default.css
```

只要 Core 仓库位于同级目录(`../jsray`)或通过 `JSRAY_CORE_DIR` 指定,`npm run check:versions` 就会校验快照是否与 Core 一致,从而在打包前发现漂移。

插件自有的文件位于:

```text
assets/js/jsray-block.js
assets/js/jsray-loader.js
assets/css/jsray-block.css
assets/css/jsray-block-editor.css
```

## 打包 Zip

在仓库根目录执行:

```sh
npm run build
```

脚本从 `jsray.php` 读取插件版本,生成 `build/jsray-wp-<version>.zip`,并在打包时剔除 macOS 的 `.DS_Store` 元数据。
