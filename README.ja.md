# local_h5plogger

MoodleのH5Pアクティビティ（`mod_h5pactivity`）における学習者の操作を詳細に記録する **Localプラグイン**。学習分析（Learning Analytics）・IR（Institutional Research）用途を想定している。

Moodle標準のH5Pログは粗く、スライド遷移・ボタンクリック・チャプター移動・動画再生イベントなどは記録されない。`local_h5plogger` は3系統のイベントソースを監視し、それらを単一のカスタムテーブルに書き込むことでこのギャップを埋める。**Configurable Reports** での参照はもちろん、Python/Rでの分析にもそのままエクスポートできる。

> English version: [README.md](README.md)

---

## 取得できるもの

| 系統 | 取得方法 | 主なverb |
|---|---|---|
| xAPI | `H5P.externalDispatcher.on('xAPI', ...)` | `progressed`, `answered`, `attempted`, `interacted`, `completed` |
| DOMクリック | `addEventListener('click', ..., true)` | `button_clicked`, `chapter_jumped`, `chapter_moved`, `adaptivity_triggered` |
| 動画postMessage（YouTube / Vimeo） | `addEventListener('message', ...)` | `video_played`, `video_paused`, `video_seeked` |

**CoursePresentation**・**InteractiveBook**・**InteractiveVideo** で動作確認済み。

### なぜこれが単純ではないか

MoodleでのH5Pコンテンツは **2段のiframe構造** で描画される：

```
view.php（同オリジン）
  └── embed.php iframe（同オリジン）      ← xAPIのexternalDispatcherはここ
        └── h5p-iframe-N（同オリジン）    ← DOM要素・動画postMessageはここ
              └── YouTube/Vimeo iframe（クロスオリジン）
```

xAPIイベントは**外側**のiframe層に伝播するのに対し、DOM要素と動画プレイヤーのメッセージは**最内**のiframeにしか存在しない。本プラグインのJSはこの構造を辿り、イベント種別ごとに正しい層へリスナーを張る。最内iframeが存在しない構成（InteractiveVideo + Vimeo等）向けのフォールバックも備えている。

---

## 動作要件

- Moodle 4.0以上（Moodle 5.0で動作確認済み）
- `mod_h5pactivity`（Moodle標準のH5Pアクティビティモジュール）

---

## インストール

1. プラグインZIPをダウンロード
2. サイト管理 → プラグイン → プラグインのインストール からZIPをアップロード、**または** サーバー上の `local/h5plogger` に展開
3. Moodleのアップグレード処理を完了させる（`admin/index.php` または `php admin/cli/upgrade.php`）

---

## 設定

サイト管理 → プラグイン → ローカルプラグイン → **H5P Interaction Logger**

| 設定項目 | デフォルト | 説明 |
|---|---|---|
| Log teacher/admin interactions | OFF | ONにすると `moodle/course:manageactivities` を持つユーザーのログも記録する（通常は除外され、プレビュー・編集セッションが学習者データに混ざらないようにしている） |
| Save CSS classes in button click logs | OFF | ONにするとクリック要素のCSSクラス文字列を丸ごと `extra` に保存する。セレクタのデバッグには便利だが行サイズが増える |
| Log retention period (days) | 0（無期限） | 指定日数より古いログを日次スケジュールタスクが自動削除する。`0` は無期限保持 |

---

## DBスキーマ

テーブル：`local_h5plogger_log`

| カラム | 型 | 説明 |
|---|---|---|
| `id` | int | PK |
| `userid` | int | MoodleユーザーID |
| `cmid` | int | コースモジュールID（URLの `?id=`） |
| `h5p_id` | int | `mdl_h5p.id`（xAPIの `h5p-local-content-id`） |
| `attempt_id` | int | `mdl_h5pactivity_attempts.id`（最新）。セッション境界として利用 |
| `verb` | char(64) | 下記verb一覧を参照 |
| `extra` | text（JSON） | verb別の詳細情報 |
| `timecreated` | int | Unixタイムスタンプ |

**設計上の判断：**
- `h5pactivity_id` は意図的に保存していない。`cmid` から `course_modules.instance` で常に解決できるため、記録時の追加クエリを省いて同時アクセス時の負荷を抑えている。
- `slide_from` / `slide_to` は専用カラムではなく `extra`（JSON）に格納。verbによって可変のフィールドをスキーマ全体で一元化するため。

### 取得できるverbと `extra` の中身

| verb | 発生元 | 主な `extra` フィールド |
|---|---|---|
| `progressed` | xAPI (CP/IB) | `slide_from`, `slide_to` |
| `answered` | xAPI | `response`, `success`, `score`, `question`, `choices`, `correct` |
| `attempted` | xAPI (IV) | `timecode`, `question`, `sub_content_id` |
| `interacted` | xAPI (IV) | `timecode`, `sub_content_id` |
| `completed` | xAPI | `timecode` または `slide_to` |
| `button_clicked` | DOM | `label`, `direction`, `timecode`/`slide_no`, `popup_text` |
| `adaptivity_triggered` | DOM | `label`, `timecode` |
| `chapter_jumped` | DOM | `label`, `direction`, `slide_no` |
| `chapter_moved` | DOM | `label`, `direction`, `slide_no` |
| `video_played` | postMessage | `timecode`, `video_no`, `video_provider`＊, `video_id`＊ |
| `video_paused` | postMessage | `timecode`, `video_no`, `video_provider`＊, `video_id`＊ |
| `video_seeked` | postMessage | `from`, `to`, `video_no`, `video_provider`＊, `video_id`＊ |

＊ `video_provider`/`video_id` は動画iframeの `src` 属性から抽出できた場合のみ付与される。`video_no`（DOM出現順の連番、1始まり）は常に付与され、同一スライドに動画が複数embedされているケースでも識別できる。

### 参照例：Configurable Reports

H5Pアクティビティのあるコースに、SQLレポートを作成する。`%%COURSEID%%` はConfigurable Reportsがレポートを置いたコースのIDに置き換えるため、1つのレポートでそのコース内の全H5Pアクティビティの操作を一覧できる。

```sql
SELECT
  lg.id,
  lg.userid,
  lg.cmid,
  ha.name          AS activity_name,
  lib.machinename  AS h5p_type,
  lg.attempt_id,
  lg.verb,
  lg.extra,
  lg.timecreated
FROM {local_h5plogger_log} lg
JOIN {course_modules} cm      ON cm.id = lg.cmid
JOIN {h5pactivity} ha         ON ha.id = cm.instance
LEFT JOIN {h5p} h             ON h.id = lg.h5p_id
LEFT JOIN {h5p_libraries} lib ON lib.id = h.mainlibraryid
WHERE cm.course = %%COURSEID%%
ORDER BY lg.cmid, lg.userid, lg.timecreated, lg.id
```

このクエリについて：

- `activity_name` は `course_modules.instance` 経由で解決する（ログテーブルには `h5pactivity_id` を保存していない）。`h5p_type` はH5Pのライブラリ名（例：`H5P.CoursePresentation`）。
- `h5p_id` はクライアントからの申告値のため、欠落や不正な値が入りうる。`LEFT JOIN` にしているので、そのような行も残り、`h5p_type` だけが空になる。
- `timecreated` は秒精度のため、同じ秒のイベントの順序を固定する目的で、最後のソートキーに `lg.id` を使っている。
- 特定のアクティビティだけに絞る場合は、`WHERE` 句に `AND lg.cmid = <cmid>`（アクティビティURLの `?id=` の数字）を追加する。

結果を使うときの注意：

- **`extra` は信頼できない入力として扱う。** `label` や `popup_text` などの値は学習者のブラウザ由来。Configurable Reports以外の場所（自作ダッシュボード、Python/Rで生成したHTMLなど）で表示するときは、必ずエスケープする。
- **教師・管理者の操作は、既定では記録されない。** 動作確認は学習者ロールのアカウントで行う（または *Log teacher/admin interactions* をONにする）。そうしないと、レポートが空に見える。
- **このレポートは、個々の学習者の操作ログを表示する。** 閲覧できる人は、Configurable Reports側のレポートの権限設定で制限する。



---

## プライバシー（GDPR）

`classes/privacy/provider.php` にフルプロバイダを実装済み。全レコードがコースモジュール（`cmid`）に紐づくため、`CONTEXT_MODULE` レベルで扱う。

- サイト管理 → ユーザー → プライバシーとポリシー からのエクスポート・削除リクエストに完全対応
- 個別のGDPRリクエストとは別に、**Log retention period** 設定で日次スケジュールタスクによる自動的な古いログの削除も可能

---

## セキュリティ上の配慮

`log.php` はクライアントからの入力をすべて「信頼できないもの」として扱い、書き込み前にサーバー側で検証する：

- `verb` は既知verbの固定ホワイトリストと照合
- `cmid` は実在する `h5pactivity` コースモジュールに解決できる必要があり、リクエストしたユーザーはログイン済みかつ当該コースモジュールへの履修・可視性を持っている必要がある（`require_login($cm->course, false, $cm)`）
- `extra`（JSON）は8KBのサイズ上限あり。超過分は行ごと拒否せず `extra` のみ破棄して記録する
- `userid` は常にサーバー側セッション（`$USER->id`）由来であり、クライアントのペイロードからは受け取らない。そのため他人になりすましてログを記録することはできない

クライアント側計測である以上、悪意あるユーザーが自分自身のアカウントで偽イベントを送ること自体を完全に防ぐことはできない。ただし上記の対策により、他コースへの書き込みやDBの肥大化といった「本来あってはならない経路」は塞がれている。

---

## 既知の制約

- セッションの最初のイベントで `attempt_id` が空になることがある。H5Pのattemptレコードと最初のxAPIイベントがほぼ同時に作成されるための競合が原因
- `video_no` はリスナー接続時点でのDOM出現順を表すもので、永続的なコンテンツIDではない。H5Pコンテンツ内で動画の並び順を変更すると、`video_no` と実際の動画の対応も変わる
- InteractiveBook内のインタラクティブ要素を含まない純テキストページはxAPIイベントを発火しないため、そのページでの滞在時間は記録されない

---

## ライセンス

GPL-3.0-or-later（`LICENSE` を参照）。Moodleコア・プラグインの慣例に準拠。
