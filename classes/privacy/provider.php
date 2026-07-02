<?php
namespace local_h5plogger\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * local_h5plogger のプライバシー(GDPR)プロバイダ。
 *
 * local_h5plogger_log は userid・行動ログ(verb)・回答内容等(extra)を保存する。
 * データは常に cmid（H5Pアクティビティのコースモジュールid）に紐づくため、
 * コンテキストレベルは CONTEXT_MODULE として扱う。
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * 保存しているデータの説明を宣言する。
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_h5plogger_log',
            [
                'userid'      => 'privacy:metadata:local_h5plogger_log:userid',
                'cmid'        => 'privacy:metadata:local_h5plogger_log:cmid',
                'h5p_id'      => 'privacy:metadata:local_h5plogger_log:h5p_id',
                'attempt_id'  => 'privacy:metadata:local_h5plogger_log:attempt_id',
                'verb'        => 'privacy:metadata:local_h5plogger_log:verb',
                'extra'       => 'privacy:metadata:local_h5plogger_log:extra',
                'timecreated' => 'privacy:metadata:local_h5plogger_log:timecreated',
            ],
            'privacy:metadata:local_h5plogger_log'
        );

        return $collection;
    }

    /**
     * 指定ユーザーがログを持つ全コンテキスト（H5Pアクティビティのモジュールコンテキスト）を返す。
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {local_h5plogger_log} lg
                  JOIN {context} ctx ON ctx.contextlevel = :ctxlevel
                                     AND ctx.instanceid = lg.cmid
                 WHERE lg.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'ctxlevel' => CONTEXT_MODULE,
            'userid'   => $userid,
        ]);

        return $contextlist;
    }

    /**
     * 指定コンテキスト（H5Pアクティビティ）内でログを持つ全ユーザーを返す。
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $sql = "SELECT userid FROM {local_h5plogger_log} WHERE cmid = :cmid";
        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * 承認済みコンテキストリストに含まれるユーザーのデータをエクスポートする。
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $records = $DB->get_records(
                'local_h5plogger_log',
                ['cmid' => $context->instanceid, 'userid' => $user->id],
                'timecreated ASC'
            );

            if (!$records) {
                continue;
            }

            $interactions = [];
            foreach ($records as $record) {
                $interactions[] = [
                    'verb'        => $record->verb,
                    'h5p_id'      => $record->h5p_id,
                    'attempt_id'  => $record->attempt_id,
                    'extra'       => $record->extra,
                    'timecreated' => \core_privacy\local\request\transform::datetime($record->timecreated),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_h5plogger')],
                (object)['interactions' => $interactions]
            );
        }
    }

    /**
     * 指定コンテキスト内の全ユーザーのデータを削除する（活動削除時等）。
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $DB->delete_records('local_h5plogger_log', ['cmid' => $context->instanceid]);
    }

    /**
     * 承認済みコンテキストリストに含まれる、単一ユーザーのデータを削除する。
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $DB->delete_records('local_h5plogger_log', [
                'cmid'   => $context->instanceid,
                'userid' => $user->id,
            ]);
        }
    }

    /**
     * 承認済みユーザーリストに含まれる複数ユーザーのデータを、単一コンテキスト内で削除する。
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $inparams['cmid'] = $context->instanceid;

        $DB->delete_records_select(
            'local_h5plogger_log',
            "cmid = :cmid AND userid $insql",
            $inparams
        );
    }
}
