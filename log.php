<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * SSO audit log viewer.
 *
 * @package    local_devlearn
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/locallib.php');

global $DB, $PAGE, $OUTPUT;

admin_externalpage_setup('local_devlearn_gateways');

/** @var context $context */
$context = context_system::instance();
require_capability('local/devlearn:managegateways', $context);

$PAGE->set_url(new moodle_url('/local/devlearn/log.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('logtitle', 'local_devlearn'));
$PAGE->set_heading(get_string('logtitle', 'local_devlearn'));

// Filters.
$filterappid = optional_param('filterappid', '', PARAM_ALPHANUMEXT);
$filtersuccess = optional_param('filtersuccess', -1, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$conditions = [];
$params = [];

if ($filterappid !== '') {
    $conditions[] = 'appid = :appid';
    $params['appid'] = $filterappid;
}

if ($filtersuccess === 0 || $filtersuccess === 1) {
    $conditions[] = 'success = :success';
    $params['success'] = $filtersuccess;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$sql = "SELECT l.id, l.userid, l.appid, l.success, l.reason, l.nonce, l.ip, l.timecreated,
               " . $DB->sql_concat('u.firstname', "' '", 'u.lastname') . " AS fullname, u.username
          FROM {local_devlearn_log} l
     LEFT JOIN {user} u ON u.id = l.userid
               $where
      ORDER BY l.timecreated DESC";

$total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_devlearn_log} l $where", $params);
$records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
$appids = $DB->get_records_menu('local_devlearn_gateway', null, 'appid ASC', 'id, appid');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('logtitle', 'local_devlearn'));

// Filter form.
$filterurl = new moodle_url('/local/devlearn/log.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filterurl->out(false), 'class' => 'form-inline mb-3']);

echo html_writer::tag('label', get_string('appid', 'local_devlearn') . ':&nbsp;', ['for' => 'filterappid']);
$appoptions = ['' => get_string('all')];
foreach ($appids as $aid) {
    $appoptions[$aid] = $aid;
}
echo html_writer::select($appoptions, 'filterappid', $filterappid, false, ['id' => 'filterappid', 'class' => 'form-control mr-2']);

echo '&nbsp;';
echo html_writer::tag('label', get_string('logstatus', 'local_devlearn') . ':&nbsp;', ['for' => 'filtersuccess']);
$statusoptions = [-1 => get_string('all'), 1 => get_string('logsuccess', 'local_devlearn'), 0 => get_string('logfail', 'local_devlearn')];
echo html_writer::select($statusoptions, 'filtersuccess', $filtersuccess, false, ['id' => 'filtersuccess', 'class' => 'form-control mr-2']);

echo '&nbsp;';
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'), 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if (!$records) {
    echo $OUTPUT->notification(get_string('lognone', 'local_devlearn'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
        get_string('date'),
        get_string('user'),
        get_string('appid', 'local_devlearn'),
        get_string('logstatus', 'local_devlearn'),
        get_string('reason', 'local_devlearn'),
        'IP',
    ];

    foreach ($records as $r) {
        $statuslabel = ((int)$r->success === 1)
            ? html_writer::tag('span', get_string('logsuccess', 'local_devlearn'), ['class' => 'badge badge-success'])
            : html_writer::tag('span', get_string('logfail', 'local_devlearn'), ['class' => 'badge badge-danger']);

        $userlink = html_writer::link(
            new moodle_url('/user/view.php', ['id' => $r->userid]),
            s($r->fullname ?: $r->username ?: '#' . $r->userid)
        );

        $table->data[] = [
            userdate((int)$r->timecreated),
            $userlink,
            s($r->appid),
            $statuslabel,
            s($r->reason),
            s($r->ip),
        ];
    }

    echo html_writer::table($table);

    $pageurl = new moodle_url('/local/devlearn/log.php', ['filterappid' => $filterappid, 'filtersuccess' => $filtersuccess]);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
}

echo html_writer::div(
    html_writer::link(new moodle_url('/local/devlearn/index.php'), '&larr; ' . get_string('managegateways', 'local_devlearn')),
    'mt-3'
);

echo $OUTPUT->footer();
