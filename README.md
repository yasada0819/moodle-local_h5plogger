# local_h5plogger

A Moodle **local plugin** that captures detailed learner interactions inside H5P activities (`mod_h5pactivity`) for learning analytics and institutional research (IR) purposes.

Moodle's built-in H5P logging is coarse — it doesn't capture slide navigation, button clicks, chapter jumps, or video playback events. `local_h5plogger` fills that gap by listening to three separate event sources and writing everything into a single custom table that's easy to query with **Configurable Reports** or export for analysis in Python/R.

> 日本語版は [README.ja.md](README.ja.md) を参照してください。

---

## What it captures

| Source | Detection method | Typical verbs |
|---|---|---|
| xAPI | `H5P.externalDispatcher.on('xAPI', ...)` | `progressed`, `answered`, `attempted`, `interacted`, `completed` |
| DOM clicks | `addEventListener('click', ..., true)` | `button_clicked`, `chapter_jumped`, `chapter_moved`, `adaptivity_triggered` |
| Video postMessage (YouTube / Vimeo) | `addEventListener('message', ...)` | `video_played`, `video_paused`, `video_seeked` |

Tested and working on **CoursePresentation**, **InteractiveBook**, and **InteractiveVideo** content types.

### Why this is non-trivial

H5P content in Moodle is rendered inside a **two-layer iframe** structure:

```
view.php (same origin)
  └── embed.php iframe (same origin)   ← xAPI externalDispatcher lives here
        └── h5p-iframe-N (same origin) ← DOM elements & video postMessages live here
              └── YouTube/Vimeo iframe (cross-origin)
```

xAPI events propagate to the *outer* iframe layer, while DOM elements and video player messages only exist in the *innermost* iframe. The plugin's JS walks this structure and attaches listeners to the correct layer for each event type — including a fallback for content types (like InteractiveVideo + Vimeo) where the innermost iframe doesn't exist at all.

---

## Requirements

- Moodle 4.0+ (tested on Moodle 5.0)
- `mod_h5pactivity` (core H5P activity module)

---

## Installation

1. Download the plugin ZIP.
2. Site administration → Plugins → Install plugins → upload the ZIP, **or** extract it to `local/h5plogger` on the server.
3. Complete the Moodle upgrade process (`admin/index.php` or `php admin/cli/upgrade.php`).

---

## Configuration

Site administration → Plugins → Local plugins → **H5P Interaction Logger**

| Setting | Default | Description |
|---|---|---|
| Log teacher/admin interactions | Off | If enabled, users with `moodle/course:manageactivities` are also logged (normally excluded, so preview/editing sessions don't pollute learner data) |
| Save CSS classes in button click logs | Off | If enabled, stores the full CSS class string of clicked elements in `extra`. Useful for debugging selector issues, but increases row size |
| Log retention period (days) | 0 (unlimited) | Logs older than this many days are deleted by a daily scheduled task. `0` means keep forever |

---

## Database schema

Table: `local_h5plogger_log`

| Column | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `userid` | int | Moodle user ID |
| `cmid` | int | Course module ID (from URL `?id=`) |
| `h5p_id` | int | `mdl_h5p.id` (xAPI `h5p-local-content-id`) |
| `attempt_id` | int | `mdl_h5pactivity_attempts.id` (latest), used as a session boundary |
| `verb` | char(64) | See verb table below |
| `extra` | text (JSON) | Verb-specific detail — see below |
| `timecreated` | int | Unix timestamp |

**Design notes:**
- `h5pactivity_id` is intentionally **not** stored — it's always resolvable from `cmid` via `course_modules.instance`, and skipping the extra lookup at write time reduces load under concurrent access.
- `slide_from` / `slide_to` are stored inside `extra` (JSON), not as dedicated columns, to keep the schema uniform across verb types.

### Captured verbs and their `extra` fields

| Verb | Source | Main `extra` fields |
|---|---|---|
| `progressed` | xAPI (CP/IB) | `slide_from`, `slide_to` |
| `answered` | xAPI | `response`, `success`, `score`, `question`, `choices`, `correct` |
| `attempted` | xAPI (IV) | `timecode`, `question`, `sub_content_id` |
| `interacted` | xAPI (IV) | `timecode`, `sub_content_id` |
| `completed` | xAPI | `timecode` or `slide_to` |
| `button_clicked` | DOM | `label`, `direction`, `timecode`/`slide_no`, `popup_text` |
| `adaptivity_triggered` | DOM | `label`, `timecode` |
| `chapter_jumped` | DOM | `label`, `direction`, `slide_no` |
| `chapter_moved` | DOM | `label`, `direction`, `slide_no` |
| `video_played` | postMessage | `timecode`, `video_no`, `video_provider`\*, `video_id`\* |
| `video_paused` | postMessage | `timecode`, `video_no`, `video_provider`\*, `video_id`\* |
| `video_seeked` | postMessage | `from`, `to`, `video_no`, `video_provider`\*, `video_id`\* |

\* `video_provider`/`video_id` are only present when they can be extracted from the video iframe's `src` attribute. `video_no` (a 1-based index in DOM order) is always present, and lets you distinguish multiple videos embedded on the same slide.

### Example: Configurable Reports query

Create an SQL report in the course that contains your H5P activities. Configurable Reports replaces `%%COURSEID%%` with the ID of the course where the report is placed, so a single report lists the interactions of every H5P activity in that course.

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

Notes on this query:

- `activity_name` is resolved via `course_modules.instance` (`h5pactivity_id` is not stored in the log table). `h5p_type` is the H5P library, e.g. `H5P.CoursePresentation`.
- `h5p_id` is reported by the client, so it may be missing or invalid. The `LEFT JOIN`s keep those rows; `h5p_type` is simply empty for them.
- `timecreated` has one-second resolution, so `lg.id` is used as the final sort key to keep the order of same-second events stable.
- To restrict the report to one activity, append `AND lg.cmid = <cmid>` (the number in the activity URL `?id=`) to the `WHERE` clause.

Notes on using the results:

- **Treat `extra` as untrusted input.** Values such as `label` and `popup_text` originate in the learner's browser. Always escape them when you display them anywhere outside Configurable Reports (custom dashboards, HTML generated from Python/R, etc.).
- **Teacher/admin interactions are not logged by default.** Test with a learner-role account (or enable *Log teacher/admin interactions*), otherwise the report will look empty.
- **The report exposes individual learners' interaction logs.** Restrict who can view it with the report's permission settings in Configurable Reports.



---

## Privacy (GDPR)

The plugin implements a full privacy provider (`classes/privacy/provider.php`). Because every row is tied to a course module (`cmid`), data is treated at `CONTEXT_MODULE` level:

- Export and deletion requests (Site admin → Users → Privacy and policies) are fully supported.
- The **Log retention period** setting (see Configuration) can be used to automatically purge old logs via a daily scheduled task, independent of individual GDPR requests.

---

## Security notes

`log.php` treats all client input as untrusted and validates it server-side before writing:

- `verb` is checked against a fixed whitelist of known verbs.
- `cmid` must resolve to a real `h5pactivity` course module, and the requesting user must be logged in and have visibility/enrolment on that course module (`require_login($cm->course, false, $cm)`).
- `extra` (JSON) has an 8KB size cap; oversized payloads are discarded (the row is still recorded, just without `extra`) rather than rejecting the whole request.
- `userid` always comes from the server-side session (`$USER->id`), never from the client payload, so interactions can't be logged under someone else's identity.

Client-side telemetry can never fully prevent a determined user from fabricating events for their own account — but the above closes the gaps that would let one user pollute another course's data or overflow the database.

---

## Known limitations

- `attempt_id` can occasionally be empty on the very first event of a session, because the H5P attempt record and the first xAPI event are created almost simultaneously (race condition).
- `video_no` reflects DOM order at the time the listener attaches, not a persistent content ID — reordering videos within the H5P content will change which `video_no` maps to which video.
- Plain-text pages inside InteractiveBook (with no interactive elements) don't fire any xAPI event, so time-on-page for those isn't captured.

---

## License

GPL-3.0-or-later (see `LICENSE`), consistent with Moodle core and plugin conventions.
