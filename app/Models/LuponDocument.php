<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LuponDocument extends Model
{
    protected $table = 'lupon_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['signatories' => 'array', 'generated_at' => 'datetime', 'served_at' => 'datetime'];
    }

    public function case()
    {
        return $this->belongsTo(LuponCase::class, 'case_id');
    }
}
