<?php
// cms/cms.php
session_start();

$dbFile = __DIR__ . '/cms.db.php';
$isLoggedIn = isset($_SESSION['user_id']);
$errorMsg = '';

// 初始化 PDO
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("数据库连接失败，请确保 cms.db.php 存在且有读写权限。");
}

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header("Location: cms.php");
        exit;
    } else {
        $errorMsg = '账号或密码错误';
    }
}

// 处理登出请求
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: cms.php");
    exit;
}

// ===== 如果未登录，只渲染登录页面 =====
if (!$isLoggedIn):
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-96">
        <h2 class="text-2xl font-bold mb-6 text-center text-gray-800">CMS Admin</h2>
        <?php if ($errorMsg): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 text-sm">
                <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>
        <form method="POST" action="cms.php">
            <input type="hidden" name="action" value="login">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Account</label>
                <input type="text" name="username" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                <input type="password" name="password" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline transition">
                Login
            </button>
        </form>
    </div>
</body>
</html>
<?php 
exit; 
endif; 
// ===== 登录部分结束 =====

// ===== 后端 API 路由处理 (如获取页面列表) =====
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    // 提取出一个公共的 Sitemap 更新函数，用于页面增删时触发
    function update_sitemap($pdo) {
        $basePath = __DIR__ . '/../';
        $sitemapFile = $basePath . 'Sitemap.xml';
        
        // 获取站点域名配置，如果为空则给出默认占位符
        $stmt = $pdo->query("SELECT key_value FROM settings WHERE key_name = 'site_domain'");
        $domainRow = $stmt->fetch();
        $domain = $domainRow && !empty($domainRow['key_value']) ? rtrim($domainRow['key_value'], '/') : 'https://www.yoursite.com';
        
        $pagesStmt = $pdo->query("SELECT filename FROM pages ORDER BY type ASC, filename ASC");
        $pages = $pagesStmt->fetchAll();
        
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($pages as $p) {
            $url = $domain . '/' . ltrim($p['filename'], '/');
            // 如果是以 index.html 结尾的目录引导页，优化 URL 为单纯的斜杠
            if (substr($url, -10) === 'index.html') { $url = substr($url, 0, -10); }
            
            $xml .= "    <url>\n";
            $xml .= "        <loc>" . htmlspecialchars($url) . "</loc>\n";
            $xml .= "        <lastmod>" . date('Y-m-d') . "</lastmod>\n";
            $xml .= "        <changefreq>weekly</changefreq>\n";
            $xml .= "        <priority>0.8</priority>\n";
            $xml .= "    </url>\n";
        }
        $xml .= "</urlset>";
        file_put_contents($sitemapFile, $xml);
    }

    // 获取分类的页面列表
    if ($_GET['api'] === 'get_pages') {
        $stmt = $pdo->query("SELECT * FROM pages ORDER BY type ASC, filename ASC");
        $pages = $stmt->fetchAll();
        
        $organizedPages = [
            'static' => [],
            'templates' => []
        ];

        foreach ($pages as $page) {
            if ($page['type'] === 'static') {
                $organizedPages['static'][] = $page;
            } else {
                // 根据文件夹名称划分不同的模板 Tab
                // 例如：template1/page1.html 提取出 template1
                $parts = explode('/', $page['filename']);
                if (count($parts) > 1) {
                    $templateFolder = $parts[0];
                    if (!isset($organizedPages['templates'][$templateFolder])) {
                        $organizedPages['templates'][$templateFolder] = [];
                    }
                    $organizedPages['templates'][$templateFolder][] = $page;
                }
            }
        }
        echo json_encode(['status' => 'success', 'data' => $organizedPages]);
        exit;
    }
    
    // 复制模板页面 API
    if ($_GET['api'] === 'duplicate_page' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE id = :id AND type = 'template_instance'");
        $stmt->execute([':id' => $id]);
        $page = $stmt->fetch();
        
        if ($page) {
            $sourceFile = __DIR__ . '/../' . $page['filename'];
            $pathInfo = pathinfo($page['filename']);
            $newFilename = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '-copy-' . time() . '.' . $pathInfo['extension'];
            $targetFile = __DIR__ . '/../' . $newFilename;
            
            if (file_exists($sourceFile)) {
                if (copy($sourceFile, $targetFile)) {
                    $newTitle = $page['title'] . ' (副本)';
                    $insertStmt = $pdo->prepare("INSERT INTO pages (filename, title, type, parent_template_id) VALUES (:filename, :title, :type, :parent_id)");
                    $insertStmt->execute([
                        ':filename' => $newFilename,
                        ':title' => $newTitle,
                        ':type' => 'template_instance',
                        ':parent_id' => $page['parent_template_id'] ?? $page['id']
                    ]);
                    
                    update_sitemap($pdo); // 触发 Sitemap 更新
                    
                    echo json_encode(['status' => 'success', 'message' => 'copied']);
                    exit;
                }
            }
            echo json_encode(['status' => 'error', 'message' => '文件复制失败，请检查文件读写权限。']);
            exit;
        }
        echo json_encode(['status' => 'error', 'message' => '找不到源页面数据']);
        exit;
    }
    
    // 删除页面 API
    if ($_GET['api'] === 'delete_page' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        // 为了安全起见，只允许后台删除动态生成的 template_instance (阻止误删静态核心页)
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE id = :id AND type = 'template_instance'");
        $stmt->execute([':id' => $id]);
        $page = $stmt->fetch();
        
        if ($page) {
            $filePath = __DIR__ . '/../' . $page['filename'];
            if (file_exists($filePath)) @unlink($filePath); // 从硬盘永久删除 HTML
            $pdo->prepare("DELETE FROM pages WHERE id = :id")->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM backups WHERE page_id = :id")->execute([':id' => $id]); // 清除关联备份
            
            update_sitemap($pdo); // 触发 Sitemap 更新
            
            echo json_encode(['status' => 'success', 'message' => 'deleted']);
            exit;
        }
        echo json_encode(['status' => 'error', 'message' => '找不到页面数据，或该核心页面禁止删除']);
        exit;
    }

    // 保存静态网页文件并备份的 API
    if ($_GET['api'] === 'save_page' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        $html = $_POST['html'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $page = $stmt->fetch();
        
        if ($page && !empty($html)) {
            $filePath = __DIR__ . '/../' . $page['filename'];
            
            // 如果文件存在，先创建备份
            if (file_exists($filePath)) {
                $oldContent = file_get_contents($filePath);
                $insertBackup = $pdo->prepare("INSERT INTO backups (page_id, content) VALUES (:page_id, :content)");
                $insertBackup->execute([':page_id' => $id, ':content' => $oldContent]);
                
                // 清理旧备份：只保留最新生成的 3 份备份
                $deleteStmt = $pdo->prepare("DELETE FROM backups WHERE page_id = :page_id AND id NOT IN (SELECT id FROM backups WHERE page_id = :page_id ORDER BY backup_date DESC LIMIT 3)");
                $deleteStmt->execute([':page_id' => $id]);
            }
            
            // 写入新的 HTML 内容
            if (file_put_contents($filePath, $html) !== false) {
                echo json_encode(['status' => 'success', 'message' => '保存成功']);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => '保存失败，请检查文件写入权限']);
        exit;
    }
    
    // 获取页面备份列表
    if ($_GET['api'] === 'get_backups' && isset($_GET['page_id'])) {
        $stmt = $pdo->prepare("SELECT id, backup_date FROM backups WHERE page_id = :page_id ORDER BY backup_date DESC");
        $stmt->execute([':page_id' => $_GET['page_id']]);
        $backups = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'data' => $backups]);
        exit;
    }

    // 恢复备份 API
    if ($_GET['api'] === 'restore_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $backupId = $_POST['backup_id'] ?? 0;
        $pageId = $_POST['page_id'] ?? 0;

        $stmt = $pdo->prepare("SELECT b.content, p.filename FROM backups b JOIN pages p ON b.page_id = p.id WHERE b.id = :backup_id AND b.page_id = :page_id");
        $stmt->execute([':backup_id' => $backupId, ':page_id' => $pageId]);
        $record = $stmt->fetch();

        if ($record) {
            $filePath = __DIR__ . '/../' . $record['filename'];
            if (file_put_contents($filePath, $record['content']) !== false) {
                echo json_encode(['status' => 'success', 'message' => '恢复成功']);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => '恢复失败，找不到备份或写入权限不足']);
        exit;
    }
    
    // 获取媒体列表 API
    if ($_GET['api'] === 'get_media') {
        $mediaDir = __DIR__ . '/../img/';
        if (!is_dir($mediaDir)) @mkdir($mediaDir, 0775, true);
        
        $files = array_diff(scandir($mediaDir), ['.', '..']);
        $mediaList = [];
        foreach ($files as $file) {
            if (is_file($mediaDir . $file)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm'])) {
                    $mediaList[] = [
                        'name' => $file,
                        'url' => 'img/' . $file // 相对于根目录的路径
                    ];
                }
            }
        }
        echo json_encode(['status' => 'success', 'data' => array_values($mediaList)]);
        exit;
    }

    // 上传媒体 API
    if ($_GET['api'] === 'upload_media' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mediaDir = __DIR__ . '/../img/';
        if (!is_dir($mediaDir)) @mkdir($mediaDir, 0775, true);

        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', basename($_FILES['file']['name']));
            if (move_uploaded_file($_FILES['file']['tmp_name'], $mediaDir . $filename)) {
                echo json_encode(['status' => 'success', 'url' => 'img/' . $filename]);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => '上传失败']);
        exit;
    }

    // 获取全局SEO文件 API
    if ($_GET['api'] === 'get_global_seo') {
        $basePath = __DIR__ . '/../';
        $files = [
            'sitemap' => file_exists($basePath . 'Sitemap.xml') ? file_get_contents($basePath . 'Sitemap.xml') : '',
            'robots' => file_exists($basePath . 'robots.txt') ? file_get_contents($basePath . 'robots.txt') : '',
            'llm' => file_exists($basePath . 'llm.txt') ? file_get_contents($basePath . 'llm.txt') : '',
            'htaccess' => file_exists($basePath . '.htaccess') ? file_get_contents($basePath . '.htaccess') : '',
            'head_scripts' => '',
            'body_scripts' => ''
        ];

        $stmt = $pdo->query("SELECT key_name, key_value FROM settings WHERE key_name IN ('global_head_scripts', 'global_body_scripts', 'site_domain')");
        while ($row = $stmt->fetch()) {
            if ($row['key_name'] === 'global_head_scripts') $files['head_scripts'] = $row['key_value'];
            if ($row['key_name'] === 'global_body_scripts') $files['body_scripts'] = $row['key_value'];
            if ($row['key_name'] === 'site_domain') $files['site_domain'] = $row['key_value'];
        }
        
        echo json_encode(['status' => 'success', 'data' => $files]);
        exit;
    }

    // 保存全局SEO文件 API
    if ($_GET['api'] === 'save_global_seo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $basePath = __DIR__ . '/../';
        file_put_contents($basePath . 'Sitemap.xml', $_POST['sitemap'] ?? '');
        file_put_contents($basePath . 'robots.txt', $_POST['robots'] ?? '');
        file_put_contents($basePath . 'llm.txt', $_POST['llm'] ?? '');
        file_put_contents($basePath . '.htaccess', $_POST['htaccess'] ?? '');
        
        $domain = $_POST['site_domain'] ?? '';
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key_name, key_value) VALUES ('site_domain', :v)");
        $stmt->execute([':v' => $domain]);

        echo json_encode(['status' => 'success', 'message' => '全局 SEO 设置保存成功']);
        exit;
    }

    // 获取系统设置 API (AI API等)
    if ($_GET['api'] === 'get_settings') {
        $stmt = $pdo->query("SELECT * FROM settings");
        $settings = [];
        while ($row = $stmt->fetch()) { $settings[$row['key_name']] = $row['key_value']; }
        // 强制将 data 转为 object, 确保即使为空也返回 {} 而不是 []
        echo json_encode(['status' => 'success', 'data' => (object)$settings]);
        exit;
    }

    // 保存系统设置 API
    if ($_GET['api'] === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key_name, key_value) VALUES (:k, :v)");
        foreach ($_POST as $key => $value) {
            $stmt->execute([':k' => $key, ':v' => $value]);
        }
        echo json_encode(['status' => 'success', 'message' => '设置已保存']);
        exit;
    }

    // AI 生成 Schema Code API
    if ($_GET['api'] === 'generate_schema' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $htmlText = strip_tags($_POST['html'] ?? '');
        $htmlText = substr(preg_replace('/\s+/', ' ', $htmlText), 0, 8000); // 截取前8000个字符以防 Token 超限
        
        $provider = $_POST['ai_provider'] ?? '';
        $apiKey = $_POST['ai_api_key'] ?? '';
        $model = $_POST['ai_model'] ?? '';
        
        if (empty($apiKey)) { echo json_encode(['status' => 'error', 'message' => '未配置 API Key']); exit; }
        
        $prompt = "You are an expert SEO JSON-LD schema generator. Create a valid, comprehensive JSON-LD schema code for the following webpage content. CRITICAL INSTRUCTION: You must return ONLY the raw JSON object starting with '{' and ending with '}'. Do not include any conversational text, do not wrap it in markdown code blocks, and do not explain the code.\n\nContent:\n" . $htmlText;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 增加超时时间至 120 秒防止长文本生成断连

        if ($provider === 'gemini') {
            $model = $model ?: 'gemini-2.5-flash';
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
            $data = json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['maxOutputTokens' => 8192] // 增加 Gemini 最大生成长度
            ]);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            $url = $provider === 'groq' ? 'https://api.groq.com/openai/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
            if ($provider === 'groq') $model = $model ?: 'qwen/qwen3-32b';
            if ($provider === 'openai') $model = $model ?: 'gpt-3.5-turbo';
            $data = json_encode([
                'model' => $model, 
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => $provider === 'groq' ? 2048 : 6000 // 降低 Groq 的 max_tokens 避免超出免费额度的 TPM 限制
            ]);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            echo json_encode(['status' => 'error', 'message' => '服务器网络请求失败: ' . $curlErr]);
            exit;
        }

        $resData = json_decode($response, true);

        $schemaStr = '';
        if ($provider === 'gemini') {
            $schemaStr = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } else {
            $schemaStr = $resData['choices'][0]['message']['content'] ?? '';
        }
        
        // 如果由于额度不足、Key错误等原因未返回内容，抓取错误信息回传
        if (empty($schemaStr) && isset($resData['error'])) {
            echo json_encode(['status' => 'error', 'message' => 'API报错: ' . json_encode($resData['error'], JSON_UNESCAPED_UNICODE)]);
            exit;
        }
        
        // 强制提取真实的 JSON 字符串，剥离任何可能被混入的闲聊废话或 Markdown 标记
        $schemaStr = trim($schemaStr);
        $start = strpos($schemaStr, '{');
        $end = strrpos($schemaStr, '}');
        if ($start !== false && $end !== false) {
            $schemaStr = substr($schemaStr, $start, $end - $start + 1);
        }
        
        echo json_encode(['status' => 'success', 'schema' => $schemaStr]); exit;
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS Admin</title>
    <!-- 引入 Tailwind CSS (无构建) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- 引入 Alpine.js 处理前端状态与交互 -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <!-- 引入 Cropper.js CSS 和 JS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <!-- 配置 Tailwind 主题色 -->
    <script>
        tailwind.config = {
            theme: {
                extend: { colors: { primary: '#2563eb', } }
            }
        }
    </script>
</head>
<!-- 增加 @message.window 监听 iframe 传来的 postMessage -->
<body class="bg-gray-50 text-gray-800 h-screen flex overflow-hidden" x-data="cmsApp()" @message.window="handleMessage($event)">

    <!-- 左侧导航栏 -->
    <aside class="w-64 bg-white border-r shadow-sm flex flex-col z-20">
        <div class="h-16 flex items-center justify-center border-b font-bold text-xl text-primary tracking-wide">
            SSG-CMS
        </div>
        
        <div class="flex-1 overflow-y-auto py-4">
            <nav class="space-y-1 px-3">
                <!-- 静态页面 Tab -->
                <button @click="switchTab('static')" 
                        :class="{'bg-blue-50 text-primary': currentTab === 'static', 'hover:bg-gray-100': currentTab !== 'static'}"
                        class="w-full text-left px-3 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    Pages
                </button>

                <!-- 动态渲染模板 Tab -->
                <template x-for="(pages, templateName) in templateGroups" :key="templateName">
                    <button @click="switchTab('template_' + templateName)" 
                            :class="{'bg-blue-50 text-primary': currentTab === 'template_' + templateName, 'hover:bg-gray-100': currentTab !== 'template_' + templateName}"
                            class="w-full text-left px-3 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition mt-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"></path></svg>
                        <span x-text="templateName + ' 模板'"></span>
                    </button>
                </template>

                <!-- 媒体管理 Tab -->
                <button @click="switchTab('media')" 
                        :class="{'bg-blue-50 text-primary': currentTab === 'media', 'hover:bg-gray-100': currentTab !== 'media'}"
                        class="w-full text-left px-3 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition mt-4 border-t pt-4">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    Meida Library
                </button>
                
                <!-- 全局 SEO Tab -->
                <button @click="switchTab('global_seo')" 
                        :class="{'bg-blue-50 text-primary': currentTab === 'global_seo', 'hover:bg-gray-100': currentTab !== 'global_seo'}"
                        class="w-full text-left px-3 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition mt-1 border-t pt-3">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
                    Global SEO
                </button>
                
                <!-- AI 设置 Tab -->
                <button @click="switchTab('ai_settings')" 
                        :class="{'bg-blue-50 text-primary': currentTab === 'ai_settings', 'hover:bg-gray-100': currentTab !== 'ai_settings'}"
                        class="w-full text-left px-3 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition mt-1 border-t pt-3">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    AI Settings
                </button>
            </nav>
        </div>
        
        <div class="p-4 border-t text-sm">
            <span class="text-gray-500">Login as: <?= htmlspecialchars($_SESSION['username']) ?></span>
            <a href="cms.php?action=logout" class="block mt-2 text-red-500 hover:text-red-700 font-medium">Logout</a>
        </div>
    </aside>

    <!-- 右侧主内容区 -->
    <main class="flex-1 flex flex-col bg-gray-50 h-screen overflow-hidden relative">
        <!-- 顶部工具栏 (可放置保存按钮等) -->
        <header class="h-16 bg-white border-b shadow-sm flex items-center justify-between px-6 z-10">
            <h2 class="text-xl font-semibold" x-text="getPageTitle()"></h2>
            
            <div x-show="editingPage" class="flex gap-3">
                <button @click="showSeoPanel = !showSeoPanel" class="px-4 py-2 text-sm bg-indigo-50 text-indigo-700 hover:bg-indigo-100 rounded font-medium transition">
                    SEO Settings
                </button>
                <button @click="openHistory()" class="px-4 py-2 text-sm bg-gray-200 hover:bg-gray-300 rounded font-medium transition">
                    History
                </button>
                <button @click="requestSave()" class="px-4 py-2 text-sm bg-primary hover:bg-blue-700 text-white rounded font-medium shadow-sm transition">
                    Save
                </button>
            </div>
        </header>

        <!-- 内容渲染区 -->
        <div class="flex-1 overflow-y-auto p-6" id="main-content">
            
            <!-- 页面列表视图 -->
            <div x-show="!editingPage && currentTab !== 'media' && currentTab !== 'global_seo' && currentTab !== 'ai_settings'" class="bg-white rounded-lg shadow-sm border p-4">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">File Name</th>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                            <th class="px-4 py-3 bg-gray-50 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template x-for="page in getCurrentTabPages()" :key="page.id">
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm text-gray-900 font-medium" x-text="page.filename"></td>
                                <td class="px-4 py-3 text-sm text-gray-500" x-text="page.title || '未命名'"></td>
                                <td class="px-4 py-3 text-sm text-right font-medium space-x-2">
                                    <button @click="openEditor(page)" class="text-blue-600 hover:text-blue-900">Edit</button>
                                    <template x-if="page.type === 'template_instance'">
                                        <div class="inline-block space-x-2">
                                            <button @click="duplicatePage(page)" class="text-green-600 hover:text-green-900">Copy</button>
                                            <button @click="deletePage(page)" class="text-red-600 hover:text-red-900">Delete</button>
                                        </div>
                                    </template>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="getCurrentTabPages().length === 0">
                            <td colspan="3" class="px-4 py-8 text-center text-gray-500">No pages under this section</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Iframe 编辑器视图 -->
            <div x-show="editingPage" class="h-full w-full bg-white border shadow-inner rounded-lg overflow-hidden flex flex-col">
                <div class="bg-gray-100 p-2 border-b flex items-center justify-between text-sm">
                    <span class="text-gray-600 ml-2">Editing: <span class="font-bold" x-text="editingPage?.filename"></span></span>
                    <button @click="closeEditor()" class="text-gray-500 hover:text-red-500 px-2 py-1">Close Editor X</button>
                </div>
                <div class="flex-1 flex overflow-hidden">
                    <!-- iframe 的 src 将由 JS 动态注入 -->
                    <iframe id="visual-editor" class="flex-1 h-full w-full border-none" sandbox="allow-same-origin allow-scripts allow-modals"></iframe>
                    
                    <!-- SEO 面板 -->
                    <div x-show="showSeoPanel" class="w-80 border-l bg-gray-50 flex flex-col overflow-hidden" style="display: none;" x-transition>
                        <div class="px-4 py-3 border-b bg-gray-100 flex justify-between items-center font-medium text-gray-700 shadow-sm z-10">
                            <span>On-Page SEO</span>
                            <button @click="showSeoPanel = false" class="text-gray-400 hover:text-red-500 text-lg">&times;</button>
                        </div>
                        <div class="flex-1 overflow-y-auto p-4 space-y-6">
                            
                            <!-- General SEO -->
                            <div class="space-y-3">
                                <h4 class="font-semibold text-xs text-gray-500 uppercase tracking-wider">General Meta</h4>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">SEO Title</label>
                                    <input type="text" x-model="seoData.title" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Meta Description</label>
                                    <textarea x-model="seoData.description" rows="3" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary"></textarea>
                                </div>
                            </div>

                            <hr class="border-gray-200">

                            <!-- Indexing -->
                            <div class="space-y-3">
                                <h4 class="font-semibold text-xs text-gray-500 uppercase tracking-wider">Indexing / Robots</h4>
                                <div class="flex gap-4">
                                    <label class="flex items-center text-sm"><input type="radio" x-model="seoData.robotsIndex" value="index" class="mr-1"> Index</label>
                                    <label class="flex items-center text-sm"><input type="radio" x-model="seoData.robotsIndex" value="noindex" class="mr-1"> Noindex</label>
                                </div>
                                <div class="flex gap-4">
                                    <label class="flex items-center text-sm"><input type="radio" x-model="seoData.robotsFollow" value="follow" class="mr-1"> Follow</label>
                                    <label class="flex items-center text-sm"><input type="radio" x-model="seoData.robotsFollow" value="nofollow" class="mr-1"> Nofollow</label>
                                </div>
                                <div class="pt-2">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Canonical URL</label>
                                    <input type="text" x-model="seoData.canonical" placeholder="https://..." class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary">
                                </div>
                            </div>

                            <hr class="border-gray-200">

                            <!-- Social Media (Open Graph) -->
                            <div class="space-y-3">
                                <h4 class="font-semibold text-xs text-gray-500 uppercase tracking-wider">Social Media (OG)</h4>
                                <div><label class="block text-xs font-medium text-gray-700 mb-1">OG Title</label><input type="text" x-model="seoData.ogTitle" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary"></div>
                                <div><label class="block text-xs font-medium text-gray-700 mb-1">OG Description</label><textarea x-model="seoData.ogDescription" rows="2" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary"></textarea></div>
                                <div><label class="block text-xs font-medium text-gray-700 mb-1">OG Image URL</label><input type="text" x-model="seoData.ogImage" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary"></div>
                                <div><label class="block text-xs font-medium text-gray-700 mb-1">Facebook Publisher URL</label><input type="text" x-model="seoData.fbPublisher" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary"></div>
                            </div>

                            <hr class="border-gray-200">

                            <!-- Twitter Card -->
                            <div class="space-y-3 pb-6">
                                <h4 class="font-semibold text-xs text-gray-500 uppercase tracking-wider">Twitter</h4>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Card Type</label>
                                    <select x-model="seoData.twitterCard" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary">
                                        <option value="summary">Summary</option>
                                        <option value="summary_large_image">Summary Large Image</option>
                                    </select>
                                </div>
                                <div><label class="block text-xs font-medium text-gray-700 mb-1">Twitter Site (@username)</label><input type="text" x-model="seoData.twitterSite" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary"></div>
                            </div>
                            
                            <hr class="border-gray-200">

                            <!-- JSON-LD Schema -->
                            <div class="space-y-3 pb-6">
                                <h4 class="font-semibold text-xs text-gray-500 uppercase tracking-wider">Schema Markup (JSON-LD)</h4>
                                <div>
                                    <textarea x-model="seoData.schemaCode" rows="6" class="w-full text-xs font-mono border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:border-primary" placeholder="{ &quot;@context&quot;: &quot;https://schema.org&quot; ... }"></textarea>
                                    <button @click="generateSchemaWithAI()" type="button" :disabled="isGenerating" class="mt-2 w-full text-sm bg-purple-50 text-purple-700 font-medium px-3 py-2 rounded hover:bg-purple-100 border border-purple-200 transition flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg> 
                                        <span x-text="isGenerating ? '正在由 AI 撰写中 (约需10秒)...' : '使用 AI 智能生成 Schema'"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 媒体库视图 -->
            <div x-show="currentTab === 'media'" class="bg-white rounded-lg shadow-sm border p-4 h-full flex flex-col">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium">媒体资源库 (img/ 文件夹)</h3>
                    <label class="px-4 py-2 bg-primary text-white rounded cursor-pointer hover:bg-blue-700 transition shadow-sm text-sm font-medium">
                        <span>上传新媒体</span>
                        <input type="file" class="hidden" @change="handleFileSelect($event)" accept="image/*,video/*">
                    </label>
                </div>
                <div class="flex-1 overflow-y-auto border rounded p-4 bg-gray-50 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 content-start">
                    <template x-for="media in mediaFiles" :key="media.name">
                        <div class="bg-white border rounded shadow-sm overflow-hidden flex flex-col group relative">
                            <div class="h-32 bg-gray-200 flex items-center justify-center overflow-hidden">
                                <img :src="'../' + media.url" class="object-cover w-full h-full" loading="lazy" x-show="media.url.match(/\.(jpg|jpeg|png|gif|webp|svg)$/i)">
                                <span class="text-xs text-gray-500" x-show="!media.url.match(/\.(jpg|jpeg|png|gif|webp|svg)$/i)" x-text="'VIDEO'"></span>
                            </div>
                            <div class="p-2 text-xs truncate text-center bg-white border-t" x-text="media.name" :title="media.name"></div>
                        </div>
                    </template>
                    <div x-show="mediaFiles.length === 0" class="col-span-full text-center py-8 text-gray-500">资源库为空</div>
                </div>
            </div>

            <!-- 全局 SEO 视图 -->
            <div x-show="currentTab === 'global_seo'" class="bg-white rounded-lg shadow-sm border p-4 h-full flex flex-col" style="display: none;">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium">全局 SEO 配置文件 (Root Directory)</h3>
                    <button @click="saveGlobalSeo()" class="px-4 py-2 bg-primary text-white rounded font-medium shadow-sm hover:bg-blue-700 transition text-sm">
                        Save Global SEO
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto space-y-5 pr-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">主域名 (Site Domain) <span class="text-xs text-gray-500 font-normal">(用于生成绝对路径 Sitemap)</span></label>
                        <input type="text" x-model="globalSeoFiles.site_domain" class="w-full text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary" placeholder="例如: https://www.example.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Sitemap.xml <span class="text-xs text-gray-500 font-normal">(系统会在添加/删除页面时自动更新此文件)</span></label>
                        <textarea x-model="globalSeoFiles.sitemap" rows="8" class="w-full font-mono text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary placeholder-gray-400" placeholder="<?xml version='1.0' encoding='UTF-8'?>..."></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">robots.txt</label>
                        <textarea x-model="globalSeoFiles.robots" rows="5" class="w-full font-mono text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary placeholder-gray-400" placeholder="User-agent: *..."></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">llm.txt <span class="text-xs text-gray-500 font-normal">(用于指导 AI 爬虫如 ChatGPT/Claude 读取整站资料)</span></label>
                        <textarea x-model="globalSeoFiles.llm" rows="5" class="w-full font-mono text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">.htaccess</label>
                        <textarea x-model="globalSeoFiles.htaccess" rows="8" class="w-full font-mono text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary"></textarea>
                    </div>
                    
                    <hr class="my-4 border-gray-200">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Global &lt;head&gt; Scripts <span class="text-xs text-gray-500 font-normal">(如 GTM, GA4, FB Pixel等)</span></label>
                        <textarea x-model="globalSeoFiles.head_scripts" rows="6" class="w-full font-mono text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary placeholder-gray-400" placeholder="<!-- 将自动注入全站的 <head> 内 -->"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Global &lt;body&gt; Scripts <span class="text-xs text-gray-500 font-normal">(如 GTM 的 &lt;noscript&gt; 标签等)</span></label>
                        <textarea x-model="globalSeoFiles.body_scripts" rows="6" class="w-full font-mono text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary placeholder-gray-400" placeholder="<!-- 将自动注入全站的 <body> 下方 -->"></textarea>
                    </div>
                </div>
            </div>

            <!-- AI 设置视图 -->
            <div x-show="currentTab === 'ai_settings'" class="bg-white rounded-lg shadow-sm border p-6 max-w-2xl" style="display: none;">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-medium">AI API 设置</h3>
                    <button @click="saveAiSettings()" class="px-4 py-2 bg-primary text-white rounded font-medium shadow-sm hover:bg-blue-700 transition text-sm">
                        Save AI Settings
                    </button>
                </div>
                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">API 端口类型</label>
                        <select x-model="aiSettings.ai_provider" class="w-full text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary">
                            <option value="openai">OpenAI</option>
                            <option value="groq">Groq (推荐, 极速)</option>
                            <option value="gemini">Google Gemini</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">API Key (密钥)</label>
                        <input type="password" x-model="aiSettings.ai_api_key" class="w-full text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary" placeholder="sk-...">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">模型名称 <span class="text-xs text-gray-500 font-normal">(选填，留空则使用默认模型)</span></label>
                        <input type="text" x-model="aiSettings.ai_model" class="w-full text-sm border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary" placeholder="例如: gpt-4o 或 llama3-8b-8192">
                        <p class="text-xs text-gray-500 mt-1">此配置将用于全站范围内的 AI 生成能力（如 SEO Schema 自动生成）。</p>
                    </div>
                </div>
            </div>

        </div>
        
        <!-- 超链接编辑弹窗 -->
        <div x-show="linkModalOpen" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" style="display: none;">
            <div class="bg-white rounded-lg shadow-xl w-96 overflow-hidden" @click.away="linkModalOpen = false">
                <div class="bg-gray-50 px-4 py-3 border-b font-medium">编辑超链接</div>
                <div class="p-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">链接文本</label>
                        <input type="text" x-model="editingData.text" class="w-full border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary sm:text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">目标 URL</label>
                        <input type="text" x-model="editingData.href" class="w-full border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary sm:text-sm">
                    </div>
                    <div class="flex items-center">
                        <input type="checkbox" id="link-target" x-bind:checked="editingData.target === '_blank'" @change="editingData.target = $event.target.checked ? '_blank' : '_self'" class="h-4 w-4 text-primary border-gray-300 rounded">
                        <label for="link-target" class="ml-2 block text-sm text-gray-900">在新标签页中打开 (_blank)</label>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 border-t flex justify-end space-x-2">
                    <button @click="linkModalOpen = false" class="px-4 py-2 bg-white border rounded text-sm font-medium text-gray-700 hover:bg-gray-50">取消</button>
                    <button @click="saveLink()" class="px-4 py-2 bg-primary text-white rounded text-sm font-medium hover:bg-blue-700">保存修改</button>
                </div>
            </div>
        </div>
        
        <!-- 媒体基础信息弹窗 (图片裁剪/上传的占位，目前先实现文字数据互通) -->
        <div x-show="mediaModalOpen" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" style="display: none;">
            <div class="bg-white rounded-lg shadow-xl w-96 overflow-hidden" @click.away="mediaModalOpen = false">
                <div class="bg-gray-50 px-4 py-3 border-b font-medium">编辑多媒体属性</div>
                <div class="p-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">图片/视频地址 (Src)</label>
                        <input type="text" x-model="editingData.src" class="w-full border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary sm:text-sm">
                        
                        <div class="mt-3 h-32 overflow-y-auto border rounded bg-gray-50 grid grid-cols-3 gap-2 p-2">
                            <template x-for="media in mediaFiles" :key="media.name">
                                <img :src="'../' + media.url" class="object-cover w-full h-16 cursor-pointer border hover:border-primary hover:shadow-md transition rounded" @click="editingData.src = media.url" title="点击使用此图片">
                            </template>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">点击上方图库的图片快速替换，或输入外部链接。</p>
                    </div>
                    <template x-if="editingData.tagName === 'img'">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">替代文本 (Alt)</label>
                                <input type="text" x-model="editingData.alt" class="w-full border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary sm:text-sm">
                            </div>
                            <div class="pt-1 flex gap-2">
                                <button @click="openVideoConversion()" type="button" class="flex-1 text-xs bg-indigo-50 text-indigo-600 px-3 py-2 rounded border border-indigo-200 hover:bg-indigo-100 transition font-medium">将此图片转换成视频</button>
                                <button @click="openSliderConversion()" type="button" class="flex-1 text-xs bg-teal-50 text-teal-600 px-3 py-2 rounded border border-teal-200 hover:bg-teal-100 transition font-medium">将此图片转换成轮播</button>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="bg-gray-50 px-4 py-3 border-t flex justify-end space-x-2">
                    <button @click="mediaModalOpen = false" class="px-4 py-2 bg-white border rounded text-sm font-medium text-gray-700 hover:bg-gray-50">取消</button>
                    <button @click="saveMedia()" class="px-4 py-2 bg-primary text-white rounded text-sm font-medium hover:bg-blue-700">应用修改</button>
                </div>
            </div>
        </div>

        <!-- 转换为视频弹窗 -->
        <div x-show="videoModalOpen" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" style="display: none;">
            <div class="bg-white rounded-lg shadow-xl w-96 overflow-hidden" @click.away="videoModalOpen = false">
                <div class="bg-gray-50 px-4 py-3 border-b font-medium">转换为视频元素</div>
                <div class="p-4 space-y-4">
                    <div class="flex items-center space-x-4 mb-2">
                        <label class="flex items-center text-sm cursor-pointer">
                            <input type="radio" x-model="videoData.type" value="local" class="mr-1 text-primary"> 本地视频
                        </label>
                        <label class="flex items-center text-sm cursor-pointer">
                            <input type="radio" x-model="videoData.type" value="embed" class="mr-1 text-primary"> 嵌入视频 (iframe)
                        </label>
                    </div>

                    <!-- 本地视频配置 -->
                    <template x-if="videoData.type === 'local'">
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">视频地址 (从媒体库选择或输入)</label>
                                <input type="text" x-model="videoData.src" class="w-full border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary sm:text-sm">
                                
                                <div class="mt-3 h-32 overflow-y-auto border rounded bg-gray-50 grid grid-cols-3 gap-2 p-2">
                                    <template x-for="media in mediaFiles.filter(m => m.url.match(/\.(mp4|webm)$/i))" :key="media.name">
                                        <div class="h-16 bg-black flex items-center justify-center cursor-pointer border hover:border-primary hover:shadow-md transition rounded overflow-hidden" @click="videoData.src = media.url" :title="media.name">
                                            <span class="text-white text-[10px] break-all p-1 text-center" x-text="media.name"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-2">
                                <label class="flex items-center text-sm text-gray-700"><input type="checkbox" x-model="videoData.autoplay" class="mr-2 rounded text-primary"> 自动播放</label>
                                <label class="flex items-center text-sm text-gray-700"><input type="checkbox" x-model="videoData.loop" class="mr-2 rounded text-primary"> 循环播放</label>
                                <label class="flex items-center text-sm text-gray-700"><input type="checkbox" x-model="videoData.muted" class="mr-2 rounded text-primary"> 静音</label>
                                <label class="flex items-center text-sm text-gray-700"><input type="checkbox" x-model="videoData.controls" class="mr-2 rounded text-primary"> 显示控制条</label>
                            </div>
                        </div>
                    </template>

                    <!-- 嵌入视频配置 -->
                    <template x-if="videoData.type === 'embed'">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Iframe 代码 (如 YouTube/Vimeo)</label>
                            <textarea x-model="videoData.iframeCode" rows="6" class="w-full border-gray-300 border rounded-md shadow-sm py-2 px-3 focus:ring-primary focus:border-primary sm:text-sm" placeholder="<iframe src='...' width='100%' height='100%' frameborder='0' allowfullscreen></iframe>"></textarea>
                        </div>
                    </template>
                </div>
                <div class="bg-gray-50 px-4 py-3 border-t flex justify-end space-x-2">
                    <button @click="videoModalOpen = false" class="px-4 py-2 bg-white border rounded text-sm font-medium text-gray-700 hover:bg-gray-50">取消</button>
                    <button @click="saveVideoConversion()" class="px-4 py-2 bg-primary text-white rounded text-sm font-medium hover:bg-blue-700">确认替换</button>
                </div>
            </div>
        </div>

        <!-- 转换为轮播(Swiper)弹窗 -->
        <div x-show="sliderModalOpen" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" style="display: none;">
            <div class="bg-white rounded-lg shadow-xl w-[32rem] max-h-[90vh] flex flex-col overflow-hidden" @click.away="sliderModalOpen = false">
                <div class="bg-gray-50 px-4 py-3 border-b font-medium">转换为轮播图 (Swiper)</div>
                <div class="p-4 overflow-y-auto flex-1 space-y-5 bg-gray-50">
                    
                    <!-- 轮播全局设置 -->
                    <div class="bg-white p-3 rounded border shadow-sm space-y-3">
                        <h4 class="text-xs font-semibold text-gray-500 uppercase">全局设置</h4>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">宽度 (Width)</label>
                                <input type="text" x-model="sliderData.width" class="w-full text-sm border-gray-300 border rounded px-2 py-1.5 focus:ring-primary focus:border-primary" placeholder="例如: 100%">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">高度 (Height)</label>
                                <input type="text" x-model="sliderData.height" class="w-full text-sm border-gray-300 border rounded px-2 py-1.5 focus:ring-primary focus:border-primary" placeholder="例如: 400px 或 auto">
                            </div>
                        </div>
                        <label class="flex items-center text-sm text-gray-700">
                            <input type="checkbox" x-model="sliderData.pagination" class="mr-2 rounded text-primary"> 
                            显示底部指示器 (Pagination)
                        </label>
                    </div>

                    <!-- Slides 列表 (Repeater) -->
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase">幻灯片内容 (Slides)</h4>
                            <button @click="addSlide()" class="text-xs bg-blue-50 text-blue-600 px-2 py-1 rounded border border-blue-200 hover:bg-blue-100">+ 添加一项</button>
                        </div>
                        
                        <template x-for="(slide, index) in sliderData.slides" :key="slide.id">
                            <div class="bg-white p-3 rounded border shadow-sm relative">
                                <button x-show="sliderData.slides.length > 1" @click="removeSlide(index)" class="absolute top-2 right-2 text-red-400 hover:text-red-600 text-xs px-2 py-1 bg-red-50 rounded border border-red-100">删除</button>
                                <div class="space-y-3 mt-1">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">背景图片地址</label>
                                        <div class="flex gap-2">
                                            <input type="text" x-model="slide.src" class="flex-1 text-sm border-gray-300 border rounded px-2 py-1 focus:ring-primary focus:border-primary">
                                        </div>
                                        <!-- 小型媒体选择器 -->
                                        <div class="mt-2 h-16 overflow-y-auto border rounded bg-gray-50 grid grid-cols-6 gap-1 p-1">
                                            <template x-for="media in mediaFiles.filter(m => m.url.match(/\.(jpg|jpeg|png|webp)$/i))" :key="media.name">
                                                <img :src="'../' + media.url" class="object-cover w-full h-8 cursor-pointer border hover:border-primary rounded" @click="slide.src = media.url" :title="media.name">
                                            </template>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">主标题 (Title)</label>
                                            <input type="text" x-model="slide.title" class="w-full text-sm border-gray-300 border rounded px-2 py-1.5 focus:ring-primary">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">文本排版位置</label>
                                            <select x-model="slide.textPosition" class="w-full text-sm border-gray-300 border rounded px-2 py-1.5 focus:ring-primary">
                                                <option value="top-left">上方左对齐</option><option value="top-center">上方居中</option><option value="top-right">上方右对齐</option>
                                                <option value="center-left">中间左对齐</option><option value="center-center">中间居中</option><option value="center-right">中间右对齐</option>
                                                <option value="bottom-left">下方左对齐</option><option value="bottom-center">下方居中</option><option value="bottom-right">下方右对齐</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">描述 (Description)</label>
                                        <textarea x-model="slide.description" rows="2" class="w-full text-sm border-gray-300 border rounded px-2 py-1.5 focus:ring-primary"></textarea>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                </div>
                <div class="bg-white px-4 py-3 border-t flex justify-end space-x-2">
                    <button @click="sliderModalOpen = false" class="px-4 py-2 bg-white border rounded text-sm font-medium text-gray-700 hover:bg-gray-50">取消</button>
                    <button @click="saveSliderConversion()" class="px-4 py-2 bg-primary text-white rounded text-sm font-medium hover:bg-blue-700">生成轮播并替换</button>
                </div>
            </div>
        </div>

        <!-- 历史备份弹窗 -->
        <div x-show="historyModalOpen" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" style="display: none;">
            <div class="bg-white rounded-lg shadow-xl w-96 overflow-hidden" @click.away="historyModalOpen = false">
                <div class="bg-gray-50 px-4 py-3 border-b font-medium flex justify-between items-center">
                    <span>历史备份记录 (最多保存3份)</span>
                    <button @click="historyModalOpen = false" class="text-gray-400 hover:text-red-500">&times;</button>
                </div>
                <div class="p-4 space-y-3">
                    <template x-if="backups.length === 0">
                        <p class="text-sm text-gray-500 text-center py-4">暂无备份记录</p>
                    </template>
                    <template x-for="backup in backups" :key="backup.id">
                        <div class="flex justify-between items-center bg-gray-50 p-3 rounded border">
                            <span class="text-sm text-gray-700 font-medium" x-text="backup.backup_date"></span>
                            <button @click="restoreBackup(backup.id)" class="px-3 py-1.5 bg-green-600 text-white text-xs rounded font-medium hover:bg-green-700 transition">
                                恢复至此版本
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- 图片裁剪弹窗 -->
        <div x-show="cropperModalOpen" class="fixed inset-0 bg-black bg-opacity-75 z-[60] flex items-center justify-center" style="display: none;">
            <div class="bg-white rounded-lg shadow-xl w-11/12 max-w-3xl overflow-hidden flex flex-col h-[80vh]">
                <div class="bg-gray-50 px-4 py-3 border-b font-medium flex justify-between items-center">
                    <span>裁剪并转换为 WebP</span>
                    <button @click="closeCropper()" class="text-gray-400 hover:text-red-500">&times;</button>
                </div>
                <div class="flex-1 bg-gray-200 overflow-hidden flex items-center justify-center p-4">
                    <img id="cropper-image" :src="cropTargetImage" class="max-w-full max-h-full block">
                </div>
                <div class="bg-gray-50 px-4 py-3 border-t flex justify-end space-x-2">
                    <button @click="closeCropper()" class="px-4 py-2 bg-white border rounded text-sm font-medium text-gray-700 hover:bg-gray-50">取消</button>
                    <button @click="confirmCrop()" class="px-4 py-2 bg-primary text-white rounded text-sm font-medium hover:bg-blue-700">确认裁剪并上传</button>
                </div>
            </div>
        </div>
    </main>

    <!-- Alpine.js 核心逻辑 -->
    <script>
        function cmsApp() {
            return {
                currentTab: 'static',
                staticPages: [],
                templateGroups: {}, // 格式: { 'template1': [page1, page2], 'template2': [...] }
                editingPage: null,
                
                historyModalOpen: false,
                backups: [],
                
                mediaFiles: [], // 用于存储图片和视频资源列表
                
                linkModalOpen: false,
                mediaModalOpen: false,
                videoModalOpen: false,
                sliderModalOpen: false,
                editingData: {}, // 临时存储由 iframe 传来的待编辑数据
                videoData: { type: 'local', src: '', iframeCode: '', autoplay: true, loop: true, muted: true, controls: false },
                sliderData: { width: '100%', height: '400px', pagination: true, slides: [] },
                
                showSeoPanel: false,
                seoData: { 
                    title: '', description: '', canonical: '', robotsIndex: 'index', robotsFollow: 'follow',
                    ogTitle: '', ogDescription: '', ogImage: '', twitterCard: 'summary_large_image', 
                    twitterSite: '', fbPublisher: '',
                    schemaCode: ''
                },
                
                cropperModalOpen: false,
                cropTargetImage: '',
                originalFileName: '',
                cropperInstance: null,
                
                globalSeoFiles: { site_domain: '', sitemap: '', robots: '', llm: '', htaccess: '', head_scripts: '', body_scripts: '' },
                
                aiSettings: { ai_provider: 'openai', ai_api_key: '', ai_model: '' },
                
                isGenerating: false, // 控制按钮 loading 状态

                init() {
                    this.fetchPages();
                    this.fetchMedia();
                    this.fetchGlobalSeo();
                    this.fetchAiSettings();
                },

                // 从后端 API 获取页面结构
                async fetchPages() {
                    try {
                        const res = await fetch('cms.php?api=get_pages');
                        const json = await res.json();
                        if (json.status === 'success') {
                            this.staticPages = json.data.static;
                            this.templateGroups = json.data.templates;
                        }
                    } catch (error) {
                        console.error("加载页面失败", error);
                    }
                },

                // 根据当前 Tab 动态获取要在表格中显示的页面列表
                getCurrentTabPages() {
                    if (this.currentTab === 'static') return this.staticPages;
                    if (this.currentTab.startsWith('template_')) {
                        const tplName = this.currentTab.replace('template_', '');
                        return this.templateGroups[tplName] || [];
                    }
                    return [];
                },
                
                // 获取图库列表
                async fetchMedia() {
                    try {
                        const res = await fetch('cms.php?api=get_media');
                        const json = await res.json();
                        if (json.status === 'success') {
                            this.mediaFiles = json.data;
                        }
                    } catch (error) {
                        console.error("加载媒体失败", error);
                    }
                },
                
                // 处理文件选择 (分离图片与视频逻辑)
                handleFileSelect(event) {
                    const file = event.target.files[0];
                    if (!file) return;

                    // 如果是视频，直接上传
                    if (file.type.startsWith('video/')) {
                        this.uploadFile(file, file.name);
                        return;
                    }

                    // 如果是图片，载入裁剪器
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        this.cropTargetImage = e.target.result;
                        this.originalFileName = file.name;
                        this.cropperModalOpen = true;
                        
                        // 延迟等待 DOM 渲染后初始化 Cropper
                        setTimeout(() => {
                            const imageEl = document.getElementById('cropper-image');
                            if (this.cropperInstance) this.cropperInstance.destroy();
                            this.cropperInstance = new Cropper(imageEl, {
                                viewMode: 1, // 限制裁剪框不能超出图片范围
                                autoCropArea: 1,
                            });
                        }, 100);
                    };
                    reader.readAsDataURL(file);
                    event.target.value = ''; // 重置 input
                },

                // 确认裁剪并转换为 WebP
                confirmCrop() {
                    if (!this.cropperInstance) return;
                    const canvas = this.cropperInstance.getCroppedCanvas();
                    if (!canvas) return;

                    // 转换为 WebP (质量0.85)
                    canvas.toBlob((blob) => {
                        // 将原文件名后缀替换为 .webp
                        let newName = this.originalFileName.replace(/\.[^/.]+$/, "") + ".webp";
                        this.uploadFile(blob, newName);
                        this.closeCropper();
                    }, 'image/webp', 0.85);
                },

                closeCropper() {
                    this.cropperModalOpen = false;
                    if (this.cropperInstance) {
                        this.cropperInstance.destroy();
                        this.cropperInstance = null;
                    }
                },

                // 统一的上传执行函数
                async uploadFile(fileOrBlob, filename) {
                    const formData = new FormData();
                    formData.append('file', fileOrBlob, filename);
                    try {
                        const res = await fetch('cms.php?api=upload_media', { method: 'POST', body: formData });
                        const json = await res.json();
                        if (json.status === 'success') { 
                            this.fetchMedia(); /* 刷新媒体库 */ 
                        } else { 
                            alert('上传失败: ' + json.message); 
                        }
                    } catch (error) { 
                        alert('上传网络请求出错'); 
                    }
                },
                
                switchTab(tab) {
                    if (this.editingPage) {
                        if (confirm('您当前正在编辑页面，如果未点击“Save”保存，修改的数据将会丢失。确定要离开吗？')) {
                            this.closeEditor();
                            this.currentTab = tab;
                        }
                    } else {
                        this.currentTab = tab;
                    }
                },

            // 获取全局 SEO 文件内容
            async fetchGlobalSeo() {
                try {
                    const res = await fetch('cms.php?api=get_global_seo');
                    const json = await res.json();
                    if (json.status === 'success') {
                        this.globalSeoFiles = json.data;
                    }
                } catch (error) {
                    console.error("加载全局SEO文件失败", error);
                }
            },
            
            // 保存全局 SEO 文件内容
            async saveGlobalSeo() {
                try {
                    let formData = new FormData();
                        formData.append('site_domain', this.globalSeoFiles.site_domain);
                    formData.append('sitemap', this.globalSeoFiles.sitemap);
                    formData.append('robots', this.globalSeoFiles.robots);
                    formData.append('llm', this.globalSeoFiles.llm);
                    formData.append('htaccess', this.globalSeoFiles.htaccess);
                    formData.append('head_scripts', this.globalSeoFiles.head_scripts);
                    formData.append('body_scripts', this.globalSeoFiles.body_scripts);

                    const res = await fetch('cms.php?api=save_global_seo', { method: 'POST', body: formData });
                    const json = await res.json();
                    if (json.status === 'success') alert(json.message || '全局 SEO 设置已成功保存！');
                    else alert('保存失败: ' + (json.message || '未知错误'));
                } catch (e) {
                    alert('网络请求出错');
                }
            },

                 // 获取 AI 设置
                async fetchAiSettings() {
                    try {
                        const res = await fetch('cms.php?api=get_settings');
                        const json = await res.json();
                    if (json.status === 'success' && typeof json.data === 'object' && json.data !== null) {
                        this.aiSettings.ai_provider = json.data.ai_provider || 'openai';
                            this.aiSettings.ai_api_key = json.data.ai_api_key || '';
                            this.aiSettings.ai_model = json.data.ai_model || '';
                        }
                    } catch (error) { console.error("加载AI设置失败", error); }
                },
                
                // 保存 AI 设置
                async saveAiSettings() {
                    try {
                        let formData = new FormData();
                        formData.append('ai_provider', this.aiSettings.ai_provider);
                        formData.append('ai_api_key', this.aiSettings.ai_api_key);
                        formData.append('ai_model', this.aiSettings.ai_model);

                        const res = await fetch('cms.php?api=save_settings', { method: 'POST', body: formData });
                        const json = await res.json();
                        if (json.status === 'success') alert('AI API 设置已成功保存！');
                        else alert('保存失败: ' + (json.message || '未知错误'));
                    } catch (e) { alert('网络请求出错'); }
                },
                
                // 使用 AI 生成 Schema
                async generateSchemaWithAI() {
                    if (!this.aiSettings.ai_api_key) {
                        alert("请先在左侧菜单栏的 [AI Settings] 中配置您的 API Key！");
                        return;
                    }
                    
                    const iframe = document.getElementById('visual-editor');
                    if (!iframe || !iframe.contentWindow) return;
                    const htmlContent = iframe.contentWindow.document.documentElement.outerHTML;
                    
                    this.isGenerating = true;
                    
                    try {
                        let formData = new FormData();
                        formData.append('html', htmlContent);
                        formData.append('ai_provider', this.aiSettings.ai_provider);
                        formData.append('ai_api_key', this.aiSettings.ai_api_key);
                        formData.append('ai_model', this.aiSettings.ai_model);
                        
                        const res = await fetch('cms.php?api=generate_schema', { method: 'POST', body: formData });
                        const json = await res.json();
                        if (json.status === 'success') {
                            this.seoData.schemaCode = json.schema;
                            alert('Schema 智能生成成功！');
                        } else {
                            alert('生成失败: ' + (json.message || '未知错误'));
                        }
                    } catch (e) { alert('生成请求出错，请检查网络状态'); }
                    finally { this.isGenerating = false; }
                },

                getPageTitle() {
                    if (this.editingPage) return '可视化编辑';
                    if (this.currentTab === 'media') return '媒体管理';
                    if (this.currentTab === 'global_seo') return 'Global SEO 配置';
                    if (this.currentTab === 'ai_settings') return 'AI API 配置';
                    if (this.currentTab === 'static') return '常规页面管理';
                    return this.currentTab.replace('template_', '') + ' 模板管理';
                },

                // 打开 iframe 进行编辑
                openEditor(page) {
                    this.editingPage = page;
                    // ../ 因为我们处于 cms/ 目录下，静态文件在上一级
                    // 追加时间戳防止静态网页被浏览器缓存，确保每次打开看到的都是硬盘上的最新代码
                    const targetUrl = '../' + page.filename + '?v=' + new Date().getTime(); 
                    const iframe = document.getElementById('visual-editor');
                    iframe.src = targetUrl;
                    
                    // 等待 iframe 加载完毕后注入编辑器脚本
                    iframe.onload = () => {
                        try {
                            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                            if (!iframeDoc.getElementById('cms-editor-inject')) {
                                const script = iframeDoc.createElement('script');
                                script.id = 'cms-editor-inject';
                                // 动态计算相对于网站根目录的路径层级，防止子文件夹（如模板页面）出现 404
                                const depth = (page.filename.match(/\//g) || []).length;
                                script.src = (depth > 0 ? '../'.repeat(depth) : '') + 'cms/js/editor-inject.js';
                                iframeDoc.body.appendChild(script);
                            }
                        } catch (e) {
                            console.error('无法注入编辑器脚本，如果是跨域请检查环境配置:', e);
                        }
                    };
                },

                closeEditor() {
                    this.editingPage = null;
                    this.showSeoPanel = false;
                    document.getElementById('visual-editor').src = 'about:blank';
                },

                // 获取并打开历史记录弹窗
                async openHistory() {
                    if (!this.editingPage) return;
                    try {
                        const res = await fetch(`cms.php?api=get_backups&page_id=${this.editingPage.id}`);
                        const json = await res.json();
                        if (json.status === 'success') {
                            this.backups = json.data;
                            this.historyModalOpen = true;
                        }
                    } catch (e) {
                        alert('获取备份列表失败');
                    }
                },

                // 执行恢复备份
                async restoreBackup(backupId) {
                    if (!confirm('确定要恢复到此版本吗？当前未保存的修改将会丢失且不可逆转！')) return;
                    try {
                        let formData = new FormData();
                        formData.append('backup_id', backupId);
                        formData.append('page_id', this.editingPage.id);
                        const res = await fetch('cms.php?api=restore_backup', { method: 'POST', body: formData });
                        const json = await res.json();
                        if (json.status === 'success') {
                            alert('备份恢复成功！');
                            this.historyModalOpen = false;
                            // 重新加载 iframe 让恢复后的网页生效
                            const iframe = document.getElementById('visual-editor');
                            iframe.src = '../' + this.editingPage.filename + '?v=' + new Date().getTime();
                        } else {
                            alert('恢复失败: ' + json.message);
                        }
                    } catch (e) {
                        alert('网络请求出错');
                    }
                },

                // 处理 iframe 传来的点击事件
                handleMessage(event) {
                    const data = event.data;
                    if (!data || !data.action) return;
                    
                    if (data.action === 'edit_link') {
                        this.editingData = data;
                        this.linkModalOpen = true;
                    } else if (data.action === 'edit_media') {
                        this.editingData = data;
                        this.mediaModalOpen = true;
                    } else if (data.action === 'edit_video') {
                        this.editingData = { runtimeId: data.runtimeId, tagName: 'video' };
                        this.videoData = data.config || { type: 'local', src: '', iframeCode: '', autoplay: true, loop: true, muted: true, controls: false };
                        this.videoModalOpen = true;
                    } else if (data.action === 'edit_slider') {
                        this.editingData = { runtimeId: data.runtimeId, tagName: 'slider' };
                        let config = data.config || { width: '100%', height: '400px', pagination: true, slides: [] };
                        // 如果没有读取到任何 slide（比如第一次点击原生的占位符），注入一个默认的占位 Slide
                        if (!config.slides || config.slides.length === 0) {
                            config.slides = [{ id: Date.now(), src: data.src || '', title: 'Slide Title', description: 'Slider description text goes here.', textPosition: 'center-center' }];
                        }
                        this.sliderData = config;
                        this.sliderModalOpen = true;
                    } else if (data.action === 'save_html') {
                        this.saveHtmlToServer(data.html);
                    } else if (data.action === 'load_seo') {
                         // 合并 SEO 数据，防止 editor-inject.js 传回的数据缺失 schemaCode 字段
                        this.seoData = { ...this.seoData, ...data.seoData };
                        
                        // 尝试手动从 iframe 中提取已有的 Schema 代码
                        const iframe = document.getElementById('visual-editor');
                        if (iframe && iframe.contentWindow) {
                            try {
                                const schemaScript = iframe.contentWindow.document.querySelector('script[type="application/ld+json"]');
                                this.seoData.schemaCode = schemaScript ? schemaScript.innerHTML : '';
                            } catch (e) {
                                console.warn('无法获取 Schema 标签:', e);
                            }
                        }
                    }
                },
                
                // 向 iframe 发起获取纯净 HTML 的请求
                requestSave() {
                    const iframe = document.getElementById('visual-editor');
                    if (iframe && iframe.contentWindow) {
                        // 通过 JSON 序列化和反序列化来创建一个“干净”的对象，去除 Alpine.js 的 Proxy 包装，防止 postMessage 报错
                        const cleanSeoData = JSON.parse(JSON.stringify(this.seoData));
                        iframe.contentWindow.postMessage({ action: 'request_save', seoData: cleanSeoData }, '*');
                    }
                },
                
                async saveHtmlToServer(html) {
                    try {
                        let formData = new FormData();
                        formData.append('id', this.editingPage.id);
                        formData.append('html', html);
                        const res = await fetch('cms.php?api=save_page', { method: 'POST', body: formData });
                        const json = await res.json();
                        if (json.status === 'success') {
                            alert('网页保存成功，已自动创建备份！');
                        } else {
                            alert('保存失败: ' + json.message);
                        }
                    } catch (e) {
                        alert('网络请求出错');
                    }
                },
                
                // 将修改后的链接数据回传给 iframe
                saveLink() {
                    const iframe = document.getElementById('visual-editor');
                    iframe.contentWindow.postMessage({
                        action: 'update_element',
                        runtimeId: this.editingData.runtimeId,
                        updates: { href: this.editingData.href, text: this.editingData.text, target: this.editingData.target }
                    }, '*');
                    this.linkModalOpen = false;
                },
                
                saveMedia() {
                    const iframe = document.getElementById('visual-editor');
                    iframe.contentWindow.postMessage({
                        action: 'update_element',
                        runtimeId: this.editingData.runtimeId,
                        updates: { src: this.editingData.src, alt: this.editingData.alt }
                    }, '*');
                    this.mediaModalOpen = false;
                },
                
                openVideoConversion() {
                    this.mediaModalOpen = false;
                    // 初始化视频数据，默认开启自动播放和静音，适合通常设计稿转换的无声背景视频
                    this.videoData = { type: 'local', src: '', iframeCode: '', autoplay: true, loop: true, muted: true, controls: false };
                    this.videoModalOpen = true;
                },

                saveVideoConversion() {
                    if (this.videoData.type === 'local' && !this.videoData.src) {
                        alert('请选择或输入视频地址');
                        return;
                    }
                    if (this.videoData.type === 'embed' && !this.videoData.iframeCode) {
                        alert('请输入 Iframe 代码');
                        return;
                    }
                    const iframe = document.getElementById('visual-editor');
                    iframe.contentWindow.postMessage({
                        action: 'replace_with_video',
                        runtimeId: this.editingData.runtimeId,
                        videoData: JSON.parse(JSON.stringify(this.videoData))
                    }, '*');
                    this.videoModalOpen = false;
                },
                
                openSliderConversion() {
                    this.mediaModalOpen = false;
                    // 初始化轮播数据，默认包含一张当前的图片作为占位
                    this.sliderData = {
                        width: '100%',
                        height: '400px',
                        pagination: true,
                        slides: [
                            { id: Date.now(), src: this.editingData.src || '', title: 'Slide Title', description: 'Slider description text goes here.', textPosition: 'center-center' }
                        ]
                    };
                    this.sliderModalOpen = true;
                },
                
                addSlide() {
                    this.sliderData.slides.push({ id: Date.now(), src: '', title: '', description: '', textPosition: 'center-center' });
                },
                
                removeSlide(index) {
                    if (this.sliderData.slides.length > 1) {
                        this.sliderData.slides.splice(index, 1);
                    }
                },
                
                saveSliderConversion() {
                    const iframe = document.getElementById('visual-editor');
                    iframe.contentWindow.postMessage({
                        action: 'replace_with_slider',
                        runtimeId: this.editingData.runtimeId,
                        sliderData: JSON.parse(JSON.stringify(this.sliderData))
                    }, '*');
                    this.sliderModalOpen = false;
                },

                // 复制模板页面
                async duplicatePage(page) {
                    if(confirm(`确定要复制 [${page.filename}] 生成新页面吗？`)) {
                        try {
                            let formData = new FormData();
                            formData.append('id', page.id);
                            const res = await fetch('cms.php?api=duplicate_page', { method: 'POST', body: formData });
                            const json = await res.json();
                            if (json.status === 'success') {
                                this.fetchPages(); // 复制成功后刷新左侧和表格里的列表
                            } else {
                                alert('复制失败: ' + json.message);
                            }
                        } catch (e) {
                            alert('网络请求出错');
                        }
                    }
                },
                
                // 删除页面
                async deletePage(page) {
                    if(confirm(`警告：确定要彻底删除页面 [${page.filename}] 吗？此操作不可恢复，且会从硬盘上永久删除该 HTML 文件及其备份！`)) {
                        try {
                            let formData = new FormData();
                            formData.append('id', page.id);
                            const res = await fetch('cms.php?api=delete_page', { method: 'POST', body: formData });
                            const json = await res.json();
                            if (json.status === 'success') {
                                this.fetchPages(); // 删除成功后刷新页面列表
                            } else {
                                alert('删除失败: ' + json.message);
                            }
                        } catch (e) {
                            alert('网络请求出错');
                        }
                    }
                }
            }
        }
    </script>
</body>
</html>
