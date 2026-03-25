<?php
// cms/setup.php
$dbFile = __DIR__ . '/cms.db.php';

// 如果文件存在先删除，方便重复测试
if (file_exists($dbFile)) {
    unlink($dbFile);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 创建表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            title TEXT,
            type TEXT NOT NULL, 
            parent_template_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS settings (
            key_name TEXT PRIMARY KEY,
            key_value TEXT
        );

        CREATE TABLE IF NOT EXISTS backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id INTEGER NOT NULL,
            backup_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            content TEXT NOT NULL,
            FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE
        );
    ");

    // 插入初始管理员 admin / 11001100
    $hash = password_hash('11001100', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)");
    $stmt->execute([':username' => 'admin', ':password_hash' => $hash]);

    // 插入一些测试页面数据，模拟 Tauri 生成的数据
    $pdo->exec("
        INSERT INTO pages (filename, title, type) VALUES ('index.html', '首页', 'static');
        INSERT INTO pages (filename, title, type) VALUES ('page1.html', '关于我们', 'static');
        INSERT INTO pages (filename, title, type) VALUES ('template1/template1-page1.html', '博客文章1', 'template_instance');
        INSERT INTO pages (filename, title, type) VALUES ('template2/template2-page1.html', '产品详情1', 'template_instance');
    ");

    echo "<h3>数据库 cms.db.php 初始化成功！包含测试用户和页面。</h3>";
    echo "<p>管理员账号: admin<br>密码: 11001100</p>";
    echo "<p style='color:red;'>请立即删除此 setup.php 文件！</p>";

} catch (PDOException $e) {
    echo "初始化失败: " . $e->getMessage();
}
