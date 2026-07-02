<?php
// This file is part of Moodle - http://moodle.org/
defined('MOODLE_INTERNAL') || die();

$string['pluginname']            = 'H5P Interaction Logger';
$string['log_preview_mode']      = 'Log teacher/admin interactions';
$string['log_preview_mode_desc'] = 'If enabled, interactions by users with course management capabilities are also logged.';
$string['save_classes']          = 'Save CSS classes in button click logs';
$string['save_classes_desc']     = 'If enabled, the full CSS class string of clicked elements is saved in the extra field. Useful for debugging selector issues.';
$string['retention_days']        = 'Log retention period (days)';
$string['retention_days_desc']   = 'Logs older than this many days will be automatically deleted by a scheduled task. Set to 0 to keep logs indefinitely.';
$string['task_cleanup_old_logs'] = 'Delete old H5P interaction logs';

// Privacy API (GDPR)
$string['privacy:metadata:local_h5plogger_log']             = 'Stores learner interaction events captured from H5P activities (xAPI events, DOM clicks, and video playback events).';
$string['privacy:metadata:local_h5plogger_log:userid']      = 'The ID of the user who performed the interaction.';
$string['privacy:metadata:local_h5plogger_log:cmid']        = 'The course module ID of the H5P activity.';
$string['privacy:metadata:local_h5plogger_log:h5p_id']      = 'The H5P content ID (xAPI h5p-local-content-id).';
$string['privacy:metadata:local_h5plogger_log:attempt_id']  = 'The ID of the related H5P activity attempt, used as a session boundary.';
$string['privacy:metadata:local_h5plogger_log:verb']        = 'The type of interaction (e.g. progressed, answered, button_clicked).';
$string['privacy:metadata:local_h5plogger_log:extra']       = 'Verb-specific details of the interaction, such as slide numbers, answers, or button labels.';
$string['privacy:metadata:local_h5plogger_log:timecreated'] = 'The time the interaction was recorded.';
