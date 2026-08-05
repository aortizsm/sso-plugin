<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Plugin upgrade steps are defined here.
 *
 * @package    local_simplesso
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_simplesso upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_simplesso_upgrade(int $oldversion): bool {
    return true;
}
