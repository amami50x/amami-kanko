<?php
/**
 * link-status-check.php
 *
 * さくらレンタルサーバー上で動作する、テーブル内リンクの到達性チェッカー。
 * index.html 内の全 <table> から外部URLを抽出し、HTTPアクセスで生死を判定して
 * link-status.json に書き出す。ブラウザで本ファイルを開けば実行・進捗表示される。
 *
 * 使い方:
 *   1) ブラウザでアクセス
 *      https://violetfoal2.sakura.ne.jp/amami-kanko/link-status-check.php
 *   2) cron 定期実行（さくらのコントロールパネル → 「CRON設定」）
 *      curl -s https://violetfoal2.sakura.ne.jp/amami-kanko/link-status-check.php?cron=1 > /dev/null
 *
 * 出力:
 *   link-status.json （同じディレクトリに上書き）
 *
 * 仕様:
 *   - 対象URL = テーブルに登録された全URL。具体的には次の3つから抽出する:
 *       (1) index.html の <table> 内 <a href="...">（直リンク）
 *       (2) リンク一覧.txt（ポップアップ用。名前 = URL 形式）
 *       (3) extra-links.json（ポップアップ用。旧JSON形式の予備データ）
 *   - HEAD で確認、HEAD が拒否される場合は GET にフォールバック
 *   - 並列8本で HTTP リクエスト（curl_multi）
 *   - 200〜399 を OK、それ以外（4xx/5xx/timeout/DNS失敗）を NG
 *   - JSON のキーは登録URL文字列（JS 側の href と突き合わせるため）
 *
 * 【チェック範囲の方針】
 *   - 「テーブルに登録したURL自体」の生死だけを確認する。
 *   - 遷移先ページの中にある内部リンク（その先）はチェックしない。
 *     （市町村から受領したURLまでが管理範囲。範囲を広げすぎない方針。）
 *
 * NOTE: 装飾セル（.municipal-name / .amami-org-name / .island-title-row）も
 *       URL は抽出してチェックする。実際の着色対象から除外する制御は JS 側で行う。
 */

declare(strict_types=1);

// ---- 環境設定 ----------------------------------------------------------
@set_time_limit(0);
@ini_set('memory_limit', '256M');
mb_internal_encoding('UTF-8');

$IS_CRON = isset($_GET['cron']) && $_GET['cron'] === '1';

// 進捗を即時flushするための準備
if (!$IS_CRON) {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @ob_implicit_flush(true);
}

// ---- 定数 -------------------------------------------------------------
const HTML_FILE   = 'index.html';
const OUTPUT_FILE = 'link-status.json';
const USER_AGENT  =
    'Mozilla/5.0 (compatible; LinkStatusBot/1.0; '
  . '+https://violetfoal2.sakura.ne.jp/amami-kanko/) Chrome/124.0';
const HTTP_TIMEOUT     = 12;   // 各リクエストのタイムアウト秒
const PARALLEL         = 8;    // 並列数
const RETRY_ON_TIMEOUT = 1;    // タイムアウト時の追加リトライ回数

// 自動アクセスを拒否しやすいサイト。機械チェックだけで「リンク切れ確定」にしない。
const MANUAL_CONFIRM_HOSTS = [
    'tripadvisor.jp',
    'www.tripadvisor.jp',
    'instagram.com',
    'www.instagram.com',
    'x.com',
    'twitter.com',
    'www.twitter.com',
    'facebook.com',
    'www.facebook.com',
    'm.facebook.com',
];

function is_manual_confirm_result(string $url, int $status, ?string $reason): bool {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if ($status === 403 || $status === 429) return true;
    if (in_array($host, MANUAL_CONFIRM_HOSTS, true) && $status >= 400) return true;
    if ($status === 0 && $reason && stripos($reason, 'timeout') !== false) return true;
    return false;
}

// SNS・TripAdvisor 等は機械チェックでBOT拒否され誤検知になるため、自動チェック対象外。
function is_skip_host(string $url): bool {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    return in_array($host, MANUAL_CONFIRM_HOSTS, true);
}

// ---- 出力ユーティリティ -------------------------------------------------
function out(string $msg, bool $cron): void {
    if ($cron) {
        // cron 実行時はコンソール向けのプレーン出力（HTMLタグなし）
        echo strip_tags($msg) . "\n";
    } else {
        // 進行状況は今までどおり逐次表示する（止まって見えないように）。
        echo $msg . "\n";
        @flush();
    }
}

function html_header(bool $cron): void {
    if ($cron) {
        header('Content-Type: text/plain; charset=UTF-8');
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>テーブル内リンクチェック</title>
<style>
  body { font-family: -apple-system, "Segoe UI", sans-serif; padding: 16px; line-height: 1.55; color: #123760; }
  h1 { font-size: 1.2em; }
  pre { background: #f4f7fb; border: 1px solid #cfd8e3; border-radius: 6px;
        padding: 8px 12px; font-size: 12px; white-space: pre-wrap; word-break: break-all; }
  .ok { color: #1b6f3e; }
  .ng { color: #b6303a; font-weight: 700; }
  .summary { margin-top: 14px; padding: 10px 14px; border-radius: 8px;
             background: #fff9e6; border: 1px solid #e6c84a; }
  table.broken { border-collapse: collapse; margin-top: 10px; }
  table.broken th, table.broken td { border: 1px solid #cfd8e3; padding: 4px 8px; font-size: 13px; vertical-align: top; }
  table.broken th { background: #f0f5fa; }
</style>
</head>
<body>
<h1>テーブル内リンク 到達性チェック</h1>
<p>対象: index.html 内の全 <code>&lt;table&gt;</code> 配下の URL</p>
<div id="summary-top"></div>
<p id="runmsg" style="color:#9a6b00;background:#fff9e6;border:1px solid #e6c84a;border-radius:8px;padding:10px 14px;">チェック中です（進行状況は下に表示されます）。終わると結果がこの上に表示されます…</p>
<pre id="log">
HTML;
    @flush();
}

function html_footer(string $jsonFile, int $checked, array $broken, bool $cron, array $warnings = []): void {
    if ($cron) {
        echo "\nchecked={$checked}, broken=" . count($broken) . ", warnings=" . count($warnings) . ", out={$jsonFile}\n";
        return;
    }
    // 明細（実行ログ）の <pre> を閉じる
    echo "</pre>\n";
    // ここから結果サマリーを #result-summary にまとめて出力する。
    // 出力後に JavaScript で画面の先頭へ移動させる（明細より上に表示）。
    echo "<div id=\"result-summary\">\n";
    $brokenCount = count($broken);
    $warningCount = count($warnings);
    $cls = $brokenCount === 0 ? 'ok' : 'ng';
    echo "<div class=\"summary\">";
    echo "<strong>結果サマリー：</strong>"
       . "チェック {$checked} 件 ／ <span class=\"{$cls}\">要対応 {$brokenCount} 件</span>"
       . " ／ ブラウザ確認 {$warningCount} 件"
       . "／ 出力: <code>" . htmlspecialchars($jsonFile, ENT_QUOTES, 'UTF-8') . "</code>";
    echo "</div>\n";

    if ($brokenCount > 0) {
        echo "<h2 style=\"font-size:1.05em;margin-top:18px;\">要対応（登録URLの修正/削除候補）一覧</h2>";
        echo "<table class=\"broken\"><thead><tr>"
           . "<th>#</th><th>status</th><th>セルID</th><th>テキスト</th><th>URL</th><th>原因</th>"
           . "</tr></thead><tbody>";
        $i = 0;
        foreach ($broken as $href => $info) {
            $i++;
            $status = (int)($info['status'] ?? 0);
            $cell   = $info['cell']   ?? '';
            $label  = $info['label']  ?? '';
            $reason = $info['reason'] ?? '';
            echo '<tr>'
               . "<td>{$i}</td>"
               . '<td>' . htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') . '</td>'
               . '<td>' . htmlspecialchars($cell,   ENT_QUOTES, 'UTF-8') . '</td>'
               . '<td>' . htmlspecialchars($label,  ENT_QUOTES, 'UTF-8') . '</td>'
               . '<td><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">'
                 . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '</a></td>'
               . '<td>' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</td>'
               . '</tr>';
        }
        echo "</tbody></table>";
    }
    if ($warningCount > 0) {
        echo "<h2 style=\"font-size:1.05em;margin-top:18px;\">ブラウザ確認（機械判定ではNG・自動削除しない）一覧</h2>";
        echo "<table class=\"broken\"><thead><tr>"
           . "<th>#</th><th>status</th><th>セルID</th><th>テキスト</th><th>URL</th><th>原因</th>"
           . "</tr></thead><tbody>";
        $i = 0;
        foreach ($warnings as $href => $info) {
            $i++;
            $status = (int)($info['status'] ?? 0);
            $cell   = $info['cell']   ?? '';
            $label  = $info['label']  ?? '';
            $reason = $info['reason'] ?? '';
            echo '<tr>'
               . "<td>{$i}</td>"
               . '<td>' . htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') . '</td>'
               . '<td>' . htmlspecialchars($cell,   ENT_QUOTES, 'UTF-8') . '</td>'
               . '<td>' . htmlspecialchars($label,  ENT_QUOTES, 'UTF-8') . '</td>'
               . '<td><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">'
                 . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '</a></td>'
               . '<td>' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</td>'
               . '</tr>';
        }
        echo "</tbody></table>";
    }
    echo "<p style=\"margin-top:14px;\"><a href=\"admin-link-check.html\">→ リンク切れ一覧画面を表示</a></p>";
    echo "</div>\n"; // #result-summary 終わり

    // サマリーを画面の先頭（明細より上）へ移動し、上までスクロールする。
    echo "<script>(function(){"
       . "var s=document.getElementById('result-summary');"
       . "var a=document.getElementById('summary-top');"
       . "var r=document.getElementById('runmsg'); if(r){r.parentNode.removeChild(r);}"
       . "if(s&&a){a.parentNode.insertBefore(s,a);}"
       . "window.scrollTo(0,0);"
       . "})();</script>\n";
    echo "</body></html>";
}

// ---- HTML から URL を抽出 ----------------------------------------------
/**
 * @return array<string, array{url:string,label:string,cell:?string}>
 *         キー: 生の href（HTML上の表記）／値: 表示用メタ情報
 */
function extract_table_urls(string $htmlPath): array {
    $html = @file_get_contents($htmlPath);
    if ($html === false) {
        throw new RuntimeException("HTML を読み込めません: {$htmlPath}");
    }

    // DOMDocument で HTML5 を解析（warnings を抑制）
    $dom = new DOMDocument();
    $loadOk = @$dom->loadHTML(
        '<?xml encoding="UTF-8">' . $html,
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    if (!$loadOk) {
        throw new RuntimeException('HTML 解析に失敗しました');
    }

    $xpath = new DOMXPath($dom);
    // 全 <table> の中の <a href>
    $nodes = $xpath->query('//table//a[@href]');
    $map = [];
    foreach ($nodes as $a) {
        /** @var DOMElement $a */
        $hrefRaw = $a->getAttribute('href');
        $url = trim($hrefRaw);
        if ($url === '' || $url[0] === '#') continue;
        $lower = strtolower($url);
        foreach (['javascript:', 'mailto:', 'tel:', 'data:'] as $bad) {
            if (str_starts_with($lower, $bad)) continue 2;
        }
        // スキーム必須（外部URLのみ対象）
        if (!preg_match('#^https?://#i', $url)) continue;

        // セルIDの取得（最近接の祖先<td>の id 属性）
        $cellId = null;
        for ($p = $a->parentNode; $p && $p->nodeType === XML_ELEMENT_NODE; $p = $p->parentNode) {
            if ($p->nodeName === 'td') {
                $cellId = $p->getAttribute('id') ?: null;
                break;
            }
        }

        $label = trim(preg_replace('/\s+/u', ' ', $a->textContent ?? ''));
        if (mb_strlen($label) > 60) {
            $label = mb_substr($label, 0, 60) . '…';
        }

        if (!isset($map[$hrefRaw])) {
            $map[$hrefRaw] = [
                'url'    => $url,
                'label'  => $label,
                'cell'   => $cellId,
                'source' => 'table',
            ];
        }
    }
    return $map;
}

/**
 * リンク一覧.txt（ポップアップ用データ）から URL を抽出する。
 * 形式: 「名前 = URL」行。見出し [cell-XX-YY] / [SNS] / [島:名前] をラベル/セルに使う。
 * @return array<string,array{url:string,label:string,cell:?string}> URL をキーにした連想配列
 */
function extract_listtxt_urls(string $baseDir): array {
    $path = $baseDir . DIRECTORY_SEPARATOR . 'リンク一覧.txt';
    $text = @file_get_contents($path);
    if ($text === false || trim($text) === '') return [];

    $map = [];
    $current = '';
    $lines = preg_split('/\r\n|\r|\n/', $text);
    foreach ($lines as $rawLine) {
        // 全角スペースを半角に寄せて trim
        $line = trim(str_replace("\xE3\x80\x80", ' ', $rawLine));
        if ($line === '') continue;
        // コメント行（# または ＃）
        if ($line[0] === '#' || strncmp($line, "\xEF\xBC\x83", 3) === 0) continue;
        // 見出し行 [..] / ［..］
        if (preg_match('/^[\[\x{FF3B}]\s*(.+?)\s*[\]\x{FF3D}]\s*$/u', $line, $m)) {
            $current = trim($m[1]);
            continue;
        }
        // 「名前 = URL」（＝ も許容）
        $norm = str_replace('＝', '=', $line);
        $pos  = strpos($norm, '=');
        if ($pos !== false) {
            $name = trim(substr($norm, 0, $pos));
            $url  = trim(substr($norm, $pos + 1));
        } else {
            $name = '';
            $url  = trim($line);
        }
        if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
        $cell = preg_match('/^cell-\d\d-\d\d$/', $current) ? $current : null;
        if (!isset($map[$url])) {
            $map[$url] = [
                'url'    => $url,
                'label'  => ($name !== '' ? $name : $current),
                'cell'   => $cell,
                'source' => 'list',
            ];
        }
    }
    return $map;
}

/**
 * extra-links.json（ポップアップ用の予備データ）から URL を抽出する。
 * 形式: { "links": {cellId:[{名前,URL}...]}, "islands": {...}, "sns": [...] }
 * @return array<string,array{url:string,label:string,cell:?string}> URL をキーにした連想配列
 */
function extract_json_urls(string $baseDir): array {
    $path = $baseDir . DIRECTORY_SEPARATOR . 'extra-links.json';
    $raw  = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    $map = [];
    $collect = function($items, $cell) use (&$map) {
        if (!is_array($items)) return;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $url = '';
            foreach (['URL', 'url', 'Url'] as $k) {
                if (isset($it[$k]) && $it[$k] !== '') { $url = (string)$it[$k]; break; }
            }
            $name = '';
            foreach (['名前', 'name', '表示名', 'title'] as $k) {
                if (isset($it[$k]) && $it[$k] !== '') { $name = (string)$it[$k]; break; }
            }
            $url = trim($url);
            if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
            if (!isset($map[$url])) {
                $map[$url] = [
                    'url'    => $url,
                    'label'  => ($name !== '' ? $name : ($cell ?? '')),
                    'cell'   => $cell,
                    'source' => 'json',
                ];
            }
        }
    };

    if (isset($data['links']) && is_array($data['links'])) {
        foreach ($data['links'] as $cellKey => $items) {
            $cell = preg_match('/^cell-\d\d-\d\d$/', (string)$cellKey) ? (string)$cellKey : null;
            $collect($items, $cell);
        }
    }
    if (isset($data['islands']) && is_array($data['islands'])) {
        foreach ($data['islands'] as $islandKey => $items) {
            $collect($items, 'cell-island-' . $islandKey);
        }
    }
    if (isset($data['sns']) && is_array($data['sns'])) {
        $collect($data['sns'], null);
    }
    return $map;
}

/**
 * data/island_related_links.json（5島「関連サイト」の実データ）から URL を抽出する。
 * 形式: { "links": { islandKey: [ {label,url}... ] } }
 * @return array<string,array{url:string,label:string,cell:?string,source:string}>
 */
function extract_island_links(string $baseDir): array {
    $path = $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'island_related_links.json';
    $raw  = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['links']) || !is_array($data['links'])) return [];

    $map = [];
    foreach ($data['links'] as $islandKey => $items) {
        if (!is_array($items)) continue;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $url = trim((string)($it['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
            $name = trim((string)($it['label'] ?? ''));
            if (!isset($map[$url])) {
                $map[$url] = [
                    'url'    => $url,
                    'label'  => ($name !== '' ? $name : (string)$islandKey),
                    'cell'   => 'cell-island-' . $islandKey,
                    'source' => 'island',
                ];
            }
        }
    }
    return $map;
}

// ---- 1つの URL をチェック（curl ハンドル作成） ---------------------------
function build_curl(string $url, bool $useHead): \CurlHandle {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_NOBODY         => $useHead,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => HTTP_TIMEOUT,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ja,en;q=0.8',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        // GET 時はボディを全部受信せず途中で打ち切る
        CURLOPT_RANGE          => $useHead ? null : '0-2047',
    ]);
    return $ch;
}

/**
 * curl_multi で URL をまとめてチェック
 * @param string[] $urls
 * @return array<string, array{status:int,reason:?string,ok:bool}>
 */
function http_check_parallel(array $urls, bool $cron, callable $progress): array {
    $results = [];

    // 第1段：HEAD で確認
    $first = run_multi($urls, true);
    // HEAD で 4xx/5xx/0 が返ったものは GET で再試行
    $retryGet = [];
    foreach ($first as $url => $r) {
        $s = $r['status'];
        $needsRetry =
            $r['errno'] !== 0
            || $s === 0
            || $s === 400 || $s === 401 || $s === 403 || $s === 404
            || $s === 405 || $s === 406 || $s === 409 || $s === 410
            || $s === 429 || $s === 500 || $s === 501 || $s === 503;
        if ($needsRetry) {
            $retryGet[] = $url;
        }
    }
    $second = $retryGet ? run_multi($retryGet, false) : [];

    // タイムアウトで失敗したものはもう1度（RETRY_ON_TIMEOUT 回）
    $third = [];
    if (RETRY_ON_TIMEOUT > 0) {
        $retryTimeout = [];
        foreach ($urls as $u) {
            $r = $second[$u] ?? $first[$u] ?? null;
            if ($r && $r['errno'] !== 0 && stripos($r['error'] ?? '', 'time') !== false) {
                $retryTimeout[] = $u;
            }
        }
        if ($retryTimeout) {
            $third = run_multi($retryTimeout, false);
        }
    }

    // 結果のマージ
    $i = 0;
    foreach ($urls as $url) {
        $i++;
        if (preg_match('/\s/', $url)) {
            $results[$url] = ['status' => 0, 'reason' => 'URLに空白があります（登録文字を修正してください）', 'ok' => false];
            $progress($i, count($urls), $url, $results[$url]);
            continue;
        }
        $r = $third[$url] ?? $second[$url] ?? $first[$url] ?? null;
        if (!$r) {
            $results[$url] = ['status' => 0, 'reason' => 'no response', 'ok' => false];
        } else {
            $status = $r['status'];
            $reason = null;
            if ($r['errno'] !== 0) {
                $reason = $r['error'] ?: ('curl errno=' . $r['errno']);
            } elseif ($status >= 400) {
                $reason = 'HTTP ' . $status;
            }
            $ok = ($r['errno'] === 0 && $status >= 200 && $status < 400);
            $results[$url] = ['status' => $status, 'reason' => $reason, 'ok' => $ok];
        }
        $progress($i, count($urls), $url, $results[$url]);
    }
    return $results;
}

/**
 * @param string[] $urls
 * @return array<string, array{status:int,errno:int,error:string}>
 */
function run_multi(array $urls, bool $useHead): array {
    $results = [];
    $mh = curl_multi_init();
    $handles = []; // url => ch

    // 並列上限ずつ投入
    $chunks = array_chunk($urls, PARALLEL);
    foreach ($chunks as $chunk) {
        foreach ($chunk as $u) {
            $ch = build_curl($u, $useHead);
            curl_multi_add_handle($mh, $ch);
            $handles[$u] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($chunk as $u) {
            $ch = $handles[$u];
            $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = (int) curl_errno($ch);
            $err   = (string) curl_error($ch);
            $results[$u] = ['status' => $code, 'errno' => $errno, 'error' => $err];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$u]);
        }
    }
    curl_multi_close($mh);
    return $results;
}

// ---- メイン ------------------------------------------------------------
$baseDir = __DIR__;
$htmlPath = $baseDir . DIRECTORY_SEPARATOR . HTML_FILE;
$jsonPath = $baseDir . DIRECTORY_SEPARATOR . OUTPUT_FILE;

html_header($IS_CRON);

try {
    out("[1/3] 登録URL 解析: " . HTML_FILE . " + リンク一覧.txt + extra-links.json + 島の関連サイト", $IS_CRON);
    $hrefToMeta = extract_table_urls($htmlPath);
    $tableCount = count($hrefToMeta);

    // ポップアップ用に登録された URL も対象に加える（その先＝遷移先内部はチェックしない）
    $listCount = 0;
    foreach (extract_listtxt_urls($baseDir) as $u => $meta) {
        if (!isset($hrefToMeta[$u])) { $hrefToMeta[$u] = $meta; $listCount++; }
    }
    $jsonCount = 0;
    foreach (extract_json_urls($baseDir) as $u => $meta) {
        if (!isset($hrefToMeta[$u])) { $hrefToMeta[$u] = $meta; $jsonCount++; }
    }
    $islandCount = 0;
    foreach (extract_island_links($baseDir) as $u => $meta) {
        if (!isset($hrefToMeta[$u])) { $hrefToMeta[$u] = $meta; $islandCount++; }
    }

    $total = count($hrefToMeta);
    out("      表内リンク: {$tableCount} 件 / リンク一覧.txt 追加: {$listCount} 件 / extra-links.json 追加: {$jsonCount} 件 / 島の関連サイト 追加: {$islandCount} 件", $IS_CRON);
    out("      対象 URL 件数（重複除外後）: {$total}", $IS_CRON);

    if ($total === 0) {
        $payload = [
            'generatedAt' => date('c'),
            'source'      => HTML_FILE,
            'checked'     => 0,
            'brokenCount' => 0,
            'broken'      => (object)[],
            'warningCount' => 0,
            'warnings'     => (object)[],
            'skippedCount' => 0,
            'skipped'      => (object)[],
        ];
        @file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        html_footer(OUTPUT_FILE, 0, [], $IS_CRON);
        exit;
    }

    // 重複URLは1回しか叩かないようにユニーク化（SNS等は対象外）
    $urlsUnique = [];
    foreach ($hrefToMeta as $href => $meta) {
        if (is_skip_host($meta['url'])) continue;
        $urlsUnique[$meta['url']] = true;
    }
    $urlsUnique = array_keys($urlsUnique);

    out('[2/3] HTTP チェック開始 (parallel=' . PARALLEL . ', timeout=' . HTTP_TIMEOUT . 's)', $IS_CRON);
    $started = microtime(true);

    $resultsByUrl = http_check_parallel(
        $urlsUnique,
        $IS_CRON,
        function(int $i, int $n, string $url, array $res) use ($IS_CRON) {
            $status = (int)$res['status'];
            $ok = !empty($res['ok']);
            $mark = $ok ? '<span class="ok">OK</span>' : '<span class="ng">NG</span>';
            $line = sprintf(
                "  [%3d/%d] %s status=%-3d %s",
                $i, $n, $mark, $status, htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            );
            out($line, $IS_CRON);
        }
    );

    $elapsed = microtime(true) - $started;
    out(sprintf('      所要時間: %.1fs', $elapsed), $IS_CRON);

    // 結果を href ベースで再編成
    $broken = [];
    $warnings = [];
    $skipped = [];
    foreach ($hrefToMeta as $href => $meta) {
        // SNS・TripAdvisor 等は「参考（自動チェック対象外）」。数が実行環境でぶれないよう常に固定表示。
        if (is_skip_host($meta['url'])) {
            $skipped[$href] = [
                'status' => '-',
                'reason' => 'SNS等',
                'label'  => $meta['label']  ?? '',
                'cell'   => $meta['cell']   ?? null,
                'source' => $meta['source'] ?? '',
            ];
            continue;
        }
        $r = $resultsByUrl[$meta['url']] ?? null;
        if (!$r) continue;
        if (!$r['ok']) {
            $record = [
                'status' => (int) $r['status'],
                'reason' => $r['reason'] ?: ('HTTP ' . (int)$r['status']),
                'label'  => $meta['label']  ?? '',
                'cell'   => $meta['cell']   ?? null,
                'source' => $meta['source'] ?? '',
            ];
            if (is_manual_confirm_result($meta['url'], (int)$r['status'], $r['reason'] ?? null)) {
                $warnings[$href] = $record;
            } else {
                $broken[$href] = $record;
            }
        }
    }

    out('[3/3] 結果書き出し: ' . OUTPUT_FILE, $IS_CRON);
    $payload = [
        'generatedAt' => date('c'),
        'source'      => HTML_FILE,
        'checked'     => $total,
        'brokenCount' => count($broken),
        'broken'      => $broken ?: (object)[],
        'warningCount' => count($warnings),
        'warnings'     => $warnings ?: (object)[],
        'skippedCount' => count($skipped),
        'skipped'      => $skipped ?: (object)[],
    ];
    $ok = @file_put_contents(
        $jsonPath,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    if ($ok === false) {
        out('  ! 書き込み失敗: ' . OUTPUT_FILE . ' （パーミッションを確認してください）', $IS_CRON);
    } else {
        out('  完了。', $IS_CRON);
    }

    html_footer(OUTPUT_FILE, $total, $broken, $IS_CRON, $warnings);
} catch (Throwable $e) {
    out('ERROR: ' . $e->getMessage(), $IS_CRON);
    if (!$IS_CRON) {
        echo "</pre>";
        echo "<script>var _m=document.getElementById('runmsg'); if(_m){_m.parentNode.removeChild(_m);}</script>";
        echo "<p style=\"color:#b6303a;\">処理が中断されました。</p>";
        echo "</body></html>";
    }
}
