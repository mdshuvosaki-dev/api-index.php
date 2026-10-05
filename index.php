<?php
// ============ কনফিগ ============
const BOT_TOKEN   = '8909379894:AAHjGn3Km1yyIRl9l0zrN4AhFvMmFbCfXD4';
const FIRST_IMGBB = 'adf843ba46ace623341b5b29dabdd06e';
const SECRET      = 'ImgHippoSecret2026x';      // নিজের মতো বদলান (শুধু A-Z a-z 0-9 _ -)
const CHANNEL_URL = 'https://t.me/telegram';    // নিজের চ্যানেল লিংক দিন
const PRIVACY_URL = 'https://telegram.org/privacy';
const BASE_MB     = 5;
const REF_BONUS   = 5;
const COOLDOWN    = 1800;                       // ফেল করলে API ৩০ মিনিট বিশ্রামে
const ADMIN_IDS   = [8918569615];               // অ্যাডমিন + আনলিমিটেড স্টোরেজ
const MB          = 1048576;
const LINE        = '━━━━━━━━━━━━━━━━━━';
const PROVIDERS   = ['imgbb' => 'ImgBB', 'freeimage' => 'Freeimage.host', 'imghippo' => 'Imghippo'];

// ============ ডাটাবেস ============
$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
    file_put_contents("$dir/index.html", '');
}
$db = new PDO('sqlite:' . $dir . '/bot.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, used INTEGER DEFAULT 0, uploads INTEGER DEFAULT 0, referrals INTEGER DEFAULT 0, referred_by INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS apis(id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT, key TEXT, active INTEGER DEFAULT 1, uses INTEGER DEFAULT 0, fails INTEGER DEFAULT 0, cooldown_until REAL DEFAULT 0)");
$db->exec("CREATE TABLE IF NOT EXISTS state(uid INTEGER PRIMARY KEY, prov TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS kv(k TEXT PRIMARY KEY, v TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS seen(update_id INTEGER PRIMARY KEY, ts INTEGER)");
if (!$db->query("SELECT 1 FROM apis")->fetch()) {
    $db->prepare("INSERT INTO apis(provider,key) VALUES('imgbb',?)")->execute([FIRST_IMGBB]);
}

// ============ টেলিগ্রাম হেল্পার ============
function tg($method, $params = []) {
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true);
}

function send($chat, $text, $markup = null) {
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($markup) $p['reply_markup'] = $markup;
    return tg('sendMessage', $p);
}

function edit($chat, $mid, $text, $markup = null) {
    $p = ['chat_id' => $chat, 'message_id' => $mid, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($markup) $p['reply_markup'] = $markup;
    return tg('editMessageText', $p);   // "message is not modified" error নিজে থেকেই এড়ানো হয়
}

function kb($rows) { return ['inline_keyboard' => $rows]; }
function b($text, $cb) { return ['text' => $text, 'callback_data' => $cb]; }
function bu($text, $url) { return ['text' => $text, 'url' => $url]; }
function bc($text, $copy) { return ['text' => $text, 'copy_text' => ['text' => $copy]]; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function isAdmin($uid) { return in_array($uid, ADMIN_IDS); }

// ============ হেল্পার ============
function getUser($uid) {
    global $db;
    $s = $db->prepare("SELECT used,uploads,referrals FROM users WHERE id=?");
    $s->execute([$uid]);
    return $s->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
}

function limitBytes($refs) { return (BASE_MB + REF_BONUS * $refs) * MB; }

function fmtSize($b) { return $b < MB ? sprintf('%.1f KB', $b / 1024) : sprintf('%.2f MB', $b / MB); }

function storageLine($uid, $used, $refs) {
    if (isAdmin($uid)) return "▰▰▰▰▰▰▰▰▰▰ ♾\n" . fmtSize($used) . " / Unlimited";
    $lim = limitBytes($refs);
    $pct = min($used / $lim, 1);
    $f = (int)round($pct * 10);
    return str_repeat('▰', $f) . str_repeat('▱', 10 - $f) . ' ' . (int)($pct * 100) . "%\n" . fmtSize($used) . ' / ' . intdiv($lim, MB) . ' MB';
}

function plan($uid) { return isAdmin($uid) ? '💎 Premium Admin' : '🆓 Free Member'; }

function freeSpace($uid, $used, $refs) {
    return isAdmin($uid) ? 'Unlimited ♾' : fmtSize(max(limitBytes($refs) - $used, 0));
}

function register($uid, $ref = null) {
    global $db;
    $s = $db->prepare("SELECT 1 FROM users WHERE id=?");
    $s->execute([$uid]);
    if ($s->fetch()) return;
    $refOk = false;
    if ($ref && $ref != $uid) {
        $s = $db->prepare("SELECT 1 FROM users WHERE id=?");
        $s->execute([$ref]);
        $refOk = (bool)$s->fetch();
    }
    $db->prepare("INSERT INTO users(id,referred_by) VALUES(?,?)")->execute([$uid, $refOk ? $ref : null]);
    if ($refOk) $db->prepare("UPDATE users SET referrals=referrals+1 WHERE id=?")->execute([$ref]);
}

function maskKey($k) { return strlen($k) > 8 ? substr($k, 0, 4) . '…' . substr($k, -4) : '****'; }

function botUsername() {
    global $db;
    $s = $db->prepare("SELECT v FROM kv WHERE k='bot_username'");
    $s->execute();
    $v = $s->fetchColumn();
    if ($v) return $v;
    $r = tg('getMe');
    $v = $r['result']['username'] ?? '';
    if ($v) $db->prepare("INSERT OR REPLACE INTO kv(k,v) VALUES('bot_username',?)")->execute([$v]);
    return $v;
}

// ============ আপলোড (অটো ফেইলওভার) ============
function httpPost($url, $fields, $timeout = 60) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) throw new Exception("HTTP $code");
    return json_decode($res, true);
}

function upImgbb($key, $data) {
    $r = httpPost('https://api.imgbb.com/1/upload?key=' . urlencode($key), ['image' => base64_encode($data)]);
    return $r['data']['url'] ?? throw new Exception('no url');
}

function upFreeimage($key, $data) {
    $r = httpPost('https://freeimage.host/api/1/upload', ['key' => $key, 'source' => base64_encode($data), 'format' => 'json']);
    return $r['image']['url'] ?? throw new Exception('no url');
}

function upImghippo($key, $data) {
    $tmp = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($tmp, $data);
    try {
        $r = httpPost('https://api.imghippo.com/v1/upload', ['api_key' => $key, 'file' => new CURLFile($tmp, 'image/jpeg', 'image.jpg')]);
    } finally {
        @unlink($tmp);
    }
    return $r['data']['url'] ?? throw new Exception('no url');
}

function uploadAny($data) {
    global $db;
    $s = $db->prepare("SELECT id,provider,key FROM apis WHERE active=1 AND cooldown_until<=? ORDER BY id");
    $s->execute([time()]);
    $rows = $s->fetchAll(PDO::FETCH_NUM);
    if (!$rows) $rows = $db->query("SELECT id,provider,key FROM apis WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    $fn = ['imgbb' => 'upImgbb', 'freeimage' => 'upFreeimage', 'imghippo' => 'upImghippo'];
    foreach ($rows as [$id, $prov, $key]) {
        try {
            $url = $fn[$prov]($key, $data);
            $db->prepare("UPDATE apis SET uses=uses+1 WHERE id=?")->execute([$id]);
            return $url;
        } catch (Throwable $ex) {
            $db->prepare("UPDATE apis SET fails=fails+1, cooldown_until=? WHERE id=?")->execute([time() + COOLDOWN, $id]);
        }
    }
    throw new Exception('all APIs failed');
}

// ============ ভিউ ============
function homeView($uid, $name) {
    [$used, $uploads, $refs] = getUser($uid);
    $link = 'https://t.me/' . botUsername() . '?start=ref_' . $uid;
    $text = "✨ <b>ImgHippo Pro</b> ✨\n<i>Premium Image Hosting • Fast • Secure</i>\n" . LINE . "\n\n"
        . "👋 Hello, <b>" . e($name) . "</b>!\n\n"
        . "<blockquote>🆔 <b>ID:</b> <code>$uid</code>\n🏷 <b>Plan:</b> " . plan($uid) . "\n"
        . "📤 <b>Uploads:</b> $uploads\n👥 <b>Referrals:</b> $refs</blockquote>\n"
        . "💾 <b>Storage</b>\n<code>" . storageLine($uid, $used, $refs) . "</code>\n\n"
        . "🎁 Invite a friend → <b>+" . REF_BONUS . " MB</b> free storage\n\n"
        . "📸 <i>Send any photo to get your link instantly!</i>";
    $share = 'https://t.me/share/url?url=' . rawurlencode($link) . '&text=' . rawurlencode('Free image hosting bot 🚀');
    return [$text, kb([
        [bc('📋 Copy Referral Link', $link)],
        [b('👤 My Profile', 'profile'), bu('🚀 Share Bot', $share)],
        [bu('🔒 Privacy', PRIVACY_URL), bu('📢 Channel', CHANNEL_URL)],
    ])];
}

function profileView($uid, $fullName) {
    [$used, $uploads, $refs] = getUser($uid);
    $text = "👤 <b>My Profile</b>\n" . LINE . "\n\n"
        . "<blockquote>🆔 <b>ID:</b> <code>$uid</code>\n📛 <b>Name:</b> " . e($fullName) . "\n🏷 <b>Plan:</b> " . plan($uid) . "</blockquote>\n"
        . "💾 <b>Storage</b>\n<code>" . storageLine($uid, $used, $refs) . "</code>\n\n"
        . "🟢 <b>Free space:</b> " . freeSpace($uid, $used, $refs) . "\n"
        . "📤 <b>Total uploads:</b> $uploads\n"
        . "👥 <b>Referrals:</b> $refs  (+" . ($refs * REF_BONUS) . " MB earned)";
    return [$text, kb([[b('⬅️ Back', 'home')]])];
}

function invalidText() {
    return "🚫 <b>Unsupported Content</b>\n" . LINE . "\n\n"
        . "<blockquote>✅ Photos (JPG / PNG)\n✅ Image documents\n❌ Stickers, GIFs, videos\n❌ Plain text</blockquote>\n"
        . "📸 <i>Please send a high-quality photo to get your link!</i>";
}

// ============ ছবি আপলোড ============
function handleImage($msg) {
    global $db;
    $uid = $msg['from']['id'];
    $chat = $msg['chat']['id'];
    register($uid);

    if (!empty($msg['photo'])) {
        $f = end($msg['photo']);
        $fileId = $f['file_id'];
        $size = $f['file_size'] ?? 0;
        $fmt = 'JPG';
    } else {
        $f = $msg['document'];
        $fileId = $f['file_id'];
        $size = $f['file_size'] ?? 0;
        $fmt = ($f['mime_type'] ?? '') === 'image/png' ? 'PNG' : 'JPG';
    }

    [$used, $uploads, $refs] = getUser($uid);
    if (!isAdmin($uid) && $used + $size > limitBytes($refs)) {
        send($chat, "⚠️ <b>Storage Full</b>\n" . LINE . "\n\n<code>" . storageLine($uid, $used, $refs) . "</code>\n\n🎁 Invite friends to earn <b>+" . REF_BONUS . " MB</b> per referral.",
            kb([[b('🎁 Get Referral Link', 'home')]]));
        return;
    }

    $t0 = microtime(true);
    tg('sendChatAction', ['chat_id' => $chat, 'action' => 'upload_photo']);
    $st = send($chat, "📥 <b>Receiving image…</b>\n<code>▰▱▱▱</code>");
    $mid = $st['result']['message_id'] ?? null;

    try {
        $info = tg('getFile', ['file_id' => $fileId]);
        $path = $info['result']['file_path'] ?? throw new Exception('no file path');
        $data = file_get_contents('https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . $path);
        if ($data === false) throw new Exception('download failed');
        $size = strlen($data);
        $dim = getimagesizefromstring($data);
        $w = $dim[0] ?? 0;
        $h = $dim[1] ?? 0;
        edit($chat, $mid, "🔍 <b>Analyzing image…</b>\n<code>▰▰▱▱</code>");
        edit($chat, $mid, "☁️ <b>Uploading to server…</b>\n<code>▰▰▰▱</code>");
        $url = uploadAny($data);
    } catch (Throwable $ex) {
        edit($chat, $mid, "❌ <b>Upload Failed</b>\n" . LINE . "\n\nServer is busy right now.\n<i>Please try again in a moment.</i>");
        return;
    }

    $db->prepare("UPDATE users SET used=used+?, uploads=uploads+1 WHERE id=?")->execute([$size, $uid]);
    [$used, $uploads, $refs] = getUser($uid);
    $text = "✅ <b>Image Hosted Successfully!</b>\n" . LINE . "\n\n"
        . "<blockquote>📐 <b>Resolution:</b> $w × $h\n🖼 <b>Format:</b> $fmt\n📦 <b>Size:</b> " . fmtSize($size)
        . "\n⚡ <b>Time:</b> " . sprintf('%.1f', microtime(true) - $t0) . "s</blockquote>\n"
        . "🔗 <b>Your Link</b>\n<code>" . e($url) . "</code>\n\n"
        . "💾 Free space: <b>" . freeSpace($uid, $used, $refs) . "</b>  •  Upload <b>#$uploads</b>";
    edit($chat, $mid, $text, kb([
        [bc('📋 Copy Link', $url), bu('🌐 Open', $url)],
        [bu('📤 Share', 'https://t.me/share/url?url=' . rawurlencode($url)), b('🏠 Home', 'home')],
    ]));
}

// ============ অ্যাডমিন প্যানেল ============
function adminHomeView() {
    global $db;
    $u = $db->query("SELECT COUNT(*), COALESCE(SUM(uploads),0) FROM users")->fetch(PDO::FETCH_NUM);
    $a = $db->query("SELECT COUNT(*), COALESCE(SUM(active),0) FROM apis")->fetch(PDO::FETCH_NUM);
    $text = "🛠 <b>Admin Control Panel</b>\n" . LINE . "\n\n"
        . "<blockquote>👥 Users: <b>{$u[0]}</b>\n📤 Uploads: <b>{$u[1]}</b>\n🔑 APIs: <b>{$a[0]}</b> (🟢 {$a[1]} active)</blockquote>";
    return [$text, kb([
        [b('➕ Add API', 'adm:add'), b('🔑 API List', 'adm:list')],
        [b('📊 Statistics', 'adm:stats')],
    ])];
}

function apiListView() {
    global $db;
    $back = kb([[b('⬅️ Back', 'adm:menu')]]);
    $rows = $db->query("SELECT id,provider,key,active,uses,fails,cooldown_until FROM apis")->fetchAll(PDO::FETCH_NUM);
    if (!$rows) return ['🔑 কোনো API নেই। আগে ➕ Add API করুন।', $back];
    $lines = [];
    $k = [];
    foreach ($rows as [$id, $p, $key, $a, $u, $f, $cd]) {
        $state = !$a ? '⏸ Off' : ($cd > time() ? '😴 Cooldown' : '🟢 Active');
        $lines[] = "<b>#$id</b> " . (PROVIDERS[$p] ?? $p) . "  <code>" . maskKey($key) . "</code>\n     $state  •  ✅ $u  •  ⚠️ $f";
        $k[] = [b(($a ? '⏸ Disable' : '▶️ Enable') . " #$id", "adm:tog:$id"), b("🗑 #$id", "adm:del:$id")];
    }
    $k[] = [b('⬅️ Back', 'adm:menu')];
    return ["🔑 <b>API List</b>\n" . LINE . "\n\n" . implode("\n\n", $lines), kb($k)];
}

function handleAdminCb($q) {
    global $db;
    $uid = $q['from']['id'];
    $chat = $q['message']['chat']['id'];
    $mid = $q['message']['message_id'];
    $parts = explode(':', $q['data']);
    $act = $parts[1] ?? '';

    if ($act === 'menu') {
        $db->prepare("DELETE FROM state WHERE uid=?")->execute([$uid]);
        [$t, $m] = adminHomeView();
        edit($chat, $mid, $t, $m);
    } elseif ($act === 'add') {
        $rows = [];
        foreach (PROVIDERS as $p => $n) $rows[] = [b($n, "adm:prov:$p")];
        $rows[] = [b('⬅️ Back', 'adm:menu')];
        edit($chat, $mid, "➕ <b>Add API</b>\n" . LINE . "\n\nকোন সাইটের API যোগ করবেন?", kb($rows));
    } elseif ($act === 'prov' && isset(PROVIDERS[$parts[2] ?? ''])) {
        $db->prepare("INSERT OR REPLACE INTO state(uid,prov) VALUES(?,?)")->execute([$uid, $parts[2]]);
        edit($chat, $mid, "🔑 <b>" . PROVIDERS[$parts[2]] . "</b>\n" . LINE . "\n\nAPI key পাঠান।\n<i>সেভ হলে আপনার মেসেজ অটো ডিলিট হবে।</i>",
            kb([[b('❌ Cancel', 'adm:menu')]]));
    } elseif ($act === 'list') {
        [$t, $m] = apiListView();
        edit($chat, $mid, $t, $m);
    } elseif ($act === 'tog') {
        $db->prepare("UPDATE apis SET active=1-active, cooldown_until=0 WHERE id=?")->execute([(int)$parts[2]]);
        [$t, $m] = apiListView();
        edit($chat, $mid, $t, $m);
    } elseif ($act === 'del') {
        $db->prepare("DELETE FROM apis WHERE id=?")->execute([(int)$parts[2]]);
        [$t, $m] = apiListView();
        edit($chat, $mid, $t, $m);
    } elseif ($act === 'stats') {
        $u = $db->query("SELECT COUNT(*), COALESCE(SUM(uploads),0), COALESCE(SUM(used),0), COALESCE(SUM(referrals),0) FROM users")->fetch(PDO::FETCH_NUM);
        edit($chat, $mid, "📊 <b>Statistics</b>\n" . LINE . "\n\n<blockquote>👥 Users: <b>{$u[0]}</b>\n📤 Uploads: <b>{$u[1]}</b>\n💾 Total used: <b>" . fmtSize($u[2]) . "</b>\n🎁 Referrals: <b>{$u[3]}</b></blockquote>",
            kb([[b('⬅️ Back', 'adm:menu')]]));
    }
}

// ============ আপডেট হ্যান্ডলার ============
function handleUpdate($u) {
    global $db;

    if (isset($u['callback_query'])) {
        $q = $u['callback_query'];
        $uid = $q['from']['id'];
        $chat = $q['message']['chat']['id'];
        $mid = $q['message']['message_id'];
        $data = $q['data'] ?? '';
        if (strpos($data, 'adm:') === 0) {
            if (!isAdmin($uid)) {
                tg('answerCallbackQuery', ['callback_query_id' => $q['id'], 'text' => 'Not allowed', 'show_alert' => true]);
                return;
            }
            tg('answerCallbackQuery', ['callback_query_id' => $q['id']]);
            handleAdminCb($q);
            return;
        }
        tg('answerCallbackQuery', ['callback_query_id' => $q['id']]);
        register($uid);
        if ($data === 'home') {
            [$t, $m] = homeView($uid, $q['from']['first_name'] ?? 'User');
            edit($chat, $mid, $t, $m);
        } elseif ($data === 'profile') {
            [$t, $m] = profileView($uid, trim(($q['from']['first_name'] ?? '') . ' ' . ($q['from']['last_name'] ?? '')));
            edit($chat, $mid, $t, $m);
        }
        return;
    }

    if (!isset($u['message'])) return;
    $msg = $u['message'];
    $uid = $msg['from']['id'];
    $chat = $msg['chat']['id'];
    $text = $msg['text'] ?? '';

    if ($text !== '' && $text[0] === '/') {
        $cmd = strtolower(strtok($text, " @"));
        if ($cmd === '/start') {
            $ref = null;
            if (preg_match('/^\/start(?:@\w+)?\s+ref_(\d+)/', $text, $m)) $ref = (int)$m[1];
            register($uid, $ref);
            [$t, $mk] = homeView($uid, $msg['from']['first_name'] ?? 'User');
            send($chat, $t, $mk);
        } elseif ($cmd === '/admin' && isAdmin($uid)) {
            [$t, $mk] = adminHomeView();
            send($chat, $t, $mk);
        }
        return;
    }

    $isImg = !empty($msg['photo'])
        || (!empty($msg['document']) && in_array($msg['document']['mime_type'] ?? '', ['image/jpeg', 'image/png']));
    if ($isImg) {
        handleImage($msg);
        return;
    }

    if ($text !== '' && isAdmin($uid)) {
        $s = $db->prepare("SELECT prov FROM state WHERE uid=?");
        $s->execute([$uid]);
        $prov = $s->fetchColumn();
        if ($prov) {
            $key = trim($text);
            $db->prepare("INSERT INTO apis(provider,key) VALUES(?,?)")->execute([$prov, $key]);
            $db->prepare("DELETE FROM state WHERE uid=?")->execute([$uid]);
            tg('deleteMessage', ['chat_id' => $chat, 'message_id' => $msg['message_id']]);
            [$t, $mk] = adminHomeView();
            send($chat, "✅ <b>" . PROVIDERS[$prov] . "</b> API যোগ হয়েছে: <code>" . maskKey($key) . "</code>\n\n$t", $mk);
            return;
        }
    }

    send($chat, invalidText());
}

// ============ এন্ট্রি পয়েন্ট ============
// ১ বার ব্রাউজারে খুলুন: https://আপনার-ডোমেইন/bot.php?setup=SECRET
if (isset($_GET['setup'])) {
    if ($_GET['setup'] !== SECRET) { http_response_code(403); exit('forbidden'); }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
    $r = tg('setWebhook', ['url' => $url, 'secret_token' => SECRET, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => true]);
    tg('setMyCommands', ['commands' => [['command' => 'start', 'description' => '🏠 Home']]]);
    foreach (ADMIN_IDS as $a) {
        tg('setMyCommands', ['commands' => [['command' => 'start', 'description' => '🏠 Home'], ['command' => 'admin', 'description' => '🛠 Admin Panel']],
            'scope' => ['type' => 'chat', 'chat_id' => $a]]);
    }
    header('Content-Type: text/plain; charset=utf-8');
    exit("Webhook: $url\n" . json_encode($r, JSON_UNESCAPED_UNICODE));
}

if (($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== SECRET) {
    http_response_code(403);
    exit('forbidden');
}

$update = json_decode(file_get_contents('php://input'), true);
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
ignore_user_abort(true);
set_time_limit(120);

if (!$update) exit;
$s = $db->prepare("INSERT OR IGNORE INTO seen(update_id,ts) VALUES(?,?)");
$s->execute([$update['update_id'] ?? 0, time()]);
if ($s->rowCount() === 0) exit;                       // একই আপডেট দ্বিতীয়বার এলে বাদ
if (mt_rand(1, 50) === 1) $db->exec("DELETE FROM seen WHERE ts<" . (time() - 86400));

try {
    handleUpdate($update);
} catch (Throwable $ex) {
    error_log('Bot error: ' . $ex->getMessage());
}