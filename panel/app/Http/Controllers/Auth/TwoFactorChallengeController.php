<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->user()?->two_factor_confirmed_at) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user?->two_factor_confirmed_at) {
            return redirect()->route('dashboard');
        }

        $throttleKey = sprintf('2fa:%s|%s', $user->id, $request->ip() ?: 'unknown');

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'code' => "Too many verification attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $code     = $request->input('code', '');
        $recovery = $request->input('recovery_code', '');

        // Try TOTP code first
        if ($code) {
            $google2fa = new Google2FA();
            $secret    = decrypt($user->two_factor_secret);

            if ($google2fa->verifyKey($secret, preg_replace('/\s+/', '', $code))) {
                RateLimiter::clear($throttleKey);
                $request->session()->put('auth.two_factor_confirmed', true);
                return redirect()->intended(route('dashboard'));
            }
        }

        // Try recovery code
        if ($recovery) {
            $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            $key   = array_search(trim($recovery), $codes, true);

            if ($key !== false) {
                // Consume the code
                unset($codes[$key]);
                $user->update([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
                ]);

                RateLimiter::clear($throttleKey);
                $request->session()->put('auth.two_factor_confirmed', true);
                return redirect()->intended(route('dashboard'));
            }
        }

        RateLimiter::hit($throttleKey, 300);
        return back()->withErrors(['code' => 'The provided code was invalid.']);
    }
}
