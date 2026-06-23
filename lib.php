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
    // ---- 設定：DOMクリックで拾う対象のホワイトリスト ----
    // xAPIで取れない操作だけを狙い撃つ。コンテンツタイプ別ではなく、
    // 部品(H5Pライブラリ)のclass別で判定する（ブック内・単体を問わず効く）。
    // 上から順に評価し、最初に closest() で当たったものを採用する。
    var CLICK_RULES = [
        {
            // ブック：サイド目次からのチャプタージャンプ
            selector: '.h5p-interactive-book-navigation-chapter-button',
            verb:     'chapter_jumped'
        },
        {
            // ブック：ページ下の次/前ボタン
            selector: '.h5p-interactive-book-status-button',
            verb:     'chapter_moved'
        },
        {
            // 汎用：インフォメーションボタン等の H5P element button
            // （h5p-advancedtext-button などは h5p-element-button を含む）
            selector: '[class*="h5p-element-button"]',
            verb:     'button_clicked'
        }
    ];

    // iframeの読み込みを待ってからリスナーを仕掛ける
    function attachH5PListener(iframe) {
        try {
            var iwin = iframe.contentWindow;
            // H5P本体は最内iframeにのみ存在する。
            // location.href ではなく H5P オブジェクトの有無で本体を判定する。
            if (!iwin || !iwin.H5P || !iwin.H5P.externalDispatcher) return false;

            var lastSlide = null;

            var cmid = new URLSearchParams(window.location.search).get('id');
            var cmidInt = cmid ? parseInt(cmid) : null;

            // ---- 共通の送信関数（sesskeyはPHP埋め込み値を使う。embed.php側のM.cfgに依存しない）----
            function sendLog(payload) {
                payload.sesskey = '{$sesskey}';
                payload.cmid    = cmidInt;
                try {
                    iwin.fetch('{$logurl}', {
                        method:  'POST',
                        headers: {'Content-Type': 'application/json'},
                        body:    JSON.stringify(payload),
                        keepalive: true,
                    });
                } catch (e) {
                    // 最内iframeのfetchが使えない場合は親のfetchにフォールバック
                    try {
                        window.fetch('{$logurl}', {
                            method:  'POST',
                            headers: {'Content-Type': 'application/json'},
                            body:    JSON.stringify(payload),
                            keepalive: true,
                        });
                    } catch (e2) { /* サイレント */ }
                }
            }

            // ---- xAPIイベント監視 ----
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

                // extra(JSON) を verb ごとに組み立てる。
                // slide系は列を廃止したため progressed のとき extra に格納する。
                var extra = null;

                if (verb === 'progressed') {
                    var slideFrom = lastSlide;
                    lastSlide = slideTo;
                    extra = {
                        slide_from: slideFrom,
                        slide_to:   slideTo,
                    };
                } else if (verb === 'answered' && stmt.result) {
                    extra = {
                        slide_to: slideTo,
                        response: stmt.result.response  ?? null,
                        success:  stmt.result.success   ?? null,
                        score:    stmt.result.score     ?? null,
                        question: (stmt.object.definition && stmt.object.definition.description
                                   && stmt.object.definition.description['en-US']) || null,
                        choices:  (stmt.object.definition && stmt.object.definition.choices) || null,
                        correct:  (stmt.object.definition && stmt.object.definition.correctResponsesPattern) || null,
                    };
                } else if (slideTo !== null) {
                    // その他のverb(attempted/interacted/completed等)でも、
                    // 現在スライドが取れれば残しておく。
                    extra = { slide_to: slideTo };
                }

                var h5pId = ext['http://h5p.org/x-api/h5p-local-content-id']
                         || ctxExt['http://h5p.org/x-api/h5p-local-content-id']
                         || null;

                sendLog({
                    h5p_id: h5pId ? parseInt(h5pId) : null,
                    verb:   verb,
                    extra:  extra ? JSON.stringify(extra) : null,
                });
            });

            // ---- DOMクリック監視 ----
            // 重要：H5Pは2段iframe構造で、externalDispatcher(xAPI)は外側に顔を出すが、
            // ボタン等のDOMは最内iframe(h5p-iframe-N)のdocumentにしか存在しない。
            // そのため xAPI は iwin(外側) に張り、クリックは内側を探して張り分ける。
            //
            // クリックハンドラ本体（張る対象documentを引数で受ける）
            function clickHandler(e) {
                // ホワイトリストを上から評価し、最初に当たったルールを採用
                var matched = null;
                var el      = null;
                for (var i = 0; i < CLICK_RULES.length; i++) {
                    var hit = e.target.closest(CLICK_RULES[i].selector);
                    if (hit) { matched = CLICK_RULES[i]; el = hit; break; }
                }
                if (!matched) return; // どれにも当たらないクリックは捨てる

                // 方向(previous/next)を class から推定（chapter_moved用、他verbでも無害）
                var direction = null;
                if (el.className) {
                    if (/(^|\s|-)previous(\s|$)/.test(el.className)) direction = 'previous';
                    else if (/(^|\s|-)next(\s|$)/.test(el.className))  direction = 'next';
                }

                sendLog({
                    h5p_id: null,
                    verb:   matched.verb,
                    extra:  JSON.stringify({
                        label:     el.getAttribute('aria-label') || null,
                        text:      (el.textContent || '').trim().slice(0, 100) || null,
                        classes:   el.className || null,
                        direction: direction,
                        slide_to:  lastSlide,
                    }),
                });
            }

            // 指定windowのdocumentにキャプチャフェーズでクリックリスナーを張る
            function attachClickTo(targetWin) {
                try {
                    targetWin.document.addEventListener('click', clickHandler, true);
                    return true;
                } catch (e) {
                    return false;
                }
            }

            // 外側iframe(iwin)の中にある最内iframe(h5p-iframe-N)を探してクリックを張る。
            // 最内はxAPIより初期化が遅れることがあるためリトライで待つ。
            function attachClickToInner(outerWin, tries) {
                tries = tries || 0;
                var inner = null;
                try {
                    var innerFrames = outerWin.document.querySelectorAll('iframe');
                    for (var i = 0; i < innerFrames.length; i++) {
                        var w = innerFrames[i].contentWindow;
                        if (w && w.document) { inner = w; break; }
                    }
                } catch (e) {
                    // クロスオリジン等。同一オリジン前提なので通常来ない。
                }

                if (inner && attachClickTo(inner)) {
                    return; // 内側に張れた
                }
                if (tries < 20) {
                    setTimeout(function () { attachClickToInner(outerWin, tries + 1); }, 300);
                } else {
                    // 最終フォールバック：内側が見つからない構成なら外側に張る（保険）
                    attachClickTo(outerWin);
                }
            }

            attachClickToInner(iwin);

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
        // H5P本体(externalDispatcher)を持つiframeに張れるまでリトライ。
        // 2段iframe構造のため、最内が初期化されるまで時間がかかる場合がある。
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
