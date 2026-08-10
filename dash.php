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
    return is_array($d) ? $d : [];
}

function write_json($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function generate_id() {
    return uniqid('x_', true) . '_' . bin2hex(random_bytes(4));
}

function find_user_by_id($id) {
    foreach (read_json(USERS_FILE) as $u) {
        if ($u['id'] === $id) return $u;
    }
    return null;
}

function username_exists($username, $exclude_id = null) {
    $username = mb_strtolower(trim($username));
    foreach (read_json(USERS_FILE) as $u) {
        if ($exclude_id && $u['id'] === $exclude_id) continue;
        if (mb_strtolower(trim($u['username'])) === $username) return true;
    }
    return false;
}

function current_user() {
    if (!isset($_SESSION['user_id'])) return null;
    return find_user_by_id($_SESSION['user_id']);
}

function is_admin_user($user) {
    return !empty($user['is_admin']);
}

function message_preview($m) {
    if (!empty($m['file_path'])) {
        $caption = trim($m['caption'] ?? '');
        if ($caption !== '') return $caption;
        if (!empty($m['file_name'])) return '📎 ' . $m['file_name'];
        return '📎 فایل';
    }
    return trim($m['text'] ?? '');
}

function unlink_message_file($m) {
    if (!empty($m['file_path'])) {
        $path = __DIR__ . '/' . ltrim($m['file_path'], '/');
        if (is_file($path)) @unlink($path);
    }
}

function delete_chat_data($chat_id) {
    $chats = read_json(CHATS_FILE);
    $new_chats = [];
    foreach ($chats as $c) {
        if (($c['id'] ?? '') === $chat_id) continue;
        $new_chats[] = $c;
    }
    write_json(CHATS_FILE, $new_chats);

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
}

function update_messages_username($user_id, $new_username) {
    $messages = read_json(MESSAGES_FILE);
    $changed = false;
    foreach ($messages as &$m) {
        if (($m['user_id'] ?? '') === $user_id) {
            $m['username'] = $new_username;
            $changed = true;
        }
    }
    unset($m);
    if ($changed) write_json(MESSAGES_FILE, $messages);
}

function dir_size($dir) {
    if (!is_dir($dir)) return 0;
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->isFile()) $size += $file->getSize();
    }
    return $size;
}

function safe_user($u) {
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
    ];
}

function get_link_preview($url) {
    $url = trim($url);
    if (!preg_match('/^https?:\/\//i', $url)) $url = 'https://' . $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;

    $previews = read_json(PREVIEWS_FILE);
    $url_key = md5($url);
    if (isset($previews[$url_key])) {
        $p = $previews[$url_key];
        if ((time() - ($p['cached_at'] ?? 0)) < 86400) return $p;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_ENCODING => '',
    ]);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $http_code >= 400) return null;
    $html = mb_substr($html, 0, 500000);

    $title = '';
    $description = '';
    $image = '';
    $site = parse_url($url, PHP_URL_HOST) ?: '';

    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
        $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $description = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $description = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $image = $m[1];
        if (!preg_match('/^https?:\/\//i', $image)) {
            $base = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
            $image = rtrim($base, '/') . '/' . ltrim($image, '/');
        }
    }

    $title = trim($title);
    $description = trim($description);
    if (mb_strlen($description) > 200) $description = mb_substr($description, 0, 197) . '...';

    $preview = [
        'url' => $url,
        'title' => $title,
        'description' => $description,
        'image' => $image,
        'site' => $site,
        'cached_at' => time()
    ];

    if ($title || $description || $image) {
        $previews[$url_key] = $preview;
        if (count($previews) > 500) {
            $oldest_keys = array_slice(array_keys($previews), 0, 100);
            foreach ($oldest_keys as $k) unset($previews[$k]);
        }
        write_json(PREVIEWS_FILE, $previews);
        return $preview;
    }

    return null;
}

function extract_urls($text) {
    $urls = [];
    if (preg_match_all('/(https?:\/\/[^\s<>\'"]+)|(?:^|\s)((?:www\.)[^\s<>\'"]+)|((?:[a-zA-Z0-9-]+\.)+(?:com|net|org|ir|io|co|info|me|tv|app|dev|xyz)(?:\/[^\s<>\'"]*)?)/i', $text, $matches)) {
        foreach ($matches[0] as $m) {
            $m = trim($m);
            if ($m !== '' && !preg_match('/^https?:\/\//i', $m)) $m = 'https://' . $m;
            $urls[] = $m;
        }
    }
    return array_unique($urls);
}

function get_unread_count($chat_id, $user_id) {
    $reads = read_json(READS_FILE);
    $last_read_id = $reads[$chat_id][$user_id] ?? null;

    $messages = read_json(MESSAGES_FILE);
    $chat_msgs = [];
    foreach ($messages as $m) {
        if (($m['chat_id'] ?? '') === $chat_id) $chat_msgs[] = $m;
    }
    usort($chat_msgs, fn($a, $b) => ($a['created_at'] ?? 0) - ($b['created_at'] ?? 0));

    if (empty($chat_msgs)) return 0;
    if (!$last_read_id) {
        $count = 0;
        foreach ($chat_msgs as $m) {
            if (($m['user_id'] ?? '') !== $user_id) $count++;
        }
        return $count;
    }

    $found = false;
    $count = 0;
    foreach ($chat_msgs as $m) {
        if ($found && ($m['user_id'] ?? '') !== $user_id) {
            $count++;
        }
        if (($m['id'] ?? '') === $last_read_id) {
            $found = true;
        }
    }
    return $count;
}

function is_message_seen($message, $chat_members, $current_user_id) {
    if (($message['user_id'] ?? '') !== $current_user_id) return true;
    $seen_by = $message['seen_by'] ?? [];
    foreach ($chat_members as $mid) {
        if ($mid !== $current_user_id && in_array($mid, $seen_by)) return true;
    }
    return false;
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $user = current_user();
    if (!$user) die(json_encode(['error' => 'لاگین نیستید']));

    $action = $_GET['action'];

    switch ($action) {
        case 'logout':
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            echo json_encode(['success' => true, 'redirect' => 'index.php']);
            exit;

        case 'get_chats':
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
                    foreach ($users as $u) if ($u['id'] === $other_id) { $other = $u; break; }
                    if (!$other) continue;

                    $display = trim($other['name'] ?? '') !== '' ? $other['name'] : $other['username'];
                    $chat['display_name'] = $display;
                    $chat['avatar'] = mb_substr($display, 0, 1);
                    $chat['avatar_path'] = $other['avatar'] ?? '';
                    $chat['other_user_id'] = $other_id;
                    $chat['other_is_bot'] = !empty($other['is_bot']);
                } else {
                    $display = $chat['name'] ?? 'بدون نام';
                    $chat['display_name'] = $display;
                    $chat['avatar'] = mb_substr($display, 0, 1);
                    $chat['avatar_path'] = $chat['avatar_image'] ?? '';
                }

                $chat_messages = array_filter($messages, fn($m) => ($m['chat_id'] ?? '') === $chat['id']);
                usort($chat_messages, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));
                $last = $chat_messages[0] ?? null;

                $chat['last_message'] = $last ? mb_substr(message_preview($last), 0, 60) : '';
                $chat['last_time'] = $last ? ($last['created_at'] ?? time()) : ($chat['created_at'] ?? time());
                $chat['unread_count'] = get_unread_count($chat['id'], $user['id']);
                $chat['last_is_mine'] = $last ? (($last['user_id'] ?? '') === $user['id']) : false;
                $chat['last_seen'] = $last ? is_message_seen($last, $chat['members'] ?? [], $user['id']) : false;
                $chat['last_user_id'] = $last ? ($last['user_id'] ?? '') : '';
                $chat['last_username'] = $last ? ($last['username'] ?? '') : '';
                $my_chats[] = $chat;
            }

            usort($my_chats, fn($a, $b) => ($b['last_time'] ?? 0) - ($a['last_time'] ?? 0));
            echo json_encode(['chats' => $my_chats]);
            exit;

        case 'get_messages':
            $chat_id = $_GET['chat_id'] ?? '';
            if (!$chat_id) die(json_encode(['error' => 'چت نامعتبر است']));

            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) if ($c['id'] === $chat_id) { $chat = $c; break; }

            if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) {
                die(json_encode(['error' => 'دسترسی غیرمجاز است']));
            }

            $messages = read_json(MESSAGES_FILE);
            $chat_messages = [];
            foreach ($messages as $m) {
                if (($m['chat_id'] ?? '') === $chat_id) $chat_messages[] = $m;
            }
            usort($chat_messages, fn($a, $b) => ($a['created_at'] ?? 0) - ($b['created_at'] ?? 0));

            echo json_encode([
                'messages' => $chat_messages,
                'chat' => $chat,
                'current_user' => safe_user($user)
            ]);
            exit;

        case 'mark_chat_read':
            $chat_id = $_POST['chat_id'] ?? '';
            if (!$chat_id) die(json_encode(['error' => 'چت نامعتبر است']));

            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) if ($c['id'] === $chat_id) { $chat = $c; break; }
            if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) {
                die(json_encode(['error' => 'دسترسی غیرمجاز است']));
            }

            $messages = read_json(MESSAGES_FILE);
            $chat_msgs = [];
            foreach ($messages as $m) {
                if (($m['chat_id'] ?? '') === $chat_id) $chat_msgs[] = $m;
            }
            usort($chat_msgs, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));

            if (empty($chat_msgs)) die(json_encode(['success' => true]));

            $last_msg_id = $chat_msgs[0]['id'] ?? null;
            if (!$last_msg_id) die(json_encode(['success' => true]));

            $reads = read_json(READS_FILE);
            if (!isset($reads[$chat_id])) $reads[$chat_id] = [];
            $reads[$chat_id][$user['id']] = $last_msg_id;
            write_json(READS_FILE, $reads);

            if (($chat['type'] ?? '') === 'private') {
                $changed = false;
                foreach ($messages as &$m) {
                    if (($m['chat_id'] ?? '') === $chat_id && ($m['user_id'] ?? '') !== $user['id']) {
                        $seen_by = $m['seen_by'] ?? [];
                        if (!in_array($user['id'], $seen_by)) {
                            $seen_by[] = $user['id'];
                            $m['seen_by'] = $seen_by;
                            $changed = true;
                        }
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

            if ($has_file && $_FILES['file']['error'] !== 0) {
                die(json_encode(['error' => 'خطا در آپلود فایل']));
            }

            if (!$has_file && $text === '') {
                die(json_encode(['error' => 'پیام خالی است']));
            }

            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) if ($c['id'] === $chat_id) { $chat = $c; break; }

            if (!$chat || !in_array($user['id'], $chat['members'] ?? [])) {
                die(json_encode(['error' => 'دسترسی غیرمجاز است']));
            }

            if (($chat['type'] ?? '') === 'channel' && ($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) {
                die(json_encode(['error' => 'فقط مالک کانال می‌تواند پیام ارسال کند']));
            }

            $file_path = null;
            $file_type = null;
            $file_name = null;

            if ($has_file) {
                if ($caption === '' && $text !== '') $caption = $text;
                $f = $_FILES['file'];
                $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
                $safe_name = generate_id() . ($ext !== '' ? '.' . $ext : '');
                $dest = UPLOADS_DIR . '/' . $safe_name;

                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    die(json_encode(['error' => 'ذخیره فایل ناموفق بود']));
                }

                $file_path = 'data/uploads/' . $safe_name;
                $file_name = $f['name'];

                $mime = @mime_content_type($dest);
                if ($mime && strpos($mime, 'image/') === 0) $file_type = 'image';
                elseif ($mime && strpos($mime, 'video/') === 0) $file_type = 'video';
                elseif ($mime && strpos($mime, 'audio/') === 0) $file_type = 'audio';
                else $file_type = 'file';
            }

            $link_preview = null;
            $preview_text = $has_file ? $caption : $text;
            if ($preview_text !== '') {
                $urls = extract_urls($preview_text);
                if (!empty($urls)) {
                    $link_preview = get_link_preview($urls[0]);
                }
            }

            $messages = read_json(MESSAGES_FILE);
            $msg = [
                'id' => generate_id(),
                'chat_id' => $chat_id,
                'user_id' => $user['id'],
                'username' => trim($user['name'] ?? '') !== '' ? $user['name'] : $user['username'],
                'text' => $has_file ? '' : $text,
                'caption' => $has_file ? $caption : '',
                'file_path' => $file_path,
                'file_type' => $file_type,
                'file_name' => $file_name,
                'link_preview' => $link_preview,
                'created_at' => time(),
                'edited' => false,
                'seen_by' => [$user['id']]
            ];

            $messages[] = $msg;
            write_json(MESSAGES_FILE, $messages);

            echo json_encode(['success' => true, 'message' => $msg]);
            exit;

        case 'edit_message':
            $msg_id = $_POST['message_id'] ?? '';
            $text = trim($_POST['text'] ?? '');

            $messages = read_json(MESSAGES_FILE);
            $found = false;

            foreach ($messages as &$m) {
                if (($m['id'] ?? '') === $msg_id) {
                    if (($m['user_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) {
                        die(json_encode(['error' => 'دسترسی غیرمجاز است']));
                    }

                    if (!empty($m['file_path'])) {
                        $m['caption'] = $text;
                        $m['text'] = '';
                    } else {
                        $m['text'] = $text;
                        $m['caption'] = '';
                    }

                    $m['edited'] = true;

                    $m['link_preview'] = null;
                    $urls = extract_urls($text);
                    if (!empty($urls)) {
                        $m['link_preview'] = get_link_preview($urls[0]);
                    }

                    $found = true;
                    break;
                }
            }
            unset($m);

            if (!$found) die(json_encode(['error' => 'پیام یافت نشد']));

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
                    foreach ($chats as $c) if ($c['id'] === ($m['chat_id'] ?? '')) { $chat = $c; break; }

                    $is_owner = $chat && ($chat['owner_id'] ?? '') === $user['id'];
                    if (($m['user_id'] ?? '') !== $user['id'] && !$is_owner && empty($user['is_admin'])) {
                        die(json_encode(['error' => 'دسترسی غیرمجاز است']));
                    }

                    unlink_message_file($m);
                    continue;
                }
                $new_msgs[] = $m;
            }

            write_json(MESSAGES_FILE, $new_msgs);
            echo json_encode(['success' => true]);
            exit;

        case 'upload_user_avatar':
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== 0) {
                die(json_encode(['error' => 'فایلی انتخاب نشده است']));
            }

            $f = $_FILES['avatar'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) die(json_encode(['error' => 'فرمت فایل نامعتبر است']));

            $safe_name = $user['id'] . '_' . generate_id() . '.' . $ext;
            $dest = AVATARS_DIR . '/' . $safe_name;

            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                die(json_encode(['error' => 'ذخیره فایل ناموفق بود']));
            }

            $old = $user['avatar'] ?? '';
            if ($old !== '') {
                $old_path = __DIR__ . '/' . ltrim($old, '/');
                if (is_file($old_path)) @unlink($old_path);
            }

            $avatar_path = 'data/avatars/' . $safe_name;

            $users = read_json(USERS_FILE);
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['avatar'] = $avatar_path;
                    break;
                }
            }
            unset($u);
            write_json(USERS_FILE, $users);

            echo json_encode(['success' => true, 'avatar' => $avatar_path]);
            exit;

        case 'upload_chat_avatar':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) if ($c['id'] === $chat_id) { $chat = $c; break; }
            if (!$chat) die(json_encode(['error' => 'چت یافت نشد']));

            if (($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) {
                die(json_encode(['error' => 'دسترسی غیرمجاز است']));
            }
            if (($chat['type'] ?? '') === 'private') {
                die(json_encode(['error' => 'تغییر عکس در چت خصوصی ممکن نیست']));
            }

            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== 0) {
                die(json_encode(['error' => 'فایلی انتخاب نشده است']));
            }

            $f = $_FILES['avatar'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) die(json_encode(['error' => 'فرمت فایل نامعتبر است']));

            $safe_name = $chat_id . '_' . generate_id() . '.' . $ext;
            $dest = AVATARS_DIR . '/' . $safe_name;

            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                die(json_encode(['error' => 'ذخیره فایل ناموفق بود']));
            }

            $old = $chat['avatar_image'] ?? '';
            if ($old !== '') {
                $old_path = __DIR__ . '/' . ltrim($old, '/');
                if (is_file($old_path)) @unlink($old_path);
            }

            $avatar_path = 'data/avatars/' . $safe_name;

            foreach ($chats as &$c) {
                if ($c['id'] === $chat_id) {
                    $c['avatar_image'] = $avatar_path;
                    break;
                }
            }
            unset($c);
            write_json(CHATS_FILE, $chats);

            echo json_encode(['success' => true, 'avatar' => $avatar_path]);
            exit;

        case 'remove_user_avatar':
            $old = $user['avatar'] ?? '';
            if ($old !== '') {
                $old_path = __DIR__ . '/' . ltrim($old, '/');
                if (is_file($old_path)) @unlink($old_path);
            }

            $users = read_json(USERS_FILE);
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['avatar'] = '';
                    break;
                }
            }
            unset($u);
            write_json(USERS_FILE, $users);

            echo json_encode(['success' => true]);
            exit;

        case 'remove_chat_avatar':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);
            $chat = null;
            foreach ($chats as $c) if ($c['id'] === $chat_id) { $chat = $c; break; }
            if (!$chat) die(json_encode(['error' => 'چت یافت نشد']));

            if (($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) {
                die(json_encode(['error' => 'دسترسی غیرمجاز است']));
            }

            $old = $chat['avatar_image'] ?? '';
            if ($old !== '') {
                $old_path = __DIR__ . '/' . ltrim($old, '/');
                if (is_file($old_path)) @unlink($old_path);
            }

            foreach ($chats as &$c) {
                if ($c['id'] === $chat_id) {
                    $c['avatar_image'] = '';
                    break;
                }
            }
            unset($c);
            write_json(CHATS_FILE, $chats);

            echo json_encode(['success' => true]);
            exit;

        case 'create_chat':
            $type = $_POST['type'] ?? 'group';
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $members = $_POST['members'] ?? [];

            if (!in_array($type, ['private', 'group', 'channel'])) {
                die(json_encode(['error' => 'نوع چت نامعتبر است']));
            }

            if (is_string($members)) $members = explode(',', $members);
            $member_ids = [];
            foreach ((array)$members as $mid) {
                $mid = trim((string)$mid);
                if ($mid !== '' && $mid !== $user['id']) $member_ids[] = $mid;
            }

            if ($type === 'private') {
                if (empty($member_ids)) die(json_encode(['error' => 'یک کاربر انتخاب کنید']));
                $member_ids = [$user['id'], $member_ids[0]];
                $name = '';
            } else {
                if ($name === '') die(json_encode(['error' => 'نام الزامی است']));
                $member_ids = array_values(array_unique(array_merge([$user['id']], $member_ids)));
            }

            $chats = read_json(CHATS_FILE);
            $chat = [
                'id' => generate_id(),
                'type' => $type,
                'name' => $name,
                'description' => $description,
                'owner_id' => $user['id'],
                'members' => $member_ids,
                'public_id' => '',
                'avatar_image' => '',
                'created_at' => time()
            ];

            $chats[] = $chat;
            write_json(CHATS_FILE, $chats);

            echo json_encode(['success' => true, 'chat' => $chat]);
            exit;

        case 'update_chat':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);

            foreach ($chats as &$c) {
                if (($c['id'] ?? '') === $chat_id) {
                    if (($c['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) {
                        die(json_encode(['error' => 'دسترسی غیرمجاز است']));
                    }

                    if (isset($_POST['name'])) $c['name'] = trim($_POST['name']);
                    if (isset($_POST['description'])) $c['description'] = trim($_POST['description']);

                    if (isset($_POST['public_id'])) {
                        $pid = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['public_id']));

                        foreach ($chats as $oc) {
                            if (($oc['id'] ?? '') !== $chat_id && !empty($oc['public_id']) && $oc['public_id'] === $pid) {
                                die(json_encode(['error' => 'این آیدی قبلاً استفاده شده است']));
                            }
                        }

                        $c['public_id'] = $pid;
                    }

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
            foreach ($chats as $c) if ($c['id'] === $chat_id) { $chat = $c; break; }
            if (!$chat) die(json_encode(['error' => 'چت یافت نشد']));

            if (($chat['owner_id'] ?? '') !== $user['id'] && empty($user['is_admin'])) {
                die(json_encode(['error' => 'دسترسی غیرمجاز است']));
            }

            $old = $chat['avatar_image'] ?? '';
            if ($old !== '') {
                $old_path = __DIR__ . '/' . ltrim($old, '/');
                if (is_file($old_path)) @unlink($old_path);
            }

            delete_chat_data($chat_id);
            echo json_encode(['success' => true]);
            exit;

        case 'leave_chat':
            $chat_id = $_POST['chat_id'] ?? '';
            $chats = read_json(CHATS_FILE);

            $chat = null;
            foreach ($chats as $c) if ($c['id'] === $chat_id) { $chat = $c; break; }
            if (!$chat) die(json_encode(['error' => 'چت یافت نشد']));

            if (($chat['type'] ?? '') === 'private') {
                delete_chat_data($chat_id);
                echo json_encode(['success' => true]);
                exit;
            }

            if (($chat['owner_id'] ?? '') === $user['id']) {
                die(json_encode(['error' => 'مالک نمی‌تواند خارج شود. ابتدا چت را حذف کنید']));
            }

            foreach ($chats as &$c) {
                if (($c['id'] ?? '') === $chat_id) {
                    $c['members'] = array_values(array_diff($c['members'] ?? [], [$user['id']]));
                    break;
                }
            }
            unset($c);

            write_json(CHATS_FILE, $chats);
            echo json_encode(['success' => true]);
            exit;

        case 'get_users':
            $users = read_json(USERS_FILE);
            $list = [];

            foreach ($users as $u) {
                if ($u['id'] === $user['id'] || !empty($u['is_bot'])) continue;
                if (!empty($u['blocked']) || (isset($u['active']) && !$u['active'])) continue;
                if (isset($u['privacy_searchable']) && !$u['privacy_searchable']) continue;

                $list[] = [
                    'id' => $u['id'],
                    'username' => $u['username'],
                    'name' => $u['name'] ?? '',
                    'avatar' => $u['avatar'] ?? ''
                ];
            }

            echo json_encode(['users' => $list]);
            exit;

        case 'search_users':
            $q = trim($_GET['q'] ?? '');
            $q = ltrim($q, '@');
            if ($q === '') die(json_encode(['users' => []]));

            $users = read_json(USERS_FILE);
            $list = [];

            foreach ($users as $u) {
                if (!empty($u['is_bot'])) continue;
                if (!empty($u['blocked']) || (isset($u['active']) && !$u['active'])) continue;
                if (isset($u['privacy_searchable']) && !$u['privacy_searchable']) continue;

                if (mb_stripos($u['username'], $q) !== false || mb_stripos($u['name'] ?? '', $q) !== false) {
                    $list[] = [
                        'id' => $u['id'],
                        'username' => $u['username'],
                        'name' => $u['name'] ?? '',
                        'avatar' => $u['avatar'] ?? ''
                    ];
                }
            }

            echo json_encode(['users' => $list]);
            exit;

        case 'update_profile':
            $username = trim(ltrim($_POST['username'] ?? '', '@'));
            $name = trim($_POST['name'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $privacy_searchable = isset($_POST['privacy_searchable']) ? !empty($_POST['privacy_searchable']) : true;

            if ($username === '') die(json_encode(['error' => 'آیدی نمی‌تواند خالی باشد']));
            if (username_exists($username, $user['id'])) die(json_encode(['error' => 'این آیدی قبلاً ثبت شده است']));

            $users = read_json(USERS_FILE);
            $old_username = null;
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $old_username = $u['username'];
                    $u['username'] = $username;
                    $u['name'] = $name;
                    $u['bio'] = $bio;
                    $u['privacy_searchable'] = $privacy_searchable;
                    break;
                }
            }
            unset($u);

            write_json(USERS_FILE, $users);

            if ($old_username !== null && $old_username !== $username) {
                update_messages_username($user['id'], $username);
            }

            echo json_encode(['success' => true]);
            exit;

        case 'change_password':
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';

            if (!password_verify($current_password, $user['password'])) {
                die(json_encode(['error' => 'رمز عبور فعلی اشتباه است']));
            }

            if (mb_strlen($new_password) < 6) {
                die(json_encode(['error' => 'رمز جدید باید حداقل ۶ کاراکتر باشد']));
            }

            $users = read_json(USERS_FILE);
            foreach ($users as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['password'] = password_hash($new_password, PASSWORD_DEFAULT);
                    break;
                }
            }
            unset($u);

            write_json(USERS_FILE, $users);
            echo json_encode(['success' => true]);
            exit;

        case 'admin_get_stats':
            if (!is_admin_user($user)) die(json_encode(['error' => 'دسترسی غیرمجاز است']));

            $users = read_json(USERS_FILE);
            $chats = read_json(CHATS_FILE);
            $messages = read_json(MESSAGES_FILE);

            $total_users = count($users);
            $active_users = count(array_filter($users, fn($u) => (!isset($u['active']) || !empty($u['active'])) && empty($u['blocked'])));
            $blocked_users = count(array_filter($users, fn($u) => !empty($u['blocked'])));
            $admin_users = count(array_filter($users, fn($u) => !empty($u['is_admin']) && empty($u['is_bot'])));
            $bot_users = count(array_filter($users, fn($u) => !empty($u['is_bot'])));
            $searchable_users = count(array_filter($users, fn($u) => (!isset($u['privacy_searchable']) || !empty($u['privacy_searchable'])) && empty($u['is_bot'])));

            $total_chats = count($chats);
            $private_chats = count(array_filter($chats, fn($c) => ($c['type'] ?? '') === 'private'));
            $group_chats = count(array_filter($chats, fn($c) => ($c['type'] ?? '') === 'group'));
            $channel_chats = count(array_filter($chats, fn($c) => ($c['type'] ?? '') === 'channel'));

            $total_messages = count($messages);
            $today_start = strtotime('today');
            $today_messages = count(array_filter($messages, fn($m) => ($m['created_at'] ?? 0) >= $today_start));
            $upload_size = dir_size(UPLOADS_DIR);

            echo json_encode([
                'stats' => [
                    'total_users' => $total_users,
                    'active_users' => $active_users,
                    'blocked_users' => $blocked_users,
                    'admin_users' => $admin_users,
                    'bot_users' => $bot_users,
                    'searchable_users' => $searchable_users,
                    'total_chats' => $total_chats,
                    'private_chats' => $private_chats,
                    'group_chats' => $group_chats,
                    'channel_chats' => $channel_chats,
                    'total_messages' => $total_messages,
                    'today_messages' => $today_messages,
                    'upload_size' => $upload_size,
                ]
            ]);
            exit;

        case 'admin_get_users':
            if (!is_admin_user($user)) die(json_encode(['error' => 'دسترسی غیرمجاز است']));

            $users = read_json(USERS_FILE);
            $list = array_map('safe_user', $users);

            usort($list, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));

            echo json_encode(['users' => $list]);
            exit;

        case 'admin_create_user':
            if (!is_admin_user($user)) die(json_encode(['error' => 'دسترسی غیرمجاز است']));

            $username = trim(ltrim($_POST['username'] ?? '', '@'));
            $password = $_POST['password'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $is_admin = !empty($_POST['is_admin']);
            $active = !empty($_POST['active']);
            $privacy_searchable = isset($_POST['privacy_searchable']) ? !empty($_POST['privacy_searchable']) : true;

            if ($username === '') die(json_encode(['error' => 'آیدی الزامی است']));
            if (mb_strlen($password) < 6) die(json_encode(['error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد']));
            if (username_exists($username)) die(json_encode(['error' => 'این آیدی قبلاً ثبت شده است']));

            $users = read_json(USERS_FILE);
            $new_user = [
                'id' => generate_id(),
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'is_admin' => $is_admin,
                'active' => $active,
                'blocked' => false,
                'is_bot' => false,
                'name' => $name,
                'bio' => $bio,
                'avatar' => '',
                'privacy_searchable' => $privacy_searchable,
                'public_id' => '',
                'privacy_allow_messages' => 'everyone',
                'created_at' => time()
            ];

            $users[] = $new_user;
            write_json(USERS_FILE, $users);

            echo json_encode(['success' => true, 'user' => safe_user($new_user)]);
            exit;

        case 'admin_update_user':
            if (!is_admin_user($user)) die(json_encode(['error' => 'دسترسی غیرمجاز است']));

            $target_id = $_POST['user_id'] ?? '';
            $target = find_user_by_id($target_id);
            if (!$target) die(json_encode(['error' => 'کاربر یافت نشد']));

            if (!empty($target['is_bot'])) {
                die(json_encode(['error' => 'امکان ویرایش ربات وجود ندارد']));
            }

            $username = trim(ltrim($_POST['username'] ?? '', '@'));
            if ($username === '') die(json_encode(['error' => 'آیدی نمی‌تواند خالی باشد']));
            if (username_exists($username, $target_id)) die(json_encode(['error' => 'این آیدی قبلاً ثبت شده است']));

            $password = $_POST['password'] ?? '';
            if ($password !== '' && mb_strlen($password) < 6) {
                die(json_encode(['error' => 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد']));
            }

            $users = read_json(USERS_FILE);

            $is_self = $target_id === $user['id'];

            if (!$is_self) {
                $new_admin = !empty($_POST['is_admin']);

                if (!empty($target['is_admin']) && !$new_admin) {
                    $other_admins = count(array_filter($users, function($u) use ($target_id) {
                        return $u['id'] !== $target_id
                            && !empty($u['is_admin'])
                            && empty($u['is_bot'])
                            && empty($u['blocked'])
                            && (!isset($u['active']) || !empty($u['active']));
                    }));

                    if ($other_admins === 0) {
                        die(json_encode(['error' => 'نمی‌توان آخرین ادمین را غیرادمین کرد']));
                    }
                }
            }

            foreach ($users as &$u) {
                if ($u['id'] === $target_id) {
                    $old_username = $u['username'];

                    $u['username'] = $username;
                    $u['name'] = trim($_POST['name'] ?? '');
                    $u['bio'] = trim($_POST['bio'] ?? '');

                    if (isset($_POST['privacy_searchable'])) {
                        $u['privacy_searchable'] = !empty($_POST['privacy_searchable']);
                    }

                    if ($password !== '') {
                        $u['password'] = password_hash($password, PASSWORD_DEFAULT);
                    }

                    if (!$is_self) {
                        $u['is_admin'] = !empty($_POST['is_admin']);
                        $u['active'] = !empty($_POST['active']);
                        $u['blocked'] = !empty($_POST['blocked']);
                    }

                    break;
                }
            }
            unset($u);

            write_json(USERS_FILE, $users);

            if ($old_username !== $username) {
                update_messages_username($target_id, $username);
            }

            echo json_encode(['success' => true]);
            exit;

        case 'admin_delete_user':
            if (!is_admin_user($user)) die(json_encode(['error' => 'دسترسی غیرمجاز است']));

            $target_id = $_POST['user_id'] ?? '';

            if ($target_id === $user['id']) {
                die(json_encode(['error' => 'نمی‌توانید حساب خودتان را حذف کنید']));
            }

            $target = find_user_by_id($target_id);
            if (!$target) die(json_encode(['error' => 'کاربر یافت نشد']));

            if (!empty($target['is_bot'])) {
                die(json_encode(['error' => 'نمی‌توان ربات سیستم را حذف کرد']));
            }

            if (!empty($target['is_admin'])) {
                $users = read_json(USERS_FILE);
                $other_admins = count(array_filter($users, function($u) use ($target_id) {
                    return $u['id'] !== $target_id
                        && !empty($u['is_admin'])
                        && empty($u['is_bot'])
                        && empty($u['blocked'])
                        && (!isset($u['active']) || !empty($u['active']));
                }));

                if ($other_admins === 0) {
                    die(json_encode(['error' => 'نمی‌توان آخرین ادمین را حذف کرد']));
                }
            }

            $old = $target['avatar'] ?? '';
            if ($old !== '') {
                $old_path = __DIR__ . '/' . ltrim($old, '/');
                if (is_file($old_path)) @unlink($old_path);
            }

            $chats = read_json(CHATS_FILE);
            $keep_chats = [];
            $delete_chat_ids = [];

            foreach ($chats as $c) {
                $cid = $c['id'] ?? '';
                $type = $c['type'] ?? '';
                $members = $c['members'] ?? [];

                if (($c['owner_id'] ?? '') === $target_id && $type !== 'private') {
                    $delete_chat_ids[] = $cid;
                    continue;
                }

                if ($type === 'private' && in_array($target_id, $members)) {
                    $delete_chat_ids[] = $cid;
                    continue;
                }

                if (in_array($target_id, $members)) {
                    $c['members'] = array_values(array_diff($members, [$target_id]));
                }

                $keep_chats[] = $c;
            }

            write_json(CHATS_FILE, $keep_chats);

            $messages = read_json(MESSAGES_FILE);
            $keep_messages = [];

            foreach ($messages as $m) {
                if (($m['user_id'] ?? '') === $target_id || in_array($m['chat_id'] ?? '', $delete_chat_ids)) {
                    unlink_message_file($m);
                    continue;
                }
                $keep_messages[] = $m;
            }

            write_json(MESSAGES_FILE, $keep_messages);

            $users = read_json(USERS_FILE);
            $new_users = array_values(array_filter($users, fn($u) => $u['id'] !== $target_id));
            write_json(USERS_FILE, $new_users);

            echo json_encode(['success' => true]);
            exit;
    }

    echo json_encode(['error' => 'درخواست نامعتبر است']);
    exit;
}

if (!file_exists(CONFIG_FILE)) {
    header('Location: index.php');
    exit;
}

$user = current_user();
if (!$user) {
    header('Location: index.php');
    exit;
}

$safe_user = safe_user($user);
$LOGO_URL = 'https://abrehamrahi.ir/o/public/xzgiRZSa/';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#0b1720">
<title>داشبورد اسپاتیرا | پیام‌رسان</title>
<link rel="icon" href="<?= htmlspecialchars($LOGO_URL) ?>">
<style>
:root {
    --bg-main: #0b1720;
    --bg-card: #152532;
    --bg-input: #1c3141;
    --bg-hover: #243a4d;
    --bg-message-me: #1e5a52;
    --bg-message-me-2: #174540;
    --bg-message-other: #1c3141;
    --text-primary: #e9f3f4;
    --text-secondary: #8099a8;
    --accent: #3ddbc4;
    --accent-strong: #2bc3ad;
    --accent-soft: #5debd6;
    --accent-hover: #2bc3ad;
    --on-accent: #03251f;
    --border: #22384a;
    --success: #3ddbc4;
    --danger: #ff6b6b;
    --warning: #ffd93d;
    --info: #5288c1;
    --line: #22384a;
    --safe-top: env(safe-area-inset-top, 0px);
    --safe-bottom: env(safe-area-inset-bottom, 0px);
}

[data-theme="light"] {
    --bg-main: #e9f1f0;
    --bg-card: #ffffff;
    --bg-input: #f2f7f6;
    --bg-hover: #eaf1f0;
    --bg-message-me: #cdeee7;
    --bg-message-me-2: #bfe8e0;
    --bg-message-other: #ffffff;
    --text-primary: #13252c;
    --text-secondary: #5f7784;
    --accent: #0aa892;
    --accent-strong: #08917e;
    --accent-soft: #15c4ab;
    --accent-hover: #08917e;
    --on-accent: #ffffff;
    --border: #d7e3e1;
    --success: #0aa892;
    --danger: #e94e4e;
    --warning: #f0a500;
    --info: #3390ec;
    --line: #d7e3e1;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    -webkit-tap-highlight-color: transparent;
    font-family: 'Vazirmatn', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Tahoma, sans-serif;
}

html, body { height: 100%; overflow: hidden; }

body {
    background: var(--bg-main);
    color: var(--text-primary);
    overflow: hidden;
    transition: background .4s cubic-bezier(.22,.9,.3,1), color .4s;
}

.app {
    display: flex;
    height: 100vh;
    height: 100dvh;
    width: 100%;
}

.sidebar {
    width: 340px;
    background: var(--bg-card);
    border-left: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    position: relative;
    transition: transform .35s cubic-bezier(.22,.9,.3,1), width .3s;
}

.sidebar-header {
    padding: 12px 16px;
    padding-top: calc(12px + var(--safe-top));
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
    min-height: 56px;
    flex-shrink: 0;
}

.sidebar-header h2 {
    font-size: 17px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
    position: relative;
}

.sidebar-header h2 .brand-logo-mini {
    width: 30px;
    height: 30px;
    border-radius: 9px;
    object-fit: cover;
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    padding: 3px;
    box-shadow: 0 4px 12px color-mix(in srgb, var(--accent) 40%, transparent);
    flex-shrink: 0;
}

.sidebar-actions {
    display: flex;
    gap: 2px;
    flex-shrink: 0;
}

.icon-btn {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    font-size: 17px;
    transition: all .2s;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    position: relative;
}

.icon-btn:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
}

.icon-btn:active {
    transform: scale(.94);
}

.icon-btn.notif-enabled {
    color: var(--accent);
}

.notif-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    background: var(--danger);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    animation: pulse-notif 2s ease-in-out infinite;
}

@keyframes pulse-notif {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.user-info {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background .2s;
    flex-shrink: 0;
}

.user-info:hover {
    background: var(--bg-hover);
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--on-accent);
    font-weight: 600;
    font-size: 15px;
    flex-shrink: 0;
    overflow: hidden;
    box-shadow: 0 3px 10px color-mix(in srgb, var(--accent) 30%, transparent);
}

.user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.user-details {
    flex: 1;
    min-width: 0;
}

.user-name {
    font-weight: 600;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-status {
    font-size: 12px;
    color: var(--text-secondary);
}

.search-box {
    padding: 8px 12px;
    flex-shrink: 0;
}

.search-box input {
    width: 100%;
    padding: 9px 16px;
    border-radius: 20px;
    border: 1px solid var(--border);
    background: var(--bg-input);
    color: var(--text-primary);
    outline: none;
    font-size: 14px;
    font-family: inherit;
    transition: border-color .25s;
}

.search-box input:focus {
    border-color: var(--accent);
}

.chat-list {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding-bottom: 88px;
}

.chat-item {
    padding: 10px 14px;
    display: flex;
    gap: 11px;
    cursor: pointer;
    transition: background .15s;
    border-bottom: 1px solid var(--border);
    align-items: center;
    position: relative;
}

.chat-item:hover,
.chat-item.active {
    background: var(--bg-hover);
}

.chat-avatar {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--on-accent);
    font-weight: 600;
    font-size: 18px;
    flex-shrink: 0;
    text-transform: uppercase;
    overflow: hidden;
    box-shadow: 0 2px 8px color-mix(in srgb, var(--accent) 25%, transparent);
    position: relative;
}

.chat-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.chat-avatar.group { background: linear-gradient(135deg, #ff6b6b, #ee5a52); color: #fff; }
.chat-avatar.channel { background: linear-gradient(135deg, var(--accent-soft), var(--accent-strong)); color: var(--on-accent); }

.chat-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.chat-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}

.chat-name {
    font-weight: 600;
    font-size: 14.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-time {
    font-size: 11px;
    color: var(--text-secondary);
    flex-shrink: 0;
}

.chat-preview-wrap {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-top: 2px;
}

.chat-preview {
    font-size: 12.5px;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 4px;
}

.unread-badge {
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    background: var(--accent);
    color: var(--on-accent);
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.chat-type-badge {
    display: inline-block;
    font-size: 10px;
    padding: 2px 7px;
    border-radius: 10px;
    background: var(--accent);
    color: var(--on-accent);
    margin-left: 4px;
    font-weight: 600;
}

.msg-ticks {
    display: inline-flex;
    align-items: center;
    gap: 0;
    margin-right: 3px;
}

.msg-ticks svg {
    width: 14px;
    height: 14px;
}

.msg-ticks.sent svg {
    fill: currentColor;
}

.msg-ticks.delivered svg {
    fill: currentColor;
}

.msg-ticks.seen svg {
    fill: #3ddbc4;
}

[data-theme="light"] .msg-ticks.seen svg {
    fill: #08917e;
}

.fab-container {
    position: absolute;
    bottom: 20px;
    left: 20px;
    z-index: 50;
}

.fab-btn {
    width: 54px;
    height: 54px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    border: none;
    color: var(--on-accent);
    font-size: 22px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 18px color-mix(in srgb, var(--accent) 45%, transparent);
    transition: all .3s cubic-bezier(.4,0,.2,1);
    position: relative;
    z-index: 51;
}

.fab-btn:hover {
    transform: scale(1.06);
    box-shadow: 0 8px 24px color-mix(in srgb, var(--accent) 55%, transparent);
}

.fab-btn.active {
    background: var(--danger);
    transform: rotate(45deg);
    color: #fff;
}

.fab-btn svg {
    width: 22px;
    height: 22px;
    fill: currentColor;
    transition: transform .3s;
}

.fab-btn.active svg {
    transform: rotate(-45deg);
}

.fab-menu {
    position: absolute;
    bottom: 65px;
    left: 5px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
    opacity: 0;
    transform: translateY(10px) scale(.8);
    transition: all .3s cubic-bezier(.4,0,.2,1);
    transform-origin: bottom left;
}

.fab-container.open .fab-menu {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: all;
}

.fab-menu-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 20px;
    cursor: pointer;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
    transition: all .2s;
    font-size: 13px;
    opacity: 0;
    transform: translateX(-10px) scale(.8);
}

.fab-container.open .fab-menu-item {
    opacity: 1;
    transform: translateX(0) scale(1);
}

.fab-container.open .fab-menu-item:nth-child(1) { transition-delay: .05s; }
.fab-container.open .fab-menu-item:nth-child(2) { transition-delay: .1s; }
.fab-container.open .fab-menu-item:nth-child(3) { transition-delay: .15s; }

.fab-menu-item:hover {
    background: var(--bg-hover);
    transform: translateX(-4px) scale(1.02);
}

.fab-menu-item-icon {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}

.fab-menu-item-icon.private { background: linear-gradient(135deg, #5288c1, #4a7ab5); }
.fab-menu-item-icon.group { background: linear-gradient(135deg, #ff6b6b, #ee5a52); }
.fab-menu-item-icon.channel { background: linear-gradient(135deg, var(--accent-soft), var(--accent-strong)); color: var(--on-accent); }

.chat-area {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: var(--bg-main);
    position: relative;
    min-width: 0;
}

.chat-header {
    padding: 10px 16px;
    padding-top: calc(10px + var(--safe-top));
    background: var(--bg-card);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 60px;
    flex-shrink: 0;
}

.chat-header-info {
    flex: 1;
    min-width: 0;
}

.chat-header-name {
    font-weight: 600;
    font-size: 15.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-header-status {
    font-size: 12px;
    color: var(--text-secondary);
}

.empty-state {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-secondary);
    font-size: 15px;
    flex-direction: column;
    gap: 14px;
    padding: 20px;
    text-align: center;
}

.empty-state .emoji {
    width: 78px;
    height: 78px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 14px;
    box-shadow: 0 10px 30px color-mix(in srgb, var(--accent) 30%, transparent);
    animation: float 3.6s ease-in-out infinite;
}
.empty-state .emoji img { width: 100%; height: 100%; object-fit: contain; }
@keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }

.messages {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    position: relative;
    min-height: 0;
}

.messages::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='520' height='520' viewBox='0 0 520 520'%3E%3Cg fill='none' stroke='%233ddbc4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' opacity='.55'%3E%3Crect x='42' y='64' width='124' height='80' rx='20'/%3E%3Cpath d='M74 144l-10 30 36-30'/%3E%3Ccircle cx='79' cy='104' r='3'/%3E%3Ccircle cx='103' cy='104' r='3'/%3E%3Ccircle cx='127' cy='104' r='3'/%3E%3Cpath d='M306 44l58 22-58 22 14-22z'/%3E%3Cpath d='M364 66l34 0'/%3E%3Cpath d='M416 152c0-9 13-15 20-8 7-7 20-1 20 8 0 10-20 22-20 22s-20-12-20-22z'/%3E%3Crect x='62' y='302' width='122' height='82' rx='12'/%3E%3Cpath d='M62 316l61 42 61-42'/%3E%3Cpath d='M326 296l9 24 24 9-24 9-9 24-9-24-24-9 24-9z'/%3E%3Ccircle cx='448' cy='322' r='4'/%3E%3Ccircle cx='212' cy='252' r='4'/%3E%3Crect x='342' y='408' width='116' height='74' rx='18'/%3E%3Cpath d='M432 482l12 26-32-26'/%3E%3Cpath d='M368 436h58M368 454h36'/%3E%3Cpath d='M150 424l8 16 16 8-16 8-8 16-8-16-16-8 16-8z'/%3E%3Ccircle cx='58' cy='208' r='3'/%3E%3Ccircle cx='492' cy='96' r='3'/%3E%3Ccircle cx='258' cy='178' r='3'/%3E%3C/g%3E%3C/svg%3E");
    background-size: 480px 480px;
    opacity: .04;
    pointer-events: none;
    z-index: 0;
}
[data-theme="light"] .messages::before { opacity: .12; filter: brightness(.5); }

.message {
    max-width: 72%;
    padding: 9px 13px;
    border-radius: 14px;
    position: relative;
    word-wrap: break-word;
    animation: fadeIn .25s cubic-bezier(.22,.9,.3,1);
    z-index: 1;
    box-shadow: 0 2px 8px rgba(0,0,0,.12);
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.message.me {
    background: linear-gradient(135deg, var(--bg-message-me), var(--bg-message-me-2));
    color: #eafffb;
    align-self: flex-end;
    border-bottom-right-radius: 4px;
}
[data-theme="light"] .message.me { color: #0c3831; }

.message.other {
    background: var(--bg-message-other);
    align-self: flex-start;
    border-bottom-left-radius: 4px;
    border: 1px solid var(--border);
}

.message-meta {
    font-size: 10px;
    color: rgba(255,255,255,.7);
    margin-top: 4px;
    text-align: left;
    display: flex;
    gap: 6px;
    align-items: center;
    justify-content: flex-end;
    direction: ltr;
}
[data-theme="light"] .message.me .message-meta { color: rgba(12,56,49,.6); }
.message.other .message-meta { color: var(--text-secondary); }

.message-text {
    font-size: 14.5px;
    line-height: 1.6;
    white-space: pre-wrap;
}

.message-text a {
    color: var(--accent-soft);
    text-decoration: underline;
    text-underline-offset: 2px;
}
.message.me .message-text a {
    color: #a8fff0;
}
[data-theme="light"] .message.me .message-text a {
    color: #044d44;
}

.message-text .mention {
    color: var(--accent);
    font-weight: 600;
    cursor: pointer;
}
.message.me .message-text .mention {
    color: #a8fff0;
}

.message-file {
    margin-bottom: 6px;
}

.message-file img,
.message-file video {
    max-width: 100%;
    max-height: 320px;
    border-radius: 10px;
    display: block;
}

.message-file a {
    color: var(--accent);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
}

.link-preview-card {
    margin-top: 8px;
    background: rgba(255,255,255,.08);
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.12);
    cursor: pointer;
    transition: all .2s;
    max-width: 100%;
    display: block;
    text-decoration: none;
    color: inherit;
}
.message.other .link-preview-card {
    background: var(--bg-hover);
    border-color: var(--border);
}

.link-preview-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,.2);
}

.link-preview-image {
    width: 100%;
    height: 140px;
    object-fit: cover;
    display: block;
    background: rgba(0,0,0,.2);
}

.link-preview-content {
    padding: 10px 12px;
}

.link-preview-site {
    font-size: 11px;
    color: var(--accent);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .3px;
}

.link-preview-title {
    font-size: 13px;
    font-weight: 600;
    line-height: 1.4;
    margin-bottom: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.link-preview-desc {
    font-size: 11.5px;
    color: var(--text-secondary);
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.message.me .link-preview-desc {
    color: rgba(255,255,255,.75);
}
[data-theme="light"] .message.me .link-preview-desc {
    color: rgba(12,56,49,.7);
}

.message-actions-inline {
    display: flex;
    gap: 4px;
    margin-top: 6px;
    opacity: 0.75;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.message.other .message-actions-inline {
    justify-content: flex-start;
}

.msg-action-inline {
    background: transparent;
    border: none;
    color: inherit;
    font-size: 11px;
    cursor: pointer;
    padding: 3px 8px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    transition: all .15s;
    font-family: inherit;
    font-weight: 500;
    line-height: 1;
}

.msg-action-inline:hover {
    background: rgba(255,255,255,.12);
    opacity: 1;
}

.message.other .msg-action-inline:hover {
    background: var(--bg-hover);
}

.msg-action-inline.danger {
    color: var(--danger);
}

.message.me .msg-action-inline.danger {
    color: #ffb3b3;
}

.voice-msg {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 4px;
    min-width: 220px;
}

.voice-play-btn {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--accent);
    color: var(--on-accent);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: transform .2s;
    font-size: 14px;
}
.voice-play-btn:hover { transform: scale(1.08); }
.message.me .voice-play-btn { background: rgba(255,255,255,.25); color: #fff; }

.voice-waveform {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 2px;
    height: 32px;
    min-width: 0;
}
.voice-waveform .bar {
    flex: 1;
    background: currentColor;
    opacity: .4;
    border-radius: 2px;
    min-width: 2px;
    max-width: 4px;
    transition: opacity .15s;
}
.voice-waveform .bar.played { opacity: 1; }

.voice-time {
    font-size: 11px;
    color: var(--text-secondary);
    flex-shrink: 0;
    min-width: 32px;
    text-align: center;
}
.message.me .voice-time { color: rgba(255,255,255,.75); }
[data-theme="light"] .message.me .voice-time { color: rgba(12,56,49,.6); }

.message-edited {
    font-style: italic;
    font-size: 10px;
}

.message-input-wrap {
    padding: 10px 14px;
    padding-bottom: calc(10px + var(--safe-bottom));
    background: var(--bg-card);
    border-top: 1px solid var(--border);
    flex-shrink: 0;
}

.message-input {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    position: relative;
}

.attach-btn, .voice-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
    flex-shrink: 0;
    font-size: 18px;
}

.attach-btn:hover, .voice-btn:hover {
    background: var(--bg-hover);
    color: var(--accent);
}

.attach-btn:active, .voice-btn:active {
    transform: scale(.92);
}

.voice-btn.recording {
    color: var(--danger);
    background: rgba(255,107,107,.15);
    animation: pulse-rec 1s ease-in-out infinite;
}

@keyframes pulse-rec {
    0%, 100% { box-shadow: 0 0 0 0 rgba(255,107,107,.5); }
    50% { box-shadow: 0 0 0 8px rgba(255,107,107,0); }
}

.message-input textarea {
    flex: 1;
    padding: 10px 16px;
    border-radius: 22px;
    border: 1px solid var(--border);
    background: var(--bg-input);
    color: var(--text-primary);
    outline: none;
    font-size: 14px;
    resize: none;
    max-height: 120px;
    min-height: 40px;
    font-family: inherit;
    line-height: 1.5;
    transition: border-color .2s;
    min-width: 0;
}

.message-input textarea:focus {
    border-color: var(--accent);
}

.send-btn {
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    border: none;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    cursor: pointer;
    color: var(--on-accent);
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
    flex-shrink: 0;
    box-shadow: 0 4px 12px color-mix(in srgb, var(--accent) 40%, transparent);
}

.send-btn:hover {
    transform: scale(1.06);
    box-shadow: 0 6px 18px color-mix(in srgb, var(--accent) 55%, transparent);
}

.send-btn:active { transform: scale(.94); }

.send-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
    transform: none !important;
}

.recording-ui {
    display: none;
    align-items: center;
    gap: 10px;
    flex: 1;
    padding: 6px 10px;
    background: rgba(255,107,107,.1);
    border-radius: 20px;
    border: 1px solid rgba(255,107,107,.3);
    min-width: 0;
}

.recording-ui.active { display: flex; }

.rec-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--danger);
    animation: blink-rec 1s ease-in-out infinite;
    flex-shrink: 0;
}
@keyframes blink-rec {
    0%, 100% { opacity: 1; }
    50% { opacity: .3; }
}

.rec-waves {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 3px;
    height: 24px;
    min-width: 0;
}
.rec-waves .wbar {
    flex: 1;
    background: var(--danger);
    border-radius: 2px;
    min-width: 2px;
    max-width: 4px;
    animation: wave-dance .6s ease-in-out infinite;
}
@keyframes wave-dance {
    0%, 100% { height: 20%; }
    50% { height: 100%; }
}
.rec-waves .wbar:nth-child(1) { animation-delay: 0s; }
.rec-waves .wbar:nth-child(2) { animation-delay: .1s; }
.rec-waves .wbar:nth-child(3) { animation-delay: .2s; }
.rec-waves .wbar:nth-child(4) { animation-delay: .3s; }
.rec-waves .wbar:nth-child(5) { animation-delay: .4s; }
.rec-waves .wbar:nth-child(6) { animation-delay: .5s; }
.rec-waves .wbar:nth-child(7) { animation-delay: .6s; }

.rec-time {
    font-size: 13px;
    font-weight: 600;
    color: var(--danger);
    flex-shrink: 0;
    min-width: 40px;
    text-align: center;
    direction: ltr;
}

.rec-cancel {
    background: transparent;
    border: none;
    color: var(--danger);
    cursor: pointer;
    padding: 6px 10px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 500;
    transition: background .2s;
    font-family: inherit;
}
.rec-cancel:hover { background: rgba(255,107,107,.1); }

.attach-popup {
    position: absolute;
    bottom: 54px;
    right: 0;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 8px;
    box-shadow: 0 8px 30px rgba(0,0,0,.3);
    display: none;
    flex-direction: column;
    gap: 4px;
    min-width: 180px;
    z-index: 20;
    animation: popUp .2s cubic-bezier(.22,.9,.3,1);
}
.attach-popup.active { display: flex; }

@keyframes popUp {
    from { opacity: 0; transform: translateY(8px) scale(.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.attach-popup-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    color: var(--text-primary);
    transition: background .15s;
    border: none;
    background: transparent;
    width: 100%;
    text-align: right;
    font-family: inherit;
}
.attach-popup-item:hover { background: var(--bg-hover); }
.attach-popup-item .ico {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.attach-popup-item .ico.img { background: rgba(255,107,107,.15); color: #ff6b6b; }
.attach-popup-item .ico.vid { background: rgba(255,217,61,.15); color: #ffd93d; }
.attach-popup-item .ico.file { background: rgba(82,136,193,.15); color: #5288c1; }

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100;
    padding: 20px;
}

.modal-overlay.active {
    display: flex;
}

.modal {
    background: var(--bg-card);
    border-radius: 16px;
    padding: 24px;
    max-width: 460px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    border: 1px solid var(--border);
    box-shadow: 0 20px 60px rgba(0,0,0,.4);
    animation: modalIn .3s cubic-bezier(.22,.9,.3,1);
}

@keyframes modalIn {
    from { opacity: 0; transform: translateY(20px) scale(.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.modal.large {
    max-width: 920px;
}

.modal h3 {
    margin-bottom: 16px;
    font-size: 17px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-field {
    margin-bottom: 14px;
}

.modal-field label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    color: var(--text-secondary);
    font-weight: 500;
}

.modal-field input,
.modal-field textarea,
.modal-field select {
    width: 100%;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--bg-input);
    color: var(--text-primary);
    outline: none;
    font-size: 14px;
    font-family: inherit;
    transition: border-color .2s;
}

.modal-field input:focus,
.modal-field textarea:focus,
.modal-field select:focus {
    border-color: var(--accent);
}

.modal-field textarea {
    min-height: 80px;
    resize: vertical;
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
    flex-wrap: wrap;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all .2s;
    font-family: inherit;
}

.btn-primary {
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    color: var(--on-accent);
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px color-mix(in srgb, var(--accent) 40%, transparent);
}

.btn-secondary {
    background: var(--bg-input);
    color: var(--text-primary);
    border: 1px solid var(--border);
}

.btn-secondary:hover { background: var(--bg-hover); }

.btn-danger {
    background: var(--danger);
    color: #fff;
}

.btn-danger:hover {
    opacity: .9;
    transform: translateY(-1px);
}

.btn-success {
    background: var(--success);
    color: var(--on-accent);
}

.btn-success:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px color-mix(in srgb, var(--success) 40%, transparent);
}

.users-list {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 4px;
    background: var(--bg-input);
}

.user-option {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: 8px;
    cursor: pointer;
    transition: background .15s;
}

.user-option:hover { background: var(--bg-hover); }

.user-option input {
    width: auto;
    margin: 0;
    accent-color: var(--accent);
}

.user-option-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    color: var(--on-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 13px;
    flex-shrink: 0;
    overflow: hidden;
}
.user-option-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.link-box {
    display: flex;
    gap: 8px;
    align-items: center;
    background: var(--bg-input);
    padding: 8px 12px;
    border-radius: 10px;
    margin-top: 8px;
    border: 1px solid var(--border);
}

.link-box input {
    flex: 1;
    background: transparent;
    border: none;
    color: var(--text-primary);
    outline: none;
    font-size: 13px;
    direction: ltr;
    text-align: left;
    font-family: inherit;
}

.copy-btn {
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    color: var(--on-accent);
    border: none;
    padding: 7px 14px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    transition: transform .15s;
}
.copy-btn:hover { transform: translateY(-1px); }

.toast {
    position: fixed;
    top: calc(20px + var(--safe-top));
    left: 50%;
    transform: translateX(-50%);
    background: var(--bg-card);
    color: var(--text-primary);
    padding: 12px 22px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,.3);
    z-index: 1000;
    display: none;
    border: 1px solid var(--border);
    font-weight: 500;
    max-width: 90%;
    text-align: center;
}

.toast.show {
    display: block;
    animation: slideDown .3s cubic-bezier(.22,.9,.3,1);
}

@keyframes slideDown {
    from { transform: translate(-50%, -50px); opacity: 0; }
    to { transform: translate(-50%, 0); opacity: 1; }
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
    margin-bottom: 20px;
}

.stat-card {
    background: var(--bg-input);
    border-radius: 12px;
    padding: 14px;
    text-align: center;
    border: 1px solid var(--border);
    transition: transform .2s, border-color .2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    border-color: color-mix(in srgb, var(--accent) 40%, var(--border));
}

.stat-value {
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 4px;
    color: var(--accent);
}

.stat-label {
    font-size: 12px;
    color: var(--text-secondary);
    font-weight: 500;
}

.table-wrapper {
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 12px;
    max-height: 420px;
}

.users-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 700px;
}

.users-table th,
.users-table td {
    padding: 11px 10px;
    border-bottom: 1px solid var(--border);
    text-align: right;
    white-space: nowrap;
}

.users-table th {
    position: sticky;
    top: 0;
    background: var(--bg-card);
    z-index: 2;
    font-weight: 700;
    color: var(--text-secondary);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .3px;
}

.users-table tr:hover td { background: var(--bg-hover); }

.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    color: #fff;
    font-weight: 600;
}

.badge.success { background: var(--success); color: var(--on-accent); }
.badge.danger { background: var(--danger); }
.badge.warning { background: var(--warning); color: #13252c; }
.badge.info { background: var(--info); }
.badge.muted { background: #777; }

.checkbox-row {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
}

.checkbox-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-primary);
    cursor: pointer;
    font-weight: 500;
}

.checkbox-item input {
    width: auto;
    margin: 0;
    accent-color: var(--accent);
}

.section-title {
    font-size: 15px;
    font-weight: 700;
    margin: 18px 0 10px;
}

.attachment-preview {
    background: var(--bg-input);
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 90px;
    border: 1px solid var(--border);
    overflow: hidden;
}

.attachment-preview img,
.attachment-preview video {
    max-width: 100%;
    max-height: 260px;
    border-radius: 10px;
    display: block;
}

.file-meta {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 6px;
}

.small-note {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 5px;
    line-height: 1.6;
}

.action-buttons {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.mini-btn {
    border: none;
    border-radius: 8px;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 12px;
    background: var(--bg-input);
    color: var(--text-primary);
    transition: all .15s;
    font-family: inherit;
    font-weight: 500;
}

.mini-btn:hover {
    background: var(--bg-hover);
    transform: translateY(-1px);
}

.mini-btn.danger {
    background: rgba(255,107,107,.15);
    color: var(--danger);
}

[data-theme="light"] .mini-btn.danger { background: rgba(233,78,78,.12); }

.profile-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    padding: 12px;
    background: var(--bg-input);
    border-radius: 14px;
    border: 1px solid var(--border);
}

.profile-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    color: var(--on-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    font-weight: 700;
    overflow: hidden;
    box-shadow: 0 6px 18px color-mix(in srgb, var(--accent) 40%, transparent);
    flex-shrink: 0;
    position: relative;
    cursor: pointer;
    transition: transform .2s;
}
.profile-avatar:hover { transform: scale(1.05); }
.profile-avatar img { width: 100%; height: 100%; object-fit: cover; }

.avatar-upload-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.55);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity .2s;
    color: #fff;
    font-size: 20px;
    pointer-events: none;
}
.profile-avatar:hover .avatar-upload-overlay,
.chat-avatar-upload:hover .avatar-upload-overlay {
    opacity: 1;
}

.chat-avatar-upload {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
    color: var(--on-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    font-weight: 700;
    overflow: hidden;
    cursor: pointer;
    position: relative;
    transition: transform .2s;
    margin: 0 auto 12px;
}
.chat-avatar-upload:hover { transform: scale(1.05); }
.chat-avatar-upload img { width: 100%; height: 100%; object-fit: cover; }

.avatar-actions {
    display: flex;
    gap: 6px;
    margin-top: 6px;
    justify-content: center;
}

.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1px solid var(--border);
    margin-bottom: 12px;
    gap: 10px;
}

.toggle-row-label {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
}

.toggle-row-sub {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 2px;
}

.toggle-switch {
    position: relative;
    width: 44px;
    height: 24px;
    flex-shrink: 0;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background: var(--border);
    border-radius: 24px;
    transition: .25s;
}

.toggle-slider::before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    right: 3px;
    bottom: 3px;
    background: #fff;
    border-radius: 50%;
    transition: .25s;
}

.toggle-switch input:checked + .toggle-slider {
    background: var(--accent);
}

.toggle-switch input:checked + .toggle-slider::before {
    transform: translateX(-20px);
}

.notif-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 8px;
    background: rgba(255,107,107,.15);
    color: var(--danger);
    font-weight: 600;
    margin-right: 6px;
}
.notif-status-pill.on {
    background: rgba(61,219,196,.15);
    color: var(--accent);
}

.back-btn {
    display: none;
}

::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

::-webkit-scrollbar-track { background: transparent; }

::-webkit-scrollbar-thumb {
    background: color-mix(in srgb, var(--accent) 40%, transparent);
    border-radius: 3px;
}
::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--accent) 60%, transparent); }

@media (max-width: 768px) {
    .sidebar {
        width: 100%;
        position: absolute;
        inset: 0;
        z-index: 10;
        transform: translateX(0);
    }

    .chat-area {
        position: absolute;
        inset: 0;
        z-index: 9;
        transform: translateX(-100%);
        transition: transform .35s cubic-bezier(.22,.9,.3,1);
    }

    .app.show-chat .chat-area {
        transform: translateX(0);
        z-index: 11;
    }

    .back-btn {
        display: flex !important;
    }

    .fab-container {
        bottom: 14px;
        left: 14px;
    }

    .fab-btn { width: 50px; height: 50px; }

    .message {
        max-width: 88%;
    }

    .modal {
        max-width: calc(100vw - 32px);
        padding: 20px;
        max-height: 85vh;
    }

    .modal.large {
        max-width: calc(100vw - 16px);
    }

    .users-table {
        font-size: 12px;
    }

    .users-table th, .users-table td {
        padding: 8px 6px;
    }
}

@media (max-width: 480px) {
    .sidebar-header { padding: 10px 12px; }
    .sidebar-header h2 { font-size: 16px; }
    .user-info { padding: 8px 12px; }
    .search-box { padding: 6px 10px; }
    .chat-item { padding: 9px 12px; gap: 10px; }
    .chat-avatar { width: 42px; height: 42px; font-size: 16px; }
    .chat-name { font-size: 14px; }
    .chat-preview { font-size: 12px; }

    .chat-header { padding: 8px 12px; min-height: 56px; }
    .messages { padding: 10px 8px; }
    .message { padding: 8px 11px; max-width: 90%; }
    .message-text { font-size: 14px; }
    .voice-msg { min-width: 180px; }

    .message-input-wrap { padding: 8px 10px; }
    .attach-btn, .voice-btn { width: 38px; height: 38px; }
    .send-btn { width: 38px; height: 38px; }
    .message-input textarea { padding: 9px 14px; font-size: 14px; }

    .modal { padding: 18px; border-radius: 14px; }
    .modal h3 { font-size: 16px; }
    .btn { padding: 9px 16px; font-size: 13px; }

    .empty-state { padding: 16px; gap: 10px; }
    .empty-state .emoji { width: 64px; height: 64px; padding: 10px; }
    .empty-state div { font-size: 14px; }

    .attach-popup { min-width: 160px; }
    .attach-popup-item { font-size: 13px; padding: 8px 10px; }

    .fab-btn { width: 48px; height: 48px; font-size: 20px; }
    .fab-menu-item { font-size: 12px; padding: 7px 14px; }

    .link-preview-image { height: 100px; }
}

@media (max-width: 360px) {
    .message { max-width: 94%; font-size: 13.5px; }
    .chat-name { font-size: 13.5px; }
    .chat-time { font-size: 10px; }
    .icon-btn { width: 34px; height: 34px; padding: 6px; }
    .sidebar-actions { gap: 0; }
}

@media (max-height: 500px) and (orientation: landscape) {
    .sidebar-header { padding: 6px 12px; min-height: 48px; }
    .user-info { padding: 6px 12px; }
    .chat-header { padding: 6px 12px; min-height: 48px; }
    .messages { padding: 8px; }
    .message-input-wrap { padding: 6px 10px; }
    .empty-state .emoji { width: 56px; height: 56px; }
}
</style>
</head>
<body data-theme="dark">
<div class="app" id="app">
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>
                <img src="<?= htmlspecialchars($LOGO_URL) ?>" alt="اسپاتیرا" class="brand-logo-mini">
                <span>پیام‌ها</span>
                <span class="notif-badge" id="totalNotifBadge" style="display:none">0</span>
            </h2>
            <div class="sidebar-actions">
                <button class="icon-btn" onclick="toggleNotifications()" id="notifBtn" title="اعلان‌ها">🔔</button>
                <?php if (!empty($user['is_admin'])): ?>
                <button class="icon-btn" onclick="openAdminPanel()" title="پنل مدیریت">
                    🛠️
                </button>
                <?php endif; ?>
                <button class="icon-btn" onclick="openProfileModal()" title="تنظیمات">⚙️</button>
                <button class="icon-btn" onclick="toggleTheme()" id="themeBtn" title="تم">🌙</button>
                <button class="icon-btn" onclick="logout()" title="خروج">🚪</button>
            </div>
        </div>

        <div class="user-info" onclick="openProfileModal()">
            <div class="user-avatar">
                <?php if (!empty($user['avatar'])): ?>
                <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="<?= htmlspecialchars($user['username']) ?>">
                <?php else: ?>
                <?= mb_substr($user['name'] ?: $user['username'], 0, 1) ?>
                <?php endif; ?>
            </div>
            <div class="user-details">
                <div class="user-name"><?= htmlspecialchars($user['name'] ?? $user['username']) ?></div>
                <div class="user-status">@<?= htmlspecialchars($user['username']) ?></div>
            </div>
        </div>

        <div class="search-box">
            <input type="text" id="searchChats" placeholder="جستجو..." oninput="filterChats()">
        </div>

        <div class="chat-list" id="chatList"></div>

        <div class="fab-container" id="fabContainer">
            <div class="fab-menu">
                <div class="fab-menu-item" onclick="createChat('private')">
                    <div class="fab-menu-item-icon private">💬</div>
                    <span>پیام خصوصی</span>
                </div>
                <div class="fab-menu-item" onclick="createChat('group')">
                    <div class="fab-menu-item-icon group">👥</div>
                    <span>گروه</span>
                </div>
                <div class="fab-menu-item" onclick="createChat('channel')">
                    <div class="fab-menu-item-icon channel">📢</div>
                    <span>کانال</span>
                </div>
            </div>

            <button class="fab-btn" onclick="toggleFab()" id="fabBtn" title="چت جدید">
                <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34a.996.996 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
            </button>
        </div>
    </div>

    <div class="chat-area" id="chatArea">
        <div class="empty-state" id="emptyState">
            <div class="emoji">
                <img src="<?= htmlspecialchars($LOGO_URL) ?>" alt="اسپاتیرا">
            </div>
            <div style="font-weight:600;font-size:17px">به اسپاتیرا خوش آمدید</div>
            <div>یک چت را انتخاب کنید تا گفت‌وگو آغاز شود</div>
        </div>

        <div id="chatContainer" style="display:none;flex:1;flex-direction:column;min-height:0">
            <div class="chat-header">
                <button class="icon-btn back-btn" onclick="closeChat()">←</button>
                <div class="chat-avatar" id="chatAvatar"></div>
                <div class="chat-header-info">
                    <div class="chat-header-name" id="chatName"></div>
                    <div class="chat-header-status" id="chatStatus"></div>
                </div>
                <div class="sidebar-actions">
                    <button class="icon-btn" onclick="openChatMenu()" title="منو">⋮</button>
                </div>
            </div>

            <div class="messages" id="messages"></div>

            <div class="message-input-wrap">
                <div class="message-input" id="msgInputRow">
                    <div style="position:relative">
                        <button class="attach-btn" onclick="toggleAttach()" title="پیوست" id="attachBtn" type="button">📎</button>
                        <div class="attach-popup" id="attachPopup">
                            <button class="attach-popup-item" onclick="pickAttach('image')">
                                <span class="ico img">🖼️</span>
                                <span>تصویر</span>
                            </button>
                            <button class="attach-popup-item" onclick="pickAttach('video')">
                                <span class="ico vid">🎬</span>
                                <span>ویدیو</span>
                            </button>
                            <button class="attach-popup-item" onclick="pickAttach('file')">
                                <span class="ico file">📄</span>
                                <span>فایل</span>
                            </button>
                        </div>
                    </div>

                    <input type="file" id="fileInput" style="display:none">
                    <input type="file" id="imageInput" style="display:none" accept="image/*">
                    <input type="file" id="videoInput" style="display:none" accept="video/*">

                    <textarea id="messageInput" placeholder="پیام خود را بنویسید..." rows="1"></textarea>

                    <div class="recording-ui" id="recordingUI">
                        <div class="rec-dot"></div>
                        <div class="rec-waves">
                            <div class="wbar"></div><div class="wbar"></div><div class="wbar"></div>
                            <div class="wbar"></div><div class="wbar"></div><div class="wbar"></div><div class="wbar"></div>
                        </div>
                        <div class="rec-time" id="recTime">0:00</div>
                        <button class="rec-cancel" onclick="cancelRecording()" type="button">لغو</button>
                    </div>

                    <button class="voice-btn" onclick="toggleRecording()" title="ضبط صدا" id="voiceBtn" type="button">🎤</button>
                    <button class="send-btn" onclick="sendMessage()" id="sendBtn" type="button" aria-label="ارسال">➤</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Chat Modal -->
<div class="modal-overlay" id="newChatModal">
    <div class="modal">
        <h3 id="newChatTitle">ایجاد چت جدید</h3>

        <div class="modal-field" id="chatNameField" style="display:none">
            <label>نام</label>
            <input type="text" id="newChatName" placeholder="مثلاً: دوستان">
        </div>

        <div class="modal-field" id="chatDescField" style="display:none">
            <label>توضیحات (اختیاری)</label>
            <textarea id="newChatDesc"></textarea>
        </div>

        <div class="modal-field" id="membersField">
            <label id="membersLabel">انتخاب کاربر</label>
            <input type="text" id="userSearch" placeholder="جستجوی آیدی یا نام..." oninput="searchUsers()">
            <div class="small-note" style="margin-top:4px;margin-bottom:6px">فقط کاربرانی که «قابل جستجو» بودنشان فعال است نمایش داده می‌شوند.</div>
            <div class="users-list" id="usersList"></div>
        </div>

        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal('newChatModal')">انصراف</button>
            <button class="btn btn-primary" onclick="submitCreateChat()">ایجاد</button>
        </div>
    </div>
</div>

<!-- Chat Menu Modal -->
<div class="modal-overlay" id="chatMenuModal">
    <div class="modal">
        <h3 id="menuTitle">تنظیمات چت</h3>
        <div id="chatMenuContent"></div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal('chatMenuModal')">بستن</button>
        </div>
    </div>
</div>

<!-- Edit Chat Modal -->
<div class="modal-overlay" id="editChatModal">
    <div class="modal">
        <h3>ویرایش چت</h3>

        <div class="chat-avatar-upload" id="editChatAvatar" onclick="document.getElementById('chatAvatarInput').click()">
            <span id="editChatAvatarText"></span>
            <div class="avatar-upload-overlay">📷</div>
        </div>
        <div class="avatar-actions">
            <button class="mini-btn" type="button" onclick="removeChatAvatar()">حذف عکس</button>
        </div>
        <input type="file" id="chatAvatarInput" style="display:none" accept="image/*" onchange="uploadChatAvatar(this)">

        <div class="modal-field" style="margin-top:14px">
            <label>نام</label>
            <input type="text" id="editChatName">
        </div>
        <div class="modal-field">
            <label>توضیحات</label>
            <textarea id="editChatDesc"></textarea>
        </div>
        <div class="modal-field">
            <label>آیدی عمومی (public_id)</label>
            <input type="text" id="editPublicId" placeholder="مثلاً: my_channel">
            <div class="small-note">فقط حروف انگلیسی، اعداد و آندرلاین.</div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal('editChatModal')">انصراف</button>
            <button class="btn btn-primary" onclick="saveChatEdit()">ذخیره</button>
        </div>
    </div>
</div>

<!-- Profile Modal -->
<div class="modal-overlay" id="profileModal">
    <div class="modal">
        <h3>تنظیمات کاربری</h3>
        <div class="profile-header">
            <div class="profile-avatar" id="profileAvatar" onclick="document.getElementById('userAvatarInput').click()">
                <span id="profileAvatarText"></span>
                <div class="avatar-upload-overlay">📷</div>
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:15px" id="profileDisplayName"></div>
                <div style="font-size:12px;color:var(--text-secondary)" id="profileDisplayUsername"></div>
                <div class="avatar-actions" style="justify-content:flex-start;margin-top:6px">
                    <button class="mini-btn" type="button" onclick="removeUserAvatar()">حذف عکس</button>
                </div>
            </div>
        </div>
        <input type="file" id="userAvatarInput" style="display:none" accept="image/*" onchange="uploadUserAvatar(this)">

        <div class="section-title">اطلاعات حساب</div>
        <div class="modal-field">
            <label>آیدی</label>
            <input type="text" id="profileUsername" placeholder="آیدی شما (مثلاً: ali_123)">
            <div class="small-note">این آیدی برای یافتن شما توسط دیگران استفاده می‌شود.</div>
        </div>
        <div class="modal-field">
            <label>نام نمایشی</label>
            <input type="text" id="profileName" placeholder="نام نمایشی">
        </div>
        <div class="modal-field">
            <label>بیو</label>
            <textarea id="profileBio" placeholder="بیو..."></textarea>
        </div>
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="saveProfile()">ذخیره پروفایل</button>
        </div>

        <div class="section-title">اعلان‌ها</div>
        <div class="toggle-row">
            <div style="flex:1;min-width:0">
                <div class="toggle-row-label">
                    اعلان پیام جدید
                    <span class="notif-status-pill" id="notifPermPill">غیرفعال</span>
                </div>
                <div class="toggle-row-sub">دریافت اعلان سیستم برای پیام‌های دریافتی</div>
            </div>
            <label class="toggle-switch">
                <input type="checkbox" id="profileNotifEnabled" onchange="toggleNotifPref(this)">
                <span class="toggle-slider"></span>
            </label>
        </div>
        <button class="btn btn-secondary" onclick="requestNotifPermission()" style="width:100%;margin-top:4px" id="notifPermBtn">
            🔔 درخواست مجوز اعلان از مرورگر
        </button>

        <div class="section-title">حریم خصوصی</div>
        <div class="toggle-row">
            <div style="flex:1;min-width:0">
                <div class="toggle-row-label">قابل جستجو بودن</div>
                <div class="toggle-row-sub">اگر غیرفعال باشد، دیگران نمی‌توانند شما را در جستجوی کاربر پیدا کنند</div>
            </div>
            <label class="toggle-switch">
                <input type="checkbox" id="profileSearchable">
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="section-title">تغییر رمز عبور</div>
        <div class="modal-field">
            <label>رمز عبور فعلی</label>
            <input type="password" id="currentPassword">
        </div>
        <div class="modal-field">
            <label>رمز عبور جدید</label>
            <input type="password" id="newPassword">
        </div>
        <div class="modal-field">
            <label>تکرار رمز عبور جدید</label>
            <input type="password" id="confirmPassword">
        </div>
        <div class="modal-actions">
            <button class="btn btn-success" onclick="changePassword()">تغییر رمز عبور</button>
        </div>
    </div>
</div>

<!-- Admin Panel Modal -->
<div class="modal-overlay" id="adminModal">
    <div class="modal large">
        <h3>🛠️ پنل مدیریت</h3>
        <div class="section-title">آمار کلی</div>
        <div class="stats-grid" id="statsGrid"></div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;flex-wrap:wrap">
            <div class="section-title" style="margin:0">مدیریت کاربران</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <input type="text" id="adminUserSearch" placeholder="جستجو..." style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-input);color:var(--text-primary);outline:none;font-family:inherit" oninput="renderAdminUsers()">
                <button class="btn btn-primary" onclick="openUserModal()">+ کاربر جدید</button>
                <button class="btn btn-secondary" onclick="loadAdminData()">بروزرسانی</button>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>آیدی</th>
                        <th>نام</th>
                        <th>نقش</th>
                        <th>وضعیت</th>
                        <th>جستجو</th>
                        <th>تاریخ ثبت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody id="adminUsersTable"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Admin User Modal -->
<div class="modal-overlay" id="adminUserModal">
    <div class="modal">
        <h3 id="adminUserModalTitle">ویرایش کاربر</h3>
        <input type="hidden" id="adminUserId">
        <div class="modal-field">
            <label>آیدی</label>
            <input type="text" id="adminUserUsername" placeholder="آیدی کاربر">
        </div>
        <div class="modal-field">
            <label>نام نمایشی</label>
            <input type="text" id="adminUserName">
        </div>
        <div class="modal-field">
            <label>بیو</label>
            <textarea id="adminUserBio"></textarea>
        </div>
        <div class="modal-field">
            <label id="adminPasswordLabel">رمز عبور جدید (اختیاری)</label>
            <input type="text" id="adminUserPassword" placeholder="برای تغییر رمز وارد کنید">
        </div>
        <div class="modal-field checkbox-row">
            <label class="checkbox-item"><input type="checkbox" id="adminUserActive"> فعال</label>
            <label class="checkbox-item"><input type="checkbox" id="adminUserBlocked"> مسدود</label>
            <label class="checkbox-item"><input type="checkbox" id="adminUserIsAdmin"> ادمین</label>
            <label class="checkbox-item"><input type="checkbox" id="adminUserSearchable"> قابل جستجو</label>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal('adminUserModal')">انصراف</button>
            <button class="btn btn-primary" onclick="saveAdminUser()">ذخیره</button>
        </div>
    </div>
</div>

<!-- Attachment Modal -->
<div class="modal-overlay" id="attachModal">
    <div class="modal">
        <h3 id="attachModalTitle">ارسال فایل</h3>
        <div class="attachment-preview" id="attachPreview"></div>
        <div class="file-meta" id="attachMeta"></div>
        <div class="modal-field" style="margin-top:14px">
            <label>کپشن / توضیحات</label>
            <textarea id="attachCaption" placeholder="توضیحاتی برای این فایل بنویسید..." rows="3"></textarea>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="cancelAttachment()">انصراف</button>
            <button class="btn btn-primary" onclick="sendAttachment()">ارسال</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const currentUser = <?= json_encode($safe_user, JSON_UNESCAPED_UNICODE) ?>;
const LOGO_URL = <?= json_encode($LOGO_URL) ?>;
const BOT_ASSISTANT_ID = 'bot_assistant';

let chats = [];
let currentChat = null;
let selectedMembers = [];
let messagePoller = null;
let currentCreateType = 'private';
let fabOpen = false;
let attachOpen = false;

let adminStats = null;
let adminUsers = [];
let pendingFile = null;
let pendingObjectUrl = null;

let mediaRecorder = null;
let audioChunks = [];
let recordingStream = null;
let recordingInterval = null;
let recordingStart = 0;
let isRecording = false;

let editChatAvatarPath = '';

// ============ NOTIFICATIONS ============
let notifPermission = 'default';
let notifEnabled = false;
let previousChatsSnapshot = {}; // {chatId: lastMessageTime}

function initNotifications() {
    if (!('Notification' in window)) {
        console.warn('Notification API not supported');
        return;
    }
    notifPermission = Notification.permission;
    notifEnabled = localStorage.getItem('notif_enabled_' + currentUser.id) === '1';
    updateNotifUI();
}

function updateNotifUI() {
    const btn = document.getElementById('notifBtn');
    const permPill = document.getElementById('notifPermPill');
    const permBtn = document.getElementById('notifPermBtn');
    const toggle = document.getElementById('profileNotifEnabled');

    if (btn) {
        if (notifEnabled && notifPermission === 'granted') {
            btn.classList.add('notif-enabled');
            btn.textContent = '🔔';
            btn.title = 'اعلان‌ها فعال است';
        } else if (notifPermission === 'granted') {
            btn.classList.remove('notif-enabled');
            btn.textContent = '🔕';
            btn.title = 'اعلان‌ها غیرفعال است';
        } else {
            btn.classList.remove('notif-enabled');
            btn.textContent = '🔕';
            btn.title = 'اعلان‌ها - نیاز به مجوز';
        }
    }

    if (toggle) {
        toggle.checked = notifEnabled && notifPermission === 'granted';
    }

    if (permPill) {
        if (notifPermission === 'granted') {
            permPill.textContent = notifEnabled ? 'فعال' : 'در انتظار فعال‌سازی';
            permPill.className = 'notif-status-pill' + (notifEnabled ? ' on' : '');
        } else if (notifPermission === 'denied') {
            permPill.textContent = 'مسدود شده';
            permPill.className = 'notif-status-pill';
        } else {
            permPill.textContent = 'نیاز به مجوز';
            permPill.className = 'notif-status-pill';
        }
    }

    if (permBtn) {
        if (notifPermission === 'granted') {
            permBtn.style.display = 'none';
        } else if (notifPermission === 'denied') {
            permBtn.style.display = 'block';
            permBtn.disabled = true;
            permBtn.textContent = '⚠️ مرورگر اعلان را مسدود کرده است';
        } else {
            permBtn.style.display = 'block';
            permBtn.disabled = false;
            permBtn.textContent = '🔔 درخواست مجوز اعلان از مرورگر';
        }
    }
}

async function requestNotifPermission() {
    if (!('Notification' in window)) {
        showToast('مرورگر شما از اعلان پشتیبانی نمی‌کند');
        return;
    }
    try {
        const permission = await Notification.requestPermission();
        notifPermission = permission;
        if (permission === 'granted') {
            showToast('مجوز اعلان اعطا شد ✅');
            // Auto-enable if not set
            if (!notifEnabled) {
                notifEnabled = true;
                localStorage.setItem('notif_enabled_' + currentUser.id, '1');
            }
        } else if (permission === 'denied') {
            showToast('اعلان توسط مرورگر مسدود شد. از تنظیمات مرورگر فعال کنید.');
            notifEnabled = false;
            localStorage.setItem('notif_enabled_' + currentUser.id, '0');
        } else {
            showToast('درخواست بسته شد');
        }
    } catch (e) {
        showToast('خطا در درخواست مجوز');
    }
    updateNotifUI();
}

function toggleNotifications() {
    if (notifPermission === 'denied') {
        showToast('اعلان مسدود است. تنظیمات مرورگر را بررسی کنید');
        openProfileModal();
        return;
    }
    if (notifPermission !== 'granted') {
        requestNotifPermission();
        return;
    }
    notifEnabled = !notifEnabled;
    localStorage.setItem('notif_enabled_' + currentUser.id, notifEnabled ? '1' : '0');
    showToast(notifEnabled ? 'اعلان‌ها فعال شد 🔔' : 'اعلان‌ها غیرفعال شد 🔕');
    updateNotifUI();
}

function toggleNotifPref(checkbox) {
    if (notifPermission !== 'granted') {
        checkbox.checked = false;
        requestNotifPermission();
        return;
    }
    notifEnabled = checkbox.checked;
    localStorage.setItem('notif_enabled_' + currentUser.id, notifEnabled ? '1' : '0');
    showToast(notifEnabled ? 'اعلان‌ها فعال شد' : 'اعلان‌ها غیرفعال شد');
    updateNotifUI();
}

function showNativeNotification(title, body, chatId, icon) {
    if (!notifEnabled || notifPermission !== 'granted') return;
    // Don't notify if we're currently viewing that chat
    if (currentChat && currentChat.id === chatId && document.hasFocus()) return;

    try {
        const notif = new Notification(title, {
            body: body,
            icon: icon || LOGO_URL,
            badge: LOGO_URL,
            tag: 'chat_' + chatId,
            requireInteraction: false,
            silent: false
        });

        notif.onclick = function() {
            window.focus();
            if (chatId) openChat(chatId);
            notif.close();
        };

        // Auto-close after 6 seconds
        setTimeout(() => {
            try { notif.close(); } catch(e) {}
        }, 6000);
    } catch (e) {
        console.error('Notification error:', e);
    }
}

function checkAndNotifyNewMessages(newChats) {
    if (!notifEnabled || notifPermission !== 'granted') return;

    const newSnapshot = {};
    newChats.forEach(c => {
        newSnapshot[c.id] = {
            last_time: c.last_time || 0,
            last_is_mine: c.last_is_mine,
            last_message: c.last_message,
            last_user_id: c.last_user_id,
            last_username: c.last_username,
            display_name: c.display_name,
            type: c.type,
            name: c.name,
            avatar_path: c.avatar_path || c.avatar_image
        };
    });

    // Compare with previous snapshot
    Object.keys(newSnapshot).forEach(chatId => {
        const curr = newSnapshot[chatId];
        const prev = previousChatsSnapshot[chatId];

        // New message if:
        // 1. This chat didn't exist before
        // 2. last_time changed AND message is NOT from us AND there's unread
        if (!prev) {
            // First load - don't notify
        } else if (curr.last_time > prev.last_time && !curr.last_is_mine) {
            // Only notify if there's unread (we haven't read it yet)
            const chat = newChats.find(c => c.id === chatId);
            if (chat && (chat.unread_count || 0) > 0) {
                // Don't notify if currently viewing this chat
                if (!(currentChat && currentChat.id === chatId && document.hasFocus())) {
                    const senderName = curr.type === 'private'
                        ? (curr.display_name || 'کاربر')
                        : ((curr.last_username || 'کاربر') + ' در ' + (curr.name || curr.display_name));

                    let icon = LOGO_URL;
                    if (curr.avatar_path) icon = window.location.origin + '/' + curr.avatar_path;

                    showNativeNotification(
                        senderName,
                        curr.last_message || 'پیام جدید',
                        chatId,
                        icon
                    );
                }
            }
        }
    });

    previousChatsSnapshot = newSnapshot;
}

// ============ END NOTIFICATIONS ============

function initTheme() {
    const saved = localStorage.getItem('theme') || 'dark';
    document.body.setAttribute('data-theme', saved);
    document.getElementById('themeBtn').textContent = saved === 'dark' ? '☀️' : '🌙';
}

function toggleTheme() {
    const cur = document.body.getAttribute('data-theme');
    const next = cur === 'dark' ? 'light' : 'dark';
    document.body.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    document.getElementById('themeBtn').textContent = next === 'dark' ? '☀️' : '🌙';
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show';
    setTimeout(() => t.className = 'toast', 2500);
}

function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/[&<>"']/g, m => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
}

function formatDate(ts) {
    return new Date(ts * 1000).toLocaleDateString('fa-IR');
}

function formatTime(ts) {
    return new Date(ts * 1000).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
}

function formatDuration(ms) {
    const s = Math.floor(ms / 1000);
    const m = Math.floor(s / 60);
    const sec = s % 60;
    return `${m}:${sec.toString().padStart(2, '0')}`;
}

function linkify(text) {
    if (!text) return '';
    const urlRegex = /(https?:\/\/[^\s<>"']+)|(?:^|\s)((?:www\.)[^\s<>"']+)|((?:[a-zA-Z0-9-]+\.)+(?:com|net|org|ir|io|co|info|me|tv|app|dev|xyz)(?:\/[^\s<>"']*)?)/gi;
    return escapeHtml(text).replace(urlRegex, (match) => {
        let url = match.trim();
        let prefix = '';
        if (match.startsWith(' ')) {
            prefix = ' ';
            url = match.slice(1);
        }
        let fullUrl = url;
        if (!/^https?:\/\//i.test(fullUrl)) fullUrl = 'https://' + fullUrl;
        return `${prefix}<a href="${escapeHtml(fullUrl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(url)}</a>`;
    });
}

function renderTicks(seen) {
    const singleTick = '<svg viewBox="0 0 24 24" width="14" height="14"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" fill="currentColor"/></svg>';
    const doubleTick = '<svg viewBox="0 0 24 24" width="14" height="14"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" fill="currentColor"/><path d="M18 7.5l-1.41-1.41-7.59 7.59 1.41 1.41L18 7.5z" fill="currentColor" transform="translate(-4,0)"/></svg>';

    if (seen) {
        return `<span class="msg-ticks seen">${doubleTick}</span>`;
    } else {
        return `<span class="msg-ticks sent">${singleTick}</span>`;
    }
}

function avatarClass(type) {
    if (type === 'group') return 'chat-avatar group';
    if (type === 'channel') return 'chat-avatar channel';
    return 'chat-avatar';
}

function getAvatarHTML(displayName, avatarPath, options = {}) {
    const useLogo = options.useLogo || false;
    if (useLogo) return `<img src="${escapeHtml(LOGO_URL)}" alt="">`;
    if (avatarPath) return `<img src="${escapeHtml(avatarPath)}" alt="${escapeHtml(displayName || '')}">`;
    return escapeHtml((displayName || '?')[0].toUpperCase());
}

function toggleFab() {
    const container = document.getElementById('fabContainer');
    const btn = document.getElementById('fabBtn');
    fabOpen = !fabOpen;
    if (fabOpen) {
        container.classList.add('open');
        btn.classList.add('active');
    } else {
        container.classList.remove('open');
        btn.classList.remove('active');
    }
}

function closeFab() {
    const container = document.getElementById('fabContainer');
    const btn = document.getElementById('fabBtn');
    fabOpen = false;
    container.classList.remove('open');
    btn.classList.remove('active');
}

function toggleAttach() {
    const pop = document.getElementById('attachPop');
    attachOpen = !attachOpen;
    if (attachOpen) pop.classList.add('active');
    else pop.classList.remove('active');
}

function closeAttach() {
    attachOpen = false;
    document.getElementById('attachPop').classList.remove('active');
}

function pickAttach(type) {
    closeAttach();
    if (type === 'image') document.getElementById('imageInput').click();
    else if (type === 'video') document.getElementById('videoInput').click();
    else document.getElementById('fileInput').click();
}

function createChat(type) {
    currentCreateType = type;
    closeFab();
    document.getElementById('newChatModal').classList.add('active');
    const titles = { private: 'پیام خصوصی جدید', group: 'ایجاد گروه جدید', channel: 'ایجاد کانال جدید' };
    document.getElementById('newChatTitle').textContent = titles[type] || 'ایجاد چت جدید';
    document.getElementById('chatNameField').style.display = type === 'private' ? 'none' : 'block';
    document.getElementById('chatDescField').style.display = type === 'private' ? 'none' : 'block';
    document.getElementById('membersLabel').textContent = type === 'private' ? 'انتخاب کاربر' : 'افزودن اعضا (اختیاری)';
    document.getElementById('newChatName').value = '';
    document.getElementById('newChatDesc').value = '';
    selectedMembers = [];
    loadUsers();
}

function updateTotalNotifBadge() {
    const total = chats.reduce((sum, c) => sum + (c.unread_count || 0), 0);
    const badge = document.getElementById('totalNotifBadge');
    if (total > 0) {
        badge.textContent = total > 99 ? '99+' : total;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
    updateDocumentTitle();
}

function updateDocumentTitle() {
    const total = chats.reduce((sum, c) => sum + (c.unread_count || 0), 0);
    const baseTitle = 'داشبورد اسپاتیرا | پیام‌رسان';
    document.title = total > 0 ? `(${total}) ${baseTitle}` : baseTitle;
}

async function loadChats() {
    try {
        const res = await fetch('?action=get_chats');
        const data = await res.json();
        const newChats = data.chats || [];
        checkAndNotifyNewMessages(newChats);
        chats = newChats;
        updateTotalNotifBadge();
        renderChats();
    } catch (e) { console.error(e); }
}

function renderChats() {
    const list = document.getElementById('chatList');
    const q = document.getElementById('searchChats').value.trim().toLowerCase();
    const filtered = q ? chats.filter(c => (c.display_name || '').toLowerCase().includes(q)) : chats;

    if (filtered.length === 0) {
        list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-secondary)">چتی یافت نشد</div>';
        return;
    }

    list.innerHTML = filtered.map(c => {
        let typeBadge = '';
        if (c.type === 'group') typeBadge = '<span class="chat-type-badge" style="background:#ff6b6b">گروه</span>';
        if (c.type === 'channel') typeBadge = '<span class="chat-type-badge">کانال</span>';

        let useLogo = false, avType = 'user';
        if (c.type === 'private' && (c.other_user_id === BOT_ASSISTANT_ID || c.other_is_bot)) useLogo = true;
        else if (c.type === 'group') avType = 'group';
        else if (c.type === 'channel') avType = 'channel';

        const avatarHTML = getAvatarHTML(c.display_name, c.avatar_path, { useLogo, type: avType });
        const preview = c.last_message || (c.type === 'channel' ? 'هنوز پیامی نیست' : 'هنوز پیامی نیست');

        let lastTickHtml = '';
        if (c.last_is_mine) {
            lastTickHtml = renderTicks(c.last_seen);
        }

        const unreadHtml = (c.unread_count > 0) ? `<div class="unread-badge">${c.unread_count > 99 ? '99+' : c.unread_count}</div>` : '';

        return `
            <div class="chat-item ${currentChat?.id === c.id ? 'active' : ''}" onclick="openChat('${c.id}')">
                <div class="${avatarClass(avType)}">${avatarHTML}</div>
                <div class="chat-info">
                    <div class="chat-top">
                        <div class="chat-name">${typeBadge}${escapeHtml(c.display_name || 'نامشخص')}</div>
                        <div class="chat-time">${formatTime(c.last_time || c.created_at)}</div>
                    </div>
                    <div class="chat-preview-wrap">
                        <div class="chat-preview">${lastTickHtml}${escapeHtml(preview)}</div>
                        ${unreadHtml}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function filterChats() { renderChats(); }

async function openChat(chatId) {
    currentChat = chats.find(c => c.id === chatId) || { id: chatId };
    renderChats();
    document.getElementById('app').classList.add('show-chat');
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('chatContainer').style.display = 'flex';
    try {
        const res = await fetch(`?action=get_messages&chat_id=${encodeURIComponent(chatId)}`);
        const data = await res.json();
        if (data.error) { showToast(data.error); return; }
        currentChat = { ...currentChat, ...data.chat };
        renderChatHeader();
        renderMessages(data.messages || []);
        startPolling();
        markChatRead(chatId);
    } catch (e) { console.error(e); }
}

async function markChatRead(chatId) {
    try {
        const formData = new FormData();
        formData.append('chat_id', chatId);
        await fetch('?action=mark_chat_read', { method: 'POST', body: formData });
        const c = chats.find(x => x.id === chatId);
        if (c) {
            c.unread_count = 0;
            renderChats();
            updateTotalNotifBadge();
        }
    } catch (e) {}
}

function renderChatHeader() {
    const displayName = currentChat.type === 'private' ? (currentChat.display_name || '?') : (currentChat.name || '?');
    let useLogo = false, avType = 'user';
    if (currentChat.type === 'private' && (currentChat.other_user_id === BOT_ASSISTANT_ID || currentChat.other_is_bot)) useLogo = true;
    else if (currentChat.type === 'group') avType = 'group';
    else if (currentChat.type === 'channel') avType = 'channel';

    const avatarEl = document.getElementById('chatAvatar');
    avatarEl.className = avatarClass(avType);
    const avatarPath = currentChat.type === 'private' ? (currentChat.avatar_path || '') : (currentChat.avatar_image || '');
    avatarEl.innerHTML = getAvatarHTML(displayName, avatarPath, { useLogo, type: avType });
    document.getElementById('chatName').textContent = displayName;

    let status = '';
    if (currentChat.type === 'private') {
        status = useLogo ? '✨ دستیار هوشمند' : '@' + (currentChat.display_name || '');
    } else if (currentChat.type === 'group') status = `${currentChat.members?.length || 0} عضو`;
    else if (currentChat.type === 'channel') status = `${currentChat.members?.length || 0} دنبال‌کننده`;
    document.getElementById('chatStatus').textContent = status;
}

function renderMessages(messages) {
    const container = document.getElementById('messages');
    const atBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 80;
    container.innerHTML = messages.map(m => renderMessage(m)).join('');
    if (atBottom) container.scrollTop = container.scrollHeight;
}

function renderMessage(m) {
    const isMe = m.user_id === currentUser.id;
    const isAssistant = m.user_id === BOT_ASSISTANT_ID;
    const time = formatTime(m.created_at);

    let fileHtml = '';
    if (m.file_path) {
        if (m.file_type === 'image') {
            fileHtml = `<div class="message-file"><img src="${escapeHtml(m.file_path)}" alt="" loading="lazy"></div>`;
        } else if (m.file_type === 'video') {
            fileHtml = `<div class="message-file"><video src="${escapeHtml(m.file_path)}" controls preload="metadata" playsinline></video></div>`;
        } else if (m.file_type === 'audio') {
            const bars = Array.from({length: 20}, () => Math.floor(Math.random() * 70) + 30);
            fileHtml = `
                <div class="voice-msg" data-src="${escapeHtml(m.file_path)}">
                    <button class="voice-play-btn" onclick="toggleVoicePlay(this)">▶</button>
                    <div class="voice-waveform">
                        ${bars.map(h => `<div class="bar" style="height:${h}%"></div>`).join('')}
                    </div>
                    <div class="voice-time">🎵</div>
                </div>
            `;
        } else {
            fileHtml = `<div class="message-file"><a href="${escapeHtml(m.file_path)}" target="_blank" download="${escapeHtml(m.file_name || 'file')}">📎 ${escapeHtml(m.file_name || 'فایل')}</a></div>`;
        }
    }

    const caption = m.file_path ? (m.caption || m.text || '') : '';
    const normalText = m.file_path ? '' : (m.text || '');

    const captionHtml = caption ? `<div class="message-text message-caption">${linkify(caption)}</div>` : '';
    const textHtml = normalText ? `<div class="message-text">${linkify(normalText)}</div>` : '';

    let previewHtml = '';
    if (m.link_preview && (m.link_preview.title || m.link_preview.description)) {
        const p = m.link_preview;
        const imgHtml = p.image ? `<img class="link-preview-image" src="${escapeHtml(p.image)}" alt="" loading="lazy" onerror="this.style.display='none'">` : '';
        previewHtml = `
            <a href="${escapeHtml(p.url)}" target="_blank" rel="noopener noreferrer" class="link-preview-card" onclick="event.stopPropagation()">
                ${imgHtml}
                <div class="link-preview-content">
                    ${p.site ? `<div class="link-preview-site">🌐 ${escapeHtml(p.site)}</div>` : ''}
                    ${p.title ? `<div class="link-preview-title">${escapeHtml(p.title)}</div>` : ''}
                    ${p.description ? `<div class="link-preview-desc">${escapeHtml(p.description)}</div>` : ''}
                </div>
            </a>
        `;
    }

    const canDelete = isMe || currentUser.is_admin || (currentChat && currentChat.owner_id === currentUser.id);

    let tickHtml = '';
    if (isMe && currentChat) {
        const seen = (m.seen_by || []).some(id => id !== currentUser.id && (currentChat.members || []).includes(id));
        tickHtml = renderTicks(seen);
    }

    let actionsHtml = '';
    if (isMe || canDelete) {
        let buttons = [];
        if (isMe) {
            buttons.push(`<button type="button" class="msg-action-inline" onclick="editMessage('${m.id}')">✏️ ویرایش</button>`);
        }
        if (canDelete) {
            buttons.push(`<button type="button" class="msg-action-inline danger" onclick="deleteMessage('${m.id}')">🗑️ حذف</button>`);
        }
        if (buttons.length > 0) {
            actionsHtml = `<div class="message-actions-inline">${buttons.join('')}</div>`;
        }
    }

    return `
        <div class="message ${isMe ? 'me' : 'other'}" data-id="${m.id}">
            ${!isMe && currentChat && currentChat.type !== 'private' ? `<div style="font-size:12px;font-weight:600;color:${isAssistant ? 'var(--accent)' : 'var(--accent-strong)'};margin-bottom:4px">${escapeHtml(m.username)}</div>` : ''}
            ${fileHtml}
            ${captionHtml}
            ${textHtml}
            ${previewHtml}
            <div class="message-meta">
                ${m.edited ? '<span class="message-edited">ویرایش شده</span>' : ''}
                ${tickHtml}
                <span>${time}</span>
            </div>
            ${actionsHtml}
        </div>
    `;
}

let currentVoiceAudio = null;
let currentVoiceBtn = null;

function toggleVoicePlay(btn) {
    const voiceMsg = btn.closest('.voice-msg');
    const src = voiceMsg.dataset.src;
    const bars = voiceMsg.querySelectorAll('.bar');
    const timeEl = voiceMsg.querySelector('.voice-time');

    if (currentVoiceAudio && currentVoiceBtn === btn) {
        currentVoiceAudio.pause();
        currentVoiceAudio.currentTime = 0;
        btn.textContent = '▶';
        bars.forEach(b => b.classList.remove('played'));
        timeEl.textContent = '🎵';
        currentVoiceAudio = null;
        currentVoiceBtn = null;
        return;
    }

    if (currentVoiceAudio) {
        currentVoiceAudio.pause();
        if (currentVoiceBtn) currentVoiceBtn.textContent = '▶';
    }

    const audio = new Audio(src);
    currentVoiceAudio = audio;
    currentVoiceBtn = btn;

    audio.addEventListener('play', () => {
        btn.textContent = '⏸';
    });
    audio.addEventListener('pause', () => {
        btn.textContent = '▶';
    });
    audio.addEventListener('ended', () => {
        btn.textContent = '▶';
        bars.forEach(b => b.classList.remove('played'));
        timeEl.textContent = '🎵';
        currentVoiceAudio = null;
        currentVoiceBtn = null;
    });
    audio.addEventListener('timeupdate', () => {
        const progress = audio.currentTime / audio.duration;
        const playedCount = Math.floor(progress * bars.length);
        bars.forEach((b, i) => {
            if (i < playedCount) b.classList.add('played');
            else b.classList.remove('played');
        });
        const remaining = audio.duration - audio.currentTime;
        timeEl.textContent = formatDuration(remaining * 1000);
    });

    audio.play().catch(() => {
        btn.textContent = '▶';
        showToast('خطا در پخش صدا');
    });
}

function startPolling() {
    if (messagePoller) clearInterval(messagePoller);
    messagePoller = setInterval(async () => {
        if (!currentChat) return;
        try {
            const res = await fetch(`?action=get_messages&chat_id=${encodeURIComponent(currentChat.id)}`);
            const data = await res.json();
            if (data.messages) renderMessages(data.messages);
        } catch (e) {}
    }, 3000);

    if (window.chatsPoller) clearInterval(window.chatsPoller);
    window.chatsPoller = setInterval(() => loadChats(), 5000);
}

function closeChat() {
    document.getElementById('app').classList.remove('show-chat');
    currentChat = null;
    if (messagePoller) { clearInterval(messagePoller); messagePoller = null; }
    document.getElementById('chatContainer').style.display = 'none';
    document.getElementById('emptyState').style.display = 'flex';
    renderChats();
}

async function sendMessage() {
    if (isRecording) return;
    const input = document.getElementById('messageInput');
    const text = input.value.trim();
    if (!text || !currentChat) return;

    const formData = new FormData();
    formData.append('chat_id', currentChat.id);
    formData.append('text', text);

    input.value = '';
    input.style.height = 'auto';

    try {
        const res = await fetch('?action=send_message', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.error) { showToast(data.error); return; }
        loadChats();
        openChat(currentChat.id);
    } catch (e) { showToast('خطا در ارسال پیام'); }
}

function openAttachmentModal(file, type) {
    pendingFile = file;
    const preview = document.getElementById('attachPreview');
    const meta = document.getElementById('attachMeta');
    const title = document.getElementById('attachModalTitle');

    preview.innerHTML = '';
    meta.textContent = `${file.name} • ${formatBytes(file.size)}`;

    if (type === 'image') title.textContent = '🖼️ ارسال تصویر';
    else if (type === 'video') title.textContent = '🎬 ارسال ویدیو';
    else title.textContent = '📄 ارسال فایل';

    if (pendingObjectUrl) { URL.revokeObjectURL(pendingObjectUrl); pendingObjectUrl = null; }

    if (file.type.startsWith('image/')) {
        pendingObjectUrl = URL.createObjectURL(file);
        preview.innerHTML = `<img src="${pendingObjectUrl}" alt="">`;
    } else if (file.type.startsWith('video/')) {
        pendingObjectUrl = URL.createObjectURL(file);
        preview.innerHTML = `<video src="${pendingObjectUrl}" controls playsinline></video>`;
    } else {
        preview.innerHTML = `<div style="font-size:52px;text-align:center">📄<div style="font-size:13px;margin-top:10px;color:var(--text-secondary)">${escapeHtml(file.name)}</div></div>`;
    }

    document.getElementById('attachCaption').value = '';
    document.getElementById('attachModal').classList.add('active');
    setTimeout(() => document.getElementById('attachCaption').focus(), 300);
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

    try {
        const res = await fetch('?action=send_message', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.error) { showToast(data.error); return; }
        cancelAttachment();
        loadChats();
        openChat(currentChat.id);
    } catch (e) { showToast('خطا در ارسال فایل'); }
}

async function editMessage(msgId) {
    const msgEl = document.querySelector(`.message[data-id="${msgId}"]`);
    if (!msgEl) return;
    const captionEl = msgEl.querySelector('.message-caption');
    const textEl = msgEl.querySelector('.message-text:not(.message-caption)');
    const currentText = captionEl ? captionEl.innerText : (textEl ? textEl.innerText : '');
    const newText = prompt('ویرایش پیام:', currentText);
    if (newText === null || newText === currentText) return;
    const formData = new FormData();
    formData.append('message_id', msgId);
    formData.append('text', newText);
    const res = await fetch('?action=edit_message', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) { showToast(data.error); return; }
    openChat(currentChat.id);
}

async function deleteMessage(msgId) {
    if (!confirm('این پیام حذف شود؟')) return;
    const formData = new FormData();
    formData.append('message_id', msgId);
    const res = await fetch('?action=delete_message', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) { showToast(data.error); return; }
    openChat(currentChat.id);
}

async function loadUsers() {
    const res = await fetch('?action=get_users');
    const data = await res.json();
    renderUsers(data.users || []);
}

function renderUsers(users) {
    const list = document.getElementById('usersList');
    if (users.length === 0) {
        list.innerHTML = '<div style="padding:8px;text-align:center;color:var(--text-secondary);font-size:13px">کاربری یافت نشد</div>';
        return;
    }
    list.innerHTML = users.map(u => {
        const label = u.name ? `${escapeHtml(u.name)} (@${escapeHtml(u.username)})` : `@${escapeHtml(u.username)}`;
        const avatarHtml = u.avatar
            ? `<img src="${escapeHtml(u.avatar)}" alt="">`
            : escapeHtml((u.name || u.username || '?')[0].toUpperCase());
        return `<label class="user-option"><input type="checkbox" value="${u.id}" onchange="updateSelectedMembers()" ${selectedMembers.includes(u.id) ? 'checked' : ''}><div class="user-option-avatar">${avatarHtml}</div><span>${label}</span></label>`;
    }).join('');
}

function updateSelectedMembers() {
    selectedMembers = Array.from(document.querySelectorAll('#usersList input:checked')).map(i => i.value);
}

let searchTimeout;
async function searchUsers() {
    clearTimeout(searchTimeout);
    const q = document.getElementById('userSearch').value.trim();
    if (!q) { loadUsers(); return; }
    searchTimeout = setTimeout(async () => {
        const res = await fetch(`?action=search_users&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        renderUsers(data.users || []);
    }, 300);
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
        if (selectedMembers.length === 0) { showToast('یک کاربر انتخاب کنید'); return; }
    }
    selectedMembers.forEach(m => formData.append('members[]', m));
    try {
        const res = await fetch('?action=create_chat', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.error) { showToast(data.error); return; }
        closeModal('newChatModal');
        selectedMembers = [];
        await loadChats();
        if (data.chat) openChat(data.chat.id);
        else if (data.chat_id) openChat(data.chat_id);
    } catch (e) { showToast('خطا در ایجاد چت'); }
}

function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function openChatMenu() {
    if (!currentChat) return;
    document.getElementById('menuTitle').textContent = currentChat.display_name || currentChat.name || 'چت';
    const isOwner = currentChat.owner_id === currentUser.id;
    const isPrivate = currentChat.type === 'private';
    let html = '<div style="display:flex;flex-direction:column;gap:8px">';
    if (!isPrivate && (isOwner || currentUser.is_admin)) {
        html += `<button class="btn btn-primary" onclick="openEditChat()">✏️ ویرایش و تنظیم آیدی عمومی</button>`;
    }
    if (!isPrivate && currentChat.public_id) {
        const link = `${window.location.origin}/index.php?join=${encodeURIComponent(currentChat.public_id)}`;
        html += `<div style="margin-top:8px"><label style="font-size:13px;color:var(--text-secondary)">🔗 لینک عضویت:</label><div class="link-box"><input type="text" id="inviteLink" value="${escapeHtml(link)}" readonly><button class="copy-btn" onclick="copyLink()">کپی</button></div></div>`;
    }
    html += `<button class="btn btn-secondary" onclick="leaveChat()">🚪 ${isPrivate ? 'بستن چت' : 'خروج از ' + (currentChat.type === 'channel' ? 'کانال' : 'گروه')}</button>`;
    if (!isPrivate && (isOwner || currentUser.is_admin)) {
        html += `<button class="btn btn-danger" onclick="deleteChat()">🗑️ حذف ${currentChat.type === 'channel' ? 'کانال' : 'گروه'}</button>`;
    }
    html += '</div>';
    document.getElementById('chatMenuContent').innerHTML = html;
    document.getElementById('chatMenuModal').classList.add('active');
}

function openEditChat() {
    closeModal('chatMenuModal');
    document.getElementById('editChatName').value = currentChat.name || '';
    document.getElementById('editChatDesc').value = currentChat.description || '';
    document.getElementById('editPublicId').value = currentChat.public_id || '';

    editChatAvatarPath = currentChat.avatar_image || '';
    updateEditChatAvatarUI();

    document.getElementById('editChatModal').classList.add('active');
}

function updateEditChatAvatarUI() {
    const avatarEl = document.getElementById('editChatAvatar');
    const textEl = document.getElementById('editChatAvatarText');
    if (editChatAvatarPath) {
        avatarEl.innerHTML = `<img src="${escapeHtml(editChatAvatarPath)}" alt=""><div class="avatar-upload-overlay">📷</div>`;
    } else {
        const displayName = currentChat.name || '?';
        textEl.textContent = displayName[0].toUpperCase();
        avatarEl.innerHTML = `<span id="editChatAvatarText">${escapeHtml(displayName[0].toUpperCase())}</span><div class="avatar-upload-overlay">📷</div>`;
    }
}

async function uploadChatAvatar(input) {
    if (!input.files || !input.files[0] || !currentChat) return;
    const formData = new FormData();
    formData.append('chat_id', currentChat.id);
    formData.append('avatar', input.files[0]);
    try {
        const res = await fetch('?action=upload_chat_avatar', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.error) { showToast(data.error); input.value = ''; return; }
        editChatAvatarPath = data.avatar;
        updateEditChatAvatarUI();
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
        const res = await fetch('?action=remove_chat_avatar', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.error) { showToast(data.error); return; }
        editChatAvatarPath = '';
        updateEditChatAvatarUI();
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
    formData.append('public_id', document.getElementById('editPublicId').value);
    const res = await fetch('?action=update_chat', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) { showToast(data.error); return; }
    closeModal('editChatModal');
    showToast('ذخیره شد');
    await loadChats();
    openChat(currentChat.id);
}

async function leaveChat() {
    if (!confirm('مطمئن هستید؟')) return;
    const formData = new FormData();
    formData.append('chat_id', currentChat.id);
    const res = await fetch('?action=leave_chat', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) { showToast(data.error); return; }
    closeModal('chatMenuModal');
    closeChat();
    await loadChats();
}

async function deleteChat() {
    if (!confirm('این چت به طور کامل حذف شود؟ این عمل قابل بازگشت نیست.')) return;
    const formData = new FormData();
    formData.append('chat_id', currentChat.id);
    const res = await fetch('?action=delete_chat', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) { showToast(data.error); return; }
    closeModal('chatMenuModal');
    closeChat();
    showToast('حذف شد');
    await loadChats();
}

function copyLink() {
    const input = document.getElementById('inviteLink');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => showToast('لینک کپی شد')).catch(() => {
        document.execCommand('copy');
        showToast('لینک کپی شد');
    });
}

async function logout() {
    if (!confirm('خروج از حساب؟')) return;
    const res = await fetch('?action=logout');
    const data = await res.json();
    window.location.href = data.redirect || 'index.php';
}

function openProfileModal() {
    const u = currentUser;
    const avatarEl = document.getElementById('profileAvatar');
    if (u.avatar) {
        avatarEl.innerHTML = `<img src="${escapeHtml(u.avatar)}" alt=""><div class="avatar-upload-overlay">📷</div>`;
    } else {
        avatarEl.innerHTML = `<span id="profileAvatarText">${escapeHtml((u.name || u.username || '?')[0].toUpperCase())}</span><div class="avatar-upload-overlay">📷</div>`;
    }
    document.getElementById('profileDisplayName').textContent = u.name || u.username;
    document.getElementById('profileDisplayUsername').textContent = '@' + u.username;
    document.getElementById('profileUsername').value = u.username || '';
    document.getElementById('profileName').value = u.name || '';
    document.getElementById('profileBio').value = u.bio || '';
    document.getElementById('profileSearchable').checked = !!u.privacy_searchable;
    document.getElementById('currentPassword').value = '';
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
    updateNotifUI();
    document.getElementById('profileModal').classList.add('active');
}

async function uploadUserAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const formData = new FormData();
    formData.append('avatar', input.files[0]);
    try {
        const res = await fetch('?action=upload_user_avatar', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.error) { showToast(data.error); input.value = ''; return; }
        currentUser.avatar = data.avatar;
        openProfileModal();
        showToast('عکس پروفایل آپلود شد');
    } catch (e) {
        showToast('خطا در آپلود');
    }
    input.value = '';
}

async function removeUserAvatar() {
    if (!currentUser.avatar) {
        showToast('عکسی برای حذف وجود ندارد');
        return;
    }
    if (!confirm('عکس پروفایل حذف شود؟')) return;
    try {
        const res = await fetch('?action=remove_user_avatar', { method: 'POST' });
        const data = await res.json();
        if (data.error) { showToast(data.error); return; }
        currentUser.avatar = '';
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
    if (!username) { showToast('آیدی نمی‌تواند خالی باشد'); return; }
    const formData = new FormData();
    formData.append('username', username);
    formData.append('name', name);
    formData.append('bio', bio);
    formData.append('privacy_searchable', privacy_searchable ? '1' : '');
    const res = await fetch('?action=update_profile', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) { showToast(data.error); return; }
    currentUser.privacy_searchable = privacy_searchable;
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
    const res = await fetch('?action=change_password', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) { showToast(data.error); return; }
    showToast('رمز عبور تغییر کرد');
    document.getElementById('currentPassword').value = '';
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
}

async function openAdminPanel() {
    document.getElementById('adminModal').classList.add('active');
    await loadAdminData();
}

async function loadAdminData() {
    try {
        const [statsRes, usersRes] = await Promise.all([
            fetch('?action=admin_get_stats'),
            fetch('?action=admin_get_users')
        ]);
        const statsData = await statsRes.json();
        const usersData = await usersRes.json();
        if (statsData.error) { showToast(statsData.error); return; }
        adminStats = statsData.stats;
        adminUsers = usersData.users || [];
        renderAdminStats();
        renderAdminUsers();
    } catch (e) { console.error(e); }
}

function renderAdminStats() {
    if (!adminStats) return;
    const items = [
        { label: 'کاربران', value: adminStats.total_users },
        { label: 'کاربران فعال', value: adminStats.active_users },
        { label: 'قابل جستجو', value: adminStats.searchable_users },
        { label: 'مسدودها', value: adminStats.blocked_users },
        { label: 'ادمین‌ها', value: adminStats.admin_users },
        { label: 'چت‌ها', value: adminStats.total_chats },
        { label: 'خصوصی', value: adminStats.private_chats },
        { label: 'گروه‌ها', value: adminStats.group_chats },
        { label: 'کانال‌ها', value: adminStats.channel_chats },
        { label: 'پیام‌ها', value: adminStats.total_messages },
        { label: 'پیام‌های امروز', value: adminStats.today_messages },
        { label: 'حجم فایل‌ها', value: formatBytes(adminStats.upload_size) },
    ];
    document.getElementById('statsGrid').innerHTML = items.map(i => `
        <div class="stat-card"><div class="stat-value">${i.value}</div><div class="stat-label">${i.label}</div></div>
    `).join('');
}

function renderAdminUsers() {
    const q = document.getElementById('adminUserSearch').value.trim().toLowerCase();
    const filtered = q ? adminUsers.filter(u => u.username.toLowerCase().includes(q) || (u.name || '').toLowerCase().includes(q)) : adminUsers;
    const tbody = document.getElementById('adminUsersTable');
    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--text-secondary)">کاربری یافت نشد</td></tr>`;
        return;
    }
    tbody.innerHTML = filtered.map(u => {
        const role = u.is_bot ? '<span class="badge info">ربات</span>' : (u.is_admin ? '<span class="badge warning">ادمین</span>' : '<span class="badge muted">کاربر</span>');
        const status = u.blocked ? '<span class="badge danger">مسدود</span>' : (u.active ? '<span class="badge success">فعال</span>' : '<span class="badge danger">غیرفعال</span>');
        const searchable = u.privacy_searchable ? '<span class="badge success">فعال</span>' : '<span class="badge muted">غیرفعال</span>';
        const selfBadge = u.id === currentUser.id ? ' <span class="badge info">شما</span>' : '';
        return `<tr>
            <td>@${escapeHtml(u.username)}${selfBadge}</td>
            <td>${escapeHtml(u.name || '-')}</td>
            <td>${role}</td>
            <td>${status}</td>
            <td>${searchable}</td>
            <td>${formatDate(u.created_at)}</td>
            <td><div class="action-buttons">
                ${!u.is_bot ? `<button class="mini-btn" onclick="openUserModal('${u.id}')">✏️ ویرایش</button>` : ''}
                ${!u.is_bot && u.id !== currentUser.id ? `<button class="mini-btn danger" onclick="deleteUser('${u.id}')">🗑️ حذف</button>` : ''}
            </div></td>
        </tr>`;
    }).join('');
}

function openUserModal(userId = null) {
    document.getElementById('adminUserModal').classList.add('active');
    document.getElementById('adminUserId').value = userId || '';
    if (!userId) {
        document.getElementById('adminUserModalTitle').textContent = 'افزودن کاربر جدید';
        document.getElementById('adminPasswordLabel').textContent = 'رمز عبور';
        document.getElementById('adminUserUsername').value = '';
        document.getElementById('adminUserName').value = '';
        document.getElementById('adminUserBio').value = '';
        document.getElementById('adminUserPassword').value = '';
        document.getElementById('adminUserActive').checked = true;
        document.getElementById('adminUserBlocked').checked = false;
        document.getElementById('adminUserIsAdmin').checked = false;
        document.getElementById('adminUserSearchable').checked = true;
        return;
    }
    const u = adminUsers.find(x => x.id === userId);
    if (!u) return;
    document.getElementById('adminUserModalTitle').textContent = 'ویرایش کاربر';
    document.getElementById('adminPasswordLabel').textContent = 'رمز عبور جدید (اختیاری)';
    document.getElementById('adminUserUsername').value = u.username;
    document.getElementById('adminUserName').value = u.name || '';
    document.getElementById('adminUserBio').value = u.bio || '';
    document.getElementById('adminUserPassword').value = '';
    document.getElementById('adminUserActive').checked = !!u.active;
    document.getElementById('adminUserBlocked').checked = !!u.blocked;
    document.getElementById('adminUserIsAdmin').checked = !!u.is_admin;
    document.getElementById('adminUserSearchable').checked = !!u.privacy_searchable;
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
    const res = await fetch(`?action=${action}`, { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) { showToast(data.error); return; }
    closeModal('adminUserModal');
    showToast('ذخیره شد');
    if (user_id === currentUser.id) { setTimeout(() => window.location.reload(), 500); return; }
    await loadAdminData();
}

async function deleteUser(userId) {
    if (!confirm('این کاربر حذف شود؟ این عمل قابل بازگشت نیست.')) return;
    const formData = new FormData();
    formData.append('user_id', userId);
    const res = await fetch('?action=admin_delete_user', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) { showToast(data.error); return; }
    showToast('کاربر حذف شد');
    await loadAdminData();
}

async function toggleRecording() {
    if (isRecording) {
        await stopRecording();
    } else {
        await startRecording();
    }
}

async function startRecording() {
    if (!currentChat) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showToast('مرورگر شما از ضبط صدا پشتیبانی نمی‌کند');
        return;
    }
    try {
        recordingStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (e) {
        showToast('دسترسی به میکروفون رد شد');
        return;
    }

    const mimes = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    let mime = '';
    for (const m of mimes) {
        if (MediaRecorder.isTypeSupported(m)) { mime = m; break; }
    }

    audioChunks = [];
    try {
        mediaRecorder = new MediaRecorder(recordingStream, mime ? { mimeType: mime } : {});
    } catch (e) {
        mediaRecorder = new MediaRecorder(recordingStream);
    }

    mediaRecorder.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) audioChunks.push(e.data);
    };

    mediaRecorder.onstop = () => {
        if (recordingStream) {
            recordingStream.getTracks().forEach(t => t.stop());
            recordingStream = null;
        }
    };

    mediaRecorder.start();
    isRecording = true;
    recordingStart = Date.now();

    document.getElementById('messageInput').style.display = 'none';
    document.getElementById('attachBtn').style.display = 'none';
    document.getElementById('sendBtn').style.display = 'none';
    document.getElementById('voiceBtn').classList.add('recording');
    document.getElementById('voiceBtn').innerHTML = '⏹';
    document.getElementById('recordingUI').classList.add('active');
    document.getElementById('recTime').textContent = '0:00';

    recordingInterval = setInterval(() => {
        const elapsed = Date.now() - recordingStart;
        document.getElementById('recTime').textContent = formatDuration(elapsed);
    }, 200);
}

async function stopRecording() {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') return;

    const duration = Date.now() - recordingStart;

    return new Promise((resolve) => {
        mediaRecorder.onstop = () => {
            if (recordingStream) {
                recordingStream.getTracks().forEach(t => t.stop());
                recordingStream = null;
            }
            clearInterval(recordingInterval);
            isRecording = false;

            document.getElementById('messageInput').style.display = '';
            document.getElementById('attachBtn').style.display = '';
            document.getElementById('sendBtn').style.display = '';
            document.getElementById('voiceBtn').classList.remove('recording');
            document.getElementById('voiceBtn').innerHTML = '🎤';
            document.getElementById('recordingUI').classList.remove('active');

            if (duration < 800) {
                showToast('ضبط خیلی کوتاه بود');
                resolve();
                return;
            }

            const mime = mediaRecorder.mimeType || 'audio/webm';
            const blob = new Blob(audioChunks, { type: mime });
            const ext = mime.includes('mp4') ? 'm4a' : (mime.includes('ogg') ? 'ogg' : 'webm');
            const fileName = `voice_${Date.now()}.${ext}`;
            const file = new File([blob], fileName, { type: mime });

            sendVoiceFile(file);
            resolve();
        };
        mediaRecorder.stop();
    });
}

function cancelRecording() {
    if (!mediaRecorder) return;
    if (mediaRecorder.state !== 'inactive') {
        mediaRecorder.onstop = () => {
            if (recordingStream) {
                recordingStream.getTracks().forEach(t => t.stop());
                recordingStream = null;
            }
            clearInterval(recordingInterval);
            isRecording = false;
            audioChunks = [];
            document.getElementById('messageInput').style.display = '';
            document.getElementById('attachBtn').style.display = '';
            document.getElementById('sendBtn').style.display = '';
            document.getElementById('voiceBtn').classList.remove('recording');
            document.getElementById('voiceBtn').innerHTML = '🎤';
            document.getElementById('recordingUI').classList.remove('active');
        };
        mediaRecorder.stop();
    }
    showToast('ضبط لغو شد');
}

async function sendVoiceFile(file) {
    if (!currentChat) return;
    const formData = new FormData();
    formData.append('chat_id', currentChat.id);
    formData.append('file', file);
    formData.append('caption', '🎙️ پیام صوتی');
    try {
        const res = await fetch('?action=send_message', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.error) { showToast(data.error); return; }
        loadChats();
        openChat(currentChat.id);
    } catch (e) { showToast('خطا در ارسال صدا'); }
}

const msgInput = document.getElementById('messageInput');
msgInput.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
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
    const attachBtn = document.getElementById('attachBtn');
    if (attachOpen && !attachPop.contains(e.target) && !attachBtn.contains(e.target)) closeAttach();
});

window.addEventListener('beforeunload', (e) => {
    if (isRecording) {
        e.preventDefault();
        e.returnValue = '';
    }
});

const urlParams = new URLSearchParams(window.location.search);
const joinParam = urlParams.get('join');

async function init() {
    initTheme();
    initNotifications();
    await loadChats();
    if (joinParam) {
        const chat = chats.find(c => c.public_id === joinParam);
        if (chat) openChat(chat.id);
    } else {
        const chatParam = urlParams.get('chat');
        if (chatParam) {
            const chat = chats.find(c => c.id === chatParam);
            if (chat) openChat(chat.id);
        }
    }
}

init();
</script>
</body>
</html>