<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LuponEvidence extends Model
{
    protected $table = 'lupon_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['visible_to_parties' => 'boolean', 'size' => 'integer'];
    }

    public function case()
    {
        return $this->belongsTo(LuponCase::class, 'case_id');
    }
}
