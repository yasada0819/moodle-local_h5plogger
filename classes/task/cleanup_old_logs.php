<?php
namespace local_h5plogger\task;

/**
 * retention_days 設定に基づき、古いログを削除するスケジュールタスク。
 * retention_days が 0（デフォルト）の場合は無期限保持として何もしない。
 */
class cleanup_old_logs extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_cleanup_old_logs', 'local_h5plogger');
    }

    public function execute() {
        global $DB;

        $days = (int)get_config('local_h5plogger', 'retention_days');
        if ($days <= 0) {
            return; // retention無効（無期限保持）
        }

        $threshold = time() - ($days * DAYSECS);

        $DB->delete_records_select(
            'local_h5plogger_log',
            'timecreated < :threshold',
            ['threshold' => $threshold]
        );
    }
}
