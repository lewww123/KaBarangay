<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LuponCaseEvent extends Model
{
    protected $table = 'lupon_case_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function case()
    {
        return $this->belongsTo(LuponCase::class, 'case_id');
    }
}
