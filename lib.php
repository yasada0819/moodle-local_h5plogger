<?php
// This file is part of Moodle - http://moodle.org/
defined('MOODLE_INTERNAL') || die();

/**
 * H5Pアクティビティページにのみ、ロガーのJSを注入する
 */
function local_h5plogger_before_footer() {
    global $PAGE, $USER;

    // H5Pアクティビティページ以外はスキップ
    if ($PAGE->pagetype !== 'mod-h5pactivity-view') {
        return '';
    }

    // プレビューモード（教師・管理者）のログ除外設定を確認
    // log_preview_mode が OFF(0) のとき → 編集権限持ちはスキップ
    $log_preview = get_config('local_h5plogger', 'log_preview_mode');
    if (!$log_preview) {
        $context = $PAGE->context;
        if (has_capability('moodle/course:manageactivities', $context)) {
            return '';
        }
    }

    // ログ送信先URLとユーザー情報をJSに渡す
    $logurl  = (new moodle_url('/local/h5plogger/log.php'))->out(false);
    $sesskey = sesskey();

    return <<<HTML
<script>
(function() {
    // iframeの読み込みを待ってからリスナーを仕掛ける
    function attachH5PListener(iframe) {
        try {
            var iwin = iframe.contentWindow;
            if (!iwin || !iwin.H5P || !iwin.H5P.externalDispatcher) return false;

            var lastSlide = null;

            var cmid = new URLSearchParams(window.location.search).get('id');
            var cmidInt = cmid ? parseInt(cmid) : null;

            iwin.H5P.externalDispatcher.on('xAPI', function(event) {
                var stmt = event.data.statement;
                if (!stmt || !stmt.verb) return;

                var verbFull = stmt.verb.id || '';
                var verb     = verbFull.split('/').pop();

                var ext    = (stmt.object && stmt.object.definition && stmt.object.definition.extensions) || {};
                var ctxExt = (stmt.context && stmt.context.extensions) || {};

                var slideTo = ext['http://id.tincanapi.com/extension/ending-point']
                           ?? ctxExt['http://id.tincanapi.com/extension/ending-point']
                           ?? null;

                var slideFrom = null;
                if (verb === 'progressed') {
                    slideFrom = lastSlide;
                    lastSlide = slideTo;
                }

                var extra = null;
                if (verb === 'answered' && stmt.result) {
                    extra = {
                        response: stmt.result.response  ?? null,
                        success:  stmt.result.success   ?? null,
                        score:    stmt.result.score     ?? null,
                        question: (stmt.object.definition && stmt.object.definition.description
                                   && stmt.object.definition.description['en-US']) || null,
                        choices:  (stmt.object.definition && stmt.object.definition.choices) || null,
                        correct:  (stmt.object.definition && stmt.object.definition.correctResponsesPattern) || null,
                    };
                }

                var h5pId = ext['http://h5p.org/x-api/h5p-local-content-id']
                         || ctxExt['http://h5p.org/x-api/h5p-local-content-id']
                         || null;

                var payload = {
                    sesskey:    '{$sesskey}',
                    cmid:       cmidInt,
                    h5p_id:     h5pId,
                    verb:       verb,
                    slide_from: slideFrom,
                    slide_to:   slideTo,
                    extra:      extra ? JSON.stringify(extra) : null,
                };

                fetch('{$logurl}', {
                    method:  'POST',
                    headers: {'Content-Type': 'application/json'},
                    body:    JSON.stringify(payload),
                    keepalive: true,
                });
            });

            // DOMクリック監視：キャプチャフェーズで拾う（stopPropagation対策）
            try {
                iwin.document.addEventListener('click', function(e) {
                    var btn = e.target.closest('[class*="h5p-element-button"]');
                    if (!btn) return;

                    var payload = {
                        sesskey:    iwin.parent.M.cfg.sesskey,
                        cmid:       cmidInt,
                        h5p_id:     null,
                        verb:       'button_clicked',
                        slide_from: null,
                        slide_to:   lastSlide,
                        extra:      JSON.stringify({
                            label:   btn.getAttribute('aria-label') || null,
                            classes: btn.className || null,
                        }),
                    };

                    iwin.parent.fetch('{$logurl}', {
                        method:  'POST',
                        headers: {'Content-Type': 'application/json'},
                        body:    JSON.stringify(payload),
                        keepalive: true,
                    });
                }, true); // キャプチャフェーズで拾う
            } catch(e) {
                // DOM監視失敗はサイレントに無視
            }

            return true;
        } catch(e) {
            console.warn('H5PLogger: failed to attach listener', e);
            return false;
        }
    }

    function waitForH5PiFrame() {
        var iframes = document.querySelectorAll(
            'iframe.h5p-iframe, iframe.h5p-player, iframe[id^="h5p-iframe"], iframe[name="h5player"]'
        );
        if (iframes.length === 0) {
            setTimeout(waitForH5PiFrame, 500);
            return;
        }
        var attached = false;
        for (var i = 0; i < iframes.length; i++) {
            if (attachH5PListener(iframes[i])) {
                attached = true;
                break;
            }
        }
        if (!attached) {
            setTimeout(waitForH5PiFrame, 300);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForH5PiFrame);
    } else {
        waitForH5PiFrame();
    }
})();
</script>
HTML;
}
