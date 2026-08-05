<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Gateway management page.
 *
 * @package    local_simplesso
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/locallib.php');

global $DB, $PAGE, $OUTPUT;

admin_externalpage_setup('local_simplesso_gateways');

/** @var context $context */
$context = context_system::instance();
require_capability('local/simplesso:managegateways', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'delete' && $id > 0) {
    require_sesskey();
    $DB->delete_records('local_simplesso_gateway', ['id' => $id]);
    \core\notification::success(get_string('gatewaydeleted', 'local_simplesso'));
    redirect(new moodle_url('/local/simplesso/index.php'));
}

if ($action === 'toggle' && $id > 0) {
    require_sesskey();
    if ($record = $DB->get_record('local_simplesso_gateway', ['id' => $id], '*', IGNORE_MISSING)) {
        $record->active = (int)!((int)$record->active);
        $record->timemodified = time();
        $DB->update_record('local_simplesso_gateway', $record);
        \core\notification::success(get_string('gatewaytoggled', 'local_simplesso'));
        redirect(new moodle_url('/local/simplesso/index.php'));
    }
}

$gateways = $DB->get_records('local_simplesso_gateway', null, 'name ASC');

$PAGE->set_url(new moodle_url('/local/simplesso/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('managegateways', 'local_simplesso'));
$PAGE->set_heading(get_string('managegateways', 'local_simplesso'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managegateways', 'local_simplesso'));
echo html_writer::div(
    html_writer::link(new moodle_url('/local/simplesso/gateway.php'), get_string('addgateway', 'local_simplesso')) .
    ' | ' .
    html_writer::link(new moodle_url('/local/simplesso/log.php'), get_string('viewlog', 'local_simplesso')),
    'mb-3'
);

echo html_writer::tag('h4', get_string('gatewaylist', 'local_simplesso'));

if (!$gateways) {
    echo $OUTPUT->notification(get_string('nogateways', 'local_simplesso'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
        get_string('appid', 'local_simplesso'),
        get_string('name', 'local_simplesso'),
        get_string('endpoint', 'local_simplesso'),
        get_string('active', 'local_simplesso'),
        get_string('actions', 'local_simplesso'),
    ];

    foreach ($gateways as $gateway) {
        $toggleurl = new moodle_url('/local/simplesso/index.php', [
            'action' => 'toggle',
            'id' => $gateway->id,
            'sesskey' => sesskey(),
        ]);
        $deleteurl = new moodle_url('/local/simplesso/index.php', [
            'action' => 'delete',
            'id' => $gateway->id,
            'sesskey' => sesskey(),
        ]);
        $editurl = new moodle_url('/local/simplesso/gateway.php', ['id' => $gateway->id]);
        $launchurl = new moodle_url('/local/simplesso/launch.php', ['appid' => $gateway->appid]);

        $activevalue = ((int)$gateway->active === 1) ? get_string('yes') : get_string('no');
        $togglelabel = ((int)$gateway->active === 1)
            ? get_string('deactivategateway', 'local_simplesso')
            : get_string('activategateway', 'local_simplesso');

        $actions = [
            html_writer::link($editurl, get_string('edit')),
            html_writer::link($toggleurl, $togglelabel),
            html_writer::link($deleteurl, get_string('delete')),
            html_writer::link($launchurl, get_string('openlaunch', 'local_simplesso')),
        ];

        $table->data[] = [
            format_string($gateway->appid),
            format_string($gateway->name),
            s($gateway->endpoint),
            $activevalue,
            implode(' | ', $actions),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
