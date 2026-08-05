<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Local helper functions for Simple SSO.
 *
 * @package    local_simplesso
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Fetch a gateway by app id.
 *
 * @param string $appid
 * @param bool $activeonly
 * @return stdClass|null
 */
function local_simplesso_get_gateway_by_appid(string $appid, bool $activeonly = true): ?stdClass {
    global $DB;

    $appid = clean_param($appid, PARAM_ALPHANUMEXT);
    if ($appid === '') {
        return null;
    }

    $conditions = ['appid' => $appid];
    if ($activeonly) {
        $conditions['active'] = 1;
    }

    $record = $DB->get_record('local_simplesso_gateway', $conditions);
    return $record ?: null;
}

/**
 * Build the user payload used for SSO handoff.
 *
 * @param stdClass $user
 * @param string $appid
 * @param stdClass|null $course  Optional course the user is launching from.
 * @return array
 */
function local_simplesso_build_payload(stdClass $user, string $appid, ?stdClass $course = null): array {
    $payload = [
        'userid'    => (int)$user->id,
        'username'  => (string)$user->username,
        'email'     => (string)$user->email,
        'firstname' => (string)$user->firstname,
        'lastname'  => (string)$user->lastname,
        'appid'     => (string)$appid,
        'iat'       => time(),
        'nonce'     => local_simplesso_generate_nonce(),
        'course'    => null,
    ];

    if ($course !== null) {
        $payload['course'] = [
            'id'        => (int)$course->id,
            'shortname' => (string)$course->shortname,
            'fullname'  => (string)$course->fullname,
            'idnumber'  => (string)$course->idnumber,
            'category'  => (int)$course->category,
            'startdate' => (int)$course->startdate,
            'enddate'   => (int)$course->enddate,
        ];
    }

    return $payload;
}

/**
 * Encode payload as JSON.
 *
 * @param array $payload
 * @return string
 */
function local_simplesso_payload_json(array $payload): string {
    $json = json_encode($payload);
    if ($json === false) {
        throw new coding_exception('Unable to JSON encode payload in local_simplesso_payload_json().');
    }

    return $json;
}

/**
 * Create an HMAC signature for a payload JSON.
 *
 * @param string $payloadjson
 * @param string $secret
 * @return string
 */
function local_simplesso_sign_payload(string $payloadjson, string $secret): string {
    return hash_hmac('sha256', $payloadjson, $secret);
}

/**
 * URL-safe base64 encoder (RFC 4648 section 5).
 *
 * @param string $data
 * @return string
 */
function local_simplesso_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Encrypt a secret for storage using the Moodle site key as master key.
 *
 * Uses AES-256-CBC. The returned value is base64(iv + ciphertext) so it can
 * be stored in a TEXT column safely.
 *
 * @param string $plain
 * @return string
 */
function local_simplesso_encrypt_secret(string $plain): string {
    global $CFG;

    $salt = !empty($CFG->passwordsaltmain) ? $CFG->passwordsaltmain : ($CFG->wwwroot . $CFG->dataroot);
    $key = substr(hash('sha256', $salt . 'local_simplesso_secret', true), 0, 32);
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        throw new coding_exception('local_simplesso_encrypt_secret: openssl_encrypt failed.');
    }

    return base64_encode($iv . $ciphertext);
}

/**
 * Decrypt a secret stored by local_simplesso_encrypt_secret().
 *
 * @param string $stored Base64-encoded iv + ciphertext
 * @return string Plaintext secret
 */
function local_simplesso_decrypt_secret(string $stored): string {
    global $CFG;

    $salt = !empty($CFG->passwordsaltmain) ? $CFG->passwordsaltmain : ($CFG->wwwroot . $CFG->dataroot);
    $key = substr(hash('sha256', $salt . 'local_simplesso_secret', true), 0, 32);
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= 16) {
        throw new coding_exception('local_simplesso_decrypt_secret: invalid ciphertext.');
    }

    $iv = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);
    $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        throw new coding_exception('local_simplesso_decrypt_secret: decryption failed.');
    }

    return $plain;
}

/**
 * Build the POST parameters needed to hand off the SSO session to an external app.
 *
 * Returns the endpoint and the two POST fields (`payload` and `signature`) so the
 * caller can render a self-submitting HTML form. Data never travels in a URL.
 *
 * The gateway sharedsecret field is stored encrypted; this function decrypts it
 * before signing to keep the plaintext secret off-disk.
 *
 * @param stdClass      $gateway
 * @param stdClass      $user
 * @param stdClass|null $course  Optional course context to include in payload.
 * @return array{endpoint: string, payload: string, signature: string, nonce: string}
 */
function local_simplesso_get_launch_params(stdClass $gateway, stdClass $user, ?stdClass $course = null): array {
    $secret      = local_simplesso_decrypt_secret((string)$gateway->sharedsecret);
    $payload     = local_simplesso_build_payload($user, $gateway->appid, $course);
    $payloadjson = local_simplesso_payload_json($payload);

    return [
        'endpoint'  => (string)$gateway->endpoint,
        'payload'   => local_simplesso_base64url_encode($payloadjson),
        'signature' => local_simplesso_sign_payload($payloadjson, $secret),
        'nonce'     => (string)$payload['nonce'],
    ];
}

/**
 * Write one entry to the SSO audit log.
 *
 * @param int $userid
 * @param string $appid
 * @param bool $success
 * @param string $reason  Short description when not successful, empty on success.
 * @param string $nonce   Token nonce (empty if launch failed before nonce generation).
 * @return void
 */
function local_simplesso_write_log(int $userid, string $appid, bool $success, string $reason = '', string $nonce = ''): void {
    global $DB;

    $ip = getremoteaddr();

    $record = (object)[
        'userid' => $userid,
        'appid' => clean_param($appid, PARAM_ALPHANUMEXT),
        'success' => (int)$success,
        'reason' => substr(clean_param($reason, PARAM_TEXT), 0, 255),
        'nonce' => substr($nonce, 0, 64),
        'ip' => substr((string)$ip, 0, 45),
        'timecreated' => time(),
    ];

    $DB->insert_record('local_simplesso_log', $record, false);
}

/**
 * Validate and normalize a gateway endpoint URL.
 *
 * @param string $endpoint
 * @return string|null
 */
function local_simplesso_normalize_endpoint(string $endpoint): ?string {
    $endpoint = trim($endpoint);
    $cleanendpoint = clean_param($endpoint, PARAM_URL);

    if ($cleanendpoint === '' || $cleanendpoint !== $endpoint) {
        return null;
    }

    $parts = parse_url($cleanendpoint);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    return $cleanendpoint;
}

/**
 * Generate a nonce for replay-protection in the target application.
 *
 * @return string
 */
function local_simplesso_generate_nonce(): string {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $exception) {
        return sha1((string)microtime(true) . random_int(1, PHP_INT_MAX));
    }
}
