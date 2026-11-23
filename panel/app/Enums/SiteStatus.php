<?php

namespace App\Enums;

/**
 * State of a website. The values match the keys in lang/{de,en}/status.php
 * and <x-status-badge>.
 *
 * pending:  site-create is queued or running
 * active:   site-create succeeded
 * failed:   the last site-create or site-delete failed, see its task
 * deleting: site-delete is queued or running; on success the row is removed
 *
 * "suspended" follows with site-suspend in milestone 7.
 */
enum SiteStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Deleting = 'deleting';
}
