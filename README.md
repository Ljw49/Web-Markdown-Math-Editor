# Web Markdown Math Editor

一个轻量、高效、完全本地可部署的 **Markdown + 数学公式** 在线编辑器，基于PHP，  
支持 **KaTeX / MathJax 渲染**、**粘贴上传图片**、**文件打包导出**、**公式转义与 LaTeX 兼容模式** 等功能。  
适合科研笔记、教学文档、实验记录、以及希望保留数学表达能力的 Markdown 用户。  

A lightweight, efficient, fully local-deployable **Markdown + math formula** web editor.  
It supports **KaTeX / MathJax rendering**, **paste-to-upload images**, **bundled export**,  
and **formula escaping for LaTeX compatibility**.  
Ideal for scientific notes, teaching materials, research records, and technical documentation.  

---

## 用户界面 / User Interface

<div style="align-items: flex-start; display: flex; gap: 10px;">
  <img src="https://github.com/user-attachments/assets/49510247-a366-4cf1-a03b-03b90226c506" alt="UI on destop" style="width: 75%;">
  <img src="https://github.com/user-attachments/assets/17d59bb3-f6d6-4449-8818-4d4d94c5850c" alt="UI on mobile phone" style="width: 23%;">
</div>

---

## ✨ 功能特点 / Features

### 🖋️ Markdown 编辑与预览 / Markdown Editing & Preview
- 实时双栏同步编辑与预览（支持 KaTeX / MathJax 渲染）  
  Real-time dual-pane editing and preview with KaTeX/MathJax rendering.  
- 支持标题、列表、表格、引用、代码块等标准语法  
  Supports standard Markdown syntax: headings, lists, tables, quotes, code blocks.  
- 预览自动样式适配，表格带边框  
  Auto-styled preview with bordered tables.  
- 保存/导出后保持滚动与光标位置  
  Keeps scroll and cursor position after saving/exporting.  

---

### 🧠 数学与科学公式支持 / Math & Scientific Notation
- 支持两种渲染引擎（可一键切换）：
  - **KaTeX（快速、纯前端） / KaTeX (fast, pure front-end)**
  - **MathJax（兼容性强） / MathJax (high compatibility)**  
- **∑ 转义按钮 / Formula Escape Button**  
  - 自动转义 `_`、`*`、`-` 等符号防止被 Markdown 误解析  
    Automatically escapes `_`, `*`, and `-` to prevent Markdown misinterpretation  
  - 二次点击恢复原样 / Toggle to revert back  
  - 选中内容时仅作用于选区 / Works only on selection if highlighted  
  - 可兼容 LaTeX 拷贝 / Ensures LaTeX compatibility for copying  

---

### ⌨️ 快捷键支持 / Keyboard Shortcuts

| 快捷键 / Shortcut | 功能 / Description |
|------------------|--------------------|
| `Ctrl / Cmd + S` | 保存当前文档 / Save current document |
| `Ctrl / Cmd + E` | 插入或切换公式模式 `$...$` / `$$...$$` |
| `Ctrl / Cmd + B` | 加粗 / Bold |
| `Ctrl / Cmd + I` | 斜体 / Italic |
| `Ctrl / Cmd + H` | 标题循环 (#→##→###) / Cycle heading levels |
| `Ctrl / Cmd + /` | 注释切换 / Toggle comment |
| `Ctrl / Cmd + D` | 多光标选中下一个相同文本 / Multi-cursor next match |

---

### 🖼️ 图片与资源 / Images & Resources
- **粘贴上传图片 / Paste to upload images**
- 支持格式 / Supported formats:  
  PNG, JPEG, GIF, WEBP, AVIF, HEIC, HEIF, JXL, BMP, SVG, ICO  
- 自动 MIME 检测 / Auto MIME detection  
- 图片保存在 `/uploads/`，日期命名 / Uploaded images stored in `/uploads/`  
- Markdown 与 HTML `<img>` 可自由切换 (HTML标记的图片显示效果好很多)/ Switchable between Markdown and HTML tags (HTML tags display much better)

---

### 📦 文件导出与打包 / File Export & Packaging
- **Markdown 导出 / Export Markdown**：自动打包本地图片并改为相对路径  
- **HTML 导出 / Export HTML**：嵌入公共 CDN 的 KaTeX / MathJax  
- **TAR 打包 / TAR Archive**：打包 `.md` 与图片为单文件下载  

---

### 📊 其他功能 / Additional Features
- 自动字数统计 / Word count on save  
- 自动目录（可导航） / Auto-generated Table of Contents  
- 深浅色主题切换 / Light-Dark theme toggle  
- 全屏模式（仅编辑/仅预览） / Fullscreen edit or preview modes  
- 移动端支持 / Mobile-friendly  

---

## ⚙️ 安装与运行 / Installation & Usage

### 🧾 依赖 / Requirements
- PHP ≥ 7.4 (Recommended ≥ 8.0)
- 内置组件 / Bundled Components:
  - Parsedown (v1.7.4)
  - KaTeX (v0.16.25) / MathJax (v4.0.0)
- PHP 扩展 / Required Extensions:
  - `mbstring`
  - `fileinfo`
- 可选择加载本地或公共 CDN / Supports local or CDN loading

---

### 📁 目录结构 / Directory Structure

```
www/
├── md_editor.php          ← 主程序 / main PHP file
├── parsedown/
├── lib/
│   ├── katex/
│   ├── mathjax/
│   └── ...
├── uploads/               ← 图片目录 / uploaded images
└── notes/                 ← Markdown 文件 / notes storage
```

---

### 🚀 启动 / Run

#### 方式一：本地 PHP 服务器（推荐） / Local PHP Server
```bash
php -S 0.0.0.0:8080 -t /path/to/www
```
访问 / Visit:  
👉 `http://localhost:8080/md_editor.php`

#### 方式二：部署到 Web 服务器 / Deploy to Web Server
- 将项目放入网站根目录 / Place project in web root  
- 确保 `/uploads` 与 `/notes` 可写 / Ensure write permissions  

---

## 🧩 可选功能 / Optional Features

| 功能 / Feature | 说明 / Description | 依赖 / Dependency |
|----------------|--------------------|-------------------|
| 图像 MIME 自动识别 / Image MIME detection | 提高兼容性 / Improves compatibility | PHP `fileinfo` |
| 压缩打包导出 / TAR export | 打包 Markdown 与图片 | PHP `exec` |

---

## 📖 版权与贡献 / License & Contribution
- 作者 / Author: **Ljw49 & ChatGPT**  
- 协议 / License: **MIT**  
- 欢迎 fork / 修改 / 二次开发  
  Feel free to fork, modify, and redistribute  
- 推荐引用本项目支持开发与改进  
  Attribution appreciated for continued development  

---

## ❤️ 致谢 / Acknowledgements
- [Parsedown](https://github.com/erusev/parsedown) — Markdown parser  
- [KaTeX](https://katex.org/) / [MathJax](https://www.mathjax.org/) — Formula rendering  

---

### 📘 说明 / Note

并没有适配多语言版本。
This version is not adapted for multiple languages. If you require a version in another language, you can feed `md_editor.php` to a large language model for translation, then deploy it in the same manner.

## 🧾 License

This project is released under the MIT License.

It includes code from the following open-source projects:

- [Parsedown](https://github.com/erusev/parsedown) — MIT License  
- [KaTeX](https://github.com/KaTeX/KaTeX) — MIT License  
- [MathJax](https://github.com/mathjax/MathJax) — Apache License 2.0
  
All licenses are compatible with MIT and their original copyright notices
are preserved in the `parsedown/` and `lib/` directory.
