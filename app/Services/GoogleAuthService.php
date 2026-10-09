<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Two\User as GoogleUser;

class GoogleAuthService
{
    public function identity(GoogleUser $profile): array
    {
        $raw = $profile->getRaw();
        $id = (string) $profile->getId();
        $email = Str::lower(trim((string) $profile->getEmail()));
        if (($raw['email_verified'] ?? false) !== true || $id === '' || strlen($id) > 255 || strlen($email) > 190 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => __('shop.google_unverified')]);
        }

        return ['id' => $id, 'email' => $email, 'name' => Str::limit(trim((string) $profile->getName()) ?: __('shop.google_account'), 120, '')];
    }

    public function resolve(array $identity, ?User $linking = null): ?User
    {
        return DB::transaction(function () use ($identity, $linking) {
            $bound = User::where('google_id', $identity['id'])->lockForUpdate()->first();
            if ($linking) {
                $account = User::whereKey($linking->id)->lockForUpdate()->firstOrFail();
                $this->ensureActive($account);
                if (! hash_equals(Str::lower($account->email), $identity['email']) || ($bound && $bound->id !== $account->id) || ($account->google_id && $account->google_id !== $identity['id'])) {
                    throw ValidationException::withMessages(['email' => __('shop.google_link_mismatch')]);
                }
                $account->forceFill(['google_id' => $identity['id'], 'email_verified_at' => $account->email_verified_at ?? now()])->save();

                return $account;
            }
            if ($bound) {
                $this->ensureActive($bound);

                return $bound;
            }
            if (User::whereRaw('LOWER(email) = ?', [$identity['email']])->exists()) {
                throw ValidationException::withMessages(['email' => __('shop.google_link_password')]);
            }

            return null;
        }, 3);
    }

    public function register(array $identity): User
    {
        try {
            return DB::transaction(function () use ($identity) {
                $existing = $this->resolve($identity);
                if ($existing) {
                    return $existing;
                }
                $user = new User(['name' => $identity['name'], 'email' => $identity['email'], 'password' => Str::random(64)]);
                $user->forceFill(['google_id' => $identity['id'], 'email_verified_at' => now(), 'terms_accepted_at' => now()])->save();

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Another callback may have completed this identity while this request waited.
            return $this->resolve($identity) ?? throw ValidationException::withMessages(['email' => __('shop.google_failed')]);
        }
    }

    private function ensureActive(User $user): void
    {
        if (! $user->active) {
            throw ValidationException::withMessages(['email' => __('shop.google_disabled')]);
        }
    }
}
