<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Plan extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'name', 'slug', 'price_usd', 'price_ngn', 'credits', 'description', 
        'features', 'not_included', 'is_popular', 'is_active', 'btn_text',
        'polar_product_id', 'paystack_plan_id'
    ];

    protected $casts = [
        'features' => 'array',
        'not_included' => 'array',
        'is_popular' => 'boolean',
        'is_active' => 'boolean',
        'price_usd' => 'decimal:2',
        'price_ngn' => 'decimal:2',
        'credits' => 'integer',
    ];
}
