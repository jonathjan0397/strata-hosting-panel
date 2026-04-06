<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AgentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class FileManagerController extends Controller
{
    // ── Page render ────────────────────────────────────────────────────────────

    public function index(Request $request): \Inertia\Response|RedirectResponse
    {
        $account = $this->account($request);

        if (! $account) {
            return redirect()->route('my.dashboard')->with('error', 'No hosting account found.');
        }

        return Inertia::render('User/FileManager', [
            'accountId' => $account->id,
        ]);
    }

    // ── API proxy methods ──────────────────────────────────────────────────────

    public function list(Request $request): JsonResponse
    {
        [$client, $username] = $this->clientAndUsername($request);
        $path = $request->query('path', '/');

        $response = $client->fileList($username, $path);

        return $response->successful()
            ? response()->json($response->json())
            : response()->json(['error' => $response->body()], $response->status());
    }

    public function read(Request $request): JsonResponse
    {
        [$client, $username] = $this->clientAndUsername($request);
        $path = $request->query('path');

        $response = $client->fileRead($username, $path);

        return $response->successful()
            ? response()->json($response->json())
            : response()->json(['error' => $response->body()], $response->status());
    }

    public function write(Request $request): JsonResponse
    {
        $request->validate([
            'path'    => ['required', 'string'],
            'content' => ['required', 'string'],
        ]);

        [$client, $username] = $this->clientAndUsername($request);
        $response = $client->fileWrite($username, $request->path, $request->content);

        return $response->successful()
            ? response()->json(['status' => 'ok'])
            : response()->json(['error' => $response->body()], $response->status());
    }

    public function mkdir(Request $request): JsonResponse
    {
        $request->validate(['path' => ['required', 'string']]);

        [$client, $username] = $this->clientAndUsername($request);
        $response = $client->fileMkdir($username, $request->path);

        return $response->successful()
            ? response()->json(['status' => 'ok'])
            : response()->json(['error' => $response->body()], $response->status());
    }

    public function rename(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'string'],
            'to'   => ['required', 'string'],
        ]);

        [$client, $username] = $this->clientAndUsername($request);
        $response = $client->fileRename($username, $request->from, $request->to);

        return $response->successful()
            ? response()->json(['status' => 'ok'])
            : response()->json(['error' => $response->body()], $response->status());
    }

    public function delete(Request $request): JsonResponse
    {
        $request->validate(['path' => ['required', 'string']]);

        [$client, $username] = $this->clientAndUsername($request);
        $response = $client->fileDelete($username, $request->path);

        return $response->successful()
            ? response()->json(['status' => 'ok'])
            : response()->json(['error' => $response->body()], $response->status());
    }

    public function chmod(Request $request): JsonResponse
    {
        $request->validate([
            'path' => ['required', 'string'],
            'mode' => ['required', 'string', 'regex:/^[0-7]{4}$/'],
        ]);

        [$client, $username] = $this->clientAndUsername($request);
        $response = $client->fileChmod($username, $request->path, $request->mode);

        return $response->successful()
            ? response()->json(['status' => 'ok'])
            : response()->json(['error' => $response->body()], $response->status());
    }

    public function compress(Request $request): JsonResponse
    {
        $request->validate([
            'paths'  => ['required', 'array'],
            'paths.*' => ['string'],
            'dest'   => ['required', 'string'],
            'format' => ['nullable', 'in:zip,tar.gz'],
        ]);

        [$client, $username] = $this->clientAndUsername($request);
        $response = $client->fileCompress($username, $request->paths, $request->dest, $request->format ?? 'zip');

        return $response->successful()
            ? response()->json(['status' => 'ok'])
            : response()->json(['error' => $response->body()], $response->status());
    }

    public function extract(Request $request): JsonResponse
    {
        $request->validate([
            'path' => ['required', 'string'],
            'dest' => ['nullable', 'string'],
        ]);

        [$client, $username] = $this->clientAndUsername($request);
        $response = $client->fileExtract($username, $request->path, $request->dest ?? '');

        return $response->successful()
            ? response()->json(['status' => 'ok'])
            : response()->json(['error' => $response->body()], $response->status());
    }

    public function download(Request $request): Response|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate(['path' => ['required', 'string']]);

        [$client, $username] = $this->clientAndUsername($request);

        // Stream the file from the agent directly to the browser.
        $agentUrl = $client->fileDownloadUrl($username, $request->path);
        $node     = $this->account($request)->node;

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . "\n", $node->hmac_secret);

        $agentResponse = Http::withHeaders([
            'X-Strata-Signature' => $signature,
            'X-Strata-Timestamp' => $timestamp,
        ])->get($agentUrl);

        if (! $agentResponse->successful()) {
            abort($agentResponse->status(), $agentResponse->body());
        }

        $filename = basename($request->path);

        return response($agentResponse->body(), 200, [
            'Content-Type'        => $agentResponse->header('Content-Type') ?: 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'path'    => ['required', 'string'],
            'files'   => ['required', 'array'],
            'files.*' => ['file', 'max:262144'], // 256 MB each
        ]);

        [$client, $username] = $this->clientAndUsername($request);
        $path = $request->path;

        $agentUrl  = $client->node->apiUrl("/files/{$username}/upload?path=" . urlencode($path));
        $timestamp = (string) time();
        [$body, $contentType] = $this->buildMultipartUploadPayload($request->file('files'));
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $client->node->hmac_secret);

        $response = Http::withHeaders([
            'X-Strata-Signature' => $signature,
            'X-Strata-Timestamp' => $timestamp,
        ])->withBody($body, $contentType)->post($agentUrl);

        return $response->successful()
            ? response()->json(['status' => 'ok'])
            : response()->json(['error' => $response->body()], $response->status());
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function account(Request $request): ?Account
    {
        return Account::where('user_id', $request->user()->id)->with('node')->first();
    }

    /** Returns [AgentClient, username] for the current user's account. */
    private function clientAndUsername(Request $request): array
    {
        $account = $this->account($request);
        abort_if(! $account, 404, 'No hosting account.');
        abort_if(! $account->node, 503, 'Account has no assigned node.');

        return [AgentClient::for($account->node), $account->username];
    }

    private function buildMultipartUploadPayload(array $files): array
    {
        $boundary = 'strata-' . bin2hex(random_bytes(16));
        $eol = "\r\n";
        $body = '';

        foreach ($files as $file) {
            $body .= "--{$boundary}{$eol}";
            $body .= 'Content-Disposition: form-data; name="files[]"; filename="' . addslashes($file->getClientOriginalName()) . "\"{$eol}";
            $body .= 'Content-Type: ' . ($file->getMimeType() ?: 'application/octet-stream') . "{$eol}{$eol}";
            $body .= file_get_contents($file->getRealPath());
            $body .= $eol;
        }

        $body .= "--{$boundary}--{$eol}";

        return [$body, "multipart/form-data; boundary={$boundary}"];
    }
}
