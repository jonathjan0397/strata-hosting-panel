<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBackupJobAction;
use App\Jobs\ProcessBackupImport;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BackupImport;
use App\Models\Node;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class BackupImportController extends Controller
{
    public function index(): Response
    {
        $imports = BackupImport::with([
            'account:id,username,node_id',
            'node:id,name,hostname',
            'backupJob:id,filename,size_bytes,status',
            'importedBy:id,name',
        ])
            ->latest()
            ->paginate(25)
            ->through(fn (BackupImport $import) => [
                'id' => $import->id,
                'account' => $import->account?->username,
                'node' => $import->node?->name,
                'source_system' => $import->source_system,
                'status' => $import->status,
                'original_filename' => $import->original_filename,
                'converted_filename' => $import->converted_filename,
                'size_human' => $import->size_human,
                'detected_paths' => [
                    'source_system' => $import->detected_paths['source_system'] ?? null,
                    'sql_dumps' => count($import->detected_paths['sql_dumps'] ?? []),
                    'has_home' => filled($import->detected_paths['home_path'] ?? null),
                    'has_public_html' => filled($import->detected_paths['public_html_path'] ?? null),
                    'domains' => $import->detected_paths['domains'] ?? [],
                    'dns_zones' => $import->detected_paths['dns_zones'] ?? [],
                    'mailboxes' => $import->detected_paths['mailboxes'] ?? [],
                    'forwarders' => $import->detected_paths['forwarders'] ?? [],
                ],
                'backup' => $import->backupJob ? [
                    'filename' => $import->backupJob->filename,
                    'status' => $import->backupJob->status,
                    'size_human' => $import->backupJob->size_human,
                ] : null,
                'notes' => $import->notes,
                'error' => $import->error,
                'imported_by' => $import->importedBy?->name,
                'created_at' => $import->created_at?->toDateTimeString(),
                'completed_at' => $import->completed_at?->toDateTimeString(),
            ]);

        return Inertia::render('Admin/BackupImports/Index', [
            'imports' => $imports,
            'accounts' => Account::with('node:id,name')
                ->where('status', 'active')
                ->orderBy('username')
                ->get(['id', 'username', 'node_id'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'username' => $account->username,
                    'node_id' => $account->node_id,
                    'node' => $account->node?->name,
                ]),
            'nodes' => Node::where('status', 'online')
                ->orderBy('name')
                ->get(['id', 'name', 'hostname']),
            'limits' => [
                'max_upload_mb' => 2048,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'node_id' => ['required', 'exists:nodes,id'],
            'source_system' => ['required', 'in:auto,cpanel,cwp'],
            'archive' => ['required', 'file', 'max:2097152'],
        ]);

        $account = Account::with('node')->findOrFail($data['account_id']);
        $node = Node::findOrFail($data['node_id']);

        if (! $account->isActive()) {
            return back()->with('error', 'Destination account must be active before importing a competitor backup.');
        }

        if (! $node->isOnline()) {
            return back()->with('error', 'Target node must be online before importing a backup.');
        }

        if ((int) $node->id !== (int) $account->node_id) {
            return back()->with('error', 'Competitor backup imports must target the destination account node. Use account migration after restore if the account needs to move nodes.');
        }

        $file = $request->file('archive');
        $originalName = $file->getClientOriginalName();
        if (! preg_match('/\.(tar\.gz|tgz)$/i', $originalName)) {
            return back()->with('error', 'Only .tar.gz and .tgz cPanel/CWP backup archives are supported in this importer.');
        }

        $import = BackupImport::create([
            'account_id' => $account->id,
            'node_id' => $node->id,
            'imported_by' => $request->user()?->id,
            'source_system' => $data['source_system'],
            'status' => 'queued',
            'original_filename' => $originalName,
            'stored_path' => 'backup-imports/pending',
            'size_bytes' => $file->getSize(),
        ]);

        $safeName = 'source-' . $import->id . '.tar.gz';
        $directory = storage_path("app/backup-imports/{$import->id}");
        File::ensureDirectoryExists($directory, 0750);
        $file->move($directory, $safeName);
        $import->update(['stored_path' => "backup-imports/{$import->id}/{$safeName}"]);

        ProcessBackupImport::dispatch($import->id);

        AuditLog::record('backup.import_queued', $account, [
            'backup_import_id' => $import->id,
            'source_system' => $data['source_system'],
            'node_id' => $node->id,
            'original_filename' => $originalName,
        ]);

        return back()->with('success', "Backup import for {$account->username} was queued. Refresh the import queue for progress.");
    }

    public function restore(BackupImport $import): RedirectResponse
    {
        $import->load(['backupJob', 'account']);

        if ($import->status !== 'complete' || ! $import->backupJob) {
            return back()->with('error', 'Only completed imports with a generated backup can be restored.');
        }

        $import->backupJob->update(['restore_status' => 'running', 'restore_error' => null]);
        ProcessBackupJobAction::dispatch($import->backupJob->id, 'restore');

        AuditLog::record('backup.import_restore_queued', $import->account, [
            'backup_import_id' => $import->id,
            'backup_job_id' => $import->backup_job_id,
        ]);

        return back()->with('success', "Restore for imported backup {$import->backupJob->filename} was queued.");
    }
}
