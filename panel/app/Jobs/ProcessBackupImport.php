<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\BackupImport;
use App\Models\BackupJob;
use App\Services\AgentClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ProcessBackupImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public int $backupImportId) {}

    public function handle(): void
    {
        $import = BackupImport::with(['account.node', 'node'])->findOrFail($this->backupImportId);

        if (! $import->account || ! $import->node) {
            $this->failImport($import, 'Import is missing account or target node metadata.');
            return;
        }

        if (! $import->account->isActive()) {
            $this->failImport($import, 'Destination account must be active before importing a competitor backup.');
            return;
        }

        $sourceArchive = storage_path('app/' . $import->stored_path);
        if (! is_file($sourceArchive)) {
            $this->failImport($import, 'Uploaded archive is missing from panel storage.');
            return;
        }

        $workDir = storage_path("app/backup-imports/{$import->id}/work");
        File::deleteDirectory($workDir);
        File::ensureDirectoryExists($workDir, 0750);

        try {
            $import->update(['status' => 'analyzing', 'error' => null]);
            $entries = $this->listArchive($sourceArchive);
            $this->rejectUnsafeEntries($entries);

            $extractDir = "{$workDir}/extract";
            File::ensureDirectoryExists($extractDir, 0750);
            $this->run(['tar', '--no-same-owner', '--no-same-permissions', '-xzf', $sourceArchive, '-C', $extractDir], 900);

            $detected = $this->detectPaths($extractDir);
            $import->update([
                'detected_paths' => $detected,
                'source_system' => $import->source_system === 'auto' ? $detected['source_system'] : $import->source_system,
                'status' => 'converting',
            ]);

            if (! $detected['home_path'] && ! $detected['public_html_path']) {
                throw new \RuntimeException('No cPanel/CWP home directory or public_html directory was found in the archive.');
            }

            $convertedPath = $this->buildStrataArchive($import, $detected, $workDir);
            $filename = basename($convertedPath);
            $contents = file_get_contents($convertedPath);
            if ($contents === false) {
                throw new \RuntimeException('Converted archive could not be read for target upload.');
            }

            $import->update(['status' => 'uploading', 'converted_filename' => $filename]);
            $upload = AgentClient::for($import->node)->backupUpload($import->account->username, $filename, $contents);
            if (! $upload->successful()) {
                throw new \RuntimeException($upload->body());
            }

            $result = $upload->json();
            $backup = BackupJob::create([
                'account_id' => $import->account_id,
                'node_id' => $import->node_id,
                'filename' => $result['filename'] ?? $filename,
                'type' => 'full',
                'status' => 'complete',
                'size_bytes' => $result['size_bytes'] ?? filesize($convertedPath),
                'trigger' => 'manual',
            ]);

            $import->update([
                'status' => 'complete',
                'backup_job_id' => $backup->id,
                'size_bytes' => $backup->size_bytes,
                'notes' => $this->notes($detected),
                'completed_at' => now(),
            ]);

            AuditLog::record('backup.import_complete', $import->account, [
                'backup_import_id' => $import->id,
                'source_system' => $import->source_system,
                'backup_job_id' => $backup->id,
                'filename' => $backup->filename,
            ]);
        } catch (\Throwable $e) {
            $this->failImport($import, $e->getMessage());
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function listArchive(string $archive): array
    {
        $process = $this->run(['tar', '-tzf', $archive], 300);

        return array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
    }

    private function rejectUnsafeEntries(array $entries): void
    {
        foreach ($entries as $entry) {
            $normalized = str_replace('\\', '/', $entry);
            if (str_starts_with($normalized, '/') || str_contains($normalized, '/../') || str_starts_with($normalized, '../') || $normalized === '..') {
                throw new \RuntimeException("Archive contains unsafe path: {$entry}");
            }
        }
    }

    private function detectPaths(string $extractDir): array
    {
        $homePath = null;
        $publicHtmlPath = null;
        $sqlDumps = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $base = $item->getBasename();
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen($extractDir))), '/');

            if ($item->isDir() && ! $item->isLink() && $base === 'homedir' && ! $homePath) {
                $homePath = $path;
            }

            if ($item->isDir() && ! $item->isLink() && $base === 'public_html' && ! $publicHtmlPath) {
                $publicHtmlPath = $path;
            }

            if ($item->isFile() && ! $item->isLink() && preg_match('/\.(sql|sql\.gz)$/i', $base)) {
                if (preg_match('#(^|/)(mysql|databases|postgres|psql)(/|$)#i', $relative) || count($sqlDumps) < 25) {
                    $sqlDumps[] = $path;
                }
            }
        }

        $sourceSystem = 'unknown';
        $haystack = strtolower(implode("\n", [
            $homePath ? str_replace('\\', '/', $homePath) : '',
            $publicHtmlPath ? str_replace('\\', '/', $publicHtmlPath) : '',
            implode("\n", $sqlDumps),
        ]));
        if (str_contains($haystack, 'cpmove-') || str_contains($haystack, '/homedir')) {
            $sourceSystem = 'cpanel';
        } elseif (str_contains($haystack, 'cwp') || str_contains($haystack, '/public_html')) {
            $sourceSystem = 'cwp';
        }

        return [
            'source_system' => $sourceSystem,
            'home_path' => $homePath,
            'public_html_path' => $publicHtmlPath,
            'sql_dumps' => $sqlDumps,
        ];
    }

    private function buildStrataArchive(BackupImport $import, array $detected, string $workDir): string
    {
        $bundleDir = "{$workDir}/bundle";
        $filesRoot = "{$workDir}/files";
        $accountRoot = "{$filesRoot}/{$import->account->username}";
        File::ensureDirectoryExists($accountRoot, 0750);

        if ($detected['home_path']) {
            $this->copyDirectorySafely($detected['home_path'], $accountRoot);
        } else {
            File::ensureDirectoryExists("{$accountRoot}/public_html", 0750);
            $this->copyDirectorySafely($detected['public_html_path'], "{$accountRoot}/public_html");
        }

        File::ensureDirectoryExists($bundleDir, 0750);
        $filesArchive = "{$bundleDir}/files.tar.gz";
        $this->run(['tar', '-czf', $filesArchive, '-C', $filesRoot, $import->account->username], 900);

        if ($detected['sql_dumps'] !== []) {
            $this->writeDatabaseDump($detected['sql_dumps'], "{$bundleDir}/databases.sql.gz");
        }

        $filename = sprintf('%s_full_imported_%s.tar.gz', $import->account->username, now()->utc()->format('YmdHis'));
        $converted = storage_path("app/backup-imports/{$import->id}/{$filename}");
        $this->run(['tar', '-czf', $converted, '-C', $bundleDir, '.'], 900);

        return $converted;
    }

    private function copyDirectorySafely(string $source, string $target): void
    {
        File::ensureDirectoryExists($target, 0750);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source))), '/');
            if ($relative === '' || str_starts_with($relative, '../') || str_contains($relative, '/../')) {
                continue;
            }

            $destination = $target . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                File::ensureDirectoryExists($destination, 0750);
                continue;
            }

            File::ensureDirectoryExists(dirname($destination), 0750);
            File::copy($item->getPathname(), $destination);
        }
    }

    private function writeDatabaseDump(array $sqlDumps, string $destination): void
    {
        $out = gzopen($destination, 'wb9');
        if (! $out) {
            throw new \RuntimeException('Could not create converted database dump.');
        }

        try {
            foreach ($sqlDumps as $dump) {
                $name = basename($dump);
                gzwrite($out, "\n-- Imported from {$name}\n");
                $compressed = str_ends_with(strtolower($dump), '.gz');
                $in = $compressed ? gzopen($dump, 'rb') : fopen($dump, 'rb');
                if (! $in) {
                    continue;
                }
                while (! feof($in)) {
                    gzwrite($out, $compressed ? gzread($in, 1048576) : fread($in, 1048576));
                }
                $compressed ? gzclose($in) : fclose($in);
                gzwrite($out, "\n");
            }
        } finally {
            gzclose($out);
        }
    }

    private function notes(array $detected): string
    {
        $notes = [
            'Converted website files into a Strata full backup archive.',
            'Database SQL dumps were included when detected.',
            'Mailboxes, FTP users, app installer metadata, and original control-panel credentials are not recreated automatically.',
        ];

        if ($detected['sql_dumps'] === []) {
            $notes[] = 'No SQL dumps were detected in the source archive.';
        }

        return implode(' ', $notes);
    }

    private function run(array $command, int $timeout): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->mustRun();

        return $process;
    }

    private function failImport(BackupImport $import, string $error): void
    {
        $import->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
