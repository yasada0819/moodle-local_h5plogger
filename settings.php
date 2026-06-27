<?php
// This file is part of Moodle - http://moodle.org/
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_h5plogger',
        get_string('pluginname', 'local_h5plogger')
    );

    $ADMIN->add('localplugins', $settings);

    // プレビューモード（教師・管理者）のログを残すか否か
    $settings->add(new admin_setting_configcheckbox(
        'local_h5plogger/log_preview_mode',
        get_string('log_preview_mode', 'local_h5plogger'),
        get_string('log_preview_mode_desc', 'local_h5plogger'),
        0  // デフォルト：残さない
    ));

    // ボタンクリックログに CSS クラスを含めるか否か
    $settings->add(new admin_setting_configcheckbox(
        'local_h5plogger/save_classes',
        get_string('save_classes', 'local_h5plogger'),
        get_string('save_classes_desc', 'local_h5plogger'),
        0  // デフォルト：含めない
    ));
}
