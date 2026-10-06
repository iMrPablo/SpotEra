<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
@ini_set('max_execution_time', '300');
@ini_set('max_input_time', '300');
@ini_set('memory_limit', '512M');
@ini_set('post_max_size', '500M');
@ini_set('upload_max_filesize', '500M');
define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('MESSAGES_FILE', DATA_DIR . '/messages.json');
define('CONFIG_FILE', DATA_DIR . '/config.json');
define('CHATS_FILE', DATA_DIR . '/chats.json');
define('READS_FILE', DATA_DIR . '/reads.json');
define('WALLET_CONFIG_FILE', DATA_DIR . '/wallet_config.json');
define('WALLET_HISTORY_FILE', DATA_DIR . '/wallet_history.json');
define('UPLOADS_DIR', DATA_DIR . '/uploads');
define('AVATARS_DIR', DATA_DIR . '/avatars');
define('NORMAL_MAX_UPLOAD_MB', 200);
define('PREMIUM_MAX_UPLOAD_MB', 500);
define('NORMAL_BIO_MAX', 200);
define('PREMIUM_BIO_MAX', 1000);
if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0755, true);
if (!is_dir(AVATARS_DIR)) @mkdir(AVATARS_DIR, 0755, true);
function read_json($file) { if (!file_exists($file)) return []; $d = json_decode(file_get_contents($file), true); return is_array($d) ? $d : []; }
function write_json($file, $data) { file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); }
function generate_id() { return uniqid('x_', true) . '_' . bin2hex(random_bytes(4)); }
function find_user_by_id($id) { foreach (read_json(USERS_FILE) as $u) { if ($u['id'] === $id) return $u; } return null; }
function find_user_by_username($username) { $username = mb_strtolower(trim($username)); foreach (read_json(USERS_FILE) as $u) { if (mb_strtolower(trim($u['username'])) === $username) return $u; } return null; }
function username_exists($username, $exclude_id = null) { $username = mb_strtolower(trim($username)); foreach (read_json(USERS_FILE) as $u) { if ($exclude_id && $u['id'] === $exclude_id) continue; if (mb_strtolower(trim($u['username'])) === $username) return true; } return false; }
function current_user() { if (!isset($_SESSION['user_id'])) return null; return find_user_by_id($_SESSION['user_id']); }
function is_admin_user($user) { return !empty($user['is_admin']); }
function is_premium_user($user) { return !empty($user['premium_until']) && $user['premium_until'] > time(); }
function get_wallet_config() {
    if (!file_exists(WALLET_CONFIG_FILE)) {
        $default = ['spc_to_toman' => 1000];
        write_json(WALLET_CONFIG_FILE, $default);
        return $default;
    }
    $data = read_json(WALLET_CONFIG_FILE);
    return !empty($data) ? $data : ['spc_to_toman' => 1000];
}
function add_wallet_history_entry($user_id, $entry) {
    $history = read_json(WALLET_HISTORY_FILE);
    if (!isset($history[$user_id])) $history[$user_id] = [];
    $entry['id'] = generate_id();
    $entry['created_at'] = time();
    array_unshift($history[$user_id], $entry);
    if (count($history[$user_id]) > 200) $history[$user_id] = array_slice($history[$user_id], 0, 200);
    write_json(WALLET_HISTORY_FILE, $history);
}
function safe_user($u) {
    $premium = is_premium_user($u);
    $premium_until = $premium ? $u['premium_until'] : 0;
    $days_left = $premium ? max(0, ceil(($u['premium_until'] - time()) / 86400)) : 0;
    return [
        'id' => $u['id'],
        'username' => $u['username'],
        'name' => $u['name'] ?? '',
        'bio' => $u['bio'] ?? '',
        'avatar' => $u['avatar'] ?? '',
        'is_admin' => !empty($u['is_admin']),
        'active' => !isset($u['active']) || !empty($u['active']),
        'blocked' => !empty($u['blocked']),
        'is_bot' => !empty($u['is_bot']),
        'privacy_searchable' => !isset($u['privacy_searchable']) || !empty($u['privacy_searchable']),
        'created_at' => $u['created_at'] ?? time(),
        'last_activity' => $u['last_activity'] ?? time(),
        'verified' => !empty($u['verified']),
        'premium' => $premium,
        'premium_until' => $premium_until,
        'premium_days_left' => $days_left,
        'premium_color' => $u['premium_color'] ?? '',
        'premium_message_sound' => !empty($u['premium_message_sound']),
        'premium_animated_avatar' => !empty($u['premium_animated_avatar']),
        'premium_hide_last_seen' => !empty($u['premium_hide_last_seen']),
        'wallet_balance' => $u['wallet_balance'] ?? 0,
    ];
}
function message_preview($m) { if (!empty($m['file_path'])) { $caption = trim($m['caption'] ?? ''); if ($caption !== '') return $caption; if (($m['file_type'] ?? '') === 'voice') return '🎙️ پیام صوتی'; if (!empty($m['file_name'])) return '📎 ' . $m['file_name']; return '📎 فایل'; } return trim($m['text'] ?? ''); }
function unlink_message_file($m) { if (!empty($m['file_path'])) { $path = __DIR__ . '/' . ltrim($m['file_path'], '/'); if (is_file($path)) @unlink($path); } }
function delete_chat_data($chat_id) { $chats = read_json(CHATS_FILE); $new_chats = []; foreach ($chats as $c) { if (($c['id'] ?? '') === $chat_id) continue; $new_chats[] = $c; } write_json(CHATS_FILE, $new_chats); $messages = read_json(MESSAGES_FILE); $new_messages = []; foreach ($messages as $m) { if (($m['chat_id'] ?? '') === $chat_id) { unlink_message_file($m); continue; } $new_messages[] = $m; } write_json(MESSAGES_FILE, $new_messages); }
function update_messages_username($user_id, $new_username) { $messages = read_json(MESSAGES_FILE); $changed = false; foreach ($messages as &$m) { if (($m['user_id'] ?? '') === $user_id) { $m['username'] = $new_username; $changed = true; } } unset($m); if ($changed) write_json(MESSAGES_FILE, $messages); }
function dir_size($dir) { if (!is_dir($dir)) return 0; $size = 0; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) { if ($file->isFile()) $size += $file->getSize(); } return $size; }
function ensure_saved_chat($user) { $chats = read_json(CHATS_FILE); foreach ($chats as $c) { if (($c['type'] ?? '') === 'saved' && ($c['owner_id'] ?? '') === $user['id']) return $c; } $chat = ['id' => generate_id(), 'type' => 'saved', 'name' => 'پیام‌های ذخیره‌شده', 'description' => 'فضای شخصی شما', 'owner_id' => $user['id'], 'members' => [$user['id']], 'avatar_image' => '', 'verified' => false, 'created_at' => time()]; $chats[] = $chat; write_json(CHATS_FILE, $chats); return $chat; }
function get_unread_count($chat_id, $user_id) { $reads = read_json(READS_FILE); $last_read_id = $reads[$chat_id][$user_id] ?? null; $messages = read_json(MESSAGES_FILE); $chat_msgs = []; foreach ($messages as $m) { if (($m['chat_id'] ?? '') === $chat_id) $chat_msgs[] = $m; } usort($chat_msgs, fn($a, $b) => ($a['created_at'] ?? 0) - ($b['created_at'] ?? 0)); if (empty($chat_msgs)) return 0; if (!$last_read_id) { $count = 0; foreach ($chat_msgs as $m) { if (($m['user_id'] ?? '') !== $user_id) $count++; } return $count; } $found = false; $count = 0; foreach ($chat_msgs as $m) { if ($found && ($m['user_id'] ?? '') !== $user_id) $count++; if (($m['id'] ?? '') === $last_read_id) $found = true; } return $count; }
function is_message_seen($message, $chat_members, $current_user_id) { if (($message['user_id'] ?? '') !== $current_user_id) return true; $seen_by = $message['seen_by'] ?? []; foreach ($chat_members as $mid) { if ($mid !== $current_user_id && in_array($mid, $seen_by)) return true; } return false; }
function update_last_activity($user_id) {
    $users = read_json(USERS_FILE);
    $changed = false;
    $now = time();
    foreach ($users as &$u) {
        if ($u['id'] === $user_id) {
            $last = $u['last_activity'] ?? 0;
            if ($now - $last >= 45) {
                $u['last_activity'] = $now;
                $changed = true;
            }
            break;
        }
    }
    unset($u);
    if ($changed) write_json(USERS_FILE, $users);
}
function get_online_status($user) { if (empty($user) || empty($user['id'])) return ['online' => false, 'last_seen' => 0, 'text' => '']; $hide_last_seen = !empty($user['premium_hide_last_seen']) && is_premium_user($user); $last = $user['last_activity'] ?? time(); $diff = time() - $last; $online = $diff < 60; if ($online) $text = 'آنلاین'; elseif ($hide_last_seen) $text = 'اخیراً'; elseif ($diff < 3600) $text = floor($diff / 60) . ' دقیقه پیش'; elseif ($diff < 86400) $text = floor($diff / 3600) . ' ساعت پیش'; elseif ($diff < 172800) $text = 'دیروز'; else $text = date('Y/m/d', $last); return ['online' => $online, 'last_seen' => $last, 'text' => $text, 'hide_last_seen' => $hide_last_seen]; }
if (!file_exists(CONFIG_FILE)) { header('Location: index.php'); exit; }
$user = current_user();
if (!$user) { header('Location: index.php'); exit; }
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action === 'serve_file') {
        $p = str_replace('\\', '/', trim($_GET['p'] ?? ''));
        $p = ltrim($p, '/');
        if (strpos($p, '..') !== false) { http_response_code(403); exit('Forbidden'); }
        $ok = (strpos($p, 'data/uploads/') === 0) || (strpos($p, 'data/avatars/') === 0);
        if (!$ok) { http_response_code(403); exit('Forbidden'); }
        $full = __DIR__ . '/' . $p;
        if (!is_file($full)) { http_response_code(404); exit('Not found'); }
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'opus' => 'audio/opus', 'amr' => 'audio/amr'];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: private, max-age=86400');
        if (!empty($_GET['dl'])) {
            header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        } else {
            header('Content-Disposition: inline; filename="' . basename($p) . '"');
        }
        readfile($full);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($action === 'poll_state') {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        update_last_activity($user['id']);
        session_write_close();
        $known = trim($_GET['known'] ?? '');
        $wait = min(25, max(0, (int)($_GET['wait'] ?? 0)));
        $get_state = function () {
            clearstatcache();
            $m = (@filemtime(MESSAGES_FILE) ?: 0) . ':' . (@filesize(MESSAGES_FILE) ?: 0);
            $c = (@filemtime(CHATS_FILE) ?: 0) . ':' . (@filesize(CHATS_FILE) ?: 0);
            $r = (@filemtime(READS_FILE) ?: 0) . ':' . (@filesize(READS_FILE) ?: 0);
            $u = (@filemtime(USERS_FILE) ?: 0) . ':' . (@filesize(USERS_FILE) ?: 0);
            return md5($m . '|' . $c . '|' . $r . '|' . $u);
        };
        $state = $get_state();
        if ($wait > 0 && $known !== '' && $state === $known) {
            $end = microtime(true) + $wait;
            while (microtime(true) < $end) {
                usleep(400000);
                $state = $get_state();
                if ($state !== $known) break;
            }
        }
        echo json_encode(['state' => $state, 'changed' => ($known === '' || $state !== $known), 'ts' => time()]);
        exit;
    }
    update_last_activity($user['id']);
    switch ($action) {
        case 'logout':
            $_SESSION = [];
            if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); }
            session_destroy();
            echo json_encode(['success' => true, 'redirect' => 'index.php']);
            exit;
        case 'get_chats':
            ensure_saved_chat($user);
            $chats = read_json(CHATS_FILE);
            $users = read_json(USERS_FILE);
            $messages = read_json(MESSAGES_FILE);
            $my_chats = [];
            foreach ($chats as $chat) {
                if (!in_array($user['id'], $chat['members'] ?? [])) continue;
                if (($chat['type'] ?? '') === 'private') {
                    if (count($chat['members']) < 2) continue;
                    $other_id = $chat['members'][0] === $user['id'] ? $chat['members'][1] : $chat['members'][0];
                    $other = null;
                    foreach ($users as $u) { if ($u['id'] === $other_id) { $other = $u; break; } }
                    if (!$other) continue;
                    $display = trim($other['name'] ?? '') !== '' ? $other['name'] : $other['username'];
                    $chat['display_name'] = $display;
                    $chat['avatar_path'] = $other['avatar'] ?? '';
                    $chat['other_user_id'] = $other_id;
                    $chat['other_username'] = $other['username'] ?? '';
                    $chat['other_verified'] = !empty($other['verified']);
                    $chat['other_premium'] = is_premium_user($other);
                    $status = get_online_status($other);
                    $chat['other_online'] = $status['online'];
                    $chat['other_last_seen_text'] = $status['text'];
                } else {
                    $chat['display_name'] = $chat['name'] ?? 'بدون نام';
                    $chat['avatar_path'] = $chat['avatar_image'] ?? '';
                    $owner = find_user_by_id($chat['owner_id'] ?? '');
                    $chat['owner_name'] = $owner ? ($owner['name'] ?: $owner['username']) : 'نامشخص';
                    $chat['owner_username'] = $owner ? $owner['username'] : '';
                    $chat['owner_premium'] = $owner ? is_premium_user($owner) : false;
                    $online_count = 0;
                    foreach ($chat['members'] as $mid) {
                        if ($mid === $user['id']) continue;
                        $u = find_user_by_id($mid);
                        if ($u) { $st = get_online_status($u); if ($st['online']) $online_count++; }
                    }
                    $chat['online_members_count'] = $online_count;
                }
                $chat_messages = array_filter($messages, fn($m) => ($m['chat_id'] ?? '') === $chat['id']);
                usort($chat_messages, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));
                $last = $chat_messages[0] ?? null;
                $chat['last_message'] = $last ? mb_substr(message_preview($last), 0, 70) : '';
                $chat['last_time'] = $last ? ($last['created_at'] ?? time()) : ($chat['created_at'] ?? time());
                $chat['unread_count'] = get_unread_count($chat['id'], $user['id']);
                $chat['last_is_mine'] = $last ? (($last['user_id'] ?? '') === $user['id']) : false;
                $chat['last_seen'] = $last ? is_message_seen($last, $chat['members'] ?? [], $user['id']) : false;
                $chat['last_username'] = $last ? ($last['username'] ?? '') : '';
                $my_chats[] = $chat;
            }
            usort($my_chats, fn($a, $b) => ($b['last_time'] ?? 0) - ($a['last_time'] ?? 0));
            echo json_encode(['chats' => $my_chats], JSON_UNESCAPED_UNICODE);
            exit;
        case 'get_messages':
            $chat_id = $_GET['chat_id'] ?? '';
            if (!$chat_id) { echo json_encode(['error' => 'چت نامعتبر است']); exit; }
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $messages = read_json(MESSAGES_FILE);
            $users = read_json(USERS_FILE);
            $chat_messages = [];
            foreach ($messages as $m) {
                if (($m['chat_id'] ?? '') === $chat_id) {
                    $sender = null;
                    foreach ($users as $uu) { if ($uu['id'] === ($m['user_id'] ?? '')) { $sender = $uu; break; } }
                    $m['sender_premium'] = $sender ? is_premium_user($sender) : false;
                    $m['sender_premium_color'] = $sender ? ($sender['premium_color'] ?? '') : '';
                    $m['sender_verified'] = $sender ? !empty($sender['verified']) : false;
                    $chat_messages[] = $m;
                }
            }
            usort($chat_messages, fn($a, $b) => ($a['created_at'] ?? 0) - ($b['created_at'] ?? 0));
            if ($chat['type'] === 'private') {
                $other_id = $chat['members'][0] === $user['id'] ? $chat['members'][1] : $chat['members'][0];
                $other = find_user_by_id($other_id);
                if ($other) {
                    $status = get_online_status($other);
                    $chat['other_user_id'] = $other_id;
                    $chat['other_username'] = $other['username'] ?? '';
                    $chat['other_online'] = $status['online'];
                    $chat['other_last_seen_text'] = $status['text'];
                    $chat['other_verified'] = !empty($other['verified']);
                    $chat['other_premium'] = is_premium_user($other);
                    $chat['other_premium_color'] = $other['premium_color'] ?? '';
                    $chat['display_name'] = trim($other['name'] ?? '') !== '' ? $other['name'] : $other['username'];
                    $chat['avatar_path'] = $other['avatar'] ?? '';
                }
            } else {
                $online_count = 0;
                foreach ($chat['members'] as $mid) {
                    if ($mid === $user['id']) continue;
                    $u = find_user_by_id($mid);
                    if ($u) { $st = get_online_status($u); if ($st['online']) $online_count++; }
                }
                $chat['online_members_count'] = $online_count;
                $chat['display_name'] = $chat['name'] ?? 'بدون نام';
                $chat['avatar_path'] = $chat['avatar_image'] ?? '';
                $owner = find_user_by_id($chat['owner_id'] ?? '');
                $chat['owner_name'] = $owner ? ($owner['name'] ?: $owner['username']) : 'نامشخص';
                $chat['owner_username'] = $owner ? $owner['username'] : '';
                $chat['owner_premium'] = $owner ? is_premium_user($owner) : false;
            }
            echo json_encode(['messages' => $chat_messages, 'chat' => $chat, 'current_user' => safe_user($user)], JSON_UNESCAPED_UNICODE);
            exit;
        case 'mark_chat_read':
            $chat_id = $_POST['chat_id'] ?? '';
            if (!$chat_id) { echo json_encode(['error' => 'چت نامعتبر است']); exit; }
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $messages = read_json(MESSAGES_FILE);
            $chat_msgs = [];
            foreach ($messages as $m) { if (($m['chat_id'] ?? '') === $chat_id) $chat_msgs[] = $m; }
            usort($chat_msgs, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));
            if (empty($chat_msgs)) { echo json_encode(['success' => true]); exit; }
            $last_msg_id = $chat_msgs[0]['id'] ?? null;
            if (!$last_msg_id) { echo json_encode(['success' => true]); exit; }
            $reads = read_json(READS_FILE);
            if (!isset($reads[$chat_id])) $reads[$chat_id] = [];
            $reads[$chat_id][$user['id']] = $last_msg_id;
            write_json(READS_FILE, $reads);
            if (($chat['type'] ?? '') === 'private') {
                $changed = false;
                foreach ($messages as &$m) {
                    if (($m['chat_id'] ?? '') === $chat_id && ($m['user_id'] ?? '') !== $user['id']) {
                        $seen_by = $m['seen_by'] ?? [];
                        if (!in_array($user['id'], $seen_by)) { $seen_by[] = $user['id']; $m['seen_by'] = $seen_by; $changed = true; }
                    }
                }
                unset($m);
                if ($changed) write_json(MESSAGES_FILE, $messages);
            }
            echo json_encode(['success' => true]);
            exit;
        case 'send_message':
            $chat_id = $_POST['chat_id'] ?? '';
            $text = trim($_POST['text'] ?? '');
            $caption = trim($_POST['caption'] ?? '');
            $has_file = isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;
            if ($has_file && $_FILES['file']['error'] !== 0) { $err = $_FILES['file']['error']; $msg = ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) ? 'حجم فایل بیشتر از حد مجاز سرور است' : 'خطا در آپلود فایل'; echo json_encode(['error' => $msg]); exit; }
            if (!$has_file && $text === '') { echo json_encode(['error' => 'پیام خالی است']); exit; }
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            if (($chat['type'] ?? '') === 'channel' && ($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'فقط مالک کانال می‌تواند پیام ارسال کند']); exit; }
            $file_path = null;
            $file_type = null;
            $file_name = null;
            if ($has_file) {
                $max_mb = is_premium_user($user) ? PREMIUM_MAX_UPLOAD_MB : NORMAL_MAX_UPLOAD_MB;
                $max_bytes = $max_mb * 1024 * 1024;
                if ($_FILES['file']['size'] > $max_bytes) {
                    echo json_encode(['error' => 'حجم فایل بیشتر از حد مجاز (' . $max_mb . ' مگابایت) است']);
                    exit;
                }
                if ($caption === '' && $text !== '') $caption = $text;
                $f = $_FILES['file'];
                $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
                $safe_name = generate_id() . ($ext !== '' ? '.' . $ext : '');
                $dest = UPLOADS_DIR . '/' . $safe_name;
                if (!move_uploaded_file($f['tmp_name'], $dest)) { echo json_encode(['error' => 'ذخیره فایل ناموفق بود']); exit; }
                $file_path = 'data/uploads/' . $safe_name;
                $file_name = $f['name'];
                $mime = @mime_content_type($dest);
                if ($mime && strpos($mime, 'image/') === 0) $file_type = 'image'; elseif ($mime && strpos($mime, 'video/') === 0) $file_type = 'video'; elseif ($mime && strpos($mime, 'audio/') === 0) $file_type = 'voice'; else $file_type = 'file';
            }
            $messages = read_json(MESSAGES_FILE);
            $reply_to_data = null;
            if (!empty($_POST['reply_to_id'])) {
                $reply_id = $_POST['reply_to_id'];
                foreach ($messages as $rm) {
                    if (($rm['id'] ?? '') === $reply_id && ($rm['chat_id'] ?? '') === $chat_id) {
                        $reply_to_data = ['id' => $rm['id'], 'username' => $rm['username'] ?? '', 'text' => message_preview($rm)];
                        break;
                    }
                }
            }
            $message_color = '';
            if (is_premium_user($user) && !empty($_POST['message_color'])) {
                $allowed_msg_colors = ['', '#FF6B6B', '#4ECDC4', '#A78BFA', '#F59E0B', '#EC4899', '#10B981', '#3B82F6', '#FFD700', '#FF1493', '#00CED1', '#9370DB'];
                if (in_array($_POST['message_color'], $allowed_msg_colors)) {
                    $message_color = $_POST['message_color'];
                }
            }
            $msg = ['id' => generate_id(), 'chat_id' => $chat_id, 'user_id' => $user['id'], 'username' => trim($user['name'] ?? '') !== '' ? $user['name'] : $user['username'], 'text' => $has_file ? '' : $text, 'caption' => $has_file ? $caption : '', 'file_path' => $file_path, 'file_type' => $file_type, 'file_name' => $file_name, 'created_at' => time(), 'edited' => false, 'seen_by' => [$user['id']], 'reactions' => [], 'reply_to' => $reply_to_data, 'is_pinned' => false, 'sender_premium' => is_premium_user($user), 'sender_premium_color' => $user['premium_color'] ?? '', 'sender_verified' => !empty($user['verified']), 'message_color' => $message_color];
            if ($file_type === 'voice') {
                $vd = max(0, min(3600, (int)($_POST['voice_duration'] ?? 0)));
                if ($vd > 0) $msg['voice_duration'] = (string)$vd;
                if (!empty($_POST['voice_waveform'])) {
                    $vw = array_slice(array_values(array_filter(explode(',', $_POST['voice_waveform']), function($v) { return $v !== ''; })), 0, 40);
                    $msg['waveform'] = implode(':', $vw);
                }
            }
            $messages[] = $msg;
            write_json(MESSAGES_FILE, $messages);
            echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        case 'save_message':
            $msg_id = $_POST['message_id'] ?? '';
            $messages = read_json(MESSAGES_FILE);
            $orig = null;
            foreach ($messages as $m) { if (($m['id'] ?? '') === $msg_id) { $orig = $m; break; } }
            if (!$orig) { echo json_encode(['error' => 'پیام یافت نشد']); exit; }
            $chats = read_json(CHATS_FILE);
            $orig_chat = null;
            foreach ($chats as $c) { if (($c['id'] ?? '') === ($orig['chat_id'] ?? '')) { $orig_chat = $c; break; } }
            if (!$orig_chat || !in_array($user['id'], $orig_chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $saved = ensure_saved_chat($user);
            $new = $orig;
            $new['id'] = generate_id();
            $new['chat_id'] = $saved['id'];
            $new['user_id'] = $user['id'];
            $new['username'] = trim($user['name'] ?? '') !== '' ? $user['name'] : $user['username'];
            $new['created_at'] = time();
            $new['edited'] = false;
            $new['seen_by'] = [$user['id']];
            $new['saved_from'] = $orig['username'] ?? '';
            $new['reactions'] = [];
            $messages[] = $new;
            write_json(MESSAGES_FILE, $messages);
            echo json_encode(['success' => true]);
            exit;
        case 'react_to_message':
            $msg_id = $_POST['message_id'] ?? '';
            $emoji = trim($_POST['emoji'] ?? '');
            $allowed_emojis = ['👍', '❤️', '😂', '😮', '😢', '🔥', '🎉', '👏', '🤔', '😍'];
            if (!in_array($emoji, $allowed_emojis)) { echo json_encode(['error' => 'ایموجی نامعتبر است']); exit; }
            $messages = read_json(MESSAGES_FILE);
            $changed = false;
            foreach ($messages as &$m) {
                if (($m['id'] ?? '') === $msg_id) {
                    $chats = read_json(CHATS_FILE);
                    $chat = null;
                    foreach ($chats as $c) { if ($c['id'] === ($m['chat_id'] ?? '')) { $chat = $c; break; } }
                    if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
                    $reactions = $m['reactions'] ?? [];
                    if (!isset($reactions[$emoji])) $reactions[$emoji] = [];
                    if (in_array($user['id'], $reactions[$emoji])) {
                        $reactions[$emoji] = array_values(array_diff($reactions[$emoji], [$user['id']]));
                        if (empty($reactions[$emoji])) unset($reactions[$emoji]);
                    } else {
                        $reactions[$emoji][] = $user['id'];
                    }
                    $m['reactions'] = $reactions;
                    $changed = true;
                    break;
                }
            }
            unset($m);
            if ($changed) write_json(MESSAGES_FILE, $messages);
            echo json_encode(['success' => true]);
            exit;
        case 'forward_message':
            $chat_id = $_POST['chat_id'] ?? '';
            $msg_id = $_POST['message_id'] ?? '';
            if (!$chat_id || !$msg_id) { echo json_encode(['error' => 'پارامترها نامعتبر است']); exit; }
            $chats = read_json(CHATS_FILE);
            $target_chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $target_chat = $c; break; } }
            if (!$target_chat || !in_array($user['id'], $target_chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            if (($target_chat['type'] ?? '') === 'channel' && ($target_chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'فقط مالک کانال می‌تواند پیام ارسال کند']); exit; }
            $messages = read_json(MESSAGES_FILE);
            $orig = null;
            foreach ($messages as $m) { if (($m['id'] ?? '') === $msg_id) { $orig = $m; break; } }
            if (!$orig) { echo json_encode(['error' => 'پیام یافت نشد']); exit; }
            $orig_chat = null;
            foreach ($chats as $c) { if ($c['id'] === ($orig['chat_id'] ?? '')) { $orig_chat = $c; break; } }
            if (!$orig_chat || !in_array($user['id'], $orig_chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $new_msg = $orig;
            $new_msg['id'] = generate_id();
            $new_msg['chat_id'] = $chat_id;
            $new_msg['user_id'] = $user['id'];
            $new_msg['username'] = trim($user['name'] ?? '') !== '' ? $user['name'] : $user['username'];
            $new_msg['created_at'] = time();
            $new_msg['edited'] = false;
            $new_msg['seen_by'] = [$user['id']];
            $new_msg['forwarded_from'] = $orig['username'] ?? 'نامشخص';
            $new_msg['reactions'] = [];
            $messages[] = $new_msg;
            write_json(MESSAGES_FILE, $messages);
            echo json_encode(['success' => true, 'message' => $new_msg], JSON_UNESCAPED_UNICODE);
            exit;
        case 'edit_message':
            $msg_id = $_POST['message_id'] ?? '';
            $text = trim($_POST['text'] ?? '');
            $messages = read_json(MESSAGES_FILE);
            $found = false;
            foreach ($messages as &$m) {
                if (($m['id'] ?? '') === $msg_id) {
                    if (($m['user_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
                    if (!empty($m['file_path'])) { $m['caption'] = $text; $m['text'] = ''; } else { $m['text'] = $text; $m['caption'] = ''; }
                    $m['edited'] = true;
                    $found = true;
                    break;
                }
            }
            unset($m);
            if (!$found) { echo json_encode(['error' => 'پیام یافت نشد']); exit; }
            write_json(MESSAGES_FILE, $messages);
            echo json_encode(['success' => true]);
            exit;
        case 'delete_message':
            $msg_id = $_POST['message_id'] ?? '';
            $messages = read_json(MESSAGES_FILE);
            $chats = read_json(CHATS_FILE);
            $new_msgs = [];
            foreach ($messages as $m) {
                if (($m['id'] ?? '') === $msg_id) {
                    $chat = null;
                    foreach ($chats as $c) { if ($c['id'] === ($m['chat_id'] ?? '')) { $chat = $c; break; } }
                    $is_owner = $chat && ($chat['owner_id'] ?? '') === $user['id'];
                    if (($m['user_id'] ?? '') !== $user['id'] && !$is_owner && empty($user['is_admin'])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
                    unlink_message_file($m);
                    continue;
                }
                $new_msgs[] = $m;
            }
            write_json(MESSAGES_FILE, $new_msgs);
            echo json_encode(['success' => true]);
            exit;
        case 'toggle_pin_message':
            $msg_id = $_POST['message_id'] ?? '';
            $messages = read_json(MESSAGES_FILE);
            $changed = false;
            foreach ($messages as &$m) {
                if (($m['id'] ?? '') === $msg_id) {
                    $chats = read_json(CHATS_FILE);
                    $chat = null;
                    foreach ($chats as $c) { if ($c['id'] === ($m['chat_id'] ?? '')) { $chat = $c; break; } }
                    if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
                    $m['is_pinned'] = empty($m['is_pinned']);
                    $changed = true;
                    break;
                }
            }
            unset($m);
            if ($changed) write_json(MESSAGES_FILE, $messages);
            echo json_encode(['success' => true]);
            exit;
        case 'clear_chat':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            if (($chat['type'] ?? '') !== 'saved' && ($chat['type'] ?? '') !== 'private' && ($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'فقط مالک یا ادمین می‌تواند تاریخچه را پاک کند']); exit; }
            $messages = read_json(MESSAGES_FILE);
            $new_messages = [];
            foreach ($messages as $m) {
                if (($m['chat_id'] ?? '') === $chat_id) {
                    unlink_message_file($m);
                    continue;
                }
                $new_messages[] = $m;
            }
            write_json(MESSAGES_FILE, $new_messages);
            echo json_encode(['success' => true]);
            exit;
        case 'add_members_to_chat':
            $chat_id = $_POST['chat_id'] ?? '';
            $members = $_POST['members'] ?? [];
            if ($chat_id === '') { echo json_encode(['error' => 'چت نامعتبر است']); exit; }
            if (is_string($members)) $members = explode(',', $members);
            $member_ids = array_filter(array_map('trim', (array)$members));
            if (empty($member_ids)) { echo json_encode(['error' => 'حداقل یک کاربر انتخاب کنید']); exit; }
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat) { echo json_encode(['error' => 'چت یافت نشد']); exit; }
            if (($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'فقط مالک یا ادمین می‌تواند عضو اضافه کند']); exit; }
            if ($chat['type'] === 'private' || $chat['type'] === 'saved') { echo json_encode(['error' => 'افزودن عضو به این چت ممکن نیست']); exit; }
            $current_members = $chat['members'] ?? [];
            $new_members = array_values(array_unique(array_merge($current_members, $member_ids)));
            foreach ($chats as &$c) { if ($c['id'] === $chat_id) { $c['members'] = $new_members; break; } }
            unset($c);
            write_json(CHATS_FILE, $chats);
            echo json_encode(['success' => true, 'added_count' => count($new_members) - count($current_members)]);
            exit;
        case 'remove_member_from_chat':
            $chat_id = $_POST['chat_id'] ?? '';
            $target_id = $_POST['user_id'] ?? '';
            if ($chat_id === '' || $target_id === '') { echo json_encode(['error' => 'پارامترها نامعتبر است']); exit; }
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat) { echo json_encode(['error' => 'چت یافت نشد']); exit; }
            if (($chat['type'] ?? '') === 'private' || ($chat['type'] ?? '') === 'saved') { echo json_encode(['error' => 'حذف عضو از این چت ممکن نیست']); exit; }
            if (($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'فقط مالک یا ادمین می‌تواند عضو را حذف کند']); exit; }
            if (($chat['owner_id'] ?? '') === $target_id) { echo json_encode(['error' => 'نمی‌توان مالک را حذف کرد']); exit; }
            if (!in_array($target_id, $chat['members'] ?? [])) { echo json_encode(['error' => 'این کاربر عضو چت نیست']); exit; }
            foreach ($chats as &$c) { if ($c['id'] === $chat_id) { $c['members'] = array_values(array_diff($c['members'] ?? [], [$target_id])); break; } }
            unset($c);
            write_json(CHATS_FILE, $chats);
            echo json_encode(['success' => true]);
            exit;
        case 'upload_user_avatar':
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== 0) { echo json_encode(['error' => 'فایلی انتخاب نشده است']); exit; }
            $f = $_FILES['avatar'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) { echo json_encode(['error' => 'فرمت فایل نامعتبر است']); exit; }
            $safe_name = $user['id'] . '_' . generate_id() . '.' . $ext;
            $dest = AVATARS_DIR . '/' . $safe_name;
            if (!move_uploaded_file($f['tmp_name'], $dest)) { echo json_encode(['error' => 'ذخیره فایل ناموفق بود']); exit; }
            $old = $user['avatar'] ?? '';
            if ($old !== '') { $old_path = __DIR__ . '/' . ltrim($old, '/'); if (is_file($old_path)) @unlink($old_path); }
            $avatar_path = 'data/avatars/' . $safe_name;
            $users = read_json(USERS_FILE);
            foreach ($users as &$u) { if ($u['id'] === $user['id']) { $u['avatar'] = $avatar_path; break; } }
            unset($u);
            write_json(USERS_FILE, $users);
            echo json_encode(['success' => true, 'avatar' => $avatar_path]);
            exit;
        case 'remove_user_avatar':
            $old = $user['avatar'] ?? '';
            if ($old !== '') { $old_path = __DIR__ . '/' . ltrim($old, '/'); if (is_file($old_path)) @unlink($old_path); }
            $users = read_json(USERS_FILE);
            foreach ($users as &$u) { if ($u['id'] === $user['id']) { $u['avatar'] = ''; break; } }
            unset($u);
            write_json(USERS_FILE, $users);
            echo json_encode(['success' => true]);
            exit;
        case 'upload_chat_avatar':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat) { echo json_encode(['error' => 'چت یافت نشد']); exit; }
            if (($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            if (in_array($chat['type'] ?? '', ['private', 'saved'])) { echo json_encode(['error' => 'تغییر عکس برای این چت ممکن نیست']); exit; }
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== 0) { echo json_encode(['error' => 'فایلی انتخاب نشده است']); exit; }
            $f = $_FILES['avatar'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) { echo json_encode(['error' => 'فرمت فایل نامعتبر است']); exit; }
            $safe_name = $chat_id . '_' . generate_id() . '.' . $ext;
            $dest = AVATARS_DIR . '/' . $safe_name;
            if (!move_uploaded_file($f['tmp_name'], $dest)) { echo json_encode(['error' => 'ذخیره فایل ناموفق بود']); exit; }
            $old = $chat['avatar_image'] ?? '';
            if ($old !== '') { $old_path = __DIR__ . '/' . ltrim($old, '/'); if (is_file($old_path)) @unlink($old_path); }
            $avatar_path = 'data/avatars/' . $safe_name;
            foreach ($chats as &$c) { if ($c['id'] === $chat_id) { $c['avatar_image'] = $avatar_path; break; } }
            unset($c);
            write_json(CHATS_FILE, $chats);
            echo json_encode(['success' => true, 'avatar' => $avatar_path]);
            exit;
        case 'remove_chat_avatar':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat) { echo json_encode(['error' => 'چت یافت نشد']); exit; }
            if (($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $old = $chat['avatar_image'] ?? '';
            if ($old !== '') { $old_path = __DIR__ . '/' . ltrim($old, '/'); if (is_file($old_path)) @unlink($old_path); }
            foreach ($chats as &$c) { if ($c['id'] === $chat_id) { $c['avatar_image'] = ''; break; } }
            unset($c);
            write_json(CHATS_FILE, $chats);
            echo json_encode(['success' => true]);
            exit;
        case 'create_chat':
            $type = $_POST['type'] ?? 'group';
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $members = $_POST['members'] ?? [];
            if (!in_array($type, ['private', 'group', 'channel'])) { echo json_encode(['error' => 'نوع چت نامعتبر است']); exit; }
            if (is_string($members)) $members = explode(',', $members);
            $member_ids = [];
            foreach ((array)$members as $mid) { $mid = trim((string)$mid); if ($mid !== '' && $mid !== $user['id']) $member_ids[] = $mid; }
            $chats = read_json(CHATS_FILE);
            if ($type === 'private') {
                if (empty($member_ids)) { echo json_encode(['error' => 'یک کاربر انتخاب کنید']); exit; }
                $target_id = $member_ids[0];
                foreach ($chats as $c) { if (($c['type'] ?? '') === 'private' && count($c['members'] ?? []) === 2 && in_array($user['id'], $c['members']) && in_array($target_id, $c['members'])) { echo json_encode(['success' => true, 'chat' => $c]); exit; } }
                $member_ids = [$user['id'], $target_id];
                $name = '';
            } else {
                if ($name === '') { echo json_encode(['error' => 'نام الزامی است']); exit; }
                $member_ids = array_values(array_unique(array_merge([$user['id']], $member_ids)));
            }
            $chat = ['id' => generate_id(), 'type' => $type, 'name' => $name, 'description' => $description, 'owner_id' => $user['id'], 'members' => $member_ids, 'avatar_image' => '', 'verified' => false, 'created_at' => time()];
            $chats[] = $chat;
            write_json(CHATS_FILE, $chats);
            echo json_encode(['success' => true, 'chat' => $chat], JSON_UNESCAPED_UNICODE);
            exit;
        case 'update_chat':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            foreach ($chats as &$c) {
                if (($c['id'] ?? '') === $chat_id) {
                    if (($c['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
                    if (in_array($c['type'] ?? '', ['private', 'saved'])) { echo json_encode(['error' => 'ویرایش این چت ممکن نیست']); exit; }
                    if (isset($_POST['name'])) $c['name'] = trim($_POST['name']);
                    if (isset($_POST['description'])) $c['description'] = trim($_POST['description']);
                    break;
                }
            }
            unset($c);
            write_json(CHATS_FILE, $chats);
            echo json_encode(['success' => true]);
            exit;
        case 'delete_chat':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat) { echo json_encode(['error' => 'چت یافت نشد']); exit; }
            if (($chat['type'] ?? '') === 'saved') { echo json_encode(['error' => 'امکان حذف پیام‌های ذخیره‌شده وجود ندارد']); exit; }
            if (($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $old = $chat['avatar_image'] ?? '';
            if ($old !== '') { $old_path = __DIR__ . '/' . ltrim($old, '/'); if (is_file($old_path)) @unlink($old_path); }
            delete_chat_data($chat_id);
            echo json_encode(['success' => true]);
            exit;
        case 'leave_chat':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat) { echo json_encode(['error' => 'چت یافت نشد']); exit; }
            if (($chat['type'] ?? '') === 'saved') { echo json_encode(['error' => 'امکان خروج از پیام‌های ذخیره‌شده وجود ندارد']); exit; }
            if (($chat['type'] ?? '') === 'private') { delete_chat_data($chat_id); echo json_encode(['success' => true]); exit; }
            if (($chat['owner_id'] ?? '') === $user['id']) { echo json_encode(['error' => 'مالک نمی‌تواند خارج شود. ابتدا چت را حذف کنید']); exit; }
            foreach ($chats as &$c) { if (($c['id'] ?? '') === $chat_id) { $c['members'] = array_values(array_diff($c['members'] ?? [], [$user['id']])); break; } }
            unset($c);
            write_json(CHATS_FILE, $chats);
            echo json_encode(['success' => true]);
            exit;
        case 'get_users':
            $users = read_json(USERS_FILE);
            $list = [];
            foreach ($users as $u) {
                if ($u['id'] === $user['id']) continue;
                if (!empty($u['blocked']) || (isset($u['active']) && !$u['active'])) continue;
                if (isset($u['privacy_searchable']) && !$u['privacy_searchable']) continue;
                $list[] = ['id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'] ?? '', 'avatar' => $u['avatar'] ?? '', 'verified' => !empty($u['verified']), 'premium' => is_premium_user($u), 'premium_color' => $u['premium_color'] ?? ''];
            }
            echo json_encode(['users' => $list], JSON_UNESCAPED_UNICODE);
            exit;
        case 'search_users':
            $q = trim($_GET['q'] ?? '');
            $q = ltrim($q, '@');
            if ($q === '') { echo json_encode(['users' => []]); exit; }
            $users = read_json(USERS_FILE);
            $list = [];
            foreach ($users as $u) {
                if (!empty($u['blocked']) || (isset($u['active']) && !$u['active'])) continue;
                if (isset($u['privacy_searchable']) && !$u['privacy_searchable']) continue;
                if (mb_stripos($u['username'], $q) !== false || mb_stripos($u['name'] ?? '', $q) !== false) {
                    $list[] = ['id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'] ?? '', 'avatar' => $u['avatar'] ?? '', 'verified' => !empty($u['verified']), 'premium' => is_premium_user($u), 'premium_color' => $u['premium_color'] ?? ''];
                }
            }
            echo json_encode(['users' => $list], JSON_UNESCAPED_UNICODE);
            exit;
        case 'get_user_profile':
            $uid = $_GET['user_id'] ?? '';
            $target = find_user_by_id($uid);
            if (!$target) { echo json_encode(['error' => 'کاربر یافت نشد']); exit; }
            echo json_encode(['user' => safe_user($target)], JSON_UNESCAPED_UNICODE);
            exit;
        case 'update_profile':
            $username = trim(ltrim($_POST['username'] ?? '', '@'));
            $name = trim($_POST['name'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $privacy_searchable = isset($_POST['privacy_searchable']) ? !empty($_POST['privacy_searchable']) : true;
            $premium_color = trim($_POST['premium_color'] ?? '');
            $premium_message_sound = !empty($_POST['premium_message_sound']);
            $premium_animated_avatar = !empty($_POST['premium_animated_avatar']);
            $premium_hide_last_seen = !empty($_POST['premium_hide_last_seen']);
            if ($username === '') { echo json_encode(['error' => 'آیدی نمی‌تواند خالی باشد']); exit; }
            if (username_exists($username, $user['id'])) { echo json_encode(['error' => 'این آیدی قبلاً ثبت شده است']); exit; }
            $bio_max = is_premium_user($user) ? PREMIUM_BIO_MAX : NORMAL_BIO_MAX;
            if (mb_strlen($bio) > $bio_max) { echo json_encode(['error' => 'طول بیو نمی‌تواند بیشتر از ' . $bio_max . ' کاراکتر باشد']); exit; }
            $allowed_colors = ['', '#FFD700', '#FF6B6B', '#4ECDC4', '#A78BFA', '#F59E0B', '#EC4899', '#10B981', '#3B82F6'];
            if (!in_array($premium_color, $allowed_colors)) $premium_color = '';
            $users = read_json(USERS_FILE);
            $old_username = null;
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $old_username = $u['username'];
                    $u['username'] = $username;
                    $u['name'] = $name;
                    $u['bio'] = $bio;
                    $u['privacy_searchable'] = $privacy_searchable;
                    if (is_premium_user($u)) {
                        $u['premium_color'] = $premium_color;
                        $u['premium_message_sound'] = $premium_message_sound;
                        $u['premium_animated_avatar'] = $premium_animated_avatar;
                        $u['premium_hide_last_seen'] = $premium_hide_last_seen;
                    }
                    break;
                }
            }
            unset($u);
            write_json(USERS_FILE, $users);
            if ($old_username !== null && $old_username !== $username) update_messages_username($user['id'], $username);
            echo json_encode(['success' => true]);
            exit;
        case 'change_password':
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            if (!password_verify($current_password, $user['password'])) { echo json_encode(['error' => 'رمز عبور فعلی اشتباه است']); exit; }
            if (mb_strlen($new_password) < 6) { echo json_encode(['error' => 'رمز جدید باید حداقل ۶ کاراکتر باشد']); exit; }
            $users = read_json(USERS_FILE);
            foreach ($users as &$u) { if ($u['id'] === $user['id']) { $u['password'] = password_hash($new_password, PASSWORD_DEFAULT); break; } }
            unset($u);
            write_json(USERS_FILE, $users);
            echo json_encode(['success' => true]);
            exit;
        case 'get_wallet_info':
            $wallet_config = get_wallet_config();
            echo json_encode(['success' => true, 'balance' => $user['wallet_balance'] ?? 0, 'spc_to_toman' => $wallet_config['spc_to_toman']], JSON_UNESCAPED_UNICODE);
            exit;
        case 'transfer_spc':
            $to_username = trim(ltrim($_POST['to_username'] ?? '', '@'));
            $amount = (int)($_POST['amount'] ?? 0);
            if ($to_username === '') { echo json_encode(['error' => 'نام کاربری مقصد را وارد کنید']); exit; }
            if ($amount <= 0) { echo json_encode(['error' => 'مقدار باید بیشتر از صفر باشد']); exit; }
            if ($amount > 1000000) { echo json_encode(['error' => 'حداکثر مقدار انتقال 1,000,000 SPC است']); exit; }
            $target = find_user_by_username($to_username);
            if (!$target) { echo json_encode(['error' => 'کاربر مقصد یافت نشد']); exit; }
            if ($target['id'] === $user['id']) { echo json_encode(['error' => 'نمی‌توانید به خودتان انتقال دهید']); exit; }
            if (!empty($target['blocked']) || (isset($target['active']) && !$target['active'])) { echo json_encode(['error' => 'کاربر مقصد غیرفعال است']); exit; }
            $current_balance = $user['wallet_balance'] ?? 0;
            if ($current_balance < $amount) { echo json_encode(['error' => 'موجودی کافی نیست. موجودی شما: ' . $current_balance . ' SPC']); exit; }
            $users = read_json(USERS_FILE);
            $changed = false;
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['wallet_balance'] = ($u['wallet_balance'] ?? 0) - $amount;
                    $changed = true;
                } elseif ($u['id'] === $target['id']) {
                    $u['wallet_balance'] = ($u['wallet_balance'] ?? 0) + $amount;
                    $changed = true;
                }
            }
            unset($u);
            if ($changed) write_json(USERS_FILE, $users);
            add_wallet_history_entry($user['id'], ['type' => 'out', 'amount' => $amount, 'with_username' => $target['username'], 'with_name' => $target['name'] ?? '', 'balance_after' => ($user['wallet_balance'] ?? 0) - $amount, 'note' => 'انتقال SPC']);
            add_wallet_history_entry($target['id'], ['type' => 'in', 'amount' => $amount, 'with_username' => $user['username'], 'with_name' => $user['name'] ?? '', 'balance_after' => ($target['wallet_balance'] ?? 0) + $amount, 'note' => 'دریافت SPC']);
            echo json_encode(['success' => true, 'new_balance' => ($user['wallet_balance'] ?? 0) - $amount, 'message' => 'انتقال ' . $amount . ' SPC به ' . $target['username'] . ' با موفقیت انجام شد'], JSON_UNESCAPED_UNICODE);
            exit;
        case 'admin_get_stats':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $users = read_json(USERS_FILE);
            $chats = read_json(CHATS_FILE);
            $messages = read_json(MESSAGES_FILE);
            $premium_count = count(array_filter($users, fn($u) => is_premium_user($u)));
            $expired_premium_count = count(array_filter($users, fn($u) => !empty($u['premium_until']) && $u['premium_until'] <= time() && empty($u['is_bot'])));
            $total_spc = array_sum(array_map(fn($u) => $u['wallet_balance'] ?? 0, $users));
            echo json_encode(['stats' => [
                'total_users' => count($users),
                'active_users' => count(array_filter($users, fn($u) => (!isset($u['active']) || !empty($u['active'])) && empty($u['blocked']))),
                'blocked_users' => count(array_filter($users, fn($u) => !empty($u['blocked']))),
                'admin_users' => count(array_filter($users, fn($u) => !empty($u['is_admin']))),
                'bot_users' => count(array_filter($users, fn($u) => !empty($u['is_bot']))),
                'searchable_users' => count(array_filter($users, fn($u) => !isset($u['privacy_searchable']) || !empty($u['privacy_searchable']))),
                'premium_users' => $premium_count,
                'expired_premium_users' => $expired_premium_count,
                'total_chats' => count($chats),
                'private_chats' => count(array_filter($chats, fn($c) => ($c['type'] ?? '') === 'private')),
                'group_chats' => count(array_filter($chats, fn($c) => ($c['type'] ?? '') === 'group')),
                'channel_chats' => count(array_filter($chats, fn($c) => ($c['type'] ?? '') === 'channel')),
                'total_messages' => count($messages),
                'today_messages' => count(array_filter($messages, fn($m) => ($m['created_at'] ?? 0) >= strtotime('today'))),
                'upload_size' => dir_size(UPLOADS_DIR),
                'total_spc_in_circulation' => $total_spc,
            ]]);
            exit;
        case 'admin_get_users':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $users = read_json(USERS_FILE);
            $list = array_map('safe_user', $users);
            usort($list, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));
            echo json_encode(['users' => $list], JSON_UNESCAPED_UNICODE);
            exit;
        case 'admin_get_chats':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $chats = read_json(CHATS_FILE);
            $users = read_json(USERS_FILE);
            $messages = read_json(MESSAGES_FILE);
            $list = [];
            foreach ($chats as $chat) {
                $type = $chat['type'] ?? '';
                if ($type === 'private') continue;
                $owner = find_user_by_id($chat['owner_id'] ?? '');
                $chat_msgs = array_filter($messages, fn($m) => ($m['chat_id'] ?? '') === $chat['id']);
                usort($chat_msgs, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));
                $last = $chat_msgs[0] ?? null;
                $list[] = [
                    'id' => $chat['id'],
                    'type' => $type,
                    'name' => $chat['name'] ?? '',
                    'description' => $chat['description'] ?? '',
                    'owner_id' => $chat['owner_id'] ?? '',
                    'owner_name' => $owner ? ($owner['name'] ?: $owner['username']) : 'نامشخص',
                    'owner_username' => $owner ? $owner['username'] : '',
                    'owner_premium' => $owner ? is_premium_user($owner) : false,
                    'members_count' => count($chat['members'] ?? []),
                    'avatar_image' => $chat['avatar_image'] ?? '',
                    'verified' => !empty($chat['verified']),
                    'created_at' => $chat['created_at'] ?? time(),
                    'last_time' => $last ? ($last['created_at'] ?? time()) : ($chat['created_at'] ?? time()),
                    'last_message' => $last ? mb_substr(message_preview($last), 0, 70) : '',
                    'messages_count' => count($chat_msgs),
                ];
            }
            usort($list, fn($a, $b) => ($b['last_time'] ?? 0) - ($a['last_time'] ?? 0));
            echo json_encode(['chats' => $list], JSON_UNESCAPED_UNICODE);
            exit;
        case 'admin_toggle_chat_verified':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            $new_value = false;
            foreach ($chats as &$c) {
                if (($c['id'] ?? '') === $chat_id) {
                    $c['verified'] = empty($c['verified']);
                    $new_value = !empty($c['verified']);
                    break;
                }
            }
            unset($c);
            write_json(CHATS_FILE, $chats);
            echo json_encode(['success' => true, 'verified' => $new_value]);
            exit;
        case 'admin_delete_chat':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat) { echo json_encode(['error' => 'چت یافت نشد']); exit; }
            if (($chat['type'] ?? '') === 'saved') { echo json_encode(['error' => 'امکان حذف پیام‌های ذخیره‌شده وجود ندارد']); exit; }
            $old = $chat['avatar_image'] ?? '';
            if ($old !== '') { $old_path = __DIR__ . '/' . ltrim($old, '/'); if (is_file($old_path)) @unlink($old_path); }
            delete_chat_data($chat_id);
            echo json_encode(['success' => true]);
            exit;
        case 'admin_get_bot':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $users = read_json(USERS_FILE);
            $bot = null;
            foreach ($users as $u) { if (!empty($u['is_bot'])) { $bot = $u; break; } }
            if (!$bot) { echo json_encode(['error' => 'ربات سیستمی یافت نشد']); exit; }
            echo json_encode(['bot' => safe_user($bot)], JSON_UNESCAPED_UNICODE);
            exit;
        case 'admin_update_bot':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $username = trim(ltrim($_POST['username'] ?? '', '@'));
            $name = trim($_POST['name'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            if ($username === '') { echo json_encode(['error' => 'آیدی نمی‌تواند خالی باشد']); exit; }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { echo json_encode(['error' => 'آیدی فقط می‌تواند شامل حروف انگلیسی، عدد و _ باشد']); exit; }
            $users = read_json(USERS_FILE);
            $bot = null;
            $bot_id = null;
            foreach ($users as $u) { if (!empty($u['is_bot'])) { $bot = $u; $bot_id = $u['id']; break; } }
            if (!$bot) { echo json_encode(['error' => 'ربات سیستمی یافت نشد']); exit; }
            if (username_exists($username, $bot_id)) { echo json_encode(['error' => 'این آیدی قبلاً ثبت شده است']); exit; }
            $old_username = $bot['username'];
            foreach ($users as &$u) {
                if ($u['id'] === $bot_id) {
                    $u['username'] = $username;
                    $u['name'] = $name;
                    $u['bio'] = $bio;
                    break;
                }
            }
            unset($u);
            write_json(USERS_FILE, $users);
            if ($old_username !== $username) update_messages_username($bot_id, $username);
            echo json_encode(['success' => true]);
            exit;
        case 'admin_upload_bot_avatar':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== 0) { echo json_encode(['error' => 'فایلی انتخاب نشده است']); exit; }
            $users = read_json(USERS_FILE);
            $bot = null;
            $bot_id = null;
            foreach ($users as $u) { if (!empty($u['is_bot'])) { $bot = $u; $bot_id = $u['id']; break; } }
            if (!$bot) { echo json_encode(['error' => 'ربات سیستمی یافت نشد']); exit; }
            $f = $_FILES['avatar'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) { echo json_encode(['error' => 'فرمت فایل نامعتبر است']); exit; }
            $safe_name = $bot_id . '_' . generate_id() . '.' . $ext;
            $dest = AVATARS_DIR . '/' . $safe_name;
            if (!move_uploaded_file($f['tmp_name'], $dest)) { echo json_encode(['error' => 'ذخیره فایل ناموفق بود']); exit; }
            $old = $bot['avatar'] ?? '';
            if ($old !== '') { $old_path = __DIR__ . '/' . ltrim($old, '/'); if (is_file($old_path)) @unlink($old_path); }
            $avatar_path = 'data/avatars/' . $safe_name;
            foreach ($users as &$u) { if ($u['id'] === $bot_id) { $u['avatar'] = $avatar_path; break; } }
            unset($u);
            write_json(USERS_FILE, $users);
            echo json_encode(['success' => true, 'avatar' => $avatar_path]);
            exit;
        case 'admin_remove_bot_avatar':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $users = read_json(USERS_FILE);
            $bot_id = null;
            foreach ($users as $u) { if (!empty($u['is_bot'])) { $bot_id = $u['id']; break; } }
            if (!$bot_id) { echo json_encode(['error' => 'ربات سیستمی یافت نشد']); exit; }
            $old = $bot['avatar'] ?? '';
            foreach ($users as $u) { if ($u['id'] === $bot_id) { $old = $u['avatar'] ?? ''; break; } }
            if ($old !== '') { $old_path = __DIR__ . '/' . ltrim($old, '/'); if (is_file($old_path)) @unlink($old_path); }
            foreach ($users as &$u) { if ($u['id'] === $bot_id) { $u['avatar'] = ''; break; } }
            unset($u);
            write_json(USERS_FILE, $users);
            echo json_encode(['success' => true]);
            exit;
        case 'admin_set_exchange_rate':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $rate = (int)($_POST['rate'] ?? 0);
            if ($rate < 1 || $rate > 100000000) { echo json_encode(['error' => 'نرخ تبدیل نامعتبر است (1 تا 100,000,000)']); exit; }
            $config = get_wallet_config();
            $config['spc_to_toman'] = $rate;
            write_json(WALLET_CONFIG_FILE, $config);
            echo json_encode(['success' => true, 'rate' => $rate]);
            exit;
        case 'admin_update_wallet':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $target_id = $_POST['user_id'] ?? '';
            $action_type = $_POST['wallet_action'] ?? '';
            $amount = (int)($_POST['amount'] ?? 0);
            $target = find_user_by_id($target_id);
            if (!$target) { echo json_encode(['error' => 'کاربر یافت نشد']); exit; }
            if ($amount < 0) { echo json_encode(['error' => 'مقدار نمی‌تواند منفی باشد']); exit; }
            $users = read_json(USERS_FILE);
            $new_balance = 0;
            foreach ($users as &$u) {
                if ($u['id'] === $target_id) {
                    $current = $u['wallet_balance'] ?? 0;
                    if ($action_type === 'add') {
                        $u['wallet_balance'] = $current + $amount;
                    } elseif ($action_type === 'subtract') {
                        $u['wallet_balance'] = max(0, $current - $amount);
                    } elseif ($action_type === 'set') {
                        $u['wallet_balance'] = $amount;
                    }
                    $new_balance = $u['wallet_balance'];
                    break;
                }
            }
            unset($u);
            write_json(USERS_FILE, $users);
            if ($amount > 0 || $action_type === 'set') {
                $htype = ($action_type === 'subtract') ? 'out' : 'in';
                add_wallet_history_entry($target_id, ['type' => $htype, 'amount' => $amount, 'with_username' => $user['username'], 'with_name' => $user['name'] ?? '', 'balance_after' => $new_balance, 'note' => 'تنظیم توسط مدیر (' . $action_type . ')']);
            }
            echo json_encode(['success' => true, 'new_balance' => $new_balance]);
            exit;
        case 'get_wallet_history':
            $history = read_json(WALLET_HISTORY_FILE);
            $wallet_config = get_wallet_config();
            echo json_encode(['success' => true, 'history' => $history[$user['id']] ?? [], 'balance' => $user['wallet_balance'] ?? 0, 'spc_to_toman' => $wallet_config['spc_to_toman']], JSON_UNESCAPED_UNICODE);
            exit;
        case 'admin_create_user':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $username = trim(ltrim($_POST['username'] ?? '', '@'));
            $password = $_POST['password'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $is_admin = !empty($_POST['is_admin']);
            $active = !empty($_POST['active']);
            $privacy_searchable = isset($_POST['privacy_searchable']) ? !empty($_POST['privacy_searchable']) : true;
            $verified = !empty($_POST['verified']);
            $premium_days = isset($_POST['premium_days']) ? (int)$_POST['premium_days'] : 0;
            $initial_wallet = isset($_POST['initial_wallet']) ? (int)$_POST['initial_wallet'] : 0;
            if ($username === '') { echo json_encode(['error' => 'آیدی الزامی است']); exit; }
            if (mb_strlen($password) < 6) { echo json_encode(['error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد']); exit; }
            if (username_exists($username)) { echo json_encode(['error' => 'این آیدی قبلاً ثبت شده است']); exit; }
            if ($initial_wallet < 0) { echo json_encode(['error' => 'موجودی اولیه نمی‌تواند منفی باشد']); exit; }
            $users = read_json(USERS_FILE);
            $premium_until = $premium_days > 0 ? time() + ($premium_days * 86400) : 0;
            $new_user = ['id' => generate_id(), 'username' => $username, 'password' => password_hash($password, PASSWORD_DEFAULT), 'is_admin' => $is_admin, 'active' => $active, 'blocked' => false, 'is_bot' => false, 'name' => $name, 'bio' => $bio, 'avatar' => '', 'privacy_searchable' => $privacy_searchable, 'created_at' => time(), 'last_activity' => time(), 'verified' => $verified, 'premium_until' => $premium_until, 'premium_color' => '', 'premium_message_sound' => false, 'premium_animated_avatar' => false, 'premium_hide_last_seen' => false, 'wallet_balance' => $initial_wallet];
            $users[] = $new_user;
            write_json(USERS_FILE, $users);
            echo json_encode(['success' => true, 'user' => safe_user($new_user)], JSON_UNESCAPED_UNICODE);
            exit;
        case 'admin_update_user':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $target_id = $_POST['user_id'] ?? '';
            $target = find_user_by_id($target_id);
            if (!$target) { echo json_encode(['error' => 'کاربر یافت نشد']); exit; }
            if (!empty($target['is_bot'])) { echo json_encode(['error' => 'برای ویرایش ربات، از بخش ربات خوش‌آمدگویی استفاده کنید']); exit; }
            $username = trim(ltrim($_POST['username'] ?? '', '@'));
            if ($username === '') { echo json_encode(['error' => 'آیدی نمی‌تواند خالی باشد']); exit; }
            if (username_exists($username, $target_id)) { echo json_encode(['error' => 'این آیدی قبلاً ثبت شده است']); exit; }
            $password = $_POST['password'] ?? '';
            if ($password !== '' && mb_strlen($password) < 6) { echo json_encode(['error' => 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد']); exit; }
            $premium_action = $_POST['premium_action'] ?? 'keep';
            $premium_days = isset($_POST['premium_days']) ? (int)$_POST['premium_days'] : 0;
            $premium_until = $target['premium_until'] ?? 0;
            if ($premium_action === 'add_days') {
                if ($premium_days < 0 || $premium_days > 3650) { echo json_encode(['error' => 'تعداد روزهای پرمیوم نامعتبر است (0 تا 3650)']); exit; }
                $base_time = $premium_until > time() ? $premium_until : time();
                $premium_until = $base_time + ($premium_days * 86400);
            } elseif ($premium_action === 'set_days') {
                if ($premium_days < 0 || $premium_days > 3650) { echo json_encode(['error' => 'تعداد روزهای پرمیوم نامعتبر است (0 تا 3650)']); exit; }
                $premium_until = $premium_days > 0 ? time() + ($premium_days * 86400) : 0;
            } elseif ($premium_action === 'remove') {
                $premium_until = 0;
            }
            $users = read_json(USERS_FILE);
            $is_self = $target_id === $user['id'];
            if (!$is_self) {
                $new_admin = !empty($_POST['is_admin']);
                if (!empty($target['is_admin']) && !$new_admin) {
                    $other_admins = count(array_filter($users, function($u) use ($target_id) { return $u['id'] !== $target_id && !empty($u['is_admin']) && empty($u['blocked']) && (!isset($u['active']) || !empty($u['active'])); }));
                    if ($other_admins === 0) { echo json_encode(['error' => 'نمی‌توان آخرین ادمین را غیرادمین کرد']); exit; }
                }
            }
            $old_username = $target['username'];
            foreach ($users as &$u) {
                if ($u['id'] === $target_id) {
                    $u['username'] = $username;
                    $u['name'] = trim($_POST['name'] ?? '');
                    $u['bio'] = trim($_POST['bio'] ?? '');
                    if (isset($_POST['privacy_searchable'])) $u['privacy_searchable'] = !empty($_POST['privacy_searchable']);
                    if ($password !== '') $u['password'] = password_hash($password, PASSWORD_DEFAULT);
                    if (!$is_self) { $u['is_admin'] = !empty($_POST['is_admin']); $u['active'] = !empty($_POST['active']); $u['blocked'] = !empty($_POST['blocked']); }
                    $u['verified'] = isset($_POST['verified']) ? !empty($_POST['verified']) : ($u['verified'] ?? false);
                    $u['premium_until'] = $premium_until;
                    break;
                }
            }
            unset($u);
            write_json(USERS_FILE, $users);
            if ($old_username !== $username) update_messages_username($target_id, $username);
            echo json_encode(['success' => true]);
            exit;
        case 'admin_delete_user':
            if (!is_admin_user($user)) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $target_id = $_POST['user_id'] ?? '';
            if ($target_id === $user['id']) { echo json_encode(['error' => 'نمی‌توانید حساب خودتان را حذف کنید']); exit; }
            $target = find_user_by_id($target_id);
            if (!$target) { echo json_encode(['error' => 'کاربر یافت نشد']); exit; }
            if (!empty($target['is_bot'])) { echo json_encode(['error' => 'نمی‌توان ربات سیستم را حذف کرد']); exit; }
            if (!empty($target['is_admin'])) {
                $users = read_json(USERS_FILE);
                $other_admins = count(array_filter($users, function($u) use ($target_id) { return $u['id'] !== $target_id && !empty($u['is_admin']) && empty($u['blocked']) && (!isset($u['active']) || !empty($u['active'])); }));
                if ($other_admins === 0) { echo json_encode(['error' => 'نمی‌توان آخرین ادمین را حذف کرد']); exit; }
            }
            $old = $target['avatar'] ?? '';
            if ($old !== '') { $old_path = __DIR__ . '/' . ltrim($old, '/'); if (is_file($old_path)) @unlink($old_path); }
            $chats = read_json(CHATS_FILE);
            $keep_chats = [];
            $delete_chat_ids = [];
            foreach ($chats as $c) {
                $cid = $c['id'] ?? '';
                $type = $c['type'] ?? '';
                $members = $c['members'] ?? [];
                if (($c['owner_id'] ?? '') === $target_id && $type !== 'private') { $delete_chat_ids[] = $cid; continue; }
                if ($type === 'private' && in_array($target_id, $members)) { $delete_chat_ids[] = $cid; continue; }
                if (in_array($target_id, $members)) $c['members'] = array_values(array_diff($members, [$target_id]));
                $keep_chats[] = $c;
            }
            write_json(CHATS_FILE, $keep_chats);
            $messages = read_json(MESSAGES_FILE);
            $keep_messages = [];
            foreach ($messages as $m) {
                if (($m['user_id'] ?? '') === $target_id || in_array($m['chat_id'] ?? '', $delete_chat_ids)) { unlink_message_file($m); continue; }
                $keep_messages[] = $m;
            }
            write_json(MESSAGES_FILE, $keep_messages);
            $users = read_json(USERS_FILE);
            $new_users = array_values(array_filter($users, fn($u) => $u['id'] !== $target_id));
            write_json(USERS_FILE, $new_users);
            echo json_encode(['success' => true]);
            exit;
        case 'get_chat_members':
            $chat_id = $_GET['chat_id'] ?? '';
            if (!$chat_id) { echo json_encode(['error' => 'چت نامعتبر است']); exit; }
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) { if ($c['id'] === $chat_id) { $chat = $c; break; } }
            if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) { echo json_encode(['error' => 'دسترسی غیرمجاز است']); exit; }
            $members = [];
            foreach ($chat['members'] as $mid) {
                $u = find_user_by_id($mid);
                if (!$u) continue;
                $status = get_online_status($u);
                $members[] = ['id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'] ?? '', 'avatar' => $u['avatar'] ?? '', 'online' => $status['online'], 'last_seen_text' => $status['text'], 'is_owner' => ($chat['owner_id'] ?? '') === $u['id'], 'is_admin' => !empty($u['is_admin']), 'is_bot' => !empty($u['is_bot']), 'verified' => !empty($u['verified']), 'premium' => is_premium_user($u), 'premium_color' => $u['premium_color'] ?? ''];
            }
            echo json_encode(['members' => $members], JSON_UNESCAPED_UNICODE);
            exit;
    }
    echo json_encode(['error' => 'درخواست نامعتبر است']);
    exit;
}
$safe_user = safe_user($user);
$LOGO_URL = 'https://abrehamrahi.ir/o/public/BptxmSLT/';
$ME_AVATAR_URL = !empty($user['avatar']) ? '?action=serve_file&p=' . urlencode($user['avatar']) : '';
$wallet_config = get_wallet_config();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>پیام‌رسان</title>
<link rel="icon" href="<?= htmlspecialchars($LOGO_URL) ?>">
<style>
@import url('https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css');
:root{--bg:#060b11;--panel:rgba(15,24,34,.78);--panel-solid:#0e1822;--card:#131f2c;--card-2:#182836;--input:#1a2a3a;--hover:#20344a;--msg-me:#14544c;--msg-me-2:#0f453f;--msg-other:#16242f;--t1:#eef6f6;--t2:#8aa2b2;--accent:#3ddbc4;--accent-2:#17b09b;--on-accent:#03251f;--border:rgba(255,255,255,.08);--border-strong:rgba(255,255,255,.14);--danger:#ff6b6b;--vip-gold:#FFD700;--vip-gold-2:#FFA500;--spc-green:#10B981;--spc-gold:#F59E0B;--shadow:0 20px 50px rgba(0,0,0,.5)}
[data-theme="light"]{--bg:#e8eef0;--panel:rgba(255,255,255,.84);--panel-solid:#fff;--card:#fff;--card-2:#f3f8f7;--input:#edf3f2;--hover:#e0ebe9;--msg-me:#cdeee7;--msg-me-2:#bfe8e0;--msg-other:#fff;--t1:#122530;--t2:#5d7684;--accent:#0aa892;--accent-2:#078e7b;--on-accent:#fff;--border:rgba(10,40,50,.09);--border-strong:rgba(10,40,50,.16);--danger:#e94e4e;--vip-gold:#D4AF37;--vip-gold-2:#B8860B;--spc-green:#059669;--spc-gold:#D97706;--shadow:0 20px 50px rgba(20,50,60,.15)}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;font-family:'Vazirmatn',Tahoma,sans-serif}
html,body{height:100%;overflow:hidden}
body{background:var(--bg);color:var(--t1);transition:background .35s,color .35s}
.bg-scene{position:fixed;inset:0;z-index:0;overflow:hidden;pointer-events:none}
.blob{position:absolute;border-radius:50%;filter:blur(90px);opacity:.45;animation:blobMove 16s ease-in-out infinite alternate}
.blob-1{width:520px;height:520px;top:-180px;right:-120px;background:radial-gradient(circle,rgba(61,219,196,.32),transparent 70%)}
.blob-2{width:460px;height:460px;bottom:-160px;left:-100px;background:radial-gradient(circle,rgba(167,139,250,.22),transparent 70%);animation-delay:-5s}
@keyframes blobMove{from{transform:translate(0,0) scale(1)}to{transform:translate(-45px,35px) scale(1.1)}}
.app{display:flex;gap:14px;height:100dvh;width:100%;padding:14px;position:relative;z-index:1}
.sidebar{width:370px;background:var(--panel);backdrop-filter:blur(24px);border:1px solid var(--border);border-radius:26px;display:flex;flex-direction:column;flex-shrink:0;box-shadow:var(--shadow);overflow:hidden;position:relative;animation:fadeSlide .4s ease}
.sidebar-header{padding:16px 18px 12px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.brand{display:flex;align-items:center;gap:12px;flex:1;min-width:0}
.brand-logo{width:44px;height:44px;border-radius:15px;padding:5px;object-fit:cover;background:linear-gradient(145deg,#62f7df,#0eb9a2);box-shadow:0 8px 24px rgba(61,219,196,.25);animation:logoFloat 3.5s ease-in-out infinite}
@keyframes logoFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}
.brand-title{font-size:17px;font-weight:800;line-height:1.25}
.brand-title small{display:block;font-size:10.5px;font-weight:500;color:var(--t2)}
.header-actions{display:flex;gap:2px}
.icon-btn{background:transparent;border:none;color:var(--t2);cursor:pointer;padding:8px;border-radius:13px;font-size:18px;transition:.2s;display:flex;align-items:center;justify-content:center;width:38px;height:38px;flex-shrink:0}
.icon-btn:hover{background:var(--hover);transform:translateY(-1px)}
.me-card{display:flex;align-items:center;gap:12px;margin:4px 14px 10px;padding:11px 14px;background:linear-gradient(135deg,rgba(61,219,196,.10),rgba(255,255,255,.03));border:1px solid var(--border);border-radius:18px;cursor:pointer;transition:.22s}
.me-card:hover{transform:translateY(-1px);border-color:rgba(61,219,196,.35)}
.me-card.premium{background:linear-gradient(135deg,rgba(255,215,0,.15),rgba(255,165,0,.05));border-color:rgba(255,215,0,.35)}
.avatar{border-radius:50%;background:linear-gradient(145deg,#62f7df,#0eb9a2);display:flex;align-items:center;justify-content:center;color:var(--on-accent);font-weight:800;flex-shrink:0;overflow:hidden}
.avatar img{width:100%;height:100%;object-fit:cover;display:block}
.me-card .avatar{width:44px;height:44px;font-size:17px}
.me-info{flex:1;min-width:0}
.me-name{font-weight:800;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:5px}
.me-username{font-size:12px;color:var(--accent);direction:ltr;text-align:right}
.search-box{padding:0 14px 12px}
.search-box input{width:100%;padding:12px 16px;border-radius:16px;border:1.5px solid var(--border);background:var(--input);color:var(--t1);outline:none;font-size:13.5px;transition:.22s}
.search-box input:focus{border-color:rgba(61,219,196,.55);box-shadow:0 0 0 3px rgba(61,219,196,.12)}
.chat-list{flex:1;overflow-y:auto;overflow-x:hidden;padding:2px 10px 96px;display:flex;flex-direction:column;gap:5px}
.chat-item{padding:10px 12px;display:flex;gap:12px;cursor:pointer;transition:all .18s ease;border-radius:18px;align-items:center;border:1px solid transparent;animation:fadeUp .25s ease}
.chat-item:hover{background:var(--hover);transform:translateX(-2px)}
.chat-item.active{background:rgba(61,219,196,.11);border-color:rgba(61,219,196,.32)}
.chat-avatar{width:48px;height:48px;border-radius:17px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:18px;flex-shrink:0;overflow:hidden;background:linear-gradient(145deg,#7cc0ff,#2f6fd0);transition:transform .2s}
.chat-item:hover .chat-avatar{transform:scale(1.05) rotate(-2deg)}
.chat-avatar img{width:100%;height:100%;object-fit:cover}
.chat-avatar.group{background:linear-gradient(145deg,#ff9aa8,#e11d48)}
.chat-avatar.channel{background:linear-gradient(145deg,#7defdd,#0d9c88)}
.chat-avatar.saved{background:linear-gradient(145deg,#5b9bd5,#3b6fb0)}
.chat-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
.chat-top{display:flex;justify-content:space-between;align-items:center;gap:8px}
.chat-name{font-weight:800;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:6px}
.chat-time{font-size:10.5px;color:var(--t2);flex-shrink:0}
.chat-preview-wrap{display:flex;justify-content:space-between;align-items:center;gap:8px}
.chat-preview{font-size:12.5px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;display:flex;align-items:center;gap:5px}
.unread-badge{min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:linear-gradient(145deg,#62f7df,#0eb9a2);color:#04302a;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
.ticks{font-size:11px;opacity:.8}
.ticks.seen{color:var(--accent);opacity:1}
.fab-container{position:absolute;bottom:22px;left:22px;z-index:50}
.fab-btn{width:60px;height:60px;border-radius:21px;background:linear-gradient(145deg,#62f7df,#0eb9a2);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:25px;box-shadow:0 10px 30px rgba(61,219,196,.35);transition:.25s}
.fab-btn:hover{transform:translateY(-3px) scale(1.04)}
.fab-menu{position:absolute;bottom:72px;left:4px;display:flex;flex-direction:column;gap:10px;pointer-events:none;opacity:0;transform:translateY(14px);transition:.28s ease}
.fab-container.open .fab-menu{opacity:1;transform:translateY(0);pointer-events:all}
.fab-menu-item{display:flex;align-items:center;gap:12px;padding:9px 18px 9px 22px;background:var(--panel);border:1px solid var(--border-strong);border-radius:19px;cursor:pointer;white-space:nowrap;box-shadow:0 10px 28px rgba(0,0,0,.35);animation:popIn .25s ease}
.fab-mi-icon{width:42px;height:42px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:21px;background:var(--input)}
.fab-mi-text b{display:block;font-size:13.5px}
.fab-mi-text span{font-size:11px;color:var(--t2)}
.chat-area{flex:1;display:flex;flex-direction:column;background:var(--panel);backdrop-filter:blur(24px);border:1px solid var(--border);border-radius:26px;min-width:0;box-shadow:var(--shadow);overflow:hidden;animation:fadeSlide .45s ease}
.chat-header{padding:12px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;min-height:72px;background:linear-gradient(180deg,rgba(255,255,255,.03),transparent)}
.chat-header .chat-avatar{width:46px;height:46px;border-radius:15px;cursor:pointer}
.chat-header-info{flex:1;min-width:0;cursor:pointer}
.chat-header-name{font-weight:800;font-size:15.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:7px}
.chat-header-status{font-size:12px;color:var(--t2)}
.empty-state{flex:1;display:flex;align-items:center;justify-content:center;color:var(--t2);font-size:15px;flex-direction:column;gap:18px;padding:24px;text-align:center;animation:fadeIn .5s ease}
.empty-logo{width:112px;height:112px;border-radius:36px;padding:20px;background:linear-gradient(145deg,#62f7df,#0eb9a2);display:flex;align-items:center;justify-content:center;box-shadow:0 10px 30px rgba(61,219,196,.25);animation:logoFloat 3.5s ease-in-out infinite}
.empty-logo img{width:100%;height:100%;object-fit:contain}
.empty-chips{display:flex;gap:9px;flex-wrap:wrap;justify-content:center}
.empty-chip{display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border-radius:22px;background:var(--card);border:1px solid var(--border);font-size:12px;color:var(--t2);cursor:pointer;transition:.2s}
.empty-chip:hover{transform:translateY(-2px);border-color:rgba(61,219,196,.4)}
.messages{flex:1;overflow-y:auto;overflow-x:hidden;padding:18px 16px;display:flex;flex-direction:column;gap:9px}
.message{max-width:72%;padding:10px 14px;border-radius:19px;position:relative;word-wrap:break-word;animation:messageSlideIn .35s cubic-bezier(.34,1.56,.64,1)}
.message.me{background:linear-gradient(135deg,var(--msg-me),var(--msg-me-2));align-self:flex-end;border-bottom-right-radius:7px}
.message.other{background:var(--msg-other);align-self:flex-start;border-bottom-left-radius:7px;border:1px solid var(--border)}
.msg-sender{font-size:12px;font-weight:800;color:var(--accent);margin-bottom:4px;cursor:pointer;display:flex;align-items:center;gap:5px}
.saved-from{font-size:10.5px;color:var(--t2);margin-bottom:5px}
.message-text{font-size:14.5px;line-height:1.7;white-space:pre-wrap}
.message-text a{color:#7defdd}
.message-file{margin-bottom:6px}
.message-file img,.message-file video{max-width:100%;max-height:320px;border-radius:13px;display:block}
.file-chip{display:inline-flex;align-items:center;gap:9px;background:rgba(61,219,196,.12);border:1px solid rgba(61,219,196,.25);padding:9px 14px;border-radius:13px;color:var(--t1);text-decoration:none;font-weight:700;font-size:13px;transition:.2s}
.file-chip:hover{transform:translateY(-1px);background:rgba(61,219,196,.18)}
.message-meta{font-size:10px;margin-top:5px;display:flex;gap:6px;align-items:center;justify-content:flex-end;direction:ltr;color:var(--t2)}
.message.me .message-meta{color:rgba(255,255,255,.65)}
.message-actions-inline{display:flex;gap:4px;margin-top:6px;opacity:.85;justify-content:flex-end;flex-wrap:wrap}
.message.other .message-actions-inline{justify-content:flex-start}
.msg-action-inline{background:transparent;border:none;color:inherit;font-size:11px;cursor:pointer;padding:4px 9px;border-radius:9px;display:inline-flex;align-items:center;gap:5px;font-weight:700;transition:.18s}
.msg-action-inline:hover{background:rgba(255,255,255,.14);transform:translateY(-1px)}
.msg-action-inline.danger{color:var(--danger)}
.message-input-wrap{padding:11px 16px;border-top:1px solid var(--border)}
.message-input{display:flex;align-items:flex-end;gap:7px;position:relative}
.round-btn{width:44px;height:44px;border-radius:15px;border:none;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:21px;color:var(--t1);transition:.2s}
.round-btn:hover{background:var(--hover);transform:translateY(-1px)}
.message-input textarea{flex:1;padding:12px 18px;border-radius:23px;border:1.5px solid var(--border);background:var(--input);color:var(--t1);outline:none;font-size:14px;resize:none;max-height:120px;min-height:44px;line-height:1.5;transition:.2s}
.message-input textarea:focus{border-color:rgba(61,219,196,.55);box-shadow:0 0 0 3px rgba(61,219,196,.12)}
.send-btn{background:linear-gradient(145deg,#62f7df,#0eb9a2);border:none;width:44px;height:44px;border-radius:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:19px;color:#04302a;transition:.22s}
.send-btn:hover{transform:translateY(-2px) scale(1.06);border-radius:50%}
.attach-popup{position:absolute;bottom:58px;right:0;background:var(--panel-solid);border:1px solid var(--border-strong);border-radius:19px;padding:8px;box-shadow:var(--shadow);display:none;flex-direction:column;gap:4px;min-width:210px;z-index:30;animation:popIn .22s ease}
.attach-popup.active{display:flex}
.attach-popup-item{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:14px;cursor:pointer;font-size:13.5px;color:var(--t1);border:none;background:transparent;width:100%;text-align:right;font-weight:700;transition:.18s}
.attach-popup-item:hover{background:var(--hover);transform:translateX(-3px)}
.attach-popup-item .ico{width:39px;height:39px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:19px;background:var(--input)}
.modal-overlay{position:fixed;inset:0;background:rgba(2,6,10,.72);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}
.modal-overlay.active{display:flex}
.modal{background:var(--panel-solid);border-radius:26px;padding:28px;max-width:470px;width:100%;max-height:90vh;overflow-y:auto;border:1px solid var(--border-strong);box-shadow:var(--shadow);animation:modalIn .3s cubic-bezier(.34,1.3,.64,1)}
.modal.large{max-width:1080px}
@keyframes modalIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-head{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.modal-head-icon{width:46px;height:46px;border-radius:15px;background:var(--input);display:flex;align-items:center;justify-content:center;font-size:23px}
.modal-head h3{font-size:17px;font-weight:800;flex:1}
.modal-field{margin-bottom:16px}
.modal-field label{display:block;margin-bottom:7px;font-size:12.5px;color:var(--t2);font-weight:700}
.modal-field input,.modal-field textarea,.modal-field select{width:100%;padding:12px 16px;border-radius:14px;border:1.5px solid var(--border);background:var(--input);color:var(--t1);outline:none;font-size:14px;transition:.2s}
.modal-field input:focus,.modal-field textarea:focus{border-color:rgba(61,219,196,.55);box-shadow:0 0 0 3px rgba(61,219,196,.12)}
.modal-field textarea{min-height:84px;resize:vertical}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:24px;flex-wrap:wrap}
.btn{padding:12px 24px;border:none;border-radius:14px;cursor:pointer;font-size:14px;font-weight:800;display:inline-flex;align-items:center;gap:8px;justify-content:center;transition:.22s}
.btn-primary{background:linear-gradient(145deg,#62f7df,#0eb9a2);color:#04302a}
.btn-primary:hover{transform:translateY(-2px)}
.btn-secondary{background:var(--input);color:var(--t1);border:1px solid var(--border)}
.btn-secondary:hover{background:var(--hover)}
.btn-danger{background:linear-gradient(145deg,#ff8a9b,#e11d48);color:#fff}
.btn-danger:hover{transform:translateY(-2px)}
.btn-vip{background:linear-gradient(145deg,#FFD700,#FFA500);color:#4a2c00}
.btn-vip:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(255,215,0,.4)}
.btn-spc{background:linear-gradient(145deg,#10B981,#059669);color:#fff}
.btn-spc:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(16,185,129,.4)}
.btn-block{width:100%}
.small-note{font-size:11px;color:var(--t2);margin-top:6px;line-height:1.7}
.users-list{max-height:235px;overflow-y:auto;border:1.5px solid var(--border);border-radius:15px;padding:6px;background:var(--input);display:flex;flex-direction:column;gap:3px}
.user-option{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:12px;cursor:pointer;border:1.5px solid transparent;transition:.18s}
.user-option:hover{background:var(--hover)}
.user-option input{width:auto;margin:0;accent-color:var(--accent)}
.user-option-avatar{width:37px;height:37px;border-radius:12px;background:linear-gradient(145deg,#62f7df,#0eb9a2);color:#04302a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;overflow:hidden}
.user-option-avatar img{width:100%;height:100%;object-fit:cover}
.user-option-info{flex:1;min-width:0}
.user-option-name{font-size:13.5px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:5px}
.user-option-username{font-size:11.5px;color:var(--t2);direction:ltr;text-align:right}
.selected-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}
.member-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(61,219,196,.14);border:1px solid rgba(61,219,196,.3);color:var(--accent);padding:5px 11px;border-radius:20px;font-size:12px;font-weight:800;animation:popIn .2s ease}
.member-chip button{background:none;border:none;color:inherit;cursor:pointer;padding:0;opacity:.8}
.menu-rows{display:flex;flex-direction:column;gap:7px}
.menu-row{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:16px;background:var(--input);border:1px solid var(--border);cursor:pointer;font-size:13.5px;font-weight:800;color:var(--t1);width:100%;text-align:right;transition:.2s}
.menu-row:hover{background:var(--hover);transform:translateX(-3px)}
.menu-row.danger{color:var(--danger)}
.avatar-upload-box{display:flex;flex-direction:column;align-items:center;gap:8px;margin-bottom:18px}
.avatar-big{width:90px;height:90px;border-radius:28px;background:linear-gradient(145deg,#62f7df,#0eb9a2);color:#04302a;display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:800;overflow:hidden;cursor:pointer;position:relative;transition:.25s}
.avatar-big:hover{transform:scale(1.05) rotate(-2deg)}
.avatar-big img{width:100%;height:100%;object-fit:cover}
.avatar-big.premium-glow{box-shadow:0 0 20px rgba(255,215,0,.5);border:2px solid rgba(255,215,0,.4)}
.avatar-upload-overlay{position:absolute;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;opacity:0;transition:.2s;color:#fff;pointer-events:none;font-size:27px}
.avatar-big:hover .avatar-upload-overlay,.profile-avatar:hover .avatar-upload-overlay{opacity:1}
.avatar-actions{display:flex;gap:6px;margin-top:8px}
.section-title{font-size:14px;font-weight:800;margin:22px 0 12px;display:flex;align-items:center;gap:9px}
.section-title::after{content:'';flex:1;height:1px;background:var(--border)}
.section-title.vip{color:var(--vip-gold)}
.section-title.vip::after{background:linear-gradient(to left,transparent,rgba(255,215,0,.3))}
.section-title.spc{color:var(--spc-green)}
.section-title.spc::after{background:linear-gradient(to left,transparent,rgba(16,185,129,.3))}
.profile-header{display:flex;align-items:center;gap:14px;margin-bottom:18px;padding:16px;background:linear-gradient(135deg,rgba(61,219,196,.1),transparent);border-radius:19px;border:1px solid var(--border)}
.profile-header.premium{background:linear-gradient(135deg,rgba(255,215,0,.12),rgba(255,165,0,.05));border-color:rgba(255,215,0,.3)}
.profile-avatar{width:68px;height:68px;border-radius:23px;background:linear-gradient(145deg,#62f7df,#0eb9a2);color:#04302a;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;overflow:hidden;flex-shrink:0;position:relative;cursor:pointer;transition:.2s}
.profile-avatar:hover{transform:scale(1.05)}
.profile-avatar img{width:100%;height:100%;object-fit:cover}
.profile-avatar.premium-glow{box-shadow:0 0 22px rgba(255,215,0,.5);border:2px solid rgba(255,215,0,.4)}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:var(--input);border-radius:15px;border:1px solid var(--border);margin-bottom:10px;gap:12px}
.toggle-row-label{font-size:13px;font-weight:800}
.toggle-switch{position:relative;width:48px;height:27px;flex-shrink:0}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;cursor:pointer;inset:0;background:var(--border-strong);border-radius:27px;transition:.3s}
.toggle-slider::before{position:absolute;content:"";height:21px;width:21px;right:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
.toggle-switch input:checked+.toggle-slider{background:linear-gradient(145deg,#62f7df,#0eb9a2)}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(-21px)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:11px;margin-bottom:24px}
.stat-card{background:var(--input);border-radius:18px;padding:17px 14px;text-align:center;border:1px solid var(--border);transition:.25s}
.stat-card:hover{transform:translateY(-4px);border-color:rgba(61,219,196,.42)}
.stat-card.vip{background:linear-gradient(135deg,rgba(255,215,0,.15),rgba(255,165,0,.05));border-color:rgba(255,215,0,.4)}
.stat-card.spc{background:linear-gradient(135deg,rgba(16,185,129,.15),rgba(5,150,105,.05));border-color:rgba(16,185,129,.4)}
.stat-ico{font-size:22px;margin-bottom:6px;display:inline-block}
.stat-value{font-size:23px;font-weight:800;margin-bottom:3px;color:var(--accent)}
.stat-card.vip .stat-value{color:var(--vip-gold)}
.stat-card.spc .stat-value{color:var(--spc-green)}
.stat-label{font-size:11.5px;color:var(--t2);font-weight:700}
.table-wrapper{overflow:auto;border:1px solid var(--border);border-radius:17px;max-height:430px;background:var(--input)}
.users-table{width:100%;border-collapse:collapse;font-size:13px;min-width:900px}
.users-table th,.users-table td{padding:13px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap}
.users-table th{position:sticky;top:0;background:var(--panel-solid);z-index:2;font-weight:800;color:var(--t2);font-size:11.5px}
.users-table tr:hover td{background:var(--hover)}
.badge{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:20px;font-size:11px;font-weight:800}
.badge.success{background:rgba(61,219,196,.18);color:var(--accent)}
.badge.danger{background:rgba(255,107,107,.16);color:var(--danger)}
.badge.warning{background:rgba(255,217,61,.14);color:#e0a800}
.badge.info{background:rgba(91,155,213,.15);color:#5b9bd5}
.badge.muted{background:rgba(127,127,127,.16);color:var(--t2)}
.badge.vip{background:linear-gradient(145deg,rgba(255,215,0,.2),rgba(255,165,0,.1));color:var(--vip-gold);border:1px solid rgba(255,215,0,.35)}
.badge.spc{background:linear-gradient(145deg,rgba(16,185,129,.2),rgba(5,150,105,.1));color:var(--spc-green);border:1px solid rgba(16,185,129,.35)}
.checkbox-row{display:flex;flex-wrap:wrap;gap:10px}
.checkbox-item{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:700;padding:10px 15px;background:var(--input);border-radius:12px;border:1px solid var(--border);cursor:pointer;transition:.2s}
.checkbox-item input{width:auto;margin:0;accent-color:var(--accent)}
.action-buttons{display:flex;gap:6px;flex-wrap:wrap}
.mini-btn{border:none;border-radius:10px;padding:7px 12px;cursor:pointer;font-size:12px;background:var(--hover);color:var(--t1);font-weight:800;transition:.18s;display:inline-flex;align-items:center;gap:5px}
.mini-btn:hover{transform:translateY(-1px)}
.mini-btn.danger{background:rgba(255,107,107,.13);color:var(--danger)}
.mini-btn.vip{background:linear-gradient(145deg,rgba(255,215,0,.25),rgba(255,165,0,.15));color:var(--vip-gold);border:1px solid rgba(255,215,0,.35)}
.mini-btn.spc{background:linear-gradient(145deg,rgba(16,185,129,.25),rgba(5,150,105,.15));color:var(--spc-green);border:1px solid rgba(16,185,129,.35)}
.admin-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:13px;flex-wrap:wrap}
.admin-toolbar input{padding:11px 15px;border-radius:13px;border:1.5px solid var(--border);background:var(--input);color:var(--t1);outline:none;min-width:210px}
.admin-tabs{display:flex;gap:6px;margin-bottom:18px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.admin-tab{padding:10px 18px;background:transparent;border:none;color:var(--t2);font-weight:700;font-size:13px;cursor:pointer;border-bottom:2px solid transparent;transition:.2s;border-radius:10px 10px 0 0}
.admin-tab:hover{color:var(--t1);background:var(--hover)}
.admin-tab.active{color:var(--accent);border-bottom-color:var(--accent);background:rgba(61,219,196,.08)}
.admin-panel{display:none}
.admin-panel.active{display:block}
.attachment-preview{background:var(--input);border-radius:17px;padding:14px;margin-bottom:12px;display:flex;align-items:center;justify-content:center;min-height:96px;border:1px solid var(--border);overflow:hidden}
.attachment-preview img,.attachment-preview video{max-width:100%;max-height:260px;border-radius:13px;display:block}
.file-meta{font-size:12px;color:var(--t2);margin-top:6px;display:flex;align-items:center;justify-content:center;gap:7px}
.member-list-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;border-bottom:1px solid var(--border);transition:.18s}
.member-list-item:hover{background:var(--hover)}
.member-list-item:last-child{border-bottom:none}
.member-avatar{width:36px;height:36px;border-radius:12px;background:linear-gradient(145deg,#62f7df,#0eb9a2);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;overflow:hidden;color:#04302a}
.member-avatar img{width:100%;height:100%;object-fit:cover}
.member-info{flex:1;min-width:0}
.member-name{font-size:13px;font-weight:700;display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.member-status{font-size:11px;color:var(--t2)}
.member-badge{font-size:10px;padding:2px 8px;border-radius:10px;background:var(--hover);color:var(--t2)}
.member-actions{display:flex;gap:5px}
.toast{position:fixed;top:24px;left:50%;transform:translateX(-50%);background:var(--panel-solid);color:var(--t1);padding:14px 26px;border-radius:17px;box-shadow:var(--shadow);z-index:1000;display:none;border:1px solid var(--border-strong);font-weight:800;max-width:90%;text-align:center;font-size:13.5px}
.toast.show{display:block;animation:toastIn .3s ease}
@keyframes toastIn{from{transform:translate(-50%,-35px);opacity:0}to{transform:translate(-50%,0);opacity:1}}
.back-btn{display:none}
.online-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-left:4px;background:#4caf50}
.verified-badge{display:inline-flex;align-items:center;vertical-align:middle;flex-shrink:0;cursor:pointer;transition:.2s}
.verified-badge:hover{transform:scale(1.2)}
.verified-badge img{width:20px;height:20px;display:block}
.vip-badge{display:inline-flex;align-items:center;vertical-align:middle;flex-shrink:0;cursor:pointer;transition:.2s;filter:drop-shadow(0 0 4px rgba(255,215,0,.6))}
.vip-badge:hover{transform:scale(1.2) rotate(-8deg)}
.vip-badge img{width:22px;height:22px;display:block}
.vip-badge.animated{animation:vipShine 3s ease-in-out infinite}
@keyframes vipShine{0%,100%{filter:drop-shadow(0 0 4px rgba(255,215,0,.6))}50%{filter:drop-shadow(0 0 12px rgba(255,215,0,.9))}}
.chat-card{background:var(--input);border:1px solid var(--border);border-radius:16px;padding:15px;margin-bottom:10px;transition:.2s}
.chat-card:hover{border-color:rgba(61,219,196,.4);transform:translateY(-1px)}
.chat-card-head{display:flex;align-items:center;gap:12px;margin-bottom:10px}
.chat-card-avatar{width:48px;height:48px;border-radius:14px;background:linear-gradient(145deg,#62f7df,#0eb9a2);display:flex;align-items:center;justify-content:center;color:var(--on-accent);font-weight:800;font-size:18px;overflow:hidden;flex-shrink:0}
.chat-card-avatar img{width:100%;height:100%;object-fit:cover}
.chat-card-title{flex:1;min-width:0}
.chat-card-title .name{font-weight:800;font-size:14px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.chat-card-title .meta{font-size:11px;color:var(--t2);margin-top:3px}
.chat-card-stats{display:flex;gap:10px;font-size:11px;color:var(--t2);margin-bottom:10px;flex-wrap:wrap}
.chat-card-stats span{display:inline-flex;align-items:center;gap:4px}
.chat-card-actions{display:flex;gap:6px;flex-wrap:wrap}
.premium-card{background:linear-gradient(135deg,rgba(255,215,0,.12),rgba(255,165,0,.04));border:1.5px solid rgba(255,215,0,.3);border-radius:18px;padding:18px;margin-bottom:16px;position:relative;overflow:hidden}
.premium-card::before{content:'';position:absolute;top:-50%;right:-50%;width:200%;height:200%;background:radial-gradient(circle,rgba(255,215,0,.08) 0%,transparent 50%);animation:premiumRotate 20s linear infinite;pointer-events:none}
@keyframes premiumRotate{from{transform:rotate(0)}to{transform:rotate(360deg)}}
.premium-card-content{position:relative;z-index:1}
.premium-card-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.premium-card-head h4{font-size:15px;font-weight:800;color:var(--vip-gold);margin:0}
.premium-info-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,215,0,.1)}
.premium-info-row:last-child{border-bottom:none}
.premium-info-label{font-size:12px;color:var(--t2)}
.premium-info-value{font-size:13px;font-weight:700;color:var(--t1)}
.wallet-card{background:linear-gradient(135deg,rgba(16,185,129,.12),rgba(5,150,105,.04));border:1.5px solid rgba(16,185,129,.3);border-radius:18px;padding:18px;margin-bottom:16px;position:relative;overflow:hidden}
.wallet-card::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle,rgba(16,185,129,.08) 0%,transparent 50%);animation:walletRotate 25s linear infinite;pointer-events:none}
@keyframes walletRotate{from{transform:rotate(0)}to{transform:rotate(-360deg)}}
.wallet-card-content{position:relative;z-index:1}
.wallet-card-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.wallet-card-head h4{font-size:15px;font-weight:800;color:var(--spc-green);margin:0}
.wallet-balance{font-size:28px;font-weight:800;color:var(--spc-green);text-align:center;padding:12px 0;border-radius:12px;background:rgba(16,185,129,.1);margin-bottom:12px}
.wallet-balance small{font-size:14px;color:var(--t2);font-weight:500}
.wallet-toman{font-size:13px;color:var(--t2);text-align:center;margin-top:-8px;margin-bottom:12px}
.color-picker{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.color-option{width:36px;height:36px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:.2s;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:800}
.color-option:hover{transform:scale(1.1)}
.color-option.selected{border-color:#fff;box-shadow:0 0 0 2px var(--accent)}
.msg-color-picker{display:flex;gap:6px;padding:8px 12px;background:var(--input);border-radius:12px;margin-bottom:8px;border:1px solid var(--border);align-items:center;flex-wrap:wrap}
.msg-color-picker label{font-size:11px;color:var(--t2);font-weight:700;margin-left:8px}
.msg-color-option{width:28px;height:28px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:.2s}
.msg-color-option:hover{transform:scale(1.15)}
.msg-color-option.selected{border-color:var(--accent);box-shadow:0 0 0 2px var(--accent)}
.msg-color-option.none{background:var(--input);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--t2)}
.premium-feature-list{display:flex;flex-direction:column;gap:8px;margin-top:10px}
.premium-feature{display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,215,0,.06);border-radius:10px;border:1px solid rgba(255,215,0,.15);font-size:12.5px}
.premium-feature-icon{font-size:18px}
.premium-feature-text{flex:1}
.premium-feature-text strong{display:block;font-size:13px;margin-bottom:2px}
.premium-feature-text span{font-size:11px;color:var(--t2)}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:rgba(61,219,196,.35);border-radius:3px}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeSlide{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes popIn{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}
@keyframes messageSlideIn{from{opacity:0;transform:translateY(20px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes reactionPop{0%{transform:scale(.5);opacity:0}50%{transform:scale(1.2)}100%{transform:scale(1);opacity:1}}
.message-reactions{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.reaction-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:15px;background:rgba(255,255,255,.08);border:1px solid var(--border);font-size:12px;cursor:pointer;transition:.2s;animation:reactionPop .3s ease-out}
.reaction-chip:hover{background:rgba(255,255,255,.15);transform:translateY(-2px)}
.reaction-chip.active{background:rgba(61,219,196,.18);border-color:var(--accent);color:var(--accent)}
.reaction-picker{position:absolute;bottom:100%;left:0;background:var(--panel-solid);border:1px solid var(--border-strong);border-radius:24px;padding:8px;display:none;gap:4px;box-shadow:var(--shadow);z-index:40;animation:popIn .2s ease}
.reaction-picker.active{display:flex}
.reaction-picker button{width:40px;height:40px;border:none;background:transparent;font-size:22px;border-radius:50%;cursor:pointer;transition:.2s}
.reaction-picker button:hover{background:var(--hover);transform:scale(1.2)}
.loading-spinner{display:inline-block;width:20px;height:20px;border:3px solid rgba(255,255,255,.3);border-radius:50%;border-top-color:var(--accent);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.bio-counter{font-size:10.5px;color:var(--t2);margin-top:4px;text-align:left;direction:ltr}
.bio-counter.warn{color:#e0a800}
.bio-counter.danger{color:var(--danger)}
.wallet-actions{display:flex;gap:8px;margin-top:12px}
.wallet-actions .btn{flex:1}
.transfer-history{max-height:150px;overflow-y:auto;margin-top:12px;padding:8px;background:var(--input);border-radius:12px;border:1px solid var(--border)}
.wallet-fab{position:fixed;bottom:26px;left:50%;transform:translateX(-50%);width:64px;height:64px;border-radius:50%;background:linear-gradient(145deg,#34d399,#059669);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:28px;z-index:90;box-shadow:0 10px 30px rgba(16,185,129,.45);transition:.25s}
.wallet-fab:hover{transform:translateX(-50%) translateY(-3px) scale(1.06)}
.wallet-page{position:fixed;inset:0;z-index:95;background:var(--bg);display:none;flex-direction:column;animation:walletPageIn .3s ease}
.wallet-page.active{display:flex}
@keyframes walletPageIn{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.wallet-page-head{display:flex;align-items:center;gap:10px;padding:16px 18px;border-bottom:1px solid var(--border);background:var(--panel);backdrop-filter:blur(24px)}
.wallet-page-head h3{font-size:17px;font-weight:800;margin:0;flex:1}
.wallet-page-body{flex:1;overflow-y:auto;padding:18px;min-height:0}
.wallet-page-inner{max-width:640px;margin:0 auto}
.wallet-balance-big{font-size:34px;font-weight:800;color:var(--spc-green);text-align:center;padding:18px 0 6px}
.wallet-balance-big small{font-size:16px;color:var(--t2);font-weight:500}
.wallet-toman-big{font-size:14px;color:var(--t2);text-align:center;margin-bottom:16px}
.wallet-stats-row{display:flex;gap:10px;margin-bottom:16px}
.wallet-stat{flex:1;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px;text-align:center}
.wallet-stat b{display:block;font-size:17px;margin-bottom:2px}
.wallet-stat span{font-size:11.5px;color:var(--t2)}
.wallet-stat.in b{color:var(--spc-green)}
.wallet-stat.out b{color:var(--danger)}
.history-list{display:flex;flex-direction:column;gap:8px}
.history-item{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px 14px}
.history-icon{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.history-item.in .history-icon{background:rgba(16,185,129,.14)}
.history-item.out .history-icon{background:rgba(255,107,107,.12)}
.history-info{flex:1;min-width:0}
.history-title{font-size:13.5px;font-weight:700;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.history-meta{font-size:11.5px;color:var(--t2)}
.history-amount{text-align:center;flex-shrink:0}
.history-amount b{display:block;font-size:14.5px;direction:ltr}
.history-item.in .history-amount b{color:var(--spc-green)}
.history-item.out .history-amount b{color:var(--danger)}
.history-amount span{font-size:10.5px;color:var(--t2);direction:ltr;display:inline-block}
.history-empty{text-align:center;color:var(--t2);padding:40px 10px;font-size:14px}
@media (max-width:768px){
.wallet-fab{bottom:18px;width:58px;height:58px;font-size:25px}
}
@media (max-width:768px){
.app{padding:0;gap:0}
.sidebar{width:100%;position:absolute;inset:0;z-index:10;border-radius:0;border:none}
.chat-area{position:absolute;inset:0;z-index:9;transform:translateX(-100%);transition:transform .35s cubic-bezier(.22,.9,.3,1);border-radius:0;border:none}
.app.show-chat .chat-area{transform:translateX(0);z-index:11}
.back-btn{display:flex !important}
.message{max-width:88%}
.modal{max-width:calc(100vw - 26px);padding:20px}
.modal.large{max-width:calc(100vw - 16px)}
.fab-container{bottom:16px;left:14px}
.users-table{min-width:700px}
}
/* ==================== استایل اختصاصی ویس (پیام صوتی) ==================== */
.mic-btn{position:relative;width:44px;height:44px;flex:none;border-radius:15px;border:1.5px solid rgba(61,219,196,.35);background:linear-gradient(145deg,rgba(61,219,196,.16),rgba(23,176,155,.08));color:var(--accent);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:19px;transition:.22s;-webkit-user-select:none;user-select:none;touch-action:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.06)}
.mic-btn:hover{transform:translateY(-2px) scale(1.04);border-color:var(--accent);box-shadow:0 8px 22px rgba(23,176,155,.35)}
.mic-btn:active{transform:scale(.95)}
.mic-ico{display:block;line-height:1;filter:drop-shadow(0 0 6px rgba(61,219,196,.4));position:relative;z-index:2}
.mic-pulse{position:absolute;inset:-2px;border-radius:17px;border:2px solid var(--accent);opacity:0;pointer-events:none}
.mic-btn.ready .mic-pulse{animation:micReadyPulse 1.6s ease-out infinite}
@keyframes micReadyPulse{0%{opacity:.7;transform:scale(.9)}70%{opacity:0;transform:scale(1.35)}100%{opacity:0;transform:scale(1.35)}}
.voice-ui{flex:1;align-items:center;gap:9px;background:linear-gradient(145deg,var(--card),var(--card-2));border:1.5px solid rgba(255,107,107,.4);border-radius:17px;padding:6px 9px;animation:popIn .2s ease;box-shadow:0 8px 26px rgba(255,80,80,.14)}
.voice-cancel-btn{width:36px;height:36px;flex:none;border-radius:12px;border:1px solid var(--border-strong);background:transparent;color:var(--danger);font-size:15px;cursor:pointer;transition:.2s;display:flex;align-items:center;justify-content:center}
.voice-cancel-btn:hover{background:rgba(255,107,107,.14);transform:rotate(90deg)}
.voice-wave{flex:1;display:flex;align-items:center;justify-content:space-between;gap:2px;height:36px;min-width:60px;overflow:hidden}
.voice-wave span{flex:1;max-width:5px;min-width:2px;height:14%;border-radius:3px;background:linear-gradient(180deg,#ff9b9b,#ff5470);animation:vbarBounce 1s ease-in-out infinite;animation-delay:calc(var(--i,0)*.07s)}
@keyframes vbarBounce{0%,100%{transform:scaleY(.55)}50%{transform:scaleY(1)}}
.voice-timer{direction:ltr;font-weight:800;font-size:13.5px;color:#ff8f8f;font-family:'Vazirmatn',Tahoma,sans-serif;min-width:38px;text-align:center;display:flex;align-items:center;gap:5px}
.voice-timer::before{content:'';width:8px;height:8px;border-radius:50%;background:#ff5470;animation:recDot 1s steps(2,start) infinite}
@keyframes recDot{0%,100%{opacity:1}50%{opacity:.15}}
.voice-send-btn{width:40px;height:40px;flex:none;border-radius:13px;border:none;background:linear-gradient(145deg,#62f7df,#0eb9a2);color:#04302a;font-size:17px;cursor:pointer;transition:.2s;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 18px rgba(14,185,162,.35)}
.voice-send-btn:hover{transform:translateY(-2px) scale(1.05)}
.voice-send-btn:disabled{opacity:.5;cursor:wait;transform:none}
.attach-ico-voice{background:linear-gradient(145deg,rgba(61,219,196,.22),rgba(23,176,155,.1)) !important;border:1px solid rgba(61,219,196,.35)}
.voice-perm-overlay{position:fixed;inset:0;background:rgba(3,10,16,.72);backdrop-filter:blur(9px);-webkit-backdrop-filter:blur(9px);z-index:1200;display:flex;align-items:center;justify-content:center;padding:18px;animation:permFade .25s ease}
@keyframes permFade{from{opacity:0}to{opacity:1}}
.voice-perm-card{background:linear-gradient(160deg,var(--card),var(--panel-solid));border:1px solid var(--border-strong);border-radius:26px;padding:30px 26px;max-width:370px;width:100%;text-align:center;box-shadow:var(--shadow);animation:modalIn .32s cubic-bezier(.22,.9,.3,1)}
.voice-perm-icon{width:88px;height:88px;margin:0 auto 16px;border-radius:50%;background:radial-gradient(circle at 32% 28%,rgba(61,219,196,.35),rgba(23,176,155,.08));border:1.5px solid rgba(61,219,196,.45);display:flex;align-items:center;justify-content:center;font-size:40px;animation:permFloat 2.4s ease-in-out infinite}
@keyframes permFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-7px)}}
.voice-perm-title{font-size:17px;font-weight:800;color:var(--t1);margin-bottom:8px}
.voice-perm-desc{font-size:13px;line-height:1.9;color:var(--t2);margin-bottom:22px}
.voice-perm-actions{display:flex;gap:10px}
.voice-perm-actions button{flex:1;padding:12px 0;border-radius:15px;border:none;cursor:pointer;font-size:14px;font-weight:800;font-family:inherit;transition:.2s}
.vp-allow{background:linear-gradient(145deg,#62f7df,#0eb9a2);color:#04302a;box-shadow:0 8px 22px rgba(14,185,162,.3)}
.vp-allow:hover{transform:translateY(-2px)}
.vp-deny{background:transparent;border:1.5px solid var(--border-strong) !important;color:var(--t2)}
.vp-deny:hover{background:var(--hover);color:var(--t1)}
.voice-bubble{display:flex;align-items:center;gap:10px;direction:ltr;min-width:215px;max-width:300px;padding:10px 12px;border-radius:17px;background:linear-gradient(145deg,rgba(61,219,196,.13),rgba(23,176,155,.05));border:1px solid rgba(61,219,196,.28);box-shadow:inset 0 1px 0 rgba(255,255,255,.05)}
.message.me .voice-bubble{background:linear-gradient(145deg,rgba(61,219,196,.2),rgba(23,176,155,.09));border-color:rgba(61,219,196,.4)}
.voice-play-btn{width:42px;height:42px;flex:none;border-radius:50%;border:none;background:linear-gradient(145deg,#62f7df,#0eb9a2);color:#04302a;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.2s;box-shadow:0 5px 16px rgba(14,185,162,.4)}
.voice-play-btn:hover{transform:scale(1.09)}
.voice-play-btn:active{transform:scale(.93)}
.voice-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:6px}
.voice-waveform{display:flex;align-items:center;gap:2px;height:30px;cursor:pointer;padding:0 1px}
.voice-waveform span{flex:1;min-width:2px;max-width:5px;border-radius:3px;background:rgba(138,162,178,.45);transition:background .15s}
[data-theme="light"] .voice-waveform span{background:rgba(93,118,132,.35)}
.voice-waveform span.played{background:linear-gradient(180deg,#3ddbc4,#17b09b);box-shadow:0 0 6px rgba(61,219,196,.45)}
.voice-waveform.static span{background:rgba(61,219,196,.55)}
.voice-bottom{display:flex;align-items:center;justify-content:space-between;gap:8px}
.voice-duration{font-size:11.5px;font-weight:800;color:var(--t2);direction:ltr;font-variant-numeric:tabular-nums}
.voice-listen-hint{font-size:10.5px;font-weight:800;color:#ff9d5c;letter-spacing:.2px}
.voice-listen-hint.listened{color:var(--accent)}
.voice-dl{flex:none;width:30px;height:30px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--t2);text-decoration:none;font-size:13px;background:rgba(255,255,255,.05);border:1px solid var(--border);transition:.2s}
.voice-dl:hover{color:var(--accent);border-color:var(--accent);transform:translateY(-2px)}
.attach-voice{width:100%}
/* ---------- ارتقاء ظاهر ویس‌های ارسالی (افزونه اختصاصی - بدون حذف هیچ کد قبلی) ---------- */
.voice-bubble{position:relative;overflow:hidden;padding:12px 14px;border-radius:20px;background:linear-gradient(150deg,rgba(61,219,196,.16),rgba(23,176,155,.05) 65%,rgba(14,185,162,.1));border:1px solid rgba(61,219,196,.3);box-shadow:inset 0 1px 0 rgba(255,255,255,.07),0 6px 20px rgba(4,30,26,.25)}
.message.me .voice-bubble{background:linear-gradient(150deg,rgba(98,247,223,.22),rgba(23,176,155,.1) 65%,rgba(14,185,162,.14));border-color:rgba(98,247,223,.42);box-shadow:inset 0 1px 0 rgba(255,255,255,.1),0 6px 20px rgba(4,40,34,.3)}
[data-theme="light"] .voice-bubble{background:linear-gradient(150deg,rgba(23,176,155,.1),rgba(61,219,196,.06));border-color:rgba(23,176,155,.3);box-shadow:0 4px 14px rgba(23,120,105,.12)}
[data-theme="light"] .message.me .voice-bubble{background:linear-gradient(150deg,rgba(23,176,155,.16),rgba(61,219,196,.08));border-color:rgba(23,176,155,.4)}
.voice-bubble::before{content:'';position:absolute;top:-60%;left:-30%;width:45%;height:220%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.14),transparent);transform:rotate(18deg);pointer-events:none;opacity:0}
.voice-bubble.playing::before{opacity:1;animation:voiceSheen 2.6s ease-in-out infinite}
@keyframes voiceSheen{0%{left:-30%}100%{left:120%}}
.voice-play-btn{position:relative;width:46px;height:46px;font-size:16px;line-height:1;background:linear-gradient(145deg,#6ffbe4,#0eb9a2 70%,#0aa18d);box-shadow:0 6px 18px rgba(14,185,162,.45),inset 0 1px 0 rgba(255,255,255,.35)}
.voice-play-btn::after{content:'';position:absolute;inset:-4px;border-radius:50%;border:2px solid rgba(61,219,196,.55);opacity:0;pointer-events:none}
.voice-bubble.playing .voice-play-btn::after{animation:playRipple 1.5s ease-out infinite}
@keyframes playRipple{0%{opacity:.85;transform:scale(.82)}70%{opacity:0;transform:scale(1.28)}100%{opacity:0;transform:scale(1.28)}}
.voice-waveform{height:34px;gap:3px}
.voice-waveform span{align-self:center;border-radius:99px;min-width:3px;max-width:6px;background:linear-gradient(180deg,rgba(148,172,188,.5),rgba(110,136,152,.4));transition:background .18s,transform .18s,box-shadow .18s}
[data-theme="light"] .voice-waveform span{background:linear-gradient(180deg,rgba(104,128,142,.4),rgba(84,108,122,.3))}
.voice-waveform span.played{background:linear-gradient(180deg,#7cf3de,#17b09b);transform:scaleY(1.12);box-shadow:0 0 9px rgba(61,219,196,.55)}
.voice-waveform:hover span{filter:brightness(1.15)}
.voice-head{display:flex;align-items:center;gap:6px;font-size:10.5px;font-weight:800;color:var(--t2);letter-spacing:.2px}
.voice-mic-badge{font-size:11px;filter:drop-shadow(0 0 5px rgba(61,219,196,.5))}
.voice-speed-btn{margin-inline-start:auto;direction:ltr;min-width:34px;height:22px;padding:0 7px;border-radius:8px;border:1px solid var(--border-strong);background:rgba(255,255,255,.05);color:var(--t2);font-size:10px;font-weight:900;font-family:'Vazirmatn',Tahoma,sans-serif;cursor:pointer;transition:.18s;display:flex;align-items:center;justify-content:center;font-variant-numeric:tabular-nums}
.voice-speed-btn:hover{color:var(--accent);border-color:var(--accent);background:rgba(61,219,196,.1);transform:translateY(-1px)}
.voice-speed-btn.active{color:#04302a;background:linear-gradient(145deg,#62f7df,#0eb9a2);border-color:transparent;box-shadow:0 3px 10px rgba(14,185,162,.35)}
.voice-progress-track{position:relative;height:4px;border-radius:99px;background:rgba(138,162,178,.22);overflow:hidden;margin-top:-1px}
[data-theme="light"] .voice-progress-track{background:rgba(93,118,132,.2)}
.voice-progress-fill{position:absolute;inset-block:0;inset-inline-start:0;width:0%;border-radius:99px;background:linear-gradient(90deg,#17b09b,#62f7df);box-shadow:0 0 8px rgba(61,219,196,.6);transition:width .25s linear}
.voice-eq{display:flex;align-items:flex-end;gap:2px;height:12px;opacity:0;transition:opacity .3s}
.voice-eq i{width:3px;height:20%;border-radius:2px;background:linear-gradient(180deg,#62f7df,#0eb9a2);animation:vbarBounce .9s ease-in-out infinite;animation-delay:calc(var(--i,0)*.12s)}
.voice-bubble.playing .voice-eq{opacity:1}
.voice-duration{display:flex;align-items:center;gap:5px;color:var(--accent);font-size:12px}
.voice-listen-hint{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;background:rgba(255,157,92,.12);border:1px solid rgba(255,157,92,.25);transition:.25s}
.voice-listen-hint.listened{background:rgba(61,219,196,.12);border-color:rgba(61,219,196,.3);animation:hintPop .3s cubic-bezier(.34,1.56,.64,1)}
@keyframes hintPop{0%{transform:scale(.7)}100%{transform:scale(1)}}
.voice-dl{background:linear-gradient(145deg,rgba(61,219,196,.14),rgba(23,176,155,.05))}
.voice-dl:hover{box-shadow:0 6px 16px rgba(14,185,162,.3);background:linear-gradient(145deg,rgba(61,219,196,.25),rgba(23,176,155,.1))}
@media (max-width:480px){.voice-bubble{min-width:190px}}
</style>
</head>
<body data-theme="dark">
<div class="bg-scene"><div class="blob blob-1"></div><div class="blob blob-2"></div></div>
<button class="wallet-fab" id="walletFab" onclick="openWalletPage()" title="کیف پول">💰</button>
<div class="wallet-page" id="walletPage">
<div class="wallet-page-head">
<button class="icon-btn" onclick="closeWalletPage()" title="بازگشت">⬅️</button>
<h3>💳 کیف پول SPC</h3>
<button class="icon-btn" onclick="loadWalletHistory()" title="بروزرسانی">🔄</button>
</div>
<div class="wallet-page-body">
<div class="wallet-page-inner" id="walletPageContent">
<div style="text-align:center;color:var(--t2);padding:30px">در حال بارگذاری...</div>
</div>
</div>
</div>
<div class="app" id="app">
<div class="sidebar">
<div class="sidebar-header">
<div class="brand">
<img src="<?= htmlspecialchars($LOGO_URL) ?>" alt="لوگو" class="brand-logo">
<div class="brand-title">
<span>پیام‌ها <span id="totalNotifBadge" class="unread-badge" style="display:none">0</span></span>
<small>پیام‌رسان</small>
</div>
</div>
<div class="header-actions">
<?php if (!empty($user['is_admin'])): ?>
<button class="icon-btn" onclick="openAdminPanel()" title="پنل مدیریت">🛡️</button>
<?php endif; ?>
<button class="icon-btn" onclick="openProfileModal()" title="تنظیمات">⚙️</button>
<button class="icon-btn" onclick="toggleTheme()" id="themeBtn" title="تم">🌙</button>
<button class="icon-btn" onclick="logout()" title="خروج">🚪</button>
</div>
</div>
<div class="me-card <?= !empty($safe_user['premium']) ? 'premium' : '' ?>" onclick="openProfileModal()">
<div class="avatar <?= !empty($safe_user['premium']) ? 'premium-glow' : '' ?>" id="meAvatar">
<?php if ($ME_AVATAR_URL !== ''): ?>
<img src="<?= htmlspecialchars($ME_AVATAR_URL) ?>" alt="">
<?php else: ?>
<?= htmlspecialchars(mb_substr($user['name'] ?: $user['username'], 0, 1)) ?>
<?php endif; ?>
</div>
<div class="me-info">
<div class="me-name"><?= htmlspecialchars($user['name'] ?? $user['username']) ?></div>
<div class="me-username">@<?= htmlspecialchars($user['username']) ?></div>
</div>
<span class="icon-btn" style="pointer-events:none">›</span>
</div>
<div class="search-box">
<input type="text" id="searchChats" placeholder="جستجو در گفتگوها..." oninput="filterChats()">
</div>
<div class="chat-list" id="chatList"></div>
<div class="fab-container" id="fabContainer">
<div class="fab-menu" id="fabMenu">
<div class="fab-menu-item" onclick="createChat('channel')"><div class="fab-mi-icon">📣</div><div class="fab-mi-text"><b>کانال جدید</b><span>انتشار محتوا</span></div></div>
<div class="fab-menu-item" onclick="createChat('group')"><div class="fab-mi-icon">👥</div><div class="fab-mi-text"><b>گروه جدید</b><span>گفتگوی چند نفره</span></div></div>
<div class="fab-menu-item" onclick="createChat('private')"><div class="fab-mi-icon">💬</div><div class="fab-mi-text"><b>پیام خصوصی</b><span>شروع گفتگوی دونفره</span></div></div>
</div>
<button class="fab-btn" onclick="toggleFab()" id="fabBtn">➕</button>
</div>
</div>
<div class="chat-area" id="chatArea">
<div class="empty-state" id="emptyState">
<div class="empty-logo"><img src="<?= htmlspecialchars($LOGO_URL) ?>" alt="لوگو"></div>
<h3>به پیام‌رسان خوش آمدید</h3>
<div>یک گفتگو را انتخاب کنید یا یکی جدید بسازید</div>
<div class="empty-chips">
<span class="empty-chip" onclick="openSavedChat()">🔖 پیام‌های ذخیره‌شده</span>
<span class="empty-chip" onclick="createChat('channel')">📣 ساخت کانال</span>
<span class="empty-chip" onclick="createChat('group')">👥 ایجاد گروه</span>
</div>
</div>
<div id="chatContainer" style="display:none;flex:1;flex-direction:column;min-height:0">
<div class="chat-header">
<button class="icon-btn back-btn" onclick="closeChat()">⬅️</button>
<div class="chat-avatar" id="chatAvatar"></div>
<div class="chat-header-info" id="chatHeaderInfo">
<div class="chat-header-name" id="chatName"></div>
<div class="chat-header-status" id="chatStatus"></div>
</div>
<div class="header-actions">
<button class="icon-btn" onclick="toggleChatSearch()" title="جستجو">🔍</button>
<button class="icon-btn" onclick="openChatMenu()" title="منو">⋮</button>
<button class="icon-btn" onclick="openMembersModal()" title="اعضا" id="membersBtn" style="display:none">👥</button>
</div>
</div>
<div id="chatSearchBar" style="display:none;padding:10px 16px;border-bottom:1px solid var(--border);background:var(--input)">
<div style="display:flex;align-items:center;gap:8px">
<input type="text" id="chatSearchInput" placeholder="جستجو در پیام‌ها..." oninput="filterChatMessages()" style="flex:1;padding:8px 12px;border-radius:10px;border:1px solid var(--border);background:var(--panel-solid);color:var(--t1);outline:none;font-size:13px">
<button class="icon-btn" onclick="toggleChatSearch()" style="width:32px;height:32px;font-size:14px">✖️</button>
</div>
</div>
<div id="pinnedMessage" style="display:none;padding:8px 16px;background:var(--input);border-bottom:1px solid var(--border);cursor:pointer" onclick="scrollToMessage(this.dataset.msgId)"></div>
<div class="messages" id="messages"></div>
<div class="message-input-wrap" id="messageInputWrap">
<div id="replyPreview" style="display:none;padding:0 16px 8px"></div>
<div id="msgColorPicker" style="display:none"></div>
<div class="message-input">
<div style="position:relative">
<button class="round-btn" onclick="toggleAttach()" title="پیوست" type="button">📎</button>
<div class="attach-popup" id="attachPop">
<button class="attach-popup-item" onclick="pickAttach('image')"><span class="ico">🖼️</span><span>تصویر</span></button>
<button class="attach-popup-item" onclick="pickAttach('video')"><span class="ico">🎬</span><span>ویدیو</span></button>
<button class="attach-popup-item" onclick="pickAttach('voice')"><span class="ico attach-ico-voice">🎙️</span><span>پیام صوتی (ویس)</span></button>
<button class="attach-popup-item" onclick="pickAttach('file')"><span class="ico">📄</span><span>فایل</span></button>
</div>
</div>
<input type="file" id="fileInput" style="display:none">
<input type="file" id="imageInput" style="display:none" accept="image/*">
<input type="file" id="videoInput" style="display:none" accept="video/*">
<input type="file" id="voiceInput" style="display:none" accept="audio/*">
<div class="voice-ui" id="voiceUi" style="display:none">
<button class="voice-cancel-btn" id="voiceCancelBtn" type="button" title="لغو ضبط" onclick="cancelVoice()">✖</button>
<div class="voice-wave" id="voiceWave"></div>
<span class="voice-timer" id="voiceTimer">0:00</span>
<button class="voice-send-btn" id="voiceSendBtn" type="button" title="ارسال ویس" onclick="sendVoice()">🎙➤</button>
</div>
<textarea id="messageInput" placeholder="پیام خود را بنویسید..." rows="1"></textarea>
<button class="mic-btn" id="micBtn" type="button" title="برای ضبط ویس، دکمه میکروفون را نگه دارید" onclick="requestMicPermission()" onmousedown="startVoice(event)" ontouchstart="startVoice(event)"><span class="mic-ico">🎙️</span><span class="mic-pulse"></span></button>
<button class="send-btn" onclick="sendMessage()" type="button">➤</button>
</div>
</div>
</div>
</div>
</div>
<div class="modal-overlay" id="newChatModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">💬</div>
<h3 id="newChatTitle">ایجاد چت جدید</h3>
<button class="icon-btn" onclick="closeModal('newChatModal')">✖️</button>
</div>
<div class="modal-field" id="chatNameField" style="display:none">
<label>نام</label>
<input type="text" id="newChatName" placeholder="مثلاً: دوستان صمیمی">
</div>
<div class="modal-field" id="chatDescField" style="display:none">
<label>توضیحات (اختیاری)</label>
<textarea id="newChatDesc" placeholder="توضیحی کوتاه..."></textarea>
</div>
<div class="modal-field">
<label>انتخاب کاربر</label>
<input type="text" id="userSearch" placeholder="جستجوی آیدی یا نام کاربر..." oninput="searchUsers()">
<div class="small-note">فقط کاربرانی که «قابل جستجو» باشند نمایش داده می‌شوند.</div>
<div class="selected-chips" id="selectedChips"></div>
<div class="users-list" id="usersList" style="margin-top:8px"></div>
</div>
<div class="modal-actions">
<button class="btn btn-secondary" onclick="closeModal('newChatModal')">انصراف</button>
<button class="btn btn-primary" onclick="submitCreateChat()">➕ ایجاد</button>
</div>
</div>
</div>
<div class="modal-overlay" id="chatMenuModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">⚙️</div>
<h3 id="menuTitle">تنظیمات چت</h3>
<button class="icon-btn" onclick="closeModal('chatMenuModal')">✖️</button>
</div>
<div id="chatMenuContent"></div>
</div>
</div>
<div class="modal-overlay" id="editChatModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">✏️</div>
<h3>ویرایش چت</h3>
<button class="icon-btn" onclick="closeModal('editChatModal')">✖️</button>
</div>
<div class="avatar-upload-box">
<div class="avatar-big" id="editChatAvatar" onclick="document.getElementById('chatAvatarInput').click()"></div>
<div class="avatar-actions">
<button class="mini-btn danger" type="button" onclick="removeChatAvatar()">🗑️ حذف عکس</button>
</div>
</div>
<input type="file" id="chatAvatarInput" style="display:none" accept="image/*" onchange="uploadChatAvatar(this)">
<div class="modal-field"><label>نام چت</label><input type="text" id="editChatName"></div>
<div class="modal-field"><label>توضیحات</label><textarea id="editChatDesc"></textarea></div>
<div class="modal-actions">
<button class="btn btn-secondary" onclick="closeModal('editChatModal')">انصراف</button>
<button class="btn btn-primary" onclick="saveChatEdit()">💾 ذخیره تغییرات</button>
</div>
</div>
</div>
<div class="modal-overlay" id="chatProfileModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon" id="chatProfileIcon">👥</div>
<h3 id="chatProfileTitle">پروفایل چت</h3>
<button class="icon-btn" onclick="closeModal('chatProfileModal')">✖️</button>
</div>
<div class="profile-header">
<div class="profile-avatar" id="chatProfileAvatar" style="cursor:default"></div>
<div style="flex:1;min-width:0">
<div style="font-weight:800;font-size:16px;display:flex;align-items:center;gap:6px" id="chatProfileName"></div>
<div style="font-size:12.5px;color:var(--t2);margin-top:4px" id="chatProfileType"></div>
</div>
</div>
<div class="modal-field"><label>توضیحات</label><div id="chatProfileDesc" style="font-size:13px;color:var(--t2);line-height:1.8;background:var(--input);padding:12px;border-radius:12px"></div></div>
<div class="modal-field"><label>مالک</label><div id="chatProfileOwner" style="font-size:13px;color:var(--t1);line-height:1.8"></div></div>
<div class="modal-field"><label>آمار</label><div id="chatProfileStats" style="font-size:13px;color:var(--t2);line-height:1.8"></div></div>
<div class="modal-actions">
<button class="btn btn-secondary" onclick="closeModal('chatProfileModal')">بستن</button>
<button class="btn btn-primary" onclick="openMembersModalFromProfile()">👥 مشاهده اعضا</button>
</div>
</div>
</div>
<div class="modal-overlay" id="forwardModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">↪️</div>
<h3>فوروارد پیام به...</h3>
<button class="icon-btn" onclick="closeModal('forwardModal')">✖️</button>
</div>
<div class="modal-field">
<input type="text" id="forwardSearch" placeholder="جستجوی چت..." oninput="renderForwardChats()">
<div class="users-list" id="forwardChatsList" style="margin-top:8px;max-height:300px"></div>
</div>
</div>
</div>
<div class="modal-overlay" id="profileModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">👤</div>
<h3>تنظیمات حساب کاربری</h3>
<button class="icon-btn" onclick="closeModal('profileModal')">✖️</button>
</div>
<div id="premiumSection"></div>
<div id="walletSection"></div>
<div class="profile-header">
<div class="profile-avatar" id="profileAvatar" onclick="document.getElementById('userAvatarInput').click()"></div>
<div style="flex:1;min-width:0">
<div style="font-weight:800;font-size:16px;display:flex;align-items:center;gap:6px" id="profileDisplayName"></div>
<div style="font-size:12.5px;color:var(--accent);direction:ltr;text-align:right" id="profileDisplayUsername"></div>
<div class="avatar-actions" style="justify-content:flex-start">
<button class="mini-btn danger" type="button" onclick="removeUserAvatar()">🗑️ حذف عکس</button>
</div>
</div>
</div>
<input type="file" id="userAvatarInput" style="display:none" accept="image/*" onchange="uploadUserAvatar(this)">
<div class="section-title">👤 اطلاعات حساب</div>
<div class="modal-field"><label>@ آیدی</label><input type="text" id="profileUsername" style="direction:ltr;text-align:left"></div>
<div class="modal-field"><label>نام نمایشی</label><input type="text" id="profileName"></div>
<div class="modal-field">
<label>بیو <span id="bioMaxLabel" style="font-weight:500;color:var(--t2)"></span></label>
<textarea id="profileBio" oninput="updateBioCounter()"></textarea>
<div class="bio-counter" id="bioCounter"></div>
</div>
<button class="btn btn-primary btn-block" onclick="saveProfile()">💾 ذخیره پروفایل</button>
<div class="section-title">🔒 حریم خصوصی</div>
<div class="toggle-row">
<div style="flex:1">
<div class="toggle-row-label">🔍 قابل جستجو بودن</div>
<div class="small-note">اگر غیرفعال باشد، دیگران شما را در جستجو پیدا نمی‌کنند</div>
</div>
<label class="toggle-switch"><input type="checkbox" id="profileSearchable"><span class="toggle-slider"></span></label>
</div>
<div class="section-title">🔑 تغییر رمز عبور</div>
<div class="modal-field"><label>رمز عبور فعلی</label><input type="password" id="currentPassword"></div>
<div class="modal-field"><label>رمز عبور جدید</label><input type="password" id="newPassword"></div>
<div class="modal-field"><label>تکرار رمز عبور جدید</label><input type="password" id="confirmPassword"></div>
<button class="btn btn-primary btn-block" onclick="changePassword()">🔑 تغییر رمز عبور</button>
</div>
</div>
<div class="modal-overlay" id="viewProfileModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">👤</div>
<h3>پروفایل کاربر</h3>
<button class="icon-btn" onclick="closeModal('viewProfileModal')">✖️</button>
</div>
<div id="viewProfilePremiumSection"></div>
<div class="profile-header" id="viewProfileHeader">
<div class="profile-avatar" id="viewProfileAvatar" style="cursor:default"></div>
<div style="flex:1;min-width:0">
<div style="font-weight:800;font-size:16px;display:flex;align-items:center;gap:6px" id="viewProfileName"></div>
<div style="font-size:12.5px;color:var(--accent);direction:ltr;text-align:right" id="viewProfileUsername"></div>
</div>
</div>
<div class="modal-field"><label>بیو</label><div id="viewProfileBio" style="font-size:13px;color:var(--t2);line-height:1.8"></div></div>
<div class="modal-actions">
<button class="btn btn-secondary" onclick="closeModal('viewProfileModal')">بستن</button>
<button class="btn btn-primary" onclick="startPrivateWith(viewProfileTarget)">💬 پیام خصوصی</button>
</div>
</div>
</div>
<div class="modal-overlay" id="transferModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">💸</div>
<h3>انتقال SPC</h3>
<button class="icon-btn" onclick="closeModal('transferModal')">✖️</button>
</div>
<div id="transferWalletInfo" style="margin-bottom:16px"></div>
<div class="modal-field">
<label>نام کاربری مقصد</label>
<input type="text" id="transferToUsername" placeholder="@username" style="direction:ltr;text-align:left">
<div class="small-note">نام کاربری (آیدی) فردی که می‌خواهید SPC به او انتقال دهید</div>
</div>
<div class="modal-field">
<label>مقدار SPC</label>
<input type="number" id="transferAmount" min="1" max="1000000" placeholder="مثلاً: 100" style="direction:ltr;text-align:left">
<div class="small-note">حداقل 1 و حداکثر 1,000,000 SPC در هر انتقال</div>
</div>
<div id="transferPreview" style="padding:12px;background:var(--input);border-radius:12px;border:1px solid var(--border);margin-bottom:16px;display:none">
<div style="display:flex;justify-content:space-between;margin-bottom:6px"><span style="color:var(--t2);font-size:12px">مقدار انتقال:</span><strong id="transferPreviewAmount">0 SPC</strong></div>
<div style="display:flex;justify-content:space-between"><span style="color:var(--t2);font-size:12px">معادل تومانی:</span><strong id="transferPreviewToman">0 تومان</strong></div>
</div>
<div class="modal-actions">
<button class="btn btn-secondary" onclick="closeModal('transferModal')">انصراف</button>
<button class="btn btn-spc" onclick="submitTransfer()">💸 انتقال SPC</button>
</div>
</div>
</div>
<div class="modal-overlay" id="adminModal">
<div class="modal large">
<div class="modal-head">
<div class="modal-head-icon">🛡️</div>
<h3>پنل مدیریت</h3>
<button class="icon-btn" onclick="closeModal('adminModal')">✖️</button>
</div>
<div class="admin-tabs">
<button class="admin-tab active" onclick="switchAdminTab('users',this)">👥 کاربران</button>
<button class="admin-tab" onclick="switchAdminTab('chats',this)">💬 گروه‌ها و کانال‌ها</button>
<button class="admin-tab" onclick="switchAdminTab('wallet',this)">💰 کیف پول</button>
<button class="admin-tab" onclick="switchAdminTab('bot',this)">🤖 ربات خوش‌آمدگویی</button>
<button class="admin-tab" onclick="switchAdminTab('stats',this)">✨ آمار</button>
</div>
<div class="admin-panel active" id="adminPanel-users">
<div class="admin-toolbar">
<div class="section-title" style="margin:0;flex:none">👥 مدیریت کاربران</div>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
<input type="text" id="adminUserSearch" placeholder="جستجوی کاربر..." oninput="renderAdminUsers()">
<button class="btn btn-primary" onclick="openUserModal()">➕ کاربر جدید</button>
<button class="btn btn-secondary" onclick="loadAdminData()">🔄 بروزرسانی</button>
</div>
</div>
<div class="table-wrapper">
<table class="users-table">
<thead><tr><th>آیدی</th><th>نام</th><th>نقش</th><th>وضعیت</th><th>پرمیوم</th><th>کیف پول</th><th>جستجو</th><th>تاریخ ثبت</th><th>عملیات</th></tr></thead>
<tbody id="adminUsersTable"></tbody>
</table>
</div>
</div>
<div class="admin-panel" id="adminPanel-chats">
<div class="admin-toolbar">
<div class="section-title" style="margin:0;flex:none">💬 مدیریت گروه‌ها و کانال‌ها</div>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
<select id="adminChatFilter" onchange="renderAdminChats()" style="padding:11px 15px;border-radius:13px;border:1.5px solid var(--border);background:var(--input);color:var(--t1);outline:none">
<option value="all">همه</option>
<option value="group">فقط گروه‌ها</option>
<option value="channel">فقط کانال‌ها</option>
<option value="verified">فقط تایید شده</option>
</select>
<input type="text" id="adminChatSearch" placeholder="جستجوی چت..." oninput="renderAdminChats()">
<button class="btn btn-secondary" onclick="loadAdminChats()">🔄 بروزرسانی</button>
</div>
</div>
<div id="adminChatsList" style="display:flex;flex-direction:column;gap:8px"></div>
</div>
<div class="admin-panel" id="adminPanel-wallet">
<div class="section-title spc">💰 مدیریت کیف پول و نرخ تبدیل</div>
<div style="padding:16px;background:var(--input);border-radius:16px;border:1px solid var(--border);margin-bottom:20px">
<h4 style="font-size:14px;font-weight:800;margin-bottom:12px;color:var(--spc-green)">⚙️ تنظیم نرخ تبدیل SPC به تومان</h4>
<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
<div style="flex:1;min-width:200px">
<label style="display:block;margin-bottom:6px;font-size:12px;color:var(--t2);font-weight:700">ارزش هر 1 SPC (به تومان)</label>
<input type="number" id="adminExchangeRate" min="1" max="100000000" value="<?= htmlspecialchars($wallet_config['spc_to_toman']) ?>" style="width:100%;padding:12px 16px;border-radius:12px;border:1.5px solid var(--border);background:var(--panel-solid);color:var(--t1);outline:none;font-size:14px;direction:ltr;text-align:left">
</div>
<button class="btn btn-spc" onclick="saveExchangeRate()">💾 ذخیره نرخ</button>
</div>
<div class="small-note" style="margin-top:10px">این نرخ برای نمایش معادل تومانی موجودی کاربران استفاده می‌شود.</div>
</div>
<div style="padding:16px;background:var(--input);border-radius:16px;border:1px solid var(--border)">
<h4 style="font-size:14px;font-weight:800;margin-bottom:12px;color:var(--spc-green)">💳 مدیریت موجودی کاربران</h4>
<div class="small-note" style="margin-bottom:12px">برای افزایش یا کاهش موجودی هر کاربر، از دکمه "✏️ ویرایش" در جدول کاربران استفاده کنید.</div>
</div>
</div>
<div class="admin-panel" id="adminPanel-bot">
<div class="section-title">🤖 ویرایش ربات خوش‌آمدگویی</div>
<div id="botEditArea">
<div style="padding:24px;text-align:center;color:var(--t2)">در حال بارگذاری...</div>
</div>
</div>
<div class="admin-panel" id="adminPanel-stats">
<div class="section-title">✨ آمار کلی</div>
<div class="stats-grid" id="statsGrid"></div>
</div>
</div>
</div>
<div class="modal-overlay" id="adminUserModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">👤</div>
<h3 id="adminUserModalTitle">ویرایش کاربر</h3>
<button class="icon-btn" onclick="closeModal('adminUserModal')">✖️</button>
</div>
<input type="hidden" id="adminUserId">
<div class="modal-field"><label>@ آیدی</label><input type="text" id="adminUserUsername" style="direction:ltr;text-align:left"></div>
<div class="modal-field"><label>نام نمایشی</label><input type="text" id="adminUserName"></div>
<div class="modal-field"><label>بیو</label><textarea id="adminUserBio"></textarea></div>
<div class="modal-field"><label id="adminPasswordLabel">🔑 رمز عبور</label><input type="text" id="adminUserPassword" style="direction:ltr;text-align:left"></div>
<div class="modal-field checkbox-row">
<label class="checkbox-item"><input type="checkbox" id="adminUserActive"> فعال</label>
<label class="checkbox-item"><input type="checkbox" id="adminUserBlocked"> مسدود</label>
<label class="checkbox-item"><input type="checkbox" id="adminUserIsAdmin"> ادمین</label>
<label class="checkbox-item"><input type="checkbox" id="adminUserSearchable"> قابل جستجو</label>
<label class="checkbox-item"><input type="checkbox" id="adminUserVerified"> تیک تایید</label>
</div>
<div class="section-title vip">⭐ اشتراک پرمیوم VIP</div>
<div id="adminPremiumStatus" style="margin-bottom:12px"></div>
<div class="modal-field">
<label>عملیات پرمیوم</label>
<select id="adminPremiumAction" onchange="updatePremiumActionUI()" style="padding:12px 16px;border-radius:14px;border:1.5px solid var(--border);background:var(--input);color:var(--t1);outline:none;font-size:14px">
<option value="keep">بدون تغییر</option>
<option value="add_days">افزودن روز به اشتراک فعلی</option>
<option value="set_days">تنظیم تعداد روز (از امروز)</option>
<option value="remove">لغو اشتراک پرمیوم</option>
</select>
</div>
<div class="modal-field" id="adminPremiumDaysField">
<label>تعداد روز (0 تا 3650)</label>
<input type="number" id="adminPremiumDays" min="0" max="3650" value="30" style="direction:ltr;text-align:left">
<div class="small-note">برای اشتراک دائمی عدد بزرگ مثل 3650 وارد کنید</div>
</div>
<div class="section-title spc">💰 مدیریت کیف پول</div>
<div id="adminWalletStatus" style="margin-bottom:12px"></div>
<div class="modal-field">
<label>عملیات کیف پول</label>
<select id="adminWalletAction" style="padding:12px 16px;border-radius:14px;border:1.5px solid var(--border);background:var(--input);color:var(--t1);outline:none;font-size:14px">
<option value="keep">بدون تغییر</option>
<option value="add">افزایش موجودی</option>
<option value="subtract">کاهش موجودی</option>
<option value="set">تنظیم موجودی</option>
</select>
</div>
<div class="modal-field">
<label>مقدار SPC</label>
<input type="number" id="adminWalletAmount" min="0" max="100000000" value="0" style="direction:ltr;text-align:left">
<div class="small-note">برای "بدون تغییر" این فیلد نادیده گرفته می‌شود</div>
</div>
<div class="modal-actions">
<button class="btn btn-secondary" onclick="closeModal('adminUserModal')">انصراف</button>
<button class="btn btn-primary" onclick="saveAdminUser()">💾 ذخیره</button>
</div>
</div>
</div>
<div class="modal-overlay" id="attachModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon" id="attachIcon">📄</div>
<h3 id="attachModalTitle">ارسال فایل</h3>
<button class="icon-btn" onclick="cancelAttachment()">✖️</button>
</div>
<div class="attachment-preview" id="attachPreview"></div>
<div class="file-meta" id="attachMeta"></div>
<div class="modal-field" style="margin-top:14px">
<label>کپشن / توضیحات</label>
<textarea id="attachCaption" placeholder="توضیحاتی برای این فایل بنویسید..." rows="3"></textarea>
</div>
<div class="modal-actions">
<button class="btn btn-secondary" onclick="cancelAttachment()">انصراف</button>
<button class="btn btn-primary" onclick="sendAttachment()">➤ ارسال</button>
</div>
</div>
</div>
<div class="modal-overlay" id="membersModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">👥</div>
<h3 id="membersTitle">اعضا</h3>
<button class="icon-btn" onclick="closeModal('membersModal')">✖️</button>
</div>
<div id="membersList" style="max-height:400px;overflow-y:auto;display:flex;flex-direction:column;gap:4px"></div>
<div class="modal-actions">
<button class="btn btn-secondary" onclick="closeModal('membersModal')">بستن</button>
<button class="btn btn-primary" id="addMemberBtnInModal" onclick="openAddMembersModal()" style="display:none">➕ افزودن عضو</button>
</div>
</div>
</div>
<div class="modal-overlay" id="addMembersModal">
<div class="modal">
<div class="modal-head">
<div class="modal-head-icon">➕</div>
<h3>افزودن عضو</h3>
<button class="icon-btn" onclick="closeModal('addMembersModal')">✖️</button>
</div>
<div class="modal-field">
<label>انتخاب کاربران</label>
<input type="text" id="addMemberSearch" placeholder="جستجوی آیدی یا نام کاربر..." oninput="searchAddMembers()">
<div class="selected-chips" id="addMemberChips"></div>
<div class="users-list" id="addMembersList" style="margin-top:8px"></div>
</div>
<div class="modal-actions">
<button class="btn btn-secondary" onclick="closeModal('addMembersModal')">انصراف</button>
<button class="btn btn-primary" onclick="submitAddMembers()">➕ افزودن</button>
</div>
</div>
</div>
<div class="toast" id="toast"></div>
<script>
const currentUser = <?= json_encode($safe_user, JSON_UNESCAPED_UNICODE) ?>;
const LOGO_URL = <?= json_encode($LOGO_URL) ?>;
const NORMAL_MAX_UPLOAD_MB = <?= NORMAL_MAX_UPLOAD_MB ?>;
const PREMIUM_MAX_UPLOAD_MB = <?= PREMIUM_MAX_UPLOAD_MB ?>;
const NORMAL_BIO_MAX = <?= NORMAL_BIO_MAX ?>;
const PREMIUM_BIO_MAX = <?= PREMIUM_BIO_MAX ?>;
const VIP_TICK_URL = 'https://chat.spotera.ir/tick/vip1.png';
const VERIFIED_TICK_URL = 'https://chat.spotera.ir/tick/icons8-tick-94.png';
const ALLOWED_PREMIUM_COLORS = ['', '#FFD700', '#FF6B6B', '#4ECDC4', '#A78BFA', '#F59E0B', '#EC4899', '#10B981', '#3B82F6'];
const ALLOWED_MSG_COLORS = ['', '#FF6B6B', '#4ECDC4', '#A78BFA', '#F59E0B', '#EC4899', '#10B981', '#3B82F6', '#FFD700', '#FF1493', '#00CED1', '#9370DB'];
let spcToToman = <?= $wallet_config['spc_to_toman'] ?>;
function getVerifiedBadge() {
return `<span class="verified-badge" onclick="event.stopPropagation();showToast('این صفحه تایید شده است')"><img src="${VERIFIED_TICK_URL}" alt="تایید شده"></span>`;
}
function getVipBadge(animated = true) {
return `<span class="vip-badge ${animated ? 'animated' : ''}" onclick="event.stopPropagation();showToast('🌟 کاربر پرمیوم VIP')" title="کاربر پرمیوم"><img src="${VIP_TICK_URL}" alt="VIP"></span>`;
}
function badgesHtml(user) {
let html = '';
if (user.verified) html += ' ' + getVerifiedBadge();
if (user.premium) html += ' ' + getVipBadge(true);
return html;
}
function formatNumber(num) {
return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
function formatToman(amount) {
return formatNumber(amount) + ' تومان';
}
let chats = [];
let currentChat = null;
let currentMessages = [];
let selectedMembers = [];
let selectedMembersData = {};
let addMembersList = [];
let addMembersSelected = [];
let currentCreateType = 'private';
let fabOpen = false;
let attachOpen = false;
let adminStats = null;
let adminUsers = [];
let adminChats = [];
let adminBot = null;
let pendingFile = null;
let pendingObjectUrl = null;
let lastRenderKey = '';
let lastMessageCount = 0;
let viewProfileTarget = null;
let forwardTargetMsg = null;
let replyingTo = null;
let liveState = null;
let liveStopped = false;
let liveRunning = false;
let knownMessageIds = new Set();
let selectedPremiumColor = currentUser.premium_color || '';
let selectedMsgColor = '';
function initTheme() {
const saved = localStorage.getItem('theme') || 'dark';
document.body.dataset.theme = saved;
document.getElementById('themeBtn').textContent = saved === 'dark' ? '☀️' : '🌙';
}
function toggleTheme() {
const cur = document.body.dataset.theme;
const next = cur === 'dark' ? 'light' : 'dark';
document.body.dataset.theme = next;
localStorage.setItem('theme', next);
document.getElementById('themeBtn').textContent = next === 'dark' ? '☀️' : '🌙';
}
function showToast(msg) {
const t = document.getElementById('toast');
t.textContent = msg;
t.className = 'toast show';
setTimeout(() => t.className = 'toast', 3000);
}
function escapeHtml(s) {
if (!s) return '';
return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}
function formatTime(ts) { return new Date(ts * 1000).toLocaleTimeString('fa-IR', {hour: '2-digit', minute: '2-digit'}); }
function formatDate(ts) { return new Date(ts * 1000).toLocaleDateString('fa-IR'); }
function formatDateTime(ts) {
if (!ts) return '-';
return new Date(ts * 1000).toLocaleString('fa-IR', {year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'});
}
function formatBytes(bytes) { if (bytes === 0) return '0 B'; const sizes = ['B','KB','MB','GB']; const i = Math.floor(Math.log(bytes) / Math.log(1024)); return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]; }
function resolveFileUrl(path) {
if (!path) return '';
if (/^https?:\/\//i.test(path)) return path;
return '?action=serve_file&p=' + encodeURIComponent(String(path).replace(/^\/+/, ''));
}
function linkify(text) {
if (!text) return '';
let html = escapeHtml(text);
return html.replace(/(https?:\/\/[^\s<>"']+|www\.[^\s<>"']+)/gi, function(url) {
const full = /^https?:\/\//i.test(url) ? url : 'https://' + url;
return '<a href="' + escapeHtml(full) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(url) + '</a>';
});
}
function typeIcon(type) {
if (type === 'group') return '👥';
if (type === 'channel') return '📣';
if (type === 'saved') return '🔖';
return '';
}
function getAvatarHTML(displayName, avatarPath) {
if (avatarPath) return '<img src="' + escapeHtml(resolveFileUrl(avatarPath)) + '" alt="">';
return escapeHtml((displayName || '?')[0].toUpperCase());
}
function avatarClass(type) {
if (type === 'group') return 'chat-avatar group';
if (type === 'channel') return 'chat-avatar channel';
if (type === 'saved') return 'chat-avatar saved';
return 'chat-avatar';
}
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function openModal(id) { document.getElementById(id).classList.add('active'); }
function toggleFab() {
const container = document.getElementById('fabContainer');
fabOpen = !fabOpen;
container.classList.toggle('open', fabOpen);
}
function closeFab() {
fabOpen = false;
document.getElementById('fabContainer').classList.remove('open');
}
function toggleAttach() {
const pop = document.getElementById('attachPop');
attachOpen = !attachOpen;
pop.classList.toggle('active', attachOpen);
}
function closeAttach() {
attachOpen = false;
document.getElementById('attachPop').classList.remove('active');
}
function pickAttach(type) {
closeAttach();
if (type === 'image') document.getElementById('imageInput').click();
else if (type === 'video') document.getElementById('videoInput').click();
else if (type === 'voice') { closeAttach(); requestMicPermission(); return; }
else document.getElementById('fileInput').click();
}
function updateTotalNotifBadge() {
const total = chats.reduce((sum, c) => sum + (c.type === 'saved' ? 0 : (c.unread_count || 0)), 0);
const badge = document.getElementById('totalNotifBadge');
if (total > 0) {
badge.textContent = total > 99 ? '99+' : total;
badge.style.display = 'inline-flex';
} else {
badge.style.display = 'none';
}
document.title = total > 0 ? '(' + total + ') پیام‌رسان' : 'پیام‌رسان';
}
function renderMsgColorPicker() {
if (!currentUser.premium) {
document.getElementById('msgColorPicker').style.display = 'none';
return;
}
const picker = document.getElementById('msgColorPicker');
let html = '<div class="msg-color-picker"><label>🎨 رنگ متن:</label>';
html += `<div class="msg-color-option none ${selectedMsgColor === '' ? 'selected' : ''}" onclick="selectMsgColor('')" title="بدون رنگ">×</div>`;
ALLOWED_MSG_COLORS.filter(c => c !== '').forEach(c => {
html += `<div class="msg-color-option ${selectedMsgColor === c ? 'selected' : ''}" style="background:${c}" onclick="selectMsgColor('${c}')" title="${c}"></div>`;
});
html += '</div>';
picker.innerHTML = html;
picker.style.display = 'block';
}
function selectMsgColor(color) {
selectedMsgColor = color;
renderMsgColorPicker();
}
function buildChatItemHtml(c) {
let avType = '';
if (c.type === 'saved') avType = 'saved';
else if (c.type === 'group') avType = 'group';
else if (c.type === 'channel') avType = 'channel';
const avatarHTML = c.type === 'saved' ? '🔖' : getAvatarHTML(c.display_name, c.avatar_path);
const preview = c.last_message || (c.type === 'saved' ? 'فضای شخصی شما' : 'هنوز پیامی نیست');
const tick = c.last_is_mine && c.type !== 'saved'
? '<span class="ticks ' + (c.last_seen ? 'seen' : '') + '">' + (c.last_seen ? '✔✔' : '✔') + '</span>'
: '';
const unread = (c.unread_count > 0 && c.type !== 'saved')
? '<div class="unread-badge">' + (c.unread_count > 99 ? '99+' : c.unread_count) + '</div>'
: '';
let verifiedHtml = '';
if (c.type === 'private') {
if (c.other_verified) verifiedHtml += ' ' + getVerifiedBadge();
if (c.other_premium) verifiedHtml += ' ' + getVipBadge(true);
} else if (c.type === 'group' || c.type === 'channel') {
if (c.verified) verifiedHtml += ' ' + getVerifiedBadge();
}
return `
<div class="${avatarClass(avType)}">${avatarHTML}</div>
<div class="chat-info">
<div class="chat-top">
<div class="chat-name">${typeIcon(c.type)} ${escapeHtml(c.display_name || 'نامشخص')}${verifiedHtml}</div>
<div class="chat-time">${formatTime(c.last_time || c.created_at)}</div>
</div>
<div class="chat-preview-wrap">
<div class="chat-preview">${tick} ${escapeHtml(preview)}</div>
${unread}
</div>
</div>
</div>
`;
}
function chatSignature(c) {
return c.last_time + '|' + c.unread_count + '|' + (c.last_message||'') + '|' + c.last_seen + '|' + c.other_online + '|' + c.online_members_count + '|' + c.display_name + '|' + c.avatar_path + '|' + (c.other_premium||'') + '|' + (c.other_verified||'');
}
async function loadChats(forceFull = false) {
try {
const res = await fetch('?action=get_chats', {cache: 'no-store'});
const data = await res.json();
const newChats = data.chats || [];
const oldMap = new Map(chats.map(c => [c.id, c]));
const newMap = new Map(newChats.map(c => [c.id, c]));
let listChanged = chats.length !== newChats.length;
for (const nc of newChats) {
const oc = oldMap.get(nc.id);
if (!oc || chatSignature(oc) !== chatSignature(nc)) {
listChanged = true;
break;
}
}
if (!listChanged) {
for (const oc of chats) {
if (!newMap.has(oc.id)) { listChanged = true; break; }
}
}
chats = newChats;
updateTotalNotifBadge();
if (forceFull || listChanged) {
renderChats();
}
if (currentChat) {
const active = chats.find(c => c.id === currentChat.id);
if (active) {
currentChat = {...currentChat, ...active};
renderChatHeader();
if (active.unread_count > 0 && document.hasFocus()) {
markChatRead(active.id);
}
}
}
} catch (e) {
console.error(e);
}
}
function renderChats() {
const list = document.getElementById('chatList');
const q = document.getElementById('searchChats').value.trim().toLowerCase();
const filtered = q ? chats.filter(c => (c.display_name || '').toLowerCase().includes(q)) : chats;
if (!filtered.length) {
list.innerHTML = '<div style="padding:34px 20px;text-align:center;color:var(--t2)"><div style="font-size:34px;margin-bottom:10px">💬</div><div style="font-size:13px">چتی یافت نشد</div></div>';
return;
}
const saved = filtered.filter(c => c.type === 'saved');
const others = filtered.filter(c => c.type !== 'saved');
const ordered = saved.concat(others);
list.innerHTML = ordered.map(c => {
return `
<div class="chat-item ${currentChat?.id === c.id ? 'active' : ''}" data-chat-id="${c.id}" onclick="openChat('${c.id}')">
${buildChatItemHtml(c)}
</div>
`;
}).join('');
}
function filterChats() { renderChats(); }
async function openChat(chatId) {
currentChat = chats.find(c => c.id === chatId) || {id: chatId};
renderChats();
document.getElementById('app').classList.add('show-chat');
document.getElementById('emptyState').style.display = 'none';
document.getElementById('chatContainer').style.display = 'flex';
closeFab();
closeAttach();
knownMessageIds = new Set();
lastRenderKey = '';
lastMessageCount = 0;
selectedMsgColor = '';
try {
const res = await fetch('?action=get_messages&chat_id=' + encodeURIComponent(chatId), {cache: 'no-store'});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
currentChat = {...currentChat, ...data.chat};
renderChatHeader();
const messages = data.messages || [];
currentMessages = messages;
messages.forEach(m => knownMessageIds.add(m.id));
const container = document.getElementById('messages');
const q = document.getElementById('chatSearchInput')?.value.trim().toLowerCase();
const renderList = q ? messages.filter(m => {
const text = m.file_path ? (m.caption || m.file_name || '') : (m.text || '');
return text.toLowerCase().includes(q) || (m.username || '').toLowerCase().includes(q);
}) : messages;
container.innerHTML = renderList.map(renderMessage).join('');
container.scrollTop = container.scrollHeight;
lastMessageCount = messages.length;
lastRenderKey = messages.map(m => m.id + (m.edited ? 'e' : '') + (m.is_pinned ? 'p' : '') + JSON.stringify(m.reactions || {}) + (m.seen_by || []).join(',') + (m.message_color || '')).join('|');
updatePinnedMessage(messages);
updateInputState();
renderMsgColorPicker();
markChatRead(chatId);
const input = document.getElementById('messageInput');
input.value = loadDraft(chatId);
if (input.value) {
input.style.height = 'auto';
input.style.height = Math.min(input.scrollHeight, 120) + 'px';
}
} catch (e) {
console.error(e);
}
}
function closeChat() {
const input = document.getElementById('messageInput');
if (currentChat && input.value) {
saveDraft(currentChat.id, input.value);
} else if (currentChat) {
saveDraft(currentChat.id, '');
}
cancelReply();
document.getElementById('chatSearchBar').style.display = 'none';
document.getElementById('chatSearchInput').value = '';
document.getElementById('app').classList.remove('show-chat');
currentChat = null;
lastRenderKey = '';
lastMessageCount = 0;
knownMessageIds = new Set();
document.getElementById('chatContainer').style.display = 'none';
document.getElementById('emptyState').style.display = 'flex';
document.getElementById('msgColorPicker').style.display = 'none';
renderChats();
}
function updateInputState() {
const wrap = document.getElementById('messageInputWrap');
if (!currentChat) return;
const canSend = !(currentChat.type === 'channel' && currentChat.owner_id !== currentUser.id && !currentUser.is_admin);
wrap.style.display = canSend ? 'block' : 'none';
if (canSend) renderMsgColorPicker();
}
function renderChatHeader() {
const isSaved = currentChat.type === 'saved';
const displayName = isSaved
? (currentChat.name || 'پیام‌های ذخیره‌شده')
: (currentChat.type === 'private' ? (currentChat.display_name || '?') : (currentChat.name || '?'));
const avatarPath = currentChat.type === 'private' ? (currentChat.avatar_path || '') : (currentChat.avatar_image || '');
const avatarEl = document.getElementById('chatAvatar');
avatarEl.className = avatarClass(isSaved ? 'saved' : currentChat.type);
avatarEl.innerHTML = isSaved ? '🔖' : getAvatarHTML(displayName, avatarPath);
let nameHtml = escapeHtml(displayName);
if (currentChat.type === 'private') {
if (currentChat.other_verified) nameHtml += ' ' + getVerifiedBadge();
if (currentChat.other_premium) nameHtml += ' ' + getVipBadge(true);
} else if (currentChat.type === 'group' || currentChat.type === 'channel') {
if (currentChat.verified) nameHtml += ' ' + getVerifiedBadge();
}
document.getElementById('chatName').innerHTML = (isSaved ? '' : typeIcon(currentChat.type)) + ' ' + nameHtml;
const statusEl = document.getElementById('chatStatus');
if (isSaved) {
statusEl.textContent = 'فضای شخصی';
} else if (currentChat.type === 'private') {
statusEl.innerHTML = currentChat.other_online
? '<span class="online-dot"></span> آنلاین'
: 'آخرین بازدید: ' + (currentChat.other_last_seen_text || 'نامشخص');
} else {
const count = currentChat.online_members_count || 0;
statusEl.innerHTML = count > 0 ? '<span class="online-dot"></span> ' + count + ' عضو آنلاین' : 'هیچ عضوی آنلاین نیست';
}
document.getElementById('membersBtn').style.display = (currentChat.type === 'group' || currentChat.type === 'channel') ? 'flex' : 'none';
const info = document.getElementById('chatHeaderInfo');
if (currentChat.type === 'private' && currentChat.other_user_id) {
avatarEl.onclick = () => viewProfile(currentChat.other_user_id);
info.onclick = () => viewProfile(currentChat.other_user_id);
} else if (currentChat.type === 'group' || currentChat.type === 'channel') {
avatarEl.onclick = () => openChatProfile();
info.onclick = () => openChatProfile();
} else {
avatarEl.onclick = null;
info.onclick = null;
}
}
function updatePinnedMessage(messages) {
const pinnedMsg = messages.filter(m => m.is_pinned).pop();
const pinnedEl = document.getElementById('pinnedMessage');
if (pinnedMsg) {
const text = pinnedMsg.file_path ? (pinnedMsg.caption || pinnedMsg.file_name || '📎 فایل') : (pinnedMsg.text || '');
pinnedEl.innerHTML = '<div style="display:flex;align-items:center;gap:8px"><span style="font-size:16px">📌</span><div style="flex:1;min-width:0"><div style="font-size:11px;color:var(--accent);font-weight:800">پیام سنجاق شده</div><div style="font-size:12.5px;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(text) + '</div></div></div>';
pinnedEl.dataset.msgId = pinnedMsg.id;
pinnedEl.style.display = 'block';
} else {
pinnedEl.style.display = 'none';
}
}
function updateExistingMessageNode(m) {
const existing = document.querySelector(`.message[data-id="${m.id}"]`);
if (!existing) return false;
const fresh = renderMessage(m);
const temp = document.createElement('div');
temp.innerHTML = fresh.trim();
const newNode = temp.firstChild;
if (!newNode) return false;
existing.replaceWith(newNode);
return true;
}
function renderMessages(messages, force = false) {
stopAllVoices();
const key = (messages || []).map(m => m.id + (m.edited ? 'e' : '') + (m.is_pinned ? 'p' : '') + JSON.stringify(m.reactions || {}) + (m.seen_by || []).join(',') + (m.message_color || '')).join('|');
if (!force && key === lastRenderKey) return;
lastRenderKey = key;
lastMessageCount = messages.length;
currentMessages = messages;
const container = document.getElementById('messages');
const q = document.getElementById('chatSearchInput')?.value.trim().toLowerCase();
if (q) {
const filtered = messages.filter(m => {
const text = m.file_path ? (m.caption || m.file_name || '') : (m.text || '');
return text.toLowerCase().includes(q) || (m.username || '').toLowerCase().includes(q);
});
container.innerHTML = filtered.map(renderMessage).join('');
} else {
const existingIds = new Set();
container.querySelectorAll('.message[data-id]').forEach(n => existingIds.add(n.dataset.id));
const incomingIds = new Set(messages.map(m => m.id));
const newMessages = [];
const toUpdate = [];
for (const m of messages) {
if (existingIds.has(m.id)) {
toUpdate.push(m);
} else {
newMessages.push(m);
}
knownMessageIds.add(m.id);
}
for (const id of existingIds) {
if (!incomingIds.has(id)) {
const node = container.querySelector(`.message[data-id="${id}"]`);
if (node) node.remove();
}
}
for (const m of toUpdate) {
updateExistingMessageNode(m);
}
if (newMessages.length > 0) {
const wasAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 80;
const fragment = document.createDocumentFragment();
for (const m of newMessages) {
const temp = document.createElement('div');
temp.innerHTML = renderMessage(m).trim();
const node = temp.firstChild;
if (node) {
node.classList.add('flash');
fragment.appendChild(node);
}
}
container.appendChild(fragment);
if (wasAtBottom || force) {
requestAnimationFrame(() => {
container.scrollTop = container.scrollHeight;
});
}
}
}
updatePinnedMessage(messages);
}
function renderMessage(m) {
const isMe = m.user_id === currentUser.id;
const isSaved = currentChat?.type === 'saved';
const members = currentChat?.members || [];
let fileHtml = '';
if (m.file_path) {
const fileUrl = escapeHtml(resolveFileUrl(m.file_path));
if (m.file_type === 'image') {
fileHtml = '<div class="message-file"><img src="' + fileUrl + '" alt="" style="cursor:pointer" onclick="window.open(this.src,\'_blank\')" onerror="imgError(this)"></div>';
} else if (m.file_type === 'video') {
fileHtml = '<div class="message-file"><video src="' + fileUrl + '" controls preload="metadata" playsinline></video></div>';
} else if (m.file_type === 'voice') {
const durSecs = parseInt(m.voice_duration || '0', 10) || 0;
let bars = [];
if (typeof m.waveform === 'string' && m.waveform !== '') bars = m.waveform.split(':');
else if (Array.isArray(m.waveform)) bars = m.waveform;
let waveBars = '';
for (let wi = 0; wi < 28; wi++) {
const wv = bars.length ? (bars[wi % bars.length] || 30) : (26 + ((wi * 37 + durSecs * 13) % 58));
waveBars += '<span style="height:' + Math.max(14, Math.min(100, wv)) + '%"></span>';
}
const mm = Math.floor(durSecs / 60), ss = durSecs % 60;
const durTxt = durSecs > 0 ? (mm + ':' + (ss < 10 ? '0' : '') + ss) : '';
const dlUrl = fileUrl + '&dl=1';
const vHeadHtml = '<div class="voice-head"><span class="voice-mic-badge">🎙</span><span>ویس</span><span class="voice-eq" aria-hidden="true"><i style="--i:0"></i><i style="--i:1"></i><i style="--i:2"></i><i style="--i:3"></i></span><button type="button" class="voice-speed-btn" id="vspd_' + m.id + '" data-msg="' + m.id + '" onclick="cycleVoiceSpeed(this.dataset.msg)" title="تغییر سرعت پخش">1x</button></div>';
const vTrackHtml = '<div class="voice-progress-track"><div class="voice-progress-fill" id="vprog_' + m.id + '"></div></div>';
const vWasPlayed = playedVoices.has(m.id);
fileHtml = '<div class="message-file voice-bubble" id="vbub_' + m.id + '">' +
'<button type="button" class="voice-play-btn" id="vpb_' + m.id + '" onclick="toggleVoicePlay(\'' + m.id + '\', \'' + fileUrl + '\', this)" title="پخش / توقف">▶</button>' +
'<div class="voice-body">' +
vHeadHtml +
'<div class="voice-waveform" id="vwf_' + m.id + '" onclick="seekVoice(event, \'' + m.id + '\')">' + waveBars + '</div>' +
vTrackHtml +
'<div class="voice-bottom"><span class="voice-duration" id="vdur_' + m.id + '">⏱ ' + durTxt + '</span><span class="voice-listen-hint" id="vhint_' + m.id + '">' + (vWasPlayed ? '✓ شنیده شده' : '● پخش نشده') + '</span></div>' +
'</div>' +
'<a class="voice-dl" href="' + dlUrl + '" target="_blank" title="دانلود ویس">⬇</a>' +
'<audio id="vaudio_' + m.id + '" src="" preload="none" data-src="' + fileUrl + '"></audio>' +
'</div>';
if (vWasPlayed && typeof document !== 'undefined') { setTimeout(() => { try { const hel = document.getElementById('vhint_' + m.id); if (hel) hel.classList.add('listened'); } catch (e) {} }, 0); }
} else {
fileHtml = '<div class="message-file"><a class="file-chip" href="' + fileUrl + '" target="_blank">📄 ' + escapeHtml(m.file_name || 'فایل') + '</a></div>';
}
}
const text = m.file_path ? (m.caption || '') : (m.text || '');
const msgColor = m.message_color || '';
const textStyle = msgColor ? ' style="color:' + escapeHtml(msgColor) + '"' : '';
const textHtml = text ? '<div class="message-text"' + textStyle + '>' + linkify(text) + '</div>' : '';
const canDelete = isMe || currentUser.is_admin || (currentChat && currentChat.owner_id === currentUser.id);
const seen = (m.seen_by || []).some(id => id !== currentUser.id && members.includes(id));
const tickHtml = isMe && !isSaved
? '<span class="ticks ' + (seen ? 'seen' : '') + '">' + (seen ? '✔✔' : '✔') + '</span>'
: '';
let senderHtml = '';
if (!isMe && currentChat && currentChat.type !== 'private' && !isSaved) {
const senderNameColor = m.sender_premium_color || '';
const styleAttr = senderNameColor ? ' style="color:' + escapeHtml(senderNameColor) + '"' : '';
let senderBadges = '';
if (m.sender_verified) senderBadges += ' ' + getVerifiedBadge();
if (m.sender_premium) senderBadges += ' ' + getVipBadge(true);
senderHtml = '<div class="msg-sender" onclick="viewProfile(\'' + m.user_id + '\')"><span' + styleAttr + '>' + escapeHtml(m.username || '') + '</span>' + senderBadges + '</div>';
}
const actions = [];
if (!isSaved) actions.push('<button type="button" class="msg-action-inline" onclick="saveMessage(\'' + m.id + '\')">🔖 ذخیره</button>');
actions.push('<button type="button" class="msg-action-inline" onclick="setReply(\'' + m.id + '\')">↩️ پاسخ</button>');
actions.push('<button type="button" class="msg-action-inline" onclick="togglePin(\'' + m.id + '\')">📌 ' + (m.is_pinned ? 'برداشتن' : 'سنجاق') + '</button>');
actions.push('<button type="button" class="msg-action-inline" onclick="toggleReactionPicker(\'' + m.id + '\', this)">😊</button>');
actions.push('<button type="button" class="msg-action-inline" onclick="copyMessageText(\'' + m.id + '\')">📋</button>');
actions.push('<button type="button" class="msg-action-inline" onclick="openForwardModal(\'' + m.id + '\')">↪️</button>');
if (isMe) actions.push('<button type="button" class="msg-action-inline" onclick="editMessage(\'' + m.id + '\')">✏️ ویرایش</button>');
if (canDelete) actions.push('<button type="button" class="msg-action-inline danger" onclick="deleteMessage(\'' + m.id + '\')">🗑️ حذف</button>');
const savedFromHtml = (isSaved && m.saved_from) ? '<div class="saved-from">🔖 ذخیره‌شده از: ' + escapeHtml(m.saved_from) + '</div>' : '';
const forwardedFromHtml = m.forwarded_from ? '<div class="saved-from">↪️ فوروارد شده از: ' + escapeHtml(m.forwarded_from) + '</div>' : '';
const reactionsHtml = renderReactions(m);
let replyHtml = '';
if (m.reply_to) {
replyHtml = '<div style="border-right:3px solid var(--accent);padding:5px 10px;margin-bottom:6px;background:rgba(255,255,255,.05);border-radius:8px;cursor:pointer" onclick="scrollToMessage(\'' + m.reply_to.id + '\')"><div style="font-size:11px;font-weight:800;color:var(--accent);margin-bottom:2px">' + escapeHtml(m.reply_to.username || 'کاربر') + '</div><div style="font-size:12px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(m.reply_to.text || '') + '</div></div>';
}
return `
<div class="message ${isMe ? 'me' : 'other'}" data-id="${m.id}">
${savedFromHtml}
${forwardedFromHtml}
${senderHtml}
${replyHtml}
${fileHtml}
${textHtml}
${reactionsHtml}
<div class="message-meta">
${m.edited ? '<span>ویرایش شده</span>' : ''}
${tickHtml}
<span>${formatTime(m.created_at)}</span>
</div>
${actions.length ? '<div class="message-actions-inline">' + actions.join('') + '</div>' : ''}
</div>
`;
}
function renderReactions(m) {
const reactions = m.reactions || {};
const entries = Object.entries(reactions);
if (!entries.length) return '';
let html = '<div class="message-reactions">';
entries.forEach(([emoji, userIds]) => {
const isActive = userIds.includes(currentUser.id);
html += `<span class="reaction-chip ${isActive ? 'active' : ''}" onclick="reactToMessage('${m.id}', '${emoji}')">${emoji} ${userIds.length}</span>`;
});
html += '</div>';
return html;
}
function toggleReactionPicker(msgId, btn) {
let picker = document.getElementById('reactionPicker-' + msgId);
if (!picker) {
picker = document.createElement('div');
picker.id = 'reactionPicker-' + msgId;
picker.className = 'reaction-picker';
const emojis = ['👍','❤️','😂','😮','😢','🔥','🎉','👏','🤔','😍'];
picker.innerHTML = emojis.map(e => `<button type="button" onclick="reactToMessage('${msgId}', '${e}')">${e}</button>`).join('');
btn.parentElement.appendChild(picker);
}
const isActive = picker.classList.contains('active');
document.querySelectorAll('.reaction-picker.active').forEach(p => p.classList.remove('active'));
if (!isActive) picker.classList.add('active');
}
function closeReactionPickers() {
document.querySelectorAll('.reaction-picker.active').forEach(p => p.classList.remove('active'));
}
async function reactToMessage(msgId, emoji) {
const formData = new FormData();
formData.append('message_id', msgId);
formData.append('emoji', emoji);
try {
const res = await fetch('?action=react_to_message', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) { showToast(data.error); return; }
closeReactionPickers();
refreshMessages();
} catch (e) { showToast('خطا در ثبت واکنش'); }
}
function copyMessageText(msgId) {
const msg = currentMessages.find(m => m.id === msgId);
if (!msg) return;
const text = msg.file_path ? (msg.caption || '') : (msg.text || '');
if (!text) { showToast('متنی برای کپی وجود ندارد'); return; }
navigator.clipboard.writeText(text).then(() => {
showToast('متن کپی شد');
}).catch(() => {
showToast('خطا در کپی کردن');
});
}
function openForwardModal(msgId) {
forwardTargetMsg = currentMessages.find(m => m.id === msgId);
if (!forwardTargetMsg) return;
document.getElementById('forwardSearch').value = '';
renderForwardChats();
openModal('forwardModal');
}
function renderForwardChats() {
const q = document.getElementById('forwardSearch').value.trim().toLowerCase();
const list = document.getElementById('forwardChatsList');
const filtered = q ? chats.filter(c => (c.display_name || c.name || '').toLowerCase().includes(q) && c.type !== 'saved') : chats.filter(c => c.type !== 'saved');
if (!filtered.length) {
list.innerHTML = '<div style="padding:16px;text-align:center;color:var(--t2);font-size:13px">چتی یافت نشد</div>';
return;
}
list.innerHTML = filtered.map(c => {
const avatarPath = c.type === 'private' ? (c.avatar_path || '') : (c.avatar_image || '');
const avatarHtml = c.type === 'saved' ? '🔖' : getAvatarHTML(c.display_name || c.name, avatarPath);
const displayName = c.display_name || c.name || 'چت';
const meta = c.type === 'private' ? '@' + escapeHtml(c.other_username || '') : (c.type === 'channel' ? '📣 کانال' : '👥 گروه');
return `
<label class="user-option" onclick="submitForward('${c.id}')">
<div class="user-option-avatar">${avatarHtml}</div>
<div class="user-option-info">
<div class="user-option-name">${escapeHtml(displayName)}</div>
<div class="user-option-username">${meta}</div>
</div>
</label>
`;
}).join('');
}
async function submitForward(chatId) {
if (!forwardTargetMsg) return;
const formData = new FormData();
formData.append('chat_id', chatId);
formData.append('message_id', forwardTargetMsg.id);
try {
const res = await fetch('?action=forward_message', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) { showToast(data.error); return; }
showToast('پیام فوروارد شد');
closeModal('forwardModal');
await loadChats();
if (currentChat && currentChat.id === chatId) {
await refreshMessages();
}
} catch (e) { showToast('خطا در فوروارد'); }
}
function imgError(img) {
const a = document.createElement('a');
a.className = 'file-chip';
a.href = img.src;
a.target = '_blank';
a.textContent = '📎 مشاهده فایل';
img.replaceWith(a);
}
async function markChatRead(chatId) {
try {
const formData = new FormData();
formData.append('chat_id', chatId);
await fetch('?action=mark_chat_read', {method: 'POST', body: formData});
const c = chats.find(x => x.id === chatId);
if (c) {
c.unread_count = 0;
renderChats();
updateTotalNotifBadge();
}
} catch (e) {}
}
async function refreshMessages(forceFull = false) {
if (!currentChat) return;
try {
const res = await fetch('?action=get_messages&chat_id=' + encodeURIComponent(currentChat.id), {cache: 'no-store'});
const data = await res.json();
if (data.error) return;
currentChat = {...currentChat, ...data.chat};
renderChatHeader();
renderMessages(data.messages || [], forceFull);
updateInputState();
} catch (e) {}
}
async function sendMessage() {
const input = document.getElementById('messageInput');
const text = input.value.trim();
if (!text || !currentChat) return;
const formData = new FormData();
formData.append('chat_id', currentChat.id);
formData.append('text', text);
if (currentUser.premium && selectedMsgColor) {
formData.append('message_color', selectedMsgColor);
}
if (replyingTo) {
formData.append('reply_to_id', replyingTo.id);
cancelReply();
}
input.value = '';
input.style.height = 'auto';
saveDraft(currentChat.id, '');
try {
const res = await fetch('?action=send_message', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
await refreshMessages();
await loadChats();
} catch (e) {
showToast('خطا در ارسال پیام');
}
}
function openAttachmentModal(file, type) {
const maxMb = currentUser.premium ? PREMIUM_MAX_UPLOAD_MB : NORMAL_MAX_UPLOAD_MB;
const maxBytes = maxMb * 1024 * 1024;
if (file.size > maxBytes) {
showToast('حجم فایل بیشتر از ' + maxMb + ' مگابایت است' + (currentUser.premium ? '' : ' (پرمیوم: ' + PREMIUM_MAX_UPLOAD_MB + 'MB)'));
return;
}
pendingFile = file;
const preview = document.getElementById('attachPreview');
const meta = document.getElementById('attachMeta');
const title = document.getElementById('attachModalTitle');
const icon = document.getElementById('attachIcon');
preview.innerHTML = '';
meta.innerHTML = '📄 ' + escapeHtml(file.name) + ' • ' + formatBytes(file.size);
if (type === 'image') { title.textContent = 'ارسال تصویر'; icon.textContent = '🖼️'; }
else if (type === 'video') { title.textContent = 'ارسال ویدیو'; icon.textContent = '🎬'; }
else if (type === 'voice') { title.textContent = 'ارسال پیام صوتی'; icon.textContent = '🎙️'; }
else { title.textContent = 'ارسال فایل'; icon.textContent = '📄'; }
if (pendingObjectUrl) {
URL.revokeObjectURL(pendingObjectUrl);
pendingObjectUrl = null;
}
if (file.type.startsWith('image/')) {
pendingObjectUrl = URL.createObjectURL(file);
preview.innerHTML = '<img src="' + pendingObjectUrl + '" alt="">';
} else if (file.type.startsWith('video/')) {
pendingObjectUrl = URL.createObjectURL(file);
preview.innerHTML = '<video src="' + pendingObjectUrl + '" controls playsinline></video>';
} else if (type === 'voice' || file.type.startsWith('audio/')) {
pendingObjectUrl = URL.createObjectURL(file);
let vBars = '';
for (let i = 0; i < 24; i++) vBars += '<span style="height:' + (25 + ((i * 53 + file.size) % 60)) + '%"></span>';
preview.innerHTML = '<div class="voice-bubble attach-voice"><span class="voice-play-btn" style="pointer-events:none">▶</span><div class="voice-body"><div class="voice-waveform static">' + vBars + '</div><div class="voice-bottom"><span class="voice-duration">در حال پخش...</span></div></div><audio src="' + pendingObjectUrl + '" controls preload="metadata" style="display:none"></audio></div>';
const pvAudio = preview.querySelector('audio');
if (pvAudio) {
pvAudio.addEventListener('loadedmetadata', () => {
const d = Math.floor(pvAudio.duration || 0);
const el = preview.querySelector('.voice-duration');
if (el && d > 0) el.textContent = Math.floor(d / 60) + ':' + String(d % 60).padStart(2, '0');
}, {once: true});
}
} else {
preview.innerHTML = '<div style="text-align:center"><div style="font-size:52px">📄</div><div style="font-size:13px;margin-top:10px">' + escapeHtml(file.name) + '</div></div>';
}
document.getElementById('attachCaption').value = '';
openModal('attachModal');
}
function cancelAttachment() {
pendingFile = null;
if (pendingObjectUrl) { URL.revokeObjectURL(pendingObjectUrl); pendingObjectUrl = null; }
document.getElementById('attachPreview').innerHTML = '';
document.getElementById('attachMeta').textContent = '';
document.getElementById('attachCaption').value = '';
closeModal('attachModal');
}
async function sendAttachment() {
if (!pendingFile || !currentChat) return;
const caption = document.getElementById('attachCaption').value.trim();
const formData = new FormData();
formData.append('chat_id', currentChat.id);
formData.append('caption', caption);
formData.append('file', pendingFile);
if (pendingFile.type.startsWith('audio/')) {
const vDur = await getAudioDuration(pendingFile);
if (vDur > 0) formData.append('voice_duration', String(Math.round(vDur)));
}
saveDraft(currentChat.id, '');
try {
const res = await fetch('?action=send_message', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
cancelAttachment();
await refreshMessages();
await loadChats();
} catch (e) {
showToast('خطا در ارسال فایل');
}
}
// ==================== سیستم ویس (پیام صوتی) ====================
let voiceRecorder = null;
let voiceStream = null;
let voiceChunks = [];
let voiceTimerInt = null;
let voiceStartTs = 0;
let voiceRafId = null;
let voiceAudioCtx = null;
let voiceAnalyser = null;
let voiceWaveBars = [];
let currentVoiceAudio = null;
const playedVoices = new Set();
function getAudioDuration(fileOrBlob) {
return new Promise((resolve) => {
const url = URL.createObjectURL(fileOrBlob);
const a = new Audio();
a.preload = 'metadata';
a.onloadedmetadata = () => { const d = isFinite(a.duration) ? a.duration : 0; URL.revokeObjectURL(url); resolve(d); };
a.onerror = () => { URL.revokeObjectURL(url); resolve(0); };
a.src = url;
});
}
function buildVoiceWaveform(samples, barsCount = 28) {
if (!samples || samples.length === 0) return [];
const out = [];
const chunk = Math.floor(samples.length / barsCount) || 1;
for (let i = 0; i < barsCount; i++) {
let maxV = 0;
const start = i * chunk;
for (let j = start; j < Math.min(start + chunk, samples.length); j++) {
const v = Math.abs(samples[j]);
if (v > maxV) maxV = v;
}
out.push(Math.max(14, Math.min(100, Math.round(maxV * 130))));
}
return out;
}
function stopAllVoices() {
document.querySelectorAll('#messages audio').forEach(a => { try { a.pause(); a.currentTime = 0; } catch (e) {} });
currentVoiceAudio = null;
document.querySelectorAll('.voice-play-btn').forEach(b => { if (b.textContent === '⏸') b.textContent = '▶'; });
document.querySelectorAll('.voice-bubble.playing').forEach(b => b.classList.remove('playing'));
}
function fmtVoiceTime(s) { s = Math.max(0, Math.floor(s)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
// ---------- پنل اختصاصی درخواست مجوز میکروفون ----------
let micPermResolve = null;
function requestMicPermission() {
return new Promise((resolve) => {
micPermResolve = resolve;
if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
showToast('مرورگر شما از ضبط صدا پشتیبانی نمی‌کند');
resolve(false);
micPermResolve = null;
return;
}
if (document.getElementById('voicePermOverlay')) { resolve(false); return; }
const ov = document.createElement('div');
ov.id = 'voicePermOverlay';
ov.className = 'voice-perm-overlay';
ov.setAttribute('dir', 'rtl');
ov.innerHTML = '<div class="voice-perm-card">' +
'<div class="voice-perm-icon">🎙️</div>' +
'<div class="voice-perm-title">اجازه دسترسی به میکروفون</div>' +
'<div class="voice-perm-desc">برای ضبط و ارسال پیام صوتی (ویس)، اسپاتیرا به میکروفون شما نیاز دارد.<br>پس از انتخاب «اجازه دادن»، دکمه میکروفون را نگه دارید تا ضبط شروع شود.</div>' +
'<div class="voice-perm-actions">' +
'<button type="button" class="vp-allow" onclick="micPermResult(true)">✔ اجازه دادن</button>' +
'<button type="button" class="vp-deny" onclick="micPermResult(false)">لغو</button>' +
'</div></div>';
document.body.appendChild(ov);
});
}
function micPermResult(allowed) {
const ov = document.getElementById('voicePermOverlay');
if (ov) ov.remove();
if (micPermResolve) {
const r = micPermResolve;
micPermResolve = null;
r(allowed);
}
if (allowed) {
const mb = document.getElementById('micBtn');
if (mb) { mb.classList.add('ready'); mb.title = 'دکمه میکروفون را برای ضبط نگه دارید'; }
showToast('🎙️ حالا دکمه میکروفون را نگه دارید تا ضبط شروع شود');
} else {
showToast('دسترسی به میکروفون لغو شد');
}
}
async function startVoice(e) {
if (e && e.type === 'touchstart' && e.cancelable) e.preventDefault();
if (voiceRecorder && voiceRecorder.state === 'recording') return;
if (document.getElementById('voicePermOverlay')) return;
if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
showToast('مرورگر شما از ضبط صدا پشتیبانی نمی‌کند');
return;
}
let permOk = true;
try {
if (navigator.permissions && navigator.permissions.query) {
const pState = await navigator.permissions.query({name: 'microphone'});
if (pState.state === 'prompt') permOk = await requestMicPermission();
} else {
permOk = await requestMicPermission();
}
} catch (err) { permOk = true; }
if (!permOk) return;
try {
voiceStream = await navigator.mediaDevices.getUserMedia({audio: true});
} catch (err) {
if (err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError')) {
showToast('مجوز میکروفون داده نشد؛ از تنظیمات مرورگر دسترسی را فعال کنید');
} else if (err && err.name === 'NotFoundError') {
showToast('میکروفونی روی دستگاه شما پیدا نشد');
} else {
showToast('دسترسی به میکروفون داده نشد');
}
return;
}
const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : (MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '');
voiceChunks = [];
try {
voiceRecorder = mime ? new MediaRecorder(voiceStream, {mimeType: mime}) : new MediaRecorder(voiceStream);
} catch (err) {
try { voiceRecorder = new MediaRecorder(voiceStream); } catch (e2) { showToast('خطا در شروع ضبط'); stopVoiceStream(); return; }
}
const rawSamples = [];
voiceRecorder.ondataavailable = (ev) => { if (ev.data && ev.data.size > 0) voiceChunks.push(ev.data); };
voiceRecorder.onstop = async () => {
const blob = new Blob(voiceChunks, {type: (mime || 'audio/webm').split(';')[0]});
const duration = Math.max(1, Math.round((Date.now() - voiceStartTs) / 1000));
stopVoiceStream();
if (duration < 1 || blob.size < 500) { showToast('ویس خیلی کوتاه بود'); return; }
await uploadVoice(blob, duration, rawSamples.slice());
};
voiceStartTs = Date.now();
document.getElementById('messageInput').style.display = 'none';
document.getElementById('micBtn').style.display = 'none';
document.getElementById('sendBtnRef') && (document.getElementById('sendBtnRef').style.display = 'none');
document.querySelectorAll('.message-input .send-btn').forEach(b => b.style.display = 'none');
const wave = document.getElementById('voiceWave');
wave.innerHTML = '';
voiceWaveBars = [];
for (let i = 0; i < 22; i++) { const s = document.createElement('span'); s.style.setProperty('--i', String(i)); wave.appendChild(s); voiceWaveBars.push(s); }
document.getElementById('voiceUi').style.display = 'flex';
document.getElementById('voiceTimer').textContent = '0:00';
document.getElementById('voiceSendBtn').disabled = false;
document.getElementById('voiceSendBtn').textContent = '➤';
document.body.classList.add('voice-recording');
window.addEventListener('mouseup', handleVoiceMouseUp);
window.addEventListener('touchend', handleVoiceTouchEnd);
window.addEventListener('touchcancel', handleVoiceTouchEnd);
voiceTimerInt = setInterval(() => {
const d = Math.floor((Date.now() - voiceStartTs) / 1000);
document.getElementById('voiceTimer').textContent = fmtVoiceTime(d);
if (d >= 300) stopVoiceRecording(true);
}, 250);
try {
voiceAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
const srcNode = voiceAudioCtx.createMediaStreamSource(voiceStream);
voiceAnalyser = voiceAudioCtx.createAnalyser();
voiceAnalyser.fftSize = 256;
srcNode.connect(voiceAnalyser);
const dataArr = new Uint8Array(voiceAnalyser.frequencyBinCount);
const drawWave = () => {
if (!voiceAnalyser) return;
voiceAnalyser.getByteFrequencyData(dataArr);
let sum = 0;
for (let i = 0; i < dataArr.length; i++) sum += dataArr[i];
rawSamples.push(Math.min(1, sum / dataArr.length / 180));
voiceWaveBars.forEach((s, idx) => {
const v = dataArr[idx * 2 + 1] || 0;
s.style.height = Math.max(12, (v / 255) * 100) + '%';
});
voiceRafId = requestAnimationFrame(drawWave);
};
drawWave();
} catch (err) {}
voiceRecorder.start(250);
showToast('🎙️ در حال ضبط... برای ارسال رها کنید');
}
function stopVoiceStream() {
if (voiceTimerInt) { clearInterval(voiceTimerInt); voiceTimerInt = null; }
if (voiceRafId) { cancelAnimationFrame(voiceRafId); voiceRafId = null; }
if (voiceAnalyser) { try { voiceAnalyser.disconnect(); } catch (e) {} voiceAnalyser = null; }
if (voiceAudioCtx) { try { voiceAudioCtx.close(); } catch (e) {} voiceAudioCtx = null; }
if (voiceStream) { voiceStream.getTracks().forEach(t => t.stop()); voiceStream = null; }
document.getElementById('voiceUi').style.display = 'none';
document.getElementById('messageInput').style.display = '';
document.getElementById('micBtn').style.display = '';
document.querySelectorAll('.message-input .send-btn').forEach(b => b.style.display = '');
window.removeEventListener('mouseup', handleVoiceMouseUp);
window.removeEventListener('touchend', handleVoiceTouchEnd);
window.removeEventListener('touchcancel', handleVoiceTouchEnd);
document.body.classList.remove('voice-recording');
}
function stopVoiceRecording(send) {
if (!voiceRecorder || voiceRecorder.state !== 'recording') return;
if (send) {
const sb = document.getElementById('voiceSendBtn');
if (sb) { sb.disabled = true; sb.textContent = '⏳'; }
voiceRecorder.stop();
}
else { voiceRecorder.onstop = null; voiceRecorder.stop(); stopVoiceStream(); }
}
function cancelVoice() {
if (voiceRecorder && voiceRecorder.state === 'recording') { voiceRecorder.onstop = null; voiceRecorder.stop(); }
stopVoiceStream();
showToast('ویس لغو شد');
}
// ---------- آزادسازی انگشت: رها روی دکمه ➤ = ارسال، رها روی ✖ = لغو، در غیر این صورت ضبط ادامه می‌یابد ----------
function isPointOverEl(x, y, el) {
if (!el || el.style.display === 'none') return false;
const r = el.getBoundingClientRect();
return x >= r.left - 14 && x <= r.right + 14 && y >= r.top - 14 && y <= r.bottom + 14;
}
function handleVoiceMouseUp(e) {
if (!voiceRecorder || voiceRecorder.state !== 'recording') return;
const x = e.clientX, y = e.clientY;
if (isPointOverEl(x, y, document.getElementById('voiceSendBtn'))) { stopVoiceRecording(true); }
else if (isPointOverEl(x, y, document.getElementById('voiceCancelBtn'))) { cancelVoice(); }
}
function handleVoiceTouchEnd(e) {
if (!voiceRecorder || voiceRecorder.state !== 'recording') return;
const t = (e.changedTouches && e.changedTouches[0]) || null;
if (!t) return;
const x = t.clientX, y = t.clientY;
if (isPointOverEl(x, y, document.getElementById('voiceSendBtn'))) { stopVoiceRecording(true); }
else if (isPointOverEl(x, y, document.getElementById('voiceCancelBtn'))) { cancelVoice(); }
}
// کلیک/تاچ معمولی روی دکمه‌های پنل ضبط (حالت ضربه‌ای): ارسال یا لغو
(function bindVoiceUiClicks() {
const initVc = () => {
const vsb = document.getElementById('voiceSendBtn');
const vcb = document.getElementById('voiceCancelBtn');
if (vsb) vsb.addEventListener('click', (ev) => { ev.stopPropagation(); if (voiceRecorder && voiceRecorder.state === 'recording') stopVoiceRecording(true); });
if (vcb) vcb.addEventListener('click', (ev) => { ev.stopPropagation(); cancelVoice(); });
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initVc);
else initVc();
})();
async function uploadVoice(blob, duration, samples) {
if (!currentChat) return;
const file = new File([blob], 'voice_' + Date.now() + '.webm', {type: blob.type || 'audio/webm'});
const formData = new FormData();
formData.append('chat_id', currentChat.id);
formData.append('caption', '');
formData.append('file', file);
formData.append('voice_duration', String(duration));
if (samples.length > 0) {
formData.append('voice_waveform', buildVoiceWaveform(samples).join(','));
}
try {
const res = await fetch('?action=send_message', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) { showToast(data.error); return; }
await refreshMessages();
await loadChats();
} catch (e) { showToast('خطا در ارسال ویس'); }
}
function markVoicePlayed(msgId) {
playedVoices.add(msgId);
const hint = document.getElementById('vhint_' + msgId);
if (hint) { hint.textContent = '✓ شنیده شده'; hint.classList.add('listened'); }
}
function toggleVoicePlay(msgId, fileUrl, btn) {
const audio = document.getElementById('vaudio_' + msgId);
if (!audio) return;
if (!audio.src || audio.src === window.location.href) { audio.src = fileUrl; }
if (audio.paused) {
stopOthers(audio);
try { audio.playbackRate = voiceSpeeds[msgId] || 1; } catch (e) {}
audio.play().then(() => { btn.textContent = '⏸'; const bub = document.getElementById('vbub_' + msgId); if (bub) bub.classList.add('playing'); }).catch(() => showToast('پخش ویس ناموفق بود'));
markVoicePlayed(msgId);
} else {
audio.pause();
btn.textContent = '▶';
const bub = document.getElementById('vbub_' + msgId); if (bub) bub.classList.remove('playing');
}
audio.onended = () => { btn.textContent = '▶'; setVoiceProgress(msgId, 1); const bub = document.getElementById('vbub_' + msgId); if (bub) bub.classList.remove('playing'); };
audio.ontimeupdate = () => {
if (audio.duration > 0) setVoiceProgress(msgId, audio.currentTime / audio.duration);
const durEl = document.getElementById('vdur_' + msgId);
if (durEl) durEl.textContent = fmtVoiceTime(Math.max(0, audio.duration - audio.currentTime));
};
audio.onloadedmetadata = () => {
const m = audio.duration;
if (isFinite(m) && m > 0) {
const msg = currentMessages.find(x => x.id === msgId);
if (msg && !msg.voice_duration) msg.voice_duration = String(Math.round(m));
}
};
}
function stopOthers(exceptAudio) {
document.querySelectorAll('#messages audio').forEach(a => {
if (a !== exceptAudio && !a.paused) { a.pause(); const bubble = a.closest('.voice-bubble'); if (bubble) { bubble.classList.remove('playing'); const b = bubble.querySelector('.voice-play-btn'); if (b) b.textContent = '▶'; } }
});
}
function setVoiceProgress(msgId, ratio) {
const wf = document.getElementById('vwf_' + msgId);
if (!wf) return;
const bars = wf.querySelectorAll('span');
const upto = Math.floor(ratio * bars.length);
bars.forEach((b, i) => b.classList.toggle('played', i < upto));
try {
const vpf = document.getElementById('vprog_' + msgId);
if (vpf) vpf.style.width = Math.max(0, Math.min(100, ratio * 100)) + '%';
} catch (e) {}
}
function seekVoice(ev, msgId) {
const audio = document.getElementById('vaudio_' + msgId);
if (!audio || !isFinite(audio.duration) || audio.duration === 0) return;
const rect = ev.currentTarget.getBoundingClientRect();
const pos = (rect.right - ev.clientX) / rect.width;
audio.currentTime = Math.max(0, Math.min(1, pos)) * audio.duration;
setVoiceProgress(msgId, audio.currentTime / audio.duration);
}
/* ---------- افزونه‌های اختصاصی ویس: سرعت پخش ---------- */
const voiceSpeeds = {};
const voiceSpeedSteps = [1, 1.25, 1.5, 2, 0.75];
function cycleVoiceSpeed(msgId) {
const audio = document.getElementById('vaudio_' + msgId);
if (!audio) return;
const cur = voiceSpeeds[msgId] || 1;
const idx = voiceSpeedSteps.indexOf(cur);
const next = voiceSpeedSteps[(idx + 1) % voiceSpeedSteps.length];
voiceSpeeds[msgId] = next;
try { audio.playbackRate = next; } catch (e) {}
const spBtn = document.getElementById('vspd_' + msgId);
if (spBtn) {
spBtn.textContent = (next === 1 ? '1' : next) + 'x';
spBtn.classList.toggle('active', next !== 1);
}
showToast('سرعت پخش: ' + (next === 1 ? 'عادی' : next + ' برابر'));
}
async function editMessage(msgId) {
const msg = currentMessages.find(m => m.id === msgId);
if (!msg) return;
const currentText = msg.file_path ? (msg.caption || '') : (msg.text || '');
const newText = prompt('ویرایش پیام:', currentText);
if (newText === null || newText === currentText) return;
const formData = new FormData();
formData.append('message_id', msgId);
formData.append('text', newText);
const res = await fetch('?action=edit_message', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
refreshMessages();
}
async function deleteMessage(msgId) {
if (!confirm('این پیام حذف شود؟')) return;
const formData = new FormData();
formData.append('message_id', msgId);
const res = await fetch('?action=delete_message', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
knownMessageIds.delete(msgId);
refreshMessages();
}
async function saveMessage(msgId) {
const formData = new FormData();
formData.append('message_id', msgId);
try {
const res = await fetch('?action=save_message', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
showToast('در پیام‌های ذخیره‌شده ذخیره شد');
await loadChats();
if (currentChat && currentChat.type === 'saved') refreshMessages();
} catch (e) {
showToast('خطا در ذخیره پیام');
}
}
function setReply(msgId) {
const msg = currentMessages.find(m => m.id === msgId);
if (!msg) return;
replyingTo = msg;
const replyBox = document.getElementById('replyPreview');
const text = msg.file_path ? (msg.caption || msg.file_name || '📎 فایل') : (msg.text || '');
replyBox.innerHTML = '<div style="border-right:3px solid var(--accent);padding:6px 12px;background:var(--input);border-radius:10px;display:flex;justify-content:space-between;align-items:center;gap:10px"><div style="min-width:0;flex:1"><div style="font-size:12px;font-weight:800;color:var(--accent);margin-bottom:2px">' + escapeHtml(msg.username || 'کاربر') + '</div><div style="font-size:12.5px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(text) + '</div></div><button class="icon-btn" onclick="cancelReply()" style="width:28px;height:28px;font-size:14px">✖️</button></div>';
replyBox.style.display = 'block';
document.getElementById('messageInput').focus();
}
function cancelReply() {
replyingTo = null;
document.getElementById('replyPreview').style.display = 'none';
document.getElementById('replyPreview').innerHTML = '';
}
function scrollToMessage(msgId) {
const el = document.querySelector('.message[data-id="' + msgId + '"]');
if (el) {
el.scrollIntoView({behavior:'smooth', block:'center'});
el.style.transition = 'background 0.5s';
const origBg = el.style.background;
el.style.background = 'rgba(61,219,196,0.3)';
setTimeout(() => { el.style.background = origBg; }, 1500);
}
}
async function togglePin(msgId) {
const formData = new FormData();
formData.append('message_id', msgId);
try {
const res = await fetch('?action=toggle_pin_message', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) { showToast(data.error); return; }
refreshMessages();
} catch (e) { showToast('خطا در سنجاق کردن'); }
}
function toggleChatSearch() {
const bar = document.getElementById('chatSearchBar');
const input = document.getElementById('chatSearchInput');
if (bar.style.display === 'none' || !bar.style.display) {
bar.style.display = 'block';
input.focus();
} else {
bar.style.display = 'none';
input.value = '';
filterChatMessages();
}
}
function filterChatMessages() {
const q = document.getElementById('chatSearchInput').value.trim().toLowerCase();
const container = document.getElementById('messages');
if (!q) {
renderMessages(currentMessages, true);
return;
}
const filtered = currentMessages.filter(m => {
const text = m.file_path ? (m.caption || m.file_name || '') : (m.text || '');
return text.toLowerCase().includes(q) || (m.username || '').toLowerCase().includes(q);
});
container.innerHTML = filtered.map(renderMessage).join('');
}
async function clearChat() {
if (!confirm('تمام پیام‌های این چت حذف شوند؟ این عمل قابل بازگشت نیست.')) return;
const formData = new FormData();
formData.append('chat_id', currentChat.id);
try {
const res = await fetch('?action=clear_chat', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) { showToast(data.error); return; }
showToast('تاریخچه چت پاک شد');
closeModal('chatMenuModal');
knownMessageIds = new Set();
lastRenderKey = '';
lastMessageCount = 0;
await refreshMessages(true);
await loadChats();
} catch (e) { showToast('خطا در پاک کردن چت'); }
}
function exportChat() {
if (!currentMessages.length) { showToast('پیامی برای خروجی گرفتن وجود ندارد'); return; }
let text = 'تاریخچه چت: ' + (currentChat.display_name || currentChat.name) + '\n';
text += 'تاریخ خروجی: ' + new Date().toLocaleString('fa-IR') + '\n';
text += '========================================\n\n';
currentMessages.forEach(m => {
const time = new Date(m.created_at * 1000).toLocaleString('fa-IR');
const sender = m.username || 'کاربر';
const content = m.file_path ? (m.caption ? '[فایل] ' + m.caption : '[فایل: ' + (m.file_name || 'فایل') + ']') : (m.text || '');
text += '[' + time + '] ' + sender + ':\n' + content + '\n\n';
});
const blob = new Blob([text], {type: 'text/plain;charset=utf-8'});
const url = URL.createObjectURL(blob);
const a = document.createElement('a');
a.href = url;
a.download = 'chat_' + currentChat.id + '_' + Date.now() + '.txt';
document.body.appendChild(a);
a.click();
document.body.removeChild(a);
URL.revokeObjectURL(url);
showToast('خروجی با موفقیت دانلود شد');
}
async function loadUsers() {
const res = await fetch('?action=get_users');
const data = await res.json();
renderUsers(data.users || []);
}
function renderUsers(users) {
users.forEach(u => selectedMembersData[u.id] = u);
const list = document.getElementById('usersList');
if (!users.length) {
list.innerHTML = '<div style="padding:16px;text-align:center;color:var(--t2);font-size:13px">کاربری یافت نشد</div>';
return;
}
const single = currentCreateType === 'private';
list.innerHTML = users.map(u => {
const avatarHtml = u.avatar ? '<img src="' + escapeHtml(resolveFileUrl(u.avatar)) + '" alt="">' : escapeHtml((u.name || u.username || '?')[0].toUpperCase());
const badges = badgesHtml(u);
return `
<label class="user-option">
<input type="${single ? 'radio' : 'checkbox'}" name="memberPick" value="${u.id}" onchange="updateSelectedMembers()" ${selectedMembers.includes(u.id) ? 'checked' : ''}>
<div class="user-option-avatar">${avatarHtml}</div>
<div class="user-option-info">
<div class="user-option-name">${escapeHtml(u.name || u.username)}${badges}</div>
<div class="user-option-username">@${escapeHtml(u.username)}</div>
</div>
</label>
`;
}).join('');
}
function updateSelectedMembers() {
const checked = Array.from(document.querySelectorAll('#usersList input:checked'));
selectedMembers = checked.map(i => i.value);
renderSelectedChips();
}
function renderSelectedChips() {
const box = document.getElementById('selectedChips');
if (!selectedMembers.length) { box.innerHTML = ''; return; }
box.innerHTML = selectedMembers.map(id => {
const u = selectedMembersData[id] || {name: 'کاربر'};
return '<span class="member-chip">' + escapeHtml(u.name || u.username || 'کاربر') + '<button type="button" onclick="event.preventDefault();removeMember(\'' + id + '\')">✖️</button></span>';
}).join('');
}
function removeMember(id) {
selectedMembers = selectedMembers.filter(m => m !== id);
renderSelectedChips();
const cb = document.querySelector('#usersList input[value="' + id + '"]');
if (cb) cb.checked = false;
}
let searchTimeout = null;
function searchUsers() {
clearTimeout(searchTimeout);
const q = document.getElementById('userSearch').value.trim();
if (!q) { loadUsers(); return; }
searchTimeout = setTimeout(async () => {
const res = await fetch('?action=search_users&q=' + encodeURIComponent(q));
const data = await res.json();
renderUsers(data.users || []);
}, 300);
}
function createChat(type) {
currentCreateType = type;
closeFab();
openModal('newChatModal');
const isPrivate = type === 'private';
document.getElementById('newChatTitle').textContent = {
private: 'پیام خصوصی جدید',
group: 'ایجاد گروه جدید',
channel: 'ایجاد کانال جدید'
}[type];
document.getElementById('chatNameField').style.display = isPrivate ? 'none' : 'block';
document.getElementById('chatDescField').style.display = isPrivate ? 'none' : 'block';
document.getElementById('newChatName').value = '';
document.getElementById('newChatDesc').value = '';
selectedMembers = [];
selectedMembersData = {};
renderSelectedChips();
loadUsers();
}
async function submitCreateChat() {
const type = currentCreateType;
const formData = new FormData();
formData.append('type', type);
if (type !== 'private') {
const name = document.getElementById('newChatName').value.trim();
if (!name) { showToast('نام الزامی است'); return; }
formData.append('name', name);
formData.append('description', document.getElementById('newChatDesc').value);
} else {
if (!selectedMembers.length) { showToast('یک کاربر انتخاب کنید'); return; }
}
selectedMembers.forEach(m => formData.append('members[]', m));
try {
const res = await fetch('?action=create_chat', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
closeModal('newChatModal');
selectedMembers = [];
selectedMembersData = {};
await loadChats(true);
if (data.chat) openChat(data.chat.id);
} catch (e) {
showToast('خطا در ایجاد چت');
}
}
async function openSavedChat() {
let chat = chats.find(c => c.type === 'saved');
if (!chat) { await loadChats(true); chat = chats.find(c => c.type === 'saved'); }
if (chat) openChat(chat.id);
else showToast('خطا در یافتن پیام‌های ذخیره‌شده');
}
function openChatMenu() {
if (!currentChat || currentChat.type === 'saved') return;
document.getElementById('menuTitle').textContent = currentChat.display_name || currentChat.name || 'چت';
const isOwner = currentChat.owner_id === currentUser.id;
const isPrivate = currentChat.type === 'private';
const canManage = isOwner || currentUser.is_admin;
let html = '<div class="menu-rows">';
html += '<button class="menu-row" onclick="toggleChatSearch();closeModal(\'chatMenuModal\')">🔍 جستجو در پیام‌ها</button>';
html += '<button class="menu-row" onclick="exportChat();closeModal(\'chatMenuModal\')">📥 خروجی گرفتن از چت</button>';
html += '<button class="menu-row danger" onclick="clearChat()">🧹 پاک کردن تاریخچه چت</button>';
if (!isPrivate && canManage) {
html += '<button class="menu-row" onclick="openEditChat()">✏️ ویرایش چت</button>';
html += '<button class="menu-row" onclick="openAddMembersModal()">➕ افزودن عضو</button>';
html += '<button class="menu-row danger" onclick="deleteChat()">🗑️ حذف کامل ' + (currentChat.type === 'channel' ? 'کانال' : 'گروه') + '</button>';
}
if (isPrivate || !canManage) {
html += '<button class="menu-row" onclick="leaveChat()">🚪 ' + (isPrivate ? 'بستن چت خصوصی' : 'خروج از ' + (currentChat.type === 'channel' ? 'کانال' : 'گروه')) + '</button>';
}
html += '</div>';
document.getElementById('chatMenuContent').innerHTML = html;
openModal('chatMenuModal');
}
function openEditChat() {
closeModal('chatMenuModal');
document.getElementById('editChatName').value = currentChat.name || '';
document.getElementById('editChatDesc').value = currentChat.description || '';
updateEditChatAvatarUI();
openModal('editChatModal');
}
function updateEditChatAvatarUI() {
const avatarEl = document.getElementById('editChatAvatar');
const avatarPath = currentChat.avatar_image || '';
if (avatarPath) {
avatarEl.innerHTML = '<img src="' + escapeHtml(resolveFileUrl(avatarPath)) + '" alt=""><div class="avatar-upload-overlay">📷</div>';
} else {
avatarEl.innerHTML = '<span>' + escapeHtml((currentChat.name || '?')[0].toUpperCase()) + '</span><div class="avatar-upload-overlay">📷</div>';
}
}
async function uploadChatAvatar(input) {
if (!input.files || !input.files[0] || !currentChat) return;
const formData = new FormData();
formData.append('chat_id', currentChat.id);
formData.append('avatar', input.files[0]);
try {
const res = await fetch('?action=upload_chat_avatar', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
input.value = '';
return;
}
currentChat.avatar_image = data.avatar;
updateEditChatAvatarUI();
renderChatHeader();
showToast('عکس آپلود شد');
} catch (e) {
showToast('خطا در آپلود');
}
input.value = '';
}
async function removeChatAvatar() {
if (!currentChat) return;
if (!confirm('عکس چت حذف شود؟')) return;
const formData = new FormData();
formData.append('chat_id', currentChat.id);
try {
const res = await fetch('?action=remove_chat_avatar', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
currentChat.avatar_image = '';
updateEditChatAvatarUI();
renderChatHeader();
showToast('عکس حذف شد');
} catch (e) {
showToast('خطا');
}
}
async function saveChatEdit() {
const formData = new FormData();
formData.append('chat_id', currentChat.id);
formData.append('name', document.getElementById('editChatName').value);
formData.append('description', document.getElementById('editChatDesc').value);
const res = await fetch('?action=update_chat', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
currentChat.name = document.getElementById('editChatName').value;
currentChat.description = document.getElementById('editChatDesc').value;
currentChat.display_name = currentChat.name;
closeModal('editChatModal');
showToast('ذخیره شد');
await loadChats();
renderChatHeader();
}
async function leaveChat() {
if (!confirm('مطمئن هستید؟')) return;
const formData = new FormData();
formData.append('chat_id', currentChat.id);
const res = await fetch('?action=leave_chat', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
closeModal('chatMenuModal');
closeChat();
await loadChats(true);
}
async function deleteChat() {
if (!confirm('این چت به طور کامل حذف شود؟ این عمل قابل بازگشت نیست.')) return;
const formData = new FormData();
formData.append('chat_id', currentChat.id);
const res = await fetch('?action=delete_chat', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
closeModal('chatMenuModal');
closeChat();
showToast('حذف شد');
await loadChats(true);
}
async function logout() {
if (!confirm('خروج از حساب؟')) return;
const res = await fetch('?action=logout');
const data = await res.json();
window.location.href = data.redirect || 'index.php';
}
function updateMeAvatar(avatarPath) {
const el = document.getElementById('meAvatar');
if (avatarPath) {
el.innerHTML = '<img src="' + escapeHtml(resolveFileUrl(avatarPath)) + '" alt="">';
} else {
el.innerHTML = escapeHtml((currentUser.name || currentUser.username || '?')[0].toUpperCase());
}
}
function renderPremiumSection() {
const section = document.getElementById('premiumSection');
if (!currentUser.premium) {
section.innerHTML = '';
return;
}
const until = currentUser.premium_until;
const daysLeft = currentUser.premium_days_left;
const untilStr = formatDateTime(until);
section.innerHTML = `
<div class="premium-card">
<div class="premium-card-content">
<div class="premium-card-head">
<span style="font-size:28px">⭐</span>
<h4>اشتراک پرمیوم VIP فعال</h4>
</div>
<div class="premium-info-row">
<span class="premium-info-label">📅 تاریخ انقضا</span>
<span class="premium-info-value">${untilStr}</span>
</div>
<div class="premium-info-row">
<span class="premium-info-label">⏳ روزهای باقیمانده</span>
<span class="premium-info-value">${daysLeft} روز</span>
</div>
<div class="premium-info-row">
<span class="premium-info-label">📦 حداکثر حجم فایل</span>
<span class="premium-info-value">${PREMIUM_MAX_UPLOAD_MB} مگابایت</span>
</div>
<div class="premium-info-row">
<span class="premium-info-label">📝 حداکثر طول بیو</span>
<span class="premium-info-value">${PREMIUM_BIO_MAX} کاراکتر</span>
</div>
<div class="premium-info-row">
<span class="premium-info-label">🎨 رنگ پیام</span>
<span class="premium-info-value">فعال</span>
</div>
</div>
</div>
`;
}
function renderWalletSection() {
const section = document.getElementById('walletSection');
const balance = currentUser.wallet_balance || 0;
const tomanValue = balance * spcToToman;
section.innerHTML = `
<div class="wallet-card">
<div class="wallet-card-content">
<div class="wallet-card-head">
<span style="font-size:28px">💰</span>
<h4>کیف پول SPC</h4>
</div>
<div class="wallet-balance">
${formatNumber(balance)} <small>SPC</small>
</div>
<div class="wallet-toman">≈ ${formatToman(tomanValue)}</div>
<div class="premium-info-row">
<span class="premium-info-label">💱 نرخ تبدیل</span>
<span class="premium-info-value">1 SPC = ${formatNumber(spcToToman)} تومان</span>
</div>
<div class="wallet-actions">
<button class="btn btn-spc" onclick="openTransferModal()">💸 انتقال SPC</button>
</div>
</div>
</div>
`;
}
function openTransferModal() {
document.getElementById('transferToUsername').value = '';
document.getElementById('transferAmount').value = '';
document.getElementById('transferPreview').style.display = 'none';
const balance = currentUser.wallet_balance || 0;
document.getElementById('transferWalletInfo').innerHTML = `
<div style="padding:12px;background:var(--input);border-radius:12px;border:1px solid var(--border)">
<div style="display:flex;justify-content:space-between"><span style="color:var(--t2);font-size:12px">موجودی شما:</span><strong style="color:var(--spc-green)">${formatNumber(balance)} SPC</strong></div>
</div>
`;
openModal('transferModal');
updateTransferPreview();
}
function updateTransferPreview() {
const amount = parseInt(document.getElementById('transferAmount').value) || 0;
const preview = document.getElementById('transferPreview');
if (amount > 0) {
document.getElementById('transferPreviewAmount').textContent = formatNumber(amount) + ' SPC';
document.getElementById('transferPreviewToman').textContent = formatToman(amount * spcToToman);
preview.style.display = 'block';
} else {
preview.style.display = 'none';
}
}
async function submitTransfer() {
const toUsername = document.getElementById('transferToUsername').value.trim().replace(/^@/, '');
const amount = parseInt(document.getElementById('transferAmount').value) || 0;
if (!toUsername) { showToast('نام کاربری مقصد را وارد کنید'); return; }
if (amount <= 0) { showToast('مقدار باید بیشتر از صفر باشد'); return; }
const formData = new FormData();
formData.append('to_username', toUsername);
formData.append('amount', amount);
try {
const res = await fetch('?action=transfer_spc', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
showToast(data.message);
currentUser.wallet_balance = data.new_balance;
closeModal('transferModal');
renderWalletSection();
if (document.getElementById('walletPage').classList.contains('active')) loadWalletHistory();
} catch (e) {
showToast('خطا در انتقال');
}
}
document.getElementById('transferAmount')?.addEventListener('input', updateTransferPreview);
let walletHistoryData = [];
async function openWalletPage() {
document.getElementById('walletPage').classList.add('active');
await loadWalletHistory();
}
function closeWalletPage() {
document.getElementById('walletPage').classList.remove('active');
}
async function loadWalletHistory() {
const content = document.getElementById('walletPageContent');
try {
const res = await fetch('?action=get_wallet_history');
const data = await res.json();
if (data.error) { showToast(data.error); closeWalletPage(); return; }
walletHistoryData = data.history || [];
currentUser.wallet_balance = data.balance;
spcToToman = data.spc_to_toman;
renderWalletSection();
renderWalletPage(data);
} catch (e) {
content.innerHTML = '<div class="history-empty">⚠️ خطا در بارگذاری تاریخچه</div>';
showToast('خطا در بارگذاری کیف پول');
}
}
function renderWalletPage(data) {
const content = document.getElementById('walletPageContent');
const balance = data.balance || 0;
const history = data.history || [];
const totalIn = history.filter(h => h.type === 'in').reduce((s, h) => s + (h.amount || 0), 0);
const totalOut = history.filter(h => h.type === 'out').reduce((s, h) => s + (h.amount || 0), 0);
const historyHtml = history.length === 0 ? '<div class="history-empty">📭 تراکنشی وجود ندارد</div>' : '<div class="history-list">' + history.map(h => {
const isIn = h.type === 'in';
const icon = isIn ? '⬇️' : '⬆️';
const title = isIn ? 'واریز SPC' : 'برداشت SPC';
const counterparty = h.with_username ? '@' + escapeHtml(h.with_username) : '';
const note = h.note ? escapeHtml(h.note) : '';
return `<div class="history-item ${isIn ? 'in' : 'out'}">
<div class="history-icon">${icon}</div>
<div class="history-info">
<div class="history-title">${title}${counterparty ? ' ' + (isIn ? 'از' : 'به') + ' ' + counterparty : ''}</div>
<div class="history-meta">🕐 ${formatDateTime(h.created_at)}${note ? ' • ' + note : ''}</div>
</div>
<div class="history-amount">
<b>${isIn ? '+' : '-'}${formatNumber(h.amount || 0)} SPC</b>
<span>موجودی: ${formatNumber(h.balance_after || 0)}</span>
</div>
</div>`;
}).join('') + '</div>';
content.innerHTML = `
<div class="wallet-card" style="margin-bottom:16px">
<div class="wallet-card-content">
<div class="wallet-card-head">
<span style="font-size:28px">💰</span>
<h4>موجودی کیف پول</h4>
</div>
<div class="wallet-balance-big">${formatNumber(balance)} <small>SPC</small></div>
<div class="wallet-toman-big">≈ ${formatToman(balance * spcToToman)}</div>
<div class="premium-info-row">
<span class="premium-info-label">💱 نرخ تبدیل</span>
<span class="premium-info-value">1 SPC = ${formatNumber(spcToToman)} تومان</span>
</div>
<div class="wallet-actions">
<button class="btn btn-spc" onclick="openTransferModal()">💸 انتقال SPC</button>
</div>
</div>
</div>
<div class="wallet-stats-row">
<div class="wallet-stat in"><b>+${formatNumber(totalIn)}</b><span>کل واریز (SPC)</span></div>
<div class="wallet-stat out"><b>-${formatNumber(totalOut)}</b><span>کل برداشت (SPC)</span></div>
</div>
<div class="section-title spc">🧾 تاریخچه تراکنش‌ها</div>
${historyHtml}
`;
}
function renderPremiumSettings() {
if (!currentUser.premium) return '';
const selectedColor = selectedPremiumColor || '';
const colorOptions = ALLOWED_PREMIUM_COLORS.map(c => {
const isSelected = c === selectedColor;
const style = c ? `background:${c}` : 'background:var(--hover);color:var(--t1)';
const label = c ? '' : '×';
return `<div class="color-option ${isSelected ? 'selected' : ''}" style="${style}" onclick="selectPremiumColor('${c}')" title="${c ? c : 'بدون رنگ'}">${label}</div>`;
}).join('');
return `
<div class="section-title vip">⭐ تنظیمات پرمیوم VIP</div>
<div class="premium-feature-list">
<div class="premium-feature">
<span class="premium-feature-icon">🎨</span>
<div class="premium-feature-text">
<strong>رنگ نام کاربری</strong>
<span>رنگی برای نمایش نام خود در پیام‌ها انتخاب کنید</span>
</div>
</div>
<div style="padding:0 12px 8px">
<div class="color-picker">${colorOptions}</div>
</div>
<div class="toggle-row" style="background:rgba(255,215,0,.06);border-color:rgba(255,215,0,.2)">
<div style="flex:1">
<div class="toggle-row-label">🔔 صدای پیام سفارشی</div>
<div class="small-note">پخش صدای خاص هنگام دریافت پیام</div>
</div>
<label class="toggle-switch"><input type="checkbox" id="premiumSound" ${currentUser.premium_message_sound ? 'checked' : ''}><span class="toggle-slider"></span></label>
</div>
<div class="toggle-row" style="background:rgba(255,215,0,.06);border-color:rgba(255,215,0,.2)">
<div style="flex:1">
<div class="toggle-row-label">✨ انیمیشن آواتار</div>
<div class="small-note">افکت درخشان برای عکس پروفایل شما</div>
</div>
<label class="toggle-switch"><input type="checkbox" id="premiumAnimAvatar" ${currentUser.premium_animated_avatar ? 'checked' : ''}><span class="toggle-slider"></span></label>
</div>
<div class="toggle-row" style="background:rgba(255,215,0,.06);border-color:rgba(255,215,0,.2)">
<div style="flex:1">
<div class="toggle-row-label">👻 مخفی کردن آخرین بازدید</div>
<div class="small-note">دیگران زمان آخرین بازدید شما را نمی‌بینند (فقط "اخیراً" نمایش داده می‌شود)</div>
</div>
<label class="toggle-switch"><input type="checkbox" id="premiumHideLastSeen" ${currentUser.premium_hide_last_seen ? 'checked' : ''}><span class="toggle-slider"></span></label>
</div>
</div>
`;
}
function selectPremiumColor(color) {
selectedPremiumColor = color;
const settingsHtml = renderPremiumSettings();
const container = document.querySelector('#profileModal .modal');
const existingSettings = container.querySelector('.premium-settings-container');
if (existingSettings) {
existingSettings.outerHTML = `<div class="premium-settings-container">${settingsHtml}</div>`;
}
}
function updateBioCounter() {
const bio = document.getElementById('profileBio').value;
const counter = document.getElementById('bioCounter');
const maxLen = currentUser.premium ? PREMIUM_BIO_MAX : NORMAL_BIO_MAX;
const len = bio.length;
counter.textContent = len + ' / ' + maxLen;
counter.className = 'bio-counter';
if (len > maxLen * 0.9) counter.classList.add('danger');
else if (len > maxLen * 0.75) counter.classList.add('warn');
}
function openProfileModal() {
const u = currentUser;
selectedPremiumColor = u.premium_color || '';
renderPremiumSection();
renderWalletSection();
const settingsContainer = document.querySelector('#profileModal .premium-settings-container');
if (settingsContainer) settingsContainer.remove();
const premiumSettingsHtml = renderPremiumSettings();
if (premiumSettingsHtml) {
const profileHeader = document.querySelector('#profileModal .profile-header');
const container = document.createElement('div');
container.className = 'premium-settings-container';
container.innerHTML = premiumSettingsHtml;
profileHeader.parentNode.insertBefore(container, profileHeader.nextSibling);
}
const avatarEl = document.getElementById('profileAvatar');
if (u.avatar) {
avatarEl.innerHTML = '<img src="' + escapeHtml(resolveFileUrl(u.avatar)) + '" alt=""><div class="avatar-upload-overlay">📷</div>';
avatarEl.className = 'profile-avatar' + (u.premium && u.premium_animated_avatar ? ' premium-glow' : '');
} else {
avatarEl.innerHTML = '<span>' + escapeHtml((u.name || u.username || '?')[0].toUpperCase()) + '</span><div class="avatar-upload-overlay">📷</div>';
avatarEl.className = 'profile-avatar' + (u.premium && u.premium_animated_avatar ? ' premium-glow' : '');
}
const displayNameEl = document.getElementById('profileDisplayName');
displayNameEl.innerHTML = escapeHtml(u.name || u.username) + badgesHtml(u);
document.getElementById('profileDisplayUsername').textContent = '@' + u.username;
document.getElementById('profileUsername').value = u.username || '';
document.getElementById('profileName').value = u.name || '';
document.getElementById('profileBio').value = u.bio || '';
document.getElementById('profileSearchable').checked = !!u.privacy_searchable;
const bioMax = u.premium ? PREMIUM_BIO_MAX : NORMAL_BIO_MAX;
document.getElementById('bioMaxLabel').textContent = '(حداکثر ' + bioMax + ' کاراکتر' + (u.premium ? ' ⭐' : '') + ')';
document.getElementById('profileBio').maxLength = bioMax;
updateBioCounter();
document.getElementById('currentPassword').value = '';
document.getElementById('newPassword').value = '';
document.getElementById('confirmPassword').value = '';
openModal('profileModal');
}
async function uploadUserAvatar(input) {
if (!input.files || !input.files[0]) return;
const formData = new FormData();
formData.append('avatar', input.files[0]);
try {
const res = await fetch('?action=upload_user_avatar', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
input.value = '';
return;
}
currentUser.avatar = data.avatar;
updateMeAvatar(data.avatar);
openProfileModal();
showToast('عکس پروفایل آپلود شد');
} catch (e) {
showToast('خطا در آپلود');
}
input.value = '';
}
async function removeUserAvatar() {
if (!currentUser.avatar) { showToast('عکسی برای حذف وجود ندارد'); return; }
if (!confirm('عکس پروفایل حذف شود؟')) return;
try {
const res = await fetch('?action=remove_user_avatar', {method: 'POST'});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
currentUser.avatar = '';
updateMeAvatar('');
openProfileModal();
showToast('عکس حذف شد');
} catch (e) {
showToast('خطا');
}
}
async function saveProfile() {
const username = document.getElementById('profileUsername').value.trim();
const name = document.getElementById('profileName').value.trim();
const bio = document.getElementById('profileBio').value.trim();
const privacy_searchable = document.getElementById('profileSearchable').checked;
const bioMax = currentUser.premium ? PREMIUM_BIO_MAX : NORMAL_BIO_MAX;
if (!username) { showToast('آیدی نمی‌تواند خالی باشد'); return; }
if (bio.length > bioMax) { showToast('طول بیو نمی‌تواند بیشتر از ' + bioMax + ' کاراکتر باشد'); return; }
const formData = new FormData();
formData.append('username', username);
formData.append('name', name);
formData.append('bio', bio);
formData.append('privacy_searchable', privacy_searchable ? '1' : '');
if (currentUser.premium) {
formData.append('premium_color', selectedPremiumColor || '');
const soundEl = document.getElementById('premiumSound');
const animAvatarEl = document.getElementById('premiumAnimAvatar');
const hideLastSeenEl = document.getElementById('premiumHideLastSeen');
formData.append('premium_message_sound', soundEl && soundEl.checked ? '1' : '');
formData.append('premium_animated_avatar', animAvatarEl && animAvatarEl.checked ? '1' : '');
formData.append('premium_hide_last_seen', hideLastSeenEl && hideLastSeenEl.checked ? '1' : '');
}
const res = await fetch('?action=update_profile', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
showToast('پروفایل ذخیره شد');
setTimeout(() => window.location.reload(), 500);
}
async function changePassword() {
const current_password = document.getElementById('currentPassword').value;
const new_password = document.getElementById('newPassword').value;
const confirm_password = document.getElementById('confirmPassword').value;
if (!current_password || !new_password) { showToast('فیلدهای رمز عبور را پر کنید'); return; }
if (new_password !== confirm_password) { showToast('رمز جدید و تکرار آن یکسان نیستند'); return; }
const formData = new FormData();
formData.append('current_password', current_password);
formData.append('new_password', new_password);
const res = await fetch('?action=change_password', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
showToast('رمز عبور تغییر کرد');
document.getElementById('currentPassword').value = '';
document.getElementById('newPassword').value = '';
document.getElementById('confirmPassword').value = '';
}
async function viewProfile(uid) {
try {
const res = await fetch('?action=get_user_profile&user_id=' + encodeURIComponent(uid));
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
const u = data.user;
viewProfileTarget = u.id;
const premiumSection = document.getElementById('viewProfilePremiumSection');
const headerEl = document.getElementById('viewProfileHeader');
if (u.premium) {
premiumSection.innerHTML = `
<div class="premium-card">
<div class="premium-card-content">
<div class="premium-card-head">
<span style="font-size:28px">⭐</span>
<h4>کاربر پرمیوم VIP</h4>
</div>
<div class="premium-info-row">
<span class="premium-info-label">📅 اشتراک تا</span>
<span class="premium-info-value">${formatDateTime(u.premium_until)}</span>
</div>
<div class="premium-info-row">
<span class="premium-info-label">⏳ باقیمانده</span>
<span class="premium-info-value">${u.premium_days_left} روز</span>
</div>
</div>
</div>
`;
headerEl.className = 'profile-header premium';
} else {
premiumSection.innerHTML = '';
headerEl.className = 'profile-header';
}
const avatarEl = document.getElementById('viewProfileAvatar');
const hasAnimAvatar = u.premium && u.premium_animated_avatar;
avatarEl.className = 'profile-avatar' + (hasAnimAvatar ? ' premium-glow' : '');
avatarEl.innerHTML = u.avatar
? '<img src="' + escapeHtml(resolveFileUrl(u.avatar)) + '" alt="">'
: escapeHtml((u.name || u.username || '?')[0].toUpperCase());
const nameHtml = escapeHtml(u.name || u.username) + badgesHtml(u);
document.getElementById('viewProfileName').innerHTML = nameHtml;
const usernameStyle = u.premium && u.premium_color ? ' style="color:' + escapeHtml(u.premium_color) + '"' : '';
document.getElementById('viewProfileUsername').innerHTML = '<span' + usernameStyle + '>@' + escapeHtml(u.username) + '</span>';
document.getElementById('viewProfileBio').textContent = u.bio || 'بیویی ثبت نشده';
openModal('viewProfileModal');
} catch (e) {
showToast('خطا در دریافت پروفایل');
}
}
async function startPrivateWith(uid) {
if (!uid) return;
closeModal('viewProfileModal');
const existing = chats.find(c => c.type === 'private' && c.other_user_id === uid);
if (existing) {
openChat(existing.id);
return;
}
const formData = new FormData();
formData.append('type', 'private');
formData.append('members[]', uid);
const res = await fetch('?action=create_chat', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
await loadChats(true);
if (data.chat) openChat(data.chat.id);
}
async function openAdminPanel() {
openModal('adminModal');
await loadAdminData();
await loadAdminChats();
await loadAdminBot();
}
function switchAdminTab(tab, btn) {
document.querySelectorAll('.admin-tab').forEach(b => b.classList.remove('active'));
document.querySelectorAll('.admin-panel').forEach(p => p.classList.remove('active'));
btn.classList.add('active');
document.getElementById('adminPanel-' + tab).classList.add('active');
}
async function loadAdminData() {
try {
const [statsRes, usersRes] = await Promise.all([
fetch('?action=admin_get_stats'),
fetch('?action=admin_get_users')
]);
const statsData = await statsRes.json();
const usersData = await usersRes.json();
if (statsData.error) {
showToast(statsData.error);
return;
}
adminStats = statsData.stats;
adminUsers = usersData.users || [];
renderAdminStats();
renderAdminUsers();
} catch (e) {
console.error(e);
}
}
async function loadAdminChats() {
try {
const res = await fetch('?action=admin_get_chats');
const data = await res.json();
if (data.error) { showToast(data.error); return; }
adminChats = data.chats || [];
renderAdminChats();
} catch (e) {
showToast('خطا در دریافت چت‌ها');
}
}
async function loadAdminBot() {
try {
const res = await fetch('?action=admin_get_bot');
const data = await res.json();
if (data.error) {
document.getElementById('botEditArea').innerHTML = '<div style="padding:24px;text-align:center;color:var(--danger)">❌ ' + escapeHtml(data.error) + '</div>';
return;
}
adminBot = data.bot;
renderBotEditForm();
} catch (e) {
document.getElementById('botEditArea').innerHTML = '<div style="padding:24px;text-align:center;color:var(--danger)">❌ خطا در دریافت اطلاعات ربات</div>';
}
}
function renderBotEditForm() {
if (!adminBot) return;
const area = document.getElementById('botEditArea');
const avatarHtml = adminBot.avatar
? '<img src="' + escapeHtml(resolveFileUrl(adminBot.avatar)) + '" alt="">'
: escapeHtml((adminBot.name || adminBot.username || '?')[0].toUpperCase());
area.innerHTML = `
<div class="profile-header">
<div class="profile-avatar" id="botAvatar" onclick="document.getElementById('botAvatarInput').click()" style="width:80px;height:80px;border-radius:25px;font-size:32px">
${avatarHtml}
<div class="avatar-upload-overlay">📷</div>
</div>
<div style="flex:1;min-width:0">
<div style="font-weight:800;font-size:16px">${escapeHtml(adminBot.name || adminBot.username)}</div>
<div style="font-size:12.5px;color:var(--accent);direction:ltr;text-align:right">@${escapeHtml(adminBot.username)}</div>
<div class="avatar-actions" style="justify-content:flex-start">
<button class="mini-btn danger" type="button" onclick="removeBotAvatar()">🗑️ حذف عکس</button>
</div>
</div>
</div>
<input type="file" id="botAvatarInput" style="display:none" accept="image/*" onchange="uploadBotAvatar(this)">
<div class="section-title">🤖 اطلاعات ربات</div>
<div class="modal-field"><label>@ آیدی</label><input type="text" id="botUsername" value="${escapeHtml(adminBot.username)}" style="direction:ltr;text-align:left"></div>
<div class="modal-field"><label>نام نمایشی</label><input type="text" id="botName" value="${escapeHtml(adminBot.name || '')}"></div>
<div class="modal-field"><label>بیو (پیام خوش‌آمدگویی)</label><textarea id="botBio" rows="4">${escapeHtml(adminBot.bio || '')}</textarea><div class="small-note">این متن هنگام مشاهده پروفایل ربات نمایش داده می‌شود.</div></div>
<button class="btn btn-primary btn-block" onclick="saveBot()">💾 ذخیره تغییرات ربات</button>
`;
}
async function uploadBotAvatar(input) {
if (!input.files || !input.files[0]) return;
const formData = new FormData();
formData.append('avatar', input.files[0]);
try {
const res = await fetch('?action=admin_upload_bot_avatar', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
input.value = '';
return;
}
adminBot.avatar = data.avatar;
renderBotEditForm();
showToast('عکس ربات آپلود شد');
} catch (e) {
showToast('خطا در آپلود');
}
input.value = '';
}
async function removeBotAvatar() {
if (!adminBot || !adminBot.avatar) {
showToast('عکسی برای حذف وجود ندارد');
return;
}
if (!confirm('عکس ربات حذف شود؟')) return;
try {
const res = await fetch('?action=admin_remove_bot_avatar', {method: 'POST'});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
adminBot.avatar = '';
renderBotEditForm();
showToast('عکس ربات حذف شد');
} catch (e) {
showToast('خطا');
}
}
async function saveBot() {
const formData = new FormData();
formData.append('username', document.getElementById('botUsername').value);
formData.append('name', document.getElementById('botName').value);
formData.append('bio', document.getElementById('botBio').value);
const res = await fetch('?action=admin_update_bot', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
showToast('ربات ذخیره شد');
await loadAdminBot();
}
async function saveExchangeRate() {
const rate = parseInt(document.getElementById('adminExchangeRate').value) || 0;
if (rate < 1 || rate > 100000000) {
showToast('نرخ تبدیل نامعتبر است (1 تا 100,000,000)');
return;
}
const formData = new FormData();
formData.append('rate', rate);
try {
const res = await fetch('?action=admin_set_exchange_rate', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
spcToToman = data.rate;
showToast('نرخ تبدیل ذخیره شد: 1 SPC = ' + formatNumber(data.rate) + ' تومان');
} catch (e) {
showToast('خطا در ذخیره نرخ');
}
}
function renderAdminStats() {
if (!adminStats) return;
const items = [
{label:'کاربران', value:adminStats.total_users, icon:'👥', cls:''},
{label:'کاربران فعال', value:adminStats.active_users, icon:'✅', cls:''},
{label:'پرمیوم فعال', value:adminStats.premium_users, icon:'⭐', cls:'vip'},
{label:'پرمیوم منقضی', value:adminStats.expired_premium_users, icon:'⏰', cls:''},
{label:'قابل جستجو', value:adminStats.searchable_users, icon:'🔍', cls:''},
{label:'مسدودها', value:adminStats.blocked_users, icon:'⛔', cls:''},
{label:'ادمین‌ها', value:adminStats.admin_users, icon:'👑', cls:''},
{label:'ربات‌ها', value:adminStats.bot_users, icon:'🤖', cls:''},
{label:'چت‌ها', value:adminStats.total_chats, icon:'💬', cls:''},
{label:'خصوصی', value:adminStats.private_chats, icon:'💬', cls:''},
{label:'گروه‌ها', value:adminStats.group_chats, icon:'👥', cls:''},
{label:'کانال‌ها', value:adminStats.channel_chats, icon:'📣', cls:''},
{label:'پیام‌ها', value:adminStats.total_messages, icon:'✉️', cls:''},
{label:'پیام‌های امروز', value:adminStats.today_messages, icon:'✨', cls:''},
{label:'SPC در گردش', value:formatNumber(adminStats.total_spc_in_circulation || 0), icon:'💰', cls:'spc'},
{label:'حجم فایل‌ها', value:formatBytes(adminStats.upload_size), icon:'📦', cls:''},
];
document.getElementById('statsGrid').innerHTML = items.map(i => `
<div class="stat-card ${i.cls}">
<div class="stat-ico">${i.icon}</div>
<div class="stat-value">${i.value}</div>
<div class="stat-label">${i.label}</div>
</div>
`).join('');
}
function renderAdminUsers() {
const q = document.getElementById('adminUserSearch').value.trim().toLowerCase();
const filtered = q
? adminUsers.filter(u => u.username.toLowerCase().includes(q) || (u.name || '').toLowerCase().includes(q))
: adminUsers;
const tbody = document.getElementById('adminUsersTable');
if (!filtered.length) {
tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--t2);padding:26px">کاربری یافت نشد</td></tr>';
return;
}
tbody.innerHTML = filtered.map(u => {
const role = u.is_bot
? '<span class="badge info">🤖 ربات</span>'
: (u.is_admin ? '<span class="badge warning">👑 ادمین</span>' : '<span class="badge muted">👤 کاربر</span>');
const status = u.blocked
? '<span class="badge danger">⛔ مسدود</span>'
: (u.active ? '<span class="badge success">✅ فعال</span>' : '<span class="badge danger">غیرفعال</span>');
const searchable = u.privacy_searchable ? '<span class="badge success">✅ فعال</span>' : '<span class="badge muted">غیرفعال</span>';
const verified = u.verified ? getVerifiedBadge() : '';
const premiumBadge = u.premium ? `<span class="badge vip">⭐ ${u.premium_days_left}d</span>` : '<span class="badge muted">—</span>';
const walletBadge = `<span class="badge spc">💰 ${formatNumber(u.wallet_balance || 0)}</span>`;
const selfBadge = u.id === currentUser.id ? ' <span class="badge info">شما</span>' : '';
return `
<tr>
<td>@${escapeHtml(u.username)}${selfBadge} ${verified}</td>
<td>${escapeHtml(u.name || '-')}</td>
<td>${role}</td>
<td>${status}</td>
<td>${premiumBadge}</td>
<td>${walletBadge}</td>
<td>${searchable}</td>
<td>${formatDate(u.created_at)}</td>
<td>
<div class="action-buttons">
${!u.is_bot ? '<button class="mini-btn" onclick="openUserModal(\'' + u.id + '\')">✏️ ویرایش</button>' : ''}
${!u.is_bot && u.id !== currentUser.id ? '<button class="mini-btn danger" onclick="deleteUser(\'' + u.id + '\')">🗑️ حذف</button>' : ''}
</div>
</td>
</tr>
`;
}).join('');
}
function renderAdminChats() {
const filter = document.getElementById('adminChatFilter').value;
const q = document.getElementById('adminChatSearch').value.trim().toLowerCase();
let filtered = adminChats;
if (filter === 'group') filtered = filtered.filter(c => c.type === 'group');
else if (filter === 'channel') filtered = filtered.filter(c => c.type === 'channel');
else if (filter === 'verified') filtered = filtered.filter(c => c.verified);
if (q) filtered = filtered.filter(c => c.name.toLowerCase().includes(q) || c.owner_username.toLowerCase().includes(q));
const list = document.getElementById('adminChatsList');
if (!filtered.length) {
list.innerHTML = '<div style="padding:30px;text-align:center;color:var(--t2)">چتی یافت نشد</div>';
return;
}
list.innerHTML = filtered.map(c => {
const avatarPath = c.avatar_image || '';
const avatarHtml = avatarPath
? '<img src="' + escapeHtml(resolveFileUrl(avatarPath)) + '" alt="">'
: escapeHtml((c.name || '?')[0].toUpperCase());
const typeBadge = c.type === 'channel'
? '<span class="badge success">📣 کانال</span>'
: '<span class="badge info">👥 گروه</span>';
const verifiedBadge = c.verified ? getVerifiedBadge() : '';
const ownerPremiumBadge = c.owner_premium ? ' ' + getVipBadge(true) : '';
const verifiedBtnText = c.verified ? '❌ برداشتن تایید' : '✓ تایید چت';
const verifiedBtnClass = c.verified ? 'btn-danger' : 'btn-primary';
return `
<div class="chat-card">
<div class="chat-card-head">
<div class="chat-card-avatar">${avatarHtml}</div>
<div class="chat-card-title">
<div class="name">
${escapeHtml(c.name || 'بدون نام')}
${verifiedBadge}
${typeBadge}
</div>
<div class="meta">
👤 مالک: <strong>@${escapeHtml(c.owner_username || 'نامشخص')}</strong>${ownerPremiumBadge}
${c.description ? ' • ' + escapeHtml(c.description) : ''}
</div>
</div>
</div>
<div class="chat-card-stats">
<span>👥 ${c.members_count} عضو</span>
<span>💬 ${c.messages_count} پیام</span>
<span>🕐 ${formatDate(c.created_at)}</span>
${c.last_message ? '<span>📨 ' + escapeHtml(c.last_message) + '</span>' : ''}
</div>
<div class="chat-card-actions">
<button class="${verifiedBtnClass}" style="padding:8px 14px;font-size:12px;border-radius:10px" onclick="toggleChatVerified('${c.id}')">${verifiedBtnText}</button>
<button class="btn-danger" style="padding:8px 14px;font-size:12px;border-radius:10px" onclick="adminDeleteChat('${c.id}')">🗑️ حذف کامل</button>
</div>
</div>
`;
}).join('');
}
async function toggleChatVerified(chatId) {
const formData = new FormData();
formData.append('chat_id', chatId);
const res = await fetch('?action=admin_toggle_chat_verified', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
const chat = adminChats.find(c => c.id === chatId);
if (chat) chat.verified = data.verified;
renderAdminChats();
await loadChats();
showToast(data.verified ? '✅ چت تایید شد' : 'تایید چت برداشته شد');
}
async function adminDeleteChat(chatId) {
if (!confirm('این چت به طور کامل حذف شود؟ این عمل قابل بازگشت نیست و تمام پیام‌ها و فایل‌ها پاک می‌شوند.')) return;
const formData = new FormData();
formData.append('chat_id', chatId);
const res = await fetch('?action=admin_delete_chat', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
showToast('چت حذف شد');
await loadAdminChats();
await loadChats(true);
}
function updatePremiumActionUI() {
const action = document.getElementById('adminPremiumAction').value;
const daysField = document.getElementById('adminPremiumDaysField');
if (action === 'remove' || action === 'keep') {
daysField.style.display = 'none';
} else {
daysField.style.display = 'block';
}
}
function renderAdminPremiumStatus(u) {
const statusEl = document.getElementById('adminPremiumStatus');
if (!u) {
statusEl.innerHTML = '<div style="padding:10px 14px;background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.2);border-radius:12px;font-size:12.5px;color:var(--t2)">🆕 کاربر جدید - اشتراک پرمیوم ندارد</div>';
return;
}
if (u.premium) {
statusEl.innerHTML = `
<div style="padding:12px 14px;background:linear-gradient(135deg,rgba(255,215,0,.12),rgba(255,165,0,.04));border:1.5px solid rgba(255,215,0,.3);border-radius:12px">
<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
<span style="font-size:24px">⭐</span>
<strong style="color:var(--vip-gold);font-size:14px">اشتراک پرمیوم فعال</strong>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px">
<div><span style="color:var(--t2)">📅 انقضا:</span> <strong>${formatDateTime(u.premium_until)}</strong></div>
<div><span style="color:var(--t2)">⏳ باقیمانده:</span> <strong>${u.premium_days_left} روز</strong></div>
</div>
</div>
`;
} else {
const hadPremium = u.premium_until > 0;
statusEl.innerHTML = `
<div style="padding:10px 14px;background:var(--input);border:1px solid var(--border);border-radius:12px;font-size:12.5px">
<span style="color:var(--t2)">${hadPremium ? '⏰ اشتراک پرمیوم منقضی شده' : '❌ بدون اشتراک پرمیوم'}</span>
</div>
`;
}
}
function renderAdminWalletStatus(u) {
const statusEl = document.getElementById('adminWalletStatus');
if (!u) {
statusEl.innerHTML = '<div style="padding:10px 14px;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:12px;font-size:12.5px;color:var(--t2)">🆕 کاربر جدید - موجودی: 0 SPC</div>';
return;
}
const balance = u.wallet_balance || 0;
const tomanValue = balance * spcToToman;
statusEl.innerHTML = `
<div style="padding:12px 14px;background:linear-gradient(135deg,rgba(16,185,129,.12),rgba(5,150,105,.04));border:1.5px solid rgba(16,185,129,.3);border-radius:12px">
<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
<span style="font-size:24px">💰</span>
<strong style="color:var(--spc-green);font-size:14px">موجودی کیف پول</strong>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px">
<div><span style="color:var(--t2)">💎 SPC:</span> <strong>${formatNumber(balance)}</strong></div>
<div><span style="color:var(--t2)">💵 معادل:</span> <strong>${formatToman(tomanValue)}</strong></div>
</div>
</div>
`;
}
function openUserModal(userId = null) {
openModal('adminUserModal');
document.getElementById('adminUserId').value = userId || '';
document.getElementById('adminPremiumAction').value = 'keep';
document.getElementById('adminPremiumDays').value = 30;
document.getElementById('adminWalletAction').value = 'keep';
document.getElementById('adminWalletAmount').value = 0;
updatePremiumActionUI();
if (!userId) {
document.getElementById('adminUserModalTitle').textContent = 'افزودن کاربر جدید';
document.getElementById('adminPasswordLabel').textContent = '🔑 رمز عبور';
document.getElementById('adminUserUsername').value = '';
document.getElementById('adminUserName').value = '';
document.getElementById('adminUserBio').value = '';
document.getElementById('adminUserPassword').value = '';
document.getElementById('adminUserActive').checked = true;
document.getElementById('adminUserBlocked').checked = false;
document.getElementById('adminUserIsAdmin').checked = false;
document.getElementById('adminUserSearchable').checked = true;
document.getElementById('adminUserVerified').checked = false;
renderAdminPremiumStatus(null);
renderAdminWalletStatus(null);
return;
}
const u = adminUsers.find(x => x.id === userId);
if (!u) return;
document.getElementById('adminUserModalTitle').textContent = 'ویرایش کاربر';
document.getElementById('adminPasswordLabel').textContent = '🔑 رمز عبور جدید (اختیاری)';
document.getElementById('adminUserUsername').value = u.username;
document.getElementById('adminUserName').value = u.name || '';
document.getElementById('adminUserBio').value = u.bio || '';
document.getElementById('adminUserPassword').value = '';
document.getElementById('adminUserActive').checked = !!u.active;
document.getElementById('adminUserBlocked').checked = !!u.blocked;
document.getElementById('adminUserIsAdmin').checked = !!u.is_admin;
document.getElementById('adminUserSearchable').checked = !!u.privacy_searchable;
document.getElementById('adminUserVerified').checked = !!u.verified;
renderAdminPremiumStatus(u);
renderAdminWalletStatus(u);
}
async function saveAdminUser() {
const user_id = document.getElementById('adminUserId').value;
const action = user_id ? 'admin_update_user' : 'admin_create_user';
const formData = new FormData();
if (user_id) formData.append('user_id', user_id);
formData.append('username', document.getElementById('adminUserUsername').value);
formData.append('name', document.getElementById('adminUserName').value);
formData.append('bio', document.getElementById('adminUserBio').value);
formData.append('password', document.getElementById('adminUserPassword').value);
formData.append('active', document.getElementById('adminUserActive').checked ? '1' : '');
formData.append('blocked', document.getElementById('adminUserBlocked').checked ? '1' : '');
formData.append('is_admin', document.getElementById('adminUserIsAdmin').checked ? '1' : '');
formData.append('privacy_searchable', document.getElementById('adminUserSearchable').checked ? '1' : '');
formData.append('verified', document.getElementById('adminUserVerified').checked ? '1' : '');
if (user_id) {
const premiumAction = document.getElementById('adminPremiumAction').value;
formData.append('premium_action', premiumAction);
if (premiumAction === 'add_days' || premiumAction === 'set_days') {
formData.append('premium_days', document.getElementById('adminPremiumDays').value);
}
const walletAction = document.getElementById('adminWalletAction').value;
if (walletAction !== 'keep') {
const walletAmount = parseInt(document.getElementById('adminWalletAmount').value) || 0;
const walletFormData = new FormData();
walletFormData.append('user_id', user_id);
walletFormData.append('wallet_action', walletAction);
walletFormData.append('amount', walletAmount);
try {
const walletRes = await fetch('?action=admin_update_wallet', {method: 'POST', body: walletFormData});
const walletData = await walletRes.json();
if (walletData.error) {
showToast('خطا در کیف پول: ' + walletData.error);
return;
}
} catch (e) {
showToast('خطا در به‌روزرسانی کیف پول');
return;
}
}
} else {
const setDays = confirm('آیا می‌خواهید برای این کاربر اشتراک پرمیوم فعال کنید؟\n\nبله = 30 روز پرمیوم\nخیر = بدون پرمیوم');
if (setDays) {
formData.append('premium_days', '30');
}
const initialWallet = prompt('موجودی اولیه کیف پول (SPC) - برای 0 خالی بگذارید:', '0');
if (initialWallet !== null && initialWallet !== '') {
const walletVal = parseInt(initialWallet) || 0;
if (walletVal >= 0) {
formData.append('initial_wallet', walletVal);
}
}
}
const res = await fetch('?action=' + action, {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
closeModal('adminUserModal');
showToast('ذخیره شد');
if (user_id === currentUser.id) {
setTimeout(() => window.location.reload(), 500);
return;
}
await loadAdminData();
}
async function deleteUser(userId) {
if (!confirm('این کاربر حذف شود؟ این عمل قابل بازگشت نیست.')) return;
const formData = new FormData();
formData.append('user_id', userId);
const res = await fetch('?action=admin_delete_user', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
showToast('کاربر حذف شد');
await loadAdminData();
}
async function openMembersModal() {
if (!currentChat) return;
document.getElementById('membersTitle').textContent = 'اعضای ' + (currentChat.display_name || currentChat.name || '');
openModal('membersModal');
const canManage = currentChat.owner_id === currentUser.id || currentUser.is_admin;
document.getElementById('addMemberBtnInModal').style.display = (canManage && currentChat.type !== 'private' && currentChat.type !== 'saved') ? 'inline-flex' : 'none';
try {
const res = await fetch('?action=get_chat_members&chat_id=' + encodeURIComponent(currentChat.id));
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
const members = data.members || [];
const list = document.getElementById('membersList');
if (!members.length) {
list.innerHTML = '<div style="padding:16px;text-align:center;color:var(--t2)">هیچ عضوی یافت نشد</div>';
return;
}
list.innerHTML = members.map(m => {
const avatarHtml = m.avatar ? '<img src="' + escapeHtml(resolveFileUrl(m.avatar)) + '" alt="">' : escapeHtml((m.name || m.username || '?')[0].toUpperCase());
const badge = m.is_owner ? '<span class="member-badge">مالک</span>' : (m.is_admin ? '<span class="member-badge">ادمین</span>' : '');
const badges = badgesHtml(m);
const onlineStatus = m.online ? '<span class="online-dot"></span> آنلاین' : 'آخرین بازدید: ' + (m.last_seen_text || 'نامشخص');
let removeBtn = '';
const canRemove = canManage && !m.is_owner && m.id !== currentUser.id && currentChat.type !== 'private' && currentChat.type !== 'saved';
if (canRemove) {
removeBtn = `<div class="member-actions"><button class="mini-btn danger" onclick="removeMemberFromChat('${m.id}')">🚫 حذف</button></div>`;
}
return `
<div class="member-list-item">
<div class="member-avatar">${avatarHtml}</div>
<div class="member-info">
<div class="member-name">${escapeHtml(m.name || m.username)} ${badges} ${badge}</div>
<div class="member-status">${onlineStatus}</div>
</div>
${removeBtn}
</div>
`;
}).join('');
} catch (e) {
showToast('خطا در دریافت اعضا');
}
}
function openMembersModalFromProfile() {
closeModal('chatProfileModal');
openMembersModal();
}
function openChatProfile() {
if (!currentChat || currentChat.type === 'saved' || currentChat.type === 'private') return;
document.getElementById('chatProfileTitle').textContent = currentChat.name || 'پروفایل چت';
document.getElementById('chatProfileIcon').textContent = currentChat.type === 'channel' ? '📣' : '👥';
const avatarEl = document.getElementById('chatProfileAvatar');
const avatarPath = currentChat.avatar_image || '';
avatarEl.innerHTML = avatarPath
? '<img src="' + escapeHtml(resolveFileUrl(avatarPath)) + '" alt="">'
: escapeHtml((currentChat.name || '?')[0].toUpperCase());
let nameHtml = escapeHtml(currentChat.name || 'بدون نام');
if (currentChat.verified) nameHtml += ' ' + getVerifiedBadge();
document.getElementById('chatProfileName').innerHTML = nameHtml;
document.getElementById('chatProfileType').textContent = currentChat.type === 'channel' ? '📣 کانال' : '👥 گروه';
document.getElementById('chatProfileDesc').textContent = currentChat.description || 'توضیحاتی ثبت نشده است';
const ownerPremiumBadge = currentChat.owner_premium ? ' ' + getVipBadge(true) : '';
document.getElementById('chatProfileOwner').innerHTML = (currentChat.owner_name ? ('@' + currentChat.owner_username + ownerPremiumBadge) : 'نامشخص');
const membersCount = currentChat.members ? currentChat.members.length : 0;
document.getElementById('chatProfileStats').innerHTML = `👥 ${membersCount} عضو<br>💬 ${currentMessages.length} پیام در این گفتگو`;
openModal('chatProfileModal');
}
async function removeMemberFromChat(userId) {
if (!currentChat) return;
const member = currentChat.members?.includes(userId);
if (!member) return;
const user = adminUsers.find(u => u.id === userId);
const userName = user ? (user.name || user.username) : 'این کاربر';
if (!confirm('آیا از حذف ' + userName + ' از این چت اطمینان دارید؟')) return;
const formData = new FormData();
formData.append('chat_id', currentChat.id);
formData.append('user_id', userId);
try {
const res = await fetch('?action=remove_member_from_chat', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
showToast('عضو حذف شد');
await openMembersModal();
await loadChats();
refreshMessages(true);
} catch (e) {
showToast('خطا در حذف عضو');
}
}
async function openAddMembersModal() {
if (!currentChat) return;
closeModal('chatMenuModal');
addMembersSelected = [];
addMembersList = [];
document.getElementById('addMemberSearch').value = '';
renderAddMemberChips();
renderAddMembersList();
openModal('addMembersModal');
try {
const res = await fetch('?action=get_users');
const data = await res.json();
addMembersList = (data.users || []).filter(u => {
return !(currentChat.members || []).includes(u.id);
});
renderAddMembersList();
} catch (e) {
showToast('خطا در دریافت کاربران');
}
}
function renderAddMembersList() {
const list = document.getElementById('addMembersList');
if (!addMembersList.length) {
list.innerHTML = '<div style="padding:16px;text-align:center;color:var(--t2);font-size:13px">کاربری برای افزودن یافت نشد</div>';
return;
}
list.innerHTML = addMembersList.map(u => {
const avatarHtml = u.avatar ? '<img src="' + escapeHtml(resolveFileUrl(u.avatar)) + '" alt="">' : escapeHtml((u.name || u.username || '?')[0].toUpperCase());
const badges = badgesHtml(u);
return `
<label class="user-option">
<input type="checkbox" name="addMemberPick" value="${u.id}" onchange="updateAddMembersSelected()" ${addMembersSelected.includes(u.id) ? 'checked' : ''}>
<div class="user-option-avatar">${avatarHtml}</div>
<div class="user-option-info">
<div class="user-option-name">${escapeHtml(u.name || u.username)}${badges}</div>
<div class="user-option-username">@${escapeHtml(u.username)}</div>
</div>
</label>
`;
}).join('');
}
function updateAddMembersSelected() {
const checked = Array.from(document.querySelectorAll('#addMembersList input:checked'));
addMembersSelected = checked.map(i => i.value);
renderAddMemberChips();
}
function renderAddMemberChips() {
const box = document.getElementById('addMemberChips');
if (!addMembersSelected.length) {
box.innerHTML = '';
return;
}
box.innerHTML = addMembersSelected.map(id => {
const u = addMembersList.find(x => x.id === id) || {name: 'کاربر', username: 'کاربر'};
return '<span class="member-chip">' + escapeHtml(u.name || u.username || 'کاربر') + '<button type="button" onclick="event.preventDefault();removeAddMember(\'' + id + '\')">✖️</button></span>';
}).join('');
}
function removeAddMember(id) {
addMembersSelected = addMembersSelected.filter(m => m !== id);
renderAddMemberChips();
const cb = document.querySelector('#addMembersList input[value="' + id + '"]');
if (cb) cb.checked = false;
}
let addMemberSearchTimeout = null;
function searchAddMembers() {
clearTimeout(addMemberSearchTimeout);
const q = document.getElementById('addMemberSearch').value.trim();
if (!q) {
renderAddMembersList();
return;
}
addMemberSearchTimeout = setTimeout(async () => {
try {
const res = await fetch('?action=search_users&q=' + encodeURIComponent(q));
const data = await res.json();
addMembersList = (data.users || []).filter(u => {
return !(currentChat.members || []).includes(u.id);
});
renderAddMembersList();
} catch (e) {
showToast('خطا در جستجو');
}
}, 300);
}
async function submitAddMembers() {
if (!addMembersSelected.length) {
showToast('حداقل یک کاربر انتخاب کنید');
return;
}
const formData = new FormData();
formData.append('chat_id', currentChat.id);
addMembersSelected.forEach(m => formData.append('members[]', m));
try {
const res = await fetch('?action=add_members_to_chat', {method: 'POST', body: formData});
const data = await res.json();
if (data.error) {
showToast(data.error);
return;
}
closeModal('addMembersModal');
showToast((data.added_count || 0) + ' عضو اضافه شد');
await loadChats();
refreshMessages(true);
} catch (e) {
showToast('خطا در افزودن اعضا');
}
}
function saveDraft(chatId, text) {
if (!chatId) return;
if (text) {
localStorage.setItem('draft_' + chatId, text);
} else {
localStorage.removeItem('draft_' + chatId);
}
}
function loadDraft(chatId) {
if (!chatId) return '';
return localStorage.getItem('draft_' + chatId) || '';
}
const msgInput = document.getElementById('messageInput');
msgInput.addEventListener('input', function() {
this.style.height = 'auto';
this.style.height = Math.min(this.scrollHeight, 120) + 'px';
if (currentChat) {
saveDraft(currentChat.id, this.value);
}
});
msgInput.addEventListener('keypress', function(e) {
if (e.key === 'Enter' && !e.shiftKey) {
e.preventDefault();
sendMessage();
}
});
document.getElementById('fileInput').addEventListener('change', function() {
if (this.files && this.files[0]) openAttachmentModal(this.files[0], 'file');
this.value = '';
});
document.getElementById('imageInput').addEventListener('change', function() {
if (this.files && this.files[0]) openAttachmentModal(this.files[0], 'image');
this.value = '';
});
document.getElementById('videoInput').addEventListener('change', function() {
if (this.files && this.files[0]) openAttachmentModal(this.files[0], 'video');
this.value = '';
});
document.querySelectorAll('.modal-overlay').forEach(overlay => {
overlay.addEventListener('click', e => {
if (e.target === overlay) {
overlay.classList.remove('active');
if (fabOpen) closeFab();
if (attachOpen) closeAttach();
}
});
});
document.addEventListener('click', function(e) {
const fab = document.getElementById('fabContainer');
if (fabOpen && !fab.contains(e.target)) closeFab();
const attachPop = document.getElementById('attachPop');
const attachBtn = document.querySelector('.message-input .round-btn[onclick="toggleAttach()"]');
if (attachOpen && !attachPop.contains(e.target) && attachBtn && !attachBtn.contains(e.target)) closeAttach();
if (!e.target.closest('.reaction-picker') && !e.target.closest('.msg-action-inline')) {
closeReactionPickers();
}
});
async function applyLiveChange() {
try {
if (currentChat) {
await refreshMessages();
}
await loadChats();
} catch (e) {}
}
async function liveLoop() {
if (liveStopped || liveRunning) return;
liveRunning = true;
while (!liveStopped) {
try {
const url = '?action=poll_state&wait=25' + (liveState ? '&known=' + encodeURIComponent(liveState) : '');
const res = await fetch(url, {cache: 'no-store'});
const data = await res.json();
if (data && data.state) {
const changed = liveState && data.state !== liveState;
liveState = data.state;
if (changed) await applyLiveChange();
}
} catch (e) {
await new Promise(r => setTimeout(r, 3000));
}
}
liveRunning = false;
}
function startLiveUpdates() {
liveStopped = false;
liveLoop();
}
async function init() {
initTheme();
await loadChats(true);
startLiveUpdates();
}
init();
</script>
</body>
</html>
