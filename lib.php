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
    // json_encode経由で埋め込むことで、JS文字列リテラルへの直接埋め込みより防御的にする
    $logurl      = json_encode((new moodle_url('/local/h5plogger/log.php'))->out(false));
    $sesskeyjs   = json_encode(sesskey());
    $save_classes = get_config('local_h5plogger', 'save_classes') ? 'true' : 'false';

    return <<<HTML
<script>
(function() {
    var _v = '0.4.8'; // version tag — do not remove (affects JS engine behaviour)
    // ---- 設定：DOMクリックで拾う対象のホワイトリスト ----
    // xAPIで取れない操作だけを狙い撃つ。コンテンツタイプ別ではなく、
    // 部品(H5Pライブラリ)のclass別で判定する（ブック内・単体を問わず効く）。
    // 上から順に評価し、最初に closest() で当たったものを採用する。
    var CLICK_RULES = [
        {
            // InteractiveVideo: オーバーレイボタン（情報・テキスト等）
            selector: '.h5p-interaction-button',
            verb:     'button_clicked'
        },
        {
            // InteractiveVideo: 不正解→分岐ボタン
            selector: '.h5p-question-iv-adaptivity-wrong',
            verb:     'adaptivity_triggered'
        },
        {
            // InteractiveBook: サイド目次からのチャプタージャンプ
            selector: '.h5p-interactive-book-navigation-chapter-button',
            verb:     'chapter_jumped'
        },
        {
            // InteractiveBook: ページ下の次/前ボタン
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

    // ISO 8601 duration文字列 → 秒数（InteractiveVideoのending-point用）
    // 例: "PT11S" → 11, "PT1M30S" → 90
    function parseISO8601Duration(pt) {
        if (!pt || typeof pt !== 'string') return null;
        var m = pt.match(/PT(?:(\d+)M)?(?:([\d.]+)S)?/);
        if (!m) return null;
        return Math.round(((parseInt(m[1])||0) * 60 + (parseFloat(m[2])||0)) * 100) / 100;
    }

    // iframeの読み込みを待ってからリスナーを仕掛ける
    function attachH5PListener(iframe) {
        try {
            var iwin = iframe.contentWindow;
            // H5P本体は最内iframeにのみ存在する。
            // location.href ではなく H5P オブジェクトの有無で本体を判定する。
            if (!iwin || !iwin.H5P || !iwin.H5P.externalDispatcher) return false;

            var lastSlide     = null; // CP/IB: 現在のスライド番号（progressed xAPIで更新）
            var lastVideoTime = null; // IV: 現在の動画再生位置（postMessageで更新）

            var cmid = new URLSearchParams(window.location.search).get('id');
            var cmidInt = cmid ? parseInt(cmid) : null;

            // ---- 共通の送信関数（sesskeyはPHP埋め込み値を使う。embed.php側のM.cfgに依存しない）----
            function sendLog(payload) {
                payload.sesskey = {$sesskeyjs};
                payload.cmid    = cmidInt;
                try {
                    iwin.fetch({$logurl}, {
                        method:  'POST',
                        headers: {'Content-Type': 'application/json'},
                        body:    JSON.stringify(payload),
                        keepalive: true,
                    });
                } catch (e) {
                    // 最内iframeのfetchが使えない場合は親のfetchにフォールバック
                    try {
                        window.fetch({$logurl}, {
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

                // コンテンツタイプ判定（InteractiveVideoか否か）
                var categoryId = (stmt.context
                    && stmt.context.contextActivities
                    && stmt.context.contextActivities.category
                    && stmt.context.contextActivities.category[0]
                    && stmt.context.contextActivities.category[0].id) || '';
                var isIV = categoryId.indexOf('H5P.InteractiveVideo') !== -1;

                var endingPoint = ext['http://id.tincanapi.com/extension/ending-point']
                               ?? ctxExt['http://id.tincanapi.com/extension/ending-point']
                               ?? null;

                var h5pId = ext['http://h5p.org/x-api/h5p-local-content-id']
                         || ctxExt['http://h5p.org/x-api/h5p-local-content-id']
                         || null;

                var subContentId = ext['http://h5p.org/x-api/h5p-subContentId'] || null;

                // extra(JSON) を verb・コンテンツタイプごとに組み立てる
                var extra = null;

                if (isIV) {
                    // ---- InteractiveVideo 固有処理 ----
                    // ending-point は ISO 8601 duration 形式 → 秒数に変換
                    var timecode = parseISO8601Duration(endingPoint);

                    if (verb === 'attempted') {
                        extra = {
                            timecode:       timecode,
                            question:       (stmt.object.definition && stmt.object.definition.name
                                            && stmt.object.definition.name['en-US']) || null,
                            sub_content_id: subContentId,
                        };
                    } else if (verb === 'answered' && stmt.result) {
                        var durationSec = null;
                        if (stmt.result.duration) {
                            durationSec = parseISO8601Duration(stmt.result.duration);
                        }
                        extra = {
                            timecode:       timecode,
                            response:       stmt.result.response  ?? null,
                            success:        stmt.result.success   ?? null,
                            score:          stmt.result.score     ?? null,
                            duration_sec:   durationSec,
                            question:       (stmt.object.definition && stmt.object.definition.description
                                            && stmt.object.definition.description['en-US']) || null,
                            choices:        (stmt.object.definition && stmt.object.definition.choices) || null,
                            correct:        (stmt.object.definition && stmt.object.definition.correctResponsesPattern) || null,
                            sub_content_id: subContentId,
                        };
                    } else if (verb === 'interacted') {
                        extra = {
                            timecode:       timecode,
                            sub_content_id: subContentId,
                        };
                    } else if (timecode !== null) {
                        // completed 等その他のverb
                        extra = { timecode: timecode };
                    }

                } else {
                    // ---- CoursePresentation / InteractiveBook 既存処理 ----
                    var slideTo = endingPoint;

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
                        // その他のverb(attempted/interacted/completed等)
                        extra = { slide_to: slideTo };
                    }
                }

                sendLog({
                    h5p_id: h5pId ? parseInt(h5pId) : null,
                    verb:   verb,
                    extra:  extra ? JSON.stringify(extra) : null,
                });
            });

            // ---- DOMクリック監視 ----
            // 重要：H5Pは2段iframe構造で、externalDispatcher(xAPI)は外側に顔を出すが、
            // ボタン等のDOMは最内iframe(h5p-iframe-N)のdocumentにしか存在しない。
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

                // 位置情報：IVなら timecode（秒）、CP/IBなら slide_no、どちらもなければ省略
                var posExtra = {};
                if (lastVideoTime !== null) {
                    posExtra.timecode = lastVideoTime;
                } else if (lastSlide !== null) {
                    posExtra.slide_no = lastSlide;
                }

                var clickExtra = {
                    // aria-label → title → textContent の順でラベルを取得
                    label:     el.getAttribute('aria-label')
                               || el.getAttribute('title')
                               || (el.textContent.trim().replace(/\s+/g, ' ').slice(0, 100) || null),
                    direction: direction,
                };
                if ({$save_classes}) {
                    clickExtra.classes = el.className || null;
                }
                Object.assign(clickExtra, posExtra);

                // 常に300ms待ってからポップアップ本文を補完して送信
                // （aria-haspopupなしのIV interaction-buttonでもポップアップが開く場合がある）
                var doc = e.target.ownerDocument;
                setTimeout(function() {
                    var popup = doc.querySelector('.h5p-popup-overlay, .h5p-dialog-interaction');
                    clickExtra.popup_text = popup
                        ? (popup.textContent.trim().replace(/\s+/g, ' ').slice(0, 300) || null)
                        : null;
                    sendLog({ h5p_id: null, verb: matched.verb, extra: JSON.stringify(clickExtra) });
                }, 300);
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

            // ---- 動画（YouTube / Vimeo）postMessage監視 ----
            // postMessageはh5p-iframe-Nに届く（動画iframeの直接の親）。
            // innerWin = h5p-iframe-N のwindowに対してlistenerを張る。
            //
            // 同一スライドに動画が複数embedされるケース（CPで動画を2個並べる等）に対応するため、
            // DOM上のvideo iframeをあらかじめ列挙してレジストリを作り、
            // postMessageの送信元(e.source)と突き合わせて「どの動画か」を特定する。
            // - video_no      : DOM出現順の連番（1始まり）。常に付与
            // - video_provider/video_id : iframeのsrc属性から抽出できた場合のみ付与
            //   （src属性は親ドキュメントから読む分にはクロスオリジン制約を受けない）
            function buildVideoRegistry(win) {
                var registry = [];
                var iframes;
                try {
                    iframes = win.document.querySelectorAll('iframe');
                } catch(e) {
                    return registry;
                }
                var no = 0;
                for (var i = 0; i < iframes.length; i++) {
                    var src = iframes[i].getAttribute('src') || '';
                    var provider = null;
                    var videoId  = null;

                    if (/(^|\.)youtube(-nocookie)?\.com\//.test(src)) {
                        provider = 'youtube';
                        var ym = src.match(/\/embed\/([a-zA-Z0-9_-]{11})/);
                        if (ym) videoId = ym[1];
                    } else if (/player\.vimeo\.com\//.test(src)) {
                        provider = 'vimeo';
                        var vm = src.match(/\/video\/(\d+)/);
                        if (vm) videoId = vm[1];
                    }

                    if (!provider) continue; // YouTube/Vimeo以外のiframeは対象外

                    no++;
                    registry.push({
                        win:      iframes[i].contentWindow,
                        provider: provider,
                        videoId:  videoId,
                        videoNo:  no,
                    });
                }
                return registry;
            }

            function attachVideoListener(innerWin) {
                var videoRegistry = buildVideoRegistry(innerWin);

                // 動画ごとの再生状態。レジストリの要素（マッチしない場合はe.source自体）をキーにして、
                // 複数動画が同時に存在してもlastTime/playerState等が混線しないようにする。
                var stateByVideo = new Map();
                function getState(key) {
                    if (!stateByVideo.has(key)) {
                        stateByVideo.set(key, { lastTime: null, playerState: -1, vimeoState: -1, seekDebounce: null });
                    }
                    return stateByVideo.get(key);
                }
                function findEntry(source) {
                    for (var i = 0; i < videoRegistry.length; i++) {
                        if (videoRegistry[i].win === source) return videoRegistry[i];
                    }
                    return null;
                }

                innerWin.addEventListener('message', function(e) {
                    var data;
                    try {
                        data = (typeof e.data === 'string') ? JSON.parse(e.data) : e.data;
                    } catch(ex) { return; }
                    if (!data) return;

                    var entry = findEntry(e.source);
                    if (!entry) {
                        // レジストリ構築時点でまだ描画されていなかった動画iframe向けの救済（1回だけ再スキャン）
                        videoRegistry = buildVideoRegistry(innerWin);
                        entry = findEntry(e.source);
                    }
                    var st = getState(entry || e.source); // 未マッチでもsource単位で状態は分離する

                    function tagVideo(extra) {
                        extra.video_no = entry ? entry.videoNo : null;
                        if (entry && entry.provider) extra.video_provider = entry.provider;
                        if (entry && entry.videoId)  extra.video_id = entry.videoId;
                        return extra;
                    }

                    // ── Vimeo Player API (origin: player.vimeo.com) ──────────────
                    if (e.origin === 'https://player.vimeo.com' && data.event) {
                        if (data.event === 'play') {
                            if (st.vimeoState !== 1) {
                                st.vimeoState = 1;
                                // data.data.seconds があればそちらが正確
                                var pt = (data.data && data.data.seconds !== undefined)
                                         ? data.data.seconds : st.lastTime;
                                if (pt !== null) { st.lastTime = pt; lastVideoTime = pt; }
                                sendLog({ h5p_id: null, verb: 'video_played',
                                          extra: JSON.stringify(tagVideo({ timecode: st.lastTime })) });
                            }
                        } else if (data.event === 'pause') {
                            if (st.vimeoState !== 2) {
                                st.vimeoState = 2;
                                var pt2 = (data.data && data.data.seconds !== undefined)
                                          ? data.data.seconds : st.lastTime;
                                if (pt2 !== null) { st.lastTime = pt2; lastVideoTime = pt2; }
                                sendLog({ h5p_id: null, verb: 'video_paused',
                                          extra: JSON.stringify(tagVideo({ timecode: st.lastTime })) });
                            }
                        } else if (data.event === 'timeupdate' && data.data) {
                            var vct = data.data.seconds;
                            if (vct !== undefined && vct !== null) {
                                if (st.lastTime !== null && Math.abs(vct - st.lastTime) > 2) {
                                    // シーク検出：2秒以上の不連続ジャンプ
                                    var vfrom = st.lastTime;
                                    var vto   = vct;
                                    if (st.seekDebounce) clearTimeout(st.seekDebounce);
                                    st.seekDebounce = setTimeout(function() {
                                        sendLog({ h5p_id: null, verb: 'video_seeked',
                                                  extra: JSON.stringify(tagVideo({ from: vfrom, to: vto })) });
                                    }, 500);
                                }
                                st.lastTime = vct;
                                lastVideoTime = vct;
                            }
                        }
                        return;
                    }

                    // ── YouTube IFrame API (channel: 'widget') ───────────────────
                    // origin検証：通常domain・privacy-enhanced domain(nocookie)の両方を許可
                    var isYouTubeOrigin = (e.origin === 'https://www.youtube.com'
                                          || e.origin === 'https://www.youtube-nocookie.com');
                    if (!isYouTubeOrigin || !data.event || data.channel !== 'widget') return;

                    if (data.event === 'onStateChange') {
                        var newState = parseInt(data.info);
                        if (newState === 1 && st.playerState !== 1) {
                            // 再生
                            sendLog({ h5p_id: null, verb: 'video_played',
                                      extra: JSON.stringify(tagVideo({ timecode: st.lastTime })) });
                        } else if (newState === 2 && st.playerState !== 2) {
                            // 一時停止
                            sendLog({ h5p_id: null, verb: 'video_paused',
                                      extra: JSON.stringify(tagVideo({ timecode: st.lastTime })) });
                        }
                        st.playerState = newState;
                    }

                    if (data.event === 'infoDelivery' && data.info) {
                        var ct = data.info.currentTime;
                        if (ct !== undefined && ct !== null) {
                            if (st.lastTime !== null && Math.abs(ct - st.lastTime) > 2) {
                                // シーク検出：2秒以上の不連続ジャンプ
                                var from = st.lastTime;
                                var to   = ct;
                                if (st.seekDebounce) clearTimeout(st.seekDebounce);
                                st.seekDebounce = setTimeout(function() {
                                    sendLog({ h5p_id: null, verb: 'video_seeked',
                                              extra: JSON.stringify(tagVideo({ from: from, to: to })) });
                                }, 500);
                            }
                            st.lastTime = ct;
                            lastVideoTime = ct; // 外側スコープの変数を更新（clickHandlerのposExtra用）
                        }
                    }
                });
            }

            // 外側iframe(iwin)の中にある最内iframe(h5p-iframe-N)を探して
            // クリック・動画リスナーを張る。
            //
            // コンテンツタイプ別の構造：
            //   CP/IB/IV(YouTube): embed.php → h5p-iframe-N(同オリジン) → 動画iframe(cross-origin)
            //   IV(Vimeo):         embed.php に直接IVコンテンツ → Vimeo iframe(cross-origin)
            //                      ※ h5p-iframe-Nが存在しない構成
            //
            // "全てcross-origin" と "同オリジンだがabout:blank(ロード中)" を区別し、
            // 前者は即フォールバック、後者はリトライで待つ。
            function attachInner(outerWin, tries) {
                tries = tries || 0;
                var inner = null;
                var hasUnreadyFrame = false; // 同オリジンだがabout:blank（まだロード中）

                var innerFrames = [];
                try { innerFrames = outerWin.document.querySelectorAll('iframe'); } catch(e) {}

                // iframe毎に個別にtry/catch（クロスオリジンiframeで全体が止まるのを防ぐ）
                for (var i = 0; i < innerFrames.length; i++) {
                    try {
                        var w = innerFrames[i].contentWindow;
                        if (w && w.document && w.location.href !== 'about:blank') {
                            inner = w; break;
                        }
                        // 同オリジンだがabout:blank → ロード待ち（cross-originはcatchへ）
                        hasUnreadyFrame = true;
                    } catch(e) { /* クロスオリジン、スキップ */ }
                }

                if (inner) {
                    attachClickTo(inner);
                    attachVideoListener(inner);
                    return;
                }
                // 同オリジンiframeがabout:blankでロード中 → リトライ
                if (hasUnreadyFrame && tries < 20) {
                    setTimeout(function () { attachInner(outerWin, tries + 1); }, 300);
                    return;
                }
                // 全てのiframeがcross-origin / iframeなし → コンテンツがouterWin直下の構成
                // (Vimeo IV等) または20回待ってもabout:blank → フォールバック
                attachClickTo(outerWin);
                attachVideoListener(outerWin);
            }

            attachInner(iwin);

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
