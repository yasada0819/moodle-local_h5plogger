<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();

// verbホワイトリスト（サーバー側の入口検問所その1）。
// 既知12種に固定。未知のverbやDBカラム長(char(64))溢れをここで弾く。
const H5PLOGGER_ALLOWED_VERBS = [
    'progressed', 'answered', 'attempted', 'interacted', 'completed',
    'button_clicked', 'adaptivity_triggered', 'chapter_jumped', 'chapter_moved',
    'video_played', 'video_paused', 'video_seeked',
];

// extra(JSON)のサイズ上限（バイト）。超過分は行ごと拒否せず、extraのみ破棄して記録は残す
// （既存のJSON妥当性検証と同じ「壊れていたらnull化」の思想を踏襲）。
const H5PLOGGER_EXTRA_MAX_BYTES = 8192;

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    die(json_encode(['error' => 'invalid json']));
}

if (empty($data['sesskey']) || !confirm_sesskey($data['sesskey'])) {
    http_response_code(403);
    die(json_encode(['error' => 'invalid sesskey']));
}

// 基本値（骨格列のみ。slide等のverb固有情報はすべて extra(JSON) に格納）
$cmid   = isset($data['cmid'])   && $data['cmid']   !== null ? (int)$data['cmid']   : null;
$h5p_id = isset($data['h5p_id']) && $data['h5p_id'] !== null ? (int)$data['h5p_id'] : null;
$verb   = isset($data['verb'])   ? clean_param($data['verb'], PARAM_ALPHANUMEXT)     : null;
$extra  = isset($data['extra'])  ? $data['extra'] : null;

if (!$verb) {
    http_response_code(400);
    die(json_encode(['error' => 'verb required']));
}

// verbホワイトリスト照合
if (!in_array($verb, H5PLOGGER_ALLOWED_VERBS, true)) {
    http_response_code(400);
    die(json_encode(['error' => 'unknown verb']));
}

// cmidは必須。クライアントJS(lib.php)は常にURLの?id=から取得したcmidを送るため、
// 欠落している時点で正規のH5Pページ由来ではないと判断できる。
if (!$cmid) {
    http_response_code(400);
    die(json_encode(['error' => 'cmid required']));
}

// cmidの実在確認（サーバー側の入口検問所その2）
$cm = get_coursemodule_from_id('h5pactivity', $cmid);
if (!$cm) {
    http_response_code(400);
    die(json_encode(['error' => 'invalid cmid']));
}

// 履修・活動可視性チェック。未履修コースへのログ書き込みや、
// 権限のないcmへのなりすまし書き込みを防ぐ。
// AJAX_SCRIPT定義済みのため、権限不足時はリダイレクトではなく例外がスローされる。
try {
    require_login($cm->course, false, $cm);
} catch (require_login_exception $e) {
    http_response_code(403);
    die(json_encode(['error' => 'access denied']));
}

// extra は JSON文字列を想定。妥当性を軽く検証（不正ならそのまま保存せずnull化）
if ($extra !== null) {
    if (!is_string($extra)) {
        // 念のためオブジェクトで来たら文字列化
        $extra = json_encode($extra);
    }
    $decoded = json_decode($extra, true);
    if ($decoded === null && trim($extra) !== 'null') {
        // JSONとして壊れている場合は破棄（DBに不正データを残さない）
        $extra = null;
    }
}

// サイズ上限チェック（サーバー側の入口検問所その3）。
// 大容量JSONの高頻度POSTによるDBリソース肥大化(DoS)を防ぐ。
// 行自体は記録し、extraのみ破棄する（verb/timecreated等の骨格情報は分析上有用なため残す）。
if ($extra !== null && strlen($extra) > H5PLOGGER_EXTRA_MAX_BYTES) {
    $extra = null;
}

// cmidからh5pactivity_idとattempt_idを解決（$cmは上のアクセス検証で取得済みのものを再利用）
$h5pactivity_id = (int)$cm->instance;
$attempt_id     = null;

// 該当ユーザーの最新attemptを取得（get_records_sqlで複数レコード問題を回避）
$attempts = $DB->get_records_sql(
    "SELECT id FROM {h5pactivity_attempts}
     WHERE h5pactivityid = ? AND userid = ?
     ORDER BY timecreated DESC",
    [$h5pactivity_id, $USER->id],
    0, 1  // offset=0, limit=1
);
if ($attempts) {
    $attempt = reset($attempts); // 先頭1件取得
    $attempt_id = (int)$attempt->id;
}

// 保存（h5pactivity_id はDBに保存しない。cmid→course_modules.instanceで常に解決可能なため、
// 記録時負荷の最小化を優先。上記の $h5pactivity_id は attempt_id 解決のためにのみ内部利用）
$record = new stdClass();
$record->userid         = $USER->id;
$record->cmid           = $cmid;
$record->h5p_id         = $h5p_id;
$record->attempt_id     = $attempt_id;
$record->verb           = $verb;
$record->extra          = $extra;
$record->timecreated    = time();

$DB->insert_record('local_h5plogger_log', $record);

header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
