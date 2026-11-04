<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResidentInboxNotification extends Model
{
    protected $table = 'resident_notifications';

    protected $fillable = ['resident_id', 'barangay_id', 'event_key', 'title', 'message', 'url', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
