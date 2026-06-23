<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_h5plogger_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026062301) {
        $table = new xmldb_table('local_h5plogger_log');

        // cmid
        $field = new xmldb_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // h5pactivity_id
        $field = new xmldb_field('h5pactivity_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'h5p_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // attempt_id
        $field = new xmldb_field('attempt_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'h5pactivity_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // インデックス追加
        $index = new xmldb_index('cmid', XMLDB_INDEX_NOTUNIQUE, ['cmid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('attempt_id', XMLDB_INDEX_NOTUNIQUE, ['attempt_id']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026062301, 'local', 'h5plogger');
    }

    return true;
}
