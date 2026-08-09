<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionImportService
{
    /**
     * Convert a raw panel expiry value into a Carbon instance.
     *
     * Panels report expiry in different units depending on the panel/version:
     *   - X-UI (3x-ui / Sanaei): milliseconds, 0 = unlimited, negative = "delayed start"
     *   - Marzban / PasarGuard:  seconds, 0 = unlimited
     *   - some forks: microseconds, null/'' = not set
     *
     * Values below ~year 2100 in seconds (< 4 102 444 800) are treated as seconds,
     * values up to ~year 2100 in milliseconds (< 4 102 444 800 000) as milliseconds,
     * and anything larger is treated as microseconds.
     *
     * 0 / null / empty / negative values mean there is no absolute expiry date, so
     * null is returned (UI renders it as "نامحدود" / unlimited).
     *
     * Some panels (e.g. PasarGuard and newer Marzban/forks) serialise the
     * expire field as a date/datetime string ("2026-09-01T00:00:00") instead
     * of a Unix timestamp. Those strings are parsed as well, otherwise the
     * subscription would wrongly be treated as unlimited.
     *
     * @param mixed $value
     * @return Carbon|null
     */
    public static function parseExpiryValue(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Non-numeric expiry values: try to parse them as a date string.
        if (!is_numeric($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable $e) {
                return null;
            }
        }

        $value = (int) $value;

        // 0 => unlimited / no expiry set. Negative => delayed start (3x-ui stores
        // negative values as "N days after first use", no absolute date exists yet).
        if ($value <= 0) {
            return null;
        }

        // Unix seconds (before ~year 2100).
        if ($value < 4102444800) {
            return Carbon::createFromTimestamp($value);
        }

        // Milliseconds (typical 13-digit timestamps, before ~year 2100).
        if ($value < 4102444800000) {
            return Carbon::createFromTimestampMs($value);
        }

        // Anything larger cannot be a valid millisecond timestamp for a realistic
        // subscription (that would land past year 2099). It must therefore be
        // expressed in microseconds (or finer) – normalise back to seconds so the
        // expiry date is correct instead of jumping thousands of years into the
        // future. Some X-UI / Marzban forks report expiry in microseconds.
        return Carbon::createFromTimestamp((int) ($value / 1000000));
    }

    /**
     * Import a subscription
     *
     * @param string $input VLESS URI or Subscription URL
     * @param User $user Owner user
     * @param string $source Source: 'web' or 'telegram'
     * @return array ['success' => bool, 'order' => Order|null, 'error' => string|null]
     */
    public static function import(string $input, User $user, string $source = 'web'): array
    {
        $input = trim($input);

        if (empty($input)) {
            return [
                'success' => false,
                'order' => null,
                'error' => 'Input cannot be empty.',
            ];
        }

        // Step 1: Detect input type and extract UUID
        $uuid = null;
        $originalConfig = $input;
        $parsedVlessUris = [];

        $inputType = VlessParserService::detectInputType($input);

        if ($inputType === 'vless') {
            $uuid = VlessParserService::extractUuidFromVless($input);
            if (!$uuid) {
                Log::warning('Invalid VLESS URI provided for import', [
                    'input' => Str::limit($input, 200),
                    'user_id' => $user->id,
                ]);
                return [
                    'success' => false,
                    'order' => null,
                    'error' => 'Invalid VLESS URI. Please check the format and UUID.',
                ];
            }
            $parsedVlessUris = [['uri' => $input, 'uuid' => $uuid]];

        } elseif ($inputType === 'url') {
            // Fetch subscription URL
            $fetchResult = SubscriptionFetcherService::fetch($input);
            if (!$fetchResult['success']) {
                return [
                    'success' => false,
                    'order' => null,
                    'error' => $fetchResult['error'] ?? 'Failed to fetch subscription URL.',
                ];
            }

            $content = $fetchResult['content'];
            $parsed = VlessParserService::parseSubscriptionContent($content);

            if (empty($parsed)) {
                Log::warning('No VLESS entries found in subscription content', [
                    'url' => $input,
                    'content_preview' => Str::limit($content, 200),
                    'user_id' => $user->id,
                ]);
                return [
                    'success' => false,
                    'order' => null,
                    'error' => 'No valid VLESS configurations found in subscription URL. Content may be malformed.',
                ];
            }

            // Use ONLY UUID from FIRST VLESS entry as per requirement
            $uuid = $parsed[0]['uuid'];
            $parsedVlessUris = $parsed;
            $originalConfig = $input; // Keep original subscription URL as config

        } else {
            return [
                'success' => false,
                'order' => null,
                'error' => 'Invalid input. Please provide a valid VLESS URI (vless://...) or Subscription URL (https://...).',
            ];
        }

        // Step 2: Validate UUID
        if (!VlessParserService::isValidUuid($uuid)) {
            return [
                'success' => false,
                'order' => null,
                'error' => 'Invalid UUID format extracted from input.',
            ];
        }

        // Step 3: Duplicate protection - check if UUID already belongs to any user
        $existingOrder = Order::where('panel_client_id', $uuid)->where('status', 'paid')->first();
        if ($existingOrder) {
            if ($existingOrder->user_id === $user->id) {
                // Telegram webhooks may be delivered more than once while the
                // first (panel) request is still running.  Import is therefore
                // idempotent for the Telegram flow: a retry should show the
                // already-created service instead of turning a successful import
                // into a misleading error.  Keep the old web/API response for
                // callers that explicitly expect duplicate validation.
                if ($source === 'telegram') {
                    return [
                        'success' => true,
                        'order' => $existingOrder,
                        'error' => null,
                    ];
                }

                return [
                    'success' => false,
                    'order' => null,
                    'error' => 'This subscription is already imported in your account.',
                ];
            } else {
                Log::warning('Duplicate UUID import attempt', [
                    'uuid' => $uuid,
                    'existing_user_id' => $existingOrder->user_id,
                    'attempting_user_id' => $user->id,
                ]);
                return [
                    'success' => false,
                    'order' => null,
                    'error' => 'This subscription already belongs to another user and cannot be imported.',
                ];
            }
        }

        // Step 4: Search panel using UUID
        try {
            $panelResult = PanelSearchService::searchByUuid($uuid);
        } catch (\Exception $e) {
            Log::error('Exception during panel search for UUID', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'success' => false,
                'order' => null,
                'error' => 'Error searching panel for this UUID. Please try again later.',
            ];
        }

        if (!$panelResult) {
            Log::info('UUID not found in any panel', [
                'uuid' => $uuid,
                'user_id' => $user->id,
            ]);
            return [
                'success' => false,
                'order' => null,
                'error' => 'This UUID does not exist in any configured panel. Please check the UUID or contact support.',
            ];
        }

        // Step 5: Read subscription info from panel and synchronize
        try {
            $order = self::createOrderFromPanelData(
                $uuid,
                $panelResult,
                $user,
                $source,
                $originalConfig,
                $parsedVlessUris
            );

            // Immediately re-sync the freshly imported order from the panel so the
            // expiry date (and traffic) shown in "My Services" match the panel
            // exactly. This is the fix for imports that showed a wrong expire date:
            // it guarantees the stored `expires_at` is the live panel value and
            // covers any panel that reports expiry in a non-standard unit on the
            // first pass. Failures are swallowed so the import still succeeds.
            try {
                self::syncOrderWithPanel($order);
            } catch (\Throwable $e) {
                Log::debug('Post-import live sync skipped', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Subscription imported successfully', [
                'uuid' => $uuid,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'panel_type' => $panelResult['type'],
                'server_id' => $panelResult['server_id'] ?? null,
            ]);

            return [
                'success' => true,
                'order' => $order,
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create order from panel data', [
                'uuid' => $uuid,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'success' => false,
                'order' => null,
                'error' => 'Failed to import subscription: ' . $e->getMessage(),
            ];
        }
    }

    protected static function createOrderFromPanelData(
        string $uuid,
        array $panelResult,
        User $user,
        string $source,
        string $originalConfig,
        array $parsedVlessUris
    ): Order {
        return DB::transaction(function () use ($uuid, $panelResult, $user, $source, $originalConfig, $parsedVlessUris) {
            $type = $panelResult['type'];
            $serverId = $panelResult['server_id'] ?? null;
            $server = $panelResult['server'] ?? null;
            $details = $panelResult['details'] ?? [];

            // Resolve the subscription link with a clear priority:
            //   1. the link built by the panel search (e.g. /sub/{token}),
            //   2. the raw subscription_url coming from the "get user" record,
            //   3. the input the user pasted (raw vless:// config or sub URL).
            $subscriptionLink = $panelResult['subscription_link'] ?? null;
            if (empty($subscriptionLink) && !empty($details['subscription_url'])) {
                $subscriptionLink = $details['subscription_url'];
            }
            if (empty($subscriptionLink)) {
                $subscriptionLink = $originalConfig;
            }

            // Determine panel username, expiration, traffic
            $panelUsername = null;
            $expiresAt = null;
            $totalTraffic = 0;
            $usedTraffic = 0;
            $subId = null;
            $importMeta = [];

            if ($type === 'xui') {
                $client = $panelResult['client'];
                $panelUsername = $client['email'] ?? 'imported-' . substr($uuid, 0, 8);
                $subId = $client['subId'] ?? ($details['subId'] ?? null);

                // expiryTime is in milliseconds in X-UI, 0 means unlimited. The
                // parser also tolerates seconds/microseconds from panel forks.
                $expiryMs = $details['expiryTime'] ?? ($client['expiryTime'] ?? 0);
                $expiresAt = self::parseExpiryValue($expiryMs);

                $totalTraffic = (int) ($details['totalGB'] ?? ($client['totalGB'] ?? 0));
                // totalGB 0 means unlimited in XUI
                $usedTraffic = (int) ($details['traffic_used'] ?? 0);
                // Also check traffic_up/down sum fallback
                if ($usedTraffic === 0 && isset($details['traffic_up'])) {
                    $usedTraffic = (int) ($details['traffic_up'] ?? 0) + (int) ($details['traffic_down'] ?? 0);
                }
                $remainingTraffic = $totalTraffic > 0 ? max(0, $totalTraffic - $usedTraffic) : null;

                $importMeta = [
                    'panel_type' => 'xui',
                    'inbound_id' => $details['inbound_id'] ?? null,
                    'inbound_remark' => $details['inbound_remark'] ?? null,
                    'subId' => $subId,
                    'totalGB' => $totalTraffic,
                    'used_traffic' => $usedTraffic,
                    'remaining_traffic' => $remainingTraffic,
                    'expiryTime' => $expiryMs,
                    'original_config' => $originalConfig,
                    'parsed_count' => count($parsedVlessUris),
                    'first_vless_uri' => $parsedVlessUris[0]['uri'] ?? null,
                    'imported_at' => now()->toIso8601String(),
                ];
            } elseif ($type === 'pasarguard') {
                // PasarGuard is similar to Marzban
                $pasarguardUser = $panelResult['user'] ?? $panelResult['client'];
                $panelUsername = $pasarguardUser['username'] ?? 'imported-' . substr($uuid, 0, 8);

                $expireTimestamp = (int) ($details['expire'] ?? ($pasarguardUser['expire'] ?? 0));
                $expiresAt = self::parseExpiryValue($expireTimestamp);

                $totalTraffic = (int) ($details['data_limit'] ?? ($pasarguardUser['data_limit'] ?? 0));
                $usedTraffic = (int) ($details['used_traffic'] ?? ($pasarguardUser['used_traffic'] ?? 0));
                $remainingTraffic = $totalTraffic > 0 ? max(0, $totalTraffic - $usedTraffic) : null;

                $importMeta = [
                    'panel_type' => 'pasarguard',
                    'username' => $panelUsername,
                    'data_limit' => $totalTraffic,
                    'used_traffic' => $usedTraffic,
                    'remaining_traffic' => $remainingTraffic,
                    'expire' => $expireTimestamp,
                    'status' => $details['status'] ?? 'active',
                    'proxies' => $details['proxies'] ?? [],
                    'original_config' => $originalConfig,
                    'parsed_count' => count($parsedVlessUris),
                    'imported_at' => now()->toIso8601String(),
                ];
            } else { // marzban
                $marzbanUser = $panelResult['user'] ?? $panelResult['client'];
                $panelUsername = $marzbanUser['username'] ?? 'imported-' . substr($uuid, 0, 8);

                $expireTimestamp = (int) ($details['expire'] ?? ($marzbanUser['expire'] ?? 0));
                $expiresAt = self::parseExpiryValue($expireTimestamp);

                $totalTraffic = (int) ($details['data_limit'] ?? ($marzbanUser['data_limit'] ?? 0));
                $usedTraffic = (int) ($details['used_traffic'] ?? ($marzbanUser['used_traffic'] ?? 0));
                $remainingTraffic = $totalTraffic > 0 ? max(0, $totalTraffic - $usedTraffic) : null;

                $importMeta = [
                    'panel_type' => 'marzban',
                    'username' => $panelUsername,
                    'data_limit' => $totalTraffic,
                    'used_traffic' => $usedTraffic,
                    'remaining_traffic' => $remainingTraffic,
                    'expire' => $expireTimestamp,
                    'status' => $details['status'] ?? 'active',
                    'proxies' => $details['proxies'] ?? [],
                    'original_config' => $originalConfig,
                    'parsed_count' => count($parsedVlessUris),
                    'imported_at' => now()->toIso8601String(),
                ];
            }

            // Find matching plan based on traffic limit
            $plan = self::findMatchingPlan($totalTraffic);

            // When the user pasted a single VLESS link we must still store the
            // account's real subscription link (the /sub/... URL the panel knows
            // about) so "My Services" shows a subscription link they can paste into
            // any VPN app – not just the raw vless:// config they started from.
            // For a subscription URL input we keep that URL. We always prefer the
            // panel-provided subscription link; only fall back to the pasted input
            // if the panel returned nothing usable.
            $wasVlessInput = Str::startsWith($originalConfig, 'vless://');
            $configDetails = !empty($subscriptionLink) ? $subscriptionLink : $originalConfig;

            // Ensure configDetails is not empty
            if (empty($configDetails)) {
                $configDetails = $originalConfig;
            }

            // Remember which link was saved and mark the order as freshly synced so
            // the dashboard does not need to re-query the panel right away.
            $importMeta['input_type'] = $wasVlessInput ? 'vless' : 'url';
            $importMeta['subscription_link'] = $configDetails;
            $importMeta['original_config'] = $originalConfig;
            $importMeta['expires_at'] = $expiresAt ? $expiresAt->toIso8601String() : null;
            $importMeta['panel_username'] = $panelUsername;
            $importMeta['last_synced_at'] = now()->toIso8601String();

            // Create order - must behave identically to normal orders
            $order = Order::create([
                'user_id' => $user->id,
                'plan_id' => $plan ? $plan->id : null,
                'server_id' => $serverId,
                'status' => 'paid',
                'source' => $source,
                'payment_method' => 'imported',
                'config_details' => $configDetails,
                'expires_at' => $expiresAt,
                'panel_username' => $panelUsername,
                'panel_client_id' => $uuid,
                'panel_sub_id' => $subId,
                'amount' => $plan ? $plan->price : 0,
                'is_imported' => true,
                'import_meta' => $importMeta,
            ]);

            return $order;
        });
    }

    /**
     * Re-read the live panel data for an imported order and refresh its stored
     * traffic / expiry so the dates shown in "My Services" (web or Telegram) are
     * always the real ones. Also heals orders imported before the expiry parsing
     * was fixed (re-import is blocked by duplicate protection, so this is the way
     * those stale dates get corrected).
     *
     * @param Order $order
     * @return bool true when the order was successfully refreshed
     */
    public static function syncOrderWithPanel(Order $order): bool
    {
        if (!$order->is_imported || empty($order->panel_client_id)) {
            return false;
        }

        $meta = $order->import_meta ?? [];

        // Record the attempt timestamp in every outcome (success or failure) so
        // callers can throttle re-syncs (e.g. the dashboard only retries every 24h)
        // and an unreachable panel never slows down page loads on every request.
        $markAttempt = function () use (&$meta, $order) {
            $meta['last_synced_at'] = now()->toIso8601String();
            $order->import_meta = $meta;
            $order->save();
        };

        try {
            $panelResult = PanelSearchService::searchByUuid($order->panel_client_id);
            if (!$panelResult) {
                $markAttempt();

                return false;
            }

            $details = $panelResult['details'] ?? [];

            if (($panelResult['type'] ?? '') === 'xui') {
                $total = (int) ($details['totalGB'] ?? ($panelResult['client']['totalGB'] ?? 0));
                $used = (int) ($details['traffic_used'] ?? 0);
                $meta['totalGB'] = $total;
                $meta['used_traffic'] = $used;
                $meta['remaining_traffic'] = $total > 0 ? max(0, $total - $used) : null;
                $expiresAt = self::parseExpiryValue($details['expiryTime'] ?? ($panelResult['client']['expiryTime'] ?? 0));
            } else {
                // Marzban / PasarGuard
                $total = (int) ($details['data_limit'] ?? ($panelResult['client']['data_limit'] ?? 0));
                $used = (int) ($details['used_traffic'] ?? 0);
                $meta['data_limit'] = $total;
                $meta['used_traffic'] = $used;
                $meta['remaining_traffic'] = $total > 0 ? max(0, $total - $used) : null;
                $expiresAt = self::parseExpiryValue($details['expire'] ?? ($panelResult['client']['expire'] ?? 0));
            }

            // Refresh the stored subscription link as well, so orders imported
            // from a raw vless:// link (or imported before the panel link was
            // persisted) end up with the subscription link stored in the
            // database and shown under "My Services" / service details.
            $subscriptionLink = $panelResult['subscription_link'] ?? ($details['subscription_url'] ?? null);
            if (!empty($subscriptionLink)) {
                $meta['subscription_link'] = $subscriptionLink;
                $storedLink = trim((string) ($order->config_details ?? ''));
                if (empty($storedLink) || Str::startsWith(strtolower($storedLink), 'vless://')) {
                    $order->config_details = $subscriptionLink;
                }
            }

            $meta['expires_at'] = $expiresAt ? $expiresAt->toIso8601String() : null;

            $order->import_meta = $meta;
            $order->expires_at = $expiresAt;
            $markAttempt();

            return true;
        } catch (\Throwable $e) {
            Log::debug('Failed to sync imported order with panel', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            try {
                $markAttempt();
            } catch (\Throwable $e2) {
                Log::debug('Could not persist sync attempt timestamp', [
                    'order_id' => $order->id,
                    'error' => $e2->getMessage(),
                ]);
            }

            return false;
        }
    }

    protected static function findMatchingPlan(int $totalBytes): ?Plan
    {
        if ($totalBytes <= 0) {
            // Unlimited or 0 - return first active plan
            return Plan::where('is_active', true)->orderBy('price')->first();
        }

        // Convert bytes to GB
        $totalGB = (int) ceil($totalBytes / (1024 * 1024 * 1024));

        // Try exact match
        $exact = Plan::where('is_active', true)
            ->where('volume_gb', $totalGB)
            ->first();

        if ($exact) {
            return $exact;
        }

        // Try closest match (within 5GB)
        $closest = Plan::where('is_active', true)
            ->orderByRaw("ABS(volume_gb - {$totalGB})")
            ->first();

        if ($closest && abs($closest->volume_gb - $totalGB) <= 5) {
            return $closest;
        }

        // Fallback to first active
        return Plan::where('is_active', true)->orderBy('price')->first();
    }
}
