<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\SiteSftpController as AdminSiteSftpController;

/**
 * SFTP of a website in the customer area (E-124, E-143): the same actions
 * as in the administration, checked by the same policy (own website only),
 * on pages of the customer area.
 */
class SiteSftpController extends AdminSiteSftpController
{
    protected string $area = 'my.sites.';
}
