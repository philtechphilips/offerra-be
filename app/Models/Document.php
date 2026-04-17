<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Document extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'user_id', 'name', 'file_path', 'signed_path', 'status', 'signed_at', 'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'signed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
