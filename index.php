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

// ایجاد پوشه‌ها در صورت عدم وجود
if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0755, true);
if (!is_dir(AVATARS_DIR)) @mkdir(AVATARS_DIR, 0755, true);

function read_json($file) {
    if (!file_exists($file)) return [];
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : [];
}

function write_json($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function is_installed() {
    if (!file_exists(CONFIG_FILE)) return false;
    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
    return !empty($cfg['installed']);
}

function find_user_by_username($username) {
    foreach (read_json(USERS_FILE) as $u) {
        if (strtolower($u['username']) === strtolower($username)) return $u;
    }
    return null;
}

function generate_id() {
    return uniqid('x_', true) . '_' . bin2hex(random_bytes(4));
}

function ensure_data_htaccess() {
    $htaccess_content = <<<HTACCESS
# جلوگیری از فهرست‌بندی پوشه‌ها
Options -Indexes

# مسدود کردن دسترسی مستقیم از طریق مرورگر
Order Allow,Deny
Deny from all

# مسدود کردن دسترسی به فایل‌های حساس
<FilesMatch "\.(json|log|txt|md|bak|sql)$">
    Require all denied
</FilesMatch>

# غیرفعال کردن اجرای PHP در این پوشه
<FilesMatch "\.ph(p|tml)$">
    Require all denied
</FilesMatch>
HTACCESS;

    $files = [
        DATA_DIR . '/.htaccess',
        UPLOADS_DIR . '/.htaccess',
        AVATARS_DIR . '/.htaccess'
    ];

    foreach ($files as $f) {
        if (!file_exists($f)) {
            @file_put_contents($f, $htaccess_content);
        }
    }
}

function ensure_empty_json_files() {
    $files = [MESSAGES_FILE, CHATS_FILE, READS_FILE, PREVIEWS_FILE];
    foreach ($files as $f) {
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

    $users = read_json(USERS_FILE);
    $bot = null;
    foreach ($users as $u) {
        if ($u['id'] === 'bot_assistant') { $bot = $u; break; }
    }

    if (!$bot) {
        $bot = [
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
        $users[] = $bot;
        write_json(USERS_FILE, $users);
    }

    $chats = read_json(CHATS_FILE);
    $chat = null;
    foreach ($chats as $c) {
        if (($c['type'] ?? '') === 'private' && in_array($user['id'], $c['members'] ?? []) && in_array($bot['id'], $c['members'] ?? [])) {
            $chat = $c;
            break;
        }
    }

    if (!$chat) {
        $chat = [
            'id' => generate_id(),
            'type' => 'private',
            'name' => '',
            'description' => '',
            'owner_id' => $bot['id'],
            'members' => [$user['id'], $bot['id']],
            'public_id' => '',
            'avatar_image' => '',
            'created_at' => time()
        ];
        $chats[] = $chat;
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
    $password = $_POST['password'] ?? '';
    $name = trim($_POST['name'] ?? '');

    if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
        die(json_encode(['error' => 'آیدی باید بین 3 تا 20 کاراکتر باشد']));
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        die(json_encode(['error' => 'آیدی فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد']));
    }
    if (mb_strlen($password) < 6) {
        die(json_encode(['error' => 'رمز عبور باید حداقل 6 کاراکتر باشد']));
    }
    if (mb_strlen($password) > 100) {
        die(json_encode(['error' => 'رمز عبور بیش از حد طولانی است']));
    }

    // ساخت فایل‌های htaccess برای محافظت از پوشه data
    ensure_data_htaccess();

    // ایجاد فایل‌های JSON خالی
    ensure_empty_json_files();

    // ساخت کاربر ادمین
    $users = [];
    $admin = [
        'id' => generate_id(),
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'is_admin' => true,
        'active' => true,
        'blocked' => false,
        'is_bot' => false,
        'name' => $name !== '' ? $name : 'مدیر سیستم',
        'bio' => '',
        'avatar' => '',
        'privacy_searchable' => true,
        'public_id' => '',
        'privacy_allow_messages' => 'everyone',
        'created_at' => time()
    ];
    $users[] = $admin;

    // ساخت ربات دستیار
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
    $users[] = $bot;

    write_json(USERS_FILE, $users);

    // نوشتن فایل config برای علامت‌گذاری نصب
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
    if (!isset($_POST['username'], $_POST['password'])) return;

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $user = find_user_by_username($username);
    if (!$user || !password_verify($password, $user['password'])) {
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

    $_SESSION['user_id'] = $user['id'];
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

    echo json_encode(['success' => true]);
    exit;
}

function handle_check_username_available() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isset($_POST['username'])) return;

    $username = trim($_POST['username']);

    if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
        die(json_encode(['error' => 'نام کاربری باید بین 3 تا 20 کاراکتر باشد']));
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
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
    if (!isset($_POST['username'], $_POST['password'])) {
        die(json_encode(['error' => 'اطلاعات ناقص است']));
    }

    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $displayName = isset($_POST['display_name']) ? trim($_POST['display_name']) : '';

    if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
        die(json_encode(['error' => 'نام کاربری باید بین 3 تا 20 کاراکتر باشد']));
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
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

    $users = read_json(USERS_FILE);
    $newUser = [
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
    $users[] = $newUser;
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
<meta name="theme-color" content="#0b1720">
<title><?= $installed ? 'ورود | اسپاتیرا' : 'نصب | اسپاتیرا' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Markazi+Text:wght@400;500;600;700&family=Vazirmatn:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<style>
:root{ --ease: cubic-bezier(.22,.9,.3,1); }

body[data-theme="dark"]{
    --bg:#0b1720; --surface:#152532; --surface-2:#1c3141;
    --line:#22384a; --ink:#e9f3f4; --muted:#8099a8;
    --accent:#3ddbc4; --accent-strong:#2bc3ad; --on-accent:#03251f;
    --mine-a:#1e5a52; --mine-b:#174540; --mine-ink:#eafffb;
    --err-bg:rgba(255,107,107,.12); --err-ink:#ff8f8f; --err-line:rgba(255,107,107,.35);
    --shadow:0 14px 38px rgba(0,0,0,.45);
    --glow:radial-gradient(680px 340px at 50% -90px, rgba(61,219,196,.14), transparent 70%);
}
body[data-theme="light"]{
    --bg:#e9f1f0; --surface:#ffffff; --surface-2:#f2f7f6;
    --line:#d7e3e1; --ink:#13252c; --muted:#5f7784;
    --accent:#0aa892; --accent-strong:#08917e; --on-accent:#ffffff;
    --mine-a:#cdeee7; --mine-b:#bfe8e0; --mine-ink:#0c3831;
    --err-bg:rgba(233,78,78,.09); --err-ink:#d64545; --err-line:rgba(233,78,78,.3);
    --shadow:0 14px 34px rgba(23,60,64,.14);
    --glow:radial-gradient(680px 340px at 50% -90px, rgba(10,168,146,.15), transparent 70%);
}

*{margin:0;padding:0;box-sizing:border-box}
[hidden]{display:none!important}
::selection{background:var(--accent);color:var(--on-accent)}
html{scroll-behavior:smooth}
body{
    font-family:'Vazirmatn',-apple-system,BlinkMacSystemFont,'Segoe UI',Tahoma,sans-serif;
    background:var(--bg); color:var(--ink);
    min-height:100dvh; display:flex; flex-direction:column;
    transition:background-color .4s var(--ease), color .4s var(--ease);
    -webkit-tap-highlight-color:transparent;
}
body::before{
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='520' height='520' viewBox='0 0 520 520'%3E%3Cg fill='none' stroke='%233ddbc4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' opacity='.55'%3E%3Crect x='42' y='64' width='124' height='80' rx='20'/%3E%3Cpath d='M74 144l-10 30 36-30'/%3E%3Ccircle cx='79' cy='104' r='3'/%3E%3Ccircle cx='103' cy='104' r='3'/%3E%3Ccircle cx='127' cy='104' r='3'/%3E%3Cpath d='M306 44l58 22-58 22 14-22z'/%3E%3Cpath d='M364 66l34 0'/%3E%3Cpath d='M416 152c0-9 13-15 20-8 7-7 20-1 20 8 0 10-20 22-20 22s-20-12-20-22z'/%3E%3Crect x='62' y='302' width='122' height='82' rx='12'/%3E%3Cpath d='M62 316l61 42 61-42'/%3E%3Cpath d='M326 296l9 24 24 9-24 9-9 24-9-24-24-9 24-9z'/%3E%3Ccircle cx='448' cy='322' r='4'/%3E%3Ccircle cx='212' cy='252' r='4'/%3E%3Crect x='342' y='408' width='116' height='74' rx='18'/%3E%3Cpath d='M432 482l12 26-32-26'/%3E%3Cpath d='M368 436h58M368 454h36'/%3E%3Cpath d='M150 424l8 16 16 8-16 8-8 16-8-16-16-8 16-8z'/%3E%3Ccircle cx='58' cy='208' r='3'/%3E%3Ccircle cx='492' cy='96' r='3'/%3E%3Ccircle cx='258' cy='178' r='3'/%3E%3C/g%3E%3C/svg%3E");
    background-size:480px 480px; opacity:.07; transition:opacity .4s;
}
body[data-theme="light"]::before{opacity:.16; filter:brightness(.5)}
body::after{
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background:var(--glow); transition:background .4s;
}

.topbar{
    position:sticky; top:0; z-index:10;
    display:flex; align-items:center; justify-content:space-between;
    padding:12px 20px; border-bottom:1px solid var(--line);
    background:color-mix(in srgb, var(--bg) 78%, transparent);
    backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px);
    transition:background-color .4s, border-color .4s;
}
.brand{display:flex; align-items:center; gap:11px; min-width:0}
.brand-mark{
    width:40px; height:40px; border-radius:13px; flex:none;
    background:linear-gradient(135deg, var(--accent), var(--accent-strong));
    color:var(--on-accent); display:grid; place-items:center;
    box-shadow:0 6px 18px color-mix(in srgb, var(--accent) 40%, transparent);
    animation:float 3.6s ease-in-out infinite;
}
.brand-mark svg{width:20px;height:20px;transform:scaleX(-1)}
.brand-text{min-width:0}
.brand-name{font-weight:900; font-size:16px; letter-spacing:.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
.brand-sub{font-size:11px; color:var(--muted); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
.top-date{font-size:12px; color:var(--muted); flex-shrink:0; margin-inline-start:8px}

.stage{
    position:relative; z-index:1; flex:1;
    width:100%; max-width:640px; margin-inline:auto;
    display:flex; flex-direction:column;
    padding:30px 18px 96px;
}
.intro{margin-bottom:26px; animation:rise .7s var(--ease) both .1s; transition:all .55s var(--ease)}
.intro .display{
    font-family:'Markazi Text', serif; font-weight:600;
    font-size:clamp(34px, 7vw, 54px); line-height:1.2;
}
.intro .display em{font-style:normal; color:var(--accent)}
.intro .sub{color:var(--muted); font-size:14px; margin-top:2px; font-weight:300}
.intro.compact{margin-bottom:12px}
.intro.compact .display{font-size:25px}
.intro.compact .sub{font-size:12px; opacity:.75}

.chat{display:flex; flex-direction:column; gap:7px}
.day-chip{
    align-self:center; font-size:11.5px; color:var(--muted);
    background:var(--surface-2); border:1px solid var(--line);
    padding:4px 14px; border-radius:999px; margin:2px 0 10px;
    animation:pop .4s var(--ease) both;
}
.msg-row{
    display:flex; align-items:flex-end; gap:8px; max-width:86%;
    animation:pop .38s var(--ease) both;
}
.msg-row.bot{align-self:flex-start}
.msg-row.me{align-self:flex-end; flex-direction:row-reverse}
.msg-row.leaving, .sys-error.leaving, .action-row.leaving{animation:leave .26s ease both}
.avatar{
    width:32px; height:32px; border-radius:50%; flex:none;
    background:linear-gradient(135deg, var(--accent), var(--accent-strong));
    color:var(--on-accent);
    display:grid; place-items:center;
    box-shadow:0 3px 10px color-mix(in srgb, var(--accent) 30%, transparent);
}
.avatar svg{width:18px; height:18px}
.bubble{
    padding:10px 14px 7px; font-size:15.5px; line-height:1.85;
    box-shadow:0 3px 14px rgba(0,0,0,.12); word-break:break-word;
    transition:background-color .4s, color .4s;
    white-space:pre-wrap;
}
.msg-row.bot .bubble{
    background:var(--surface); border-radius:16px 16px 16px 5px;
}
.msg-row.me .bubble{
    background:linear-gradient(135deg, var(--mine-a), var(--mine-b));
    color:var(--mine-ink); border-radius:16px 16px 5px 16px;
}
.meta{
    display:flex; align-items:center; justify-content:flex-end; gap:5px;
    font-size:10.5px; opacity:.68; margin-top:1px; font-weight:300;
}
.msg-row.me .ticks{color:var(--accent); font-weight:700; opacity:1}
body[data-theme="light"] .msg-row.me .ticks{color:var(--accent-strong)}

.bubble.typing{display:flex; gap:5px; padding:14px 16px; align-items:center}
.bubble.typing i{
    width:7px; height:7px; border-radius:50%; background:var(--accent);
    animation:blink 1.15s ease-in-out infinite;
    opacity:.6;
}
.bubble.typing i:nth-child(2){animation-delay:.18s}
.bubble.typing i:nth-child(3){animation-delay:.36s}

.sys-error{
    align-self:center; max-width:92%;
    background:var(--err-bg); color:var(--err-ink);
    border:1px solid var(--err-line);
    padding:9px 18px; border-radius:999px; font-size:13px;
    animation:pop .35s var(--ease) both;
}
.action-row{align-self:center; margin:6px 0 2px; animation:pop .38s var(--ease) both; display:flex; flex-wrap:wrap; gap:6px; justify-content:center}
.chip-btn{
    font-family:inherit; font-size:12.5px; cursor:pointer;
    color:var(--accent); background:transparent;
    border:1.5px dashed color-mix(in srgb, var(--accent) 55%, transparent);
    padding:6px 16px; border-radius:999px;
    transition:all .25s var(--ease);
}
.chip-btn:hover{background:color-mix(in srgb, var(--accent) 10%, transparent); border-style:solid; transform:translateY(-1px)}
.chip-btn.primary{
    background:linear-gradient(135deg, var(--accent), var(--accent-strong));
    color:var(--on-accent);
    border:1.5px solid transparent;
    font-weight:600;
}
.chip-btn.primary:hover{transform:translateY(-1px); box-shadow:0 4px 14px color-mix(in srgb, var(--accent) 40%, transparent)}

.composer-wrap{
    position:sticky; bottom:16px; margin-top:20px; margin-inline:64px 64px;
    opacity:0; transform:translateY(28px); pointer-events:none;
    transition:opacity .45s var(--ease), transform .45s var(--ease);
}
.composer-wrap.show{opacity:1; transform:none; pointer-events:auto}
.composer{
    display:flex; align-items:center; gap:7px;
    background:var(--surface); border:1.5px solid var(--line);
    border-radius:999px; padding:6px; box-shadow:var(--shadow);
    transition:border-color .3s, box-shadow .3s, background-color .4s;
}
.composer:focus-within{
    border-color:var(--accent);
    box-shadow:0 8px 26px rgba(0,0,0,.18), 0 0 0 4px color-mix(in srgb, var(--accent) 16%, transparent);
}
.composer.shake{animation:shake .4s ease}
.composer input{
    flex:1; min-width:0; border:0; outline:0; background:transparent;
    font-family:inherit; font-size:16px; color:var(--ink);
    padding:10px 14px;
}
.composer input::placeholder{color:var(--muted); font-weight:300}
.icon-btn{
    width:40px; height:40px; border-radius:50%; border:0; flex:none;
    background:transparent; color:var(--muted); cursor:pointer;
    display:grid; place-items:center; transition:all .2s;
}
.icon-btn:hover{color:var(--ink); background:var(--surface-2)}
.icon-btn svg{width:20px;height:20px}
.send-btn{
    position:relative; width:46px; height:46px; border-radius:50%; border:0; flex:none;
    background:linear-gradient(135deg, var(--accent), var(--accent-strong));
    color:var(--on-accent); cursor:pointer; display:grid; place-items:center;
    transition:transform .2s var(--ease), box-shadow .2s;
}
.send-btn svg{width:20px; height:20px; transform:scaleX(-1) translateX(-1px); transition:opacity .2s}
.send-btn:hover{transform:translateY(-2px) scale(1.06); box-shadow:0 8px 20px color-mix(in srgb, var(--accent) 45%, transparent)}
.send-btn:active{transform:scale(.92)}
.send-btn.busy{pointer-events:none}
.send-btn.busy svg{opacity:0}
.send-btn.busy::after{
    content:''; position:absolute; width:18px; height:18px; border-radius:50%;
    border:2.5px solid var(--on-accent); border-top-color:transparent;
    animation:spin .7s linear infinite;
}
.composer-hint{text-align:center; font-size:11.5px; color:var(--muted); margin-top:9px; font-weight:300}

.confetti{
    position:fixed; top:-10px; z-index:9999; pointer-events:none;
    width:8px; height:14px; border-radius:2px;
    animation:confetti-fall linear forwards;
}
@keyframes confetti-fall{
    0%{transform:translateY(0) rotate(0deg); opacity:1}
    100%{transform:translateY(100vh) rotate(720deg); opacity:0}
}

.theme-toggle{
    position:fixed; bottom:20px; right:20px; z-index:50;
    width:52px; height:52px; border-radius:50%; cursor:pointer;
    background:var(--surface); color:var(--ink);
    border:1px solid var(--line); box-shadow:var(--shadow);
    display:grid; place-items:center;
    transition:transform .3s var(--ease), background-color .4s, border-color .4s;
}
.theme-toggle:hover{transform:translateY(-3px) rotate(14deg)}
.theme-toggle:active{transform:scale(.9)}
.theme-toggle svg{width:22px; height:22px; grid-area:1/1; transition:opacity .4s, transform .5s var(--ease)}
body[data-theme="dark"] .icon-moon{opacity:0; transform:rotate(100deg) scale(.3)}
body[data-theme="light"] .icon-sun{opacity:0; transform:rotate(-100deg) scale(.3)}
.theme-toggle.spin svg{animation:tt-spin .55s var(--ease)}

@keyframes rise{from{opacity:0; transform:translateY(18px)} to{opacity:1; transform:none}}
@keyframes pop{from{opacity:0; transform:translateY(12px) scale(.96)} to{opacity:1; transform:none}}
@keyframes leave{to{opacity:0; transform:translateY(8px) scale(.95)}}
@keyframes blink{0%,100%{opacity:.25; transform:translateY(0)} 45%{opacity:1; transform:translateY(-3px)}}
@keyframes float{0%,100%{transform:translateY(0) rotate(-3deg)} 50%{transform:translateY(-4px) rotate(3deg)}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes tt-spin{from{transform:rotate(0)} to{transform:rotate(180deg)}}
@keyframes shake{0%,100%{transform:none} 25%{transform:translateX(6px)} 50%{transform:translateX(-6px)} 75%{transform:translateX(4px)}}

@media (max-width: 720px) {
    .top-date{display:none}
    .stage{padding:24px 14px 96px}
}

@media (max-width: 560px) {
    .topbar{padding:10px 14px}
    .brand-mark{width:36px; height:36px; border-radius:11px}
    .brand-mark svg{width:18px; height:18px}
    .brand-name{font-size:14.5px}
    .brand-sub{font-size:10px}
    
    .stage{padding:20px 12px 100px}
    
    .intro .display{font-size:clamp(28px, 8vw, 42px)}
    .intro .sub{font-size:13px}
    .intro.compact .display{font-size:22px}
    
    .msg-row{max-width:92%; gap:6px}
    .avatar{width:28px; height:28px}
    .avatar svg{width:16px; height:16px}
    .bubble{padding:8px 12px 6px; font-size:14.5px; line-height:1.75}
    
    .composer-wrap{
        margin-inline:12px;
        bottom:12px;
        margin-top:16px;
    }
    .composer{padding:4px; gap:4px}
    .composer input{padding:10px 12px; font-size:16px}
    .icon-btn{width:38px; height:38px}
    .send-btn{width:42px; height:42px}
    
    .theme-toggle{
        bottom:84px;
        right:14px;
        width:44px;
        height:44px;
    }
    .theme-toggle svg{width:18px; height:18px}
    
    .sys-error{font-size:12.5px; padding:8px 14px}
    .chip-btn{font-size:12px; padding:6px 14px}
    .day-chip{font-size:11px}
}

@media (max-width: 380px) {
    .stage{padding:16px 10px 90px}
    .intro .display{font-size:clamp(24px, 8.5vw, 34px)}
    .composer-wrap{margin-inline:8px; bottom:10px}
    .bubble{font-size:14px; padding:7px 11px 5px}
    .theme-toggle{bottom:78px; right:10px; width:40px; height:40px}
}

@media (max-height: 500px) and (orientation: landscape) {
    .topbar{padding:6px 14px}
    .brand-mark{width:32px; height:32px}
    .intro .display{font-size:24px}
    .stage{padding:12px 14px 80px}
    .composer-wrap{bottom:8px}
}

@media (prefers-reduced-motion: reduce){
    *,*::before,*::after{animation-duration:.01ms!important; transition-duration:.01ms!important}
}
</style>
</head>
<body data-theme="dark">
<script>document.body.setAttribute('data-theme', localStorage.getItem('theme') || 'dark');</script>

<header class="topbar">
    <div class="brand">
        <span class="brand-mark">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>
        </span>
        <div class="brand-text">
            <div class="brand-name">اسپاتیرا</div>
            <div class="brand-sub"><?= $installed ? 'پیام‌رسان همراه شما' : 'نصب اولیه سیستم' ?></div>
        </div>
    </div>
    <div class="top-date" id="top-date"></div>
</header>

<main class="stage">
    <div class="intro" id="intro">
        <h1 class="display"><?= $installed ? 'به اسپاتیرا <em>خوش آمدید</em>' : 'نصب <em>اسپاتیرا</em>' ?></h1>
        <p class="sub"><?= $installed ? 'دستیار پیام‌رسان قدم‌به‌قدم شما را برای ورود همراهی می‌کند' : 'برای شروع، اطلاعات حساب مدیر را وارد کنید' ?></p>
    </div>

    <div class="chat" id="chat">
        <div class="day-chip">امروز</div>
    </div>

    <div class="composer-wrap" id="composer-wrap">
        <form id="composer" class="composer" autocomplete="off">
            <input id="msg-input" type="text" placeholder="نام کاربری" autocomplete="username" enterkeyhint="next">
            <button type="button" class="icon-btn" id="eye-btn" hidden aria-label="نمایش رمز">
                <svg class="eye-open" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>
                <svg class="eye-closed" viewBox="0 0 24 24" fill="currentColor" hidden><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92A11.8 11.8 0 0 0 23 12c-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.8 11.8 0 0 0 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>
            </button>
            <button type="submit" class="send-btn" id="send-btn" aria-label="ارسال">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>
            </button>
        </form>
        <div class="composer-hint" id="composer-hint">برای ادامه Enter بزنید</div>
    </div>
</main>

<button class="theme-toggle" id="theme-toggle" aria-label="تغییر پوسته">
    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.4M12 19.1v2.4M2.5 12h2.4M19.1 12h2.4M5.3 5.3l1.7 1.7M17 17l1.7 1.7M18.7 5.3L17 7M7 17l-1.7 1.7"/></svg>
    <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
</button>

<script>
const INSTALLED = <?= $installed ? 'true' : 'false' ?>;

const $ = id => document.getElementById(id);
const chat = $('chat'), wrap = $('composer-wrap'), form = $('composer'),
      input = $('msg-input'), eye = $('eye-btn'), sendBtn = $('send-btn'),
      hint = $('composer-hint'), intro = $('intro');

let step = 'choose';
let busy = false;
let loginUsername = '';
let regData = { username: '', displayName: '', password: '' };
let installData = { username: '', name: '', password: '' };
let tempUI = [];

const sleep = ms => new Promise(r => setTimeout(r, ms));
const nowTime = () => new Intl.DateTimeFormat('fa-IR', {hour:'2-digit', minute:'2-digit'}).format(new Date());

$('top-date').textContent = new Intl.DateTimeFormat('fa-IR',
    {weekday:'long', day:'numeric', month:'long'}).format(new Date());

const themeBtn = $('theme-toggle');
themeBtn.addEventListener('click', () => {
    const next = document.body.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.body.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    document.querySelector('meta[name="theme-color"]').setAttribute('content', next === 'dark' ? '#0b1720' : '#e9f1f0');
    themeBtn.classList.remove('spin'); void themeBtn.offsetWidth; themeBtn.classList.add('spin');
});

const scrollToBottom = () => window.scrollTo({top: document.body.scrollHeight, behavior: 'smooth'});

const botAvatarSVG = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2M7.5 13A2.5 2.5 0 0 0 5 15.5A2.5 2.5 0 0 0 7.5 18a2.5 2.5 0 0 0 2.5-2.5A2.5 2.5 0 0 0 7.5 13m9 0a2.5 2.5 0 0 0-2.5 2.5a2.5 2.5 0 0 0 2.5 2.5a2.5 2.5 0 0 0 2.5-2.5a2.5 2.5 0 0 0-2.5-2.5z"/></svg>';

function addMsg(side, text, doubleTick) {
    const row = document.createElement('div');
    row.className = 'msg-row ' + side;
    if (side === 'bot') {
        const av = document.createElement('div');
        av.className = 'avatar';
        av.innerHTML = botAvatarSVG;
        row.appendChild(av);
    }
    const bub = document.createElement('div'); bub.className = 'bubble';
    const txt = document.createElement('div'); txt.textContent = text; bub.appendChild(txt);
    const meta = document.createElement('div'); meta.className = 'meta';
    const t = document.createElement('span'); t.textContent = nowTime(); meta.appendChild(t);
    if (side === 'me') {
        const c = document.createElement('span');
        c.className = 'ticks'; c.textContent = doubleTick ? '✓✓' : '✓';
        meta.appendChild(c);
    }
    bub.appendChild(meta); row.appendChild(bub);
    chat.appendChild(row); scrollToBottom();
    return row;
}

function showTyping() {
    const row = document.createElement('div'); row.className = 'msg-row bot';
    const av = document.createElement('div'); av.className = 'avatar';
    av.innerHTML = botAvatarSVG;
    const bub = document.createElement('div'); bub.className = 'bubble typing';
    bub.innerHTML = '<i></i><i></i><i></i>';
    row.appendChild(av); row.appendChild(bub);
    chat.appendChild(row); scrollToBottom();
    return row;
}
function removeTyping(row) {
    if (!row) return;
    row.classList.add('leaving');
    setTimeout(() => row.remove(), 240);
}

async function botSay(text) {
    const t = showTyping();
    const delay = Math.min(500 + text.length * 20, 1400);
    await sleep(delay);
    removeTyping(t);
    await sleep(100);
    return addMsg('bot', text);
}

function sysError(text) {
    const el = document.createElement('div');
    el.className = 'sys-error'; el.textContent = '⚠️ ' + text;
    chat.appendChild(el); scrollToBottom();
    return el;
}

function createChip(label, onclick, isPrimary = false) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'chip-btn' + (isPrimary ? ' primary' : '');
    b.textContent = label;
    if (onclick) b.addEventListener('click', onclick);
    return b;
}

function actionRow(buttons) {
    const row = document.createElement('div');
    row.className = 'action-row';
    buttons.forEach(b => row.appendChild(b));
    chat.appendChild(row);
    scrollToBottom();
    tempUI.push(row);
    return row;
}

function animateOut(el) {
    if (!el) return;
    el.classList.add('leaving');
    setTimeout(() => el.remove(), 250);
}

function clearTempUI() {
    tempUI.forEach(el => animateOut(el));
    tempUI = [];
}

function celebrate() {
    const colors = ['#3ddbc4', '#2bc3ad', '#4d96ff', '#6bcf7f', '#ffd93d', '#c77dff', '#ff6b6b'];
    for (let i = 0; i < 80; i++) {
        const c = document.createElement('div');
        c.className = 'confetti';
        c.style.left = Math.random() * 100 + '%';
        c.style.background = colors[Math.floor(Math.random() * colors.length)];
        c.style.animationDuration = (2 + Math.random() * 2.5) + 's';
        c.style.animationDelay = (Math.random() * 0.5) + 's';
        c.style.transform = `rotate(${Math.random() * 360}deg)`;
        document.body.appendChild(c);
        setTimeout(() => c.remove(), 5000);
    }
}

async function post(action, fields) {
    try {
        const fd = new FormData();
        for (const k in fields) fd.append(k, fields[k]);
        const r = await fetch('?action=' + action, {method:'POST', body:fd});
        return await r.json();
    } catch (e) {
        return {error: 'ارتباط با سرور برقرار نشد'};
    }
}

function showComposer(mode) {
    step = mode;
    eye.hidden = true;
    switch(mode) {
        case 'login_username':
            input.type = 'text'; input.placeholder = 'نام کاربری';
            input.autocomplete = 'username'; input.enterKeyHint = 'next';
            hint.textContent = 'برای ادامه Enter بزنید';
            break;
        case 'login_password':
            input.type = 'password'; input.placeholder = 'رمز عبور';
            input.autocomplete = 'current-password'; input.enterKeyHint = 'go';
            eye.hidden = false;
            hint.textContent = 'رمز عبور شما به‌صورت امن بررسی می‌شود 🔒';
            break;
        case 'reg_username':
            input.type = 'text'; input.placeholder = 'نام کاربری دلخواه';
            input.autocomplete = 'username'; input.enterKeyHint = 'next';
            hint.textContent = 'فقط حروف انگلیسی، اعداد و _ (حداقل 3 کاراکتر)';
            break;
        case 'reg_display_name':
            input.type = 'text'; input.placeholder = 'نام نمایشی (اختیاری)';
            input.autocomplete = 'name'; input.enterKeyHint = 'next';
            hint.textContent = 'می‌توانید خالی بگذارید یا دکمه رد کردن را بزنید';
            break;
        case 'reg_password':
            input.type = 'password'; input.placeholder = 'رمز عبور (حداقل 6 کاراکتر)';
            input.autocomplete = 'new-password'; input.enterKeyHint = 'next';
            eye.hidden = false;
            hint.textContent = 'رمزی قوی انتخاب کنید 🔐';
            break;
        case 'reg_confirm':
            input.type = 'password'; input.placeholder = 'تکرار رمز عبور';
            input.autocomplete = 'new-password'; input.enterKeyHint = 'go';
            eye.hidden = false;
            hint.textContent = 'رمز عبور را دوباره وارد کنید';
            break;
        // حالت‌های نصب
        case 'install_username':
            input.type = 'text'; input.placeholder = 'آیدی مدیر (مثلاً: admin)';
            input.autocomplete = 'username'; input.enterKeyHint = 'next';
            hint.textContent = 'فقط حروف انگلیسی، اعداد و _ (حداقل 3 کاراکتر)';
            break;
        case 'install_name':
            input.type = 'text'; input.placeholder = 'نام نمایشی مدیر (اختیاری)';
            input.autocomplete = 'name'; input.enterKeyHint = 'next';
            hint.textContent = 'می‌توانید خالی بگذارید یا دکمه رد کردن را بزنید';
            break;
        case 'install_password':
            input.type = 'password'; input.placeholder = 'رمز عبور مدیر (حداقل 6 کاراکتر)';
            input.autocomplete = 'new-password'; input.enterKeyHint = 'next';
            eye.hidden = false;
            hint.textContent = 'رمزی قوی برای حساب مدیر انتخاب کنید 🔐';
            break;
        case 'install_confirm':
            input.type = 'password'; input.placeholder = 'تکرار رمز عبور';
            input.autocomplete = 'new-password'; input.enterKeyHint = 'go';
            eye.hidden = false;
            hint.textContent = 'رمز عبور را دوباره وارد کنید';
            break;
    }
    wrap.classList.add('show');
    setTimeout(() => input.focus(), 380);
}

function hideComposer() { wrap.classList.remove('show'); }

function shakeComposer() {
    form.classList.remove('shake'); void form.offsetWidth; form.classList.add('shake');
}

const errorMessages = {
    'نام کاربری یا رمز عبور اشتباه است': [
        'رمز اشتباه بود 🤔 یه بار دیگه دقت کن!',
        'این رمز با کاربری‌ت جور نیست! دوباره امتحان کن 🔐',
        'نزدیک بود! رمز درست رو یه بار دیگه وارد کن 🎯'
    ],
    'حساب شما مسدود شده است': [
        'حساب‌ت موقتاً مسدود شده 🚫 با پشتیبانی تماس بگیر',
    ],
    'حساب شما هنوز فعال نشده است': [
        'حساب‌ت هنوز فعال نشده! ⏳ منتظر تأیید مدیر باش',
    ],
    'کاربر یافت نشد': [
        'این نام کاربری رو نمی‌شناسم! 🤷 مطمئنی درسته؟',
        'کاربری با این اسم پیدا نشد! دوباره چک کن 🔍',
    ],
    'این نام کاربری قبلاً ثبت شده است': [
        'این نام کاربری قبلاً ثبت شده! 🙁 یکی دیگه انتخاب کن',
    ],
    'نام کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد': [
        'فقط حروف انگلیسی، اعداد و _ مجازه! 🔤',
        'آیدی فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد 🔤',
    ],
    'نام کاربری باید بین 3 تا 20 کاراکتر باشد': [
        'نام کاربری باید بین 3 تا 20 کاراکتر باشه! 📏',
        'آیدی باید بین 3 تا 20 کاراکتر باشه! 📏',
    ],
    'آیدی باید بین 3 تا 20 کاراکتر باشد': [
        'آیدی باید بین 3 تا 20 کاراکتر باشه! 📏',
    ],
    'آیدی فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد': [
        'آیدی فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد 🔤',
    ],
    'رمز عبور باید حداقل 6 کاراکتر باشد': [
        'رمز عبور باید حداقل 6 کاراکتر باشه! 🔐',
    ],
    'رمز عبور نمی‌تواند بیشتر از 100 کاراکتر باشد': [
        'رمز عبور خیلی طولانیه! حداکثر 100 کاراکتر مجازه 📏',
    ],
    'رمز عبور بیش از حد طولانی است': [
        'رمز عبور خیلی طولانیه! حداکثر 100 کاراکتر مجازه 📏',
    ]
};

function randomError(msg) {
    const arr = errorMessages[msg];
    if (!arr) return msg;
    return arr[Math.floor(Math.random() * arr.length)];
}

/* ── فرآیند نصب ── */
async function startInstall() {
    clearTempUI();
    step = 'install_username';
    installData = { username: '', name: '', password: '' };
    
    await botSay('👋 سلام! من دستیار نصب اسپاتیرا هستم.');
    await sleep(200);
    await botSay('📦 در حال آماده‌سازی پوشه‌ها و فایل‌های سیستم…\n✅ پوشه‌های data، uploads و avatars آماده شدند.\n✅ فایل‌های پایگاه داده ایجاد شدند.\n✅ فایل محافظت .htaccess (کد 403) ساخته شد.');
    await sleep(200);
    await botSay('حالا باید اطلاعات حساب مدیر سیستم را وارد کنی.\nاین حساب تمام دسترسی‌های مدیریتی را دارد 👑');
    await sleep(200);
    await botSay('لطفاً یک آیدی برای مدیر انتخاب کن.\nاین آیدی برای ورود استفاده می‌شود.');
    
    showComposer('install_username');
}

async function submitInstallUsername(val) {
    busy = true; hideComposer();
    addMsg('me', val);
    intro.classList.add('compact');
    
    // اعتبارسنجی در سمت کلاینت
    if (val.length < 3 || val.length > 20) {
        sysError('آیدی باید بین 3 تا 20 کاراکتر باشد');
        busy = false;
        showComposer('install_username');
        input.value = val;
        shakeComposer();
        return;
    }
    if (!/^[a-zA-Z0-9_]+$/.test(val)) {
        sysError('آیدی فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد');
        busy = false;
        showComposer('install_username');
        input.value = val;
        shakeComposer();
        return;
    }
    
    installData.username = val;
    await botSay(`آیدی «${val}» انتخاب شد ✅`);
    await sleep(200);
    await botSay('حالا یک نام نمایشی برای مدیر وارد کن (اختیاری).\nمی‌توانی خالی بگذاری.');
    
    const skipBtn = createChip('⏭️ رد کردن (بدون نام نمایشی)', () => {
        clearTempUI();
        installData.name = '';
        continueInstallAfterName();
    });
    const backBtn = createChip('↩️ تغییر آیدی', () => {
        clearTempUI();
        step = 'install_username';
        showComposer('install_username');
        input.value = installData.username;
    });
    actionRow([skipBtn, backBtn]);
    
    busy = false;
    showComposer('install_name');
}

async function submitInstallName(val) {
    busy = true; hideComposer();
    clearTempUI();
    
    if (val) {
        addMsg('me', val);
        installData.name = val;
    } else {
        addMsg('me', '(بدون نام نمایشی)');
        installData.name = '';
    }
    
    await continueInstallAfterName();
}

async function continueInstallAfterName() {
    busy = true;
    await botSay('حالا رمز عبور مدیر را وارد کن 🔐\nاین رمز باید قوی و حداقل 6 کاراکتر باشد.');
    busy = false;
    showComposer('install_password');
}

async function submitInstallPassword(val) {
    busy = true; hideComposer();
    addMsg('me', '••••••••');
    
    if (val.length < 6) {
        sysError('رمز عبور باید حداقل 6 کاراکتر باشد');
        busy = false;
        showComposer('install_password');
        shakeComposer();
        return;
    }
    
    installData.password = val;
    await botSay('رمز عبور دریافت شد ✅\nحالا برای اطمینان، دوباره آن را وارد کن.');
    busy = false;
    showComposer('install_confirm');
}

async function submitInstallConfirm(val) {
    busy = true; hideComposer();
    sendBtn.classList.add('busy');
    addMsg('me', '••••••••', true);
    
    if (val !== installData.password) {
        sendBtn.classList.remove('busy');
        sysError('رمز عبور و تکرار آن یکسان نیستند! 🤔');
        busy = false;
        showComposer('install_confirm');
        shakeComposer();
        return;
    }
    
    const t = showTyping();
    const res = await post('install', {
        username: installData.username,
        name: installData.name,
        password: installData.password
    });
    await sleep(600); removeTyping(t); await sleep(120);
    
    sendBtn.classList.remove('busy');
    
    if (res.error) {
        sysError(randomError(res.error));
        busy = false;
        showComposer('install_password');
        shakeComposer();
        return;
    }
    
    clearTempUI();
    celebrate();
    await botSay('🎉 نصب اسپاتیرا با موفقیت انجام شد!');
    await sleep(300);
    await botSay('✅ حساب مدیر سیستم ایجاد شد\n✅ فایل‌های پایگاه‌داده ساخته شدند\n✅ پوشه‌ها با .htaccess محافظت شدند');
    await sleep(300);
    await botSay('🔄 در حال انتقال به صفحه ورود…');
    setTimeout(() => { location.reload(); }, 1500);
}

/* ── فرآیند ورود ── */
async function startLogin() {
    clearTempUI();
    step = 'login_username';
    await botSay('🔑 عالیه! برای ورود، نام کاربری‌ت رو بنویس.');
    
    const backBtn = createChip('↩️ بازگشت به انتخاب', goToMainMenu);
    actionRow([backBtn]);
    
    showComposer('login_username');
}

async function submitLoginUsername(val) {
    busy = true; hideComposer();
    addMsg('me', val);
    intro.classList.add('compact');

    const t = showTyping();
    const res = await post('check_username', {username: val});
    await sleep(420); removeTyping(t); await sleep(120);

    if (res.error) {
        sysError(randomError(res.error));
        busy = false;
        showComposer('login_username');
        input.value = val;
        shakeComposer();
        return;
    }

    loginUsername = val;
    await botSay(`«${val}» پیدا شد ✅\nحالا رمز عبورت رو وارد کن.`);
    
    const backBtn = createChip('↩️ تغییر نام کاربری', () => {
        clearTempUI();
        step = 'login_username';
        showComposer('login_username');
        input.value = loginUsername;
    });
    actionRow([backBtn]);
    
    busy = false;
    showComposer('login_password');
}

async function submitLoginPassword(val) {
    busy = true; hideComposer();
    sendBtn.classList.add('busy');
    addMsg('me', '••••••••', true);

    const t = showTyping();
    const res = await post('login', {username: loginUsername, password: val});
    await sleep(500); removeTyping(t); await sleep(120);

    if (res.error) {
        sendBtn.classList.remove('busy');
        sysError(randomError(res.error));
        busy = false;
        showComposer('login_password');
        shakeComposer();
        return;
    }

    localStorage.setItem('last_username', loginUsername);

    clearTempUI();
    celebrate();
    await botSay('🎉 ورود موفق! خوش اومدی به اسپاتیرا');
    await sleep(300);
    await botSay('🚀 در حال انتقال به داشبورد…');
    setTimeout(() => { window.location.href = res.redirect || 'dash.php'; }, 1200);
}

/* ── فرآیند عضویت ── */
async function startRegister() {
    clearTempUI();
    step = 'reg_username';
    regData = { username: '', displayName: '', password: '' };
    
    await botSay('✨ خوشحالم که می‌خوای به ما بپیوندی!');
    await sleep(200);
    await botSay('برای شروع، یک نام کاربری دلخواه انتخاب کن.\nفقط حروف انگلیسی، اعداد و _ مجاز است.');
    
    const backBtn = createChip('↩️ بازگشت به انتخاب', goToMainMenu);
    actionRow([backBtn]);
    
    showComposer('reg_username');
}

async function submitRegUsername(val) {
    busy = true; hideComposer();
    addMsg('me', val);

    const t = showTyping();
    const res = await post('check_username_available', {username: val});
    await sleep(420); removeTyping(t); await sleep(120);

    if (res.error) {
        sysError(randomError(res.error));
        busy = false;
        showComposer('reg_username');
        input.value = val;
        shakeComposer();
        return;
    }

    regData.username = val;
    await botSay(`نام کاربری «${val}» آزاد است ✅`);
    await sleep(200);
    await botSay('حالا یک نام نمایشی انتخاب کن (اختیاری).\nاین نام را دیگران می‌بینند.');
    
    const skipBtn = createChip('⏭️ رد کردن (بدون نام نمایشی)', () => {
        clearTempUI();
        regData.displayName = '';
        continueRegAfterDisplayName();
    });
    const backBtn = createChip('↩️ تغییر نام کاربری', () => {
        clearTempUI();
        step = 'reg_username';
        showComposer('reg_username');
        input.value = regData.username;
    });
    actionRow([skipBtn, backBtn]);
    
    busy = false;
    showComposer('reg_display_name');
}

async function submitRegDisplayName(val) {
    busy = true; hideComposer();
    clearTempUI();
    
    if (val) {
        addMsg('me', val);
        regData.displayName = val;
    } else {
        addMsg('me', '(بدون نام نمایشی)');
        regData.displayName = '';
    }
    
    await continueRegAfterDisplayName();
}

async function continueRegAfterDisplayName() {
    busy = true;
    await botSay('حالا یک رمز عبور قوی انتخاب کن 🔐\nحداقل 6 کاراکتر.');
    busy = false;
    showComposer('reg_password');
}

async function submitRegPassword(val) {
    busy = true; hideComposer();
    addMsg('me', '••••••••');

    if (val.length < 6) {
        sysError('رمز عبور باید حداقل 6 کاراکتر باشد');
        busy = false;
        showComposer('reg_password');
        shakeComposer();
        return;
    }

    regData.password = val;
    await botSay('رمز عبور دریافت شد ✅\nحالا برای اطمینان، دوباره آن را وارد کن.');
    busy = false;
    showComposer('reg_confirm');
}

async function submitRegConfirm(val) {
    busy = true; hideComposer();
    sendBtn.classList.add('busy');
    addMsg('me', '••••••••', true);

    if (val !== regData.password) {
        sendBtn.classList.remove('busy');
        sysError('رمز عبور و تکرار آن یکسان نیستند! 🤔');
        busy = false;
        showComposer('reg_confirm');
        shakeComposer();
        return;
    }

    const t = showTyping();
    const res = await post('register', {
        username: regData.username,
        password: regData.password,
        display_name: regData.displayName
    });
    await sleep(600); removeTyping(t); await sleep(120);

    sendBtn.classList.remove('busy');

    if (res.error) {
        sysError(randomError(res.error));
        busy = false;
        if (res.error.includes('نام کاربری')) {
            regData.username = '';
            showComposer('reg_username');
        } else {
            showComposer('reg_password');
        }
        shakeComposer();
        return;
    }

    clearTempUI();
    celebrate();
    await botSay('🎉 عضویت شما با موفقیت ثبت شد!');
    await sleep(300);
    await botSay('📋 حساب شما ایجاد شد اما هنوز فعال نیست.\nمدیر سیستم باید آن را تأیید و فعال کند.');
    await sleep(300);
    await botSay('⏳ پس از فعال‌سازی، می‌توانید با همین نام کاربری و رمز عبور وارد شوید.');
    await sleep(300);
    await botSay('💬 پیام‌رسان اسپاتیرا - همراه لحظه‌های شما');
    
    const homeBtn = createChip('🏠 بازگشت به صفحه اصلی', () => {
        location.reload();
    }, true);
    actionRow([homeBtn]);
    
    busy = false;
}

/* ── منوی اصلی ── */
function goToMainMenu() {
    clearTempUI();
    step = 'choose';
    hideComposer();
    
    const buttons = [];
    const lastUser = localStorage.getItem('last_username');
    if (lastUser) {
        buttons.push(createChip(`🔑 ورود با ${lastUser}`, () => {
            input.value = lastUser;
            startLogin();
            submitLoginUsername(lastUser);
        }, true));
    }
    buttons.push(createChip('🔑 ورود با حساب دیگر', () => {
        startLogin();
    }));
    buttons.push(createChip('✨ عضویت', () => {
        startRegister();
    }));
    actionRow(buttons);
}

/* ── رویدادها ── */
form.addEventListener('submit', e => {
    e.preventDefault();
    if (busy) return;
    const val = input.value.trim();
    
    if (!val && step !== 'reg_display_name' && step !== 'install_name') {
        shakeComposer();
        input.focus();
        return;
    }
    input.value = '';
    
    switch(step) {
        case 'install_username': submitInstallUsername(val); break;
        case 'install_name': submitInstallName(val); break;
        case 'install_password': submitInstallPassword(val); break;
        case 'install_confirm': submitInstallConfirm(val); break;
        case 'login_username': submitLoginUsername(val); break;
        case 'login_password': submitLoginPassword(val); break;
        case 'reg_username': submitRegUsername(val); break;
        case 'reg_display_name': submitRegDisplayName(val); break;
        case 'reg_password': submitRegPassword(val); break;
        case 'reg_confirm': submitRegConfirm(val); break;
    }
});

eye.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    eye.querySelector('.eye-open').hidden = show;
    eye.querySelector('.eye-closed').hidden = !show;
    input.focus();
});

/* ── شروع گفتگو ── */
(async function start() {
    await sleep(650);

    const hour = new Date().getHours();
    let greeting, followup;
    if (hour >= 5 && hour < 12) {
        greeting = '☀️ صبح بخیر';
        followup = 'امیدوارم روز پرانرژی‌ای داشته باشی!';
    } else if (hour >= 12 && hour < 17) {
        greeting = '🌤️ ظهر بخیر';
        followup = 'ظهرتون بخیر، آماده کمک‌ام!';
    } else if (hour >= 17 && hour < 21) {
        greeting = '🌆 عصر بخیر';
        followup = 'عصر خوبی داشته باشی!';
    } else {
        greeting = '🌙 شب بخیر';
        followup = 'شب‌تون بخیر، آماده خدمت‌ام!';
    }

    await botSay(`${greeting}\nمن دستیار اسپاتیرا هستم 👋`);
    intro.classList.add('compact');
    await sleep(200);
    
    if (!INSTALLED) {
        await botSay('🆕 سیستم هنوز نصب نشده است!');
        await sleep(200);
        await botSay('برای شروع کار با اسپاتیرا، ابتدا باید حساب مدیر و فایل‌های پایگاه‌داده ایجاد شوند.');
        await sleep(200);
        const installBtn = createChip('🚀 شروع نصب', () => {
            startInstall();
        }, true);
        actionRow([installBtn]);
    } else {
        await botSay(followup);
        await sleep(200);
        await botSay('آیا حساب کاربری دارید یا می‌خواهید عضو شوید؟');
        goToMainMenu();
    }
})();
</script>
</body>
</html>