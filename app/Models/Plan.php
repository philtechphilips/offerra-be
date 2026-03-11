<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'price_usd', 'price_ngn', 'description', 
        'features', 'not_included', 'is_popular', 'is_active', 'btn_text'
    ];

    protected $casts = [
        'features' => 'array',
        'not_included' => 'array',
        'is_popular' => 'boolean',
        'is_active' => 'boolean',
        'price_usd' => 'decimal:2',
        'price_ngn' => 'decimal:2',
    ];
}
