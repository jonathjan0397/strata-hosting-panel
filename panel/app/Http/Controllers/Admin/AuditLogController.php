<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = AuditLog::with('user')
            ->orderByDesc('created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%"));
            });
        }

        if ($actor = $request->input('actor')) {
            $query->where('actor_type', $actor);
        }

        if ($action = $request->input('action')) {
            $query->where('action', 'like', "{$action}%");
        }

        $logs = $query->paginate(50)->withQueryString();

        return Inertia::render('Admin/AuditLog/Index', [
            'logs'    => $logs,
            'filters' => $request->only('search', 'actor', 'action'),
        ]);
    }
}
