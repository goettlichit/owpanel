<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\SiteLogController as AdminSiteLogController;

/**
 * Access and error log of a website in the customer area (E-124, E-143).
 * Only the page "show"; the tasks stay with the administration.
 */
class SiteLogController extends AdminSiteLogController
{
    protected string $area = 'my.sites.';
}
