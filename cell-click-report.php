<?php
/* cell-click-report.php
 * 管理者向け「興味分析」レポート。
 *   登録URL一覧（リンク一覧.txt ＞ extra-links.json）と
 *   実クリック数（cell-clicks.json）を突き合わせ、
 *   カテゴリ別・島/市町村別・URL別に集計して表示する。
 *   ※ クリック 0 件の登録URLも表示する（何に興味が無いかも分かる）。
 *
 * 使い方:  cell-click-report.php?pw=管理者パスワード
 * パスワードは cell-click-counter.php の ADMIN_PW と同じ値にしてください。
 */

define('ADMIN_PW', 'amami-admin-2026');  // ★ cell-click-counter.php と同じ値にする

ini_set('display_errors', 0);
error_reporting(E_ALL);
/* mbstring が無い環境でも動くよう、解析は ASCII 区切り（= [ ]）のみに依存する。 */

$dir        = __DIR__;
$clicksFile = $dir . '/cell-clicks.json';
$txtFile    = $dir . '/リンク一覧.txt';
$jsonFile   = $dir . '/extra-links.json';

function admin_ok() {
    $pw = isset($_GET['pw']) ? $_GET['pw'] : '';
    return is_string($pw) && hash_equals(ADMIN_PW, $pw);
}
$pw = isset($_GET['pw']) ? (string)$_GET['pw'] : '';

/* ---- 対応表（表ヘッダー・市町村・島） ---- */
$COL = ['01'=>'市町村名','02'=>'観光','03'=>'グルメ','04'=>'特産品','05'=>'郷土芸能','06'=>'歴史文化','07'=>'体験','08'=>'アクセス','09'=>'天気','10'=>'市町村旗歌','11'=>'基本情報','12'=>'イベントカレンダー','13'=>'移住・定住','14'=>'ふるさと納税'];
$ROW = ['01'=>'喜界町','02'=>'龍郷町','03'=>'奄美市','04'=>'大和村','05'=>'宇検村','06'=>'瀬戸内町','07'=>'徳之島町','08'=>'天城町','09'=>'伊仙町','10'=>'知名町','11'=>'和泊町','12'=>'与論町','13'=>'東京奄美会'];
$ISLAND = ['kikai'=>'喜界島','amami'=>'奄美大島','tokunoshima'=>'徳之島','okinoerabu'=>'沖永良部島','yoron'=>'与論島'];

/* セルID → [group(島/市町村), category(カテゴリ)] を求める */
function cell_meta($cell, $COL, $ROW, $ISLAND) {
    if (preg_match('/^cell-island-(.+)$/', $cell, $m)) {
        $key = $m[1];
        return [isset($ISLAND[$key]) ? $ISLAND[$key] : $key, '関連サイト'];
    }
    if (preg_match('/^cell-(\d\d)-(\d\d)$/', $cell, $m)) {
        $g = isset($ROW[$m[1]]) ? $ROW[$m[1]] : ('行' . $m[1]);
        $c = isset($COL[$m[2]]) ? $COL[$m[2]] : ('列' . $m[2]);
        return [$g, $c];
    }
    return ['その他', 'その他'];
}

/* リンク一覧.txt を解析（index.html の AmamiLinks と同じ形式） */
function parse_link_text($text) {
    $result = ['links'=>[], 'islands'=>[], 'sns'=>[]];
    $islandNameToKey = ['奄美大島'=>'amami','喜界島'=>'kikai','徳之島'=>'tokunoshima','沖永良部島'=>'okinoerabu','与論島'=>'yoron'];
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $current = null;
    foreach ($lines as $raw) {
        $line = trim(str_replace("\xE3\x80\x80", ' ', $raw)); // 全角空白→半角
        if ($line === '') continue;
        if (preg_match('/^(#|＃)/u', $line)) continue;
        if (preg_match('/^[\[\x{FF3B}]\s*(.+?)\s*[\]\x{FF3D}]\s*$/u', $line, $h)) {
            $key = trim($h[1]);
            if (preg_match('/^(sns|ｓｎｓ)$/iu', $key)) {
                $current = &$result['sns'];
            } elseif (preg_match('/^(?:島|island)\s*[:：\-－]\s*(.+)$/iu', $key, $im)) {
                $nm = trim($im[1]);
                $ik = isset($islandNameToKey[$nm]) ? $islandNameToKey[$nm] : $nm;
                if (!isset($result['islands'][$ik])) $result['islands'][$ik] = [];
                $current = &$result['islands'][$ik];
            } else {
                if (!isset($result['links'][$key])) $result['links'][$key] = [];
                $current = &$result['links'][$key];
            }
            continue;
        }
        if ($current === null) continue;
        $norm = preg_replace('/＝/u', '=', $line);
        $pos = strpos($norm, '=');  // '=' は ASCII。UTF-8 ではバイト位置で安全に分割できる
        if ($pos !== false) {
            $name = trim(substr($norm, 0, $pos));
            $url  = trim(substr($norm, $pos + 1));
        } else {
            $name = '';
            $url  = trim($line);
        }
        if ($url === '') continue;
        $current[] = ['名前'=>$name, 'URL'=>$url];
    }
    unset($current);
    return $result;
}

function reg_has_data($r) {
    return $r && (count($r['links']) || count($r['islands']) || count($r['sns']));
}
function item_url($it) {
    if (!is_array($it)) return '';
    foreach (['URL','url','ＵＲＬ'] as $k) if (isset($it[$k]) && $it[$k] !== '') return (string)$it[$k];
    return '';
}
function item_name($it) {
    if (!is_array($it)) return '';
    foreach (['名前','name','表示名'] as $k) if (isset($it[$k]) && $it[$k] !== '') return (string)$it[$k];
    return '';
}

/* ---- 登録データ読込（txt 優先・json 予備） ---- */
$reg = null;
if (is_file($txtFile)) {
    $t = file_get_contents($txtFile);
    if ($t !== false && trim($t) !== '') $reg = parse_link_text($t);
}
if (!reg_has_data($reg) && is_file($jsonFile)) {
    $d = json_decode(file_get_contents($jsonFile), true);
    if (is_array($d)) {
        $reg = [
            'links'   => isset($d['links']) && is_array($d['links']) ? $d['links'] : [],
            'islands' => isset($d['islands']) && is_array($d['islands']) ? $d['islands'] : [],
            'sns'     => isset($d['sns']) && is_array($d['sns']) ? $d['sns'] : [],
        ];
    }
}
if (!$reg) $reg = ['links'=>[], 'islands'=>[], 'sns'=>[]];

/* ---- クリック実績読込 ---- */
$byUrl = [];
if (is_file($clicksFile)) {
    $d = json_decode(file_get_contents($clicksFile), true);
    if (is_array($d) && isset($d['urls']) && is_array($d['urls'])) $byUrl = $d['urls'];
}
function counts_for($byUrl, $url) {
    if (isset($byUrl[$url]) && is_array($byUrl[$url])) {
        $r = $byUrl[$url];
        $ja = isset($r['ja']) ? (int)$r['ja'] : 0;
        $fo = isset($r['foreign']) ? (int)$r['foreign'] : 0;
        $to = isset($r['total']) ? (int)$r['total'] : ($ja + $fo);
        return [$ja, $fo, $to];
    }
    return [0, 0, 0];
}

/* ---- 登録URL × 実績 を結合 ---- */
$entries = [];
$seen = [];
$addEntry = function($cell, $label, $url) use (&$entries, &$seen, &$byUrl, $COL, $ROW, $ISLAND) {
    if ($url === '') return;
    list($group, $category) = cell_meta($cell, $COL, $ROW, $ISLAND);
    list($ja, $fo, $to) = counts_for($byUrl, $url);
    $entries[] = [
        'cell'=>$cell, 'group'=>$group, 'category'=>$category,
        'label'=>($label !== '' ? $label : $url), 'url'=>$url,
        'ja'=>$ja, 'foreign'=>$fo, 'total'=>$to, 'registered'=>true,
    ];
    $seen[$url] = true;
};

foreach ($reg['links'] as $cell => $items) {
    if (!is_array($items)) continue;
    foreach ($items as $it) $addEntry($cell, item_name($it), item_url($it));
}
foreach ($reg['islands'] as $key => $items) {
    if (!is_array($items)) continue;
    $cell = 'cell-island-' . $key;
    foreach ($items as $it) $addEntry($cell, item_name($it), item_url($it));
}
foreach ($reg['sns'] as $it) {
    $addEntry('cell-13-07', item_name($it), item_url($it));
}

/* クリックはされたが登録一覧に無いURL（古いリンク等）も拾う */
/* 登録一覧（リンク一覧.txt）に無いが正規に使っているURLの表示名（2026-11-05追加） */
function extra_registered_label($url, $cell, $COL, $ROW, $ISLAND) {
    if ($url === 'action:bgm-window') return '東京奄美会 BGM再生';
    if (strpos($url, 'action:song:') === 0) {
        list($group, $category) = cell_meta($cell, $COL, $ROW, $ISLAND);
        $name = ($group !== '' ? $group . ' ' : '') . '町歌（再生）';
        return $name;
    }
    $map = [
        'https://goontoamami.jp/yoron/'       => 'Go on to Amami（与論島）',
        'https://goontoamami.jp/kikai/'       => 'Go on to Amami（喜界島）',
        'https://goontoamami.jp/amamiisland/' => 'Go on to Amami（奄美大島）',
        'https://goontoamami.jp/tokunoshima/' => 'Go on to Amami（徳之島）',
        'http://www.tokunoshima-kanko.com/'   => '徳之島観光連盟',
        'https://www.tokunoshima-town.org/omotenashikanko/kanko/kankorenmei.html' => '徳之島町 観光連盟',
        'https://www.town.china.lg.jp/kikakushinkou/asobiniku/tokusanhin/index.html' => '知名町 特産品',
        'https://www.town.china.lg.jp/shougai/kurasu/kosodate-kyoiku/shogaigakushu/denshogeno/denshogeno.html' => '知名町 伝承芸能',
    ];
    return isset($map[$url]) ? $map[$url] : '';
}

foreach ($byUrl as $url => $rec) {
    if (isset($seen[$url])) continue;
    $cell = (is_array($rec) && isset($rec['cell'])) ? $rec['cell'] : '';
    list($group, $category) = cell_meta($cell, $COL, $ROW, $ISLAND);
    $ja = isset($rec['ja']) ? (int)$rec['ja'] : 0;
    $fo = isset($rec['foreign']) ? (int)$rec['foreign'] : 0;
    $to = isset($rec['total']) ? (int)$rec['total'] : ($ja + $fo);
    $xl = extra_registered_label($url, $cell, $COL, $ROW, $ISLAND);
    $entries[] = [
        'cell'=>$cell, 'group'=>$group, 'category'=>$category,
        'label'=>($xl !== '' ? $xl : (is_array($rec) && isset($rec['label']) && $rec['label'] !== '' ? $rec['label'] : $url)),
        'url'=>$url, 'ja'=>$ja, 'foreign'=>$fo, 'total'=>$to, 'registered'=>($xl !== ''),
    ];
}

/* ---- 集計 ---- */
$totAll = 0; $totJa = 0; $totFo = 0; $clickedUrls = 0;
$catTotals = []; $groupTotals = [];
foreach ($entries as $e) {
    $totAll += $e['total']; $totJa += $e['ja']; $totFo += $e['foreign'];
    if ($e['total'] > 0) $clickedUrls++;
    $catTotals[$e['category']]   = (isset($catTotals[$e['category']]) ? $catTotals[$e['category']] : 0) + $e['total'];
    $groupTotals[$e['group']]    = (isset($groupTotals[$e['group']]) ? $groupTotals[$e['group']] : 0) + $e['total'];
}
arsort($catTotals);
arsort($groupTotals);
// URL一覧はクリック数の多い順（0件は最後に登録順で）
usort($entries, function($a, $b) {
    if ($b['total'] !== $a['total']) return $b['total'] - $a['total'];
    return strcmp($a['group'].$a['category'].$a['label'], $b['group'].$b['category'].$b['label']);
});
$regCount = 0;
foreach ($entries as $e) if ($e['registered']) $regCount++;

/* ---- スナップショット（現在値）をJSへ埋め込み、ダウンロード/比較に使う ---- */
$snapUrls = [];
foreach ($entries as $e) {
    $snapUrls[$e['url']] = [
        'label'    => $e['label'],
        'group'    => $e['group'],
        'category' => $e['category'],
        'ja'       => $e['ja'],
        'foreign'  => $e['foreign'],
        'total'    => $e['total'],
    ];
}
$snapshot = [
    'type'         => 'amami-click-snapshot',
    'generated_at' => date('c'),
    'total'        => $totAll,
    'total_ja'     => $totJa,
    'total_foreign'=> $totFo,
    'urls'         => $snapUrls,
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pwEnc = rawurlencode($pw);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>クリック分析レポート｜奄美 旅行情報</title>
<style>
  :root { --line:#e3e3e3; --ink:#222; --muted:#777; --accent:#800080; --bar:#6ec3f7; }
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif; color: var(--ink); margin: 0; background: #fafafa; }
  .wrap { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; }
  h1 { font-size: 1.3rem; margin: 0 0 4px; }
  .sub { color: var(--muted); font-size: .85rem; margin-bottom: 18px; }
  .cards { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 22px; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 12px 16px; min-width: 150px; flex: 1; }
  .card .n { font-size: 1.5rem; font-weight: 700; }
  .card .l { color: var(--muted); font-size: .8rem; }
  h2 { font-size: 1.05rem; margin: 26px 0 10px; border-left: 4px solid var(--accent); padding-left: 8px; }
  table { width: 100%; border-collapse: collapse; background: #fff; font-size: .88rem; }
  th, td { border: 1px solid var(--line); padding: 6px 8px; text-align: left; vertical-align: top; }
  th { background: #f1eef5; cursor: pointer; white-space: nowrap; }
  td.num, th.num { text-align: right; white-space: nowrap; }
  tr.zero td { color: #aaa; }
  .barrow { display: grid; grid-template-columns: 130px 1fr 60px; align-items: center; gap: 8px; margin: 3px 0; }
  .barrow .name { font-size: .85rem; }
  .bar { background: var(--bar); height: 16px; border-radius: 3px; min-width: 2px; }
  .barrow .v { text-align: right; font-variant-numeric: tabular-nums; font-size: .85rem; }
  .url { color: #1b5eb8; word-break: break-all; text-decoration: none; }
  .url:hover { text-decoration: underline; }
  .tag { display: inline-block; font-size: .72rem; background: #eee; color: #555; border-radius: 4px; padding: 1px 6px; }
  .tag.new { background: #fff2cc; color: #8a6d00; }
  .tools { margin: 6px 0 0; }
  .tools a { display: inline-block; margin-right: 10px; font-size: .85rem; color: var(--accent); }
  .snap { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; margin: 6px 0 4px; }
  .snap .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .snap button, .snap .filebtn { font-size: .85rem; padding: 7px 14px; border: 1px solid var(--accent); background: var(--accent); color: #fff; border-radius: 6px; cursor: pointer; }
  .snap .filebtn { background: #fff; color: var(--accent); }
  .snap input[type=file] { display: none; }
  .snap .hint { color: var(--muted); font-size: .8rem; margin: 8px 0 0; }
  .diff-summary { display: flex; flex-wrap: wrap; gap: 10px; margin: 12px 0; }
  .diff-summary .card .n.up { color: #1a7f37; }
  .diff-summary .card .n.down { color: #c00; }
  td.up, td.num.up { color: #1a7f37; font-weight: 700; }
  td.down, td.num.down { color: #c00; font-weight: 700; }
  tr.new-url td { background: #fff8e6; }
  .meta { color: var(--muted); font-size: .8rem; margin: 6px 0; }
  form.login { max-width: 360px; margin: 80px auto; background: #fff; padding: 24px; border: 1px solid var(--line); border-radius: 10px; }
  form.login input[type=password] { width: 100%; padding: 8px; margin: 8px 0 14px; }
  form.login button { padding: 8px 16px; }
  .err { color: #c00; font-size: .85rem; }
</style>
</head>
<body>
<div class="wrap">
<?php if (!admin_ok()): ?>
  <form class="login" method="get" action="cell-click-report.php">
    <h1>クリック分析レポート</h1>
    <p class="sub">管理者パスワードを入力してください。</p>
    <?php if ($pw !== ''): ?><p class="err">パスワードが違います。</p><?php endif; ?>
    <input type="password" name="pw" placeholder="管理者パスワード" autofocus>
    <button type="submit">表示</button>
  </form>
</div></body></html>
<?php exit; endif; ?>

  <h1>クリック分析レポート <span class="sub">― ユーザーが興味を持った旅行情報</span></h1>
  <p class="sub">登録URL（リンク一覧）と実際のクリック数を突き合わせています。クリック0件の登録URLも含みます。集計対象は「実際にURLへ遷移したクリック」のみ（ポップアップを開く操作は除外）。</p>

  <div class="cards">
    <div class="card"><div class="n"><?php echo number_format($totAll); ?></div><div class="l">総クリック数</div></div>
    <div class="card"><div class="n"><?php echo number_format($totJa); ?></div><div class="l">日本語</div></div>
    <div class="card"><div class="n"><?php echo number_format($totFo); ?></div><div class="l">外国語</div></div>
    <div class="card"><div class="n"><?php echo number_format($clickedUrls); ?> / <?php echo number_format($regCount); ?></div><div class="l">クリックされたURL / 登録URL</div></div>
  </div>

  <div class="tools">
    <a href="cell-click-counter.php?action=urls&amp;pw=<?php echo $pwEnc; ?>">URL別CSVをダウンロード</a>
    <a href="cell-click-counter.php?action=report&amp;pw=<?php echo $pwEnc; ?>">月別CSVをダウンロード</a>
  </div>

  <h2>スナップショット比較（前回との差分チェック）</h2>
  <div class="snap">
    <div class="row">
      <button type="button" id="snapSave">① 今の数字を保存（スナップショット）</button>
      <label class="filebtn">② 前回の保存ファイルを選んで比較
        <input type="file" id="snapFile" accept="application/json,.json">
      </label>
      <button type="button" id="snapClear" style="display:none;background:#fff;color:#777;border-color:#ccc;">比較をクリア</button>
    </div>
    <p class="hint">
      使い方：チェックのたびに①でファイル（amami-snapshot-日付.json）を保存しておきます。次回は②で前回のファイルを選ぶと、URLごとに「前回→今回」の増減が表示されます。<br>
      自分でクリックした回数どおりに増えていれば正常です。サーバーには何も書き込みません（読み取りのみ）。
    </p>
    <div id="snapResult"></div>
  </div>

  <h2>カテゴリ別の関心（クリック合計）</h2>
  <?php $maxCat = $catTotals ? max($catTotals) : 0; ?>
  <?php if (!$catTotals): ?><p class="sub">データがありません。</p><?php endif; ?>
  <?php foreach ($catTotals as $cat => $v): if ($v <= 0 && $maxCat > 0) continue; ?>
    <div class="barrow">
      <span class="name"><?php echo h($cat); ?></span>
      <span class="bar" style="width: <?php echo $maxCat ? max(2, round($v / $maxCat * 100)) : 2; ?>%"></span>
      <span class="v"><?php echo number_format($v); ?></span>
    </div>
  <?php endforeach; ?>

  <h2>島・市町村別の関心（クリック合計）</h2>
  <?php $maxGrp = $groupTotals ? max($groupTotals) : 0; ?>
  <?php foreach ($groupTotals as $grp => $v): if ($v <= 0 && $maxGrp > 0) continue; ?>
    <div class="barrow">
      <span class="name"><?php echo h($grp); ?></span>
      <span class="bar" style="width: <?php echo $maxGrp ? max(2, round($v / $maxGrp * 100)) : 2; ?>%"></span>
      <span class="v"><?php echo number_format($v); ?></span>
    </div>
  <?php endforeach; ?>

  <h2>URL別 詳細（クリック多い順 / 0件含む）</h2>
  <table id="urlTable">
    <thead>
      <tr>
        <th data-k="group">島・市町村</th>
        <th data-k="category">カテゴリ</th>
        <th data-k="label">リンク名</th>
        <th data-k="url">URL</th>
        <th class="num" data-k="ja">日本語</th>
        <th class="num" data-k="foreign">外国語</th>
        <th class="num" data-k="total">合計</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($entries as $e): ?>
      <tr class="<?php echo $e['total'] === 0 ? 'zero' : ''; ?>">
        <td><?php echo h($e['group']); ?></td>
        <td><?php echo h($e['category']); ?></td>
        <td><?php echo h($e['label']); ?><?php if (!$e['registered']): ?> <span class="tag new">未登録</span><?php endif; ?></td>
        <td><a class="url" href="<?php echo h($e['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo h($e['url']); ?></a></td>
        <td class="num"><?php echo number_format($e['ja']); ?></td>
        <td class="num"><?php echo number_format($e['foreign']); ?></td>
        <td class="num"><?php echo number_format($e['total']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

<script>
/* ===== スナップショット保存 & 比較 ===== */
(function () {
  var CURRENT = <?php echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var saveBtn = document.getElementById('snapSave');
  var fileInp = document.getElementById('snapFile');
  var clearBtn = document.getElementById('snapClear');
  var result = document.getElementById('snapResult');
  if (!saveBtn || !fileInp || !result) return;

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function stamp() {
    var d = new Date();
    return d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes());
  }
  function fmt(n) { return (n || 0).toLocaleString('ja-JP'); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  saveBtn.addEventListener('click', function () {
    var blob = new Blob([JSON.stringify(CURRENT, null, 2)], { type: 'application/json' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'amami-snapshot-' + stamp() + '.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  });

  fileInp.addEventListener('change', function () {
    var f = fileInp.files && fileInp.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function () {
      var prev;
      try { prev = JSON.parse(reader.result); } catch (e) { prev = null; }
      if (!prev || prev.type !== 'amami-click-snapshot' || !prev.urls) {
        result.innerHTML = '<p class="err">このファイルはスナップショットではありません。①で保存したJSONを選んでください。</p>';
        return;
      }
      renderDiff(prev);
    };
    reader.readAsText(f);
  });

  clearBtn.addEventListener('click', function () {
    result.innerHTML = '';
    clearBtn.style.display = 'none';
    fileInp.value = '';
  });

  function renderDiff(prev) {
    var prevUrls = prev.urls || {};
    var curUrls = CURRENT.urls || {};
    var keys = {};
    Object.keys(prevUrls).forEach(function (k) { keys[k] = 1; });
    Object.keys(curUrls).forEach(function (k) { keys[k] = 1; });

    var rows = [];
    var sumPrev = 0, sumCur = 0, changed = 0, increased = 0, decreased = 0;
    Object.keys(keys).forEach(function (url) {
      var p = prevUrls[url] || { total: 0, ja: 0, foreign: 0 };
      var c = curUrls[url] || { total: 0, ja: 0, foreign: 0, label: (p.label || url), group: (p.group || ''), category: (p.category || '') };
      var pt = p.total || 0, ct = c.total || 0, d = ct - pt;
      sumPrev += pt; sumCur += ct;
      if (d !== 0) { changed++; if (d > 0) increased++; else decreased++; }
      rows.push({
        url: url,
        label: c.label || p.label || url,
        group: c.group || p.group || '',
        category: c.category || p.category || '',
        prev: pt, cur: ct, diff: d,
        isNew: !(url in prevUrls),
        gone: !(url in curUrls)
      });
    });

    rows.sort(function (a, b) {
      if (b.diff !== a.diff) return b.diff - a.diff;
      return b.cur - a.cur;
    });

    var when = prev.generated_at ? new Date(prev.generated_at) : null;
    var whenStr = when && !isNaN(when) ? when.toLocaleString('ja-JP') : '(不明)';
    var totalDiff = sumCur - sumPrev;

    var html = '';
    html += '<p class="meta">前回保存日時：' + esc(whenStr) + '</p>';
    html += '<div class="diff-summary">';
    html += '<div class="card"><div class="n">' + fmt(sumPrev) + '</div><div class="l">前回 合計</div></div>';
    html += '<div class="card"><div class="n">' + fmt(sumCur) + '</div><div class="l">今回 合計</div></div>';
    html += '<div class="card"><div class="n ' + (totalDiff > 0 ? 'up' : (totalDiff < 0 ? 'down' : '')) + '">' + (totalDiff > 0 ? '+' : '') + fmt(totalDiff) + '</div><div class="l">増減（今回−前回）</div></div>';
    html += '<div class="card"><div class="n">' + fmt(increased) + ' / ' + fmt(decreased) + '</div><div class="l">増えたURL / 減ったURL</div></div>';
    html += '</div>';

    if (decreased > 0) {
      html += '<p class="err">⚠ クリック数が減っているURLがあります（' + fmt(decreased) + '件）。通常クリック数は減りません。データ消失や別ファイルとの比較の可能性があります。</p>';
    }

    html += '<table><thead><tr>'
         + '<th>島・市町村</th><th>カテゴリ</th><th>リンク名</th>'
         + '<th class="num">前回</th><th class="num">今回</th><th class="num">増減</th>'
         + '</tr></thead><tbody>';
    rows.forEach(function (r) {
      if (r.diff === 0 && r.cur === 0) return; // 双方0は省略
      var cls = r.isNew ? ' class="new-url"' : '';
      var dcls = r.diff > 0 ? ' up' : (r.diff < 0 ? ' down' : '');
      var dtxt = (r.diff > 0 ? '+' : '') + fmt(r.diff);
      html += '<tr' + cls + '>'
           + '<td>' + esc(r.group) + '</td>'
           + '<td>' + esc(r.category) + '</td>'
           + '<td>' + esc(r.label) + (r.isNew ? ' <span class="tag new">新規</span>' : '') + '</td>'
           + '<td class="num">' + fmt(r.prev) + '</td>'
           + '<td class="num">' + fmt(r.cur) + '</td>'
           + '<td class="num' + dcls + '">' + dtxt + '</td>'
           + '</tr>';
    });
    html += '</tbody></table>';
    if (changed === 0) html += '<p class="meta">前回から変化はありません。</p>';

    result.innerHTML = html;
    clearBtn.style.display = '';
  }
})();

/* 列ヘッダークリックで並べ替え（数値列は数値ソート） */
(function () {
  var table = document.getElementById('urlTable');
  if (!table) return;
  var numCols = { ja: 1, foreign: 1, total: 1 };
  var dir = {};
  table.querySelectorAll('th').forEach(function (th, idx) {
    th.addEventListener('click', function () {
      var k = th.getAttribute('data-k');
      var asc = dir[idx] = !dir[idx];
      var rows = Array.prototype.slice.call(table.tBodies[0].rows);
      rows.sort(function (a, b) {
        var x = a.cells[idx].textContent.trim();
        var y = b.cells[idx].textContent.trim();
        if (numCols[k]) {
          x = parseInt(x.replace(/[^0-9-]/g, ''), 10) || 0;
          y = parseInt(y.replace(/[^0-9-]/g, ''), 10) || 0;
          return asc ? x - y : y - x;
        }
        return asc ? x.localeCompare(y, 'ja') : y.localeCompare(x, 'ja');
      });
      rows.forEach(function (r) { table.tBodies[0].appendChild(r); });
    });
  });
})();
</script>
</div>
</body>
</html>
