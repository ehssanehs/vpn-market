<?php

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\TelegramOrderNotificationService;
use Telegram\Bot\Laravel\Facades\Telegram;

function createTelegramNotificationPlan(array $attributes = []): Plan
{
    return Plan::create(array_merge([
        'name' => 'Premium <Plan>',
        'price' => 120000,
        'features' => ['Fast connection'],
        'is_popular' => false,
        'is_active' => true,
        'server_type' => 'all',
        'volume_gb' => 50,
        'duration_days' => 30,
    ], $attributes));
}

function mockTelegramOrderMessage(array &$payloads): void
{
    Telegram::shouldReceive('setAccessToken')
        ->once()
        ->with('test-bot-token');

    Telegram::shouldReceive('sendMessage')
        ->once()
        ->with(Mockery::on(function (array $payload) use (&$payloads): bool {
            $payloads[] = $payload;

            return true;
        }));
}

beforeEach(function () {
    Setting::create([
        'key' => 'telegram_bot_token',
        'value' => 'test-bot-token',
    ]);
});

it('sends new connection details after a service is activated', function () {
    $user = User::factory()->create(['telegram_chat_id' => '123456789']);
    $plan = createTelegramNotificationPlan();
    $config = 'vless://aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa@example.com:443?security=tls&type=ws#Premium_Config';

    $order = $user->orders()->create([
        'plan_id' => $plan->id,
        'status' => 'paid',
        'source' => 'web',
        'amount' => $plan->price,
        'payment_method' => 'card',
        'config_details' => $config,
        'panel_username' => 'customer_10',
        'expires_at' => now()->addDays(30),
    ]);

    $payloads = [];
    mockTelegramOrderMessage($payloads);

    $sent = app(TelegramOrderNotificationService::class)
        ->sendServiceActivated($order);

    expect($sent)->toBeTrue()
        ->and($payloads)->toHaveCount(1)
        ->and($payloads[0]['chat_id'])->toBe('123456789')
        ->and($payloads[0]['parse_mode'])->toBe('HTML')
        ->and($payloads[0]['text'])->toContain('خرید شما تایید')
        ->and($payloads[0]['text'])->toContain('Premium &lt;Plan&gt;')
        ->and($payloads[0]['text'])->toContain('customer_10')
        ->and($payloads[0]['text'])->toContain('security=tls&amp;type=ws')
        ->and(json_encode($payloads[0]['reply_markup']->toArray()))->toContain("copy_link_{$order->id}");
});

it('uses the original service configuration when a renewal is accepted', function () {
    $user = User::factory()->create(['telegram_chat_id' => '987654321']);
    $plan = createTelegramNotificationPlan(['name' => 'Renewal Plan']);
    $config = 'https://vpn.example.test/sub/original-connection';

    $originalOrder = $user->orders()->create([
        'plan_id' => $plan->id,
        'status' => 'paid',
        'source' => 'web',
        'amount' => $plan->price,
        'payment_method' => 'card',
        'config_details' => $config,
        'panel_username' => 'renew_user',
        'expires_at' => now()->addDays(60),
    ]);

    $renewalOrder = $user->orders()->create([
        'plan_id' => $plan->id,
        'status' => 'paid',
        'source' => 'web',
        'amount' => $plan->price,
        'payment_method' => 'card',
        'renews_order_id' => $originalOrder->id,
    ]);

    $payloads = [];
    mockTelegramOrderMessage($payloads);

    $sent = app(TelegramOrderNotificationService::class)
        ->sendServiceActivated($renewalOrder);

    expect($sent)->toBeTrue()
        ->and($payloads[0]['text'])->toContain('تمدید سرویس')
        ->and($payloads[0]['text'])->toContain($config)
        ->and($payloads[0]['text'])->toContain('renew_user')
        ->and(json_encode($payloads[0]['reply_markup']->toArray()))->toContain("copy_link_{$originalOrder->id}");
});

it('shows the renewed plan volume and details when renewing with a different plan', function () {
    $user = User::factory()->create(['telegram_chat_id' => '987654321']);
    $initialPlan = createTelegramNotificationPlan([
        'name' => '10GB Starter Plan',
        'volume_gb' => 10,
        'duration_days' => 30,
        'price' => 50000,
    ]);
    $renewedPlan = createTelegramNotificationPlan([
        'name' => '50GB Pro Plan',
        'volume_gb' => 50,
        'duration_days' => 60,
        'price' => 150000,
    ]);
    $config = 'https://vpn.example.test/sub/user-connection';

    $originalOrder = $user->orders()->create([
        'plan_id' => $initialPlan->id,
        'status' => 'paid',
        'source' => 'web',
        'amount' => $initialPlan->price,
        'payment_method' => 'card',
        'config_details' => $config,
        'panel_username' => 'test_user_volume',
        'expires_at' => now()->addDays(60),
    ]);

    $renewalOrder = $user->orders()->create([
        'plan_id' => $renewedPlan->id,
        'status' => 'paid',
        'source' => 'telegram_renewal',
        'amount' => $renewedPlan->price,
        'payment_method' => 'wallet',
        'renews_order_id' => $originalOrder->id,
    ]);

    $payloads = [];
    mockTelegramOrderMessage($payloads);

    $sent = app(TelegramOrderNotificationService::class)
        ->sendServiceActivated($renewalOrder);

    expect($sent)->toBeTrue()
        ->and($payloads[0]['text'])->toContain('تمدید سرویس')
        ->and($payloads[0]['text'])->toContain('50GB Pro Plan')
        ->and($payloads[0]['text'])->toContain('50 گیگابایت')
        ->and($payloads[0]['text'])->toContain('60 روز')
        ->and($payloads[0]['text'])->not->toContain('10GB Starter Plan')
        ->and($payloads[0]['text'])->not->toContain('10 گیگابایت');
});

it('sends the admin rejection reason to the user safely', function () {
    $user = User::factory()->create(['telegram_chat_id' => '1122334455']);
    $plan = createTelegramNotificationPlan(['name' => 'Monthly & Fast']);

    $order = $user->orders()->create([
        'plan_id' => $plan->id,
        'status' => 'rejected',
        'source' => 'telegram',
        'amount' => $plan->price,
        'payment_method' => 'card',
    ]);

    $payloads = [];
    mockTelegramOrderMessage($payloads);

    $sent = app(TelegramOrderNotificationService::class)
        ->sendPaymentRejected($order, 'مبلغ <کمتر> است & رسید ناخوانا است.');

    expect($sent)->toBeTrue()
        ->and($payloads[0]['parse_mode'])->toBe('HTML')
        ->and($payloads[0]['text'])->toContain('رسید پرداخت شما تایید نشد')
        ->and($payloads[0]['text'])->toContain('خرید سرویس')
        ->and($payloads[0]['text'])->toContain('Monthly &amp; Fast')
        ->and($payloads[0]['text'])->toContain('مبلغ &lt;کمتر&gt; است &amp; رسید ناخوانا است.')
        ->and(json_encode($payloads[0]['reply_markup']->toArray()))->toContain('/support_new');
});
