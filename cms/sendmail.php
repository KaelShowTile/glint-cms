<?php
// 处理跨域和请求头
header('Content-Type: application/json');

// 连接到 CMS 数据库读取全局邮件设置
$dbFile = __DIR__ . '/cms/cms.db.php';
if (!file_exists($dbFile)) {
    echo json_encode(['status' => 'error', 'message' => 'CMS 未初始化']);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => '数据库连接失败']);
    exit;
}

$stmt = $pdo->query("SELECT * FROM settings");
$settings = [];
while ($row = $stmt->fetch()) { $settings[$row['key_name']] = $row['key_value']; }

$adminEmail = $settings['admin_email'] ?? '';
if (empty($adminEmail)) {
    echo json_encode(['status' => 'error', 'message' => 'CMS 后台中未配置接收表单的 Admin 邮箱']);
    exit;
}

$method = $_POST['_mail_method'] ?? 'php';
$name = $_POST['姓名'] ?? $_POST['Name'] ?? 'Unknown';
$email = $_POST['邮箱'] ?? $_POST['Email'] ?? 'no-reply@domain.com';
$subject = $_POST['主题'] ?? $_POST['Subject'] ?? '新网站留言 (Contact Form)';

$body = "您收到了一条新的网站表单留言:\n\n";
foreach ($_POST as $key => $value) {
    if ($key === '_mail_method') continue;
    $body .= htmlspecialchars($key) . ":\n" . htmlspecialchars($value) . "\n\n";
}

if ($method === 'smtp') {
    require __DIR__ . '/libs/PHPMailer/Exception.php';
    require __DIR__ . '/libs/PHPMailer/PHPMailer.php';
    require __DIR__ . '/libs/PHPMailer/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $settings['smtp_host'] ?? '';
        $mail->SMTPAuth   = true;
        $mail->Username   = $settings['smtp_user'] ?? '';
        $mail->Password   = $settings['smtp_pass'] ?? '';
        $secure = $settings['smtp_secure'] ?? 'tls';
        if ($secure === 'none') { $mail->SMTPAutoTLS = false; $mail->SMTPSecure = false; } 
        else { $mail->SMTPSecure = $secure === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; }
        $mail->Port       = $settings['smtp_port'] ?? 587;
        $mail->setFrom($email, $name);
        $mail->addAddress($adminEmail);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'SMTP 发送错误: ' . $mail->ErrorInfo]); }
} else {
    $headers = "From: $email\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    if (mail($adminEmail, $subject, $body, $headers)) { echo json_encode(['status' => 'success']); } 
    else { echo json_encode(['status' => 'error', 'message' => 'PHP mail() 发送失败']); }
}