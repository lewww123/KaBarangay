<?php

namespace App\Http\Controllers\Clerk;

use App\Http\Controllers\Concerns\ManagesOrgChart;
use App\Http\Controllers\Controller;

class OrgChartController extends Controller
{
    use ManagesOrgChart;

    protected function portal(): string
    {
        return 'clerk';
    }

    protected function photoRouteName(): string
    {
        return 'clerk.org-chart.photo';
    }
}
