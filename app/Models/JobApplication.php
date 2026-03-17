<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class JobApplication extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'user_id', 'title', 'company', 'location', 'type', 'is_remote', 
        'salary', 'job_url', 'company_url', 'contact_info', 
        'summary', 'tech_stack', 'status',
        'cv_match_score', 'cv_match_details'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected $casts = [
        'tech_stack' => 'array',
        'cv_match_details' => 'array',
        'is_remote' => 'boolean',
    ];
}
