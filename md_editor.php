<?php
// md_editor.php —— 轻量级 Markdown 编辑 + 保存 + 预览
// 支持 KaTeX / MathJax 渲染公式，文件重命名、新建、下载、导出 HTML/PDF、字数统计

// 兼容旧版本 PHP：提供 str_contains / str_ends_with polyfill
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        if ($needle === '') return true;
        if (strlen($needle) > strlen($haystack)) return false;
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

/**
 * 1) 图片上传接口（集成在本文件）
 */
if (isset($_GET['upload_image']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        echo json_encode(['ok' => false, 'error' => 'no_file']);
        exit;
    }

    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'upload_error_' . $file['error']]);
        exit;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'too_large']);
        exit;
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $file['tmp_name']);
            if ($detected !== false) {
                $mime = $detected;
            }
            finfo_close($finfo);
        }
    }

    $allowed = [
        'image/png'        => 'png',
        'image/jpeg'       => 'jpg',
        'image/gif'        => 'gif',
        'image/webp'       => 'webp',
        'image/avif'       => 'avif',
        'image/bmp'        => 'bmp',
        'image/x-ms-bmp'   => 'bmp',
        'image/svg+xml'    => 'svg',
        'image/x-icon'     => 'ico',
        'image/heif'       => 'heif',
        'image/heic'       => 'heic',
        'image/jpgXL'      => 'jXL',
        'image/vnd.microsoft.icon' => 'ico',
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(['ok' => false, 'error' => 'bad_type_' . $mime]);
        exit;
    }

    $ext = $allowed[$mime];

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    if (function_exists('random_bytes')) {
        $rand = bin2hex(random_bytes(4));
    } else {
        $rand = substr(md5(uniqid('', true)), 0, 8);
    }
    $name = date('Ymd_His') . '_' . $rand . '.' . $ext;
    $path = $uploadDir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        echo json_encode(['ok' => false, 'error' => 'move_failed']);
        exit;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $url    = $scheme . '://' . $host . $base . '/uploads/' . $name;

    echo json_encode(['ok' => true, 'url' => $url]);
    exit;
}

// 2) Markdown 编辑器主体逻辑 -----------------------------------

require_once __DIR__ . '/parsedown/Parsedown.php';
$Parsedown = new Parsedown();

function md_count_words($text) {
    $plain = preg_replace('/```.*?```/us', ' ', $text);
    $plain = preg_replace('/[#>*_`\-\[\]\(\)!~]/u', ' ', $plain);
    $plain = preg_replace('/\s+/u', '', $plain);
    if (function_exists('mb_strlen')) {
        return mb_strlen($plain, 'UTF-8');
    }
    return strlen($plain);
}

function md_collect_local_images($text, $uploadDir, $uploadBaseUrl) {
    $images = [];
    $used   = [];

    $patternMd = '/!\[[^\]]*\]\(([^)]+)\)/';
    if (preg_match_all($patternMd, $text, $matchesMd)) {
        foreach ($matchesMd[1] as $url) {
            $url = trim($url, " \t\n\r\0\x0B\"'");
            $file = null;

            if ($uploadBaseUrl && strpos($url, $uploadBaseUrl) === 0) {
                $file = substr($url, strlen($uploadBaseUrl));
            } else {
                $rel = $url;
                if (strpos($rel, '/') === 0) {
                    $rel = substr($rel, 1);
                }
                if (strpos($rel, 'uploads/') === 0) {
                    $file = substr($rel, strlen('uploads/'));
                }
            }

            if ($file === null || $file === '') continue;
            if (strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) continue;

            $fullPath = $uploadDir . '/' . $file;
            if (!is_file($fullPath)) continue;
            if (isset($used[$file])) continue;

            $used[$file] = true;
            $images[] = [
                'file'         => $file,
                'full'         => $fullPath,
                'original_url' => $url,
                'relative'     => 'images/' . $file,
                'tar_path'     => 'images/' . $file,
            ];
        }
    }

    $patternImg = '/<img\s+[^>]*src\s*=\s*(["\'])(.*?)\1[^>]*>/i';
    if (preg_match_all($patternImg, $text, $matchesImg)) {
        foreach ($matchesImg[2] as $url) {
            $url = trim($url, " \t\n\r\0\x0B\"'");
            $file = null;

            if ($uploadBaseUrl && strpos($url, $uploadBaseUrl) === 0) {
                $file = substr($url, strlen($uploadBaseUrl));
            } else {
                $rel = $url;
                if (strpos($rel, '/') === 0) {
                    $rel = substr($rel, 1);
                }
                if (strpos($rel, 'uploads/') === 0) {
                    $file = substr($rel, strlen('uploads/'));
                }
            }

            if ($file === null || $file === '') continue;
            if (strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) continue;

            $fullPath = $uploadDir . '/' . $file;
            if (!is_file($fullPath)) continue;
            if (isset($used[$file])) continue;

            $used[$file] = true;
            $images[] = [
                'file'         => $file,
                'full'         => $fullPath,
                'original_url' => $url,
                'relative'     => 'images/' . $file,
                'tar_path'     => 'images/' . $file,
            ];
        }
    }

    return $images;
}

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function send_tar_from_temp_dir($tmpDir, $baseName) {
    $tmpBase = sys_get_temp_dir() . '/mdpack_' . uniqid();
    $tmpTar  = $tmpBase . '.tar';

    $cmd = 'tar -cf ' . escapeshellarg($tmpTar) . ' -C ' . escapeshellarg($tmpDir) . ' .';
    $out = [];
    $ret = 0;
    @exec($cmd, $out, $ret);

    if ($ret === 0 && is_file($tmpTar)) {
        header('Content-Type: application/x-tar');
        header('Content-Disposition: attachment; filename="' . $baseName . '.tar"');
        readfile($tmpTar);
        @unlink($tmpTar);
        return true;
    }
    return false;
}

$baseDir = __DIR__ . '/notes';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$rel = isset($_GET['file']) ? trim($_GET['file'], "/\\") : 'demo.md';
if (str_contains($rel, '..') || str_contains($rel, '/') || str_contains($rel, '\\')) {
    $rel = 'demo.md';
}
$path = $baseDir . '/' . $rel;

$renderer = isset($_GET['renderer']) ? $_GET['renderer'] : 'katex';
if (!in_array($renderer, ['katex', 'mathjax'], true)) {
    $renderer = 'katex';
}

if ($renderer === 'katex') {
    $rendererLabel = 'KaTeX';
    $toggleLabel   = '切换到 MathJax';
    $toggleRendererQuery = http_build_query([
        'file'     => $rel,
        'renderer' => 'mathjax',
    ]);
} else {
    $rendererLabel = 'MathJax';
    $toggleLabel   = '切换到 KaTeX';
    $toggleRendererQuery = http_build_query([
        'file'     => $rel,
        'renderer' => 'katex',
    ]);
}

if (isset($_GET['download']) && $_GET['download'] === '1') {
    if (!is_file($path)) {
        http_response_code(404);
        echo "File not found.";
        exit;
    }
    $markdown = file_get_contents($path);
    $baseName = preg_replace('/\.md$/i', '', basename($rel));

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $uploadBaseUrl = $scheme . '://' . $host . $base . '/uploads/';
    $uploadDir     = __DIR__ . '/uploads';

    $images = md_collect_local_images($markdown, $uploadDir, $uploadBaseUrl);

    if (!empty($images)) {
        $tmpDir = sys_get_temp_dir() . '/mdpack_dir_' . uniqid();
        @mkdir($tmpDir, 0777, true);
        @mkdir($tmpDir . '/images', 0777, true);

        $mdForTar = $markdown;
        foreach ($images as $img) {
            $mdForTar = str_replace($img['original_url'], $img['relative'], $mdForTar);
        }
        file_put_contents($tmpDir . '/' . $baseName . '.md', $mdForTar);

        foreach ($images as $img) {
            @copy($img['full'], $tmpDir . '/images/' . $img['file']);
        }

        $sent = send_tar_from_temp_dir($tmpDir, $baseName);
        rrmdir($tmpDir);
        if ($sent) {
            exit;
        }
    }

    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($rel) . '"');
    echo $markdown;
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? 'save';
    $content  = $_POST['content'] ?? '';
    $newName  = isset($_POST['filename']) ? trim($_POST['filename'], "/\\") : $rel;

    if ($newName !== '' && !str_ends_with(strtolower($newName), '.md')) {
        $newName .= '.md';
    }

    if (str_contains($newName, '..') || str_contains($newName, '/') || str_contains($newName, '\\')) {
        $message = '❌ 文件名不合法。';
    } else {
        $newPath = $baseDir . '/' . $newName;

        if ($action === 'rename') {
            if (file_put_contents($path, $content, LOCK_EX) !== false) {
                if (@rename($path, $newPath)) {
                    $message = '✅ 已保存并重命名为 ' . htmlspecialchars($newName, ENT_QUOTES, 'UTF-8');
                    $rel  = $newName;
                    $path = $newPath;
                } else {
                    $message = '❌ 重命名失败，请检查权限。';
                }
            } else {
                $message = '❌ 保存失败，重命名已取消。';
            }
        } else {
            if (file_put_contents($path, $content, LOCK_EX) !== false) {
                $message = '✅ 已保存于 ' . date('Y-m-d H:i:s');
            } else {
                $message = '❌ 保存失败，请检查 notes 目录权限。';
            }
        }
    }
}

if (is_file($path)) {
    $text = file_get_contents($path);
} else {
    $text = "# 新建笔记：{$rel}\n\n在左侧编辑 Markdown，点击下方保存按钮。\n\n支持行内公式：\$E = mc^2\$\n\n块级公式：\n\n$$\n\\int_0^1 x^2 \\, dx = \\frac{1}{3}\n$$";
}

$htmlPreview = $Parsedown->text($text);
$wordCount = md_count_words($text);
if ($message !== '') {
    $message .= '（当前约 ' . $wordCount . ' 字）';
}

$downloadQuery = http_build_query([
    'file'     => $rel,
    'download' => 1,
    'renderer' => $renderer,
]);
$exportHtmlQuery = http_build_query([
    'file'     => $rel,
    'export'   => 'html',
    'renderer' => $renderer,
]);

if (isset($_GET['export']) && $_GET['export'] === 'html') {
    $baseName = preg_replace('/\.md$/i', '', basename($rel));

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $uploadBaseUrl = $scheme . '://' . $host . $base . '/uploads/';
    $uploadDir     = __DIR__ . '/uploads';

    $style = <<<CSS
<style>
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    font-size: 14px;
    line-height: 1.6;
    padding: 20px;
  }
  pre {
    background:#f5f5f5;
    padding:8px;
    overflow:auto;
  }
  code {
    background:#f5f5f5;
    padding:2px 4px;
    border-radius:3px;
  }
  table {
    border-collapse: collapse;
    margin: 8px 0;
    width: 100%;
  }
  th, td {
    border: 1px solid #ccc;
    padding: 4px 8px;
    text-align: left;
  }
</style>
CSS;

    if ($renderer === 'katex') {
        $math = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/contrib/auto-render.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
  renderMathInElement(document.body, {
    delimiters: [
      {left: "$$", right: "$$", display: true},
      {left: "$",  right: "$",  display: false},
      {left: "\\(", right: "\\)", display: false},
      {left: "\\[", right: "\\]", display: true}
    ],
    throwOnError: false
  });
});
</script>
HTML;
    } else {
        $math = <<<HTML
<script>
  window.MathJax = {
    tex: {
      inlineMath: [['$', '$'], ['\\(', '\\)']],
      displayMath: [['$$', '$$'], ['\\[', '\\]']]
    }
  };
</script>
<script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
HTML;
    }

    $title = htmlspecialchars($baseName, ENT_QUOTES, 'UTF-8');
    $htmlDoc = "<!DOCTYPE html><html lang=\"zh-CN\"><head><meta charset=\"utf-8\"><title>{$title}</title>{$style}{$math}</head><body>{$htmlPreview}</body></html>";

    $images = md_collect_local_images($text . "\n" . $htmlDoc, $uploadDir, $uploadBaseUrl);

    if (!empty($images)) {
        $tmpDir = sys_get_temp_dir() . '/htmlpack_dir_' . uniqid();
        @mkdir($tmpDir, 0777, true);
        @mkdir($tmpDir . '/images', 0777, true);

        $htmlForTar = $htmlDoc;
        foreach ($images as $img) {
            $htmlForTar = str_replace($img['original_url'], $img['relative'], $htmlForTar);
        }
        file_put_contents($tmpDir . '/' . $baseName . '.html', $htmlForTar);

        foreach ($images as $img) {
            @copy($img['full'], $tmpDir . '/images/' . $img['file']);
        }

        $sent = send_tar_from_temp_dir($tmpDir, $baseName);
        rrmdir($tmpDir);
        if ($sent) {
            exit;
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.html"');
    echo $htmlDoc;
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>PHP Markdown 编辑器 - <?php echo htmlspecialchars($rel, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    overflow: hidden;
    background: #111;
    color: #eee;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  }
  body { background: #111; }
  form {
    height: 100%;
    display: flex;
    flex-direction: column;
  }
  header {
    padding: 4px 10px;
    background: #181818;
    border-bottom: 1px solid #333;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
  }
  .header-left {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }
  .header-right {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }
  .filename {
    min-width: 220px;
    padding: 3px 6px;
    border-radius: 3px;
    border: 1px solid #444;
    background: #000;
    color: #eee;
    font-size: 12px;
  }
  .btn-small {
    padding: 3px 8px;
    border-radius: 3px;
    border: 1px solid #555;
    background: #333;
    color: #eee;
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
  }
  .btn-small.btn-new {
    background: #2a6f3a;
    border-color: #3a9f4a;
  }
  .btn-small.btn-new:hover { background: #3b8f4b; }
  header .btn-small:hover { background: #555; }
  header .badge {
    font-size: 12px;
    color: #ccc;
  }
  header .badge strong { color: #ffd66b; }
  a.link {
    color: #8cf;
    text-decoration: none;
    font-size: 12px;
    white-space: nowrap;
  }
  a.link:hover { text-decoration: underline; }

  .toolbar {
    margin: 2px 10px 4px;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }
  .toolbar button {
    border: 1px solid #444;
    background: #2a2a2a;
    color: #eee;
    border-radius: 3px;
    padding: 2px 6px;
    font-size: 12px;
    cursor: pointer;
  }
  .toolbar button:hover { background: #3a3a3a; }
  .toolbar-separator {
    width: 1px;
    height: 18px;
    margin: 0 4px;
    background: #444;
  }

  .container {
    flex: 1;
    display: flex;
    flex-direction: row;
    min-height: 0;
  }
  .pane {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    min-height: 0;
  }
  .pane-left { border-right: 1px solid #333; }
  .pane-right { position: relative; }

  textarea {
    flex: 1;
    width: 100%;
    border: none;
    outline: none;
    padding: 8px;
    font-family: Menlo, Consolas, monospace;
    font-size: 14px;
    background: #0b0b0b;
    color: #eee;
    box-sizing: border-box;
    min-height: 0;
    resize: none;
  }
  .preview {
    flex: 1;
    padding: 8px 12px;
    overflow: auto;
    background: #181818;
    box-sizing: border-box;
    min-height: 0;
    scroll-behavior: smooth;
  }
  .controls {
    padding: 3px 8px;
    border-top: 1px solid #333;
    background: #181818;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
  }
  .controls-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .message {
    font-size: 12px;
    color: #ccc;
  }
  .main-btn {
    padding: 4px 12px;
    border-radius: 3px;
    border: 1px solid #3a9f4a;
    background: #2a6f3a;
    color: #fff;
    font-size: 13px;
    cursor: pointer;
  }
  .main-btn:hover { background: #3b8f4b; }
  .preview h1, .preview h2, .preview h3 {
    margin-top: 16px;
  }
  .preview pre {
    background:#000;
    padding:8px;
    overflow:auto;
  }
  .preview code {
    background:#000;
    padding:2px 4px;
    border-radius:3px;
  }

  .preview table {
    border-collapse: collapse;
    margin: 8px 0;
    width: 100%;
  }
  .preview th,
  .preview td {
    border: 1px solid #444;
    padding: 4px 8px;
    text-align: left;
  }

  .toc-panel {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 230px;
    max-height: 80%;
    overflow: auto;
    background: #202020;
    border: 1px solid #444;
    border-radius: 4px;
    font-size: 12px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.5);
    z-index: 10;
  }
  .toc-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:4px 6px;
    border-bottom:1px solid #333;
    font-weight:bold;
  }
  .toc-body {
    padding:4px 6px;
  }
  .toc-item {
    cursor:pointer;
    padding:2px 0;
    user-select:none;
  }
  .toc-item:hover {
    text-decoration:underline;
  }
  .toc-close-btn {
    border:none;
    background:transparent;
    color:inherit;
    cursor:pointer;
    font-size:12px;
  }

  body.mode-edit-only .pane-right {
    display:none;
  }
  body.mode-edit-only .pane-left {
    flex: 1;
  }
  body.mode-preview-only .pane-left {
    display:none;
  }
  body.mode-preview-only .pane-right {
    flex:1;
  }

  .view-toggle.active {
    border-color:#ffd66b;
  }

  body.light-theme {
    background:#f5f5f5;
    color:#222;
  }
  body.light-theme header,
  body.light-theme .controls {
    background:#f0f0f0;
    border-color:#ddd;
  }
  body.light-theme .pane-left {
    border-right:1px solid #ddd;
  }
  body.light-theme textarea {
    background:#ffffff;
    color:#222;
  }
  body.light-theme .preview {
    background:#ffffff;
  }
  body.light-theme .btn-small {
    background:#e0e0e0;
    border-color:#ccc;
    color:#222;
  }
  body.light-theme .btn-small.btn-new {
    background:#d6f5dd;
    border-color:#7fcf8a;
  }
  body.light-theme .btn-small:hover {
    background:#d0d0d0;
  }
  body.light-theme .main-btn {
    background:#4caf50;
    border-color:#4caf50;
  }
  body.light-theme .main-btn:hover {
    background:#43a047;
  }
  body.light-theme .filename {
    background:#ffffff;
    border-color:#ccc;
    color:#222;
  }
  body.light-theme .toolbar button {
    background:#e0e0e0;
    border-color:#ccc;
    color:#222;
  }
  body.light-theme .toolbar button:hover {
    background:#d0d0d0;
  }
  body.light-theme .preview pre {
    background:#f2f2f2;
  }
  body.light-theme .preview code {
    background:#f2f2f2;
  }
  body.light-theme a.link {
    color:#0066cc;
  }
  body.light-theme .message {
    color:#555;
  }
  body.light-theme .preview th,
  body.light-theme .preview td {
    border-color:#ccc;
  }
  body.light-theme .toc-panel {
    background:#ffffff;
    border-color:#ccc;
    box-shadow:0 4px 10px rgba(0,0,0,0.2);
  }
  body.light-theme .view-toggle.active {
    border-color:#ff9800;
  }

  @media (max-width: 900px) {
    .container { flex-direction: column; }
    .pane-left, .pane-right { height: 50%; }
  }
</style>
</head>
<body>

<form method="post">
  <header>
    <div class="header-left">
      <button type="button" class="btn-small btn-new" onclick="createNew()">🆕 新建</button>
      <span>文件名：</span>
      <input class="filename" type="text" name="filename" id="filename"
             value="<?php echo htmlspecialchars($rel, ENT_QUOTES, 'UTF-8'); ?>">
      <button type="submit" class="btn-small" onclick="setAction('rename')">✏️ 重命名</button>
    </div>
    <div class="header-right">
      <button type="button" class="btn-small view-toggle" id="view-split" onclick="setViewMode('split')">🧾 分屏</button>
      <button type="button" class="btn-small view-toggle" id="view-edit" onclick="setViewMode('edit')">✏️ 只编辑</button>
      <button type="button" class="btn-small view-toggle" id="view-preview" onclick="setViewMode('preview')">👁 只预览</button>

      <button type="button" class="btn-small" id="theme-toggle" onclick="toggleTheme()">
        ☀️ 浅色
      </button>
      <span class="badge">
        当前公式渲染：<strong><?php echo htmlspecialchars($rendererLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
      </span>
      <a class="link" href="?<?php echo htmlspecialchars($toggleRendererQuery, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($toggleLabel, ENT_QUOTES, 'UTF-8'); ?>
      </a>
    </div>
  </header>

  <div class="toolbar">
    <button type="button" onclick="mdTool('undo')" title="撤销 (Ctrl/Cmd+Z)">↺ 撤销</button>
    <button type="button" onclick="mdTool('redo')" title="重做 (Ctrl/Cmd+Y / Ctrl/Cmd+Shift+Z)">↻ 重做</button>
    <span class="toolbar-separator"></span>

    <button type="button" onclick="mdTool('heading')" title="标题循环 (H1-H6-普通) / Ctrl/Cmd+H">H</button>
    <button type="button" onclick="mdTool('bold')" title="粗体 (Ctrl/Cmd+B)"><b>B</b></button>
    <button type="button" onclick="mdTool('italic')" title="斜体 (Ctrl/Cmd+I)"><i>I</i></button>
    <button type="button" onclick="mdTool('strike')" title="删除线"><s>S</s></button>
    <button type="button" onclick="mdTool('underline')" title="下划线"><u>U</u></button>
    <span class="toolbar-separator"></span>

    <button type="button" onclick="mdTool('ul')" title="无序列表：选中行加/取消 - ">• 列表</button>
    <button type="button" onclick="mdTool('ol')" title="有序列表：选中行加/取消 1. 2.">1. 列表</button>
    <button type="button" onclick="mdTool('quote')" title="引用：选中行加/取消 >">&gt; 引用</button>
    <button type="button" onclick="mdTool('indent')" title="缩进选中行，适合做二级列表">⇥ 缩进</button>
    <button type="button" onclick="toggleToc()" title="自动目录">📑 目录</button>
    <span class="toolbar-separator"></span>

    <button type="button" onclick="mdTool('code')" title="代码块">{ }</button>
    <button type="button" onclick="mdTool('inlinecode')" title="行内代码 (Ctrl/Cmd+`)">`code`</button>
    <button type="button" onclick="mdTool('table')" title="表格">表格</button>
    <span class="toolbar-separator"></span>

    <button type="button" onclick="mdTool('link')" title="链接">🔗 链接</button>
    <button type="button" onclick="mdTool('image')" title="插入图片 Markdown">🖼 图片</button>
    <button type="button" onclick="mdTool('imgtoggle')" title="图片 Markdown ↔ HTML 容器转换">🖼↔️HTML</button>
    <button type="button" onclick="mdTool('hr')" title="分割线">—</button>
    <span class="toolbar-separator"></span>

    <button type="button" onclick="mdTool('formula')" title="插入公式模板">∑ 公式</button>
    <button type="button" onclick="mdTool('mathEsc')" title="修复公式中下划线被 Markdown 误解析的问题（再次点击还原）">∑ 转义</button>
    <button type="button" onclick="mdTool('comment')" title="注释 (Ctrl/Cmd+1 或 Ctrl/Cmd+/)">💬 注释</button>
    <button type="button" onclick="mdTool('help')" title="帮助">❓ 帮助</button>
  </div>

  <div class="container">
    <div class="pane pane-left">
      <textarea name="content" id="content"><?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></textarea>
      <div class="controls">
        <div class="message" id="status-message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="controls-right">
          <a class="link" href="?<?php echo htmlspecialchars($downloadQuery, ENT_QUOTES, 'UTF-8'); ?>">⬇ 下载 .md</a>
          <a class="link" href="?<?php echo htmlspecialchars($exportHtmlQuery, ENT_QUOTES, 'UTF-8'); ?>">⬇ 导出 HTML</a>
          <button type="submit" class="main-btn" onclick="setAction('save')">💾 保存内容</button>
        </div>
      </div>
    </div>
    <div class="pane pane-right">
      <div class="preview">
        <?php echo $htmlPreview; ?>
      </div>
      <div id="toc-panel" class="toc-panel" style="display:none;">
        <div class="toc-header">
          <span>目录</span>
          <button type="button" class="toc-close-btn" onclick="toggleToc()">×</button>
        </div>
        <div id="toc-body" class="toc-body"></div>
      </div>
    </div>
  </div>

  <input type="hidden" name="action" id="action" value="save">
</form>

<?php if ($renderer === 'katex'): ?>
<link rel="stylesheet" href="/lib/katex/katex.min.css">
<script src="/lib/katex/katex.min.js"></script>
<script src="/lib/katex/contrib/auto-render.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
  var previewEl = document.querySelector('.preview');
  if (!previewEl) return;
  renderMathInElement(previewEl, {
    delimiters: [
      {left: "$$", right: "$$", display: true},
      {left: "$",  right: "$",  display: false},
      {left: "\\(", right: "\\)", display: false},
      {left: "\\[", right: "\\]", display: true}
    ],
    throwOnError: false
  });
});
</script>
<?php else: ?>
<script>
window.MathJax = {
  tex: {
    inlineMath: [['$', '$'], ['\\(', '\\)']],
    displayMath: [['$$', '$$'], ['\\[', '\\]']]
  },
  options: {
    skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code']
  }
};
</script>
<script src="/lib/mathjax/tex-mml-chtml.js"></script>
<?php endif; ?>

<script>
function setAction(act) {
  var el = document.getElementById('action');
  if (el) el.value = act;
}

function createNew() {
  var currentRenderer = "<?php echo htmlspecialchars($renderer, ENT_QUOTES, 'UTF-8'); ?>";
  var name = prompt(
    "请输入新建文件名（例如：note-2025-11-06.md）：",
    "note-" + new Date().toISOString().slice(0,10) + ".md"
  );
  if (!name) return;
  name = name.trim();
  if (!name) return;
  if (name.indexOf("/") >= 0 || name.indexOf("\\") >= 0 || name.indexOf("..") >= 0) {
    alert("文件名不合法，不能包含 /、\\ 或 ..");
    return;
  }
  if (!name.toLowerCase().endsWith(".md")) {
    name += ".md";
  }
  var url = "?file=" + encodeURIComponent(name) +
            "&renderer=" + encodeURIComponent(currentRenderer);
  window.location.href = url;
}

function applyTheme(theme) {
  var body = document.body;
  var btn = document.getElementById('theme-toggle');
  if (theme === 'light') {
    body.classList.add('light-theme');
    if (btn) btn.textContent = '🌙 深色';
  } else {
    body.classList.remove('light-theme');
    if (btn) btn.textContent = '☀️ 浅色';
  }
}
function toggleTheme() {
  var current = localStorage.getItem('mdTheme') || 'dark';
  var next = current === 'dark' ? 'light' : 'dark';
  localStorage.setItem('mdTheme', next);
  applyTheme(next);
}

function updateViewButtons(mode) {
  var splitBtn   = document.getElementById('view-split');
  var editBtn    = document.getElementById('view-edit');
  var previewBtn = document.getElementById('view-preview');
  [splitBtn, editBtn, previewBtn].forEach(function(btn) {
    if (btn) btn.classList.remove('active');
  });
  if (mode === 'edit' && editBtn) editBtn.classList.add('active');
  else if (mode === 'preview' && previewBtn) previewBtn.classList.add('active');
  else if (splitBtn) splitBtn.classList.add('active');
}

function setViewMode(mode) {
  var body = document.body;
  body.classList.remove('mode-edit-only', 'mode-preview-only');
  if (mode === 'edit') {
    body.classList.add('mode-edit-only');
  } else if (mode === 'preview') {
    body.classList.add('mode-preview-only');
  }
  localStorage.setItem('mdViewMode', mode);
  updateViewButtons(mode);
}

/* 历史栈撤销重做 */
var mdHistory = {
  stack: [],
  index: -1,
  locked: false,
  max: 100
};

function saveHistory() {
  var ta = document.getElementById('content');
  if (!ta || mdHistory.locked) return;

  var val = ta.value;
  var selStart = ta.selectionStart || 0;
  var selEnd = ta.selectionEnd || 0;

  if (mdHistory.index >= 0) {
    var last = mdHistory.stack[mdHistory.index];
    if (last && last.value === val &&
        last.selStart === selStart && last.selEnd === selEnd) {
      return;
    }
  }

  mdHistory.stack = mdHistory.stack.slice(0, mdHistory.index + 1);
  mdHistory.stack.push({
    value: val,
    selStart: selStart,
    selEnd: selEnd
  });

  if (mdHistory.stack.length > mdHistory.max) {
    mdHistory.stack.shift();
  } else {
    mdHistory.index++;
  }
  if (mdHistory.stack.length > mdHistory.max) {
    mdHistory.index = mdHistory.stack.length - 1;
  }
}

function mdHistoryUndo() {
  var ta = document.getElementById('content');
  if (!ta) return;
  if (mdHistory.index <= 0) return;
  mdHistory.locked = true;
  mdHistory.index--;
  var state = mdHistory.stack[mdHistory.index];
  if (state) {
    ta.value = state.value;
    ta.focus();
    if (typeof ta.setSelectionRange === "function") {
      ta.setSelectionRange(state.selStart, state.selEnd);
    }
  }
  mdHistory.locked = false;
}

function mdHistoryRedo() {
  var ta = document.getElementById('content');
  if (!ta) return;
  if (mdHistory.index >= mdHistory.stack.length - 1) return;
  mdHistory.locked = true;
  mdHistory.index++;
  var state = mdHistory.stack[mdHistory.index];
  if (state) {
    ta.value = state.value;
    ta.focus();
    if (typeof ta.setSelectionRange === "function") {
      ta.setSelectionRange(state.selStart, state.selEnd);
    }
  }
  mdHistory.locked = false;
}

/* Ctrl/Cmd + D 选中下一个相同内容（循环） */
function selectNextOccurrence() {
  var ta = document.getElementById('content');
  if (!ta) return;
  var v = ta.value;
  var start = ta.selectionStart || 0;
  var end = ta.selectionEnd || 0;

  if (!v.length) return;

  if (start === end) {
    var left = start;
    var right = end;
    while (left > 0 && !/\s/.test(v.charAt(left - 1))) {
      left--;
    }
    while (right < v.length && !/\s/.test(v.charAt(right))) {
      right++;
    }
    if (left === right) return;
    start = left;
    end = right;
  }

  var text = v.substring(start, end);
  if (!text) return;

  var nextIndex = v.indexOf(text, end);
  if (nextIndex === -1) {
    nextIndex = v.indexOf(text, 0);
    if (nextIndex === -1) return;
  }

  if (nextIndex === start) {
    var second = v.indexOf(text, start + text.length);
    if (second === -1) {
      return;
    }
    nextIndex = second;
  }

  var nextStart = nextIndex;
  var nextEnd = nextIndex + text.length;
  ta.focus();
  if (typeof ta.setSelectionRange === "function") {
    ta.setSelectionRange(nextStart, nextEnd);
  }
}

/* 目录构建 */
function buildToc() {
  var preview = document.querySelector('.preview');
  var tocBody = document.getElementById('toc-body');
  if (!preview || !tocBody) return;
  tocBody.innerHTML = '';

  var headings = preview.querySelectorAll('h1,h2,h3,h4,h5,h6');
  if (!headings.length) {
    var empty = document.createElement('div');
    empty.textContent = '暂无标题';
    empty.style.color = '#888';
    tocBody.appendChild(empty);
    return;
  }

  headings.forEach(function(h, idx) {
    var level = parseInt(h.tagName.substring(1), 10);
    var text = (h.textContent || '').trim() || ('(无标题 ' + (idx + 1) + ')');
    var id = h.id;
    if (!id) {
      id = 'h-' + idx + '-' + text.replace(/\s+/g, '-').replace(/[^A-Za-z0-9\-\u4e00-\u9fa5]/g, '');
      h.id = id;
    }
    var item = document.createElement('div');
    item.className = 'toc-item';
    item.style.marginLeft = ((level - 1) * 10) + 'px';
    item.textContent = text;
    item.dataset.targetId = id;
    item.onclick = function(e) {
      e.preventDefault();
      var target = document.getElementById(this.dataset.targetId);
      var previewEl = document.querySelector('.preview');
      if (target && previewEl) {
        var previewRect = previewEl.getBoundingClientRect();
        var targetRect = target.getBoundingClientRect();
        var offset = targetRect.top - previewRect.top;
        previewEl.scrollTop = previewEl.scrollTop + offset - 8;
      }
    };
    tocBody.appendChild(item);
  });
}

function toggleToc() {
  var panel = document.getElementById('toc-panel');
  if (!panel) return;
  if (panel.style.display === 'none' || panel.style.display === '') {
    buildToc();
    panel.style.display = 'block';
  } else {
    panel.style.display = 'none';
  }
}

/* DOMContentLoaded 初始化 */
document.addEventListener("DOMContentLoaded", function() {
  var savedTheme = localStorage.getItem('mdTheme') || 'dark';
  applyTheme(savedTheme);

  var savedView = localStorage.getItem('mdViewMode') || 'split';
  setViewMode(savedView);

  var ta = document.getElementById('content');
  var previewEl = document.querySelector('.preview');

  var savedEdScroll = parseInt(localStorage.getItem('mdScrollEditor') || '0', 10);
  if (!isNaN(savedEdScroll) && ta) {
    ta.scrollTop = savedEdScroll;
  }
  var savedPrevScroll = parseInt(localStorage.getItem('mdScrollPreview') || '0', 10);
  if (!isNaN(savedPrevScroll) && previewEl) {
    previewEl.scrollTop = savedPrevScroll;
  }

  var form = document.querySelector('form');
  if (form) {
    form.addEventListener('submit', function() {
      var ta2 = document.getElementById('content');
      var prev2 = document.querySelector('.preview');
      if (ta2) localStorage.setItem('mdScrollEditor', String(ta2.scrollTop));
      if (prev2) localStorage.setItem('mdScrollPreview', String(prev2.scrollTop));
    });
  }

  if (!ta) return;

  saveHistory();

  ta.addEventListener('input', function() {
    saveHistory();
  });

  ta.addEventListener('keydown', function(e) {
    var isMod = e.ctrlKey || e.metaKey;
    var key = (e.key || "").toLowerCase();

    if (isMod && !e.shiftKey && key === 'z') {
      e.preventDefault();
      mdHistoryUndo();
      return;
    }

    if (isMod && (key === 'y' || (e.shiftKey && key === 'z'))) {
      e.preventDefault();
      mdHistoryRedo();
      return;
    }

    if (isMod && !e.shiftKey && key === 's') {
      e.preventDefault();
      var actionInput = document.getElementById('action');
      if (actionInput) actionInput.value = 'save';
      if (form) {
        localStorage.setItem('mdScrollEditor', String(ta.scrollTop));
        if (previewEl) localStorage.setItem('mdScrollPreview', String(previewEl.scrollTop));
        form.submit();
      }
      return;
    }

    if (isMod && !e.shiftKey && key === 'd') {
      e.preventDefault();
      selectNextOccurrence();
      return;
    }

    if (isMod && !e.shiftKey && key === 'b') {
      e.preventDefault();
      mdTool('bold');
      return;
    }

    if (isMod && !e.shiftKey && key === 'i') {
      e.preventDefault();
      mdTool('italic');
      return;
    }

    if (isMod && key === '`') {
      e.preventDefault();
      mdTool('inlinecode');
      return;
    }

    if (isMod && !e.shiftKey && key === 'h') {
      e.preventDefault();
      mdTool('heading');
      return;
    }

    if (isMod && (key === '1' || key === '/')) {
      e.preventDefault();
      mdTool('comment');
      return;
    }

    // Ctrl/Cmd + E：公式快捷键
    if (isMod && !e.shiftKey && key === 'e') {
      e.preventDefault();
      mdTool('mathwrap');
      return;
    }
  });

  ta.addEventListener('paste', function(e) {
    var clipboardData = e.clipboardData || (e.originalEvent && e.originalEvent.clipboardData);
    if (!clipboardData || !clipboardData.items) return;

    var items = clipboardData.items;
    var imageFile = null;
    for (var i = 0; i < items.length; i++) {
      if (items[i].kind === 'file') {
        var file = items[i].getAsFile();
        if (file && file.type && file.type.indexOf('image') === 0) {
          imageFile = file;
          break;
        }
      }
    }
    if (!imageFile) {
      return;
    }

    e.preventDefault();

    if (imageFile.size > 5 * 1024 * 1024) {
      alert("图片太大，超过 5MB，无法上传。");
      return;
    }

    var formData = new FormData();
    formData.append('image', imageFile);

    var statusEl = document.getElementById('status-message');
    var oldStatus = statusEl ? statusEl.textContent : "";

    if (statusEl) {
      statusEl.textContent = "正在上传图片...";
    }

    fetch('md_editor.php?upload_image=1', {
      method: 'POST',
      body: formData
    }).then(function(res) {
      return res.json();
    }).then(function(data) {
      if (statusEl) {
        statusEl.textContent = oldStatus;
      }
      if (!data || !data.ok) {
        alert("图片上传失败：" + (data && data.error ? data.error : "未知错误"));
        return;
      }
      var url = data.url;
      var cursorPos = ta.selectionStart || 0;
      var v = ta.value;
      var md = "![](" + url + ")\n";
      ta.value = v.slice(0, cursorPos) + md + v.slice(cursorPos);
      var newPos = cursorPos + md.length;
      if (typeof ta.setSelectionRange === "function") {
        ta.setSelectionRange(newPos, newPos);
      }
      ta.focus();
      saveHistory();
    }).catch(function(err) {
      if (statusEl) {
        statusEl.textContent = oldStatus;
      }
      alert("图片上传异常：" + err);
    });
  });
});

/* 工具栏主逻辑 */
function mdTool(action) {
  var ta = document.getElementById('content');
  if (!ta) return;

  if (action === 'undo') {
    mdHistoryUndo();
    return;
  }
  if (action === 'redo') {
    mdHistoryRedo();
    return;
  }

  var originalScrollTop = ta.scrollTop;

  var start = ta.selectionStart || 0;
  var end = ta.selectionEnd || 0;
  var value = ta.value || "";
  var selected = value.substring(start, end);
  var before = value.substring(0, start);
  var after = value.substring(end);
  var insert = "";

  function apply(newText, selectOffsetStart, selectOffsetEnd) {
    ta.value = before + newText + after;
    var base = before.length + (selectOffsetStart || 0);
    var selEnd = before.length + (selectOffsetEnd != null ? selectOffsetEnd : newText.length);
    ta.focus();
    if (typeof ta.setSelectionRange === "function") {
      ta.setSelectionRange(base, selEnd);
    }
    ta.scrollTop = originalScrollTop;
    saveHistory();
  }

  function htmlEscape(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  switch (action) {
    case 'heading': {
      var lineStart = value.lastIndexOf("\n", start - 1);
      if (lineStart === -1) lineStart = 0; else lineStart += 1;
      var lineEnd = value.indexOf("\n", start);
      if (lineEnd === -1) lineEnd = value.length;
      var line = value.substring(lineStart, lineEnd);

      var m = line.match(/^(\s{0,3})(#+)\s+(.*)$/);
      var indent, hashes, text;
      if (m) {
        indent = m[1];
        hashes = m[2];
        text   = m[3];
      } else {
        indent = "";
        hashes = "";
        text   = line.trim();
      }

      var level = hashes.length;
      level = level + 1;
      if (level > 6) level = 0;

      if (!text) text = "标题";

      var newLine;
      if (level === 0) {
        newLine = indent + text;
      } else {
        newLine = indent + "#".repeat(level) + " " + text;
      }

      ta.value = value.substring(0, lineStart) + newLine + value.substring(lineEnd);

      var textStart =
        lineStart +
        indent.length +
        (level === 0 ? 0 : level + 1);

      var textEnd = textStart + text.length;

      ta.focus();
      if (typeof ta.setSelectionRange === "function") {
        ta.setSelectionRange(textStart, textEnd);
      }
      ta.scrollTop = originalScrollTop;
      saveHistory();
      return;
    }

    case 'bold': {
      var text = selected || "粗体文本";
      insert = "**" + text + "**";
      apply(insert, 2, 2 + text.length);
      return;
    }
    case 'italic': {
      var text = selected || "斜体文本";
      insert = "*" + text + "*";
      apply(insert, 1, 1 + text.length);
      return;
    }
    case 'strike': {
      var text = selected || "删除线";
      insert = "~~" + text + "~~";
      apply(insert, 2, 2 + text.length);
      return;
    }
    case 'underline': {
      var text = selected || "下划线";
      insert = "<u>" + text + "</u>";
      apply(insert, 3, 3 + text.length);
      return;
    }
    case 'ul': {
      var text = selected || "列表项";
      var lines = text.split("\n");

      var allBulleted = true;
      for (var i = 0; i < lines.length; i++) {
        var t = lines[i].trim();
        if (!t) continue;
        if (!/^[-*+]\s+/.test(t)) {
          allBulleted = false;
          break;
        }
      }

      var newLines = lines.map(function(l) {
        var t = l.trim();
        if (!t) return l;
        if (allBulleted) {
          return l.replace(/^(\s*)[-*+]\s+/, "$1");
        } else {
          return l.replace(/^(\s*)/, "$1- ");
        }
      });

      var result = newLines.join("\n");
      apply(result, 0, result.length);
      return;
    }
    case 'ol': {
      var text = selected || "列表项";
      var lines = text.split("\n");

      var allOrdered = true;
      for (var i = 0; i < lines.length; i++) {
        var t = lines[i].trim();
        if (!t) continue;
        if (!/^\d+\.\s+/.test(t)) {
          allOrdered = false;
          break;
        }
      }

      var newLines = lines.map(function(l, idx) {
        var t = l.trim();
        if (!t) return l;
        if (allOrdered) {
          return l.replace(/^(\s*)\d+\.\s+/, "$1");
        } else {
          var n = idx + 1;
          return l.replace(/^(\s*)/, "$1" + n + ". ");
        }
      });

      var result = newLines.join("\n");
      apply(result, 0, result.length);
      return;
    }
    case 'quote': {
      var text = selected || "引用内容";
      var lines = text.split("\n");
      var allQuoted = true;
      for (var i = 0; i < lines.length; i++) {
        var t = lines[i].trim();
        if (!t) continue;
        if (!/^>/.test(t)) {
          allQuoted = false;
          break;
        }
      }
      var newLines = lines.map(function(l) {
        var t = l.trim();
        if (!t) return l;
        if (allQuoted) {
          return l.replace(/^(\s*)>\s?/, "$1");
        } else {
          return l.replace(/^(\s*)/, "$1> ");
        }
      });
      var result = newLines.join("\n");
      apply(result, 0, result.length);
      return;
    }
    case 'indent': {
      var txt = selected;
      var beforeAll = before;
      var afterAll = after;

      if (!txt) {
        var s = start;
        var lineStart = value.lastIndexOf("\n", s - 1);
        if (lineStart === -1) lineStart = 0; else lineStart += 1;
        var lineEnd = value.indexOf("\n", s);
        if (lineEnd === -1) lineEnd = value.length;
        txt = value.substring(lineStart, lineEnd);
        beforeAll = value.substring(0, lineStart);
        afterAll = value.substring(lineEnd);
      }

      var lines = txt.split("\n");
      var indented = lines.map(function(l) {
        if (!l.trim()) return l;
        return "  " + l;
      }).join("\n");

      before = beforeAll;
      after  = afterAll;
      apply(indented, 0, indented.length);
      return;
    }
    case 'code': {
      var text = selected || "这里写代码";
      insert = "```\n" + text + "\n```\n";
      apply(insert, 4, 4 + text.length);
      return;
    }
    case 'inlinecode': {
      var text = selected || "code";
      insert = "`" + text + "`";
      apply(insert, 1, 1 + text.length);
      return;
    }
    case 'table': {
      insert = "| 列1 | 列2 |\n| --- | --- |\n| 内容1 | 内容2 |\n";
      apply(insert, 2, insert.length);
      return;
    }
    case 'link': {
      var text = selected || "链接文字";
      insert = "[" + text + "](https://example.com)";
      apply(insert, 1, 1 + text.length);
      return;
    }
    case 'image': {
      insert = "![图片说明](https://example.com/image.png \"名称\")";
      apply(insert, 2, 6);
      return;
    }
    case 'hr': {
      insert = "\n\n---\n\n";
      apply(insert, insert.length, insert.length);
      return;
    }
    case 'formula': {
      insert = "$$\nE = mc^2\n$$\n";
      apply(insert, 3, 9);
      return;
    }

    // Ctrl/Cmd+E：公式快捷键逻辑
    case 'mathwrap': {
      var v = value;
      var s = start;
      var e = end;

      if (s === e) {
        // 无选区：插入块公式模版
        insert = "$$\nE = mc^2\n$$\n";
        apply(insert, 3, 9);
        return;
      }

      // 有选区：检查外侧是不是已经有 $ 或 $$ 包裹
      var beforeChar  = s > 0 ? v.charAt(s - 1) : '';
      var afterChar   = e < v.length ? v.charAt(e) : '';
      var before2     = s >= 2 ? v.substring(s - 2, s) : '';
      var after2      = e + 2 <= v.length ? v.substring(e, e + 2) : '';

      var innerText = v.substring(s, e);
      var newBefore = v.substring(0, s);
      var newAfter  = v.substring(e);
      var newStart, newEnd;

      // 情况1：$$...$$  →  取消包裹
      if (before2 === '$$' && after2 === '$$') {
        newBefore = v.substring(0, s - 2);
        newAfter  = v.substring(e + 2);
        ta.value  = newBefore + innerText + newAfter;
        newStart  = s - 2;
        newEnd    = newStart + innerText.length;
      }
      // 情况2：$...$   →  升级为 $$...$$
      else if (beforeChar === '$' && afterChar === '$') {
        newBefore = v.substring(0, s - 1) + '$$';
        newAfter  = '$$' + v.substring(e + 1);
        ta.value  = newBefore + innerText + newAfter;
        newStart  = newBefore.length;
        newEnd    = newStart + innerText.length;
      }
      // 情况3：普通文本 → 加一层 $...$
      else {
        newBefore = v.substring(0, s) + '$';
        newAfter  = '$' + v.substring(e);
        ta.value  = newBefore + innerText + newAfter;
        newStart  = newBefore.length;
        newEnd    = newStart + innerText.length;
      }

      ta.focus();
      if (typeof ta.setSelectionRange === "function") {
        ta.setSelectionRange(newStart, newEnd);
      }
      ta.scrollTop = originalScrollTop;
      saveHistory();
      return;
    }

    // 数学符号自动转义 / 还原（_、*、-）
    case 'mathEsc': {
      var isSelection = selected.length > 0;
      var target = isSelection ? selected : value;

      // 判断当前内容是否需要“加转义”（存在未转义的 _ / * / -）
      var needAdd =
        /(^|[^\\])_/.test(target) ||              // 未转义的 _
        /(^|[^\\])\*/.test(target) ||             // 未转义的 *
        /(^|[^\\])-/.test(target);                // 未转义的 -

      var replaced;

      if (needAdd) {
        // 1) 未转义的 '_'  →  '\_'
        replaced = target.replace(/(^|[^\\])_/g, function (m, p1) {
          return p1 + "\\_";
        });
        // 2) 未转义的 '*'  →  '\*'
        replaced = replaced.replace(/(^|[^\\])\*/g, function (m, p1) {
          return p1 + "\\*";
        });
        // 3) 未转义的 '-'  →  '\-'
        replaced = replaced.replace(/(^|[^\\])-/g, function (m, p1) {
          return p1 + "\\-";
        });
      } else {
        // 已经是“转义版”，则全部还原：
        // '\_' → '_'
        replaced = target.replace(/\\_/g, "_");
        // '\*' → '*'
        replaced = replaced.replace(/\\\*/g, "*");
        // '\-' → '-'
        replaced = replaced.replace(/\\-/g, "-");
      }

      if (isSelection) {
        ta.value = before + replaced + after;
        var ns = before.length;
        var ne = ns + replaced.length;
        ta.focus();
        if (typeof ta.setSelectionRange === "function") {
          ta.setSelectionRange(ns, ne);
        }
      } else {
        ta.value = replaced;
        ta.focus();
        if (typeof ta.setSelectionRange === "function") {
          ta.setSelectionRange(0, replaced.length);
        }
      }
      ta.scrollTop = originalScrollTop;
      saveHistory();
      return;
    }

    // 图片 Markdown ↔ HTML 容器
    case 'imgtoggle': {
      var full = value;
      var useWhole = (start === end);
      var rangeStart = useWhole ? 0 : start;
      var rangeEnd   = useWhole ? full.length : end;
      var segment    = full.slice(rangeStart, rangeEnd);

      var mdImgRegex = /!\[([^\]]*)\]\((\S+?)(?:\s+"([^"]*)")?\)/g;
      var didMd = false;
      var converted = segment.replace(mdImgRegex, function(match, alt, url, title) {
        didMd = true;
        alt = alt || "";
        title = title || "";
        var escAlt = htmlEscape(alt);
        var escTitle = htmlEscape(title);
        var escUrl = url;
        var titleAttr = escTitle ? ' title="' + escTitle + '"' : '';
        return '<div style="text-align: center;">\n' +
               '    <img src="' + escUrl + '" alt="' + escAlt + '"' + titleAttr + ' width="50%">\n' +
               '</div>';
      });

      if (didMd) {
        before = full.slice(0, rangeStart);
        after  = full.slice(rangeEnd);
        ta.value = before + converted + after;
        var ns = rangeStart;
        var ne = rangeStart + converted.length;
        ta.focus();
        if (typeof ta.setSelectionRange === "function") {
          ta.setSelectionRange(ns, ne);
        }
        ta.scrollTop = originalScrollTop;
        saveHistory();
        return;
      }

      var htmlBlockRegex = /<div\s+style="text-align:\s*center;"\s*>\s*<img\s+([^>]*?)>\s*<\/div>/gi;
      var didHtml = false;
      converted = segment.replace(htmlBlockRegex, function(match, attrs) {
        didHtml = true;

        function getAttr(str, name) {
          var re = new RegExp(name + '\\s*=\\s*"([^"]*)"', 'i');
          var m = str.match(re);
          return m ? m[1] : "";
        }
        function unescapeHtml(str) {
          return String(str)
            .replace(/&quot;/g, '"')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>');
        }

        var src   = getAttr(attrs, 'src');
        var alt   = unescapeHtml(getAttr(attrs, 'alt'));
        var title = unescapeHtml(getAttr(attrs, 'title'));

        if (!src) {
          return match;
        }

        alt   = alt || "";
        title = title || "";

        var md;
        if (alt === "" && title === "") {
          md = "![](" + src + ")";
        } else if (alt === "" && title !== "") {
          md = '![](' + src + ' "' + title.replace(/"/g, '\\"') + '")';
        } else if (alt !== "" && title === "") {
          md = "![" + alt.replace(/\]/g, '\\]') + "](" + src + ")";
        } else {
          md = "![" + alt.replace(/\]/g, '\\]') + '](' + src + ' "' +
               title.replace(/"/g, '\\"') + '")';
        }
        return md;
      });

      if (!didHtml) {
        alert("当前选区或全文中没有检测到可转换的图片 Markdown 或 HTML。");
        ta.scrollTop = originalScrollTop;
        return;
      }

      before = full.slice(0, rangeStart);
      after  = full.slice(rangeEnd);
      ta.value = before + converted + after;
      var ns2 = rangeStart;
      var ne2 = rangeStart + converted.length;
      ta.focus();
      if (typeof ta.setSelectionRange === "function") {
        ta.setSelectionRange(ns2, ne2);
      }
      ta.scrollTop = originalScrollTop;
      saveHistory();
      return;
    }

    case 'comment': {
      var text = selected;
      if (text) {
        var trimmed = text.trim();
        if (trimmed.startsWith('<!--') && trimmed.endsWith('-->')) {
          var inner = trimmed.substring(4, trimmed.length - 3);
          apply(inner, 0, inner.length);
        } else {
          var comment = "<!-- " + text + " -->";
          apply(comment, 5, 5 + text.length);
        }
      } else {
        var s = start;
        var lineStart = value.lastIndexOf("\n", s - 1);
        if (lineStart === -1) lineStart = 0; else lineStart += 1;
        var lineEnd = value.indexOf("\n", s);
        if (lineEnd === -1) lineEnd = value.length;
        var line = value.substring(lineStart, lineEnd);
        var trimmedLine = line.trim();

        var newLine;
        if (trimmedLine.startsWith('<!--') && trimmedLine.endsWith('-->')) {
          var inner2 = trimmedLine.substring(4, trimmedLine.length - 3);
          newLine = inner2;
        } else {
          newLine = "<!-- " + line + " -->";
        }

        ta.value = value.substring(0, lineStart) + newLine + value.substring(lineEnd);
        ta.focus();
        if (typeof ta.setSelectionRange === "function") {
          ta.setSelectionRange(lineStart, lineStart + newLine.length);
        }
        ta.scrollTop = originalScrollTop;
        saveHistory();
      }
      return;
    }

    case 'help': {
      alert(
        "Markdown 快速帮助:\n\n" +
        "• 粗体: **text**  (Ctrl/Cmd + B)\n" +
        "• 斜体: *text*      (Ctrl/Cmd + I)\n" +
        "• 标题循环: H1-H6-普通 (Ctrl/Cmd + H)\n" +
        "• 删除线: ~~text~~\n" +
        "• 下划线: <u>text</u>\n" +
        "• 列表: 按“• 列表 / 1. 列表”\n" +
        "• 缩进: 按“⇥ 缩进”增加两个空格\n" +
        "• 代码块: ``` 包裹\n" +
        "• 行内代码: `code` (Ctrl/Cmd+`)\n" +
        "• 链接: [文本](url)\n" +
        "• 图片: ![alt](url \"title\")\n" +
        "• 图片 ↔ HTML 容器: “🖼↔️HTML”\n" +
        "• 注释: Ctrl/Cmd + 1 或 Ctrl/Cmd + /  → <!-- ... -->\n" +
        "• 数学符号转义: “∑ 转义” 按钮，首点击为 _ / * / - 自动加 \\，再次点击全部还原\n" +
        "• 公式快捷键: Ctrl/Cmd + E\n" +
        "    - 有选区: $...$ → $$...$$ → 还原\n" +
        "    - 无选区: 插入 $$ 块公式模版\n\n" +
        "公式示例:\n" +
        "• 行内: $ a^2 + b^2 = c^2 $\n" +
        "• 块级: $$\\\\int_0^1 x^2 dx = 1/3$$\n"
      );
      ta.scrollTop = originalScrollTop;
      return;
    }

    default:
      ta.scrollTop = originalScrollTop;
      return;
  }
}
</script>

</body>
</html>
