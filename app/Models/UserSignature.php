<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UserSignature extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'signature_data',
        'type',
        'is_default'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
