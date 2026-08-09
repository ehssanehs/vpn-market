<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarzbanService
{
    protected string $baseUrl;
    protected string $username;
    protected string $password;
    protected string $nodeHostname;
    protected ?string $accessToken = null;

    public function __construct(string $baseUrl, string $username, string $password, string $nodeHostname)
    {
        // Remove /dashboard if present
        $baseUrl = str_ireplace('/dashboard', '', $baseUrl);
        $this->baseUrl = rtrim($baseUrl, '/');
        
        $this->username = $username;
        $this->password = $password;

        // Clean node hostname
        $nodeHostname = trim($nodeHostname);
        
        // Remove any leading slashes (e.g. if user entered /https://...)
        $nodeHostname = ltrim($nodeHostname, '/');
        
        // Remove trailing slashes
        $nodeHostname = rtrim($nodeHostname, '/');
        
        // Ensure scheme exists
        if (!preg_match("~^(?:f|ht)tps?://~i", $nodeHostname)) {
            $nodeHostname = "https://" . $nodeHostname;
        }

        // Double check for duplicate scheme (e.g. https:///https://)
        while (str_starts_with($nodeHostname, 'https:///')) {
             $nodeHostname = str_replace('https:///', 'https://', $nodeHostname);
        }

        $this->nodeHostname = $nodeHostname;
    }



    public function login(): bool
    {
        try {
            $response = Http::asForm()->post($this->baseUrl . '/api/admin/token', [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            if ($response->successful() && isset($response->json()['access_token'])) {
                $this->accessToken = $response->json()['access_token'];
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::error('Marzban Login Exception:', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function createUser(array $userData): ?array
    {
        if (!$this->accessToken) {
            if (!$this->login()) {
                return ['detail' => 'Authentication failed'];
            }
        }

        try {
            // Fix: Marzban expects a dictionary (JSON Object) for inbounds, not an array.
            // If empty, it should be {}, not [].
            $inbounds = $userData['inbounds'] ?? [];
            
            // If it's an array and empty, convert to stdClass to ensure {} in JSON
            if (is_array($inbounds) && empty($inbounds)) {
                $inbounds = new \stdClass();
            }
            // If it's an array and not empty, it's already fine as a dictionary if keys are strings (associative array)
            // But if it's a sequential array (list), it might be wrong if Marzban expects key-value pairs.
            // Assuming Marzban inbounds structure is like {"tag": ["protocol"]} or similar dictionary structure.
            
            // Prepare proxies. Default to vless and vmess if empty.
            $proxies = $userData['proxies'] ?? [];
            if (empty($proxies)) {
                $proxies = [
                    'shadowsocks' => new \stdClass(),
                    'vless' => new \stdClass(),
                    'vmess' => new \stdClass(),
                ];
            } 

            $payload = [
                'username' => $userData['username'],
                'proxies' => $proxies,
                'inbounds' => $inbounds,
                'expire' => $userData['expire'],
                'data_limit' => (int)$userData['data_limit'],
                'data_limit_reset_strategy' => 'no_reset',
            ];

            // Manually encode to JSON to ensure correct types (especially inbounds as {})
            $jsonPayload = json_encode($payload);
            
            // Debug logging
            Log::info('Marzban Create User Payload (Raw JSON):', [
                'url' => $this->baseUrl . '/api/user',
                'json_payload' => $jsonPayload,
                'inbounds_type' => gettype($inbounds),
                'inbounds_is_object' => is_object($inbounds),
                'inbounds_is_array' => is_array($inbounds)
            ]);

            $response = Http::withToken($this->accessToken)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ])
                ->withBody($jsonPayload, 'application/json')
                ->post($this->baseUrl . '/api/user');

            Log::info('Marzban Create User Response:', $response->json() ?? ['raw' => $response->body()]);
            return $response->json();

        } catch (\Exception $e) {
            Log::error('Marzban Create User Exception:', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function updateUser(string $username, array $userData): ?array
    {
        if (!$this->accessToken) {
            if (!$this->login()) return null;
        }

        try {
            // Marzban's "modify user" endpoint (PUT /api/user/{username}) replaces
            // every field it receives. Two important API behaviours:
            //
            //  1. Sending "proxies" makes Marzban delete every proxy that is NOT
            //     listed and regenerate the settings (UUID/password) of the ones
            //     that are listed with empty settings. Defaulting proxies here
            //     would silently break the customer's existing config/subscription.
            //  2. Sending "inbounds" with tags that do not exist in the panel's
            //     xray config fails validation with a 422 error.
            //
            // Therefore only forward the fields the caller explicitly provided
            // (for a renewal this is just "expire" and "data_limit"), so the
            // user's existing proxies/inbounds stay untouched.

            $payload = [];

            if (array_key_exists('expire', $userData)) {
                $payload['expire'] = $userData['expire'] === null ? null : (int) $userData['expire'];
            }

            if (array_key_exists('data_limit', $userData)) {
                $payload['data_limit'] = $userData['data_limit'] === null ? null : (int) $userData['data_limit'];
            }

            foreach (['data_limit_reset_strategy', 'status', 'note'] as $optionalKey) {
                if (!empty($userData[$optionalKey])) {
                    $payload[$optionalKey] = $userData[$optionalKey];
                }
            }

            // Only include proxies/inbounds when the caller explicitly passed a
            // non-empty value (never default them — see the note above).
            if (!empty($userData['proxies']) && is_array($userData['proxies'])) {
                $payload['proxies'] = $userData['proxies'];
            }
            if (!empty($userData['inbounds']) && is_array($userData['inbounds'])) {
                $payload['inbounds'] = $userData['inbounds'];
            }

            if (empty($payload)) {
                Log::warning('Marzban Update User skipped: empty payload.', ['username' => $username]);
                return null;
            }

            $jsonPayload = json_encode($payload);

            Log::info('Marzban Update User Payload (Raw JSON):', [
                'url' => $this->baseUrl . "/api/user/{$username}",
                'json_payload' => $jsonPayload,
            ]);

            $response = Http::withToken($this->accessToken)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->withBody($jsonPayload, 'application/json')
                ->put($this->baseUrl . "/api/user/{$username}");

            if (!$response->successful()) {
                Log::error('Marzban Update User Failed:', [
                    'username' => $username,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            Log::info('Marzban Update User Response:', $response->json() ?? ['raw' => $response->body()]);
            return $response->json();
        } catch (\Exception $e) {
            Log::error('Marzban Update User Exception:', ['message' => $e->getMessage(), 'username' => $username]);
            return null;
        }
    }

    public function getUser(string $username): ?array
    {
        if (!$this->accessToken) {
            if (!$this->login()) return null;
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->baseUrl . "/api/user/{$username}");

            if ($response->successful()) {
                return $response->json();
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Marzban Get User Exception:', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function resetUserTraffic(string $username): ?array
    {
        if (!$this->accessToken) {
            if (!$this->login()) return null;
        }

        try {
            // Reset user traffic by setting used_traffic to 0
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->post($this->baseUrl . "/api/user/{$username}/reset");

            if (!$response->successful()) {
                Log::warning('Marzban Reset User Traffic Failed:', [
                    'username' => $username,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            Log::info('Marzban Reset User Traffic Response:', $response->json() ?? ['raw' => $response->body()]);
            return $response->json();
        } catch (\Exception $e) {
            Log::error('Marzban Reset User Traffic Exception:', ['message' => $e->getMessage(), 'username' => $username]);
            return null;
        }
    }


    public function getUsers(int $offset = 0, int $limit = 100): ?array
    {
        if (!$this->accessToken) {
            if (!$this->login()) {
                return null;
            }
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->baseUrl . "/api/users", [
                    'offset' => $offset,
                    'limit' => $limit,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Marzban getUsers failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Marzban getUsers Exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function getAllUsers(): array
    {
        $allUsers = [];
        $offset = 0;
        $limit = 100;

        while (true) {
            $data = $this->getUsers($offset, $limit);
            if (!$data) {
                break;
            }

            $users = $data['users'] ?? $data ?? [];
            if (empty($users) || !is_array($users)) {
                break;
            }

            $allUsers = array_merge($allUsers, $users);

            // If returned less than limit, we reached end
            if (count($users) < $limit) {
                break;
            }

            $offset += $limit;

            // Safety break to avoid infinite loop
            if ($offset > 10000) {
                Log::warning('Marzban getAllUsers safety break at offset 10000');
                break;
            }
        }

        return $allUsers;
    }

    /**
     * Find user by UUID (searching in proxies)
     */
    public function findUserByUuid(string $uuid): ?array
    {
        $uuid = strtolower(trim($uuid));

        if (!$this->accessToken) {
            if (!$this->login()) {
                return null;
            }
        }

        // Try to get all users and search for UUID in proxies
        $users = $this->getAllUsers();

        foreach ($users as $user) {
            // Check proxies for matching UUID
            $proxies = $user['proxies'] ?? [];
            foreach ($proxies as $protocol => $proxyData) {
                if (isset($proxyData['id']) && strtolower(trim($proxyData['id'])) === $uuid) {
                    Log::info('Found Marzban user by UUID', [
                        'uuid' => $uuid,
                        'username' => $user['username'] ?? 'unknown',
                        'protocol' => $protocol,
                    ]);
                    return $user;
                }
                // Some versions store uuid directly under proxy id or password
                if (isset($proxyData['password']) && strtolower(trim($proxyData['password'])) === $uuid) {
                    return $user;
                }
            }

            // Also check links or other fields
            // Marzban may store uuid in links array
            $links = $user['links'] ?? [];
            foreach ($links as $link) {
                if (str_contains(strtolower($link), $uuid)) {
                    return $user;
                }
            }
        }

        Log::info('Marzban user not found by UUID', ['uuid' => $uuid]);
        return null;
    }

    /**
     * Get user details including traffic and subscription
     */
    public function getUserDetailed(string $username): ?array
    {
        return $this->getUser($username);
    }

    public function generateSubscriptionLink(array $userApiResponse): string
    {
        if (!isset($userApiResponse['subscription_url'])) {
            // Fallback: try to construct from links if present
            if (isset($userApiResponse['links']) && !empty($userApiResponse['links'])) {
                return $userApiResponse['links'][0] ?? '';
            }
            return '';
        }

        $subscriptionUrl = trim($userApiResponse['subscription_url']);
        
        // If Marzban returns a full URL (check for http/https at start)
        if (preg_match("~^(?:f|ht)tps?://~i", $subscriptionUrl)) {
            return $subscriptionUrl;
        }
        
        // If it starts with /http... (weird case where Marzban returns relative path starting with http?)
        // Or if there is a double slash issue
        if (preg_match("~^/(?:f|ht)tps?://~i", $subscriptionUrl)) {
             return ltrim($subscriptionUrl, '/');
        }
        
        // Ensure one slash between host and path
        if (!str_starts_with($subscriptionUrl, '/')) {
            $subscriptionUrl = '/' . $subscriptionUrl;
        }

        // Ensure base doesn't have trailing slash (handled in constructor but safety first)
        $base = rtrim($this->nodeHostname, '/');

        return $base . $subscriptionUrl;
    }

    /**
     * Disable a user by setting expiry to now and data_limit to 0.
     */
    public function disableUser(string $username): ?array
    {
        if (!$this->accessToken) {
            if (!$this->login()) return null;
        }

        try {
            $payload = [
                'expire' => now()->timestamp,
                'data_limit' => 0,
                'data_limit_reset_strategy' => 'no_reset',
            ];

            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->put($this->baseUrl . "/api/user/{$username}", $payload);

            Log::info('Marzban Disable User Response:', $response->json() ?? ['raw' => $response->body()]);
            return $response->json();
        } catch (\Exception $e) {
            Log::error('Marzban Disable User Exception:', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
