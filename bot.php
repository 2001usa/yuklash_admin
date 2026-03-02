<?php
/**
 * bot.php — Admin Yordamchi Bot (PHP / Webhook)
 * 
 * Python (Aiogram) dan PHP ga qayta yozilgan versiya.
 * Barcha funksiyalar saqlanagan:
 *   /start  — Yangi kino qo'shish
 *   anketa  — nom → studiya → til → davlat → kanal link
 *   video   — Videolarni kanalga yuborish + bazaga saqlash
 *   /done   — Yuklashni tugatish
 *   /list   — Kinolar ro'yxati
 *   /movie_N — Kino tafsilotlari
 */

error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('display_errors', 0);

// ============================================================
// 1. .env KONFIGURATSIYA
// ============================================================
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            putenv("$key=$value");
        }
    }
}

$BOT_TOKEN    = getenv('BOT_TOKEN');
$ADMIN_ID     = (int) getenv('ADMIN_ID');
$SUPABASE_URL = getenv('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_KEY');
$CHANNEL_ID   = (int) getenv('CHANNEL_ID');

if (!$BOT_TOKEN) { http_response_code(500); exit('BOT_TOKEN topilmadi'); }

// ============================================================
// 2. HELPER FUNKSIYALAR
// ============================================================

/**
 * Telegram Bot API ga so'rov yuborish
 */
function bot($method, $data = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res);
}

/**
 * Oddiy xabar yuborish (HTML parse_mode)
 */
function sms($chat_id, $text, $reply_markup = null) {
    $data = [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($reply_markup) $data['reply_markup'] = $reply_markup;
    return bot('sendMessage', $data);
}

/**
 * Supabase REST API ga so'rov yuborish
 * @param string $method  GET, POST, DELETE, PATCH
 * @param string $path    Masalan: /rest/v1/movies
 * @param array  $body    POST/PATCH uchun JSON body
 * @param string $query   GET uchun query parametrlar (select, eq va hokazo)
 * @return object|null
 */
function supabase($method, $path, $body = null, $headers_extra = []) {
    global $SUPABASE_URL, $SUPABASE_KEY;
    $url = rtrim($SUPABASE_URL, '/') . $path;
    
    $headers = [
        "apikey: {$SUPABASE_KEY}",
        "Authorization: Bearer {$SUPABASE_KEY}",
        "Content-Type: application/json",
        "Prefer: return=representation",
    ];
    $headers = array_merge($headers, $headers_extra);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// --- Step (holat) boshqarish ---
$step_dir = __DIR__ . '/step';
if (!is_dir($step_dir)) @mkdir($step_dir);

function get_step($chat_id) {
    global $step_dir;
    $file = "{$step_dir}/{$chat_id}.json";
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

function set_step($chat_id, $step_name, $data = []) {
    global $step_dir;
    $file = "{$step_dir}/{$chat_id}.json";
    $content = array_merge(['step' => $step_name], $data);
    file_put_contents($file, json_encode($content, JSON_UNESCAPED_UNICODE));
}

function clear_step($chat_id) {
    global $step_dir;
    $file = "{$step_dir}/{$chat_id}.json";
    if (file_exists($file)) @unlink($file);
}

// ============================================================
// 3. TELEGRAM UPDATE O'QISH
// ============================================================
$update = json_decode(file_get_contents('php://input'));
if (!$update) exit();

$message = $update->message ?? null;

if (!$message) exit(); // Faqat message turini qabul qilamiz

$cid      = $message->chat->id ?? null;
$mid      = $message->message_id ?? null;
$text     = $message->text ?? null;
$uid      = $message->from->id ?? null;
$video    = $message->video ?? null;

if (!$cid) exit();

// ============================================================
// 4. ADMIN TEKSHIRISH
// ============================================================
if ($uid != $ADMIN_ID) {
    sms($cid, "⛔ Bu bot faqat admin uchun ishlaydi.");
    exit();
}

// Foydalanuvchi hozirgi holati
$state = get_step($cid);
$current_step = $state['step'] ?? null;

// ============================================================
// 5. HANDLERLAR
// ============================================================

// --- /start ---
if ($text === '/start') {
    clear_step($cid);
    sms($cid, "👋 Salom Admin! Yangi kino qo'shishni boshlaymizmi?\n\n🎬 Kino yoki Anime <b>nomini</b> kiriting:");
    set_step($cid, 'waiting_for_name');
    exit();
}

// --- /done ---
if ($text === '/done' && $current_step === 'uploading_videos') {
    $part_count = $state['part_count'] ?? 0;
    clear_step($cid);
    sms($cid, "✅ Barcha qismlar muvaffaqiyatli yuklandi! ({$part_count} ta qism)\n\nYangi kino uchun /start bosing.");
    exit();
}

// --- /list ---
if ($text === '/list') {
    clear_step($cid);
    
    $movies = supabase('GET', '/rest/v1/movies?select=id,name,studio,language,country&order=created_at.desc');
    
    if (empty($movies)) {
        sms($cid, "📭 Hozircha hech qanday kino yo'q.");
        exit();
    }
    
    $text_msg = "🎬 <b>Bazadagi kinolar:</b>\n\n";
    $movie_ids = [];
    
    foreach ($movies as $i => $movie) {
        $num = $i + 1;
        $movie_id = $movie['id'];
        $movie_ids[] = $movie_id;
        
        // Qismlar sonini hisoblash
        $parts = supabase('GET', "/rest/v1/movie_parts?select=id&movie_id=eq.{$movie_id}", null, ["Prefer: count=exact"]);
        $part_count = is_array($parts) ? count($parts) : 0;
        
        $text_msg .= "{$num}. <b>{$movie['name']}</b>\n";
        $text_msg .= "   🏢 {$movie['studio']} | 🌐 {$movie['language']} | 🌍 {$movie['country']}\n";
        $text_msg .= "   📹 Qismlar: {$part_count} ta\n";
        $text_msg .= "   👉 /movie_{$num}\n\n";
    }
    
    // Movie ID larni stateга saqlash
    set_step($cid, 'movie_list', ['movie_ids' => $movie_ids]);
    
    sms($cid, $text_msg);
    exit();
}

// --- /movie_N ---
if ($text && preg_match('/^\/movie_(\d+)$/', $text, $matches)) {
    $index = (int) $matches[1] - 1;
    
    $movie_ids = $state['movie_ids'] ?? [];
    
    if (empty($movie_ids) || $index < 0 || $index >= count($movie_ids)) {
        sms($cid, "❌ Avval /list buyrug'ini yuboring.");
        exit();
    }
    
    $movie_id = $movie_ids[$index];
    
    // Kino ma'lumotlari
    $movie_arr = supabase('GET', "/rest/v1/movies?id=eq.{$movie_id}&select=*");
    if (empty($movie_arr)) {
        sms($cid, "❌ Kino topilmadi.");
        exit();
    }
    $movie = $movie_arr[0];
    
    // Qismlar
    $parts = supabase('GET', "/rest/v1/movie_parts?movie_id=eq.{$movie_id}&select=part_number,message_id&order=part_number.asc");
    $parts_count = is_array($parts) ? count($parts) : 0;
    
    $text_msg = "🎬 <b>{$movie['name']}</b>\n"
              . "🏢 Studiya: {$movie['studio']}\n"
              . "🌐 Til: {$movie['language']}\n"
              . "🌍 Davlat: {$movie['country']}\n"
              . "🔗 Kanal: {$movie['channel_link']}\n\n"
              . "📹 <b>Qismlar ({$parts_count} ta):</b>\n";
    
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $text_msg .= "  {$part['part_number']}-qism | message_id: {$part['message_id']}\n";
        }
    }
    
    sms($cid, $text_msg);
    exit();
}

// ============================================================
// 6. FSM QADAMLARI (anketa)
// ============================================================

// --- Kino nomi ---
if ($current_step === 'waiting_for_name' && $text) {
    set_step($cid, 'waiting_for_studio', ['name' => $text]);
    sms($cid, "🏢 <b>Studiyani</b> kiriting:");
    exit();
}

// --- Studiya ---
if ($current_step === 'waiting_for_studio' && $text) {
    $data = $state;
    $data['studio'] = $text;
    set_step($cid, 'waiting_for_language', $data);
    sms($cid, "🌐 <b>Tilini</b> kiriting:");
    exit();
}

// --- Til ---
if ($current_step === 'waiting_for_language' && $text) {
    $data = $state;
    $data['language'] = $text;
    set_step($cid, 'waiting_for_country', $data);
    sms($cid, "🌍 <b>Davlatni</b> kiriting (yoki 'yo'q' deb yozing):");
    exit();
}

// --- Davlat ---
if ($current_step === 'waiting_for_country' && $text) {
    $data = $state;
    $data['country'] = $text;
    set_step($cid, 'waiting_for_channel_link', $data);
    sms($cid, "🔗 <b>Kanal linkini</b> kiriting:");
    exit();
}

// --- Kanal linki va bazaga saqlash ---
if ($current_step === 'waiting_for_channel_link' && $text) {
    $data = $state;
    $channel_link = $text;
    
    // Kinoni Supabase ga saqlash
    $movie_data = [
        'name'         => $data['name'],
        'studio'       => $data['studio'],
        'language'     => $data['language'],
        'country'      => $data['country'],
        'channel_link' => $channel_link,
    ];
    
    $response = supabase('POST', '/rest/v1/movies', $movie_data);
    
    if (empty($response) || !isset($response[0]['id'])) {
        sms($cid, "❌ Xatolik! Kinoni bazaga saqlashda muammo yuz berdi.\n\nQayta urinib ko'ring: /start");
        clear_step($cid);
        exit();
    }
    
    $movie_id = $response[0]['id'];
    
    set_step($cid, 'uploading_videos', [
        'movie_id'     => $movie_id,
        'name'         => $data['name'],
        'studio'       => $data['studio'],
        'language'     => $data['language'],
        'country'      => $data['country'],
        'channel_link' => $channel_link,
        'part_count'   => 0,
    ]);
    
    sms($cid, "✅ Kino bazaga saqlandi!\n\n📹 Endi videolarni (qismlarni) ketma-ket yuboring.\n\nTugatish uchun /done komandasini yuboring.");
    exit();
}

// --- Video qabul qilish ---
if ($current_step === 'uploading_videos' && $video) {
    $data = $state;
    $movie_id   = $data['movie_id'];
    $part_count = ($data['part_count'] ?? 0) + 1;
    
    // Caption tayyorlash
    $caption = "🎬 {$data['name']}\n"
             . "🏢 Studiya: {$data['studio']}\n"
             . "🌐 Til: {$data['language']}\n"
             . "🌍 Davlat: {$data['country']}\n"
             . "🔢 Qism: {$part_count}-qism\n\n"
             . "🔗 Kanal: {$data['channel_link']}";
    
    // Kanalga yuborish
    $sent = bot('sendVideo', [
        'chat_id' => $CHANNEL_ID,
        'video'   => $video->file_id,
        'caption' => $caption,
    ]);
    
    if (!isset($sent->result->message_id)) {
        sms($cid, "❌ Videoni kanalga yuborishda xatolik! Qayta yuboring.");
        exit();
    }
    
    $sent_message_id = $sent->result->message_id;
    
    // Bazaga qism saqlash
    $part_data = [
        'movie_id'    => $movie_id,
        'part_number' => $part_count,
        'message_id'  => $sent_message_id,
        'channel_id'  => $CHANNEL_ID,
    ];
    supabase('POST', '/rest/v1/movie_parts', $part_data);
    
    // Holatni yangilash
    $data['part_count'] = $part_count;
    set_step($cid, 'uploading_videos', $data);
    
    sms($cid, "✅ {$part_count}-qism kanalga joylandi va bazaga saqlandi.\n\nKeyingi videoni yuboring yoki /done bosing.");
    exit();
}

// Noma'lum xabar
if ($current_step === 'uploading_videos' && !$video && $text !== '/done') {
    sms($cid, "📹 Iltimos, <b>video</b> yuboring yoki tugatish uchun /done bosing.");
    exit();
}

// Umuman noma'lum holat
sms($cid, "🤖 Yangi kino qo'shish uchun /start\n📋 Kinolar ro'yxati uchun /list");
