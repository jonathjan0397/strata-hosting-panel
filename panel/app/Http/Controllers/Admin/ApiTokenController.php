<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $tokens = $request->user()
            ->tokens()
            ->latest()
            ->get()
            ->map(fn ($t) => [
                'id'          => $t->id,
                'name'        => $t->name,
                'abilities'   => $t->abilities,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at'  => $t->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/ApiTokens/Index', [
            'tokens'    => $tokens,
            'new_token' => session('new_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $token = $request->user()->createToken($data['name'], [
            'accounts:create', 'accounts:suspend', 'accounts:unsuspend',
            'accounts:terminate', 'accounts:usage', 'catalog:read',
        ]);

        AuditLog::record('api_token.created', null, ['name' => $data['name']]);

        return redirect()->route('admin.api-tokens.index')
            ->with('new_token', $token->plainTextToken);
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $request->user()->tokens()->where('id', $id)->delete();

        AuditLog::record('api_token.deleted', null, ['token_id' => $id]);

        return back()->with('success', 'API token revoked.');
    }
}
