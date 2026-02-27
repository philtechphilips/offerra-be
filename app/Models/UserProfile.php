<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'cv_filename',
        'cv_raw_text',
        'parsed_data',
        'parsed_at',
    ];

    protected $casts = [
        'parsed_data' => 'array',
        'parsed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
