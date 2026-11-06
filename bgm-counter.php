<?php
/* bgm-counter.php  BGMページ（bgm-n-amami.html）のアクセス数・曲ごとの再生回数
 *
 *  POST action=page              → ページアクセス +1（誰でも可）
 *  POST action=play&song=FILE    → 曲の再生回数 +1（FILE は hp-music/ 内に実在する音声ファイルのみ）
 *  POST action=dl&song=FILE      → 曲のダウンロード回数 +1（「保存」「現在聴いている曲をダウンロード」）
 *  GET  action=get&pw=PASSWORD   → 集計 JSON（管理者のみ。それ以外は {"admin":false} だけ）
 *
 *  管理者の判定（どちらか一方でOK）:
 *   1) WordPress に管理者（manage_options 権限）でログインしている
 *      → 同じドメインの WP ログインクッキーで自動判定。WP は「ログインクッキーがある時だけ」読み込む
 *        （一般の訪問者には WP を読み込まないので速い）。
 *   2) ?admin=パスワード（cell-click-counter.php と同じ。予備）
 *  保存先: bgm-counts.json（初回の書き込み時に自動作成）
 *  管理者のアクセス・再生も数える。
 *  連打対策: 同じ IP から ページ=10秒・同じ曲の再生/ダウンロード=各30秒 以内の重複は数えない。
 */
define('ADMIN_PW', 'amami-admin-2026');   // ★ cell-click-counter.php と同じ値にしてください
// WordPress の wp-load.php の場所。空なら下の候補を順に探す（Local・さくら とも amami-kanko の1つ上）
define('BGM_WP_LOAD', '');
define('BGM_FLOCK_TIMEOUT_SEC', 2.0);
define('BGM_PAGE_INTERVAL', 10);
define('BGM_PLAY_INTERVAL', 30);

ini_set('display_errors', '0');
error_reporting(E_ALL);
$filename = __DIR__ . '/bgm-counts.json';

function bgm_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function bgm_is_admin() {
    $pw = isset($_GET['pw']) ? $_GET['pw'] : (isset($_POST['pw']) ? $_POST['pw'] : '');
    if (is_string($pw) && $pw !== '' && hash_equals(ADMIN_PW, $pw)) return true;
    return bgm_wp_admin();
}
/* WordPress の管理者でログイン中か。ログインクッキーが無ければ WP を読み込まずに false */
$BGM_DIAG = ['cookie' => false, 'wp_found' => false, 'logged_in' => false, 'admin' => false];
function bgm_wp_admin() {
    global $BGM_DIAG;
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = false;
    $hasCookie = false;
    foreach ($_COOKIE as $k => $v) { if (strpos($k, 'wordpress_logged_in_') === 0) { $hasCookie = true; break; } }
    $BGM_DIAG['cookie'] = $hasCookie;
    if (!$hasCookie) return false;
    $cands = array_filter([BGM_WP_LOAD, dirname(__DIR__) . '/wp-load.php', $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php']);
    foreach ($cands as $f) {
        if ($f && is_file($f)) {
            try {
                ob_start();
                $BGM_DIAG['wp_found'] = true;
                if (!defined('ABSPATH')) require_once $f;
                ob_end_clean();
                $BGM_DIAG['logged_in'] = function_exists('is_user_logged_in') && is_user_logged_in();
                $cache = $BGM_DIAG['logged_in'] && current_user_can('manage_options');
                $BGM_DIAG['admin'] = $cache;
            } catch (Throwable $e) { if (ob_get_level()) ob_end_clean(); $cache = false; }
            break;
        }
    }
    return $cache;
}
function bgm_valid_song($s) {
    if (!is_string($s) || strlen($s) > 300) return false;
    if (!preg_match('#^hp-music/[^/\\\\]+\.(mp3|aac|m4a)$#iu', $s)) return false;
    if (strpos($s, '..') !== false) return false;
    return is_file(__DIR__ . '/' . $s);
}
function bgm_normalize($d) {
    if (!is_array($d)) $d = [];
    $d['page']  = isset($d['page']) ? (int)$d['page'] : 0;
    $d['songs'] = (isset($d['songs']) && is_array($d['songs'])) ? $d['songs'] : [];
    $d['downloads'] = (isset($d['downloads']) && is_array($d['downloads'])) ? $d['downloads'] : [];  // 無い古いデータでも OK
    $d['recent'] = (isset($d['recent']) && is_array($d['recent'])) ? $d['recent'] : [];
    return $d;
}
function bgm_try_flock($fp, $op) {
    $deadline = microtime(true) + BGM_FLOCK_TIMEOUT_SEC;
    while (microtime(true) < $deadline) {
        if (flock($fp, $op | LOCK_NB)) return true;
        usleep(50000);
    }
    return false;
}
function bgm_load($file) {
    $raw = @file_get_contents($file);
    return bgm_normalize($raw ? json_decode($raw, true) : []);
}
/* 排他ロックで読み→更新→書き。$fn が false を返したら書き込まない */
function bgm_update($file, $fn) {
    $fp = @fopen($file, 'c+');
    if (!$fp) return 'nofile';
    if (!bgm_try_flock($fp, LOCK_EX)) { fclose($fp); return 'busy'; }
    rewind($fp);
    $d = bgm_normalize(json_decode((string)stream_get_contents($fp), true));
    $r = $fn($d);
    if ($r !== false) {
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $r === false ? 'skip' : 'ok';
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'GET') {
    // 診断用（個人情報なし）: ?action=diag → WPログインが届いているかを true/false で表示
    if (isset($_GET['action']) && $_GET['action'] === 'diag') {
        bgm_wp_admin();
        bgm_json(['host' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '', 'wp_cookie_received' => $BGM_DIAG['cookie'], 'wp_load_found' => is_file(BGM_WP_LOAD ?: dirname(__DIR__) . '/wp-load.php') || is_file($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php'), 'wp_logged_in' => $BGM_DIAG['logged_in'], 'wp_admin' => $BGM_DIAG['admin']]);
    }
    if (!bgm_is_admin()) bgm_json(['admin' => false]);
    $d = bgm_load($filename);
    $songs = [];
    foreach ($d['songs'] as $k => $v) $songs[$k] = (int)$v;
    $dls = [];
    foreach ($d['downloads'] as $k => $v) $dls[$k] = (int)$v;
    bgm_json(['admin' => true, 'page' => $d['page'], 'songs' => (object)$songs, 'downloads' => (object)$dls, 'downloads_total' => array_sum($dls)]);
}

if ($method === 'POST') {
    // 管理者のアクセス・再生も一般の人と同じように数える（2026-10-27 変更）
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $song = '';
    if ($action === 'play' || $action === 'dl') {
        $song = isset($_POST['song']) ? (string)$_POST['song'] : '';
        if (!bgm_valid_song($song)) bgm_json(['ok' => false, 'error' => 'invalid song'], 400);
    } elseif ($action !== 'page') {
        bgm_json(['ok' => false, 'error' => 'invalid action'], 400);
    }
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $key = substr(hash('sha256', $ip . '|' . $action . '|' . $song), 0, 16);
    $now = time();
    $res = bgm_update($filename, function ($d) use ($action, $song, $key, $now) {
        foreach ($d['recent'] as $k => $t) {            // 古い連打記録は捨てる
            if ($now - (int)$t > BGM_PLAY_INTERVAL) unset($d['recent'][$k]);
        }
        $limit = $action === 'page' ? BGM_PAGE_INTERVAL : BGM_PLAY_INTERVAL;
        if (isset($d['recent'][$key]) && $now - (int)$d['recent'][$key] < $limit) return false;
        $d['recent'][$key] = $now;
        if ($action === 'page') $d['page']++;
        elseif ($action === 'dl') $d['downloads'][$song] = (isset($d['downloads'][$song]) ? (int)$d['downloads'][$song] : 0) + 1;
        else $d['songs'][$song] = (isset($d['songs'][$song]) ? (int)$d['songs'][$song] : 0) + 1;
        return $d;
    });
    if ($res === 'nofile') bgm_json(['ok' => false, 'error' => 'could not save (check folder permission)'], 500);
    bgm_json(['ok' => true, 'counted' => $res === 'ok']);
}
bgm_json(['error' => 'method_not_allowed'], 405);
