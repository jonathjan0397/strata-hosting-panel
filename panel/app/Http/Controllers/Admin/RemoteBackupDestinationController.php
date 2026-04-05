<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RemoteBackupDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RemoteBackupDestinationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Backups/Destinations', [
            'destinations' => RemoteBackupDestination::all()->map(fn($d) => [
                'id'     => $d->id,
                'name'   => $d->name,
                'type'   => $d->type,
                'active' => $d->active,
                // Never expose raw config/credentials
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:100'],
            'type'   => ['required', 'in:sftp,s3'],
            'config' => ['required', 'array'],
            'config.host'            => ['required_if:type,sftp', 'nullable', 'string'],
            'config.port'            => ['nullable', 'integer'],
            'config.remote_user'     => ['required_if:type,sftp', 'nullable', 'string'],
            'config.remote_path'     => ['required_if:type,sftp', 'nullable', 'string'],
            'config.ssh_private_key' => ['required_if:type,sftp', 'nullable', 'string'],
            'config.s3_bucket'       => ['required_if:type,s3', 'nullable', 'string'],
            'config.s3_key_id'       => ['required_if:type,s3', 'nullable', 'string'],
            'config.s3_key_secret'   => ['required_if:type,s3', 'nullable', 'string'],
            'config.s3_region'       => ['nullable', 'string'],
        ]);

        RemoteBackupDestination::create([
            'name'   => $data['name'],
            'type'   => $data['type'],
            'config' => $data['config'],
            'active' => true,
        ]);

        return back()->with('success', 'Destination added.');
    }

    public function destroy(RemoteBackupDestination $destination): RedirectResponse
    {
        $destination->delete();
        return back()->with('success', 'Destination removed.');
    }

    public function toggle(RemoteBackupDestination $destination): RedirectResponse
    {
        $destination->update(['active' => ! $destination->active]);
        return back()->with('success', 'Destination updated.');
    }
}
