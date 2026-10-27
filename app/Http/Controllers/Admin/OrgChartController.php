<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesOrgChart;
use App\Http\Controllers\Controller;

class OrgChartController extends Controller
{
    use ManagesOrgChart;

    protected function portal(): string
    {
        return 'admin';
    }

    protected function photoRouteName(): string
    {
        return 'admin.org-chart.photo';
    }
}
