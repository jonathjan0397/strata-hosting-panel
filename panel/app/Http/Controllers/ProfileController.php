<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Profile/Security', [
            'twoFactorEnabled'   => (bool) $request->user()->two_factor_confirmed_at,
            'twoFactorSetupMode' => $request->user()->two_factor_secret && ! $request->user()->two_factor_confirmed_at,
            'qrCodeSvg'          => $this->qrCodeSvg($request->user()),
            'recoveryCodes'      => $this->recoveryCodes($request->user()),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        AuditLog::record('profile.password_changed', $request->user());

        return back()->with('success', 'Password updated.');
    }

    // ── 2FA Setup ─────────────────────────────────────────────────────────────

    public function enableTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->two_factor_confirmed_at) {
            return back()->with('error', '2FA is already enabled.');
        }

        $google2fa = new Google2FA();
        $secret    = $google2fa->generateSecretKey();

        $user->update([
            'two_factor_secret'         => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($this->generateRecoveryCodes())),
            'two_factor_confirmed_at'   => null,
        ]);

        return back()->with('success', 'Scan the QR code with your authenticator app, then confirm.');
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user      = $request->user();
        $google2fa = new Google2FA();
        $secret    = decrypt($user->two_factor_secret);

        if (! $google2fa->verifyKey($secret, preg_replace('/\s+/', '', $data['code']))) {
            return back()->withErrors(['code' => 'Invalid code. Try again.']);
        }

        $user->update(['two_factor_confirmed_at' => now()]);

        // Mark session as already verified
        $request->session()->put('auth.two_factor_confirmed', true);

        AuditLog::record('profile.2fa_enabled', $user);

        return back()->with('success', 'Two-factor authentication enabled. Save your recovery codes.');
    }

    public function cancelTwoFactorSetup(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Only allowed when setup is pending (secret exists but not confirmed)
        if ($user->two_factor_confirmed_at) {
            return back()->with('error', '2FA is already active. Use disable instead.');
        }

        $user->update([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
        ]);

        return back()->with('success', '2FA setup cancelled.');
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $user->update([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ]);

        $request->session()->forget('auth.two_factor_confirmed');

        AuditLog::record('profile.2fa_disabled', $user);

        return back()->with('success', 'Two-factor authentication disabled.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->two_factor_confirmed_at) {
            return back()->with('error', 'Enable 2FA first.');
        }

        $user->update([
            'two_factor_recovery_codes' => encrypt(json_encode($this->generateRecoveryCodes())),
        ]);

        AuditLog::record('profile.recovery_codes_regenerated', $user);

        return back()->with('success', 'Recovery codes regenerated. Store them somewhere safe.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function qrCodeSvg($user): ?string
    {
        if (! $user->two_factor_secret) {
            return null;
        }

        $google2fa = new Google2FA();
        $secret    = decrypt($user->two_factor_secret);
        $otpUrl    = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(192),
            new SvgImageBackEnd()
        );

        return (new Writer($renderer))->writeString($otpUrl);
    }

    private function recoveryCodes($user): ?array
    {
        if (! $user->two_factor_recovery_codes || ! $user->two_factor_confirmed_at) {
            return null;
        }

        return json_decode(decrypt($user->two_factor_recovery_codes), true);
    }

    private function generateRecoveryCodes(): array
    {
        return Collection::times(8, fn () =>
            Str::random(5) . '-' . Str::random(5)
        )->all();
    }
}
