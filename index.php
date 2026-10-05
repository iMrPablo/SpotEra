<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('MESSAGES_FILE', DATA_DIR . '/messages.json');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('CHATS_FILE', DATA_DIR . '/chats.json');
define('READS_FILE', DATA_DIR . '/reads.json');
define('UPLOADS_DIR', DATA_DIR . '/uploads');
define('AVATARS_DIR', DATA_DIR . '/avatars');
define('PREVIEWS_FILE', DATA_DIR . '/previews.json');

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0755, true);
if (!is_dir(AVATARS_DIR)) @mkdir(AVATARS_DIR, 0755, true);

function read_json($file) {
    if (!file_exists($file)) return [];
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ?$d : [];
}

function write_json($file,$data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function is_installed() {
    if (!file_exists(CONFIG_FILE)) return false;
    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
    return !empty($cfg['installed']);
}

function find_user_by_username($username) {
    foreach (read_json(USERS_FILE) as $u) {
        if (strtolower($u['username']) === strtolower($username)) return$u;
    }
    return null;
}

function generate_id() {
    return uniqid('x_', true) . '_' . bin2hex(random_bytes(4));
}

function ensure_data_htaccess() {
    $htaccess_content = <<<HTACCESS
Options -Indexes
Order Allow,Deny
Deny from all
<FilesMatch "\.(json|log|txt|md|bak|sql)$">
    Require all denied
</FilesMatch>
<FilesMatch "\.ph(p|tml)$">
    Require all denied
</FilesMatch>
HTACCESS;

    $files = [
        DATA_DIR . '/.htaccess',
        UPLOADS_DIR . '/.htaccess',
        AVATARS_DIR . '/.htaccess'
    ];

    foreach ($files as$f) {
        if (!file_exists($f)) {
            @file_put_contents($f,$htaccess_content);
        }
    }
}

function ensure_empty_json_files() {
    $files = [MESSAGES_FILE, CHATS_FILE, READS_FILE, PREVIEWS_FILE];
    foreach ($files as$f) {
        if (!file_exists($f)) {
            @file_put_contents($f, '[]');
        }
    }
    if (!file_exists(USERS_FILE)) {
        @file_put_contents(USERS_FILE, '[]');
    }
}

function send_login_welcome($user) {
    if ($user['id'] === 'bot_assistant') return;

    $users = read_json(USERS_FILE);$bot = null;
    foreach ($users as$u) {
        if ($u['id'] === 'bot_assistant') { $bot =$u; break; }
    }

    if (!$bot) {$bot = [
            'id' => 'bot_assistant',
            'username' => 'دستیار',
            'password' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'is_admin' => false,
            'active' => true,
            'blocked' => false,
            'is_bot' => true,
            'name' => 'دستیار اسپاتیرا',
            'bio' => '',
            'avatar' => '',
            'privacy_searchable' => false,
            'public_id' => 'assistant',
            'privacy_allow_messages' => 'everyone',
            'created_at' => time()
        ];
        $users[] =$bot;
        write_json(USERS_FILE, $users);
    }

    $chats = read_json(CHATS_FILE);$chat = null;
    foreach ($chats as$c) {
        if (($c['type'] ?? '') === 'private' && in_array($user['id'],$c['members'] ?? []) && in_array($bot['id'],$c['members'] ?? [])) {
            $chat =$c;
            break;
        }
    }

    if (!$chat) {$chat = [
            'id' => generate_id(),
            'type' => 'private',
            'name' => '',
            'description' => '',
            'owner_id' => $bot['id'],
            'members' => [$user['id'],$bot['id']],
            'public_id' => '',
            'avatar_image' => '',
            'created_at' => time()
        ];
        $chats[] =$chat;
        write_json(CHATS_FILE, $chats);
    }

    $messages = read_json(MESSAGES_FILE);

    $welcomeText = "کاربر " . $user['username'] . " عزیز خوش آمدید\n\n"
                 . "📅 تاریخ و ساعت ورود مجدد شما : " . date('Y/m/d - H:i') . "\n\n"
                 . "🔐 لطفا در حفظ اطلاعات خود کوشا باشید\n\n"
                 . "━━━━━━━━━━━━━━━━━━━━\n"
                 . "💬 پیام‌رسان اسپاتیرا\n"
                 . "✨ همراه لحظه‌های شما";

    $messages[] = [
        'id' => generate_id(),
        'chat_id' => $chat['id'],
        'user_id' => 'bot_assistant',
        'username' => $bot['name'] ?? $bot['username'],
        'text' => $welcomeText,
        'caption' => '',
        'file_path' => null,
        'file_type' => null,
        'file_name' => null,
        'link_preview' => null,
        'created_at' => time(),
        'edited' => false,
        'seen_by' => []
    ];
    write_json(MESSAGES_FILE, $messages);
}

function handle_install() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (is_installed()) {
        die(json_encode(['error' => 'سیستم قبلاً نصب شده است']));
    }

    $username = trim(ltrim($_POST['username'] ?? '', '@'));
    $password =$_POST['password'] ?? '';
    $name = trim($_POST['name'] ?? '');

    if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
        die(json_encode(['error' => 'آیدی باید بین 3 تا 20 کاراکتر باشد']));
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/',$username)) {
        die(json_encode(['error' => 'آیدی فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد']));
    }
    if (mb_strlen($password) < 6) {
        die(json_encode(['error' => 'رمز عبور باید حداقل 6 کاراکتر باشد']));
    }
    if (mb_strlen($password) > 100) {
        die(json_encode(['error' => 'رمز عبور بیش از حد طولانی است']));
    }

    ensure_data_htaccess();
    ensure_empty_json_files();

    $users = [];$admin = [
        'id' => generate_id(),
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'is_admin' => true,
        'active' => true,
        'blocked' => false,
        'is_bot' => false,
        'name' => $name !== '' ?$name : 'مدیر سیستم',
        'bio' => '',
        'avatar' => '',
        'privacy_searchable' => true,
        'public_id' => '',
        'privacy_allow_messages' => 'everyone',
        'created_at' => time()
    ];
    $users[] =$admin;

    $bot = [
        'id' => 'bot_assistant',
        'username' => 'assistant',
        'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'is_admin' => false,
        'active' => true,
        'blocked' => false,
        'is_bot' => true,
        'name' => 'دستیار اسپاتیرا',
        'bio' => 'دستیار هوشمند پیام‌رسان اسپاتیرا',
        'avatar' => '',
        'privacy_searchable' => false,
        'public_id' => 'assistant',
        'privacy_allow_messages' => 'everyone',
        'created_at' => time()
    ];
    $users[] =$bot;

    write_json(USERS_FILE, $users);

    write_json(CONFIG_FILE, [
        'installed' => true,
        'installed_at' => time(),
        'version' => '1.0.0'
    ]);

    echo json_encode(['success' => true]);
    exit;
}

function handle_login() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isset($_POST['username'],$_POST['password'])) return;

    $username = trim($_POST['username']);
    $password =$_POST['password'];

    $user = find_user_by_username($username);
    if (!$user || !password_verify($password,$user['password'])) {
        die(json_encode(['error' => 'نام کاربری یا رمز عبور اشتباه است']));
    }

    if (!empty($user['is_bot'])) {
        die(json_encode(['error' => 'نام کاربری یا رمز عبور اشتباه است']));
    }

    if (!empty($user['blocked'])) {
        die(json_encode(['error' => 'حساب شما مسدود شده است']));
    }

    if (isset($user['active']) && !$user['active']) {
        die(json_encode(['error' => 'حساب شما هنوز فعال نشده است']));
    }

    send_login_welcome($user);

    $_SESSION['user_id'] =$user['id'];
    echo json_encode(['success' => true, 'redirect' => 'dash.php']);
    exit;
}

function handle_check_username() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isset($_POST['username'])) return;

    $username = trim($_POST['username']);
    $user = find_user_by_username($username);

    if (!$user || !empty($user['is_bot'])) {
        die(json_encode(['error' => 'کاربر یافت نشد']));
    }

    if (!empty($user['blocked'])) {
        die(json_encode(['error' => 'حساب شما مسدود شده است']));
    }

    if (isset($user['active']) && !$user['active']) {
        die(json_encode(['error' => 'حساب شما هنوز فعال نشده است']));
    }

    echo json_encode(['success' => true, 'name' => !empty($user['name']) ? $user['name'] :$user['username']]);
    exit;
}

function handle_check_username_available() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isset($_POST['username'])) return;

    $username = trim($_POST['username']);

    if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
        die(json_encode(['error' => 'نام کاربری باید بین 3 تا 20 کاراکتر باشد']));
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/',$username)) {
        die(json_encode(['error' => 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد']));
    }

    if (find_user_by_username($username)) {
        die(json_encode(['error' => 'این نام کاربری قبلاً ثبت شده است']));
    }

    echo json_encode(['success' => true]);
    exit;
}

function handle_register() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isset($_POST['username'],$_POST['password'])) {
        die(json_encode(['error' => 'اطلاعات ناقص است']));
    }

    $username = trim($_POST['username']);$password = $_POST['password'];$displayName = isset($_POST['display_name']) ? trim($_POST['display_name']) : '';

    if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
        die(json_encode(['error' => 'نام کاربری باید بین 3 تا 20 کاراکتر باشد']));
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/',$username)) {
        die(json_encode(['error' => 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد']));
    }

    if (find_user_by_username($username)) {
        die(json_encode(['error' => 'این نام کاربری قبلاً ثبت شده است']));
    }

    if (strlen($password) < 6) {
        die(json_encode(['error' => 'رمز عبور باید حداقل 6 کاراکتر باشد']));
    }
    if (strlen($password) > 100) {
        die(json_encode(['error' => 'رمز عبور نمی‌تواند بیشتر از 100 کاراکتر باشد']));
    }

    $users = read_json(USERS_FILE);$newUser = [
        'id' => generate_id(),
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'is_admin' => false,
        'active' => false,
        'blocked' => false,
        'is_bot' => false,
        'name' => $displayName,
        'bio' => '',
        'avatar' => '',
        'privacy_searchable' => true,
        'public_id' => strtolower($username) . '_' . bin2hex(random_bytes(3)),
        'privacy_allow_messages' => 'everyone',
        'created_at' => time()
    ];
    $users[] =$newUser;
    write_json(USERS_FILE, $users);

    echo json_encode(['success' => true]);
    exit;
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_GET['action']) {
        case 'install': handle_install(); break;
        case 'login': handle_login(); break;
        case 'check_username': handle_check_username(); break;
        case 'check_username_available': handle_check_username_available(); break;
        case 'register': handle_register(); break;
        default: echo json_encode(['error' => 'درخواست نامعتبر']); break;
    }
    exit;
}

$installed = is_installed();

if ($installed && isset($_SESSION['user_id'])) {
    header('Location: dash.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#06090f">
<title><?= $installed ? 'ورود به اسپاتیرا' : 'نصب اسپاتیرا' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --ease: cubic-bezier(.22,.9,.3,1);
    --font: 'Vazirmatn', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    --accent: #38d9a9;
    --accent-hover: #20c997;
    --accent-glow: rgba(56, 217, 169, 0.25);
    --on-accent: #052e22;
}

body[data-theme="dark"] {
    --bg: #0f1721;
    --card-bg: #17212b;
    --text: #f5f5f5;
    --text-secondary: #7f91a4;
    --input-bg: #242f3d;
    --input-border: #2b394a;
    --input-focus: #38d9a9;
    --error: #ff596a;
}

body[data-theme="light"] {
    --bg: #e7ebf0;
    --card-bg: #ffffff;
    --text: #222222;
    --text-secondary: #707579;
    --input-bg: #f4f4f5;
    --input-border: #dfe1e5;
    --input-focus: #0ca678;
    --accent: #0ca678;
    --accent-hover: #099268;
    --accent-glow: rgba(12, 166, 120, 0.2);
    --on-accent: #ffffff;
    --error: #e03131;
}

*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: var(--font);
    background-color: var(--bg);
    color: var(--text);
    min-height: 100dvh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    transition: background-color 0.3s ease;
}

/* Telegram Layout Container */
.tg-container {
    width: 100%;
    max-width: 410px;
    background: var(--card-bg);
    border-radius: 16px;
    padding: 40px 32px 32px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
    text-align: center;
    position: relative;
    overflow: hidden;
    animation: fadeIn 0.35s var(--ease);
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(12px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* Brand Logo */
.brand-logo {
    width: 100px;
    height: 100px;
    margin: 0 auto 20px;
    position: relative;
}

.brand-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
    box-shadow: 0 4px 16px var(--accent-glow);
}

/* Header */
.tg-title {
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 8px;
    color: var(--text);
}

.tg-subtitle {
    font-size: 14px;
    color: var(--text-secondary);
    line-height: 1.5;
    margin-bottom: 28px;
}

/* Tabs for Auth Mode */
.tg-tabs {
    display: flex;
    background: var(--input-bg);
    border-radius: 10px;
    padding: 3px;
    margin-bottom: 24px;
}

.tg-tab {
    flex: 1;
    padding: 8px 0;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-secondary);
    background: transparent;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.tg-tab.active {
    background: var(--card-bg);
    color: var(--text);
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

/* Form Controls */
.form-step {
    display: none;
}

.form-step.active {
    display: block;
    animation: slideIn 0.25s var(--ease);
}

@keyframes slideIn {
    from { opacity: 0; transform: translateX(-10px); }
    to { opacity: 1; transform: translateX(0); }
}

.input-group {
    position: relative;
    margin-bottom: 20px;
    text-align: right;
}

.input-control {
    width: 100%;
    height: 52px;
    background: var(--input-bg);
    border: 1.5px solid var(--input-border);
    border-radius: 12px;
    padding: 0 16px;
    font-family: var(--font);
    font-size: 15px;
    color: var(--text);
    outline: none;
    transition: all 0.2s ease;
}

.input-control:focus {
    border-color: var(--input-focus);
    box-shadow: 0 0 0 3px var(--accent-glow);
}

.input-control::placeholder {
    color: var(--text-secondary);
    font-weight: 400;
}

.toggle-pwd {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 6px;
    display: flex;
    align-items: center;
}

.toggle-pwd svg {
    width: 20px;
    height: 20px;
}

/* Submit Button */
.btn-primary {
    width: 100%;
    height: 50px;
    background: var(--accent);
    color: var(--on-accent);
    border: none;
    border-radius: 12px;
    font-family: var(--font);
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px var(--accent-glow);
}

.btn-primary:hover {
    background: var(--accent-hover);
    transform: translateY(-1px);
}

.btn-primary:active {
    transform: translateY(0);
}

.btn-primary.loading {
    pointer-events: none;
    opacity: 0.8;
}

.spinner {
    width: 20px;
    height: 20px;
    border: 2px solid var(--on-accent);
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    display: none;
}

.btn-primary.loading .spinner { display: block; }
.btn-primary.loading .btn-text { display: none; }

@keyframes spin { to { transform: rotate(360deg); } }

/* Alert / Error Message */
.tg-error {
    background: rgba(255, 89, 106, 0.1);
    color: var(--error);
    border: 1px solid rgba(255, 89, 106, 0.2);
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 18px;
    display: none;
    text-align: right;
}

/* Theme Toggle Button */
.theme-toggle {
    position: absolute;
    top: 16px;
    left: 16px;
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    transition: background 0.2s;
}

.theme-toggle:hover { background: var(--input-bg); }
.theme-toggle svg { width: 20px; height: 20px; }

body[data-theme="dark"] .icon-moon { display: none; }
body[data-theme="light"] .icon-sun { display: none; }
</style>
</head>
<body data-theme="dark">
<script>document.body.setAttribute('data-theme', localStorage.getItem('theme') || 'dark');</script>

<div class="tg-container">
    <button class="theme-toggle" id="theme-toggle" aria-label="تغییر پوسته">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>

    <div class="brand-logo">
        <img src="https://abrehamrahi.ir/o/public/SwVEynDD/" alt="اسپاتیرا">
    </div>

    <h1 class="tg-title">اسپاتیرا</h1>
    <p class="tg-subtitle" id="form-desc"><?= $installed ? 'لطفاً برای ادامه وارد حساب خود شوید' : 'تنظیمات و نصب اولیه اسپاتیرا' ?></p>

    <div class="tg-error" id="error-box"></div>

    <?php if ($installed): ?>
        <div class="tg-tabs" id="auth-tabs">
            <button class="tg-tab active" data-tab="login">ورود</button>
            <button class="tg-tab" data-tab="register">ثبت‌نام</button>
        </div>

        <!-- ── LOGIN FORM ── -->
        <form id="login-form">
            <!-- Step 1: Username -->
            <div class="form-step active" id="login-step-1">
                <div class="input-group">
                    <input type="text" class="input-control" id="login-username" placeholder="نام کاربری (آیدی)" autocomplete="username" dir="ltr" required>
                </div>
                <button type="submit" class="btn-primary">
                    <span class="btn-text">ادامه</span>
                    <span class="spinner"></span>
                </button>
            </div>

            <!-- Step 2: Password -->
            <div class="form-step" id="login-step-2">
                <div class="input-group">
                    <input type="password" class="input-control" id="login-password" placeholder="رمز عبور" autocomplete="current-password" dir="ltr">
                    <button type="button" class="toggle-pwd">
                        <svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <button type="submit" class="btn-primary">
                    <span class="btn-text">ورود به سیستم</span>
                    <span class="spinner"></span>
                </button>
            </div>
        </form>

        <!-- ── REGISTER FORM ── -->
        <form id="register-form" style="display: none;">
            <div class="input-group">
                <input type="text" class="input-control" id="reg-username" placeholder="نام کاربری دلخواه (آیدی)" dir="ltr" required>
            </div>
            <div class="input-group">
                <input type="text" class="input-control" id="reg-name" placeholder="نام نمایشی (اختیاری)">
            </div>
            <div class="input-group">
                <input type="password" class="input-control" id="reg-password" placeholder="رمز عبور (حداقل ۶ کاراکتر)" dir="ltr" required>
                <button type="button" class="toggle-pwd">
                    <svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <button type="submit" class="btn-primary">
                <span class="btn-text">ثبت‌نام حساب جدید</span>
                <span class="spinner"></span>
            </button>
        </form>

    <?php else: ?>
        <!-- ── INSTALL FORM ── -->
        <form id="install-form">
            <div class="input-group">
                <input type="text" class="input-control" id="inst-username" placeholder="آیدی مدیر (مثلا: admin)" dir="ltr" required>
            </div>
            <div class="input-group">
                <input type="text" class="input-control" id="inst-name" placeholder="نام نمایشی مدیر (اختیاری)">
            </div>
            <div class="input-group">
                <input type="password" class="input-control" id="inst-password" placeholder="رمز عبور مدیر" dir="ltr" required>
                <button type="button" class="toggle-pwd">
                    <svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <button type="submit" class="btn-primary">
                <span class="btn-text">شروع و نصب سیستم</span>
                <span class="spinner"></span>
            </button>
        </form>
    <?php endif; ?>
</div>

<script>
const $ = id => document.getElementById(id);
const errorBox = $('error-box');

function showError(msg) {
    if(!msg) {
        errorBox.style.display = 'none';
        return;
    }
    errorBox.textContent = msg;
    errorBox.style.display = 'block';
}

// Theme Toggle Logic
$('theme-toggle').addEventListener('click', () => {
    const next = document.body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.body.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
});

// Toggle Password Visibility
document.querySelectorAll('.toggle-pwd').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('input');
        input.type = input.type === 'password' ? 'text' : 'password';
    });
});

async function postData(action, data) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    const r = await fetch('?action=' + action, { method: 'POST', body: fd });
    return await r.json();
}

<?php if ($installed): ?>
// Tab Switching Logic
const loginForm = $('login-form');
const regForm = $('register-form');
const descText = $('form-desc');

document.querySelectorAll('.tg-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        showError('');
        document.querySelectorAll('.tg-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        
        if(tab.dataset.tab === 'login') {
            loginForm.style.display = 'block';
            regForm.style.display = 'none';
            descText.textContent = 'لطفاً برای ادامه وارد حساب خود شوید';
        } else {
            loginForm.style.display = 'none';
            regForm.style.display = 'block';
            descText.textContent = 'اطلاعات خود را برای ثبت‌نام وارد کنید';
        }
    });
});

// Login Flow
let currentUsername = '';

loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    showError('');

    const step1 = $('login-step-1');
    const step2 = $('login-step-2');
    const btn1 = step1.querySelector('.btn-primary');
    const btn2 = step2.querySelector('.btn-primary');

    if(step1.classList.contains('active')) {
        const u = $('login-username').value.trim();
        if(!u) return;

        btn1.classList.add('loading');
        const res = await postData('check_username', { username: u });
        btn1.classList.remove('loading');

        if(res.error) {
            showError(res.error);
        } else {
            currentUsername = u;
            descText.textContent = `سلام ${res.name}، رمز عبور خود را وارد کنید`;
            step1.classList.remove('active');
            step2.classList.add('active');
            $('login-password').focus();
        }
    } else {
        const p = $('login-password').value;
        if(!p) return;

        btn2.classList.add('loading');
        const res = await postData('login', { username: currentUsername, password: p });
        btn2.classList.remove('loading');

        if(res.error) {
            showError(res.error);
        } else {
            window.location.href = res.redirect || 'dash.php';
        }
    }
});

// Register Flow
regForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    showError('');

    const btn = regForm.querySelector('.btn-primary');
    const u = $('reg-username').value.trim();
    const name = $('reg-name').value.trim();
    const p = $('reg-password').value;

    btn.classList.add('loading');
    const res = await postData('register', { username: u, display_name: name, password: p });
    btn.classList.remove('loading');

    if(res.error) {
        showError(res.error);
    } else {
        alert('ثبت‌نام با موفقیت انجام شد. حساب شما پس از فعال‌سازی توسط مدیر در دسترس خواهد بود.');
        location.reload();
    }
});

<?php else: ?>
// Install Flow
$('install-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    showError('');

    const btn = e.target.querySelector('.btn-primary');
    const u = $('inst-username').value.trim();
    const name = $('inst-name').value.trim();
    const p = $('inst-password').value;

    btn.classList.add('loading');
    const res = await postData('install', { username: u, name: name, password: p });
    btn.classList.remove('loading');

    if(res.error) {
        showError(res.error);
    } else {
        location.reload();
    }
});
<?php endif; ?>
</script>
</body>
</html>
