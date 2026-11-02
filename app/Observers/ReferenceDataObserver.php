<?php

namespace App\Observers;

use App\Models\Barangay;
use App\Models\Certificate;
use App\Models\CitizenCharterService;
use App\Models\CitizenCharterVersion;
use App\Models\OrgChartPosition;
use App\Models\Purok;
use App\Models\Sector;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

final class ReferenceDataObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        if ($model instanceof Sector) {
            Cache::forget('registration:sector-options');

            return;
        }

        Cache::forget('registration:barangay-purok-options');
        Cache::forget('registration:barangay-purok-options:v2');

        if ($model instanceof Barangay) {
            Cache::forget('auth:active-barangay-options');
            Cache::forget('auth:resident-barangay-options');
            Cache::forget('reference:barangay:'.$model->id);
            Cache::forget('settings:barangay-name:'.$model->id);

            return;
        }

        if ($model instanceof Certificate || $model instanceof OrgChartPosition
            || $model instanceof CitizenCharterService || $model instanceof CitizenCharterVersion) {
            foreach (array_unique([$model->barangay_id, $model->getOriginal('barangay_id')]) as $barangayId) {
                foreach (['clerk:certificate-options:', 'resident:certificate-options:', 'clerk:certificate-signatories:', 'resident:citizen-charter:categories:'] as $prefix) {
                    Cache::forget($prefix.$barangayId);
                }
            }

            return;
        }

        if ($model instanceof Purok) {
            foreach (array_unique([$model->barangay_id, $model->getOriginal('barangay_id')]) as $barangayId) {
                foreach (['population:puroks:', 'admin:purok-options:v2:', 'sector:purok-options:', 'senior:purok-options:', 'pwd:purok-options:'] as $prefix) {
                    Cache::forget($prefix.$barangayId);
                }
            }
        }
    }
}
