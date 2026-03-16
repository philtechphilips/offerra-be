<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    const ROLE_USER = 'user';
    const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'plan_id',
        'credits',
        'subscription_provider',
        'subscription_id',
        'subscription_status',
        'subscription_ends_at',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function jobApplications()
    {
        return $this->hasMany(JobApplication::class);
    }

    public function cvs()
    {
        return $this->hasMany(UserProfile::class);
    }

    public function googleAccount()
    {
        return $this->hasOne(GoogleAccount::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $with = ['googleAccount'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'credits' => 'integer',
        ];
    }
}
