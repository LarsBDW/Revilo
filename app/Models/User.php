<?php

namespace App\Models;

use App\Services\EmailService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['active' => true, 'role' => 'customer'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function isAdmin(): bool
    {
        return $this->active && $this->role === 'admin';
    }

    public function sendEmailVerificationNotification(): void
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['locale' => app()->getLocale(), 'id' => $this->id, 'hash' => sha1($this->email)]);
        app(EmailService::class)->record('verify-'.$this->id.'-'.now()->format('YmdHi'), $this->email, __('shop.verify_email'), __('shop.verification_email')."\n".$url, app()->getLocale());
    }

    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', ['locale' => app()->getLocale(), 'token' => $token, 'email' => $this->email]);
        app(EmailService::class)->record('reset-'.hash('sha256', $token), $this->email, __('shop.reset_password'), __('shop.reset_email')."\n".$url, app()->getLocale());
    }
}
