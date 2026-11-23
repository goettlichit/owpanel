<?php

namespace App\Oidc;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The few admin calls of the Pocket ID API the panel uses to create and
 * link customers (E-130, E-138): find a user by email, create one, get a
 * login link or let Pocket ID send it. The API key is optional, stored
 * encrypted, and should only hold the scopes users:read, users:write and
 * users:login-codes (Pocket ID 2.19 or newer). The base URL is the issuer
 * of the Pocket ID connection; the user ID there is the "sub" at login.
 */
class PocketIdApi
{
    public const SCOPES = ['users:read', 'users:write', 'users:login-codes'];

    public function __construct(private string $baseUrl, private string $key) {}

    /**
     * The client with the stored key, or null when Pocket ID or the key is
     * not set up.
     */
    public static function fromSettings(): ?self
    {
        $issuer = OidcSettings::issuer();
        $encrypted = Setting::get(Setting::POCKET_ID_API_KEY);
        if ($issuer === null || $encrypted === null) {
            return null;
        }

        return new self($issuer, Crypt::decryptString($encrypted));
    }

    /**
     * Checks the key by reading one user; throws when it does not work.
     */
    public function verify(): void
    {
        $this->send(fn (PendingRequest $http) => $http->get('/api/users', ['pagination[limit]' => 1]));
    }

    /**
     * The user with exactly this email address, or null.
     *
     * @return array{id: string, username: string, email: ?string, firstName: string, lastName: string}|null
     */
    public function findByEmail(string $email): ?array
    {
        $data = $this->send(fn (PendingRequest $http) => $http->get('/api/users', ['search' => $email]))->json('data') ?? [];
        foreach ($data as $user) {
            if (is_array($user) && mb_strtolower((string) ($user['email'] ?? '')) === mb_strtolower($email)) {
                return $this->user($user);
            }
        }

        return null;
    }

    /**
     * @return array{id: string, username: string, email: ?string, firstName: string, lastName: string}
     */
    public function get(string $id): array
    {
        return $this->user($this->send(fn (PendingRequest $http) => $http->get('/api/users/'.rawurlencode($id)))->json());
    }

    /**
     * Creates a user without admin rights.
     *
     * @return array{id: string, username: string, email: ?string, firstName: string, lastName: string}
     */
    public function create(string $username, string $email, string $firstName, string $lastName, string $locale): array
    {
        return $this->user($this->send(fn (PendingRequest $http) => $http->post('/api/users', [
            'username' => $username,
            'email' => $email,
            'emailVerified' => true,
            'firstName' => mb_substr($firstName, 0, 50),
            'lastName' => mb_substr($lastName, 0, 50),
            'displayName' => mb_substr(trim($firstName.' '.$lastName), 0, 100),
            'isAdmin' => false,
            'locale' => $locale,
        ]))->json());
    }

    /**
     * A login link for the user, to set up the passkey.
     */
    public function loginLink(string $id, int $hours): string
    {
        $token = (string) $this->send(fn (PendingRequest $http) => $http->post('/api/users/'.rawurlencode($id).'/one-time-access-token', [
            'ttl' => $hours * 3600,
        ]))->json('token');

        return rtrim($this->baseUrl, '/').'/lc/'.$token;
    }

    /**
     * Lets Pocket ID send the login link by email (needs mail in Pocket ID).
     */
    public function sendLoginEmail(string $id, int $hours): void
    {
        $this->send(fn (PendingRequest $http) => $http->post('/api/users/'.rawurlencode($id).'/one-time-access-email', [
            'ttl' => $hours * 3600,
        ]));
    }

    /**
     * Username for a new account: the email address when Pocket ID accepts
     * it as a username, otherwise the customer number.
     */
    public static function username(string $email, string $fallback): string
    {
        return mb_strlen($email) <= 50 && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_.@-]*[a-zA-Z0-9])?$/', $email) === 1
            ? $email
            : strtolower($fallback);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call(Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withHeaders(['X-API-Key' => $this->key])
                ->acceptJson()->asJson()->timeout(10)->withoutRedirecting());
        } catch (ConnectionException) {
            throw new RuntimeException(__('settings.pocket_id_api.errors.unreachable'));
        }

        if ($response->status() === 401) {
            throw new RuntimeException(__('settings.pocket_id_api.errors.unauthorized'));
        }
        if ($response->status() === 403) {
            throw new RuntimeException(__('settings.pocket_id_api.errors.forbidden', ['scopes' => implode(', ', self::SCOPES)]));
        }
        if (! $response->successful()) {
            $message = $response->json('error') ?? $response->json('message') ?? 'HTTP '.$response->status();

            throw new RuntimeException(__('settings.pocket_id_api.errors.failed', ['error' => mb_substr((string) $message, 0, 200)]));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>|null  $user
     * @return array{id: string, username: string, email: ?string, firstName: string, lastName: string}
     */
    private function user(?array $user): array
    {
        if (! is_array($user) || ! is_string($user['id'] ?? null)) {
            throw new RuntimeException(__('settings.pocket_id_api.errors.failed', ['error' => 'no user']));
        }

        return [
            'id' => $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'email' => isset($user['email']) ? (string) $user['email'] : null,
            'firstName' => (string) ($user['firstName'] ?? ''),
            'lastName' => (string) ($user['lastName'] ?? ''),
        ];
    }
}
