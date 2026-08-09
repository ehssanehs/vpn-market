<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $plan_id
 * @property int|null $server_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $payment_method
 * @property string|null $card_payment_receipt
 * @property string|null $nowpayments_payment_id
 * @property string|null $config_details
 * @property int $amount
 * @property string|null $source
 * @property string|null $panel_username
 * @property bool $reserved_slot
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * 
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Plan|null $plan
 * @property-read \Modules\MultiServer\Models\Server|null $server
 */
class Order extends Model
{
    protected $casts = [
        'expires_at' => 'datetime',
        'amount' => 'integer',
        'reserved_slot' => 'boolean',
        'is_imported' => 'boolean',
        'import_meta' => 'array',
    ];

    protected $fillable = [
        'user_id',
        'plan_id',
        'server_id',
        'status',
        'expires_at',
        'payment_method',
        'card_payment_receipt',
        'nowpayments_payment_id',
        'config_details',
        'amount',
        'source',
        'panel_username',
        'panel_client_id',
        'panel_sub_id',
        'reserved_slot',
        'is_imported',
        'import_meta',
        'renews_order_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }


    public function server()
    {

        if (class_exists('Modules\MultiServer\Models\Server')) {
            return $this->belongsTo(\Modules\MultiServer\Models\Server::class, 'server_id');
        }

        return $this->belongsTo(Plan::class, 'plan_id')->whereNull('id');
    }

    public function store(Plan $plan)
    {
        return view('payment.choose', ['plan' => $plan]);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            Log::info('Order is being created', [
                'panel_username' => $order->panel_username,
                'user_id' => $order->user_id
            ]);
        });

        static::saving(function ($order) {
            if (empty($order->server_id) && !empty($order->plan_id) && class_exists('Modules\MultiServer\Models\Server')) {
                $plan = \App\Models\Plan::find($order->plan_id);
                $serverType = $plan ? ($plan->server_type ?? 'all') : 'all';

                $query = \Modules\MultiServer\Models\Server::where('is_active', true)
                    ->whereRaw('current_users < capacity');
                if ($serverType !== 'all') {
                    $query->where('type', $serverType);
                }
                $bestServer = $query->orderBy('current_users', 'asc')->first()
                    ?: \Modules\MultiServer\Models\Server::where('is_active', true)->whereRaw('current_users < capacity')->orderBy('current_users', 'asc')->first()
                    ?: \Modules\MultiServer\Models\Server::where('is_active', true)->first();

                if ($bestServer) {
                    $order->server_id = $bestServer->id;
                }
            }

            if (empty($order->payment_method) && !empty($order->card_payment_receipt)) {
                $order->payment_method = 'card';
            }

            if (empty($order->payment_method) && $order->status === 'paid') {
                $order->payment_method = 'card';
            }
        });
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function renewals()
    {
        return $this->hasMany(Order::class, 'renews_order_id');
    }

    public function renewedOrder()
    {
        return $this->belongsTo(Order::class, 'renews_order_id');
    }
}
