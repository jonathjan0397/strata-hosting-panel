<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;

class WebhookDispatcher
{
    public function dispatch(AuditLog $log): void
    {
        $endpoints = WebhookEndpoint::where('active', true)->get();

        foreach ($endpoints as $endpoint) {
            if (! $endpoint->listensFor($log->action)) {
                continue;
            }

            $this->deliver($endpoint, $log);
        }
    }

    private function deliver(WebhookEndpoint $endpoint, AuditLog $log): void
    {
        $body = json_encode([
            'id' => $log->id,
            'event' => $log->action,
            'actor_type' => $log->actor_type,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'payload' => $log->payload,
            'created_at' => $log->created_at?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            return;
        }

        $timestamp = (string) time();
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Strata-Event' => $log->action,
            'X-Strata-Delivery' => (string) $log->id,
            'X-Strata-Timestamp' => $timestamp,
        ];

        if ($endpoint->secret) {
            $headers['X-Strata-Signature'] = hash_hmac('sha256', $timestamp . "\n" . $body, $endpoint->secret);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(3)
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $endpoint->forceFill([
                'last_status' => $response->status(),
                'last_error' => $response->successful() ? null : substr($response->body(), 0, 1000),
                'last_delivery_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $endpoint->forceFill([
                'last_status' => null,
                'last_error' => substr($e->getMessage(), 0, 1000),
                'last_delivery_at' => now(),
            ])->save();
        }
    }
}
