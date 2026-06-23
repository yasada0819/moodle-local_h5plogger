<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();

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

// 基本値
$cmid      = isset($data['cmid'])   && $data['cmid']   !== null ? (int)$data['cmid']   : null;
$h5p_id    = isset($data['h5p_id']) && $data['h5p_id'] !== null ? (int)$data['h5p_id'] : null;
$verb      = isset($data['verb'])   ? clean_param($data['verb'], PARAM_ALPHANUMEXT)     : null;
$slide_from = isset($data['slide_from']) && $data['slide_from'] !== null ? (int)$data['slide_from'] : null;
$slide_to   = isset($data['slide_to'])   && $data['slide_to']   !== null ? (int)$data['slide_to']   : null;
$extra      = isset($data['extra']) ? $data['extra'] : null;

if (!$verb) {
    http_response_code(400);
    die(json_encode(['error' => 'verb required']));
}

// cmidからh5pactivity_idとattempt_idを解決
$h5pactivity_id = null;
$attempt_id     = null;

if ($cmid) {
    $cm = get_coursemodule_from_id('h5pactivity', $cmid);
    if ($cm) {
        $h5pactivity_id = (int)$cm->instance;

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
    }
}

// 保存
$record = new stdClass();
$record->userid         = $USER->id;
$record->cmid           = $cmid;
$record->h5p_id         = $h5p_id;
$record->h5pactivity_id = $h5pactivity_id;
$record->attempt_id     = $attempt_id;
$record->verb           = $verb;
$record->slide_from     = $slide_from;
$record->slide_to       = $slide_to;
$record->extra          = $extra;
$record->timecreated    = time();

$DB->insert_record('local_h5plogger_log', $record);

echo json_encode(['status' => 'ok']);
