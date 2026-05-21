<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * SSO launch endpoint.
 *
 * @package    local_devlearn
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once(__DIR__ . '/locallib.php');

global $USER, $DB;

require_login();

$appid    = required_param('appid', PARAM_ALPHANUMEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);

/** @var context $context */
$context = context_system::instance();

if (!has_capability('local/devlearn:usegateway', $context)) {
    local_devlearn_write_log((int)$USER->id, $appid, false, 'no_capability');
    throw new moodle_exception('launchnotallowed', 'local_devlearn');
}

$gateway = local_devlearn_get_gateway_by_appid($appid, true);
if (!$gateway) {
    local_devlearn_write_log((int)$USER->id, $appid, false, 'gateway_not_found');
    throw new moodle_exception('invalidgateway', 'local_devlearn');
}

// Load course data when a courseid is supplied and the user is enrolled.
$course = null;
if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
    if ($course && !is_enrolled(context_course::instance($courseid), $USER, '', true)) {
        $course = null; // do not expose course data the user is not enrolled in
    }
}

try {
    $launch = local_devlearn_get_launch_params($gateway, $USER, $course);
} catch (Exception $e) {
    local_devlearn_write_log((int)$USER->id, $appid, false, 'signing_error');
    throw new moodle_exception('launchfailed', 'local_devlearn');
}

local_devlearn_write_log((int)$USER->id, $appid, true, '', $launch['nonce']);

// Emit a minimal self-submitting POST form. Data travels in the request body,
// never in the URL — same pattern as SAML and LTI.
$endpoint  = htmlspecialchars($launch['endpoint'],  ENT_QUOTES, 'UTF-8');
$payload   = htmlspecialchars($launch['payload'],   ENT_QUOTES, 'UTF-8');
$signature = htmlspecialchars($launch['signature'], ENT_QUOTES, 'UTF-8');
$redirectmsg = get_string('redirecting', 'local_devlearn');

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{$redirectmsg}</title>
  <style>
    body { font-family: sans-serif; display: flex; align-items: center;
           justify-content: center; height: 100vh; margin: 0; background: #f5f5f5; }
    .box { text-align: center; color: #555; }
  </style>
</head>
<body>
  <div class="box">
    <p>{$redirectmsg}&hellip;</p>
    <form id="sso" method="post" action="{$endpoint}">
      <input type="hidden" name="payload"   value="{$payload}">
      <input type="hidden" name="signature" value="{$signature}">
      <noscript>
        <button type="submit">Continuar &rarr;</button>
      </noscript>
    </form>
  </div>
  <script>
    window.onload = function () { document.getElementById('sso').submit(); };
  </script>
</body>
</html>
HTML;
exit;
