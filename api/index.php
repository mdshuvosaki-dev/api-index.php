<?php
// ============ কনফিগ ============
function envv($k, $d = '') { $v = getenv($k); return ($v !== false && $v !== '') ? $v : $d; }

define('BOT_TOKEN',   envv('BOT_TOKEN', '8909379894:AAHjGn3Km1yyIRl9l0zrN4AhFvMmFbCfXD4'));
define('FIRST_IMGBB', envv('IMGBB_API_KEY', 'adf843ba46ace623341b5b29dabdd06e'));
define('SECRET',      envv('SECRET', 'ImgHippoSecret2026x'));   // শুধু A-Z a-z 0-9 _ -
define('CHANNEL_URL', envv('CHANNEL_URL', 'https://t.me/telegram'));  // নিজের চ্যানেল লিংক দিন
define('PRIVACY_URL', envv('PRIVACY_URL', 'https://telegram.org/privacy'));
define('UP_URL',      envv('UPSTASH_REDIS_REST_URL', envv('KV_REST_API_URL')));
define('UP_TOKEN',    envv('UPSTASH_REDIS_REST_TOKEN', envv('KV_REST_API_TOKEN')));
const BASE_MB   = 5;
const REF_BONUS = 5;
const COOLDOWN  = 1800;
const ADMIN_IDS = [8918569615];   // অ্যাডমিন + আনলিমিটেড স্টোরেজ
const MB        = 1048576;
const LINE      = '━━━━━━━━━━━━━━━━━━';
const PROVIDERS = ['imgbb' => 'ImgBB', 'freeimage' => 'Freeimage.host', 'imghippo' => 'Imghippo'];

// ============ Redis (Upstash REST) ============
function R(...$cmd) {
    $ch = curl_init(UP_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . UP_TOKEN, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(array_map('strval', $cmd), JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($res ?: '{}', true);
    if (isset($j['error'])) throw new Exception($j['error']);
    return $j['result'] ?? null;
}

// ============ টেলিগ্রাম হেল্পার ============
function tg($method, $params = []) {
    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 50,
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
    return tg('editMessageText', $p);
}

function kb($rows) { return ['inline_keyboard' => $rows]; }
function b($text, $cb) { return ['text' => $text, 'callback_data' => $cb]; }
function bu($text, $url) { return ['text' => $text, 'url' => $url]; }
function bc($text, $copy) { return ['text' => $text, 'copy_text' => ['text' => $copy]]; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function isAdmin($uid) { return in_array((int)$uid, ADMIN_IDS, true); }

// ============ ইউজার ডেটা ============
function getUser($uid) {
    $r = R('HGETALL', "user:$uid") ?: [];
    $m = [];
    for ($i = 0; $i + 1 < count($r); $i += 2) $m[$r[$i]] = (int)$r[$i + 1];
    return [$m['used'] ?? 0, $m['uploads'] ?? 0, $m['referrals'] ?? 0];
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
function freeSpace($uid, $used, $refs) { return isAdmin($uid) ? 'Unlimited ♾' : fmtSize(max(limitBytes($refs) - $used, 0)); }

function register($uid, $ref = null) {
    if ((int)R('SADD', 'users', $uid) !== 1) return;   // আগে থেকেই আছে
    R('HSET', "user:$uid", 'used', 0, 'uploads', 0, 'referrals', 0);
    if ($ref && $ref != $uid && (int)R('SISMEMBER', 'users', $ref) === 1) {
        R('HSET', "user:$uid", 'referred_by', $ref);
        R('HINCRBY', "user:$ref", 'referrals', 1);
        R('INCRBY', 'stat:refs', 1);
    }
}

function maskKey($k) { return strlen($k) > 8 ? substr($k, 0, 4) . '…' . substr($k, -4) : '****'; }

function botUsername() {
    $v = R('GET', 'bot_username');
    if ($v) return $v;
    $r = tg('getMe');
    $v = $r['result']['username'] ?? '';
    if ($v) R('SET', 'bot_username', $v);
    return $v;
}

// ============ API তালিকা ============
function apisAll() {
    if (R('SETNX', 'apis:init', 1) == 1 && FIRST_IMGBB) {
        $id = (int)R('INCR', 'apis:next');
        R('HSET', 'apis', $id, json_encode(['provider' => 'imgbb', 'key' => FIRST_IMGBB, 'active' => 1, 'uses' => 0, 'fails' => 0, 'cd' => 0]));
    }
    $r = R('HGETALL', 'apis') ?: [];
    $out = [];
    for ($i = 0; $i + 1 < count($r); $i += 2) {
        $d = json_decode($r[$i + 1], true);
        $d['id'] = (int)$r[$i];
        $out[] = $d;
    }
    usort($out, fn($a, $c) => $a['id'] <=> $c['id']);
    return $out;
}

function apiSave($d) {
    $id = $d['id'];
    unset($d['id']);
    R('HSET', 'apis', $id, json_encode($d));
}

function apiAdd($prov, $key) {
    $id = (int)R('INCR', 'apis:next');
    apiSave(['id' => $id, 'provider' => $prov, 'key' => $key, 'active' => 1, 'uses' => 0, 'fails' => 0, 'cd' => 0]);
}

// ============ আপলোড (অটো ফেইলওভার) ============
function httpPost($url, $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => $fields,
        CURLOPT_TIMEOUT => 40, CURLOPT_FOLLOWLOCATION => true,
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
    $all = array_values(array_filter(apisAll(), fn($a) => $a['active']));
    $ready = array_values(array_filter($all, fn($a) => $a['cd'] <= time()));
    $list = $ready ?: $all;
    $fn = ['imgbb' => 'upImgbb', 'freeimage' => 'upFreeimage', 'imghippo' => 'upImghippo'];
    foreach ($list as $a) {
        try {
            $url = $fn[$a['provider']]($a['key'], $data);
            $a['uses']++;
            apiSave($a);
            return $url;
        } catch (Throwable $ex) {
            $a['fails']++;
            $a['cd'] = time() + COOLDOWN;
            apiSave($a);
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
        edit($chat, $mid, "☁️ <b>Uploading to server…</b>\n<code>▰▰▰▱</code>");
        $url = uploadAny($data);
    } catch (Throwable $ex) {
        error_log('upload failed: ' . $ex->getMessage());
        edit($chat, $mid, "❌ <b>Upload Failed</b>\n" . LINE . "\n\nServer is busy right now.\n<i>Please try again in a moment.</i>");
        return;
    }

    R('HINCRBY', "user:$uid", 'used', $size);
    R('HINCRBY', "user:$uid", 'uploads', 1);
    R('INCRBY', 'stat:used', $size);
    R('INCRBY', 'stat:uploads', 1);
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
    $users = (int)R('SCARD', 'users');
    $ups = (int)R('GET', 'stat:uploads');
    $apis = apisAll();
    $act = count(array_filter($apis, fn($a) => $a['active']));
    $text = "🛠 <b>Admin Control Panel</b>\n" . LINE . "\n\n"
        . "<blockquote>👥 Users: <b>$users</b>\n📤 Uploads: <b>$ups</b>\n🔑 APIs: <b>" . count($apis) . "</b> (🟢 $act active)</blockquote>";
    return [$text, kb([
        [b('➕ Add API', 'adm:add'), b('🔑 API List', 'adm:list')],
        [b('📊 Statistics', 'adm:stats')],
    ])];
}

function apiListView() {
    $back = kb([[b('⬅️ Back', 'adm:menu')]]);
    $apis = apisAll();
    if (!$apis) return ['🔑 কোনো API নেই। আগে ➕ Add API করুন।', $back];
    $lines = [];
    $k = [];
    foreach ($apis as $a) {
        $id = $a['id'];
        $state = !$a['active'] ? '⏸ Off' : ($a['cd'] > time() ? '😴 Cooldown' : '🟢 Active');
        $lines[] = "<b>#$id</b> " . (PROVIDERS[$a['provider']] ?? $a['provider']) . "  <code>" . maskKey($a['key']) . "</code>\n     $state  •  ✅ {$a['uses']}  •  ⚠️ {$a['fails']}";
        $k[] = [b(($a['active'] ? '⏸ Disable' : '▶️ Enable') . " #$id", "adm:tog:$id"), b("🗑 #$id", "adm:del:$id")];
    }
    $k[] = [b('⬅️ Back', 'adm:menu')];
    return ["🔑 <b>API List</b>\n" . LINE . "\n\n" . implode("\n\n", $lines), kb($k)];
}

function handleAdminCb($q) {
    $uid = $q['from']['id'];
    $chat = $q['message']['chat']['id'];
    $mid = $q['message']['message_id'];
    $parts = explode(':', $q['data']);
    $act = $parts[1] ?? '';

    if ($act === 'menu') {
        R('DEL', "state:$uid");
        [$t, $m] = adminHomeView();
        edit($chat, $mid, $t, $m);
    } elseif ($act === 'add') {
        $rows = [];
        foreach (PROVIDERS as $p => $n) $rows[] = [b($n, "adm:prov:$p")];
        $rows[] = [b('⬅️ Back', 'adm:menu')];
        edit($chat, $mid, "➕ <b>Add API</b>\n" . LINE . "\n\nকোন সাইটের API যোগ করবেন?", kb($rows));
    } elseif ($act === 'prov' && isset(PROVIDERS[$parts[2] ?? ''])) {
        R('SET', "state:$uid", $parts[2], 'EX', 600);
        edit($chat, $mid, "🔑 <b>" . PROVIDERS[$parts[2]] . "</b>\n" . LINE . "\n\nAPI key পাঠান।\n<i>সেভ হলে আপনার মেসেজ অটো ডিলিট হবে।</i>",
            kb([[b('❌ Cancel', 'adm:menu')]]));
    } elseif ($act === 'list') {
        [$t, $m] = apiListView();
        edit($chat, $mid, $t, $m);
    } elseif ($act === 'tog') {
        foreach (apisAll() as $a) {
            if ($a['id'] === (int)$parts[2]) { $a['active'] = $a['active'] ? 0 : 1; $a['cd'] = 0; apiSave($a); }
        }
        [$t, $m] = apiListView();
        edit($chat, $mid, $t, $m);
    } elseif ($act === 'del') {
        R('HDEL', 'apis', (int)$parts[2]);
        [$t, $m] = apiListView();
        edit($chat, $mid, $t, $m);
    } elseif ($act === 'stats') {
        $text = "📊 <b>Statistics</b>\n" . LINE . "\n\n<blockquote>👥 Users: <b>" . (int)R('SCARD', 'users')
            . "</b>\n📤 Uploads: <b>" . (int)R('GET', 'stat:uploads')
            . "</b>\n💾 Total used: <b>" . fmtSize((int)R('GET', 'stat:used'))
            . "</b>\n🎁 Referrals: <b>" . (int)R('GET', 'stat:refs') . "</b></blockquote>";
        edit($chat, $mid, $text, kb([[b('⬅️ Back', 'adm:menu')]]));
    }
}

// ============ আপডেট হ্যান্ডলার ============
function handleUpdate($u) {
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
        $prov = R('GET', "state:$uid");
        if ($prov && isset(PROVIDERS[$prov])) {
            $key = trim($text);
            apiAdd($prov, $key);
            R('DEL', "state:$uid");
            tg('deleteMessage', ['chat_id' => $chat, 'message_id' => $msg['message_id']]);
            [$t, $mk] = adminHomeView();
            send($chat, "✅ <b>" . PROVIDERS[$prov] . "</b> API যোগ হয়েছে: <code>" . maskKey($key) . "</code>\n\n$t", $mk);
            return;
        }
    }

    send($chat, invalidText());
}

// ============ এন্ট্রি পয়েন্ট ============
header('Content-Type: text/plain; charset=utf-8');

if (!UP_URL || !UP_TOKEN) {
    exit('Database env missing: UPSTASH_REDIS_REST_URL / UPSTASH_REDIS_REST_TOKEN (বা KV_REST_API_URL / KV_REST_API_TOKEN)');
}

// একবার ব্রাউজারে খুলুন: https://আপনার-প্রজেক্ট.vercel.app/?setup=SECRET
if (isset($_GET['setup'])) {
    if ($_GET['setup'] !== SECRET) { http_response_code(403); exit('forbidden'); }
    $url = 'https://' . $_SERVER['HTTP_HOST'] . '/';
    $r = tg('setWebhook', ['url' => $url, 'secret_token' => SECRET, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => true]);
    tg('setMyCommands', ['commands' => [['command' => 'start', 'description' => '🏠 Home']]]);
    foreach (ADMIN_IDS as $a) {
        tg('setMyCommands', ['commands' => [['command' => 'start', 'description' => '🏠 Home'], ['command' => 'admin', 'description' => '🛠 Admin Panel']],
            'scope' => ['type' => 'chat', 'chat_id' => $a]]);
    }
    exit("Webhook: $url\n" . json_encode($r, JSON_UNESCAPED_UNICODE));
}

if (($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== SECRET) {
    http_response_code(403);
    exit('Bot is running');
}

$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    try {
        // একই আপডেট দ্বিতীয়বার এলে বাদ
        if (R('SET', 'seen:' . ($update['update_id'] ?? 0), 1, 'NX', 'EX', 86400) !== null) {
            handleUpdate($update);
        }
    } catch (Throwable $ex) {
        error_log('Bot error: ' . $ex->getMessage());
    }
}
echo 'ok';
