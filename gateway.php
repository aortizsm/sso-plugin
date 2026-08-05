<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Gateway create/edit page.
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

$recordid = optional_param('id', 0, PARAM_INT);
$editrecord = null;
if ($recordid > 0) {
    $editrecord = $DB->get_record('local_simplesso_gateway', ['id' => $recordid], '*', MUST_EXIST);
}

if (optional_param('savegateway', 0, PARAM_BOOL)) {
    require_sesskey();

    $recordid = optional_param('recordid', 0, PARAM_INT);
    $appid = optional_param('appid', '', PARAM_ALPHANUMEXT);
    $name = trim(optional_param('name', '', PARAM_TEXT));
    $endpoint = trim(optional_param('endpoint', '', PARAM_RAW_TRIMMED));
    $sharedsecret = trim(optional_param('sharedsecret', '', PARAM_RAW_TRIMMED));
    $active = optional_param('active', 0, PARAM_BOOL) ? 1 : 0;

    $validendpoint = local_simplesso_normalize_endpoint($endpoint);

    if ($appid === '' || $name === '' || $validendpoint === null || $sharedsecret === '') {
        \core\notification::error(get_string('missingfields', 'local_simplesso'));
        redirect(new moodle_url('/local/simplesso/gateway.php', ['id' => $recordid]));
    }

    if ($recordid > 0) {
        $duplicateexists = $DB->record_exists_select(
            'local_simplesso_gateway',
            'appid = :appid AND id <> :id',
            ['appid' => $appid, 'id' => $recordid]
        );
    } else {
        $duplicateexists = $DB->record_exists('local_simplesso_gateway', ['appid' => $appid]);
    }

    if ($duplicateexists) {
        \core\notification::error(get_string('appidtaken', 'local_simplesso'));
        redirect(new moodle_url('/local/simplesso/gateway.php', ['id' => $recordid]));
    }

    $now = time();
    $record = (object)[
        'appid' => $appid,
        'name' => $name,
        'endpoint' => $validendpoint,
        'sharedsecret' => local_simplesso_encrypt_secret($sharedsecret),
        'active' => $active,
        'timemodified' => $now,
    ];

    if ($recordid > 0) {
        $record->id = $recordid;
        $existing = $DB->get_record('local_simplesso_gateway', ['id' => $recordid], '*', MUST_EXIST);
        if ($sharedsecret === '********') {
            // Keep the stored encrypted secret unchanged.
            $record->sharedsecret = $existing->sharedsecret;
        }
        $DB->update_record('local_simplesso_gateway', $record);
    } else {
        $record->timecreated = $now;
        $recordid = $DB->insert_record('local_simplesso_gateway', $record);
    }

    \core\notification::success(get_string('gatewaysaved', 'local_simplesso'));
    redirect(new moodle_url('/local/simplesso/index.php'));
}

$PAGE->set_url(new moodle_url('/local/simplesso/gateway.php', ['id' => $recordid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('managegateways', 'local_simplesso'));
$PAGE->set_heading(get_string('managegateways', 'local_simplesso'));

echo $OUTPUT->header();
echo $OUTPUT->heading($editrecord ? get_string('editgateway', 'local_simplesso') : get_string('addgateway', 'local_simplesso'));

echo html_writer::div(
    html_writer::link(new moodle_url('/local/simplesso/index.php'), get_string('gatewaylist', 'local_simplesso')),
    'mb-3'
);

echo html_writer::start_div('card p-3 mb-3');

echo html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url('/local/simplesso/gateway.php'))->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savegateway', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'recordid', 'value' => $editrecord ? $editrecord->id : 0]);

echo html_writer::start_div('mb-2');
echo html_writer::tag('label', get_string('appid', 'local_simplesso'), ['for' => 'id_appid']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'id_appid',
    'name' => 'appid',
    'value' => $editrecord ? s($editrecord->appid) : '',
    'class' => 'form-control',
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('mb-2');
echo html_writer::tag('label', get_string('name', 'local_simplesso'), ['for' => 'id_name']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'id_name',
    'name' => 'name',
    'value' => $editrecord ? s($editrecord->name) : '',
    'class' => 'form-control',
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('mb-2');
echo html_writer::tag('label', get_string('endpoint', 'local_simplesso'), ['for' => 'id_endpoint']);
echo html_writer::empty_tag('input', [
    'type' => 'url',
    'id' => 'id_endpoint',
    'name' => 'endpoint',
    'value' => $editrecord ? s($editrecord->endpoint) : '',
    'class' => 'form-control',
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('mb-2');
echo html_writer::tag('label', get_string('sharedsecret', 'local_simplesso'), ['for' => 'id_sharedsecret']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'id_sharedsecret',
    'name' => 'sharedsecret',
    'value' => $editrecord ? '********' : '',
    'class' => 'form-control',
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('mb-2 form-check');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'id' => 'id_active',
    'name' => 'active',
    'value' => 1,
    'checked' => (!$editrecord || (int)$editrecord->active === 1) ? 'checked' : null,
    'class' => 'form-check-input',
]);
echo html_writer::tag('label', get_string('active', 'local_simplesso'), ['for' => 'id_active', 'class' => 'form-check-label']);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('savechanges'),
    'class' => 'btn btn-primary',
]);

echo html_writer::end_tag('form');
echo html_writer::end_div();

echo $OUTPUT->footer();
