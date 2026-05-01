<?php

namespace App\Models;

use App\Models\CreditLog;
use App\Models\GoogleAccount;
use App\Models\Transaction;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

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
        'professional_headline',
        'ai_tone',
        'notifications_enabled',
        'username',
        'public_profile_enabled',
        'location',
        'linkedin_url',
        'github_url',
        'twitter_url',
        'portfolio_url',
        'profile_theme',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isPro(): bool
    {
        return $this->plan && $this->plan->price_usd > 0;
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

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function creditLogs()
    {
        return $this->hasMany(CreditLog::class);
    }

    public function logCreditChange($amount, $type, $description = null)
    {
        return $this->creditLogs()->create([
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
        ]);
    }

    public function hasCredits($amount): bool
    {
        // In free mode (billing disabled), all credit checks should pass.
        if (!Setting::getVal('billing_enabled', false)) {
            return true;
        }

        return ($this->credits ?? 0) >= $amount;
    }

    public function deductCredits($amount, $description = null): bool
    {
        // In free mode (billing disabled), skip all credit deductions.
        if (!Setting::getVal('billing_enabled', false)) {
            return true;
        }

        if (!$this->hasCredits($amount)) {
            return false;
        }

        $this->decrement('credits', $amount);
        $this->logCreditChange(-$amount, 'usage', $description);
        return true;
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
            'public_profile_enabled' => 'boolean',
        ];
    }
}
