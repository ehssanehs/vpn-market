<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Laravel\Facades\Telegram;
use Throwable;

class TelegramOrderNotificationService
{
    /**
     * Telegram limits text messages to 4096 characters. Keep enough room for
     * headings when a large, multi-connection configuration must be split.
     */
    private const CONFIG_CHUNK_LENGTH = 3200;

    private const SAFE_MESSAGE_LENGTH = 3900;

    /**
     * Notify a user after a new service has been provisioned or renewed.
     *
     * For renewal payments, $paymentOrder is the renewal transaction while the
     * connection details live on the original order.
     */
    public function sendServiceActivated(Order $paymentOrder): bool
    {
        try {
            $paymentOrder->loadMissing(['user', 'plan']);

            $serviceOrder = $paymentOrder;
            $isRenewal = (bool) $paymentOrder->renews_order_id;

            if ($isRenewal) {
                $serviceOrder = Order::with(['user', 'plan'])->find($paymentOrder->renews_order_id);

                if (! $serviceOrder) {
                    Log::warning('Telegram service notification skipped: original renewal order not found.', [
                        'order_id' => $paymentOrder->id,
                        'renews_order_id' => $paymentOrder->renews_order_id,
                    ]);

                    return false;
                }
            }

            $user = $paymentOrder->user ?: $serviceOrder->user;
            $chatId = trim((string) ($user?->telegram_chat_id ?? ''));

            if ($chatId === '') {
                Log::info('Telegram service notification skipped: user has no Telegram chat ID.', [
                    'order_id' => $paymentOrder->id,
                    'user_id' => $paymentOrder->user_id,
                ]);

                return false;
            }

            $config = trim((string) $serviceOrder->config_details);
            if ($config === '') {
                Log::warning('Telegram service notification skipped: connection details are empty.', [
                    'order_id' => $paymentOrder->id,
                    'service_order_id' => $serviceOrder->id,
                ]);

                return false;
            }

            if (! $this->configureBot()) {
                return false;
            }

            $plan = $paymentOrder->plan ?: $serviceOrder->plan;
            $server = null;
            $location = null;

            if (class_exists(\Modules\MultiServer\Models\Server::class)) {
                try {
                    $serviceOrder->loadMissing('server.location');
                    $server = $serviceOrder->server;
                    $location = $server?->location;
                } catch (Throwable $exception) {
                    // Server/location metadata is optional. A missing or disabled
                    // MultiServer module must not prevent delivery of the config.
                    Log::warning('Could not load server metadata for Telegram notification.', [
                        'order_id' => $paymentOrder->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $title = $isRenewal
                ? '✅ تمدید سرویس با موفقیت انجام شد'
                : '✅ خرید شما تایید و سرویس فعال شد';

            $planName = $plan?->name ?? 'نامشخص';
            $expiresAt = to_jalali_date($serviceOrder->expires_at, 'Y/m/d H:i', 'نامشخص');
            $username = $serviceOrder->panel_username ?: 'نامشخص';
            $serverName = $server?->name ?? 'سرور اصلی';
            $locationFlag = $location?->flag ?? '🏳️';
            $locationName = $location?->name ?? 'نامشخص';

            $htmlSummary = '<b>'.$this->escapeHtml($title)."</b>\n\n";
            $htmlSummary .= '📦 <b>پلن:</b> '.$this->escapeHtml($planName)."\n";

            if (! $isRenewal) {
                $htmlSummary .= '🌍 <b>موقعیت:</b> '.$this->escapeHtml($locationFlag.' '.$locationName)."\n";
                $htmlSummary .= '🖥 <b>سرور:</b> '.$this->escapeHtml($serverName)."\n";
            }

            if ($plan) {
                $htmlSummary .= '💾 <b>حجم:</b> '.$this->escapeHtml((string) $plan->volume_gb)." گیگابایت\n";
                $htmlSummary .= '📅 <b>مدت:</b> '.$this->escapeHtml((string) $plan->duration_days)." روز\n";
            }

            $htmlSummary .= '⏳ <b>انقضا:</b> <code>'.$this->escapeHtml($expiresAt)."</code>\n";
            $htmlSummary .= '👤 <b>نام کاربری:</b> <code>'.$this->escapeHtml($username)."</code>\n";

            $plainSummary = $title."\n\n";
            $plainSummary .= "📦 پلن: {$planName}\n";

            if (! $isRenewal) {
                $plainSummary .= "🌍 موقعیت: {$locationFlag} {$locationName}\n";
                $plainSummary .= "🖥 سرور: {$serverName}\n";
            }

            if ($plan) {
                $plainSummary .= "💾 حجم: {$plan->volume_gb} گیگابایت\n";
                $plainSummary .= "📅 مدت: {$plan->duration_days} روز\n";
            }

            $plainSummary .= "⏳ انقضا: {$expiresAt}\n";
            $plainSummary .= "👤 نام کاربری: {$username}\n";

            $htmlMessage = $htmlSummary
                . "\n🔗 <b>لینک اتصال شما:</b>\n<code>"
                . $this->escapeHtml($config)
                . "</code>\n\n⚠️ برای کپی آسان می‌توانید از دکمه زیر استفاده کنید.";

            $plainMessage = $plainSummary
                . "\n🔗 لینک اتصال شما:\n{$config}\n\n"
                . '⚠️ برای کپی آسان می‌توانید از دکمه زیر استفاده کنید.';

            $keyboard = $this->serviceKeyboard($serviceOrder->id);

            if (mb_strlen($htmlMessage) <= self::SAFE_MESSAGE_LENGTH) {
                return $this->sendHtmlWithPlainFallback(
                    $chatId,
                    $htmlMessage,
                    $plainMessage,
                    $keyboard,
                    'service_activated',
                    $paymentOrder->id,
                );
            }

            // Very large subscription bundles cannot fit in a single Telegram
            // message. Send the details first and all connection data in full.
            $summarySent = $this->sendHtmlWithPlainFallback(
                $chatId,
                $htmlSummary."\n🔗 اطلاعات اتصال در پیام بعدی ارسال می‌شود.",
                $plainSummary."\n🔗 اطلاعات اتصال در پیام بعدی ارسال می‌شود.",
                null,
                'service_activated_summary',
                $paymentOrder->id,
            );

            if (! $summarySent) {
                return false;
            }

            $chunks = $this->splitConfig($config);
            $chunkCount = count($chunks);

            foreach ($chunks as $index => $chunk) {
                $part = $chunkCount > 1 ? ' '.($index + 1)."/{$chunkCount}" : '';
                $payload = [
                    'chat_id' => $chatId,
                    'text' => "🔗 لینک اتصال{$part}:\n\n{$chunk}",
                    'disable_web_page_preview' => true,
                ];

                if ($index === $chunkCount - 1) {
                    $payload['reply_markup'] = $keyboard;
                }

                try {
                    Telegram::sendMessage($payload);
                } catch (Throwable $exception) {
                    Log::error('Failed to send Telegram connection-details chunk.', [
                        'order_id' => $paymentOrder->id,
                        'chunk' => $index + 1,
                        'chunks' => $chunkCount,
                        'error' => $exception->getMessage(),
                    ]);

                    return false;
                }
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('Failed to prepare Telegram service activation notification.', [
                'order_id' => $paymentOrder->id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Notify a user when an administrator rejects their payment receipt.
     */
    public function sendPaymentRejected(Order $order, string $reason): bool
    {
        try {
            $order->loadMissing(['user', 'plan']);

            $chatId = trim((string) ($order->user?->telegram_chat_id ?? ''));
            if ($chatId === '') {
                Log::info('Telegram receipt-rejection notification skipped: user has no Telegram chat ID.', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                ]);

                return false;
            }

            if (! $this->configureBot()) {
                return false;
            }

            $reason = trim($reason) !== '' ? trim($reason) : 'دلیل مشخص نشده است.';
            $orderType = ! $order->plan_id
                ? 'شارژ کیف پول'
                : ($order->renews_order_id ? 'تمدید سرویس' : 'خرید سرویس');

            $htmlMessage = "❌ <b>رسید پرداخت شما تایید نشد</b>\n\n";
            $htmlMessage .= '🧾 <b>شماره سفارش:</b> <code>#'.$this->escapeHtml((string) $order->id)."</code>\n";
            $htmlMessage .= '📌 <b>نوع سفارش:</b> '.$this->escapeHtml($orderType)."\n";

            if ($order->plan) {
                $htmlMessage .= '📦 <b>پلن:</b> '.$this->escapeHtml($order->plan->name)."\n";
            }

            $htmlMessage .= "📝 <b>دلیل رد:</b> ".$this->escapeHtml($reason)."\n\n";
            $htmlMessage .= 'پرداخت تایید نشده و وضعیت سفارش شما به «رد شده» تغییر کرد. برای پیگیری می‌توانید با پشتیبانی تماس بگیرید.';

            $plainMessage = "❌ رسید پرداخت شما تایید نشد\n\n";
            $plainMessage .= "🧾 شماره سفارش: #{$order->id}\n";
            $plainMessage .= "📌 نوع سفارش: {$orderType}\n";

            if ($order->plan) {
                $plainMessage .= "📦 پلن: {$order->plan->name}\n";
            }

            $plainMessage .= "📝 دلیل رد: {$reason}\n\n";
            $plainMessage .= 'پرداخت تایید نشده و وضعیت سفارش شما به «رد شده» تغییر کرد. برای پیگیری می‌توانید با پشتیبانی تماس بگیرید.';

            $keyboard = Keyboard::make()->inline()
                ->row([
                    Keyboard::inlineButton([
                        'text' => '📝 ایجاد تیکت پشتیبانی',
                        'callback_data' => '/support_new',
                    ]),
                    Keyboard::inlineButton([
                        'text' => '🏠 منوی اصلی',
                        'callback_data' => '/start',
                    ]),
                ]);

            return $this->sendHtmlWithPlainFallback(
                $chatId,
                $htmlMessage,
                $plainMessage,
                $keyboard,
                'payment_rejected',
                $order->id,
            );
        } catch (Throwable $exception) {
            Log::error('Failed to prepare Telegram receipt-rejection notification.', [
                'order_id' => $order->id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function configureBot(): bool
    {
        $defaultBot = config('telegram.default', 'mybot');
        $candidates = [
            Setting::where('key', 'telegram_bot_token')->value('value'),
            config("telegram.bots.{$defaultBot}.token"),
            config('telegrambot.bot_token'),
        ];

        $token = null;

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '' && ! str_starts_with($candidate, 'YOUR-')) {
                $token = $candidate;
                break;
            }
        }

        if ($token === null) {
            Log::error('Telegram order notification was not sent: bot token is not configured.');

            return false;
        }

        Telegram::setAccessToken($token);

        return true;
    }

    private function serviceKeyboard(int $serviceOrderId): Keyboard
    {
        return Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton([
                    'text' => '📋 کپی لینک کانفیگ',
                    'callback_data' => "copy_link_{$serviceOrderId}",
                ]),
                Keyboard::inlineButton([
                    'text' => '📱 QR Code',
                    'callback_data' => "qrcode_order_{$serviceOrderId}",
                ]),
            ])
            ->row([
                Keyboard::inlineButton([
                    'text' => '🛠 سرویس‌های من',
                    'callback_data' => '/my_services',
                ]),
                Keyboard::inlineButton([
                    'text' => '🏠 منوی اصلی',
                    'callback_data' => '/start',
                ]),
            ]);
    }

    private function sendHtmlWithPlainFallback(
        string $chatId,
        string $htmlMessage,
        string $plainMessage,
        ?Keyboard $keyboard,
        string $notificationType,
        int $orderId,
    ): bool {
        $payload = [
            'chat_id' => $chatId,
            'text' => $htmlMessage,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard) {
            $payload['reply_markup'] = $keyboard;
        }

        try {
            Telegram::sendMessage($payload);

            return true;
        } catch (Throwable $htmlException) {
            Log::warning('Formatted Telegram order notification failed; trying plain text.', [
                'order_id' => $orderId,
                'notification_type' => $notificationType,
                'error' => $htmlException->getMessage(),
            ]);

            $payload['text'] = $plainMessage;
            unset($payload['parse_mode']);

            try {
                Telegram::sendMessage($payload);

                return true;
            } catch (Throwable $plainException) {
                Log::error('Telegram order notification failed.', [
                    'order_id' => $orderId,
                    'notification_type' => $notificationType,
                    'formatted_error' => $htmlException->getMessage(),
                    'plain_error' => $plainException->getMessage(),
                ]);

                return false;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function splitConfig(string $config): array
    {
        $chunks = [];

        while (mb_strlen($config) > self::CONFIG_CHUNK_LENGTH) {
            $chunks[] = mb_substr($config, 0, self::CONFIG_CHUNK_LENGTH);
            $config = mb_substr($config, self::CONFIG_CHUNK_LENGTH);
        }

        if ($config !== '' || $chunks === []) {
            $chunks[] = $config;
        }

        return $chunks;
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
