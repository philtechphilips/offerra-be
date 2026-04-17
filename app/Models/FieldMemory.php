<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FieldMemory extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'user_id', 'field_name', 'field_value'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
