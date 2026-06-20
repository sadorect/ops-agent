<?php

namespace Sadorect\OpsAgent\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Signs and sends requests to the hub. Every request carries the app slug,
 * plaintext token, a timestamp, and an HMAC-SHA256 signature over
 * "{timestamp}.{rawBody}" keyed by the shared secret — matching the hub's
 * VerifyAgentSignature middleware exactly.
 */
class HubClient
{
    public function __construct(
        protected ?string $hubUrl,
        protected ?string $slug,
        protected ?string $token,
        protected ?string $secret,
        protected int $timeout = 5,
    ) {}

    public function isConfigured(): bool
    {
        return $this->hubUrl && $this->slug && $this->token && $this->secret;
    }

    /** Verify a hub-issued command signature before executing it. */
    public function verifyCommandSignature(int $id, string $command, ?int $expiresAt, string $signature): bool
    {
        $payload = $id.'.'.$command.'.'.($expiresAt ?? 0);
        $expected = hash_hmac('sha256', $payload, (string) $this->secret);

        return hash_equals($expected, $signature);
    }

    public function post(string $path, array $body): Response
    {
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES);

        // Sign over the exact bytes we send.
        return $this->signed($raw)
            ->withBody($raw, 'application/json')
            ->post($this->url($path));
    }

    public function get(string $path): Response
    {
        // GET requests sign over an empty body, matching the hub.
        return $this->signed('')->get($this->url($path));
    }

    /** Build a request whose signature covers exactly $rawBody. */
    protected function signed(string $rawBody): PendingRequest
    {
        $timestamp = (string) time();

        return Http::timeout($this->timeout)
            ->acceptJson()
            ->withHeaders($this->headers($timestamp, $rawBody));
    }

    /** @return array<string, string> */
    protected function headers(string $timestamp, string $rawBody): array
    {
        return [
            'X-Ops-App' => (string) $this->slug,
            'X-Ops-Token' => (string) $this->token,
            'X-Ops-Timestamp' => $timestamp,
            'X-Ops-Signature' => hash_hmac('sha256', $timestamp.'.'.$rawBody, (string) $this->secret),
        ];
    }

    protected function url(string $path): string
    {
        return rtrim((string) $this->hubUrl, '/').'/api/'.ltrim($path, '/');
    }
}
