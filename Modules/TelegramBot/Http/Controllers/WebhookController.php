<?php

namespace Modules\TelegramBot\Http\Controllers;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\TelegramBotSetting;
use App\Services\ClientNamingService;
use App\Services\SubscriptionImportService;
use App\Services\TelegramOrderNotificationService;
use App\Services\VlessParserService;
use App\Services\XUIService;
use App\Models\User;
use App\Services\MarzbanService;
use App\Services\PasarGuardService;
use App\Models\Inbound;
use Modules\Reseller\Models\Reseller;
use Modules\Ticketing\Events\TicketCreated;
use Modules\Ticketing\Events\TicketReplied;
use Modules\Ticketing\Models\Ticket;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http; // ✅ اضافه شده
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Keyboard\Keyboard;
use Illuminate\Support\Str;
use App\Models\DiscountCode;
use App\Models\DiscountCodeUsage;
use Carbon\Carbon;
use Telegram\Bot\FileUpload\InputFile;

class WebhookController extends BaseController
{
    protected $settings;

    /**
     * ✅ اضافه شده: کانستراکتور برای اطمینان از مقداردهی settings
     */
    public function __construct()
    {
        $this->settings = \collect();
    }

    public function sendBroadcastMessage(string $chatId, string $message): bool
    {
        try {
            if ($this->settings->isEmpty()) { // ✅ اصلاح: استفاده از isEmpty() به جای null check
                $this->settings = Setting::all()->pluck('value', 'key')->map(
                    fn ($value) => Setting::normalizeValue($value)
                );
            }

            $botToken = $this->settings->get('telegram_bot_token');
            if (!$botToken) {
                Log::error('❌ Cannot send broadcast message: bot token is not set.');
                return false;
            }

            // ✅ اصلاح: استفاده از Telegram facade بدون بک‌اسلش اضافی
            Telegram::setAccessToken($botToken);

            $title = "📢 *اعلان ویژه از سوی تیم مدیریت*";
            $divider = str_repeat('━', 20);
            $footer = "💠 *با تشکر از همراهی شما* 💠";

            $formattedMessage = $this->escape($message);

            $fullMessage = "{$title}\n\n{$divider}\n\n📝 *{$formattedMessage}*\n\n{$divider}\n\n{$footer}";

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $fullMessage,
                'parse_mode' => 'MarkdownV2',
            ]);

            Log::info("✅ Broadcast message sent successfully to chat {$chatId}");
            return true;
        } catch (\Exception $e) {
            Log::warning("⚠️ Failed to send broadcast message to user {$chatId}: " . $e->getMessage());
            return false;
        }
    }

    public function sendSingleMessageToUser(string $chatId, string $message): bool
    {
        try {
            if ($this->settings->isEmpty()) { // ✅ اصلاح
                $this->settings = Setting::all()->pluck('value', 'key')->map(
                    fn ($value) => Setting::normalizeValue($value)
                );
            }
            $botToken = $this->settings->get('telegram_bot_token');
            if (!$botToken) {
                Log::error('Cannot send single Telegram message: bot token is not set.');
                return false;
            }
            Telegram::setAccessToken($botToken);

            $header = "📢 *پیام فوری از مدیریت*";
            // ✅ اصلاح: نقطه در MarkdownV2 باید escape شود اما توی کپشن نیاز نیست
            $notice = "⚠️ این یک پیام اطلاع‌رسانی یک‌طرفه از پنل ادمین است و پاسخ دادن به آن در این چت، پیگیری نخواهد شد.";

            $adminMessageLines = explode("\n", $message);
            $formattedMessage = implode("\n", array_map(fn($line) => "> " . trim($line), $adminMessageLines));

            $fullMessage = "{$header}\n\n{$this->escape($notice)}\n\n{$formattedMessage}";

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $fullMessage,
                'parse_mode' => 'MarkdownV2',
            ]);

            Log::info("Admin sent message to user {$chatId}.", ['message' => $message]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send single Telegram message: ' . $e->getMessage(), ['chat_id' => $chatId, 'message' => $message]);
            return false;
        }
    }

    /**
     * ارسال پیام وضعیت مسدودی/رفع مسدودی به کاربر در تلگرام
     */
    public function sendBanStatusMessage(User $user, ?string $reason = null, bool $banned = true): bool
    {
        $chatId = (string) $user->telegram_chat_id;
        if (empty($chatId)) {
            return false;
        }

        try {
            if ($this->settings->isEmpty()) {
                $this->settings = Setting::all()->pluck('value', 'key')->map(
                    fn ($value) => Setting::normalizeValue($value)
                );
            }
            $botToken = $this->settings->get('telegram_bot_token');
            if (!$botToken) {
                Log::error('Cannot send ban status message: bot token is not set.');
                return false;
            }
            Telegram::setAccessToken($botToken);

            if ($banned) {
                $text = "🚫 *حساب شما مسدود شد*\n\n";
                $text .= $this->escape("دسترسی شما به سایت و ربات تلگرام توسط مدیریت مسدود شده است.") . "\n";
                if (!empty($reason)) {
                    $text .= "\n📝 *دلیل:* " . $this->escape($reason) . "\n";
                }
                $text .= "\n" . $this->escape("در صورت نیاز با پشتیبانی تماس بگیرید.");
            } else {
                $text = "✅ *حساب شما فعال شد*\n\n";
                $text .= $this->escape("مسدودی حساب شما توسط مدیریت برداشته شد و دسترسی شما به سایت و ربات تلگرام برقرار است.") . "\n";
                $text .= "\n" . $this->escape("از همراهی شما متشکریم.");
            }

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'MarkdownV2',
            ]);

            Log::info("Ban status message sent to user {$chatId}.", ['banned' => $banned, 'reason' => $reason]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send ban status Telegram message: ' . $e->getMessage(), [
                'user_id' => $user->id ?? null,
                'banned' => $banned,
            ]);
            return false;
        }
    }

    /**
     * ارسال پیام «مسدود هستید» به کاربری که در ربات تلگرام فعالیت می‌کند
     */
    protected function sendBannedMessage(int $chatId): void
    {
        try {
            if ($this->settings->isEmpty()) {
                $this->settings = Setting::all()->pluck('value', 'key')->map(
                    fn ($value) => Setting::normalizeValue($value)
                );
            }
            $botToken = $this->settings->get('telegram_bot_token');
            if (!$botToken) {
                return;
            }
            Telegram::setAccessToken($botToken);

            $text = "🚫 *حساب شما مسدود شده است*\n\n";
            $text .= $this->escape("دسترسی شما به ربات توسط مدیریت مسدود شده است.") . "\n";
            $text .= $this->escape("در صورت نیاز با پشتیبانی تماس بگیرید.");

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'MarkdownV2',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send banned message: ' . $e->getMessage(), ['chat_id' => $chatId]);
        }
    }

    public function sendResellerRequestApprovedMessage(User $user): bool
    {
        $chatId = (string) $user->telegram_chat_id;
        if (!$chatId) {
            return false;
        }

        try {
            if ($this->settings->isEmpty()) {
                $this->settings = Setting::all()->pluck('value', 'key')->map(
                    fn ($value) => Setting::normalizeValue($value)
                );
            }
            $botToken = $this->settings->get('telegram_bot_token');
            if (!$botToken) {
                Log::error('Cannot send approval message: bot token is not set.');
                return false;
            }
            Telegram::setAccessToken($botToken);

            $text = "🎉 *درخواست نمایندگی شما تایید شد*\n\n";
            $text .= "اکنون حساب نمایندگی شما فعال شده و می‌توانید از طریق پنل نمایندگی، سرور و اکانت برای مشتریان خود بسازید.\n\n";
            $text .= "برای ورود به پنل نمایندگی، روی دکمه زیر بزنید:";

            $keyboard = Keyboard::make()->inline()
                ->row([
                    Keyboard::inlineButton([
                        'text' => '🏢 ورود به پنل نمایندگی',
                        'callback_data' => 'agent_check_status',
                    ]),
                ])
                ->row([
                    Keyboard::inlineButton([
                        'text' => '🏠 بازگشت به منوی اصلی',
                        'callback_data' => '/start',
                    ]),
                ]);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->escape($text),
                'parse_mode' => 'MarkdownV2',
                'reply_markup' => $keyboard,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send reseller request approval Telegram message: ' . $e->getMessage(), [
                'user_id' => $user->id ?? null,
            ]);
            return false;
        }
    }

    public function sendResellerRequestRejectedMessage(User $user, ?string $reason = null): bool
    {
        $chatId = (string) $user->telegram_chat_id;
        if (!$chatId) {
            return false;
        }

        try {
            if ($this->settings->isEmpty()) {
                $this->settings = Setting::all()->pluck('value', 'key')->map(
                    fn ($value) => Setting::normalizeValue($value)
                );
            }
            $botToken = $this->settings->get('telegram_bot_token');
            if (!$botToken) {
                Log::error('Cannot send rejection message: bot token is not set.');
                return false;
            }
            Telegram::setAccessToken($botToken);

            $reasonText = $reason ?: 'مشخص نشده';

            $text = "❌ *درخواست نمایندگی شما تایید نشد*\n\n";
            $text .= "دلیل: {$reasonText}\n\n";
            $text .= "اگر مایل باشید می‌توانید برای توضیحات بیشتر یک تیکت پشتیبانی ثبت کنید.";

            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton([
                    'text' => '📝 ایجاد تیکت پشتیبانی',
                    'callback_data' => '/support_menu',
                ])])
                ->row([Keyboard::inlineButton([
                    'text' => '⬅️ بازگشت به منوی اصلی',
                    'callback_data' => '/start',
                ])]);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->escape($text),
                'parse_mode' => 'MarkdownV2',
                'reply_markup' => $keyboard,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send reseller request rejection Telegram message: ' . $e->getMessage(), [
                'user_id' => $user->id ?? null,
            ]);
            return false;
        }
    }

    public function handle(Request $request)
    {
        try {
            $this->settings = Setting::all()->pluck('value', 'key')->map(
                fn ($value) => Setting::normalizeValue($value)
            );
            $botToken = $this->settings->get('telegram_bot_token');
            if (!$botToken) {
                Log::warning('Telegram bot token is not set.');
                return response('ok', 200);
            }
            Telegram::setAccessToken($botToken);
            $update = Telegram::getWebhookUpdate();

            if ($update->isType('callback_query')) {
                $this->handleCallbackQuery($update);
            } elseif ($update->has('message')) {
                $message = $update->getMessage();
                if ($message->has('text')) {
                    $this->handleTextMessage($update);
                } elseif ($message->has('photo')) {
                    $this->handlePhotoMessage($update);
                }
            }
        } catch (\Exception $e) {
            Log::error('Telegram Bot Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
        return response('ok', 200);
    }




    protected function sendSiteCredentials(User $user, ?int $messageId = null)
    {
        $chatId = $user->telegram_chat_id;
        $username = $user->email; // Use email as username for now
        
        $loginUrl = $this->settings->get('site_login_url');
        if (empty($loginUrl)) {
            $loginUrl = route('login');
        }

        $message = "🔐 *اطلاعات ورود به پنل کاربری*\n\n";
        $message .= "👤 *نام کاربری:* `{$username}`\n";
        $message .= "🔑 *کلمه عبور:* " . $this->escape("(مخفی)") . "\n\n";
        $message .= "🌐 *آدرس ورود:*\n" . $this->escape($loginUrl) . "\n\n";
        $message .= "⚠️ *نکته:* " . $this->escape("اگر رمز عبور خود را فراموش کرده‌اید یا اولین بار است که وارد می‌شوید، می‌توانید یک رمز عبور جدید بسازید.");

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '🔄 ساخت رمز عبور جدید', 'callback_data' => 'generate_new_password']),
            ]);

        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    protected function generateNewPassword(User $user, ?int $messageId = null)
    {
        $newPassword = Str::random(10); // Generate a 10-char random password
        $user->password = Hash::make($newPassword);
        $user->save();

        $chatId = $user->telegram_chat_id;
        $username = $user->email;
        
        $loginUrl = $this->settings->get('site_login_url');
        if (empty($loginUrl)) {
            $loginUrl = route('login');
        }

        $message = "✅ *رمز عبور جدید ساخته شد*\n\n";
        $message .= "👤 *نام کاربری:* `{$username}`\n";
        $message .= "🔑 *کلمه عبور جدید:* `{$newPassword}`\n\n";
        $message .= "🌐 *آدرس ورود:*\n" . $this->escape($loginUrl) . "\n\n";
        $message .= "⚠️ " . $this->escape("لطفاً این رمز را در جای امنی یادداشت کنید.");

        // We can just show a "Back" button or no button
        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '🗑 حذف پیام (امنیت)', 'callback_data' => '/cancel_action']),
            ]);

        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    protected function handleAgentMenu($user)
    {
        $chatId = $user->telegram_chat_id;

        // چک کردن وضعیت نمایندگی
        $reseller = $user->reseller;
        $resellerRequest = $user->resellerRequest;

        if (!$reseller && !$resellerRequest) {
            // هنوز درخواست نداده - پیشنهاد ثبت نام
            $this->showAgentRegistration($user);
            return;
        }

        // اگر نماینده فعال هست، داشبورد رو نشون بده
        if ($reseller && $reseller->status === 'active') {
            $this->showAgentDashboard($reseller, $user);
            return;
        }

        // اگر درخواست داره، وضعیت درخواست رو بررسی کن
        if ($resellerRequest) {
            switch ($resellerRequest->status) {
            case 'pending':
                $message = "⏳ *درخواست نمایندگی در انتظار بررسی*\n\n";
                $message .= $this->escape("درخواست شما برای نمایندگی در حال بررسی توسط ادمین است.") . "\n";
                $message .= $this->escape("لطفاً صبور باشید، پس از تایید به شما اطلاع داده خواهد شد.");

                $keyboard = Keyboard::make()->inline()
                    ->row([Keyboard::inlineButton(['text' => '🔄 بررسی مجدد وضعیت', 'callback_data' => 'agent_check_status'])])
                    ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منو', 'callback_data' => '/start'])]);

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => $keyboard
                ]);
                break;

            case 'rejected':
                $message = "❌ *درخواست نمایندگی رد شد*\n\n";
                $message .= "دلیل: " . $this->escape($resellerRequest->rejection_reason ?: 'مشخص نشده') . "\n\n";
                $message .= $this->escape("می‌توانید دوباره درخواست دهید.");

                $keyboard = Keyboard::make()->inline()
                    ->row([Keyboard::inlineButton(['text' => '📝 ثبت درخواست جدید', 'callback_data' => 'agent_register'])])
                    ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/start'])]);

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => $keyboard
                ]);
                break;

            case 'approved':
                // درخواست تایید شده، اما هنوز نماینده فعال نشده
                $message = "✅ *درخواست نمایندگی شما تایید شده*\n\n";
                $message .= $this->escape("لطفاً منتظر بمانید تا حساب نمایندگی شما فعال شود.");
                
                $keyboard = Keyboard::make()->inline()
                    ->row([Keyboard::inlineButton(['text' => '🔄 بررسی مجدد وضعیت', 'callback_data' => 'agent_check_status'])])
                    ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منو', 'callback_data' => '/start'])]);

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => $keyboard
                ]);
                break;
        }
        
        // اگر نماینده غیرفعال یا تعلیق شده باشه
        if ($reseller && in_array($reseller->status, ['inactive', 'banned'])) {
            $message = "🚫 *نمایندگی شما غیرفعال شده*\n\n";
            $message .= $this->escape($reseller->status === 'banned' ? "نمایندگی شما مسدود شده است." : "نمایندگی شما غیرفعال شده است.");
            $message .= "\n" . $this->escape("لطفاً با پشتیبانی تماس بگیرید.");

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => $this->getReplyMainMenu($chatId)
                ]);
        }
        
        // بستن if مربوط به بررسی درخواست
        }
    }


    /**
     * 📝 نمایش فرم ثبت نام نمایندگی
     */
    protected function showAgentRegistration($user)
    {
        $chatId = $user->telegram_chat_id;

        $agentPlan = \Modules\Reseller\Models\ResellerPlan::where('type', 'quota')
            ->where('is_active', true)
            ->first();

        $registrationFee = $agentPlan ? $agentPlan->price : 30000;
        $maxAccounts = $agentPlan ? $agentPlan->account_limit : 16;

        $message = "🏢 *درخواست نمایندگی*\n\n";
        $message .= $this->escape("با عضویت در سیستم نمایندگی می‌توانید:") . "\n";
        $message .= "✅ " . $this->escape("تا {$maxAccounts} اکانت بسازید و بفروشید") . "\n";
        $message .= "✅ " . $this->escape("سرور اختصاصی خریداری کنید") . "\n";
        $message .= "✅ " . $this->escape("از تعرفه ویژه نمایندگان استفاده کنید") . "\n\n";
        $message .= "💰 *هزینه ثبت‌نام: " . $this->escape(number_format($registrationFee) . " تومان") . "*\n";
        $message .= $this->escape("برای ثبت درخواست نمایندگی لطفاً با پشتیبانی تماس بگیرید.") . "\n";
        $message .= $this->escape("پس از پرداخت هزینه ثبت‌نام، رسید را برای پشتیبانی ارسال کنید.");

        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton([
                'text' => '📝 ایجاد تیکت پشتیبانی',
                'callback_data' => '/support_menu'
            ])])
            ->row([Keyboard::inlineButton([
                'text' => '⬅️ بازگشت',
                'callback_data' => '/start'
            ])]);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'MarkdownV2',
            'reply_markup' => $keyboard
        ]);
    }
    /**
     * 📊 داشبورد نماینده تایید شده
     */
    protected function showAgentDashboard($reseller, $user)
    {
        $chatId = $user->telegram_chat_id;

        $agentAccountPlan = \Modules\Reseller\Models\ResellerPlan::where('type', 'pay_as_you_go')
            ->where('is_active', true)
            ->first();

        $accountPrice = $agentAccountPlan ? $agentAccountPlan->price_per_account : 30000; // fallback

        $balance = number_format($reseller->wallet ? $reseller->wallet->balance : 0);
        $createdCount = $reseller->accounts()->count();
        $maxCount = $reseller->max_accounts;

        $message = "🏢 *پنل مدیریت نمایندگی*\n\n";
        $message .= "👤 نام: {$this->escape($user->name)}\n";
        $message .= "💰 موجودی: *" . $this->escape($balance . " تومان") . "*\n";
        $message .= "📊 وضعیت اکانت‌ها: *" . $this->escape("{$createdCount} / {$maxCount}") . "*\n";
        $message .= "💸 قیمت هر اکانت: *" . $this->escape(number_format($accountPrice) . " تومان") . "*\n\n";
        $message .= $this->escape("از دکمه‌های زیر برای مدیریت استفاده کنید:");

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '💰 شارژ کیف پول', 'callback_data' => 'agent_deposit']),
                Keyboard::inlineButton(['text' => '➕ ساخت اکانت', 'callback_data' => 'agent_create_account'])
            ])
            ->row([
                Keyboard::inlineButton(['text' => '📊 گزارشات', 'callback_data' => 'agent_reports']),
                Keyboard::inlineButton(['text' => '🖥 خرید سرور', 'callback_data' => 'agent_buy_server'])
            ])
            ->row([
                Keyboard::inlineButton(['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => '/start'])
            ]);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'MarkdownV2',
            'reply_markup' => $keyboard
        ]);
    }


    protected function handleTextMessage($update)
    {
        $message = $update->getMessage();
        if (!$message) {
            return;
        }

        $chat = $message->getChat();
        if (!$chat) {
            return;
        }

        $chatId = $chat->getId();
        $text = trim($message->getText() ?? '');

        // ═══════════════════════════════════════════════════════
        // Admin rejection reason flow (may not have a User record)
        // ═══════════════════════════════════════════════════════
        $pendingReject = \Illuminate\Support\Facades\Cache::get("admin_reject_order_{$chatId}");
        if ($pendingReject && !empty($text) && $text !== '/start' && $text !== '/cancel_action') {
            if (mb_strlen($text) < 2) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape("❌ دلیل باید حداقل ۲ حرف باشد. لطفاً دوباره وارد کنید یا /cancel_action را بزنید:"),
                    'parse_mode' => 'MarkdownV2',
                ]);
                return;
            }
            \Illuminate\Support\Facades\Cache::forget("admin_reject_order_{$chatId}");
            $this->executeRejection($pendingReject['order_id'], $chatId, $text);
            return;
        }

        // ═══════════════════════════════════════════════════════
        // Admin reply to ticket flow (may not have a User record)
        // ═══════════════════════════════════════════════════════
        $pendingAdminReply = \Illuminate\Support\Facades\Cache::get("admin_reply_ticket_{$chatId}");
        if ($pendingAdminReply) {
            if ($text === '/cancel_action') {
                \Illuminate\Support\Facades\Cache::forget("admin_reply_ticket_{$chatId}");
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape('✅ عملیات پاسخ به تیکت لغو شد.'),
                    'parse_mode' => 'MarkdownV2',
                ]);
                return;
            }

            $isPhotoOnly = $message->has('photo') && (empty(trim($text)) || $text === '[📎 فایل پیوست شد]');
            $messageText = $isPhotoOnly ? '[📎 پیوست تصویر]' : $text;

            if (empty(trim($messageText))) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape('❌ متن پاسخ نمی‌تواند خالی باشد.'),
                    'parse_mode' => 'MarkdownV2',
                ]);
                return;
            }

            $ticketId = $pendingAdminReply['ticket_id'];
            $ticket = \Modules\Ticketing\Models\Ticket::find($ticketId);
            if ($ticket) {
                $user = User::where('telegram_chat_id', $chatId)->first();
                if (!$user) {
                    $from = $message->getFrom();
                    $userFirstName = $from ? $from->getFirstName() ?? 'ادمین' : 'ادمین';
                    $user = User::create([
                        'name' => $userFirstName,
                        'email' => $chatId . '@telegram.admin',
                        'password' => Hash::make(Str::random(10)),
                        'telegram_chat_id' => $chatId,
                        'referral_code' => Str::random(8),
                        'is_admin' => true,
                    ]);
                }

                $replyData = ['user_id' => $user->id, 'message' => $messageText];
                if ($message->has('photo')) {
                    try { $replyData['attachment_path'] = $this->savePhotoAttachment($update, 'ticket_attachments'); }
                    catch (\Exception $e) { Log::error("Error saving photo for admin reply {$ticketId}: " . $e->getMessage()); }
                }
                $reply = $ticket->replies()->create($replyData);
                $ticket->update(['status' => 'answered']);

                \Illuminate\Support\Facades\Cache::forget("admin_reply_ticket_{$chatId}");
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape("✅ پاسخ شما برای تیکت #{$ticketId} ثبت شد."),
                    'parse_mode' => 'MarkdownV2',
                ]);
                event(new \Modules\Ticketing\Events\TicketReplied($reply));
            } else {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape('❌ تیکت مورد نظر یافت نشد.'),
                    'parse_mode' => 'MarkdownV2',
                ]);
                \Illuminate\Support\Facades\Cache::forget("admin_reply_ticket_{$chatId}");
            }
            return;
        }

        $user = User::where('telegram_chat_id', $chatId)->first();

        // 🚫 کاربر مسدود شده حق استفاده از ربات را ندارد
        if ($user && $user->isBanned()) {
            $user->update(['bot_state' => null]);
            $this->sendBannedMessage($chatId);
            return;
        }

        if ($user && !$this->isUserMemberOfChannel($user)) {
            $this->showChannelRequiredMessage($chatId);
            return;
        }

        if (!$user) {
            $from = $message->getFrom();
            $userFirstName = $from ? $from->getFirstName() ?? 'کاربر' : 'کاربر';
            $password = Str::random(10);
            $user = User::create([
                'name' => $userFirstName,
                'email' => $chatId . '@telegram.user',
                'password' => Hash::make($password),
                'telegram_chat_id' => $chatId,
                'referral_code' => Str::random(8),
            ]);

            if (!$this->isUserMemberOfChannel($user)) {
                $this->showChannelRequiredMessage($chatId);
                return;
            }

            $telegramSettings = TelegramBotSetting::pluck('value', 'key');
            $welcomeMessage = $telegramSettings->get('welcome_message', "🌟 خوش آمدید {$userFirstName} عزیز!\n\nبرای شروع، یکی از گزینه‌های منو را انتخاب کنید:");
            $welcomeMessage = str_replace('{userFirstName}', $userFirstName, $welcomeMessage);

            if (Str::startsWith($text, '/start ')) {
                $referralCode = Str::after($text, '/start ');
                $referrer = User::where('referral_code', $referralCode)->first();
                $referralEnabled = filter_var($this->settings->get('referral_enabled', '1'), FILTER_VALIDATE_BOOLEAN);

                if ($referrer && $referralEnabled && ! $referrer->isBanned() && $referrer->id !== $user->id) {
                    $user->referrer_id = $referrer->id;
                    $user->save();

                    $welcomeGift = (int) $this->settings->get('referral_welcome_gift', 0);
                    if ($welcomeGift > 0) {
                        $user->increment('balance', $welcomeGift);
                        $user->refresh();

                        Transaction::create([
                            'user_id' => $user->id,
                            'amount' => $welcomeGift,
                            'type' => Transaction::TYPE_DEPOSIT,
                            'status' => Transaction::STATUS_COMPLETED,
                            'description' => 'هدیه خوش‌آمدگویی دعوت از طرف ' . $referrer->name,
                            'metadata' => [
                                'referral_welcome_gift' => true,
                                'referrer_id' => $referrer->id,
                            ],
                        ]);

                        $welcomeMessage .= "\n\n🎁 هدیه خوش‌آمدگویی: " . number_format($welcomeGift) . " تومان به کیف پول شما اضافه شد.";

                        // Send custom welcome gift message to the new user
                        if ($user->telegram_chat_id && filter_var($this->settings->get('referral_telegram_notify_referred', '1'), FILTER_VALIDATE_BOOLEAN)) {
                            $tpl = $this->settings->get('referral_telegram_welcome_gift_message');
                            if ($tpl) {
                                $giftMsg = str_replace(
                                    ['{amount}', '{balance}'],
                                    [number_format($welcomeGift), number_format($user->balance)],
                                    $tpl
                                );
                                try {
                                    Telegram::sendMessage([
                                        'chat_id' => $user->telegram_chat_id,
                                        'text' => $giftMsg,
                                    ]);
                                } catch (\Exception $e) {
                                    Log::warning("Failed to send referral welcome gift notification: " . $e->getMessage());
                                }
                            }
                        }
                    }

                    // Notify referrer about the new join
                    if ($referrer->telegram_chat_id) {
                        $joinTpl = $this->settings->get('referral_telegram_referrer_join_message');
                        $referrerMessage = $joinTpl
                            ? str_replace(['{referral_name}'], [$userFirstName], $joinTpl)
                            : "👤 خبر خوب!\n\nکاربر جدیدی با نام «{$userFirstName}» با لینک دعوت شما به ربات پیوست.";
                        try {
                            Telegram::sendMessage(['chat_id' => $referrer->telegram_chat_id, 'text' => $referrerMessage]);
                        } catch (\Exception $e) {
                            Log::error("Failed to send referral notification: " . $e->getMessage());
                        }
                    }
                }
            }

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $welcomeMessage,
                'reply_markup' => $this->getReplyMainMenu($chatId)
            ]);
            return;
        }

        if ($user->bot_state) {
            if ($user->bot_state === 'awaiting_deposit_amount') {
                $this->processDepositAmount($user, $text);
            } elseif ($user->bot_state === 'awaiting_import_subscription') {
                $this->processImportSubscription($user, $text);
            } elseif (Str::startsWith($user->bot_state, 'awaiting_new_ticket_') || Str::startsWith($user->bot_state, 'awaiting_ticket_reply')) {
                $this->processTicketConversation($user, $text, $update);
            } elseif (Str::startsWith($user->bot_state, 'awaiting_discount_code|')) {
                $orderId = (int) Str::after($user->bot_state, 'awaiting_discount_code|');
                $this->processDiscountCode($user, $orderId, $text);
            }
            elseif (Str::startsWith($user->bot_state, 'awaiting_username_for_order|')) {
                $planId = (int) Str::after($user->bot_state, 'awaiting_username_for_order|');
                $this->processUsername($user, $planId, $text);
            }
            elseif (Str::startsWith($user->bot_state, 'awaiting_admin_rejection_reason|')) {
                $orderId = (int) Str::after($user->bot_state, 'awaiting_admin_rejection_reason|');
                $this->processAdminRejectionReason($user, $orderId, $text);
            }

            return;
        }

        switch ($text) {
            case '🛒 خرید سرویس':
                $this->sendPlans($chatId);
                break;
            case '🛠 سرویس‌های من':
                $this->sendMyServices($user);
                break;
            case '💰 کیف پول':
                $this->sendWalletMenu($user);
                break;
            case '📜 تاریخچه تراکنش‌ها':
                $this->sendTransactions($user);
                break;
            case '💬 پشتیبانی':
                $this->showSupportMenu($user);
                break;
            case '🎁 دعوت از دوستان':
                if (!filter_var($this->settings->get('referral_enabled', '1'), FILTER_VALIDATE_BOOLEAN)) {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $this->escape("⚠️ سیستم دعوت از دوستان در حال حاضر غیرفعال است."),
                        'parse_mode' => 'MarkdownV2',
                        'reply_markup' => $this->getReplyMainMenu($chatId),
                    ]);
                } else {
                    $this->sendReferralMenu($user);
                }
                break;
            case '📚 راهنمای اتصال':
                $this->sendTutorialsMenu($chatId);
                break;
            case '❓ سوالات متداول':
            case '/faq':
                $this->sendFaqList($chatId);
                break;
            case '🧪 اکانت تست':
                if (!$this->shouldShowTrialButton()) {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $this->escape("⚠️ دریافت اکانت تست در حال حاضر غیرفعال است."),
                        'parse_mode' => 'MarkdownV2',
                        'reply_markup' => $this->getReplyMainMenu($chatId),
                    ]);
                } else {
                    $this->handleTrialRequest($user);
                }
                break;
            case '🏢 نمایندگی':
                if (!filter_var($this->settings->get('tg_show_reseller_button', '1'), FILTER_VALIDATE_BOOLEAN)) {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $this->escape("⚠️ ثبت‌نام نمایندگی در حال حاضر غیرفعال است."),
                        'parse_mode' => 'MarkdownV2',
                        'reply_markup' => $this->getReplyMainMenu($chatId),
                    ]);
                } else {
                    $this->handleAgentMenu($user);
                }
                break;
            // case '🔐 اطلاعات ورود به سایت':
            //     $this->sendSiteCredentials($user);
            //     break;
            case '📥 ورود اشتراک قبلی به ربات':
            case 'Import Existing Subscription':
                $this->showImportPrompt($user);
                break;


            case '/start':
                $telegramSettings = TelegramBotSetting::pluck('value', 'key');
                $startMessage = $telegramSettings->get('start_message', 'سلام مجدد! لطفاً یک گزینه را انتخاب کنید:');
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape($startMessage),
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => $this->getReplyMainMenu($chatId)
                ]);
                break;
            default:
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'دستور شما نامفهوم است. لطفاً از دکمه‌های منو استفاده کنید.',
                    'reply_markup' => $this->getReplyMainMenu($chatId)
                ]);
                break;
        }
    }

    protected function processUsername($user, $planId, $username)
    {
        $username = trim($username);

        if (strlen($username) < 3) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ نام کاربری باید حداقل ۳ کاراکتر باشد."),
                'parse_mode' => 'MarkdownV2'
            ]);
            $this->promptForUsername($user, $planId);
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ نام کاربری فقط می‌تواند شامل حروف انگلیسی و اعداد باشد."),
                'parse_mode' => 'MarkdownV2'
            ]);
            $this->promptForUsername($user, $planId);
            return;
        }

        // بررسی یکتا بودن نام کاربری (فقط در سفارش‌های پرداخت شده)
        $existingOrder = Order::where('panel_username', $username)->where('status', 'paid')->first();
        if ($existingOrder) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ این نام کاربری قبلاً استفاده شده است. لطفاً نام دیگری وارد کنید."),
                'parse_mode' => 'MarkdownV2'
            ]);
            $this->promptForUsername($user, $planId);
            return;
        }

        $locationId = null;
        if ($user->bot_state && Str::contains($user->bot_state, 'selected_loc:')) {
            preg_match('/selected_loc:(\d+)/', $user->bot_state, $matches);
            if (!empty($matches[1])) {
                $locationId = (int) $matches[1];
            }
        }

        $this->startPurchaseProcess($user, $planId, $username, null, $locationId);
    }

    protected function promptForUsername(User $user, int $planId, ?int $messageId = null, ?int $locationId = null)
    {
        // If sequential naming is enabled, auto-generate and skip prompt
        if (\App\Services\ClientNamingService::isEnabled()) {
            $generated = \App\Services\ClientNamingService::generate($user->id, null);
            $this->startPurchaseProcess($user, $planId, $generated, $messageId, $locationId);
            return;
        }

        $newState = 'awaiting_username_for_order|' . $planId;

        if ($locationId) {
            $newState .= '|selected_loc:' . $locationId;
        }
        elseif ($user->bot_state && Str::contains($user->bot_state, 'selected_loc:')) {
            $parts = explode('|', $user->bot_state);
            foreach ($parts as $part) {
                if (Str::startsWith($part, 'selected_loc:')) {
                    $newState .= '|' . $part;
                    break;
                }
            }
        }

        $user->update(['bot_state' => $newState]);

        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        $message = "👤 *انتخاب نام کاربری سرویس*\n\n";
        $message .= "لطفاً یک نام کاربری انگلیسی برای سرویس خود وارد کنید\\.\n";
        $message .= "🔹 فقط حروف انگلیسی و اعداد مجاز است \\(حداقل ۳ حرف\\)\\.\n";
        $message .= "🔹 مثال: `arvin123` یا `myvpn`";

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function showImportPrompt(User $user, ?int $messageId = null)
    {
        $user->update(['bot_state' => 'awaiting_import_subscription']);

        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);

        $message = "📥 *Import Existing Subscription*\n\n";
        $message .= $this->escape("لطفاً یکی از موارد زیر را ارسال کنید:") . "\n\n";
        $message .= "1️⃣ " . $this->escape("یک لینک VLESS (مثال: vless://...)") . "\n";
        $message .= "2️⃣ " . $this->escape("یک Subscription URL (مثال: https://...)") . "\n\n";
        $message .= $this->escape("سیستم UUID را استخراج کرده و در پنل‌های شما جستجو می‌کند.") . "\n";
        $message .= $this->escape("در صورت یافت شدن، اشتراک به حساب شما متصل می‌شود و مانند اشتراک‌های عادی عمل می‌کند.");

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function processImportSubscription(User $user, string $input, ?int $messageId = null)
    {
        $input = trim($input);

        // Telegram retries webhook updates when panel lookup takes long enough.
        // Serialize imports per user/input and remember successful deliveries so
        // the retry cannot send a second progress message or a duplicate error.
        $cacheKey = 'telegram-import-complete:' . $user->id . ':' . hash('sha256', $input);
        $lock = null;
        try {
            $cache = \Illuminate\Support\Facades\Cache::store();
            if ($cache->has($cacheKey)) {
                return;
            }

            $lock = $cache->lock('telegram-import-lock:' . $user->id . ':' . hash('sha256', $input), 180);
            if (! $lock->get()) {
                // The first webhook is still processing and will send the only
                // progress/success response.
                return;
            }
        } catch (\Throwable $e) {
            // Import must remain available if a deployment has not provisioned
            // the configured cache-lock backend yet.
            Log::warning('Telegram import idempotency lock unavailable.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->performImportSubscription($user, $input, $messageId, $cacheKey);
        } finally {
            if ($lock) {
                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    Log::debug('Could not release Telegram import lock.', ['error' => $e->getMessage()]);
                }
            }
        }
    }

    protected function performImportSubscription(User $user, string $input, ?int $messageId = null, ?string $cacheKey = null)
    {
        $input = trim($input);

        if (empty($input)) {
            $this->showImportPrompt($user, $messageId);
            return;
        }

        // Basic security: limit length
        if (strlen($input) > 10000) {
            $this->sendImportFailureMessage($user, 'ورودی بیش از حد طولانی است.', $messageId);
            return;
        }

        // A failure to send this progress update must not interrupt an import that can
        // otherwise complete successfully.
        try {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("⏳ در حال بررسی و وارد کردن اشتراک... لطفاً صبر کنید."),
                'parse_mode' => 'MarkdownV2',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not send Telegram import progress message.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $result = SubscriptionImportService::import($input, $user, 'telegram');

            $this->clearImportState($user);

            if ($result['success'] ?? false) {
                $order = $result['order'] ?? null;
                if (! $order instanceof Order) {
                    throw new \UnexpectedValueException('Subscription import succeeded without an order.');
                }

                // Mark before sending the response: a Telegram webhook retry
                // arriving during/after this call must not emit another progress
                // message (or process the same subscription again).
                if ($cacheKey) {
                    try {
                        \Illuminate\Support\Facades\Cache::put($cacheKey, $order->id, now()->addMinutes(10));
                    } catch (\Throwable $e) {
                        Log::debug('Could not cache completed Telegram import.', ['error' => $e->getMessage()]);
                    }
                }

                // Do not let MarkdownV2 formatting turn an already imported account
                // into a misleading “system error” for the customer.
                $this->sendImportedSubscriptionSuccessMessage($user, $order, $messageId);
                return;
            }

            $this->sendImportFailureMessage($user, (string) ($result['error'] ?? 'خطای نامشخص'), $messageId);
        } catch (\Throwable $e) {
            Log::error('Telegram import exception', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->clearImportState($user);
            $this->sendImportSystemErrorMessage($user, $messageId);
        }
    }

    /**
     * Clear the one-shot import state without masking an import result that has
     * already been persisted successfully.
     */
    protected function clearImportState(User $user): void
    {
        try {
            $user->update(['bot_state' => null]);
        } catch (\Throwable $e) {
            Log::error('Could not clear Telegram import state.', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a valid MarkdownV2 message for a successfully imported account.
     *
     * Telegram treats '-' as a reserved MarkdownV2 character. The old message
     * inserted dates such as "2026-08-07" without escaping those hyphens, so
     * Telegram rejected the success message and the surrounding catch block
     * reported a false system error after the order had already been imported.
     */
    protected function buildImportSuccessMessage(Order $order): string
    {
        $panelUsername = trim((string) ($order->panel_username ?? '')) ?: '—';
        $uuid = trim((string) ($order->panel_client_id ?? '')) ?: '—';
        $expiresAt = $order->expires_at ? to_jalali_date($order->expires_at, 'Y/m/d') : 'نامحدود';
        $configPreview = trim((string) ($order->config_details ?? '')) ?: '—';

        // Str::limit is multibyte-safe, unlike substr(), which could split a
        // Persian character in a VLESS remark and produce invalid UTF-8.
        $configPreview = Str::limit($configPreview, 80, '…');

        $importMeta = $order->import_meta ?? [];
        $trafficLine = null;
        if (isset($importMeta['remaining_traffic']) && $importMeta['remaining_traffic'] !== null) {
            $totalBytes = $importMeta['totalGB'] ?? $importMeta['data_limit'] ?? null;
            $totalGB = ($totalBytes && $totalBytes > 0) ? round($totalBytes / 1073741824, 1) : null;
            $remainingGB = round($importMeta['remaining_traffic'] / 1073741824, 2);
            $usedGB = round(($importMeta['used_traffic'] ?? 0) / 1073741824, 2);
            if ($totalGB !== null) {
                $trafficLine = "{$remainingGB} GB باقی‌مانده از {$totalGB} GB (مصرف: {$usedGB} GB)";
            } else {
                $trafficLine = "نامحدود (مصرف: {$usedGB} GB)";
            }
        } elseif (!empty($importMeta['totalGB']) && $importMeta['totalGB'] > 0) {
            $trafficLine = round($importMeta['totalGB'] / 1073741824, 1) . ' GB (کل)';
        } elseif (!empty($importMeta['data_limit']) && $importMeta['data_limit'] > 0) {
            $trafficLine = round($importMeta['data_limit'] / 1073741824, 1) . ' GB (کل)';
        }

        $message = "✅ *اشتراک با موفقیت وارد شد*\n\n";
        $message .= "👤 *نام کاربری:* `{$this->escapeTelegramCode($panelUsername)}`\n";
        $message .= "🔑 *UUID:* `{$this->escapeTelegramCode($uuid)}`\n";
        $message .= "📅 *انقضا:* {$this->escape($expiresAt)}\n";
        if ($trafficLine) {
            $message .= "📦 *ترافیک:* {$this->escape($trafficLine)}\n";
        }
        $message .= "🔗 *لینک:* `{$this->escapeTelegramCode($configPreview)}`\n\n";
        $message .= $this->escape("این اشتراک اکنون مانند اشتراک‌های عادی در بخش سرویس‌های من قابل مشاهده است.");

        return $message;
    }

    /**
     * Escape text inside a MarkdownV2 inline-code entity.
     *
     * In code entities Telegram only requires backslashes and backticks to be
     * escaped. Escaping every Markdown character here can make configuration
     * links difficult to read or copy.
     */
    protected function escapeTelegramCode(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);

        return str_replace('`', '\\`', $text);
    }

    protected function sendImportedSubscriptionSuccessMessage(User $user, Order $order, ?int $messageId = null): void
    {
        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '🛠 سرویس‌های من', 'callback_data' => '/my_services'])])
            ->row([Keyboard::inlineButton(['text' => '🏠 منوی اصلی', 'callback_data' => '/start'])]);

        // sendOrEditMessage has a plain-text fallback. Therefore a Telegram
        // formatting/API problem cannot change a completed import into an error.
        $this->sendOrEditMessage(
            (int) $user->telegram_chat_id,
            $this->buildImportSuccessMessage($order),
            $keyboard,
            $messageId,
        );
    }

    protected function sendImportFailureMessage(User $user, string $error, ?int $messageId = null): void
    {
        $message = "❌ *خطا در وارد کردن اشتراک*\n\n";
        $message .= $this->escape($error);

        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '🔄 تلاش مجدد', 'callback_data' => 'import_retry'])])
            ->row([Keyboard::inlineButton(['text' => '🏠 منوی اصلی', 'callback_data' => '/start'])]);

        $this->sendOrEditMessage((int) $user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function sendImportSystemErrorMessage(User $user, ?int $messageId = null): void
    {
        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '🏠 منوی اصلی', 'callback_data' => '/start'])]);

        $this->sendOrEditMessage(
            (int) $user->telegram_chat_id,
            $this->escape("❌ خطای سیستمی در وارد کردن اشتراک. لطفاً بعداً تلاش کنید."),
            $keyboard,
            $messageId,
        );
    }

    /**
     * ارسال مجدد لینک اکانت تست (برای کپی آسان)
     */
    protected function handleTrialCopyLink(User $user, ?int $messageId = null)
    {
        try {
            $link = \Illuminate\Support\Facades\Cache::get("trial_link_{$user->id}");

            if (!$link) {
                Telegram::sendMessage([
                    'chat_id' => $user->telegram_chat_id,
                    'text' => $this->escape("❌ لینک اکانت تست منقضی شده یا یافت نشد.\nلطفاً اکانت تست جدیدی دریافت کنید."),
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => Keyboard::make()->inline()->row([
                        Keyboard::inlineButton(['text' => '🧪 دریافت اکانت تست', 'callback_data' => 'trial_request'])
                    ])
                ]);
                return;
            }

            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => "📋 *لینک اکانت تست شما:*\n\n`{$link}`\n\n" . $this->escape("روی لینک بالا کلیک کنید تا کپی شود."),
                'parse_mode' => 'MarkdownV2',
                'reply_markup' => Keyboard::make()->inline()->row([
                    Keyboard::inlineButton(['text' => '⬅️ بازگشت به منو', 'callback_data' => '/start'])
                ])
            ]);

        } catch (\Exception $e) {
            Log::error('Trial copy link error: ' . $e->getMessage());
        }
    }

    /**
     * ارسال QR Code برای اکانت تست
     */
    protected function sendTrialQRCode(User $user, ?int $messageId = null)
    {
        try {
            $link = \Illuminate\Support\Facades\Cache::get("trial_link_{$user->id}");

            if (!$link) {
                Telegram::sendMessage([
                    'chat_id' => $user->telegram_chat_id,
                    'text' => $this->escape("❌ لینک اکانت تست منقضی شده."),
                    'parse_mode' => 'MarkdownV2'
                ]);
                return;
            }

            $tempFile = null;
            try {
                $qrParams = [
                    'size' => '400x400',
                    'data' => $link,
                    'ecc' => 'M',
                    'margin' => 10,
                    'format' => 'png'
                ];

                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?" . http_build_query($qrParams);

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $qrUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 30
                ]);

                $qrData = curl_exec($ch);
                curl_close($ch);

                if (!$qrData) throw new \Exception("QR generation failed");

                $tempDir = storage_path('app/temp');
                if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

                $tempFile = $tempDir . '/qr_trial_' . $user->id . '_' . time() . '.png';
                file_put_contents($tempFile, $qrData);

                Telegram::sendPhoto([
                    'chat_id' => $user->telegram_chat_id,
                    'photo' => InputFile::create($tempFile),
                    'caption' => $this->escape("📱 QR Code اکانت تست\n\nلینک:\n`{$link}`"),
                    'parse_mode' => 'MarkdownV2'
                ]);

            } finally {
                if ($tempFile && file_exists($tempFile)) {
                    @unlink($tempFile);
                }
            }

        } catch (\Exception $e) {
            Log::error('Trial QR error: ' . $e->getMessage());
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ خطا در ساخت QR Code"),
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    }

    protected function handleCallbackQuery($update)
    {
        $callbackQuery = $update->getCallbackQuery();
        if (!$callbackQuery) {
            return;
        }
        
        $message = $callbackQuery->getMessage();
        if (!$message) {
            return;
        }
        
        $chat = $message->getChat();
        if (!$chat) {
            return;
        }
        
        $chatId = $chat->getId();
        $messageId = $message->getMessageId();
        $data = $callbackQuery->getData();

        // ═══════════════════════════════════════════════════════
        // Admin callback handlers (no user account required)
        // ═══════════════════════════════════════════════════════
        if (Str::startsWith($data, 'admin_approve_')) {
            $this->handleAdminApproveCallback($callbackQuery, $data, $chatId, $messageId);
            return;
        }
        if (Str::startsWith($data, 'admin_reject_')) {
            $this->handleAdminRejectCallback($callbackQuery, $data, $chatId, $messageId);
            return;
        }
        if (Str::startsWith($data, 'admin_reject_confirm_')) {
            $this->handleAdminRejectConfirmCallback($callbackQuery, $data, $chatId, $messageId);
            return;
        }
        if ($data === 'admin_reject_cancel') {
            \Illuminate\Support\Facades\Cache::forget("admin_reject_order_{$chatId}");
            try {
                Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId(), 'text' => 'عملیات لغو شد.']);
            } catch (\Exception $e) {}
            try { Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]); } catch (\Exception $e) {}
            return;
        }

        // Admin reply to ticket (works even if the admin has no User record)
        if (Str::startsWith($data, 'admin_reply_ticket_')) {
            $ticketId = (int) Str::after($data, 'admin_reply_ticket_');
            \Illuminate\Support\Facades\Cache::put("admin_reply_ticket_{$chatId}", ['ticket_id' => $ticketId, 'message_id' => $messageId], now()->addMinutes(30));
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQuery->getId(),
                    'text' => "لطفاً پاسخ خود را برای تیکت #{$ticketId} وارد کنید.",
                    'show_alert' => false,
                ]);
            } catch (\Exception $e) {}
            $keyboard = Keyboard::make()->inline()->row([
                Keyboard::inlineButton([
                    'text' => '❌ انصراف',
                    'callback_data' => 'admin_reply_cancel',
                ]),
            ]);
            // ⚠️ متن باید با escape() برای MarkdownV2 آماده شود، وگرنه تلگرام پیام را با خطای
            // "can't parse entities" رد می‌کند و ادمین هیچ پیامی برای نوشتن پاسخ نمی‌بیند.
            $message = "✉️ " . $this->escape("لطفاً پاسخ خود را برای تیکت #{$ticketId} وارد کنید (می‌توانید عکس هم ارسال کنید):");
            $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
            return;
        }

        // Admin close ticket (works even if the admin has no User record)
        if (Str::startsWith($data, 'admin_close_ticket_')) {
            $ticketId = (int) Str::after($data, 'admin_close_ticket_');
            $ticket = \Modules\Ticketing\Models\Ticket::find($ticketId);
            if ($ticket && $ticket->status !== 'closed') {
                $ticket->update(['status' => 'closed']);
                try {
                    Telegram::answerCallbackQuery([
                        'callback_query_id' => $callbackQuery->getId(),
                        'text' => "تیکت #{$ticketId} بسته شد.",
                        'show_alert' => false,
                    ]);
                } catch (\Exception $e) {}
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape("✅ تیکت #{$ticketId} بسته شد."),
                    'parse_mode' => 'MarkdownV2',
                ]);
            } else {
                try {
                    Telegram::answerCallbackQuery([
                        'callback_query_id' => $callbackQuery->getId(),
                        'text' => 'تیکت یافت نشد یا قبلاً بسته شده است.',
                        'show_alert' => true,
                    ]);
                } catch (\Exception $e) {}
            }
            return;
        }

        // Admin cancel ticket reply (works even if the admin has no User record)
        if ($data === 'admin_reply_cancel') {
            \Illuminate\Support\Facades\Cache::forget("admin_reply_ticket_{$chatId}");
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQuery->getId(),
                    'text' => 'عملیات پاسخ به تیکت لغو شد.',
                ]);
            } catch (\Exception $e) {}
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->escape('✅ عملیات پاسخ به تیکت لغو شد.'),
                'parse_mode' => 'MarkdownV2',
            ]);
            return;
        }

        $user = User::where('telegram_chat_id', $chatId)->first();

        // 🚫 کاربر مسدود شده حق استفاده از ربات را ندارد
        if ($user && $user->isBanned()) {
            $user->update(['bot_state' => null]);
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQuery->getId(),
                    'text' => 'حساب شما مسدود شده است.',
                    'show_alert' => true,
                ]);
            } catch (\Exception $e) {}
            $this->sendBannedMessage($chatId);
            return;
        }

        if ($user && !$this->isUserMemberOfChannel($user)) {
            $this->showChannelRequiredMessage($chatId, $messageId);
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => 'ابتدا باید در کانال عضو شوید!',
                'show_alert' => true
            ]);
            return;
        }

        if (!$user) {
            Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ کاربر یافت نشد. لطفاً با دستور /start ربات را مجدداً راه‌اندازی کنید."), 'parse_mode' => 'MarkdownV2']);
            return;
        }

        if (Str::startsWith($data, 'show_duration_')) {
            try {
                Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
            } catch (\Exception $e) {}

            $durationDays = (int)Str::after($data, 'show_duration_');
            $this->sendPlansByDuration($chatId, $durationDays, $messageId);
            return;
        }

        if (Str::startsWith($data, 'show_service_')) {
            try {
                Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
            } catch (\Exception $e) {}

            $orderId = (int) Str::after($data, 'show_service_');
            $this->showServiceDetails($user, $orderId, $messageId);
            return;
        }

        if (Str::startsWith($data, 'faq_view_')) {
            try {
                Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
            } catch (\Exception $e) {}

            $faqId = (int) Str::after($data, 'faq_view_');
            $this->sendFaqAnswer($chatId, $faqId, $messageId);
            return;
        }

        try {
            Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
        } catch (\Exception $e) { Log::warning('Could not answer callback query: ' . $e->getMessage()); }

        if (!Str::startsWith($data, ['/deposit_custom', '/support_new', 'reply_ticket_', 'enter_discount_'])) {
            $user->update(['bot_state' => null]);
        }

        if (Str::startsWith($data, 'select_loc_')) {
            $parts = explode('_', $data);

            if (count($parts) >= 5) {
                $locationId = $parts[2];
                $planId = $parts[4];

                if (class_exists('Modules\MultiServer\Models\Location')) {
                    $location = \Modules\MultiServer\Models\Location::find($locationId);
                    $plan = Plan::find($planId);
                    $serverType = $plan ? ($plan->server_type ?? 'all') : 'all';

                    if ($location) {
                        $query = $location->servers()->where('is_active', true);
                        
                        if ($serverType !== 'all') {
                            $query->where('type', $serverType);
                        }

                        $totalCapacity = $query->sum('capacity');
                        $totalUsed = $query->sum('current_users');

                        if ($totalUsed >= $totalCapacity) {
                            $settings = Setting::all()->pluck('value', 'key');
                            $msg = $settings->get('ms_full_location_message') ?? "❌ ظرفیت تکمیل است.";

                            Telegram::answerCallbackQuery([
                                'callback_query_id' => $callbackQuery->getId(),
                                'text' => $msg,
                                'show_alert' => true
                            ]);
                            return;
                        }
                    }
                }
                $this->promptForUsername($user, $planId, $messageId, $locationId);
                return;
            }
        }

        if (Str::startsWith($data, 'buy_plan_')) {
            $planId = (int) Str::after($data, 'buy_plan_');

            $isMultiLocationEnabled = filter_var(
                $this->settings->get('enable_multilocation', false),
                FILTER_VALIDATE_BOOLEAN
            );

            if ($isMultiLocationEnabled && class_exists('Modules\MultiServer\Models\Location')) {
                $this->promptForLocation($user, $planId, $messageId);
                return;
            }

            $this->promptForUsername($user, $planId, $messageId);
            return;
        }
        elseif (Str::startsWith($data, 'pay_wallet_')) {
            $input = Str::after($data, 'pay_wallet_');
            $this->processWalletPayment($user, $input, $messageId);
        } elseif (Str::startsWith($data, 'pay_card_')) {
            $orderId = (int) Str::after($data, 'pay_card_');
            $this->sendCardPaymentInfo($chatId, $orderId, $messageId);
        }

        elseif ($data === 'trial_request') {
            // The inline menu uses callback_data instead of the reply-keyboard text.
            // Keep this path in sync with the regular "🧪 اکانت تست" button.
            $this->handleTrialRequest($user);
        }
        elseif (Str::startsWith($data, 'copy_trial_link_')) {
            $userId = (int) Str::after($data, 'copy_trial_link_');
            $this->handleTrialCopyLink($user, $messageId);
        }
        elseif (Str::startsWith($data, 'qr_trial_')) {
            $this->sendTrialQRCode($user, $messageId);
        }

        elseif (Str::startsWith($data, 'enter_discount_')) {
            $orderId = (int) Str::after($data, 'enter_discount_');
            $this->promptForDiscount($user, $orderId, $messageId);
        }
        elseif (Str::startsWith($data, 'copy_link_')) {
            $orderId = (int) Str::after($data, 'copy_link_');
            $this->handleCopyLinkRequest($user, $orderId);
        }

        elseif (Str::startsWith($data, 'remove_discount_')) {
            $orderId = (int) Str::after($data, 'remove_discount_');
            $this->removeDiscount($user, $orderId, $messageId);
        } elseif (Str::startsWith($data, 'qrcode_order_')) {
            $orderId = (int) Str::after($data, 'qrcode_order_');
            $this->sendQRCodeForOrder($user, $orderId);
        } elseif (Str::startsWith($data, 'renew_order_')) {
            $originalOrderId = (int) Str::after($data, 'renew_order_');
            $this->startRenewalPurchaseProcess($user, $originalOrderId, $messageId);
        } elseif (Str::startsWith($data, 'renew_plan_')) {
            $payload = Str::after($data, 'renew_plan_');
            [$orderId, $planId] = array_pad(explode('_', $payload, 2), 2, null);
            $this->showRenewPlanConfirmation($user, (int)$orderId, (int)$planId, $messageId);
        } elseif (Str::startsWith($data, 'renew_pay_wallet_')) {
            $payload = Str::after($data, 'renew_pay_wallet_');
            $parts = explode('_', $payload);
            $originalOrderId = (int) ($parts[0] ?? 0);
            $planId = isset($parts[1]) ? (int)$parts[1] : null;
            $this->processRenewalWalletPayment($user, $originalOrderId, $messageId, $planId);
        } elseif (Str::startsWith($data, 'renew_pay_card_')) {
            $payload = Str::after($data, 'renew_pay_card_');
            $parts = explode('_', $payload);
            $originalOrderId = (int) ($parts[0] ?? 0);
            $planId = isset($parts[1]) ? (int)$parts[1] : null;
            $this->handleRenewCardPayment($user, $originalOrderId, $messageId, $planId);
        } elseif (Str::startsWith($data, 'deposit_amount_')) {
            $amount = (int) Str::after($data, 'deposit_amount_');
            $this->processDepositAmount($user, $amount, $messageId);
        } elseif ($data === '/deposit_custom') {
            $this->promptForCustomDeposit($user, $messageId);
        } elseif (Str::startsWith($data, 'close_ticket_')) {
            $ticketId = (int) Str::after($data, 'close_ticket_');
            $callbackQueryId = $callbackQuery ? $callbackQuery->getId() : null;
            $this->closeTicket($user, $ticketId, $messageId, $callbackQueryId);
        }

        elseif (Str::startsWith($data, 'agent_')) {
            $this->handleAgentCallbacks($user, $data, $messageId);
        }

        elseif (Str::startsWith($data, 'reply_ticket_')) {
            $ticketId = (int) Str::after($data, 'reply_ticket_');
            $this->promptForTicketReply($user, $ticketId, $messageId);
        } elseif ($data === '/support_new') {
            $this->promptForNewTicket($user, $messageId);
        } elseif ($data === 'generate_new_password') {
            $this->generateNewPassword($user, $messageId);
        } else {
            switch ($data) {
                case '/start':
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => '🌟 منوی اصلی',
                        'reply_markup' => $this->getReplyMainMenu($chatId)
                    ]);
                    try { Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]); } catch (\Exception $e) {}
                    break;
                case '/import_subscription':
                    $this->showImportPrompt($user, $messageId);
                    break;
                case '/plans': $this->sendPlans($chatId, $messageId); break;
                case '/my_services': $this->sendMyServices($user, $messageId); break;
                case '/wallet': $this->sendWalletMenu($user, $messageId); break;
                case '/referral':
                    if (!filter_var($this->settings->get('referral_enabled', '1'), FILTER_VALIDATE_BOOLEAN)) {
                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => $this->escape("⚠️ سیستم دعوت از دوستان در حال حاضر غیرفعال است."),
                            'parse_mode' => 'MarkdownV2',
                            'reply_markup' => $this->getReplyMainMenu($chatId),
                        ]);
                    } else {
                        $this->sendReferralMenu($user, $messageId);
                    }
                    break;
                case '/support_menu': $this->showSupportMenu($user, $messageId); break;
                case '/deposit': $this->showDepositOptions($user, $messageId); break;
                case '/transactions': $this->sendTransactions($user, $messageId); break;
                case '/tutorials': $this->sendTutorialsMenu($chatId, $messageId); break;
                case '/faq': $this->sendFaqList($chatId, $messageId); break;
                case '/tutorial_android': $this->sendTutorial('android', $chatId, $messageId); break;
                case '/tutorial_ios': $this->sendTutorial('ios', $chatId, $messageId); break;
                case '/tutorial_windows': $this->sendTutorial('windows', $chatId, $messageId); break;
                case '/check_membership':
                    if ($this->isUserMemberOfChannel($user)) {
                        Telegram::answerCallbackQuery([
                            'callback_query_id' => $callbackQuery->getId(),
                            'text' => 'عضویت شما تأیید شد!',
                            'show_alert' => false
                        ]);
                        try { Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]); } catch (\Exception $e) {}
                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'خوش آمدید! حالا می‌توانید از ربات استفاده کنید.',
                            'reply_markup' => $this->getReplyMainMenu($chatId)
                        ]);
                    } else {
                        Telegram::answerCallbackQuery([
                            'callback_query_id' => $callbackQuery->getId(),
                            'text' => 'هنوز عضو کانال نشده‌اید. لطفاً اول عضو شوید.',
                            'show_alert' => true
                        ]);
                        $this->showChannelRequiredMessage($chatId, $messageId);
                    }
                    break;

                case 'import_retry':
                    $this->showImportPrompt($user, $messageId);
                    break;
                case '/cancel_action':
                    $user->update(['bot_state' => null]);
                    try { Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]); } catch (\Exception $e) {}
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => '✅ عملیات لغو شد.',
                        'reply_markup' => $this->getReplyMainMenu($chatId),
                    ]);
                    break;
                default:
                    Log::warning('Unknown callback data received:', ['data' => $data, 'chat_id' => $chatId]);
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'دستور نامعتبر.',
                        'reply_markup' => $this->getReplyMainMenu($chatId),
                    ]);
                    break;
            }
        }
    }

    /**
     * Handlerهای مربوط به نمایندگی
     */
    protected function handleAgentCallbacks($user, $data, $messageId)
    {
        $chatId = $user->telegram_chat_id;
        $reseller = $user->reseller;

        switch ($data) {
            case 'agent_check_status':
                // رفرش وضعیت
                $this->handleAgentMenu($user);
                try {
                    Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]);
                } catch (\Exception $e) {}
                break;

            case 'agent_register':
                // باز کردن مینی‌اپ ثبت نام
                $this->showAgentRegistration($user);
                break;

            case 'agent_deposit':
                // شارژ کیف پول - بدون Mini App
                if (!$reseller || $reseller->status !== 'active') {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $this->escape('❌ شما نماینده فعال نیستید.'),
                        'parse_mode' => 'MarkdownV2'
                    ]);
                    return;
                }

                $walletBalance = $reseller->wallet ? $reseller->wallet->balance : 0;
                $message = "💰 *شارژ کیف پول نمایندگی*\n\n";
                $message .= "💳 موجودی فعلی: *" . $this->escape(number_format($walletBalance) . " تومان") . "*\n\n";
                $message .= $this->escape("برای شارژ کیف پول نمایندگی لطفاً با پشتیبانی تماس بگیرید و موضوع شارژ نمایندگی را مطرح کنید.") . "\n";
                $message .= $this->escape("پس از تایید پرداخت توسط ادمین، موجودی شما افزایش خواهد یافت.");

                $keyboard = Keyboard::make()->inline()
                    ->row([Keyboard::inlineButton(['text' => '📝 تماس با پشتیبانی', 'callback_data' => '/support_menu'])])
                    ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'agent_back_to_dashboard'])]);

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => $keyboard
                ]);
                break;

            case 'agent_buy_server':
                // خرید سرور - بدون Mini App
                if (!$reseller || $reseller->status !== 'active') {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $this->escape('❌ شما نماینده فعال نیستید.'),
                        'parse_mode' => 'MarkdownV2'
                    ]);
                    return;
                }

                $walletBalance = $reseller->wallet ? $reseller->wallet->balance : 0;
                $message = "🖥 *خرید سرور اختصاصی*\n\n";
                $message .= "💰 موجودی فعلی: *" . $this->escape(number_format($walletBalance) . " تومان") . "*\n\n";
                $message .= $this->escape("پلن‌های موجود:") . "\n";
                $message .= "• " . $this->escape("سرور ۱۰۰ نفره: ۵۰۰,۰۰۰ تومان") . "\n";
                $message .= "• " . $this->escape("سرور ۲۰۰ نفره: ۹۰۰,۰۰۰ تومان") . "\n";
                $message .= "• " . $this->escape("سرور ۵۰۰ نفره: ۲,۰۰۰,۰۰۰ تومان") . "\n\n";
                $message .= $this->escape("برای خرید سرور لطفاً با پشتیبانی تماس بگیرید.");

                $keyboard = Keyboard::make()->inline()
                    ->row([Keyboard::inlineButton(['text' => '📝 تماس با پشتیبانی', 'callback_data' => '/support_menu'])])
                    ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'agent_back_to_dashboard'])]);

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'MarkdownV2',
                    'reply_markup' => $keyboard
                ]);
                break;

            case 'agent_create_account':
                // ساخت اکانت جدید (مستقیم یا از طریق مینی‌اپ)
                if (!$reseller || $reseller->status !== 'active') {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $this->escape('❌ شما نماینده فعال نیستید.'),
                        'parse_mode' => 'MarkdownV2'
                    ]);
                    return;
                }

                if ($reseller->accounts()->count() >= $reseller->max_accounts && $reseller->servers()->where('is_active', true)->count() === 0) {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $this->escape('⚠️ ظرفیت اکانت‌های شما تکمیل است!\n\nبرای ساخت اکانت بیشتر، ابتدا سرور خریداری کنید.'),
                        'parse_mode' => 'MarkdownV2',
                        'reply_markup' => Keyboard::make()->inline()
                            ->row([Keyboard::inlineButton(['text' => '🖥 خرید سرور', 'callback_data' => 'agent_buy_server'])])
                            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'agent_back_to_dashboard'])])
                    ]);
                    return;
                }

                // اینجا می‌تونی مستقیم فرآیند ساخت اکانت رو شروع کنی
                // یا کاربر رو به مینی‌اپ هدایت کنی
                $this->startAgentAccountCreation($user, $reseller, $messageId);
                break;

            case 'agent_reports':
                // نمایش گزارشات
                $this->showAgentReports($reseller, $chatId, $messageId);
                break;

            case 'agent_back_to_dashboard':
                // بازگشت به داشبورد
                try {
                    Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]);
                } catch (\Exception $e) {}
                $this->showAgentDashboard($reseller, $user);
                break;

            default:
                if (Str::startsWith($data, 'agent_select_server_')) {
                    $serverId = (int) Str::after($data, 'agent_select_server_');
                    $this->processAgentServerSelection($user, $reseller, $serverId, $messageId);
                }
                break;
        }
    }

    /**
     * شروع فرآیند ساخت اکانت برای نماینده
     */
    protected function startAgentAccountCreation($user, $reseller, $messageId)
    {
        $chatId = $user->telegram_chat_id;

        // گرفتن سرورهای فعال نماینده
        $servers = $reseller->servers()->where('is_active', true)->get();

        if ($servers->isEmpty()) {
            // استفاده از سرورهای اصلی سیستم (با قیمت نمایندگی)
            $this->sendPlans($chatId, $messageId, true); // true = isAgentMode
            return;
        }

        // نمایش لیست سرورهای خود نماینده
        $message = "🖥 *انتخاب سرور*\n\n";
        $message .= "لطفاً سرور مورد نظر برای ساخت اکانت را انتخاب کنید:\n\n";

        $keyboard = Keyboard::make()->inline();

        foreach ($servers as $server) {
            $available = $server->capacity - $server->current_users;
            $status = $available > 0 ? "🟢 {$available} ظرفیت" : "🔴 تکمیل";

            $message .= "• {$server->name}: {$status}\n";

            $keyboard->row([
                Keyboard::inlineButton([
                    'text' => $server->name . ' (' . $status . ')',
                    'callback_data' => 'agent_select_server_' . $server->id
                ])
            ]);
        }

        $message .= "\n💰 هزینه هر اکانت: " . number_format($reseller->plan ? $reseller->plan->price_per_account : 30000) . " تومان";
        $message .= "\n💳 موجودی: " . number_format($reseller->wallet ? $reseller->wallet->balance : 0) . " تومان";

        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'agent_back_to_dashboard'])]);

        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    /**
     * پردازش انتخاب سرور برای نماینده
     */
    protected function processAgentServerSelection(User $user, Reseller $reseller, int $serverId, int $messageId)
    {
        $chatId = $user->telegram_chat_id;
        
        // بررسی اینکه سرور متعلق به نماینده هست یا نه
        $server = $reseller->servers()->find($serverId);
        
        if (!$server) {
            $message = "❌ *سرور نامعتبر*\n\n";
            $message .= "این سرور یا وجود ندارد یا به شما تعلق ندارد.";
            
            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '🔄 تلاش مجدد', 'callback_data' => 'agent_create_account'])])
                ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'agent_back_to_dashboard'])]);
            
            $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
            return;
        }
        
        // بررسی ظرفیت سرور
        $currentAccounts = $reseller->accounts()->where('server_id', $serverId)->count();
        $maxCapacity = $server->max_accounts_per_reseller ?? 50;
        
        if ($currentAccounts >= $maxCapacity) {
            $message = "❌ *ظرفیت سرور تکمیل شده*\n\n";
            $message .= "ظرفیت این سرور برای نمایندگان تکمیل شده است.\n";
            $message .= "لطفاً سرور دیگری انتخاب کنید.";
            
            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '🔄 انتخاب سرور دیگر', 'callback_data' => 'agent_create_account'])])
                ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'agent_back_to_dashboard'])]);
            
            $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
            return;
        }
        
        // محاسبه هزینه
        $plan = $reseller->plan;
        $costPerAccount = $plan ? $plan->price_per_account : 30000;
        $currentBalance = $reseller->wallet ? $reseller->wallet->balance : 0;
        
        if ($currentBalance < $costPerAccount) {
            $message = "❌ *موجودی کافی نیست*\n\n";
            $message .= "هزینه ساخت اکانت: *" . number_format($costPerAccount) . " تومان*\n";
            $message .= "موجودی فعلی: *" . number_format($currentBalance) . " تومان*\n\n";
            $message .= "لطفاً ابتدا کیف پول خود را شارژ کنید.";
            
            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '💳 شارژ کیف پول', 'callback_data' => 'agent_deposit'])])
                ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => 'agent_back_to_dashboard'])]);
            
            $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
            return;
        }
        
        // نمایش اطلاعات برای تایید نهایی
        $message = "✅ *تایید ساخت اکانت*\n\n";
        $message .= "🖥 سرور: *{$server->name}*\n";
        $message .= "📍 موقعیت: *{$server->location}*\n";
        $message .= "💰 هزینه: *" . number_format($costPerAccount) . " تومان*\n";
        $message .= "💳 موجودی پس از کسر: *" . number_format($currentBalance - $costPerAccount) . " تومان*\n\n";
        $message .= "آیا از ساخت اکانت اطمینان دارید؟";
        
        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '✅ تایید و ساخت', 'callback_data' => 'agent_confirm_create_' . $serverId])])
            ->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => 'agent_back_to_dashboard'])]);
        
        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    /**
     * 📊 نمایش گزارشات نماینده
     */
    protected function showAgentReports(Reseller $reseller, int $chatId, int $messageId)
    {
        $totalAccounts = $reseller->accounts()->count();
        $activeAccounts = $reseller->accounts()->where('status', 'active')->count();
        $expiredAccounts = $reseller->accounts()->where('status', 'expired')->count();
        $totalRevenue = $reseller->transactions()
            ->where('type', 'purchase')
            ->sum('amount');
        $currentBalance = $reseller->wallet ? $reseller->wallet->balance : 0;
        
        $message = "📊 *گزارشات نمایندگی*\n\n";
        $message .= "👤 نماینده: {$reseller->user->name}\n";
        $message .= "📅 تاریخ گزارش: " . to_jalali_date(now(), 'Y/m/d') . "\n\n";
        $message .= "📈 *آمار کل:*\n";
        $message .= "• کل اکانت‌ها: *{$totalAccounts}*\n";
        $message .= "• اکانت‌های فعال: *{$activeAccounts}*\n";
        $message .= "• اکانت‌های منقضی: *{$expiredAccounts}*\n\n";
        $message .= "💰 *مالی:*\n";
        $message .= "• درآمد کل: *" . number_format($totalRevenue) . " تومان*\n";
        $message .= "• موجودی فعلی: *" . number_format($currentBalance) . " تومان*\n\n";
        $message .= "📊 *عملکرد:*\n";
        
        if ($totalAccounts > 0) {
            $activePercentage = round(($activeAccounts / $totalAccounts) * 100);
            $message .= "• نرخ فعال بودن: *{$activePercentage}%*\n";
        }
        
        $message .= "\n_برای مشاهده جزئیات بیشتر به پنل نمایندگی مراجعه کنید._";

        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '🔄 بروزرسانی گزارش', 'callback_data' => 'agent_reports'])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به داشبورد', 'callback_data' => 'agent_back_to_dashboard'])]);

        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    protected function promptForLocation($user, $planId, $messageId)
    {
        $plan = Plan::find($planId);
        $serverType = $plan ? ($plan->server_type ?? 'all') : 'all';

        $settings = Setting::all()->pluck('value', 'key');
        $showCapacity = filter_var($settings->get('ms_show_capacity', true), FILTER_VALIDATE_BOOLEAN);
        $hideFull = filter_var($settings->get('ms_hide_full_locations', false), FILTER_VALIDATE_BOOLEAN);

        // ✅ فیلتر کردن سرورها بر اساس نوع پلن
        $locations = \Modules\MultiServer\Models\Location::where('is_active', true)
            ->with(['servers' => function ($query) use ($serverType) {
                $query->where('is_active', true);
                if ($serverType !== 'all') {
                    $query->where('type', $serverType);
                }
            }])
            ->get();

        $keyboard = Keyboard::make()->inline();
        $hasAvailableLocation = false;

        foreach ($locations as $loc) {
            // ✅ استفاده از سرورهای فیلتر شده
            $relevantServers = $loc->servers;

            if ($relevantServers->isEmpty()) {
                continue;
            }

            $totalCapacity = $relevantServers->sum('capacity');
            $totalUsed = $relevantServers->sum('current_users');
            $remained = max(0, $totalCapacity - $totalUsed);
            $isFull = $remained <= 0;

            if ($isFull && $hideFull) {
                continue;
            }

            $hasAvailableLocation = true;
            $flag = $loc->flag ?? '🏳️';
            $btnText = "$flag {$loc->name}";

            if ($isFull) {
                $btnText .= " (تکمیل 🔒)";
            } elseif ($showCapacity) {
                $btnText .= " ({$remained} عدد)";
            }

            $keyboard->row([
                Keyboard::inlineButton([
                    'text' => $btnText,
                    'callback_data' => "select_loc_{$loc->id}_plan_{$planId}"
                ])
            ]);
        }

        if (!$hasAvailableLocation) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ متأسفانه ظرفیت تمام سرورها تکمیل شده است."),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, "🌍 *انتخاب لوکیشن*\n\nلطفاً کشور مورد نظر خود را انتخاب کنید:", $keyboard, $messageId);
    }

    protected function handlePhotoMessage($update)
    {
        $message = $update->getMessage();
        if (!$message) {
            return;
        }
        
        $chat = $message->getChat();
        if (!$chat) {
            return;
        }
        
        $chatId = $chat->getId();

        // ═══════════════════════════════════════════════════════
        // Admin reply to ticket flow (may not have a User record)
        // ═══════════════════════════════════════════════════════
        $pendingAdminReply = \Illuminate\Support\Facades\Cache::get("admin_reply_ticket_{$chatId}");
        if ($pendingAdminReply) {
            $messageText = $message->getCaption() ?? '[📎 فایل پیوست شد]';
            $isPhotoOnly = $message->has('photo') && (empty(trim($messageText)) || $messageText === '[📎 فایل پیوست شد]');
            $messageText = $isPhotoOnly ? '[📎 پیوست تصویر]' : $messageText;

            if ($messageText === '/cancel_action') {
                \Illuminate\Support\Facades\Cache::forget("admin_reply_ticket_{$chatId}");
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape('✅ عملیات پاسخ به تیکت لغو شد.'),
                    'parse_mode' => 'MarkdownV2',
                ]);
                return;
            }

            if (empty(trim($messageText))) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape('❌ متن پاسخ نمی‌تواند خالی باشد.'),
                    'parse_mode' => 'MarkdownV2',
                ]);
                return;
            }

            $ticketId = $pendingAdminReply['ticket_id'];
            $ticket = \Modules\Ticketing\Models\Ticket::find($ticketId);
            if ($ticket) {
                $user = User::where('telegram_chat_id', $chatId)->first();
                if (!$user) {
                    $from = $message->getFrom();
                    $userFirstName = $from ? $from->getFirstName() ?? 'ادمین' : 'ادمین';
                    $user = User::create([
                        'name' => $userFirstName,
                        'email' => $chatId . '@telegram.admin',
                        'password' => Hash::make(Str::random(10)),
                        'telegram_chat_id' => $chatId,
                        'referral_code' => Str::random(8),
                        'is_admin' => true,
                    ]);
                }

                $replyData = ['user_id' => $user->id, 'message' => $messageText];
                if ($message->has('photo')) {
                    try { $replyData['attachment_path'] = $this->savePhotoAttachment($update, 'ticket_attachments'); }
                    catch (\Exception $e) { Log::error("Error saving photo for admin reply {$ticketId}: " . $e->getMessage()); }
                }
                $reply = $ticket->replies()->create($replyData);
                $ticket->update(['status' => 'answered']);

                \Illuminate\Support\Facades\Cache::forget("admin_reply_ticket_{$chatId}");
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape("✅ پاسخ شما برای تیکت #{$ticketId} ثبت شد."),
                    'parse_mode' => 'MarkdownV2',
                ]);
                event(new \Modules\Ticketing\Events\TicketReplied($reply));
            } else {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $this->escape('❌ تیکت مورد نظر یافت نشد.'),
                    'parse_mode' => 'MarkdownV2',
                ]);
                \Illuminate\Support\Facades\Cache::forget("admin_reply_ticket_{$chatId}");
            }
            return;
        }

        $user = User::where('telegram_chat_id', $chatId)->first();

        // 🚫 کاربر مسدود شده حق استفاده از ربات را ندارد
        if ($user && $user->isBanned()) {
            $user->update(['bot_state' => null]);
            $this->sendBannedMessage($chatId);
            return;
        }

        if ($user && !$this->isUserMemberOfChannel($user)) {
            $this->showChannelRequiredMessage($chatId);
            return;
        }

        if (!$user || !$user->bot_state) {
            $this->sendOrEditMainMenu($chatId, "❌ لطفاً ابتدا یک عملیات (مانند ثبت تیکت یا رسید) را شروع کنید.");
            return;
        }

        if (Str::startsWith($user->bot_state, 'awaiting_ticket_reply|') || Str::startsWith($user->bot_state, 'awaiting_new_ticket_message|')) {
            $text = $message->getCaption() ?? '[📎 فایل پیوست شد]';
            $this->processTicketConversation($user, $text, $update);
            return;
        }

        if (Str::startsWith($user->bot_state, 'waiting_receipt_')) {
            $orderId = (int) Str::after($user->bot_state, 'waiting_receipt_');
            $order = Order::find($orderId);

            if ($order && $order->user_id === $user->id && $order->status === 'pending') {
                try {
                    $fileName = $this->savePhotoAttachment($update, 'receipts');
                    if (!$fileName) throw new \Exception("Failed to save photo attachment.");

                    $updateData = [
                        'card_payment_receipt' => $fileName,
                        'payment_method' => 'card',
                    ];
                    if (!$order->server_id) {
                        $serverId = $this->resolveBestServerId($order->plan);
                        if ($serverId) {
                            $updateData['server_id'] = $serverId;
                        }
                    }
                    $order->update($updateData);
                    $user->update(['bot_state' => null]);

                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $this->escape("✅ رسید شما با موفقیت ثبت شد. پس از بررسی توسط ادمین، نتیجه به شما اطلاع داده خواهد شد."),
                        'parse_mode' => 'MarkdownV2',
                    ]);
                    $this->sendOrEditMainMenu($chatId, "چه کار دیگری برایتان انجام دهم?");

                    // Sending a receipt to an administrator is an independent operation.
                    // In particular, one stale/blocked admin chat ID must not make a receipt
                    // that was already saved look like it failed for the customer.
                    $this->notifyAdminsOfReceipt($order, $user);
                } catch (\Exception $e) {
                    Log::error("Receipt processing failed for order {$orderId}: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                    Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ خطا در پردازش رسید. لطفاً دوباره تلاش کنید."), 'parse_mode' => 'MarkdownV2']);
                    $this->sendOrEditMainMenu($chatId, "لطفا دوباره تلاش کنید.");
                }
            } else {
                Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ سفارش نامعتبر است یا در انتظار پرداخت نیست."), 'parse_mode' => 'MarkdownV2']);
                $this->sendOrEditMainMenu($chatId, "لطفا وضعیت سفارش خود را بررسی کنید.");
            }
        }
    }

    /**
     * Send a submitted card receipt to every configured administrator.
     *
     * Delivery is deliberately isolated per chat ID. Telegram returns an error
     * for chat IDs that have not started the bot, blocked it, or are no longer
     * valid; that must neither stop delivery to the remaining administrators
     * nor cause the customer's successfully stored receipt to be reported as
     * failed.
     *
     * @return bool True when at least one administrator received the receipt.
     */
    public function notifyAdminsOfReceipt(Order $order, User $user): bool
    {
        if ($this->settings->isEmpty()) {
            $this->settings = Setting::all()->pluck('value', 'key')->map(
                fn ($value) => Setting::normalizeValue($value)
            );
        }

        $botToken = $this->settings->get('telegram_bot_token');
        $adminChatIds = array_values(array_unique(getTelegramAdminChatIds($this->settings)));

        if (empty($botToken) || empty($adminChatIds)) {
            Log::warning('Receipt notification was not sent: Telegram bot token or admin chat ID is missing.', [
                'order_id' => $order->id,
                'has_bot_token' => ! empty($botToken),
                'admin_chat_ids_count' => count($adminChatIds),
            ]);
            return false;
        }

        if (empty($order->card_payment_receipt) || ! Storage::disk('public')->exists($order->card_payment_receipt)) {
            Log::error('Receipt notification was not sent: receipt file does not exist.', [
                'order_id' => $order->id,
                'receipt_path' => $order->card_payment_receipt,
            ]);
            return false;
        }

        Telegram::setAccessToken($botToken);

        $orderType = $order->renews_order_id ? 'تمدید سرویس' : ($order->plan_id ? 'خرید سرویس' : 'شارژ کیف پول');
        // HTML avoids MarkdownV2 parsing failures caused by customer names and
        // is valid for Telegram photo captions as well as ordinary messages.
        $caption = '<b>🧾 رسید جدید برای سفارش #' . $order->id . "</b>\n\n";
        $caption .= '<b>کاربر:</b> ' . escapeTelegramHTML((string) $user->name) . ' (ID: <code>' . $user->id . "</code>)\n";
        $caption .= '<b>مبلغ:</b> ' . escapeTelegramHTML(number_format((float) $order->amount) . ' تومان') . "\n";
        $caption .= '<b>نوع سفارش:</b> ' . escapeTelegramHTML($orderType) . "\n\n";
        $caption .= 'با دکمه‌های زیر می‌توانید مستقیماً از ربات تأیید یا رد کنید.';

        $keyboard = Keyboard::make()->inline()->row([
            Keyboard::inlineButton(['text' => '✅ تایید و فعال‌سازی', 'callback_data' => "admin_approve_{$order->id}"]),
            Keyboard::inlineButton(['text' => '❌ رد فیش', 'callback_data' => "admin_reject_{$order->id}"]),
        ]);

        $filePath = Storage::disk('public')->path($order->card_payment_receipt);
        $sent = false;

        foreach ($adminChatIds as $adminChatId) {
            try {
                Telegram::sendPhoto([
                    'chat_id' => $adminChatId,
                    'photo' => InputFile::create($filePath),
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => $keyboard,
                ]);
                $sent = true;
            } catch (\Throwable $e) {
                Log::warning('Failed to send receipt to a Telegram administrator.', [
                    'order_id' => $order->id,
                    'admin_chat_id' => $adminChatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $sent) {
            Log::error('Receipt could not be delivered to any Telegram administrator.', ['order_id' => $order->id]);
        }

        return $sent;
    }

    // ========================================================================
    // 🛒 سیستم خرید و تخفیف
    // ========================================================================

    protected function resolveBestServerId(?Plan $plan = null, ?int $locationId = null): ?int
    {
        if (!class_exists('Modules\MultiServer\Models\Server')) {
            return null;
        }

        $serverType = $plan ? ($plan->server_type ?? 'all') : 'all';

        if ($locationId) {
            $query = \Modules\MultiServer\Models\Server::where('location_id', $locationId)
                ->where('is_active', true)
                ->whereRaw('current_users < capacity');

            if ($serverType !== 'all') {
                $query->where('type', $serverType);
            }

            $bestServer = $query->orderBy('current_users', 'asc')->first();
            if ($bestServer) {
                return $bestServer->id;
            }
        }

        $fallbackQuery = \Modules\MultiServer\Models\Server::where('is_active', true)
            ->whereRaw('current_users < capacity');
        if ($serverType !== 'all') {
            $fallbackQuery->where('type', $serverType);
        }
        $bestServer = $fallbackQuery->orderBy('current_users', 'asc')->first();
        if ($bestServer) {
            return $bestServer->id;
        }

        $bestServer = \Modules\MultiServer\Models\Server::where('is_active', true)
            ->whereRaw('current_users < capacity')
            ->orderBy('current_users', 'asc')
            ->first();
        if ($bestServer) {
            return $bestServer->id;
        }

        $bestServer = \Modules\MultiServer\Models\Server::where('is_active', true)->first();
        return $bestServer ? $bestServer->id : null;
    }

    protected function startPurchaseProcess($user, $planId, $username, $messageId = null, $locationId = null)
    {
        $plan = Plan::find($planId);
        if (!$plan) {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ پلن مورد نظر یافت نشد.", $messageId);
            return;
        }

        if (!$locationId && $user->bot_state && Str::contains($user->bot_state, 'selected_loc:')) {
            preg_match('/selected_loc:(\d+)/', $user->bot_state, $matches);
            if (!empty($matches[1])) {
                $locationId = (int) $matches[1];
            }
        }

        $serverId = $this->resolveBestServerId($plan, $locationId);

        if (!$serverId && class_exists('Modules\MultiServer\Models\Server') && \Modules\MultiServer\Models\Server::where('is_active', true)->exists()) {
            $user->update(['bot_state' => null]);
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ متأسفانه ظرفیت تمام سرورها تکمیل شده است."),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        $order = $user->orders()->create([
            'plan_id' => $plan->id,
            'server_id' => $serverId,
            'status' => 'pending',
            'source' => 'telegram',
            'amount' => $plan->price,
            'discount_amount' => 0,
            'discount_code_id' => null,
            'panel_username' => $username
        ]);

        $user->update(['bot_state' => null]);
        $this->showInvoice($user, $order, $messageId);
    }

    protected function showInvoice($user, Order $order, $messageId = null)
    {
        $plan = $order->plan;
        if (!$plan) {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ اطلاعات سفارش نامعتبر است.");
            return;
        }
        
        $balance = $user->balance ?? 0;

        $message = "🛒 *تایید خرید*\n\n";
        $message .= "▫️ پلن: *{$this->escape($plan->name)}*\n";

        if ($order->discount_amount > 0) {
            $originalPrice = number_format($plan->price);
            $finalPrice = number_format($order->amount);
            $discount = number_format($order->discount_amount);
            $message .= "▫️ قیمت اصلی: ~*{$originalPrice} تومان*~\n";
            $message .= "🎉 *قیمت با تخفیف:* *{$finalPrice} تومان*\n";
            $message .= "💰 سود شما: *{$discount} تومان*\n";
        } else {
            $message .= "▫️ قیمت: *" . number_format($order->amount) . " تومان*\n";
        }

        $message .= "▫️ موجودی کیف پول: *" . number_format($balance) . " تومان*\n\n";
        $message .= "لطفاً روش پرداخت را انتخاب کنید:";

        $keyboard = Keyboard::make()->inline();

        if (!$order->discount_code_id) {
            $keyboard->row([Keyboard::inlineButton(['text' => '🎫 ثبت کد تخفیف', 'callback_data' => "enter_discount_{$order->id}"])]);
        } else {
            $keyboard->row([Keyboard::inlineButton(['text' => '❌ حذف کد تخفیف', 'callback_data' => "remove_discount_{$order->id}"])]);
        }

        if ($balance >= $order->amount) {
            $keyboard->row([Keyboard::inlineButton(['text' => '✅ پرداخت با کیف پول', 'callback_data' => "pay_wallet_order_{$order->id}"])]); // ✅ اصلاح: فرمت callback_data یکسان شد
        }
        $keyboard->row([Keyboard::inlineButton(['text' => '💳 کارت به کارت', 'callback_data' => "pay_card_{$order->id}"])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به پلن‌ها', 'callback_data' => '/plans'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function promptForDiscount($user, $orderId, $messageId)
    {
        $user->update(['bot_state' => 'awaiting_discount_code|' . $orderId]);
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, "🎫 لطفاً کد تخفیف خود را ارسال کنید:", $keyboard, $messageId);
    }

    protected function processDiscountCode($user, $orderId, $codeText)
    {
        $order = Order::find($orderId);
        if (!$order || $order->status !== 'pending') {
            $user->update(['bot_state' => null]);
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سفارش منقضی شده است.");
            return;
        }

        $code = DiscountCode::where('code', $codeText)->first();
        $error = null;

        if (!$code) $error = '❌ کد تخفیف نامعتبر است.';
        elseif (!$code->is_active) $error = '❌ کد تخفیف غیرفعال است.';
        elseif ($code->starts_at && $code->starts_at > now()) $error = '❌ زمان استفاده از کد نرسیده است.';
        elseif ($code->expires_at && $code->expires_at < now()) $error = '❌ کد تخفیف منقضی شده است.';
        else {
            $totalAmount = $order->plan_id ? $order->plan->price : $order->amount;
            // ⚠️ نکته: اطمینان حاصل کنید که مدل DiscountCode متدهای isValidForOrder و calculateDiscount را دارد
            if (!$code->isValidForOrder($totalAmount, $order->plan_id, !$order->plan_id, (bool)$order->renews_order_id)) {
                $error = '❌ کد تخفیف شامل شرایط این سفارش نمی‌شود.';
            }
        }

        if ($error) {
            Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $this->escape($error), 'parse_mode' => 'MarkdownV2']);
            return;
        }

        $discountAmount = $code->calculateDiscount($order->plan->price ?? $order->amount);
        $finalAmount = ($order->plan->price ?? $order->amount) - $discountAmount;

        $order->update([
            'discount_amount' => $discountAmount,
            'discount_code_id' => $code->id,
            'amount' => $finalAmount
        ]);

        $user->update(['bot_state' => null]);
        Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $this->escape("✅ کد تخفیف اعمال شد!"), 'parse_mode' => 'MarkdownV2']);
        $this->showInvoice($user, $order);
    }

    protected function removeDiscount($user, $orderId, $messageId)
    {
        $order = Order::find($orderId);
        if ($order && $order->status === 'pending') {
            $originalPrice = $order->plan->price ?? ($order->amount + $order->discount_amount);
            $order->update([
                'discount_amount' => 0,
                'discount_code_id' => null,
                'amount' => $originalPrice
            ]);
            $this->showInvoice($user, $order, $messageId);
        }
    }


    protected function processWalletPayment($user, $input, $messageId)
    {
        $order = null;
        $plan = null;

        try {
            DB::transaction(function () use ($user, $input, &$order, &$plan) { // ✅ اضافه کردن &
                // 🔒 قفل کردن رکورد کاربر برای جلوگیری از دسترسی همزمان
                $lockedUser = User::lockForUpdate()->find($user->id);

                if (!$lockedUser) {
                    throw new \Exception('User not found');
                }

                // تشخیص سفارش موجود یا ساخت سفارش جدید
                if (Str::startsWith($input, 'order_')) {
                    $orderId = (int) Str::after($input, 'order_');
                    $order = Order::where('id', $orderId)
                        ->where('user_id', $lockedUser->id)
                        ->where('status', 'pending')
                        ->first();

                    if (!$order) {
                        throw new \Exception('سفارش نامعتبر است یا منقضی شده.');
                    }

                    $plan = $order->plan;
                    if (!$order->server_id) {
                        $order->update(['server_id' => $this->resolveBestServerId($plan)]);
                    }
                } else {
                    $planId = $input;
                    $plan = Plan::find($planId);

                    if (!$plan) {
                        throw new \Exception('پلن مورد نظر یافت نشد.');
                    }

                    // ساخت سفارش داخل تراکنش
                    $order = $lockedUser->orders()->create([
                        'plan_id' => $plan->id,
                        'server_id' => $this->resolveBestServerId($plan),
                        'status' => 'pending',
                        'source' => 'telegram',
                        'amount' => $plan->price,
                        'discount_amount' => 0,
                        'discount_code_id' => null,
                    ]);
                }

                // ✅ بررسی موجودی داخل تراکنش (با رکورد قفل شده)
                if ($lockedUser->balance < $order->amount) {
                    throw new \Exception('موجودی کافی نیست');
                }

                // کسر موجودی (Atomic)
                $lockedUser->decrement('balance', $order->amount);

                // بروزرسانی سفارش به پرداخت شده
                $order->update([
                    'status' => 'paid',
                    'payment_method' => 'wallet',
                    'server_id' => $order->server_id ?: $this->resolveBestServerId($plan),
                    'expires_at' => now()->addDays($plan->duration_days)
                ]);

                // ثبت استفاده از کد تخفیف
                if ($order->discount_code_id) {
                    $dc = DiscountCode::lockForUpdate()->find($order->discount_code_id);
                    if ($dc) {
                        DiscountCodeUsage::create([
                            'discount_code_id' => $dc->id,
                            'user_id' => $lockedUser->id,
                            'order_id' => $order->id,
                            'discount_amount' => $order->discount_amount,
                            'original_amount' => $plan->price
                        ]);
                        $dc->increment('used_count');
                    }
                }

                // ثبت تراکنش مالی
                Transaction::create([
                    'user_id' => $lockedUser->id,
                    'order_id' => $order->id,
                    'amount' => -$order->amount,
                    'type' => 'purchase',
                    'status' => 'completed',
                    'description' => "خرید سرویس {$plan->name} از طریق کیف پول"
                ]);

                // ساخت اکانت در پنل (X-UI یا Marzban)
                $provisionData = $this->provisionUserAccount($order, $plan);

                if ($provisionData && $provisionData['link']) {
                    $order->update([
                        'config_details' => $provisionData['link'],
                        'panel_username' => $provisionData['username'],
                        'panel_client_id' => $provisionData['panel_client_id'] ?? null,
                        'panel_sub_id' => $provisionData['panel_sub_id'] ?? null,
                    ]);
                } else {
                    throw new \Exception('خطا در ایجاد کانفیگ در پنل. لطفاً با پشتیبانی تماس بگیرید.');
                }
            });

            // The wallet purchase path used to mark the order as paid without emitting
            // OrderPaid. As a result, referral rewards (and their Telegram notification)
            // were only skipped for purchases made through the bot.
            //
            // Dispatch after the transaction has committed so the listener can see the
            // paid order and its transaction when it checks first-purchase eligibility.
            try {
                OrderPaid::dispatch($order->fresh());
            } catch (\Throwable $e) {
                // A referral/notification failure must not report a completed purchase as
                // failed to the customer. The reward listener is idempotent and can be
                // safely retried from the logged order if necessary.
                Log::error('Unable to process referral reward for Telegram wallet purchase.', [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // ارسال پیام موفقیت (خارج از تراکنش)
            // اطمینان از در دسترس بودن سفارش و پلن
            if (!$order || !$plan) {
                throw new \RuntimeException('Order or plan not available after wallet payment processing.');
            }

            $successKeyboard = Keyboard::make()->inline()->row([
                Keyboard::inlineButton(['text' => '🛠 سرویس‌های من', 'callback_data' => '/my_services']),
                Keyboard::inlineButton(['text' => '🏠 منوی اصلی', 'callback_data' => '/start']),
            ]);

            $this->sendOrEditMessage(
                $user->telegram_chat_id,
                $this->escape('✅ خرید با موفقیت انجام شد. اطلاعات اتصال در پیام جدید برای شما ارسال می‌شود.'),
                $successKeyboard,
                $messageId,
            );

            app(TelegramOrderNotificationService::class)
                ->sendServiceActivated($order->fresh());

        } catch (\Exception $e) {
            Log::error('Wallet Payment Failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'input' => $input,
                'trace' => $e->getTraceAsString()
            ]);

            $errorMsg = $e->getMessage();
            $keyboard = Keyboard::make()->inline();

            // تشخیص نوع خطا و نمایش پیام مناسب
            if ($errorMsg === 'موجودی کافی نیست') {
                $keyboard->row([
                    Keyboard::inlineButton(['text' => '💳 شارژ کیف پول', 'callback_data' => '/deposit']),
                    Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/plans'])
                ]);
                $this->sendOrEditMessage(
                    $user->telegram_chat_id,
                    "❌ موجودی کیف پول شما کافی نیست.\n\n💡 لطفاً ابتدا کیف پول خود را شارژ کنید.",
                    $keyboard,
                    $messageId
                );
            } elseif ($errorMsg === 'سفارش نامعتبر است یا منقضی شده.') {
                $keyboard->row([Keyboard::inlineButton(['text' => '🛒 مشاهده پلن‌ها', 'callback_data' => '/plans'])]);
                $this->sendOrEditMessage(
                    $user->telegram_chat_id,
                    "❌ " . $errorMsg,
                    $keyboard,
                    $messageId
                );
            } else {
                // خطای عمومی یا خطای پر کردن اکانت
                $keyboard->row([Keyboard::inlineButton(['text' => '💬 تماس با پشتیبانی', 'callback_data' => '/support_menu'])]);
                $this->sendOrEditMessage(
                    $user->telegram_chat_id,
                    "⚠️ خطایی در پردازش خرید رخ داد: " . $this->escape($errorMsg) . "\n\nلطفاً با پشتیبانی تماس بگیرید.",
                    $keyboard,
                    $messageId
                );
            }
        }
    }

    protected function sendCardPaymentInfo($chatId, $orderId, $messageId)
    {
        $order = Order::find($orderId);
        if (!$order) {
            return;
        }

        $locationId = null;
        $user = $order->user;
        if ($user && $user->bot_state && Str::contains($user->bot_state, 'selected_loc:')) {
            preg_match('/selected_loc:(\d+)/', $user->bot_state, $matches);
            if (!empty($matches[1])) {
                $locationId = (int) $matches[1];
            }
        }

        $updateData = [
            'payment_method' => 'card',
        ];

        if (!$order->server_id) {
            $serverId = $this->resolveBestServerId($order->plan, $locationId);
            if ($serverId) {
                $updateData['server_id'] = $serverId;
                Log::info("Fixed missing server_id for order #{$order->id} with server #{$serverId}");
            }
        }

        $order->update($updateData);

        $user = $order->user;
        if ($user) {
            $user->update(['bot_state' => 'waiting_receipt_' . $orderId]);
        }

        // Pick a random payment card
        $card = \App\Models\Setting::getRandomPaymentCard();
        $cardNumber = $card['card_number'];
        $cardHolder = $card['card_holder'];
        $amountToPay = number_format($order->amount);

        $message = "💳 *پرداخت کارت به کارت*\n\n";
        $message .= "لطفاً مبلغ *" . $this->escape($amountToPay) . " تومان* را به حساب زیر واریز نمایید:\n\n";
        $message .= "👤 *به نام:* " . $this->escape($cardHolder) . "\n";
        $message .= "💳 *شماره کارت:*\n`" . $this->escape($cardNumber) . "`\n\n";
        $message .= "🔔 *مهم:* پس از واریز، *فقط عکس رسید* را در همین چت ارسال کنید\\.";

        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف از پرداخت', 'callback_data' => '/cancel_action'])]);

        $this->sendRawMarkdownMessage($chatId, $message, $keyboard, $messageId);
    }

    // ========================================================================
    // سایر متدها (پلان‌ها، تمدید، تیکت، آموزش و ...)
    // ========================================================================

    protected function sendPlans($chatId, $messageId = null)
    {
        try {
            $activePlans = Plan::where('is_active', true)
                ->orderBy('duration_days', 'asc')
                ->get();

            if ($activePlans->isEmpty()) {
                $keyboard = Keyboard::make()->inline()
                    ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/start'])]);
                $this->sendOrEditMessage($chatId, "⚠️ هیچ پلن فعالی در دسترس نیست.", $keyboard, $messageId);
                return;
            }

            $durations = $activePlans->pluck('duration_days')->unique()->sort();

            $message = "🚀 *انتخاب سرویس VPN*\n\n";
            $message .= "لطفاً مدت‌زمان سرویس مورد نظر را انتخاب کنید:\n\n";
            $message .= "👇 یکی از گزینه‌های زیر را بزنید:";

            $keyboard = Keyboard::make()->inline();

            foreach ($durations as $durationDays) {
                $buttonText = $this->generateDurationLabel($durationDays);
                $keyboard->row([
                    Keyboard::inlineButton([
                        'text' => $buttonText,
                        'callback_data' => "show_duration_{$durationDays}"
                    ])
                ]);
            }

            $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

            $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);

        } catch (\Exception $e) {
            Log::error('Error in sendPlans: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'trace' => $e->getTraceAsString()
            ]);

            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

            $this->sendOrEditMessage($chatId, $this->escape("❌ خطایی در بارگذاری پلن‌ها رخ داد."), $keyboard, $messageId);
        }
    }

    protected function generateDurationLabel(int $days): string
    {
        if ($days % 30 === 0) {
            $months = $days / 30;
            return match ($months) {
                1 => '🔸 یک ماهه',
                2 => '🔸 دو ماهه',
                3 => '🔸 سه ماهه',
                6 => '🔸 شش ماهه',
                12 => '🔸 یک ساله',
                default => "{$months} ماهه",
            };
        }
        return "{$days} روزه";
    }

    protected function sendPlansByDuration($chatId, $durationDays, $messageId = null)
    {
        try {
            $plans = Plan::where('is_active', true)
                ->where('duration_days', $durationDays)
                ->orderBy('volume_gb', 'asc')
                ->get();

            if ($plans->isEmpty()) {
                $keyboard = Keyboard::make()->inline()
                    ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/plans'])]);
                $this->sendOrEditMessage($chatId, "⚠️ پلنی با این مدت‌زمان یافت نشد.", $keyboard, $messageId);
                return;
            }

            $durationLabel = $plans->first()->duration_label;
            $message = "📅 *پلن‌های {$durationLabel}*\n\n";

            foreach ($plans as $index => $plan) {
                if ($index > 0) {
                    $message .= "〰️〰️〰️\n\n";
                }
                // Escape the dot after the index number for MarkdownV2
                $message .= ($index + 1) . "\. 💎 *" . $this->escape($plan->name) . "*\n";
                $message .= "   📦 " . $this->escape($plan->volume_gb . ' گیگ') . "\n";
                $message .= "   💳 " . $this->escape(number_format($plan->price) . ' تومان') . "\n";
            }

            $message .= "\n👇 پلن مورد نظر را انتخاب کنید:";

            $keyboard = Keyboard::make()->inline();

            foreach ($plans as $plan) {
                // ✅ اصلاح: حذف escape از نام دکمه چون دکمه‌ها plain text هستند
                $buttonText = $plan->name . ' | ' . number_format($plan->price) . ' تومان';
                $keyboard->row([
                    Keyboard::inlineButton([
                        'text' => $buttonText,
                        'callback_data' => "buy_plan_{$plan->id}"
                    ])
                ]);
            }

            $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به انتخاب زمان', 'callback_data' => '/plans'])]);

            $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);

        } catch (\Exception $e) {
            Log::error('Error in sendPlansByDuration: ' . $e->getMessage(), [
                'duration_days' => $durationDays,
                'chat_id' => $chatId,
                'trace' => $e->getTraceAsString()
            ]);

            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

            $this->sendOrEditMessage($chatId, $this->escape("❌ خطایی در بارگذاری پلن‌ها رخ داد."), $keyboard, $messageId);
        }
    }


    protected function sendQRCodeForOrder($user, $orderId)
    {
        $order = $user->orders()->find($orderId);

        if (!$order) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ سرویس یافت نشد."),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        if (empty($order->config_details) || !is_string($order->config_details)) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ لینک کانفیگ هنوز آماده نشده است."),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        $configLink = trim($order->config_details);

        // ✅ اعتبارسنجی فرمت لینک
        if (empty($configLink)) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ لینک کانفیگ خالی است."),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        $tempFile = null;

        try {

            $qrParams = [
                'size' => '400x400',
                'data' => $configLink,
                'ecc' => 'M',
                'margin' => 10,
                'color' => '000000',
                'bgcolor' => 'FFFFFF',
                'format' => 'png'
            ];

            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?" . http_build_query($qrParams);


            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $qrUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TelegramBot/1.0)'
            ]);

            $qrData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($qrData === false || $httpCode !== 200 || empty($qrData)) {
                throw new \Exception("HTTP {$httpCode} - {$curlError}");
            }


            $tempDir = storage_path('app/temp');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempFile = $tempDir . '/qr_' . $order->id . '_' . time() . '.png';

            if (file_put_contents($tempFile, $qrData) === false) {
                throw new \Exception("عدم توانایی در ذخیره فایل موقت");
            }

            // ✅ ساخت کیبورد
            $keyboard = Keyboard::make()->inline()
                ->row([
                    Keyboard::inlineButton(['text' => '🔄 تمدید سرویس', 'callback_data' => "renew_order_{$order->id}"]),
                    Keyboard::inlineButton(['text' => '⬅️ بازگشت به جزئیات', 'callback_data' => "show_service_{$order->id}"])
                ])
                ->row([
                    Keyboard::inlineButton(['text' => '⬅️ بازگشت به لیست سرویس‌ها', 'callback_data' => '/my_services'])
                ]);

            // ✅ ارسال عکس با InputFile
            Telegram::sendPhoto([
                'chat_id' => $user->telegram_chat_id,
                'photo' => InputFile::create($tempFile, "qr_code_{$order->id}.png"),
                'caption' => $this->escape("📱 QR Code برای سرویس #{$order->id}\n\n" .
                    "👤 نام کاربری: `{$order->panel_username}`\n" .
                    "🔗 لینک: {$configLink}\n\n" .
                    "⚠️ برای کپی روی لینک بالا کلیک کنید."),
                'parse_mode' => 'MarkdownV2',
                'reply_markup' => $keyboard
            ]);

        } catch (\Exception $e) {
            Log::error('QR Code Generation Failed', [
                'order_id' => $orderId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'config_length' => strlen($configLink ?? ''),
                'trace' => $e->getTraceAsString()
            ]);


            $keyboard = Keyboard::make()->inline()
                ->row([
                    Keyboard::inlineButton(['text' => '🔄 تمدید سرویس', 'callback_data' => "renew_order_{$order->id}"]),
                    Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => "show_service_{$order->id}"])
                ]);

            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ خطا در تولید QR Code.\n\n🔧 لطفاً از لینک زیر استفاده کنید:\n`{$configLink}`"),
                'parse_mode' => 'MarkdownV2',
                'reply_markup' => $keyboard
            ]);

        } finally {

            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
    /**
     * Query the orders shown in the “My Services” list.
     *
     * Regular services always have a plan. Imported subscriptions, however, may
     * have plan_id = null when no active plan matched during import (e.g. no
     * plans configured on the panel yet), so they are included explicitly –
     * exactly like the web dashboard does. Imported subscriptions are also not
     * hidden by the 30-day expiry window so a just-imported account is always
     * listed.
     */
    protected function getMyServicesOrders(User $user)
    {
        return $user->orders()->with('plan')
            ->where('status', 'paid')
            ->whereNull('renews_order_id')
            ->where(function ($query) {
                $query->whereNotNull('plan_id')->orWhere('is_imported', true);
            })
            ->where(function ($query) {
                $query->where('expires_at', '>', now()->subDays(30))
                    ->orWhere('is_imported', true);
            })
            ->orderBy('expires_at', 'desc')
            ->get();
    }

    protected function sendMyServices($user, $messageId = null)
    {
        $orders = $this->getMyServicesOrders($user);

        if ($orders->isEmpty()) {
            $keyboard = Keyboard::make()->inline()->row([
                Keyboard::inlineButton(['text' => '🛒 خرید سرویس جدید', 'callback_data' => '/plans']),
                Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start']),
            ]);
            $this->sendOrEditMessage($user->telegram_chat_id, "⚠️ شما هیچ سرویس فعال یا اخیراً منقضی شده‌ای ندارید.", $keyboard, $messageId);
            return;
        }

        $message = "🛠 *سرویس‌های شما*\n\nلطفاً یک سرویس را برای مشاهده جزئیات انتخاب کنید:";

        $keyboard = Keyboard::make()->inline();

        foreach ($orders as $order) {
            // Imported subscriptions are shown even without a matching plan.
            if (!$order->plan && !$order->is_imported) {
                continue;
            }

            $expiresAt = $order->expires_at ? Carbon::parse($order->expires_at) : null;
            $now = now();
            $statusIcon = '🟢';

            if ($expiresAt && $expiresAt->isPast()) {
                $statusIcon = '⚫️';
            } elseif ($expiresAt && $expiresAt->diffInDays($now) <= 7) {
                $statusIcon = '🟡';
            }

            $username = $order->panel_username ?: "سرویس-{$order->id}";
            $buttonText = "{$statusIcon} {$username} (ID: #{$order->id})";

            $keyboard->row([
                Keyboard::inlineButton([
                    'text' => $buttonText,
                    'callback_data' => "show_service_{$order->id}"
                ])
            ]);
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function showServiceDetails($user, $orderId, $messageId = null)
    {
        $order = $user->orders()->with('plan')->find($orderId);

        // Imported subscriptions are valid services even when no plan matched
        // during import (plan_id may be null).
        if (!$order || $order->status !== 'paid' || (!$order->plan && !$order->is_imported)) {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سرویس مورد نظر یافت نشد یا معتبر نیست.", $messageId);
            return;
        }

        // If this order has completed renewal orders with a newer plan, ensure we display the latest active plan
        $latestRenewal = Order::where('renews_order_id', $order->id)
            ->where('status', 'paid')
            ->whereNotNull('plan_id')
            ->latest('id')
            ->first();

        if ($latestRenewal && $latestRenewal->plan_id && $order->plan_id !== $latestRenewal->plan_id) {
            $order->update(['plan_id' => $latestRenewal->plan_id]);
            $order->load('plan');
        }

        $activePlan = ($latestRenewal && $latestRenewal->plan) ? $latestRenewal->plan : $order->plan;

        $panelUsername = $order->panel_username;
        if (empty($panelUsername)) {
            $panelUsername = $order->panel_username ?? \App\Services\ClientNamingService::generate($user->id, $order->id);
        }

        $expiresAt = $order->expires_at ? Carbon::parse($order->expires_at) : null;
        $now = now();
        $statusIcon = '🟢';
        $remainingText = "*نامحدود*";
        if ($expiresAt) {
            $daysRemaining = $now->diffInDays($expiresAt, false);
            $daysRemaining = (int) $daysRemaining;
            if ($expiresAt->isPast()) {
                $statusIcon = '⚫️';
                $remainingText = "*منقضی شده*";
            } elseif ($daysRemaining <= 7) {
                $statusIcon = '🟡';
                $remainingText = "*" . $this->escape($daysRemaining . ' روز') . "* باقی‌مانده \(تمدید کنید\)";
            } else {
                $remainingText = "*" . $this->escape($daysRemaining . ' روز') . "* باقی‌مانده";
            }
        }

        // Try to refresh live panel data for imported subscriptions so traffic/expire are always accurate
        $liveRefreshed = false;
        if ($order->is_imported && $order->panel_client_id) {
            $liveRefreshed = SubscriptionImportService::syncOrderWithPanel($order);
        }

        // Recompute expiry + days remaining after possible live refresh
        $expiresAt = $order->expires_at ? Carbon::parse($order->expires_at) : null;
        if ($expiresAt) {
            $daysRemaining = now()->diffInDays($expiresAt, false);
            $daysRemaining = (int) $daysRemaining;
            if ($expiresAt->isPast()) {
                $statusIcon = '⚫️';
                $remainingText = "*منقضی شده*";
            } elseif ($daysRemaining <= 7) {
                $statusIcon = '🟡';
                $remainingText = "*" . $this->escape($daysRemaining . ' روز') . "* باقی‌مانده \(تمدید کنید\)";
            } else {
                $remainingText = "*" . $this->escape($daysRemaining . ' روز') . "* باقی‌مانده";
            }
        } else {
            $remainingText = "*نامحدود*";
            $statusIcon = '🟢';
        }

        // Build traffic display – for imported show remaining/used/total from live panel data
        if ($activePlan && !$order->is_imported) {
            $planName = $activePlan->name;
            $volumeText = $activePlan->volume_gb . ' گیگابایت';
        } elseif ($order->is_imported) {
            $planName = $activePlan ? $activePlan->name . ' (واردشده 📥)' : 'اشتراک واردشده 📥';
            $importMeta = $order->import_meta ?? [];
            $totalBytes = $importMeta['totalGB'] ?? $importMeta['data_limit'] ?? 0;
            $usedBytes = $importMeta['used_traffic'] ?? 0;
            $remainingBytes = $importMeta['remaining_traffic'] ?? null;
            if ($totalBytes > 0) {
                $totalGB = round($totalBytes / 1073741824, 1);
                if ($remainingBytes !== null) {
                    $remainingGB = round($remainingBytes / 1073741824, 2);
                    $usedGB = round($usedBytes / 1073741824, 2);
                    $volumeText = "{$remainingGB} GB باقی‌مانده از {$totalGB} GB (مصرف: {$usedGB} GB)";
                } else {
                    $volumeText = "{$totalGB} گیگابایت";
                }
            } else {
                $usedGB = round($usedBytes / 1073741824, 2);
                $volumeText = $usedGB > 0 ? "نامحدود (مصرف: {$usedGB} GB)" : "نامحدود ♾️";
            }
        } else {
            $planName = $activePlan ? $activePlan->name : ($order->plan ? $order->plan->name : 'سرویس');
            $volumeText = $activePlan ? ($activePlan->volume_gb . ' گیگابایت') : ($order->plan ? ($order->plan->volume_gb . ' گیگابایت') : 'نامشخص');
        }

        $message = "🔍 جزئیات سرویس \#{$order->id}\n\n";
        $message .= "{$statusIcon} سرویس: " . $this->escape($planName) . "\n";
        $message .= "👤 نام کاربری: `" . $panelUsername . "`\n";
        $expiresText = $expiresAt ? $this->escape(to_jalali_date($expiresAt, 'Y/m/d')) : $this->escape("نامحدود");
        $message .= "🗓 انقضا: " . $expiresText . " \- " . $remainingText . "\n";
        $message .= "📦  حجم:  " . $this->escape($volumeText) . "\n";
        if (!empty($order->config_details)) {
            $message .= "\n🔗 *لینک اتصال \(جهت کپی\):*\n`" . $order->config_details . "`";
        } else {
            $message .= "\n⏳ *در حال آماده‌سازی کانفیگ\.\.\.*";
        }

        $keyboard = Keyboard::make()->inline();

        if (!empty($order->config_details)) {
            $keyboard->row([
                Keyboard::inlineButton(['text' => "📱 دریافت QR Code", 'callback_data' => "qrcode_order_{$order->id}"])
            ]);
        }

        // Renewal requires a plan (pricing/duration), so it is only offered when
        // the imported subscription actually matched an active plan.
        if ($activePlan || $order->plan) {
            $keyboard->row([
                Keyboard::inlineButton(['text' => "🔄 تمدید سرویس", 'callback_data' => "renew_order_{$order->id}"])
            ]);
        }

        $keyboard->row([
            Keyboard::inlineButton(['text' => '⬅️ بازگشت به لیست سرویس‌ها', 'callback_data' => '/my_services'])
        ]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function sendWalletMenu($user, $messageId = null)
    {
        $balance = number_format($user->balance ?? 0);
        $message = "💰 *کیف پول شما*\n\n";
        $message .= "موجودی فعلی: *{$balance} تومان*\n\n";
        $message .= "می‌توانید حساب خود را شارژ کنید یا تاریخچه تراکنش‌ها را مشاهده نمایید:";

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '💳 شارژ حساب', 'callback_data' => '/deposit']),
                Keyboard::inlineButton(['text' => '📜 تاریخچه تراکنش‌ها', 'callback_data' => '/transactions']),
            ])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    /**
     * ✅ حذف: این متد دقیقاً در انتهای فایل تکراری بود و حذف شده است.
     * نسخه اصلی در انتهای فایل نگه داشته شد.
     */
    /*
    protected function sendReferralMenu($user, $messageId = null)
    {
        try {
            $botInfo = Telegram::getMe();
            $botUsername = $botInfo->getUsername();
        } catch (\Exception $e) {
            Log::error("Could not get bot username: " . $e->getMessage());
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ خطایی در دریافت اطلاعات ربات رخ داد.", $messageId);
            return;
        }

        $referralCode = $user->referral_code ?? Str::random(8);
        if (!$user->referral_code) {
            $user->update(['referral_code' => $referralCode]);
        }

        // ✅ اصلاح: حذف space های اضافی
        $referralLink = "https://t.me/{$botUsername}?start={$referralCode}";
        $referrerReward = number_format((int) $this->settings->get('referral_referrer_reward', 0));
        $referralCount = $user->referrals()->count();

        $message = "🎁 *دعوت از دوستان*\n\n";
        $message .= "با اشتراک‌گذاری لینک زیر، دوستان خود را به ربات دعوت کنید.\n\n";
        $message .= "💸 با هر خرید موفق دوستانتان، *{$referrerReward} تومان* به کیف پول شما اضافه می‌شود.\n\n";
        $message .= "🔗 *لینک دعوت شما:*\n`{$referralLink}`\n\n";
        $message .= "👥 تعداد دعوت‌های موفق شما: *{$referralCount} نفر*";

        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }
    */

    protected function sendTransactions($user, $messageId = null)
    {
        $transactions = $user->transactions()->with('order.plan')->latest()->take(10)->get();

        $message = "📜 *۱۰ تراکنش اخیر شما*\n\n";

        if ($transactions->isEmpty()) {
            $message .= $this->escape("شما تاکنون هیچ تراکنشی ثبت نکرده‌اید.");
        } else {
            foreach ($transactions as $transaction) {
                $type = 'نامشخص';
                switch ($transaction->type) {
                    case 'deposit': $type = '💰 شارژ کیف پول'; break;
                    case 'purchase':
                        if ($transaction->order?->renews_order_id) {
                            $type = '🔄 تمدید سرویس';
                        } else {
                            $type = '🛒 خرید سرویس';
                        }
                        break;
                    case 'referral_reward': $type = '🎁 پاداش دعوت'; break;
                    case 'withdraw': $type = '📤 برداشت وجه'; break;
                    case 'refund': $type = '↩️ بازگشت وجه'; break;
                    case 'manual adjustment': $type = '✏️ اصلاح دستی'; break;
                }

                $status = '⚪️';
                switch ($transaction->status) {
                    case 'completed': $status = '✅'; break;
                    case 'pending': $status = '⏳'; break;
                    case 'failed': $status = '❌'; break;
                }

                $amount = number_format(abs($transaction->amount));
                $date = to_jalali_date($transaction->created_at, 'Y/m/d');

                $message .= "{$status} *" . $this->escape($type) . "*\n";
                $message .= "   💸 *مبلغ:* " . $this->escape($amount . " تومان") . "\n";
                $message .= "   📅 *تاریخ:* " . $this->escape($date) . "\n";
                if ($transaction->order?->plan) {
                    $message .= "   🏷 *پلن:* " . $this->escape($transaction->order->plan->name) . "\n";
                }
                $message .= "〰️〰️〰️〰️〰️〰️\n";
            }
        }

        $keyboard = Keyboard::make()->inline()->row([
            Keyboard::inlineButton(['text' => '⬅️ بازگشت به کیف پول', 'callback_data' => '/wallet'])
        ]);

        $this->sendRawMarkdownMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    /**
     * ❓ نمایش لیست سوالات متداول (FAQ)
     */
    protected function sendFaqList($chatId, $messageId = null)
    {
        try {
            $faqs = \Modules\TelegramBot\Models\Faq::where('is_active', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($faqs->isEmpty()) {
                $keyboard = Keyboard::make()->inline()
                    ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);
                $this->sendOrEditMessage(
                    $chatId,
                    $this->escape("در حال حاضر هیچ سوال متداولی ثبت نشده است. در صورت نیاز می‌توانید با پشتیبانی در ارتباط باشید."),
                    $keyboard,
                    $messageId
                );
                return;
            }

            $message = "❓ *سوالات متداول*\n\n" . $this->escape("لطفاً سوال مورد نظر خود را انتخاب کنید:");

            $keyboard = Keyboard::make()->inline();

            foreach ($faqs as $faq) {
                $keyboard->row([
                    Keyboard::inlineButton([
                        'text' => mb_substr($faq->question, 0, 60),
                        'callback_data' => 'faq_view_' . $faq->id,
                    ]),
                ]);
            }

            $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

            $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
        } catch (\Exception $e) {
            Log::error('Error in sendFaqList: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'trace' => $e->getTraceAsString(),
            ]);

            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

            $this->sendOrEditMessage($chatId, $this->escape("❌ خطایی در بارگذاری سوالات متداول رخ داد."), $keyboard, $messageId);
        }
    }

    /**
     * ❓ نمایش پاسخ یک سوال متداول (FAQ)
     */
    protected function sendFaqAnswer($chatId, int $faqId, $messageId = null)
    {
        $faq = \Modules\TelegramBot\Models\Faq::where('id', $faqId)
            ->where('is_active', true)
            ->first();

        if (!$faq) {
            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '❓ بازگشت به سوالات متداول', 'callback_data' => '/faq'])]);
            $this->sendOrEditMessage($chatId, $this->escape("❌ این سوال دیگر در دسترس نیست."), $keyboard, $messageId);
            return;
        }

        $message = "❓ *" . $this->escape($faq->question) . "*\n\n" . $this->escape($faq->answer);

        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به لیست سوالات', 'callback_data' => '/faq'])])
            ->row([Keyboard::inlineButton(['text' => '🏠 منوی اصلی', 'callback_data' => '/start'])]);

        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    protected function sendTutorialsMenu($chatId, $messageId = null)
    {
        $message = "📚 *راهنمای اتصال*\n\nلطفاً سیستم‌عامل خود را برای دریافت راهنما و لینک دانلود انتخاب کنید:";
        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '📱 اندروید (V2rayNG)', 'callback_data' => '/tutorial_android']),
                Keyboard::inlineButton(['text' => '🍏 آیفون (V2Box)', 'callback_data' => '/tutorial_ios']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '💻 ویندوز (V2rayN)', 'callback_data' => '/tutorial_windows']),
                Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start']),
            ]);
        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    protected function sendTutorial($platform, $chatId, $messageId = null)
    {
        $telegramSettings = TelegramBotSetting::pluck('value', 'key');

        $settingKey = match($platform) {
            'android' => 'tutorial_android',
            'ios' => 'tutorial_ios',
            'windows' => 'tutorial_windows',
            default => null
        };

        $message = $settingKey ? ($telegramSettings->get($settingKey) ?? "آموزشی برای این پلتفرم یافت نشد.")
            : "پلتفرم نامعتبر است.";

        if ($message === "آموزشی برای این پلتفرم یافت نشد.") {
            $fallbackTutorials = [
                'android' => "*راهنمای اندروید \\(V2rayNG\\)*\n\n1\\. برنامه V2rayNG را از [این لینک](https://github.com/2dust/v2rayNG/releases) دانلود و نصب کنید\\.\n2\\. لینک کانفیگ را از بخش *سرویس‌های من* کپی کنید\\.\n3\\. در برنامه، روی علامت `+` بزنید و `Import config from Clipboard` را انتخاب کنید\\.\n4\\. کانفیگ اضافه شده را انتخاب و دکمه اتصال \\(V شکل\\) پایین صفحه را بزنید\\.",
                'ios' => "*راهنمای آیفون \\(V2Box\\)*\n\n1\\. برنامه V2Box را از [اپ استور](https://apps.apple.com/us/app/v2box-v2ray-client/id6446814690) نصب کنید\\.\n2\\. لینک کانفیگ را از بخش *سرویس‌های من* کپی کنید\\.\n3\\. در برنامه، وارد بخش `Configs` شوید، روی `+` بزنید و `Import from clipboard` را انتخاب کنید\\.\n4\\. برای اتصال، به بخش `Home` بروید و دکمه اتصال را بزنید \\(ممکن است نیاز به تایید VPN در تنظیمات گوشی باشد\\)\\.",
                'windows' => "*راهنمای ویندوز \\(V2rayN\\)*\n\n1\\. برنامه v2rayN را از [این لینک](https://github.com/2dust/v2rayN/releases) دانلود \\(فایل `v2rayN-With-Core.zip`\\) و از حالت فشرده خارج کنید\\.\n2\\. فایل `v2rayN.exe` را اجرا کنید\\.\n3\\. لینک کانفیگ را از بخش *سرویس‌های من* کپی کنید\\.\n4\\. در برنامه V2RayN، کلیدهای `Ctrl+V` را فشار دهید تا سرور اضافه شود\\.\n5\\. روی آیکون برنامه در تسک‌بار \\(کنار ساعت\\) راست کلیک کرده، از منوی `System Proxy` گزینه `Set system proxy` را انتخاب کنید تا تیک بخورد\\.\n6\\. برای اتصال، دوباره روی آیکون راست کلیک کرده و از منوی `Servers` کانفیگ اضافه شده را انتخاب کنید\\.",
            ];
            $message = $fallbackTutorials[$platform] ?? "آموزشی برای این پلتفرم یافت نشد.";
        } else {
            // محتوای ادمین: ابتدا به‌صورت MarkdownV2 ارسال می‌شود.
            // اگر کاراکترهای رزرو‌شده escape نشده باشند، تلگرام خطای
            // "can't parse entities" می‌دهد. پس ابتدا escape می‌کنیم.
            $message = $this->escape($message);
        }

        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به آموزش‌ها', 'callback_data' => '/tutorials'])]);

        // استفاده از sendOrEditMessage که فال‌بک متن ساده دارد:
        // ۱) تلاش با MarkdownV2
        // ۲) در صورت شکست، ارسال بدون parse_mode (متن ساده)
        // این الگو در کل ربات استفاده شده و تضمین می‌کند کاربر
        // همیشه پیام را می‌بیند.
        $this->sendOrEditMessage((int) $chatId, $message, $keyboard, $messageId);
    }

    /**
     * ⚠️ نکته: اطمینان حاصل کنید که XUIService و MarzbanService وجود دارند و متدهای لازم را دارند
     */
    protected function provisionUserAccount(Order $order, Plan $plan)
    {
        $settings = $this->settings;
        $uniqueUsername = $order->panel_username ?? \App\Services\ClientNamingService::generate($order->user_id, $order->id);
        $expiresAt = $order->expires_at ? Carbon::parse($order->expires_at) : null;
        $configData = [
            'link' => null,
            'username' => null,
            'panel_client_id' => null,
            'panel_sub_id' => null
        ];

        $isMultiLocationEnabled = filter_var(
            $settings->get('enable_multilocation', false),
            FILTER_VALIDATE_BOOLEAN
        );

        $isMultiServer = false;
        $panelType = $settings->get('panel_type');
        if (empty($panelType)) {
            $hasXui = !empty($settings->get('xui_host')) && !empty($settings->get('xui_user')) && !empty($settings->get('xui_pass'));
            $hasMarzban = !empty($settings->get('marzban_host'));
            $panelType = $hasXui ? 'xui' : ($hasMarzban ? 'marzban' : 'xui');
        }
        $targetServer = null; // ✅ تعریف اولیه

        // مقادیر پیش‌فرض
        $xuiHost = $settings->get('xui_host');
        $xuiUser = $settings->get('xui_user');
        $xuiPass = $settings->get('xui_pass');
        $inboundId = (int) $settings->get('xui_default_inbound_id');

        // بررسی مولتی سرور
        if (class_exists('Modules\MultiServer\Models\Server')) {
            // اگر روی سفارش سرور مشخص نیست، یک سرور فعال انتخاب کن
            if (!$order->server_id) {
                $targetServerId = $this->resolveBestServerId($plan);
                if ($targetServerId) {
                    $order->server_id = $targetServerId;
                    try { $order->save(); } catch (\Exception $e) {}
                }
            }
            $targetServer = $order->server_id ? \Modules\MultiServer\Models\Server::find($order->server_id) : null;
            if ($targetServer && $targetServer->is_active) {
                $isMultiServer = true;
                $panelType = $targetServer->type ?? 'xui';
                
                // X-UI credentials
                $xuiHost = $targetServer->full_host;
                $xuiUser = $targetServer->username;
                $xuiPass = $targetServer->password;
                $inboundId = $targetServer->inbound_id;

                // Marzban credentials
                $marzbanHost = $targetServer->full_host;
                $marzbanUser = $targetServer->username;
                $marzbanPass = $targetServer->password;
                // Use node hostname if set, otherwise fallback to panel host
                $marzbanNode = $targetServer->marzban_node_hostname ?? $marzbanHost;

                // PasarGuard credentials
                $pasarguardHost = $targetServer->full_host;
                $pasarguardUser = $targetServer->username;
                $pasarguardPass = $targetServer->password;
                $pasarguardNode = $targetServer->pasarguard_node_hostname ?? $pasarguardHost;

                Log::info("🚀 Provisioning on MultiServer", [
                    'server_name' => $targetServer->name,
                    'server_id' => $targetServer->id,
                    'type' => $panelType,
                    'host' => parse_url($xuiHost, PHP_URL_HOST),
                    'link_type' => $targetServer->link_type ?? 'not set'
                ]);
            }
        }

        try {
            // ==========================================
            // پنل PASARGUARD
            // ==========================================
            if ($panelType === 'pasarguard') {
                if ($isMultiServer) {
                    $pasarguard = new PasarGuardService(
                        $pasarguardHost ?? '',
                        $pasarguardUser ?? '',
                        $pasarguardPass ?? '',
                        $pasarguardNode ?? ''
                    );
                } else {
                    $pasarguard = new PasarGuardService(
                        $settings->get('pasarguard_host') ?? '',
                        $settings->get('pasarguard_username') ?? '',
                        $settings->get('pasarguard_password') ?? '',
                        $settings->get('pasarguard_node_hostname') ?? ''
                    );
                }
                
                $response = $pasarguard->createUser([
                    'username' => $uniqueUsername,
                    'expire' => $expiresAt ? $expiresAt->getTimestamp() : null,
                    'data_limit' => $plan->volume_gb * 1024 * 1024 * 1024,
                ]);

                if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                    $configData['link'] = $pasarguard->generateSubscriptionLink($response);
                    $configData['username'] = $uniqueUsername;
                } else {
                    Log::error('PasarGuard user creation failed.', ['response' => $response]);
                    return null;
                }
            }
            // ==========================================
            // پنل MARZBAN
            // ==========================================
            elseif ($panelType === 'marzban') {
                if ($isMultiServer) {
                    $marzban = new MarzbanService(
                        $marzbanHost ?? '',
                        $marzbanUser ?? '',
                        $marzbanPass ?? '',
                        $marzbanNode ?? ''
                    );
                } else {
                    $marzban = new MarzbanService(
                        $settings->get('marzban_host') ?? '',
                        $settings->get('marzban_sudo_username') ?? '',
                        $settings->get('marzban_sudo_password') ?? '',
                        $settings->get('marzban_node_hostname') ?? ''
                    );
                }
                
                $response = $marzban->createUser([
                    'username' => $uniqueUsername,
                    'expire' => $expiresAt ? $expiresAt->getTimestamp() : null,
                    'data_limit' => $plan->volume_gb * 1024 * 1024 * 1024,
                ]);

                if (!empty($response['subscription_url'])) {
                    $configData['link'] = $marzban->generateSubscriptionLink($response);
                    $configData['username'] = $uniqueUsername;
                } else {
                    Log::error('Marzban user creation failed.', ['response' => $response]);
                    return null;
                }
            }
            // ==========================================
            // پنل X-UI
            // ==========================================
            elseif ($panelType === 'xui') {
                if ($inboundId <= 0) {
                    throw new \Exception("Inbound ID نامعتبر است: {$inboundId}");
                }

                $xui = new XUIService($xuiHost, $xuiUser, $xuiPass);

                if (!$xui->login()) {
                    throw new \Exception("❌ خطا در لاگین به پنل X-UI");
                }

                // دریافت اینباند
                $inboundData = null;
                if ($isMultiServer) {
                    $allInbounds = $xui->getInbounds();
                    foreach ($allInbounds as $remoteInbound) {
                        if ($remoteInbound['id'] == $inboundId) {
                            $inboundData = $remoteInbound;
                            break;
                        }
                    }
                    if (!$inboundData) throw new \Exception("اینباند در سرور یافت نشد.");
                } else {
                    $inboundModel = Inbound::whereJsonContains('inbound_data->id', (int)$inboundId)->first();
                    if ($inboundModel) {
                        $inboundData = is_string($inboundModel->inbound_data) ? json_decode($inboundModel->inbound_data, true) : $inboundModel->inbound_data;
                    } else {
                        throw new \Exception("اینباند پیش‌فرض یافت نشد.");
                    }
                }

                // تعیین نوع لینک
                $linkType = ($isMultiServer && $targetServer) ? ($targetServer->link_type ?? 'single') : $settings->get('xui_link_type', 'single');

                $clientData = [
                    'email' => $uniqueUsername,
                    'total' => $plan->volume_gb * 1024 * 1024 * 1024,
                    'expiryTime' => $expiresAt ? $expiresAt->getTimestamp() * 1000 : null,
                ];

                if ($linkType === 'subscription') {
                    $clientData['subId'] = Str::random(16);
                }

                Log::info("Creating XUI client", ['email' => $uniqueUsername, 'link_type' => $linkType]);

                // ساخت کاربر
                $response = $xui->addClient($inboundId, $clientData);


                if ($response && isset($response['success']) && $response['success']) {
                    // استخراج اطلاعات
                    $uuid = $response['generated_uuid'] ?? null;
                    if (!$uuid && isset($response['obj']['settings'])) {
                        $cSettings = json_decode($response['obj']['settings'], true);
                        $uuid = $cSettings['clients'][0]['id'] ?? null;
                    }
                    $subId = $response['generated_subId'] ?? $clientData['subId'] ?? null;

                    $streamSettings = json_decode($inboundData['streamSettings'] ?? '{}', true);
                    $protocol = $inboundData['protocol'] ?? 'vless';
                    $inboundPort = $inboundData['port'] ?? 443;
                    $serverAddress = parse_url($xuiHost, PHP_URL_HOST);

                    switch ($linkType) {
                        case 'subscription':
                            if ($isMultiServer && $targetServer) {
                                $subDomain = $targetServer->subscription_domain ?? $serverAddress;
                                $subPort = $targetServer->subscription_port ?? 2053;
                                $subPath = $targetServer->subscription_path ?? '/sub/';
                                $isHttps = $targetServer->is_https ?? true;

                                $baseUrl = rtrim($subDomain, '/');
                                // اگر پورت هست اضافه کن
                                if ($subPort) $baseUrl .= ":{$subPort}";
                                // پروتکل
                                $protocolScheme = $isHttps ? 'https' : 'http';

                                $configLink = "{$protocolScheme}://{$baseUrl}" . rtrim($subPath, '/') . '/' . $subId;
                            } else {
                                $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                                
                                // Fallback: if subscription URL base is not set, try to use X-UI host
                                if (empty($subBaseUrl) && !empty($xuiHost)) {
                                    $parsed = parse_url($xuiHost);
                                    $scheme = $parsed['scheme'] ?? 'http';
                                    $host = $parsed['host'] ?? '';
                                    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                                    
                                    // Fix: If panel is on 2053, subscription is usually on 2096
                                    if ($port === ':2053') {
                                        $port = ':2096';
                                    }
                                    
                                    $subBaseUrl = "{$scheme}://{$host}{$port}";
                                }
                                
                                $configLink = $subBaseUrl . '/sub/' . $subId;
                            }
                            break;

                        case 'tunnel':
                            if (!$uuid) throw new \Exception("UUID missing for tunnel link");

                            $tunnelAddress = $targetServer->tunnel_address;
                            $tunnelPort = $targetServer->tunnel_port ?? 443;

                            // 🔥 اصلاح مهم: خواندن وضعیت دقیق HTTPS از دیتابیس
                            $tunnelHasTls = filter_var($targetServer->tunnel_is_https, FILTER_VALIDATE_BOOLEAN);

                            $params = [];
                            $params['type'] = $streamSettings['network'] ?? 'tcp';

                            if ($tunnelHasTls) {
                                $params['security'] = 'tls';
                                $params['sni'] = $tunnelAddress;
                            } else {
                                $params['security'] = 'none';
                                // 🔥 اگر TLS خاموش است، حتما این گزینه اضافه شود
                                if ($protocol === 'vless') {
                                    $params['encryption'] = 'none';
                                }
                            }

                            if ($params['type'] === 'ws' && isset($streamSettings['wsSettings'])) {
                                $params['path'] = $streamSettings['wsSettings']['path'] ?? '/';
                                $params['host'] = $streamSettings['wsSettings']['headers']['Host'] ?? $tunnelAddress;
                            }



                            $locFlag = $targetServer->location->flag ?? '🏳️';
                            $remarkText = $locFlag . "-" . $uniqueUsername;

                            $queryString = http_build_query($params);
                            // ساخت لینک نهایی
                            $configLink = "vless://{$uuid}@{$tunnelAddress}:{$tunnelPort}?{$queryString}#" . rawurlencode($remarkText);
                            break;
                        default: // single
                            if (!$uuid) throw new \Exception("UUID missing for single link");

                            $params = [];
                            $params['type'] = $streamSettings['network'] ?? 'tcp';
                            $params['security'] = $streamSettings['security'] ?? 'none';

                            if ($params['type'] === 'ws' && isset($streamSettings['wsSettings'])) {
                                $params['path'] = $streamSettings['wsSettings']['path'] ?? '/';
                                $params['host'] = $streamSettings['wsSettings']['headers']['Host'] ?? $serverAddress;
                            }

                            if ($params['security'] === 'tls' && isset($streamSettings['tlsSettings'])) {
                                $params['sni'] = $streamSettings['tlsSettings']['serverName'] ?? $serverAddress;
                            }

                            $queryString = http_build_query(array_filter($params));
                            $configLink = "vless://{$uuid}@{$serverAddress}:{$inboundPort}?{$queryString}#" . rawurlencode($plan->name);
                            break;
                    }

                    $configData['link'] = $configLink;
                    $configData['username'] = $uniqueUsername;
                    $configData['panel_client_id'] = $uuid;
                    $configData['panel_sub_id'] = $subId;

                } else {
                    throw new \Exception($response['msg'] ?? 'Error creating user in X-UI');
                }
            } else {
                throw new \Exception("Panel type not supported");
            }

            if ($isMultiServer && isset($targetServer)) {
                $targetServer->increment('current_users');
            }

        } catch (\Exception $e) {
            Log::error("Failed to provision account for Order {$order->id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'server_id' => $order->server_id ?? null
            ]);

            if ($isMultiServer && isset($targetServer)) {
                $targetServer->decrement('current_users');
            }
            return null;
        }

        return $configData;
    }

    protected function showDepositOptions($user, $messageId)
    {
        $message = "💳 *شارژ کیف پول*\n\nلطفاً مبلغ مورد نظر برای شارژ را انتخاب کنید یا مبلغ دلخواه خود را وارد نمایید:";
        $keyboard = Keyboard::make()->inline();

        $telegramSettings = TelegramBotSetting::pluck('value', 'key');
        $depositAmountsJson = $telegramSettings->get('deposit_amounts', '[]');
        $depositAmountsData = json_decode($depositAmountsJson, true);

        $depositAmounts = [];
        if (is_array($depositAmountsData)) {
            foreach ($depositAmountsData as $item) {
                if (isset($item['amount']) && is_numeric($item['amount'])) {
                    $depositAmounts[] = (int)$item['amount'];
                }
            }
        }

        if (empty($depositAmounts)) {
            $depositAmounts = [50000, 100000, 200000, 500000];
        }

        sort($depositAmounts);

        foreach (array_chunk($depositAmounts, 2) as $row) {
            $rowButtons = [];
            foreach ($row as $amount) {
                $rowButtons[] = Keyboard::inlineButton([
                    'text' => number_format($amount) . ' تومان',
                    'callback_data' => 'deposit_amount_' . $amount
                ]);
            }
            $keyboard->row($rowButtons);
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '✍️ ورود مبلغ دلخواه', 'callback_data' => '/deposit_custom'])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به کیف پول', 'callback_data' => '/wallet'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function promptForCustomDeposit($user, $messageId)
    {
        $user->update(['bot_state' => 'awaiting_deposit_amount']);
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, $this->escape("💳 لطفاً مبلغ دلخواه خود را (به تومان، حداقل ۱۰,۰۰۰) در یک پیام ارسال کنید:"), $keyboard, $messageId);
    }

    protected function processDepositAmount($user, $amount, $messageId = null)
    {
        $amount = (int) preg_replace('/[^\d]/', '', $amount);
        $minDeposit = (int) $this->settings->get('min_deposit_amount', 10000);

        if ($amount < $minDeposit) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ مبلغ نامعتبر است. لطفاً مبلغی حداقل " . number_format($minDeposit) . " تومان وارد کنید."),
                'parse_mode' => 'MarkdownV2'
            ]);
            $this->promptForCustomDeposit($user, null);
            return;
        }

        $order = $user->orders()->create([
            'plan_id' => null, 'status' => 'pending', 'source' => 'telegram_deposit', 'amount' => $amount, 'payment_method' => 'card'
        ]);
        $user->update(['bot_state' => null]);
        $this->sendCardPaymentInfo($user->telegram_chat_id, $order->id, $messageId);
    }

    protected function sendRawMarkdownMessage($chatId, $text, $keyboard, $messageId = null, $disablePreview = false)
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'MarkdownV2',
            'reply_markup' => $keyboard,
            'disable_web_page_preview' => $disablePreview
        ];

        try {
            if ($messageId) {
                $payload['message_id'] = $messageId;
                Telegram::editMessageText($payload);
            } else {
                Telegram::sendMessage($payload);
            }
        } catch (\Exception $e) {
            if ($messageId && Str::contains($e->getMessage(), 'not found')) {
                unset($payload['message_id']);
                Telegram::sendMessage($payload);
            }
        }
    }

    protected function startRenewalPurchaseProcess($user, $originalOrderId, $messageId)
    {
        $originalOrder = $user->orders()->with('plan')->find($originalOrderId);

        if (!$originalOrder || $originalOrder->status !== 'paid') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سرویس مورد نظر برای تمدید یافت نشد یا معتبر نیست.", $messageId);
            return;
        }

        $plans = Plan::where('is_active', true)->orderBy('price')->get();
        if ($plans->isEmpty()) {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ هیچ پکیج فعالی برای تمدید یافت نشد.", $messageId);
            return;
        }

        $latestRenewal = Order::where('renews_order_id', $originalOrder->id)
            ->where('status', 'paid')
            ->whereNotNull('plan_id')
            ->latest('id')
            ->first();
        $activePlan = ($latestRenewal && $latestRenewal->plan) ? $latestRenewal->plan : $originalOrder->plan;
        $currentPlanName = $activePlan ? $activePlan->name : 'نامشخص';
        $message = "🔄 *انتخاب پکیج تمدید*\n\n";
        $message .= "سرویس فعلی: *{$this->escape($currentPlanName)}*\n";
        if ($originalOrder->expires_at) {
            $message .= "تاریخ انقضا: *{$this->escape(to_jalali_date(Carbon::parse($originalOrder->expires_at), 'Y/m/d'))}*\n";
        }
        $message .= "\nلطفاً پکیج مورد نظر برای تمدید را انتخاب کنید:";

        $keyboard = Keyboard::make()->inline();
        foreach ($plans as $pl) {
            $label = $pl->name . " — " . $pl->volume_gb . "GB / " . $pl->duration_days . "روز — " . number_format($pl->price) . "تومان";
            $keyboard->row([Keyboard::inlineButton(['text' => $label, 'callback_data' => "renew_plan_{$originalOrderId}_{$pl->id}"])]);
        }
        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به سرویس‌ها', 'callback_data' => '/my_services'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function showRenewPlanConfirmation($user, $originalOrderId, $planId, $messageId)
    {
        $originalOrder = $user->orders()->with('plan')->find($originalOrderId);
        $plan = Plan::where('id', $planId)->where('is_active', true)->first();

        if (!$originalOrder || !$plan || $originalOrder->status !== 'paid') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سرویس یا پکیج انتخاب شده یافت نشد.", $messageId);
            return;
        }

        $balance = $user->balance ?? 0;
        // تاریخ انقضای جدید پس از تمدید، دقیقاً برابر با مدت پکیج انتخابی از لحظه تمدید است
        $newExpiresAt = now()->addDays($plan->duration_days);

        $message = "🔄 *تایید تمدید سرویس*\n\n";
        $message .= "▫️ سرویس فعلی: *{$this->escape($originalOrder->plan ? $originalOrder->plan->name : '—')}*\n";
        $message .= "▫️ پکیج تمدید: *{$this->escape($plan->name)}* ({$plan->volume_gb}GB / {$plan->duration_days} روز)\n";
        $message .= "▫️ هزینه تمدید: *" . number_format($plan->price) . " تومان*\n";
        $message .= "▫️ موجودی کیف پول: *" . number_format($balance) . " تومان*\n";
        $message .= "▫️ اعتبار جدید پس از تمدید: *{$this->escape(to_jalali_date($newExpiresAt, 'Y/m/d'))}* ({$plan->duration_days} روز از امروز)\n\n";
        $message .= "لطفاً روش پرداخت برای تمدید را انتخاب کنید:";

        $keyboard = Keyboard::make()->inline();
        if ($balance >= $plan->price) {
            $keyboard->row([Keyboard::inlineButton(['text' => '✅ تمدید با کیف پول (آنی)', 'callback_data' => "renew_pay_wallet_{$originalOrderId}_{$plan->id}"])]);
        }
        $keyboard->row([Keyboard::inlineButton(['text' => '💳 تمدید با کارت به کارت', 'callback_data' => "renew_pay_card_{$originalOrderId}_{$plan->id}"])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به انتخاب پکیج', 'callback_data' => "renew_order_{$originalOrderId}"])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    /**
     * ✅ اصلاح: استفاده از & برای دسترسی به متغیرها پس از تراکنش
     */
    protected function processRenewalWalletPayment($user, $originalOrderId, $messageId, $renewPlanId = null)
    {
        $originalOrder = $user->orders()->with('plan')->find($originalOrderId);
        $newRenewalOrder = null;
        $provisionData = null;

        if (!$originalOrder || $originalOrder->status !== 'paid') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سرویس مورد نظر برای تمدید یافت نشد.", $messageId);
            return;
        }

        if ($renewPlanId) {
            $plan = Plan::where('id', $renewPlanId)->where('is_active', true)->first();
            if (!$plan) {
                $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ پکیج انتخاب شده یافت نشد.", $messageId);
                return;
            }
        } else {
            $plan = $originalOrder->plan;
            if (!$plan) {
                $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ پلن سرویس یافت نشد.", $messageId);
                return;
            }
        }

        // بررسی موجودی قبل از هر کاری
        if ($user->balance < $plan->price) {
            $keyboard = Keyboard::make()->inline()
                ->row([
                    Keyboard::inlineButton(['text' => '💳 شارژ کیف پول', 'callback_data' => '/deposit']),
                    Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/my_services'])
                ]);
            $this->sendOrEditMessage($user->telegram_chat_id, "❌ موجودی کیف پول شما برای تمدید کافی نیست.", $keyboard, $messageId);
            return;
        }

        try {
            DB::transaction(function () use ($user, $originalOrder, $plan, &$newRenewalOrder, &$provisionData) { // ✅ & اضافه شد

                $user->decrement('balance', $plan->price);

                $newRenewalOrder = $user->orders()->create([
                    'plan_id' => $plan->id,
                    'server_id' => $originalOrder->server_id ?: $this->resolveBestServerId($plan),
                    'status' => 'paid',
                    'source' => 'telegram_renewal',
                    'amount' => $plan->price,
                    'expires_at' => null,
                    'payment_method' => 'wallet',
                    'panel_username' => $originalOrder->panel_username,
                ]);

                $newRenewalOrder->renews_order_id = $originalOrder->id;
                $newRenewalOrder->save();

                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $newRenewalOrder->id,
                    'amount' => -$plan->price,
                    'type' => 'purchase',
                    'status' => 'completed',
                    'description' => "تمدید سرویس {$plan->name} (سفارش اصلی #{$originalOrder->id})"
                ]);

                $provisionData = $this->renewUserAccount($originalOrder, $plan);

                if (!$provisionData) {
                    throw new \Exception('تمدید در پنل با خطا مواجه شد.');
                }
            });

            $successKeyboard = Keyboard::make()->inline()->row([
                Keyboard::inlineButton(['text' => '🛠 سرویس‌های من', 'callback_data' => '/my_services']),
                Keyboard::inlineButton(['text' => '🏠 منوی اصلی', 'callback_data' => '/start']),
            ]);

            $this->sendOrEditMessage(
                $user->telegram_chat_id,
                $this->escape('✅ تمدید با موفقیت انجام شد. اطلاعات اتصال در پیام جدید برای شما ارسال می‌شود.'),
                $successKeyboard,
                $messageId,
            );

            app(TelegramOrderNotificationService::class)
                ->sendServiceActivated($newRenewalOrder->fresh());

        } catch (\Exception $e) {
            Log::error('Renewal Wallet Payment Failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'original_order_id' => $originalOrderId,
                'user_id' => $user->id
            ]);

            if ($newRenewalOrder) {
                try {
                    $user->increment('balance', $plan->price);
                } catch (\Exception $refundEx) {
                    Log::critical("Failed to refund user {$user->id}: " . $refundEx->getMessage());
                }
                $newRenewalOrder->delete();
            }

            $errorKeyboard = Keyboard::make()->inline()->row([
                Keyboard::inlineButton(['text' => '💬 پشتیبانی', 'callback_data' => '/support_menu'])
            ]);

            $errorMessage = $this->escape("⚠️ تمدید با خطا مواجه شد. مبلغ {$plan->price} تومان به کیف پول شما بازگردانده شد.");
            $this->sendOrEditMessage($user->telegram_chat_id, $errorMessage, $errorKeyboard, $messageId);
        }
    }

    /**
     * ارسال لینک خام (بدون فرمت) برای کپی آسان
     */
    protected function handleCopyLinkRequest($user, $orderId, $messageId = null)
    {
        try {
            $order = $user->orders()->with('plan')->find($orderId);

            if (!$order || $order->status !== 'paid') {
                Telegram::sendMessage([
                    'chat_id' => $user->telegram_chat_id,
                    'text' => $this->escape("❌ سفارش یافت نشد یا معتبر نیست."),
                    'parse_mode' => 'MarkdownV2'
                ]);
                return;
            }

            if (empty($order->config_details)) {
                Telegram::sendMessage([
                    'chat_id' => $user->telegram_chat_id,
                    'text' => $this->escape("❌ لینک کانفیگ هنوز آماده نیست."),
                    'parse_mode' => 'MarkdownV2'
                ]);
                return;
            }

            // ارسال لینک خالی (بدون markdown) که کاربر بتواند کپی کند
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $order->config_details, // فقط لینک خالی بدون هیچ فرمتی
                'reply_markup' => Keyboard::make()->inline()->row([
                    Keyboard::inlineButton(['text' => '⬅️ بازگشت به جزئیات سرویس', 'callback_data' => "show_service_{$orderId}"])
                ])
            ]);

        } catch (\Exception $e) {
            Log::error('Copy link error: ' . $e->getMessage());
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ خطا در ارسال لینک."),
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    }


    protected function handleRenewCardPayment($user, $originalOrderId, $messageId, $renewPlanId = null)
    {
        $originalOrder = $user->orders()->with('plan')->find($originalOrderId);
        if (!$originalOrder || $originalOrder->status !== 'paid') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سرویس مورد نظر برای تمدید یافت نشد.", $messageId);
            return;
        }
        if ($renewPlanId) {
            $plan = Plan::where('id', $renewPlanId)->where('is_active', true)->first();
            if (!$plan) {
                $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ پکیج انتخاب شده یافت نشد.", $messageId);
                return;
            }
        } else {
            $plan = $originalOrder->plan;
            if (!$plan) {
                $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ پلن سرویس یافت نشد.", $messageId);
                return;
            }
        }

        $newRenewalOrder = $user->orders()->create([
            'plan_id' => $plan->id,
            'server_id' => $originalOrder->server_id ?: $this->resolveBestServerId($plan),
            'status' => 'pending',
            'source' => 'telegram_renewal',
            'amount' => $plan->price,
            'expires_at' => null,
            'panel_username' => $originalOrder->panel_username,
            'payment_method' => 'card',
        ]);

        $newRenewalOrder->renews_order_id = $originalOrder->id;
        $newRenewalOrder->save();

        $this->sendCardPaymentInfo($user->telegram_chat_id, $newRenewalOrder->id, $messageId);
    }

    /**
     * ⚠️ نکته: اطمینان حاصل کنید که متدهای updateUser و resetUserTraffic در MarzbanService
     * و updateClient و resetClientTraffic در XUIService وجود دارند
     */
    protected function renewUserAccount(Order $originalOrder, Plan $plan)
    {
        $settings = $this->settings;
        $user = $originalOrder->user;
        $uniqueUsername = $originalOrder->panel_username ?? \App\Services\ClientNamingService::generate($user->id, $originalOrder->id);

        $isMultiLocationEnabled = filter_var(
            $settings->get('enable_multilocation', false),
            FILTER_VALIDATE_BOOLEAN
        );

        // ✅ تاریخ و اعتبار اشتراک پس از تمدید دقیقاً برابر با پکیج انتخابی است:
        // همان‌طور که حجم ترافیک با مقدار پکیج جایگزین می‌شود، تاریخ انقضای جدید نیز
        // از لحظه تمدید به‌مدت پکیج انتخابی محاسبه می‌شود (بدون اضافه شدن روزهای باقی‌مانده).
        $newExpiryDate = now()->addDays($plan->duration_days);

        $isMultiServer = false;
        $panelType = $settings->get('panel_type');
        if (empty($panelType)) {
            $hasXui = !empty($settings->get('xui_host')) && !empty($settings->get('xui_user')) && !empty($settings->get('xui_pass'));
            $hasMarzban = !empty($settings->get('marzban_host'));
            $panelType = $hasXui ? 'xui' : ($hasMarzban ? 'marzban' : 'xui');
        }
        $targetServer = null;

        $xuiHost = $settings->get('xui_host');
        $xuiUser = $settings->get('xui_user');
        $xuiPass = $settings->get('xui_pass');
        $inboundId = (int) $settings->get('xui_default_inbound_id');

        // بررسی مولتی سرور
        if (class_exists('Modules\MultiServer\Models\Server')) {
            if (!$originalOrder->server_id) {
                $targetServerId = $this->resolveBestServerId($plan);
                if ($targetServerId) {
                    $originalOrder->server_id = $targetServerId;
                    try { $originalOrder->save(); } catch (\Exception $e) {}
                }
            }
            $targetServer = $originalOrder->server_id ? \Modules\MultiServer\Models\Server::find($originalOrder->server_id) : null;
            if ($targetServer && $targetServer->is_active) {
                $isMultiServer = true;
                $panelType = strtolower($targetServer->type ?? 'xui');
                if ($panelType === 'sanaei') $panelType = 'xui';

                $xuiHost = $targetServer->full_host;
                $xuiUser = $targetServer->username;
                $xuiPass = $targetServer->password;
                $inboundId = $targetServer->inbound_id;

                // PasarGuard credentials for multi-server
                $pasarguardHost = $targetServer->full_host;
                $pasarguardUser = $targetServer->username;
                $pasarguardPass = $targetServer->password;
                $pasarguardNode = $targetServer->pasarguard_node_hostname ?? $pasarguardHost;

                // Marzban credentials for multi-server
                $marzbanHost = $targetServer->full_host;
                $marzbanUser = $targetServer->username;
                $marzbanPass = $targetServer->password;
                $marzbanNode = $targetServer->marzban_node_hostname ?? $marzbanHost;
            }
        }

        try {
            // --- PASARGUARD ---
            if ($panelType === 'pasarguard') {
                $pasarguard = new PasarGuardService(
                    $isMultiServer ? ($pasarguardHost ?? '') : (string) $settings->get('pasarguard_host'),
                    $isMultiServer ? ($pasarguardUser ?? '') : (string) $settings->get('pasarguard_username'),
                    $isMultiServer ? ($pasarguardPass ?? '') : (string) $settings->get('pasarguard_password'),
                    $isMultiServer ? ($pasarguardNode ?? '') : (string) $settings->get('pasarguard_node_hostname')
                );

                $updateResponse = $pasarguard->updateUser($uniqueUsername, [
                    'expire' => $newExpiryDate->timestamp,
                    'data_limit' => (int) ($plan->volume_gb * 1073741824),
                ]);
                // 🔥 ریست کردن ترافیک مصرفی — به‌صورت best-effort؛ تمدید نباید با خطای ریست شکست بخورد
                $resetResponse = $pasarguard->resetUserTraffic($uniqueUsername);
                if ($resetResponse === null) {
                    Log::warning("PasarGuard traffic reset FAILED after renewal for user: $uniqueUsername");
                }

                if ($updateResponse !== null && (isset($updateResponse['username']) || isset($updateResponse['subscription_url']))) {
                    $originalOrder->update([
                        'expires_at' => $newExpiryDate,
                        'plan_id' => $plan->id,
                    ]);
                    return [
                        'link' => $originalOrder->config_details,
                        'username' => $uniqueUsername
                    ];
                } else {
                    return null;
                }
            }
            // --- MARZBAN ---
            elseif ($panelType === 'marzban') {
                $marzban = new MarzbanService(
                    $isMultiServer ? ($marzbanHost ?? '') : (string) $settings->get('marzban_host'),
                    $isMultiServer ? ($marzbanUser ?? '') : (string) $settings->get('marzban_sudo_username'),
                    $isMultiServer ? ($marzbanPass ?? '') : (string) $settings->get('marzban_sudo_password'),
                    $isMultiServer ? ($marzbanNode ?? '') : (string) $settings->get('marzban_node_hostname')
                );

                $renewPayload = [
                    'expire' => $newExpiryDate->timestamp,
                    'data_limit' => (int) ($plan->volume_gb * 1073741824),
                ];

                $updateResponse = $marzban->updateUser($uniqueUsername, $renewPayload);

                // اگر آپدیت ناموفق بود (مثلاً کاربر از پنل مرزبان حذف شده باشد)،
                // کاربر را دوباره می‌سازیم تا تمدید از دست نرود.
                $recreated = false;
                if ($updateResponse === null) {
                    Log::warning("Marzban renewal: update failed for {$uniqueUsername}, attempting to recreate the user.");
                    $createResponse = $marzban->createUser(array_merge($renewPayload, ['username' => $uniqueUsername]));
                    if ($createResponse !== null && isset($createResponse['username'])) {
                        $recreated = true;
                        $updateResponse = $createResponse;
                    } else {
                        return null;
                    }
                }

                if ($recreated) {
                    // کاربر از نو ساخته شد؛ لینک سابسکریپشن جدید را ذخیره کن
                    $newLink = $marzban->generateSubscriptionLink($updateResponse) ?: $originalOrder->config_details;
                    $originalOrder->update([
                        'expires_at' => $newExpiryDate,
                        'config_details' => $newLink,
                        'plan_id' => $plan->id,
                    ]);
                    return [
                        'link' => $newLink,
                        'username' => $uniqueUsername
                    ];
                }

                // 🔥 ریست کردن ترافیک مصرفی (مهم برای تمدید) — best-effort، مثل مسیر X-UI
                $resetResponse = $marzban->resetUserTraffic($uniqueUsername);
                if ($resetResponse !== null) {
                    Log::info("Marzban traffic reset successful for user: $uniqueUsername");
                } else {
                    Log::warning("Marzban traffic reset FAILED after renewal for user: $uniqueUsername");
                }

                $originalOrder->update([
                    'expires_at' => $newExpiryDate,
                    'plan_id' => $plan->id,
                ]);
                return [
                    'link' => $originalOrder->config_details,
                    'username' => $uniqueUsername
                ];
            }
            // --- X-UI (SANAEI) ---
            elseif ($panelType === 'xui') {
                if ($inboundId <= 0) {
                    throw new \Exception("❌ Inbound ID نامعتبر: {$inboundId}");
                }

                $xui = new XUIService($xuiHost, $xuiUser, $xuiPass);

                if (!$xui->login()) {
                    throw new \Exception("❌ خطا در لاگین به پنل X-UI");
                }

                // گرفتن اطلاعات اینباند
                $inboundData = null;
                if ($isMultiServer) {
                    $allInbounds = $xui->getInbounds();
                    foreach ($allInbounds as $remoteInbound) {
                        if ($remoteInbound['id'] == $inboundId) {
                            $inboundData = $remoteInbound;
                            break;
                        }
                    }
                    if (!$inboundData) throw new \Exception("اینباند در سرور یافت نشد.");
                } else {
                    $inboundModel = Inbound::whereJsonContains('inbound_data->id', (int)$inboundId)->first();
                    if ($inboundModel) {
                        $inboundData = is_string($inboundModel->inbound_data) ? json_decode($inboundModel->inbound_data, true) : $inboundModel->inbound_data;
                    } else {
                        throw new \Exception("اینباند پیش‌فرض یافت نشد.");
                    }
                }

                // پیدا کردن کلاینت قبلی
                $clients = $xui->getClients($inboundData['id']);
                $client = collect($clients)->firstWhere('email', $uniqueUsername);

                if (!$client) {
                    throw new \Exception("❌ کلاینت با ایمیل {$uniqueUsername} یافت نشد.");
                }

                $linkType = ($isMultiServer && $targetServer) ? ($targetServer->link_type ?? 'single') : $settings->get('xui_link_type', 'single');

                $clientData = [
                    'id' => $client['id'],
                    'email' => $uniqueUsername,
                    'total' => $plan->volume_gb * 1073741824, // حجم جدید بر حسب بایت
                    'expiryTime' => $newExpiryDate->timestamp * 1000, // زمان انقضای جدید
                ];

                if ($linkType === 'subscription' && isset($client['subId'])) {
                    $clientData['subId'] = $client['subId'];
                }

                // ۱. آپدیت کردن زمان و حجم کلی
                $response = $xui->updateClient($inboundData['id'], $client['id'], $clientData);

                if ($response && isset($response['success']) && $response['success']) {

                    // 🔥 ۲. ریست کردن ترافیک مصرفی (مهم برای تمدید) 🔥
                    $resetResult = $xui->resetClientTraffic($inboundData['id'], $uniqueUsername);

                    if ($resetResult) {
                        Log::info("Traffic reset successful for user: $uniqueUsername");
                    } else {
                        Log::warning("Traffic reset FAILED for user: $uniqueUsername");
                    }

                    $originalOrder->update([
                        'expires_at' => $newExpiryDate,
                        'plan_id' => $plan->id,
                    ]);
                    return [
                        'link' => $originalOrder->config_details,
                        'username' => $uniqueUsername
                    ];
                } else {
                    $errorMsg = $response['msg'] ?? 'Unknown Error';
                    throw new \Exception("❌ خطا در بروزرسانی کلاینت: " . $errorMsg);
                }
            } else {
                throw new \Exception("❌ نوع پنل پشتیبانی نمی‌شود: {$panelType}");
            }
        } catch (\Exception $e) {
            Log::error("❌ تمدید انجام نشد ({$uniqueUsername}): " . $e->getMessage(), [
                'is_multi_server' => $isMultiServer,
                'server_id' => $originalOrder->server_id ?? null
            ]);
            return null;
        }
    }
    protected function showSupportMenu($user, $messageId = null)
    {
        $tickets = $user->tickets()->latest()->take(4)->get();
        $message = "💬 *پشتیبانی*\n\n";
        if ($tickets->isEmpty()) {
            $message .= $this->escape("شما تاکنون هیچ تیکتی ثبت نکرده‌اید.");
        } else {
            $message .= $this->escape("لیست آخرین تیکت‌های شما:") . "\n";
            foreach ($tickets as $ticket) {
                $status = match ($ticket->status) {
                    'open' => '🔵 باز',
                    'answered' => '🟢 پاسخ ادمین',
                    'closed' => '⚪️ بسته',
                    default => '⚪️ نامشخص',
                };
                $ticketIdEscaped = $this->escape((string)$ticket->id);
                $message .= "\n📌 *تیکت " . $this->escape("#{$ticketIdEscaped}") . "* " . $this->escape(" | ") . $this->escape($status) . "\n";
                $message .= "*موضوع:* " . $this->escape($ticket->subject) . "\n";
                $message .= "_{$this->escape($ticket->updated_at->diffForHumans())}_";
            }
        }
        
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '📝 ایجاد تیکت جدید', 'callback_data' => '/support_new'])]);
        foreach ($tickets as $ticket) {
            if ($ticket->status !== 'closed') {
                $keyboard->row([
                    Keyboard::inlineButton(['text' => "✏️ پاسخ/مشاهده تیکت #{$ticket->id}", 'callback_data' => "reply_ticket_{$ticket->id}"]),
                    Keyboard::inlineButton(['text' => "❌ بستن تیکت #{$ticket->id}", 'callback_data' => "close_ticket_{$ticket->id}"]),
                ]);
            }
        }
        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function promptForNewTicket($user, $messageId)
    {
        $user->update(['bot_state' => 'awaiting_new_ticket_subject']);
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, "📝 لطفاً *موضوع* تیکت جدید را در یک پیام ارسال کنید:", $keyboard, $messageId);
    }

    protected function promptForTicketReply($user, $ticketId, $messageId)
    {
        $user->update(['bot_state' => 'awaiting_ticket_reply|' . $ticketId]);
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        // ⚠️ متن باید با escape() برای MarkdownV2 آماده شود، وگرنه تلگرام پیام را با خطای
        // "can't parse entities" رد می‌کند و کاربر هیچ پیامی برای نوشتن پاسخ نمی‌بیند.
        $message = "✏️ " . $this->escape("لطفاً پاسخ خود را برای تیکت #{$ticketId} وارد کنید (می‌توانید عکس هم ارسال کنید):");
        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function closeTicket($user, $ticketId, $messageId, $callbackQueryId)
    {
        $ticket = $user->tickets()->where('id', $ticketId)->first();
        if ($ticket && $ticket->status !== 'closed') {
            $ticket->update(['status' => 'closed']);
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => "تیکت #{$ticketId} بسته شد.",
                    'show_alert' => false
                ]);
            } catch (\Exception $e) { Log::warning("Could not answer close ticket query: ".$e->getMessage());}
            $this->showSupportMenu($user, $messageId);
        } else {
            try { Telegram::answerCallbackQuery(['callback_query_id' => $callbackQueryId, 'text' => "تیکت یافت نشد یا قبلا بسته شده.", 'show_alert' => true]); } catch (\Exception $e) {}
        }
    }

    protected function processTicketConversation($user, $text, $update)
    {
        $state = $user->bot_state;
        $chatId = $user->telegram_chat_id;

        try {
            if ($state === 'awaiting_new_ticket_subject') {
                if (mb_strlen($text) < 3) {
                    Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ موضوع باید حداقل ۳ حرف باشد. لطفا دوباره تلاش کنید."), 'parse_mode' => 'MarkdownV2']);
                    return;
                }
                $user->update(['bot_state' => 'awaiting_new_ticket_message|' . $text]);
                $promptMsg = "✅ " . $this->escape("موضوع دریافت شد.") . "

" . $this->escape("حالا ") . "*" . $this->escape("متن پیام") . "*" . $this->escape(" خود را وارد کنید (می‌توانید همراه پیام، عکس هم ارسال کنید):");
                Telegram::sendMessage(['chat_id' => $chatId, 'text' => $promptMsg, 'parse_mode' => 'MarkdownV2']);

            } elseif (Str::startsWith($state, 'awaiting_new_ticket_message|')) {
                $subject = Str::after($state, 'awaiting_new_ticket_message|'); // This one is string, not int
                $isPhotoOnly = $update->getMessage()->has('photo') && (empty(trim($text)) || $text === '[📎 فایل پیوست شد]');
                $messageText = $isPhotoOnly ? '[📎 پیوست تصویر]' : $text;

                if (empty(trim($messageText))) {
                    Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ متن پیام نمی‌تواند خالی باشد. لطفا پیام خود را وارد کنید:"), 'parse_mode' => 'MarkdownV2']);
                    return;
                }

                $ticket = $user->tickets()->create([
                    'subject' => $subject,
                    'message' => $messageText,
                    'priority' => 'medium', 'status' => 'open', 'source' => 'telegram', 'user_id' => $user->id
                ]);

                $replyData = ['user_id' => $user->id, 'message' => $messageText];
                if ($update->getMessage()->has('photo')) {
                    try { $replyData['attachment_path'] = $this->savePhotoAttachment($update, 'ticket_attachments'); }
                    catch (\Exception $e) { Log::error("Error saving photo for new ticket {$ticket->id}: " . $e->getMessage()); }
                }
                $reply = $ticket->replies()->create($replyData);

                $user->update(['bot_state' => null]);
                Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("✅ تیکت #{$ticket->id} با موفقیت ثبت شد."), 'parse_mode' => 'MarkdownV2']);
                $this->sendOrEditMainMenu($chatId, "پشتیبانی به زودی پاسخ شما را خواهد داد.");

                event(new TicketCreated($ticket));

                // Notify admins about new ticket
                try {
                    $adminChatIds = getTelegramAdminChatIds($this->settings);
                    if (!empty($adminChatIds) && ($botToken = $this->settings->get('telegram_bot_token'))) {
                        Telegram::setAccessToken($botToken);
                        $adminMsg = "🧾 <b>تیکت جدید از ربات تلگرام</b>\n\n";
                        $adminMsg .= "<b>کاربر:</b> " . escapeTelegramHTML((string) $user->name) . " (ID: " . escapeTelegramHTML((string) $user->id) . ")\n";
                        $adminMsg .= "<b>موضوع:</b> " . escapeTelegramHTML((string) $ticket->subject) . "\n\n";
                        $adminMsg .= "<b>متن پیام:</b>\n" . escapeTelegramHTML((string) $messageText);
                        $adminKeyboard = Keyboard::make()->inline()->row([
                            Keyboard::inlineButton([
                                'text' => '✉️ پاسخ به تیکت',
                                'callback_data' => "admin_reply_ticket_{$ticket->id}",
                            ]),
                            Keyboard::inlineButton([
                                'text' => '❌ بستن تیکت',
                                'callback_data' => "admin_close_ticket_{$ticket->id}",
                            ]),
                        ]);
                        foreach ($adminChatIds as $adminChatId) {
                            if ($adminChatId) {
                                Telegram::sendMessage([
                                    'chat_id' => $adminChatId,
                                    'text' => $adminMsg,
                                    'parse_mode' => 'HTML',
                                    'reply_markup' => $adminKeyboard,
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to notify admins of new ticket: ' . $e->getMessage());
                }

            } elseif (Str::startsWith($state, 'awaiting_ticket_reply|')) {
                $ticketId = (int) Str::after($state, 'awaiting_ticket_reply|');
                $ticket = $user->tickets()->find($ticketId);

                if (!$ticket) {
                    $this->sendOrEditMainMenu($chatId, "❌ تیکت مورد نظر یافت نشد.");
                    return;
                }

                $isPhotoOnly = $update->getMessage()->has('photo') && (empty(trim($text)) || $text === '[📎 فایل پیوست شد]');
                $messageText = $isPhotoOnly ? '[📎 پیوست تصویر]' : $text;

                if (empty(trim($messageText))) {
                    Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ متن پاسخ نمی‌تواند خالی باشد."), 'parse_mode' => 'MarkdownV2']);
                    return;
                }

                $replyData = ['user_id' => $user->id, 'message' => $messageText];
                if ($update->getMessage()->has('photo')) {
                    try { $replyData['attachment_path'] = $this->savePhotoAttachment($update, 'ticket_attachments'); }
                    catch (\Exception $e) { Log::error("Error saving photo for ticket reply {$ticketId}: " . $e->getMessage()); }
                }
                $reply = $ticket->replies()->create($replyData);
                $ticket->update(['status' => 'open']);

                $user->update(['bot_state' => null]);
                Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("✅ پاسخ شما برای تیکت #{$ticketId} ثبت شد."), 'parse_mode' => 'MarkdownV2']);
                $this->sendOrEditMainMenu($chatId, "پشتیبانی به زودی پاسخ شما را خواهد داد.");

                event(new TicketReplied($reply));

                // Notify admins about ticket reply
                try {
                    $adminChatIds = getTelegramAdminChatIds($this->settings);
                    if (!empty($adminChatIds) && ($botToken = $this->settings->get('telegram_bot_token'))) {
                        Telegram::setAccessToken($botToken);
                        $adminMsg = "💬 <b>پاسخ جدید به تیکت #" . escapeTelegramHTML((string) $ticket->id) . "</b>\n\n";
                        $adminMsg .= "<b>کاربر:</b> " . escapeTelegramHTML((string) $user->name) . " (ID: " . escapeTelegramHTML((string) $user->id) . ")\n";
                        $adminMsg .= "<b>متن پاسخ:</b>\n" . escapeTelegramHTML((string) $messageText);
                        $adminKeyboard = Keyboard::make()->inline()->row([
                            Keyboard::inlineButton([
                                'text' => '✉️ پاسخ به تیکت',
                                'callback_data' => "admin_reply_ticket_{$ticket->id}",
                            ]),
                            Keyboard::inlineButton([
                                'text' => '❌ بستن تیکت',
                                'callback_data' => "admin_close_ticket_{$ticket->id}",
                            ]),
                        ]);
                        foreach ($adminChatIds as $adminChatId) {
                            if ($adminChatId) {
                                Telegram::sendMessage([
                                    'chat_id' => $adminChatId,
                                    'text' => $adminMsg,
                                    'parse_mode' => 'HTML',
                                    'reply_markup' => $adminKeyboard,
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to notify admins of ticket reply: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to process ticket conversation: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $user->update(['bot_state' => null]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->escape("❌ خطایی در پردازش پیام شما رخ داد. لطفاً دوباره تلاش کنید."),
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    }

    /**
     * ✅ اصلاح: حذف فاصله اضافی از URL و اضافه کردن import Http facade
     */
    protected function isUserMemberOfChannel($user)
    {
        $forceJoin = $this->settings->get('force_join_enabled', '0');
        
        Log::info("Membership Check Debug: User {$user->id}", [
            'force_join_enabled' => $forceJoin,
            'settings_count' => $this->settings->count()
        ]);

        if (!in_array($forceJoin, ['1', 1, true, 'on', 'true', 'True'], true)) {
            Log::info("Membership Check: Force join disabled or invalid value.");
            return true;
        }

        $channelId = $this->settings->get('telegram_required_channel_id');
        if (empty($channelId)) {
            Log::error('FORCE JOIN IS ENABLED BUT NO CHANNEL ID IS SET!');
            return false;
        }

        // Auto-fix: Add @ if missing for public channels
        if (!str_starts_with($channelId, '@') && !str_starts_with($channelId, '-100')) {
            $channelId = '@' . $channelId;
        }

        try {
            $botToken = $this->settings->get('telegram_bot_token');
            $apiUrl = "https://api.telegram.org/bot{$botToken}/getChatMember";

            $response = Http::timeout(10)->get($apiUrl, [
                'chat_id' => $channelId,
                'user_id' => $user->telegram_chat_id,
            ]);

            Log::info("Telegram API Response for Membership Check", [
                'status' => $response->status(),
                'body' => $response->json(),
                'channel_id' => $channelId,
                'user_telegram_id' => $user->telegram_chat_id
            ]);

            if (!$response->successful()) {
                return false;
            }

            $data = $response->json();
            $status = $data['result']['status'] ?? 'left';

            return in_array($status, ['member', 'administrator', 'creator'], true);

        } catch (\Exception $e) {
            Log::error("Membership check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ اصلاح: حذف فاصله اضافی از URL
     */
    protected function showChannelRequiredMessage($chatId, $messageId = null)
    {
        $channelId = $this->settings->get('telegram_required_channel_id');

        if (empty($channelId)) {
            $message = "❌ خطا: کانال عضویت اجباری تنظیم نشده است.";
            $this->sendOrEditMessage($chatId, $message, null, $messageId);
            return;
        }

        // Auto-fix: Add @ if missing for public channels
        if (!str_starts_with($channelId, '@') && !str_starts_with($channelId, '-100')) {
            $channelId = '@' . $channelId;
        }

        $channelLink = null;
        $channelDisplayName = $channelId;

        if (str_starts_with($channelId, '@')) {
            $username = ltrim($channelId, '@');
            $channelLink = "https://t.me/{$username}";
            $channelDisplayName = "@" . $username;
        } elseif (preg_match('/^-100\d+$/', $channelId)) {
            $channelDisplayName = "کانال خصوصی";
            $channelLink = $this->settings->get('telegram_private_channel_invite_link');
        }

        $message = "⛔️ *" . $this->escape("عضویت در کانال الزامی است!") . "*\n\n";
        $message .= $this->escape("برای ادامه استفاده از ربات، باید در کانال زیر عضو شوید:") . "\n\n";
        $message .= "📢 " . $this->escape($channelDisplayName) . "\n\n";
        $message .= "🔹 " . $this->escape("پس از عضویت، روی دکمه «✅ بررسی عضویت» بزنید.");

        $keyboard = Keyboard::make()->inline();

        if (!empty($channelLink)) {
            $keyboard->row([Keyboard::inlineButton(['text' => '📲 عضویت در کانال', 'url' => $channelLink])]);
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '✅ بررسی عضویت', 'callback_data' => '/check_membership'])]);

        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    /**
     * ✅ اصلاح: حذف فاصله اضافی از URL دانلود فایل
     */

    /**
     * ارسال پیام موفقیت‌آمیز بودن خرید با دکمه‌ها
     * این متد هم برای پرداخت کیف پول و هم کارت به کارت استفاده می‌شه
     */
    protected function sendPurchaseSuccessMessage($user, Order $order, $messageId = null)
    {
        app(TelegramOrderNotificationService::class)
            ->sendServiceActivated($order->fresh());
    }

    protected function savePhotoAttachment($update, $directory)
    {
        $message = $update->getMessage();
        if (!$message) {
            return null;
        }
        
        $photos = $message->getPhoto();
        if (!$photos) {
            return null;
        }
        
        $photo = collect($photos)->last();
        if(!$photo) return null;

        $botToken = $this->settings->get('telegram_bot_token');
        try {
            $file = Telegram::getFile(['file_id' => $photo->getFileId()]);
            $filePath = method_exists($file, 'getFilePath') ? $file->getFilePath() : ($file['file_path'] ?? null);
            if(!$filePath) { throw new \Exception('File path not found in Telegram response.'); }

            // ✅ اصلاح: حذف space های اضافی
            $fileContents = file_get_contents("https://api.telegram.org/file/bot{$botToken}/{$filePath}");
            if ($fileContents === false) { throw new \Exception('Failed to download file content.');}

            Storage::disk('public')->makeDirectory($directory);
            $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $fileName = $directory . '/' . Str::random(40) . '.' . $extension;
            $success = Storage::disk('public')->put($fileName, $fileContents);

            if (!$success) { throw new \Exception('Failed to save file to storage.'); }

            return $fileName;

        } catch (\Exception $e) {
            Log::error('Error saving photo attachment: ' . $e->getMessage(), ['file_id' => $photo->getFileId()]);
            return null;
        }
    }

    /**
     * ✅ نسخه اصلی sendReferralMenu (متد تکراری دیگری در انتهای فایل وجود داشت که حذف شد)
     */
    protected function sendReferralMenu($user, $messageId = null)
    {
        try {
            $botInfo = Telegram::getMe();
            $botUsername = $botInfo->getUsername();
        } catch (\Exception $e) {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ خطا در دریافت اطلاعات ربات", $messageId);
            return;
        }
        
        $referralCode = $user->referral_code ?? Str::random(8);
        if (!$user->referral_code) {
            $user->update(['referral_code' => $referralCode]);
        }
        
        $referralLink = "https://t.me/{$botUsername}?start={$referralCode}";
        $fixedReward = (int) $this->settings->get('referral_referrer_reward', 0);
        $percentReward = (float) $this->settings->get('referral_referrer_reward_percent', 0);
        $welcomeGift = (int) $this->settings->get('referral_welcome_gift', 0);
        $referralCount = $user->referrals()->count();

        $rewardDescription = [];
        if ($fixedReward > 0) {
            $rewardDescription[] = number_format($fixedReward) . " تومان ";
        }
        if ($percentReward > 0) {
            $rewardDescription[] = rtrim(rtrim(number_format($percentReward, 2), '0'), '.') . "% از مبلغ سفارش ";
        }
        if (empty($rewardDescription)) {
            $rewardText = $this->escape("در حال حاضر پاداشی تعریف نشده است.");
        } else {
            $rewardText = "*" . implode("*" . $this->escape(" + ") . "*", array_map([$this, 'escape'], $rewardDescription)) . "*";
        }

        $message = "🎁 *" . $this->escape("دعوت از دوستان") . "*\n\n";
        $message .= $this->escape("با اشتراک‌گذاری لینک زیر، دوستان خود را به ربات دعوت کنید.") . "\n\n";
        if (!empty($rewardDescription)) {
            $message .= "💸 " . $this->escape("با هر خرید موفق دوستانتان، ") . $rewardText . $this->escape(" به کیف پول شما اضافه می‌شود.") . "\n\n";
        }
        if ($welcomeGift > 0) {
            $message .= "🎁 " . $this->escape("هدیه خوش‌آمدگویی برای دوست شما: ") . "*" . $this->escape(number_format($welcomeGift) . " تومان") . "*\n\n";
        }
        $message .= "🔗 *" . $this->escape("لینک دعوت شما:") . "*\n`{$referralLink}`\n\n";
        $message .= "👥 " . $this->escape("تعداد دعوت‌های موفق شما: ") . "*".$this->escape("{$referralCount} نفر")."*";
        
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/start'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function handleTrialRequest($user)
    {
        // Trial requests may also be triggered by callbacks created before the
        // current request. Normalize here as well as in handle() so legacy
        // JSON-encoded values cannot turn numeric limits into zero or make the
        // configured panel type unrecognizable.
        $settings = collect($this->settings)->map(
            fn ($value) => Setting::normalizeValue($value)
        );
        $this->settings = $settings;
        $chatId = $user->telegram_chat_id;

        Log::info('Trial request initiated', [
            'user_id' => $user->id,
            'trial_enabled' => $this->isTrialEnabled(),
        ]);

        $trialEnabled = $this->isTrialEnabled();
        if (!$trialEnabled) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->escape('❌ قابلیت دریافت اکانت تست در حال حاضر غیرفعال است.'),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        $limit = (int) $settings->get('trial_limit_per_user', 1);
        $currentTrials = $user->trial_accounts_taken ?? 0;

        if ($currentTrials >= $limit) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->escape('❗️شما قبلاً از اکانت تست خود استفاده کرده‌اید و دیگر مجاز به دریافت آن نیستید.'),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        try {
            $volumeMB = (int) $settings->get('trial_volume_mb', 500);
            $durationHours = (int) $settings->get('trial_duration_hours', 24);

            $uniqueUsername = \App\Services\ClientNamingService::isEnabled() ? \App\Services\ClientNamingService::generate($user->id, null) : "trial-{$user->id}-" . ($currentTrials + 1);
            $expiresAt = now()->addHours($durationHours);
            $dataLimitBytes = $volumeMB * 1024 * 1024;

            $configLink = null;
            $panelType = $settings->get('panel_type');

            // --- تنظیمات سرور (Multi-Server Logic) ---
            $isMultiLocationEnabled = filter_var($settings->get('enable_multilocation', false), FILTER_VALIDATE_BOOLEAN);
            $targetServer = null;

            // 1. خواندن آیدی سرور تنظیم شده برای تست (از تنظیمات جدید)
            $forcedServerId = $settings->get('trial_server_id');

            // مقادیر پیش‌فرض
            $xuiHost = $settings->get('xui_host');
            $xuiUser = $settings->get('xui_user');
            $xuiPass = $settings->get('xui_pass');
            $inboundId = (int) $settings->get('xui_default_inbound_id');
            $linkType = $settings->get('xui_link_type', 'single');

            if ($isMultiLocationEnabled && class_exists('Modules\MultiServer\Models\Server')) {

                // الف) اگر ادمین سرور خاصی را در تنظیمات انتخاب کرده باشد
                if (!empty($forcedServerId)) {
                    $targetServer = \Modules\MultiServer\Models\Server::where('id', $forcedServerId)
                        ->where('is_active', true)
                        ->first();
                }

                // ب) اگر سرور انتخاب شده پیدا نشد یا ادمین چیزی انتخاب نکرده بود (انتخاب خودکار)
                if (!$targetServer) {
                    $targetServer = \Modules\MultiServer\Models\Server::where('is_active', true)
                        ->whereRaw('current_users < capacity')
                        ->first();
                }

                // اعمال تنظیمات سرور انتخاب شده
                if ($targetServer) {
                    $panelType = strtolower($targetServer->type ?? 'xui');
                    if ($panelType === 'sanaei') $panelType = 'xui';
                    $xuiHost = $targetServer->full_host;
                    $xuiUser = $targetServer->username;
                    $xuiPass = $targetServer->password;
                    $inboundId = $targetServer->inbound_id;
                    $linkType = $targetServer->link_type ?? 'single';
                    $marzbanHost = $targetServer->full_host;
                    $marzbanUser = $targetServer->username;
                    $marzbanPass = $targetServer->password;
                    $marzbanNode = $targetServer->marzban_node_hostname ?? $marzbanHost;
                    $pasarguardHost = $targetServer->full_host;
                    $pasarguardUser = $targetServer->username;
                    $pasarguardPass = $targetServer->password;
                    $pasarguardNode = $targetServer->pasarguard_node_hostname ?? $pasarguardHost;
                }
            }

            if ($panelType === 'pasarguard') {
                $pasarguardHost = $targetServer ? $targetServer->full_host : $settings->get('pasarguard_host');
                $pasarguardUser = $targetServer ? $targetServer->username : $settings->get('pasarguard_username');
                $pasarguardPass = $targetServer ? $targetServer->password : $settings->get('pasarguard_password');
                $pasarguardNode = $targetServer ? ($targetServer->pasarguard_node_hostname ?? $pasarguardHost) : $settings->get('pasarguard_node_hostname');

                if (empty($pasarguardHost)) {
                     throw new \Exception('آدرس پنل PasarGuard تنظیم نشده است.');
                }
                
                $pasarguardService = new PasarGuardService(
                    (string) $pasarguardHost,
                    (string) $pasarguardUser,
                    (string) $pasarguardPass,
                    (string) $pasarguardNode
                );
                $response = $pasarguardService->createUser([
                    'username' => $uniqueUsername,
                    'expire' => $expiresAt->timestamp,
                    'data_limit' => $dataLimitBytes,
                ]);

                if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                    $configLink = $pasarguardService->generateSubscriptionLink($response);
                } else {
                    throw new \Exception('خطا در ارتباط با پنل PasarGuard.');
                }

            } elseif ($panelType === 'marzban') {
                $marzbanHost = $targetServer ? $targetServer->full_host : $settings->get('marzban_host');
                $marzbanUser = $targetServer ? $targetServer->username : $settings->get('marzban_sudo_username');
                $marzbanPass = $targetServer ? $targetServer->password : $settings->get('marzban_sudo_password');
                $marzbanNode = $targetServer ? ($targetServer->marzban_node_hostname ?? $marzbanHost) : $settings->get('marzban_node_hostname');

                if (empty($marzbanHost)) {
                     throw new \Exception('آدرس پنل مرزبان تنظیم نشده است.');
                }
                
                $marzbanService = new MarzbanService(
                    (string) $marzbanHost,
                    (string) $marzbanUser,
                    (string) $marzbanPass,
                    (string) $marzbanNode
                );
                $response = $marzbanService->createUser([
                    'username' => $uniqueUsername,
                    'expire' => $expiresAt->timestamp,
                    'data_limit' => $dataLimitBytes,
                ]);

                if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                    $configLink = $marzbanService->generateSubscriptionLink($response);
                } else {
                    throw new \Exception('خطا در ارتباط با پنل مرزبان.');
                }

            } elseif ($panelType === 'xui') {
                $xuiService = new XUIService($xuiHost, $xuiUser, $xuiPass);

                if (!$xuiService->login()) {
                    throw new \Exception('خطا در لاگین به پنل X-UI.');
                }

                // گرفتن اطلاعات اینباند - همیشه از API پنل استفاده کن (قابلیت اطمینان بیشتر برای اکانت تست)
                $inboundData = null;
                try {
                    $inbounds = $xuiService->getInbounds();
                    foreach ($inbounds as $rem) {
                        if ((int)$rem['id'] === (int)$inboundId) {
                            $inboundData = $rem;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to fetch inbounds via API for trial: ' . $e->getMessage());
                }

                // Fallback to database if API failed and no targetServer
                if (!$inboundData && !$targetServer) {
                    $inboundModel = Inbound::whereJsonContains('inbound_data->id', (int)$inboundId)->first();
                    if ($inboundModel) {
                        $inboundData = is_string($inboundModel->inbound_data) ? json_decode($inboundModel->inbound_data, true) : $inboundModel->inbound_data;
                    }
                }

                if (!$inboundData) {
                    throw new \Exception('اینباند مورد نظر یافت نشد. لطفاً inbound ID را در تنظیمات بررسی کنید.');
                }

                $clientData = [
                    'email' => $uniqueUsername,
                    'total' => $dataLimitBytes,
                    'expiryTime' => $expiresAt->timestamp * 1000,
                ];

                if ($linkType === 'subscription') $clientData['subId'] = Str::random(16);

                $response = $xuiService->addClient($inboundData['id'], $clientData);

                if ($response && isset($response['success']) && $response['success']) {
                    $uuid = $response['generated_uuid'] ?? null;
                    if (!$uuid && isset($response['obj']['settings'])) {
                        $cSettings = json_decode($response['obj']['settings'], true);
                        $uuid = $cSettings['clients'][0]['id'] ?? null;
                    }
                    $subId = $response['generated_subId'] ?? $clientData['subId'] ?? null;

                    // ساخت لینک کانفیگ
                    $streamSettings = json_decode($inboundData['streamSettings'] ?? '{}', true);
                    $protocol = $inboundData['protocol'] ?? 'vless';
                    $inboundPort = $inboundData['port'] ?? 443;
                    $serverAddress = parse_url($xuiHost, PHP_URL_HOST);

                    switch ($linkType) {
                        case 'subscription':
                            if ($targetServer) {
                                $subDomain = $targetServer->subscription_domain ?? $serverAddress;
                                $subPort = $targetServer->subscription_port ?? 2053;
                                $subPath = $targetServer->subscription_path ?? '/sub/';
                                $isHttps = $targetServer->is_https ?? true;
                                $baseUrl = rtrim($subDomain, '/');
                                if ($subPort) $baseUrl .= ":{$subPort}";
                                $prot = $isHttps ? 'https' : 'http';
                                $configLink = "{$prot}://{$baseUrl}" . rtrim($subPath, '/') . '/' . $subId;
                            } else {
                                $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                                
                                // Fallback: if subscription URL base is not set, try to use X-UI host
                                if (empty($subBaseUrl) && !empty($xuiHost)) {
                                    $parsed = parse_url($xuiHost);
                                    $scheme = $parsed['scheme'] ?? 'http';
                                    $host = $parsed['host'] ?? '';
                                    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                                    
                                    // Fix: If panel is on 2053, subscription is usually on 2096
                                    if ($port === ':2053') {
                                        $port = ':2096';
                                    }
                                    
                                    $subBaseUrl = "{$scheme}://{$host}{$port}";
                                }
                                
                                $configLink = $subBaseUrl . '/sub/' . $subId;
                            }
                            break;

                        case 'tunnel':
                            if (!$uuid) throw new \Exception("UUID extracted failed");
                            $tunnelAddress = $targetServer->tunnel_address;
                            $tunnelPort = $targetServer->tunnel_port ?? 443;

                            // 🔥 چک کردن وضعیت TLS از دیتابیس (مثل بخش خرید)
                            $tls = filter_var($targetServer->tunnel_is_https, FILTER_VALIDATE_BOOLEAN);

                            $params = ['type' => $streamSettings['network'] ?? 'tcp'];
                            if ($tls) {
                                $params['security'] = 'tls';
                                $params['sni'] = $tunnelAddress;
                            } else {
                                $params['security'] = 'none';
                                // 🔥 اگر TLS خاموشه، encryption رو هم none کن
                                if($protocol === 'vless') $params['encryption'] = 'none';
                            }

                            if (($params['type'] ?? '') === 'ws') {
                                $params['path'] = $streamSettings['wsSettings']['path'] ?? '/';
                                $params['host'] = $streamSettings['wsSettings']['headers']['Host'] ?? $tunnelAddress;
                            }

                            $flag = $targetServer->location->flag ?? '🏳️';

                            $remarkText = $flag . "-" . $uniqueUsername;




                            $qs = http_build_query($params);
//
                            $configLink = "vless://{$uuid}@{$tunnelAddress}:{$tunnelPort}?{$qs}#" . rawurlencode($remarkText);
                            break;



                        default: // single
                            if (!$uuid) throw new \Exception("UUID extracted failed");
                            $params = ['type' => $streamSettings['network'] ?? 'tcp', 'security' => $streamSettings['security'] ?? 'none'];
                            if ($params['security'] === 'tls') $params['sni'] = $serverAddress;
                            $qs = http_build_query(array_filter($params));
                            $configLink = "vless://{$uuid}@{$serverAddress}:{$inboundPort}?{$qs}#" . rawurlencode("Trial Account");
                            break;
                    }

                    if ($targetServer) $targetServer->increment('current_users');

                } else {
                    throw new \Exception($response['msg'] ?? 'خطا در ساخت کاربر در پنل X-UI');
                }
            } else {
                throw new \Exception('نوع پنل در تنظیمات مشخص نشده است.');
            }

            if ($configLink) {
                if ($configLink) {
                    $user->increment('trial_accounts_taken');

                    // ذخیره لینک توی cache برای ۱۰ دقیقه (برای دکمه کپی)
                    \Illuminate\Support\Facades\Cache::put("trial_link_{$user->id}", $configLink, now()->addMinutes(10));

                    // بارگذاری اطلاعات سرور برای نمایش کشور
                    $locationFlag = '🏳️';
                    $locationName = 'نامشخص';
                    if ($targetServer && $targetServer->location) {
                        $locationFlag = $targetServer->location->flag ?? '🏳️';
                        $locationName = $targetServer->location->name;
                    }

                    // ساخت پیام کامل
                    $message = $this->escape("✅ اکانت تست شما با موفقیت ساخته شد!") . "\n\n";
                    $message .= "🌍 *موقعیت:* {$locationFlag} " . $this->escape($locationName) . "\n";
                    $message .= "📦 *حجم:* `{$volumeMB}` " . $this->escape("مگابایت") . "\n";
                    $message .= "⏳ *اعتبار:* `{$durationHours}` " . $this->escape("ساعت") . "\n\n";
                    $message .= "🔗 *لینک کانفیگ:*\n";
                    $message .= "`{$configLink}`\n\n";
                    $message .= $this->escape("⚠️ روی لینک بالا کلیک کنید یا دکمه زیر را بزنید.");

                    // کیبورد با دکمه کپی و QR
                    $keyboard = Keyboard::make()->inline()
                        ->row([
                            Keyboard::inlineButton(['text' => '📋 کپی لینک', 'callback_data' => "copy_trial_link_{$user->id}"]),
                            Keyboard::inlineButton(['text' => '📱 QR Code', 'callback_data' => "qr_trial_{$user->id}"])
                        ])
                        ->row([
                            Keyboard::inlineButton(['text' => '🛒 خرید سرویس', 'callback_data' => '/plans']),
                            Keyboard::inlineButton(['text' => '🏠 منوی اصلی', 'callback_data' => '/start'])
                        ]);

                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $message,
                        'parse_mode' => 'MarkdownV2',
                        'reply_markup' => $keyboard
                    ]);

                    Log::info('Trial account created successfully', ['user_id' => $user->id, 'username' => $uniqueUsername]);
                    }}
        } catch (\Exception $e) {
            Log::error('Trial Account Creation Failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->escape('❌ خطا در ساخت اکانت تست. لطفاً بعداً تلاش کنید.'),
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    }
    /**
     * Handle admin "Approve" button from receipt notification.
     */
    protected function handleAdminApproveCallback($callbackQuery, string $data, string $chatId, int $messageId): void
    {
        $orderId = (int) Str::after($data, 'admin_approve_');
        $order = Order::with('user')->find($orderId);

        if (!$order) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '❌ سفارش یافت نشد.',
                'show_alert' => true,
            ]);
            return;
        }

        if ($order->status !== 'pending') {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '⚠️ این سفارش قبلاً پردازش شده است: ' . $order->status,
                'show_alert' => true,
            ]);
            return;
        }

        // Answer the callback immediately
        try {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '⏳ در حال فعال‌سازی سرویس...',
            ]);
        } catch (\Exception $e) {}

        // Run the provisioning
        try {
            $notifyServiceActivation = false;

            DB::transaction(function () use ($order, $chatId, $messageId, &$notifyServiceActivation) {
                $settings = Setting::all()->pluck('value', 'key');
                $user = $order->user;
                $plan = $order->plan;

                // --- Wallet top-up ---
                if (!$plan) {
                    $order->update(['status' => 'paid', 'payment_method' => $order->payment_method ?: 'card']);
                    $user->increment('balance', $order->amount);
                    Transaction::create([
                        'user_id' => $user->id, 'order_id' => $order->id,
                        'amount' => $order->amount, 'type' => 'deposit',
                        'status' => 'completed', 'description' => "شارژ کیف پول (تایید تلگرامی)"
                    ]);
                    // Notify user
                    if ($user->telegram_chat_id) {
                        $msg = "✅ کیف پول شما شارژ شد.\nمبلغ: " . number_format($order->amount) . " تومان\nموجودی: " . number_format($user->fresh()->balance) . " تومان";
                        Telegram::setAccessToken($settings->get('telegram_bot_token'));
                        Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $msg, 'parse_mode' => 'Markdown']);
                    }
                    // Edit admin message
                    $this->editAdminNotification($chatId, $messageId, $order, true, null);
                    return;
                }

                // --- Purchase / Renewal ---
                $isRenewal = (bool) $order->renews_order_id;
                $originalOrder = $isRenewal ? Order::find($order->renews_order_id) : null;

                if ($isRenewal && !$originalOrder) {
                    throw new \Exception('سفارش اصلی برای تمدید یافت نشد.');
                }

                $uniqueUsername = ($isRenewal && $originalOrder?->panel_username)
                    ? $originalOrder->panel_username
                    : ($order->panel_username ?? ClientNamingService::generate($user->id, $isRenewal ? $originalOrder->id : $order->id));
                $uniqueUsername = trim($uniqueUsername);

                // ✅ چه خرید جدید چه تمدید: تاریخ انقضای جدید از لحظه فعال‌سازی به‌مدت
                // پکیج انتخابی محاسبه می‌شود تا روزهای اعتبار اشتراک دقیقاً برابر با
                // بسته باشد (مثل حجم ترافیک که با مقدار پکیج جایگزین می‌شود).
                $newExpiresAt = now()->addDays($plan->duration_days);

                // Determine panel/server
                $panelType = Setting::normalizeValue($settings->get('panel_type'));
                if (empty($panelType)) {
                    // اگر نوع پنل در تنظیمات ذخیره نشده، از روی مقادیر موجود حدس بزن
                    $hasXui = !empty($settings->get('xui_host')) && !empty($settings->get('xui_user')) && !empty($settings->get('xui_pass'));
                    $hasMarzban = !empty($settings->get('marzban_host'));
                    $panelType = $hasXui ? 'xui' : ($hasMarzban ? 'marzban' : 'xui');
                }
                $panelType = strtolower((string) $panelType);
                if ($panelType === 'sanaei') $panelType = 'xui';

                $targetServer = null;
                $targetServerId = $order->server_id;
                if (!$targetServerId && $isRenewal && $originalOrder) {
                    $targetServerId = $originalOrder->server_id;
                }

                $xuiHost = $settings->get('xui_host');
                $xuiUser = $settings->get('xui_user');
                $xuiPass = $settings->get('xui_pass');
                $inboundId = (int)$settings->get('xui_default_inbound_id');
                $marzbanHost = $settings->get('marzban_host');
                $marzbanUser = $settings->get('marzban_sudo_username');
                $marzbanPass = $settings->get('marzban_sudo_password');
                $marzbanNode = $settings->get('marzban_node_hostname');

                $pasarguardHost = $settings->get('pasarguard_host');
                $pasarguardUser = $settings->get('pasarguard_username');
                $pasarguardPass = $settings->get('pasarguard_password');
                $pasarguardNode = $settings->get('pasarguard_node_hostname');

                // Default server fallback
                if (!$targetServerId && class_exists('Modules\\MultiServer\\Models\\Server')) {
                    $defaultServer = \Modules\MultiServer\Models\Server::where('is_active', true)
                        ->whereRaw('current_users < capacity')->first()
                        ?: \Modules\MultiServer\Models\Server::where('is_active', true)->first();
                    if ($defaultServer) {
                        $targetServerId = $defaultServer->id;
                        $order->server_id = $targetServerId;
                        $order->save();
                    }
                }

                if ($targetServerId && class_exists('Modules\\MultiServer\\Models\\Server')) {
                    $targetServer = \Modules\MultiServer\Models\Server::find($targetServerId);
                    if ($targetServer && $targetServer->is_active) {
                        $panelType = strtolower($targetServer->type ?? 'xui');
                        if ($panelType === 'sanaei') $panelType = 'xui';
                        if ($panelType === 'pasarguard') {
                            $pasarguardHost = $targetServer->full_host;
                            $pasarguardUser = $targetServer->username;
                            $pasarguardPass = $targetServer->password;
                            $pasarguardNode = $targetServer->pasarguard_node_hostname ?? $pasarguardHost;
                        } elseif ($panelType === 'marzban') {
                            $marzbanHost = $targetServer->full_host;
                            $marzbanUser = $targetServer->username;
                            $marzbanPass = $targetServer->password;
                            $marzbanNode = $targetServer->marzban_node_hostname ?? $marzbanHost;
                        } else {
                            $xuiHost = $targetServer->full_host;
                            $xuiUser = $targetServer->username;
                            $xuiPass = $targetServer->password;
                            $inboundId = $targetServer->inbound_id;
                        }
                    }
                }

                $success = false;
                $finalConfig = '';
                $finalUuid = null;
                $finalSubId = null;

                if ($panelType === 'pasarguard') {
                    $pasarguard = new PasarGuardService(
                        (string)($pasarguardHost ?? ''), (string)($pasarguardUser ?? ''),
                        (string)($pasarguardPass ?? ''), (string)($pasarguardNode ?? '')
                    );
                    $userData = ['expire' => $newExpiresAt->getTimestamp(), 'data_limit' => $plan->volume_gb * 1073741824];
                    if ($isRenewal) {
                        $response = $pasarguard->updateUser($uniqueUsername, $userData);
                        $pasarguard->resetUserTraffic($uniqueUsername);
                    } else {
                        $response = $pasarguard->createUser(array_merge($userData, ['username' => $uniqueUsername]));
                    }
                    if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                        $finalConfig = $pasarguard->generateSubscriptionLink($response);
                        $success = true;
                    } else throw new \Exception('خطا در PasarGuard');
                } elseif ($panelType === 'marzban') {
                    $marzban = new MarzbanService(
                        (string)($marzbanHost ?? ''), (string)($marzbanUser ?? ''),
                        (string)($marzbanPass ?? ''), (string)($marzbanNode ?? '')
                    );
                    $userData = ['expire' => $newExpiresAt->getTimestamp(), 'data_limit' => (int) ($plan->volume_gb * 1073741824)];
                    if ($isRenewal) {
                        $response = $marzban->updateUser($uniqueUsername, $userData);
                        if ($response === null) {
                            // اگر کاربر در پنل مرزبان پیدا نشد (مثلاً حذف شده باشد)، دوباره می‌سازیم
                            Log::warning("Marzban renew (admin approve): update failed for {$uniqueUsername}, attempting to recreate the user.");
                            $response = $marzban->createUser(array_merge($userData, ['username' => $uniqueUsername]));
                        } else {
                            $marzban->resetUserTraffic($uniqueUsername);
                        }
                    } else {
                        $response = $marzban->createUser(array_merge($userData, ['username' => $uniqueUsername]));
                    }
                    if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                        $finalConfig = $marzban->generateSubscriptionLink($response);
                        $success = true;
                    } else throw new \Exception('خطا در مرزبان');
                } elseif ($panelType === 'xui') {
                    $xui = new XUIService($xuiHost, $xuiUser, $xuiPass);
                    if (!$xui->login()) throw new \Exception('خطا در لاگین X-UI');

                    $inboundData = null;
                    if ($targetServer) {
                        foreach ($xui->getInbounds() as $i) if ($i['id'] == $inboundId) { $inboundData = $i; break; }
                    } else {
                        $im = Inbound::whereJsonContains('inbound_data->id', (int)$inboundId)->first();
                        if ($im) $inboundData = is_string($im->inbound_data) ? json_decode($im->inbound_data, true) : $im->inbound_data;
                    }
                    if (!$inboundData) throw new \Exception('اینباند یافت نشد.');

                    $linkType = $targetServer ? ($targetServer->link_type ?? 'single') : $settings->get('xui_link_type', 'single');
                    $clientData = ['email' => $uniqueUsername, 'total' => $plan->volume_gb * 1073741824, 'expiryTime' => $newExpiresAt->getTimestamp() * 1000];

                    if ($isRenewal) {
                        $clients = $xui->getClients($inboundData['id']);
                        $client = collect($clients)->first(fn($c) => strtolower(trim($c['email'])) === strtolower(trim($uniqueUsername)));
                        if (!$client) throw new \Exception("کاربر {$uniqueUsername} یافت نشد.");
                        $clientData['id'] = $client['id'];
                        $clientData['subId'] = $client['subId'] ?? Str::random(16);
                        $upRes = $xui->updateClient($inboundData['id'], $client['id'], $clientData);
                        if ($upRes && ($upRes['success'] ?? false)) {
                            $xui->resetClientTraffic($inboundData['id'], $uniqueUsername);
                            $finalUuid = $client['id'];
                            $finalSubId = $clientData['subId'];
                        } else throw new \Exception('خطا در آپدیت کاربر');
                    } else {
                        if ($linkType === 'subscription') $clientData['subId'] = Str::random(16);
                        $clients = $xui->getClients($inboundData['id']);
                        $existingClient = collect($clients)->first(fn($c) => strtolower(trim($c['email'])) === strtolower(trim($uniqueUsername)));
                        if ($existingClient) {
                            $clientData['id'] = $existingClient['id'];
                            $clientData['subId'] = $existingClient['subId'] ?? Str::random(16);
                            $upRes = $xui->updateClient($inboundData['id'], $existingClient['id'], $clientData);
                            if ($upRes && ($upRes['success'] ?? false)) {
                                $xui->resetClientTraffic($inboundData['id'], $uniqueUsername);
                                $finalUuid = $existingClient['id'];
                                $finalSubId = $clientData['subId'];
                            } else throw new \Exception('خطا در آپدیت کاربر موجود');
                        } else {
                            $addRes = $xui->addClient($inboundData['id'], $clientData);
                            if ($addRes && ($addRes['success'] ?? false)) {
                                $finalUuid = $addRes['generated_uuid'] ?? json_decode($addRes['obj']['settings'], true)['clients'][0]['id'];
                                $finalSubId = $addRes['generated_subId'] ?? $clientData['subId'];
                                if ($targetServer) $targetServer->increment('current_users');
                            } else throw new \Exception('خطا در ساخت کاربر: ' . ($addRes['msg'] ?? 'Unknown'));
                        }
                    }

                    $stream = json_decode($inboundData['streamSettings'] ?? '{}', true);
                    $proto = $inboundData['protocol'] ?? 'vless';
                    $port = $inboundData['port'] ?? 443;
                    $addr = parse_url($xuiHost, PHP_URL_HOST);

                    if ($linkType === 'subscription') {
                        $subUrl = $targetServer ? ($targetServer->subscription_domain ?? $addr) : $settings->get('xui_subscription_url_base');
                        $subPort = $targetServer ? ($targetServer->subscription_port ?? 2053) : '';
                        $prot = ($targetServer && !$targetServer->is_https) ? 'http' : 'https';
                        $base = rtrim($subUrl, '/');
                        if ($subPort && !Str::contains($base, ":$subPort")) $base .= ":$subPort";
                        if (!Str::startsWith($base, 'http')) $base = "$prot://$base";
                        $finalConfig = "$base" . ($targetServer->subscription_path ?? '/sub/') . $finalSubId;
                    } elseif ($linkType === 'tunnel') {
                        $tunAddr = $targetServer->tunnel_address;
                        $tunPort = $targetServer->tunnel_port ?? 443;
                        $tls = filter_var($targetServer->tunnel_is_https, FILTER_VALIDATE_BOOLEAN);
                        $p = ['type' => $stream['network'] ?? 'tcp'];
                        if ($tls) { $p['security'] = 'tls'; $p['sni'] = $tunAddr; }
                        else { $p['security'] = 'none'; if ($proto === 'vless') $p['encryption'] = 'none'; }
                        if (($p['type'] ?? '') === 'ws') { $p['path'] = $stream['wsSettings']['path'] ?? '/'; $p['host'] = $stream['wsSettings']['headers']['Host'] ?? $tunAddr; }
                        $remark = ($targetServer->location->flag ?? "🏳️") . "-" . $uniqueUsername;
                        $qs = http_build_query($p);
                        $finalConfig = "vless://{$finalUuid}@{$tunAddr}:{$tunPort}?{$qs}#" . rawurlencode($remark);
                    } else {
                        if (!$finalUuid) throw new \Exception("UUID پیدا نشد");
                        $p = ['type' => $stream['network'] ?? 'tcp', 'security' => $stream['security'] ?? 'none'];
                        if ($p['security'] === 'tls') $p['sni'] = $addr;
                        $qs = http_build_query(array_filter($p));
                        $finalConfig = "vless://{$finalUuid}@{$addr}:{$port}?{$qs}#" . rawurlencode($plan->name);
                    }
                    $success = true;
                }

                if (!$success) throw new \Exception('خطای ناشناخته در فعال‌سازی');

                // Save
                $dataToUpdate = [
                    'config_details' => $finalConfig,
                    'expires_at' => $newExpiresAt,
                    'panel_username' => $uniqueUsername,
                    'panel_client_id' => $finalUuid,
                    'panel_sub_id' => $finalSubId,
                    'plan_id' => $plan->id,
                ];
                if ($isRenewal) {
                    $originalOrder->update($dataToUpdate);
                } else {
                    $order->update($dataToUpdate);
                }
                $order->update(['status' => 'paid', 'payment_method' => $order->payment_method ?: 'card']);
                $desc = ($isRenewal ? "تمدید سرویس" : "خرید سرویس") . " {$plan->name} (تایید تلگرامی)";
                Transaction::create(['user_id' => $user->id, 'order_id' => $order->id, 'amount' => $plan->price, 'type' => 'purchase', 'status' => 'completed', 'description' => $desc]);

                $notifyServiceActivation = true;

                // Edit admin notification message
                $this->editAdminNotification($chatId, $messageId, $order, true, null);

                if (class_exists(OrderPaid::class)) {
                    try { OrderPaid::dispatch($order); } catch (\Exception $e) {}
                }
            });

            if ($notifyServiceActivation) {
                $telegramNotified = app(TelegramOrderNotificationService::class)
                    ->sendServiceActivated($order->fresh());

                if (! $telegramNotified) {
                    try {
                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => "⚠️ سفارش #{$orderId} فعال شد، اما پیام جزئیات سرویس برای کاربر ارسال نشد. شناسه چت کاربر را بررسی کنید.",
                        ]);
                    } catch (\Exception $exception) {
                        Log::warning('Could not warn admin about failed service notification.', [
                            'order_id' => $orderId,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Admin approve from Telegram failed: ' . $e->getMessage(), ['order_id' => $orderId]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ خطا در تأیید سفارش " . $this->escape("#{$orderId}") . ": " . $this->escape($e->getMessage()),
                'parse_mode' => 'MarkdownV2',
            ]);
        }
    }

    /**
     * Handle admin "Reject" button — prompt for reason.
     */
    protected function handleAdminRejectCallback($callbackQuery, string $data, string $chatId, int $messageId): void
    {
        $orderId = (int) Str::after($data, 'admin_reject_');
        $order = Order::find($orderId);

        if (!$order) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '❌ سفارش یافت نشد.',
                'show_alert' => true,
            ]);
            return;
        }

        if (!in_array($order->status, ['pending', 'paid'])) {
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => '⚠️ این سفارش قبلاً پردازش شده است: ' . $order->status,
                'show_alert' => true,
            ]);
            return;
        }

        try {
            Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
        } catch (\Exception $e) {}

        // Store orderId + messageId temporarily so the admin can type the reason
        // We'll use a simple cache for this since admins may not have User records
        \Illuminate\Support\Facades\Cache::put(
            "admin_reject_order_{$chatId}",
            ['order_id' => $orderId, 'message_id' => $messageId],
            now()->addMinutes(10)
        );

        $keyboard = Keyboard::make()->inline()->row([
            Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => 'admin_reject_cancel']),
        ]);

        $orderType = $order->plan_id ? ($order->renews_order_id ? 'تمدید سرویس' : 'خرید سرویس') : 'شارژ کیف پول';
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "📝 *دلیل رد فیش*\n\nسفارش " . $this->escape("#{$orderId}") . "\nنوع: " . $this->escape($orderType) . "\n\n" . $this->escape("لطفاً دلیل رد فیش را وارد کنید (این پیام برای کاربر ارسال خواهد شد):"),
            'parse_mode' => 'MarkdownV2',
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Handle admin rejection reason confirmation (callback from inline button).
     */
    protected function handleAdminRejectConfirmCallback($callbackQuery, string $data, string $chatId, int $messageId): void
    {
        // This is triggered by a confirm button after reason is entered.
        // Not currently used — reason is submitted as text.
        Telegram::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text' => 'لطفاً دلیل را به صورت متن ارسال کنید.',
            'show_alert' => true,
        ]);
    }

    /**
     * Process admin rejection reason text input.
     */
    protected function processAdminRejectionReason($user, int $orderId, string $reason): void
    {
        $chatId = $user->telegram_chat_id;
        $reason = trim($reason);

        if (mb_strlen($reason) < 2) {
            $user->update(['bot_state' => 'awaiting_admin_rejection_reason|' . $orderId]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->escape("❌ دلیل باید حداقل ۲ حرف باشد. لطفاً دوباره وارد کنید:"),
                'parse_mode' => 'MarkdownV2',
            ]);
            return;
        }

        $user->update(['bot_state' => null]);
        $this->executeRejection($orderId, $chatId, $reason);
    }

    /**
     * Execute the rejection: disable VPN account, mark order, notify user.
     */
    protected function executeRejection(int $orderId, string $executorChatId, string $reason): void
    {
        $order = Order::with('user')->find($orderId);
        if (!$order) {
            Telegram::sendMessage(['chat_id' => $executorChatId, 'text' => '❌ سفارش یافت نشد.']);
            return;
        }

        if (!in_array($order->status, ['pending', 'paid'])) {
            Telegram::sendMessage(['chat_id' => $executorChatId, 'text' => '⚠️ سفارش قبلاً پردازش شده.']);
            return;
        }

        $settings = Setting::all()->pluck('value', 'key');

        // If order was paid, disable the VPN account
        if ($order->status === 'paid' && $order->plan_id && $order->panel_username) {
            try {
                $this->disableVpnAccountStatic($order, $settings);
            } catch (\Exception $e) {
                Log::error('Failed to disable VPN on admin reject: ' . $e->getMessage(), ['order_id' => $orderId]);
            }
        }

        // Mark as rejected
        $order->update(['status' => 'rejected']);

        // Notify the user and include the administrator's rejection reason.
        $userNotified = app(TelegramOrderNotificationService::class)
            ->sendPaymentRejected($order->fresh(), $reason);

        // Confirm to the admin and report whether Telegram delivery succeeded.
        $deliveryStatus = $userNotified
            ? 'پیام رد پرداخت برای کاربر ارسال شد.'
            : 'سفارش رد شد، اما پیام تلگرام به کاربر ارسال نشد. شناسه چت و توکن ربات را بررسی کنید.';

        Telegram::sendMessage([
            'chat_id' => $executorChatId,
            'text' => "✅ سفارش شماره {$orderId} رد شد.\n{$deliveryStatus}\n\n📝 دلیل: {$reason}",
        ]);

        // Edit the original admin notification if we have the message_id in cache
        $cached = \Illuminate\Support\Facades\Cache::get("admin_reject_order_{$executorChatId}");
        if ($cached && isset($cached['message_id'])) {
            $this->editAdminNotification($executorChatId, $cached['message_id'], $order, false, $reason);
            \Illuminate\Support\Facades\Cache::forget("admin_reject_order_{$executorChatId}");
        }
    }

    /**
     * Disable a VPN account (static version for use within this controller).
     */
    protected function disableVpnAccountStatic(Order $order, $settings): void
    {
        $panelType = $settings->get('panel_type');
        $targetServer = null;
        $targetServerId = $order->server_id;
        if ($order->renews_order_id && !$targetServerId) {
            $originalOrder = Order::find($order->renews_order_id);
            if ($originalOrder) $targetServerId = $originalOrder->server_id;
        }
        if ($targetServerId && class_exists('Modules\\MultiServer\\Models\\Server')) {
            $targetServer = \Modules\MultiServer\Models\Server::find($targetServerId);
        }
        if ($targetServer && $targetServer->is_active) {
            $panelType = strtolower($targetServer->type ?? 'xui');
            if ($panelType === 'sanaei') $panelType = 'xui';
        }

        $username = $order->panel_username;
        if (empty($username)) {
            Log::warning('Cannot disable VPN: no panel_username', ['order_id' => $order->id]);
            return;
        }

        if ($panelType === 'pasarguard') {
            $ph = $targetServer ? $targetServer->full_host : $settings->get('pasarguard_host');
            $pu = $targetServer ? $targetServer->username : $settings->get('pasarguard_username');
            $pp = $targetServer ? $targetServer->password : $settings->get('pasarguard_password');
            $pn = $targetServer ? ($targetServer->pasarguard_node_hostname ?? $ph) : $settings->get('pasarguard_node_hostname');
            $pasarguard = new PasarGuardService((string)$ph, (string)$pu, (string)$pp, (string)$pn);
            $pasarguard->disableUser($username);
            Log::info('PasarGuard user disabled (TG reject)', ['username' => $username]);
        } elseif ($panelType === 'marzban') {
            $mh = $targetServer ? $targetServer->full_host : $settings->get('marzban_host');
            $mu = $targetServer ? $targetServer->username : $settings->get('marzban_sudo_username');
            $mp = $targetServer ? $targetServer->password : $settings->get('marzban_sudo_password');
            $mn = $targetServer ? ($targetServer->marzban_node_hostname ?? $mh) : $settings->get('marzban_node_hostname');
            $marzban = new MarzbanService((string)$mh, (string)$mu, (string)$mp, (string)$mn);
            $marzban->disableUser($username);
            Log::info('Marzban user disabled (TG reject)', ['username' => $username]);
        } elseif ($panelType === 'xui') {
            $xHost = $targetServer ? $targetServer->full_host : $settings->get('xui_host');
            $xUser = $targetServer ? $targetServer->username : $settings->get('xui_user');
            $xPass = $targetServer ? $targetServer->password : $settings->get('xui_pass');
            $xInbound = $targetServer ? $targetServer->inbound_id : (int)$settings->get('xui_default_inbound_id');
            $xui = new XUIService($xHost, $xUser, $xPass);
            if (!$xui->login()) {
                Log::error('XUI login failed during TG reject');
                return;
            }
            $inboundData = null;
            if ($targetServer) {
                foreach ($xui->getInbounds() as $i) if ($i['id'] == $xInbound) { $inboundData = $i; break; }
            } else {
                $im = Inbound::whereJsonContains('inbound_data->id', (int)$xInbound)->first();
                if ($im) $inboundData = is_string($im->inbound_data) ? json_decode($im->inbound_data, true) : $im->inbound_data;
            }
            if (!$inboundData) return;
            $clients = $xui->getClients($inboundData['id']);
            $client = collect($clients)->first(fn($c) => strtolower(trim($c['email'] ?? '')) === strtolower(trim($username)));
            if ($client) {
                $xui->disableClient($inboundData['id'], $username, 0, $client['id'] ?? '', $client['subId'] ?? Str::random(16));
                if ($targetServer) $targetServer->decrement('current_users');
                Log::info('XUI client disabled (TG reject)', ['email' => $username]);
            }
        }
    }

    /**
     * Edit the original admin notification caption to reflect approval/rejection.
     */
    protected function editAdminNotification(string $chatId, int $messageId, Order $order, bool $approved, ?string $reason): void
    {
        try {
            $order->refresh();
            $orderType = $order->renews_order_id ? 'تمدید سرویس' : ($order->plan_id ? 'خرید سرویس' : 'شارژ کیف پول');
            $user = $order->user;
            $statusEmoji = $approved ? '✅' : '❌';
            $statusText = $approved ? 'تایید شد' : 'رد شد';

            $caption = "🧾 *رسید سفارش " . $this->escape("#{$order->id}") . "*\n\n";
            $caption .= "*کاربر:* " . $this->escape($user?->name ?? 'نامشخص') . " \\(ID: `{$order->user_id}`\\)\n";
            $caption .= "*مبلغ:* " . $this->escape(number_format($order->amount) . ' تومان') . "\n";
            $caption .= "*نوع:* " . $this->escape($orderType) . "\n";
            $caption .= "*وضعیت نهایی:* {$statusEmoji} *{$this->escape($statusText)}*\n";
            if (!$approved && $reason) {
                $caption .= "*دلیل رد:* " . $this->escape($reason) . "\n";
            }
            $caption .= "\n_پردازش شده از طریق ربات تلگرام_";

            Telegram::editMessageCaption([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'caption' => $caption,
                'parse_mode' => 'MarkdownV2',
            ]);
        } catch (\Exception $e) {
            // Message may have been deleted or caption unchanged — fine
            Log::info('Could not edit admin notification caption: ' . $e->getMessage());
        }
    }

    protected function sendOrEditMessage(int $chatId, string $text, $keyboard, ?int $messageId = null)
    {
        $payload = [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'MarkdownV2',
            'reply_markup' => $keyboard
        ];

        $sendAttempted = false;

        try {
            if ($messageId) {
                $payload['message_id'] = $messageId;
                Telegram::editMessageText($payload);
            } else {
                $sendAttempted = true;
                Telegram::sendMessage($payload);
            }
            return;
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            if (Str::contains($e->getMessage(), ['message is not modified'])) {
                Log::info("Message not modified.", ['chat_id' => $chatId]);
                return;
            }
            Log::warning("Telegram API error while editing/sending message: " . $e->getMessage(), ['chat_id' => $chatId, 'message_id' => $messageId]);
        }
        catch (\Exception $e) {
            Log::error("General error during send/edit message: " . $e->getMessage(), ['chat_id' => $chatId, 'trace' => $e->getTraceAsString()]);
        }

        // Fallback 1: if editing failed, send the message as a new message (still MarkdownV2)
        if (!$sendAttempted) {
            unset($payload['message_id']);
            try {
                Telegram::sendMessage($payload);
                return;
            } catch (\Exception $e) {
                Log::warning("Failed to send fallback MarkdownV2 message: " . $e->getMessage(), ['chat_id' => $chatId]);
            }
        }

        // Fallback 2: send as plain text (no parse_mode) so the user always sees the message
        // (e.g. when the MarkdownV2 text contains unescaped reserved characters)
        unset($payload['parse_mode']);
        try {
            Telegram::sendMessage($payload);
        } catch (\Exception $e) {
            Log::error("Failed to send plain-text fallback message: " . $e->getMessage(), ['chat_id' => $chatId]);
        }
    }

    protected function escape(string $text): string
    {
        $chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        $text = str_replace('\\', '\\\\', $text);
        return str_replace($chars, array_map(fn($char) => '\\' . $char, $chars), $text);
    }

    /**
     * Return whether the trial-account feature is enabled in the admin panel.
     *
     * `trial_enabled` is the source of truth for the feature. The legacy
     * `test_account_enabled` key is accepted as a fallback for installations
     * that saved the old setting name.
     */
    protected function isTrialEnabled(): bool
    {
        $value = Setting::normalizeValue($this->settings->get('trial_enabled'));

        if ($value === null) {
            $value = Setting::normalizeValue($this->settings->get('test_account_enabled'));
        }

        return filter_var($value ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Decide whether the trial button belongs in a bot menu.
     *
     * A visible button must never advertise a disabled feature. The optional
     * `tg_show_trial_button` setting remains an explicit presentation override;
     * when it has not been saved yet, it defaults to visible.
     */
    protected function shouldShowTrialButton(): bool
    {
        if (!$this->isTrialEnabled()) {
            return false;
        }

        $visibility = Setting::normalizeValue($this->settings->get('tg_show_trial_button'));

        return $visibility === null
            || $visibility === ''
            || filter_var($visibility, FILTER_VALIDATE_BOOLEAN);
    }

    protected function getMainMenuKeyboard(): Keyboard
    {
        $showTrial = $this->shouldShowTrialButton();
        $showReferral = filter_var($this->settings->get('referral_enabled', '1'), FILTER_VALIDATE_BOOLEAN);

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '🛒 خرید سرویس', 'callback_data' => '/plans']),
                Keyboard::inlineButton(['text' => '🛠 سرویس‌های من', 'callback_data' => '/my_services']),
            ]);

        if ($showReferral) {
            $keyboard->row([
                Keyboard::inlineButton(['text' => '💰 کیف پول', 'callback_data' => '/wallet']),
                Keyboard::inlineButton(['text' => '🎁 دعوت از دوستان', 'callback_data' => '/referral']),
            ]);
        } else {
            $keyboard->row([
                Keyboard::inlineButton(['text' => '💰 کیف پول', 'callback_data' => '/wallet']),
            ]);
        }
        $keyboard->row([
            Keyboard::inlineButton(['text' => '📥 ورود اشتراک قبلی به ربات', 'callback_data' => '/import_subscription']),
            Keyboard::inlineButton(['text' => '📚 راهنمای اتصال', 'callback_data' => '/tutorials']),
        ]);

        $keyboard->row([
            Keyboard::inlineButton(['text' => '❓ سوالات متداول', 'callback_data' => '/faq']),
            Keyboard::inlineButton(['text' => '💬 پشتیبانی', 'callback_data' => '/support_menu']),
        ]);

        if ($showTrial) {
            $keyboard->row([
                Keyboard::inlineButton(['text' => '🧪 اکانت تست', 'callback_data' => 'trial_request']),
            ]);
        }

        return $keyboard;
    }

    protected function sendOrEditMainMenu($chatId, $text, $messageId = null)
    {
        $this->sendOrEditMessage($chatId, $this->escape($text), $this->getMainMenuKeyboard(), $messageId);
    }

    protected function getReplyMainMenu($chatId = null): Keyboard
    {
        // Mini App feature removed
        $showReseller = filter_var($this->settings->get('tg_show_reseller_button', '1'), FILTER_VALIDATE_BOOLEAN);
        $showTrial = $this->shouldShowTrialButton();
        $showReferral = filter_var($this->settings->get('referral_enabled', '1'), FILTER_VALIDATE_BOOLEAN);

        $keyboard = [
            ['🛒 خرید سرویس', '🛠 سرویس‌های من'],
            ['💰 کیف پول', '📜 تاریخچه تراکنش‌ها'],
        ];

        if ($showReferral) {
            $keyboard[] = ['💬 پشتیبانی', '🎁 دعوت از دوستان'];
        } else {
            $keyboard[] = ['💬 پشتیبانی'];
        }

        $keyboard[] = ['📚 راهنمای اتصال', '❓ سوالات متداول'];

        if ($showTrial) {
            $keyboard[] = ['🧪 اکانت تست'];
        }

        $keyboard[] = ['📥 ورود اشتراک قبلی به ربات'];

        if ($showReseller) {
            $keyboard[] = ['🏢 نمایندگی'];
        }

        return Keyboard::make([
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ]);
    }
}
