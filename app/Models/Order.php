<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Order Model
 * 
 * Represents customer orders in the system.
 * Handles order lifecycle from creation to delivery.
 * 
 * @property int $id
 * @property string $customer_name
 * @property string $phone
 * @property string $city
 * @property string $address
 * @property string $product
 * @property int $quantity
 * @property float|null $price_per_unit
 * @property float|null $total_price
 * @property string $status
 * @property array|null $collected_data
 * @property array|null $conversation_context
 * @property \Carbon\Carbon|null $last_interaction_at
 * @property string|null $notes
 * @property string $source
 * @property string|null $assigned_to
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class Order extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Order status constants
     */
    public const STATUS_NEW = 'new';
    public const STATUS_COLLECTING_INFO = 'collecting_info';
    public const STATUS_AWAITING_CONFIRM = 'awaiting_confirm';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'customer_name',
        'phone',
        'city',
        'address',
        'product',
        'quantity',
        'price_per_unit',
        'total_price',
        'status',
        'collected_data',
        'conversation_context',
        'last_interaction_at',
        'notes',
        'source',
        'assigned_to',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'collected_data' => 'array',
        'conversation_context' => 'array',
        'quantity' => 'integer',
        'price_per_unit' => 'decimal:2',
        'total_price' => 'decimal:2',
        'last_interaction_at' => 'datetime',
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array<string>
     */
    protected $dates = [
        'last_interaction_at',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->status)) {
                $order->status = self::STATUS_NEW;
            }
            if (empty($order->collected_data)) {
                $order->collected_data = [];
            }
            $order->last_interaction_at = now();
        });

        static::saving(function ($order) {
            // Auto-calculate total price
            if ($order->price_per_unit && $order->quantity) {
                $order->total_price = $order->price_per_unit * $order->quantity;
            }
        });
    }

    /**
     * Get messages associated with this order.
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get AI interactions associated with this order.
     */
    public function aiInteractions()
    {
        return $this->hasMany(AiInteraction::class);
    }

    /**
     * Get the conversation associated with this order.
     */
    public function conversation()
    {
        return $this->hasOne(Conversation::class, 'active_order_id');
    }

    /**
     * Scope: Get orders by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Get pending orders (awaiting confirmation).
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', [
            self::STATUS_NEW,
            self::STATUS_COLLECTING_INFO,
            self::STATUS_AWAITING_CONFIRM,
        ]);
    }

    /**
     * Scope: Get confirmed orders.
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    /**
     * Scope: Get orders by phone number.
     */
    public function scopeByPhone($query, $phone)
    {
        return $query->where('phone', $phone);
    }

    /**
     * Scope: Get recent orders.
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Check if order is confirmed.
     */
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * Check if order can be modified.
     */
    public function canBeModified(): bool
    {
        return in_array($this->status, [
            self::STATUS_NEW,
            self::STATUS_COLLECTING_INFO,
            self::STATUS_AWAITING_CONFIRM,
        ]);
    }

    /**
     * Update collected data field.
     */
    public function updateCollectedData(string $key, $value): void
    {
        $data = $this->collected_data ?? [];
        $data[$key] = $value;
        $this->collected_data = $data;
        $this->save();
    }

    /**
     * Get collected data value.
     */
    public function getCollectedData(string $key, $default = null)
    {
        return data_get($this->collected_data, $key, $default);
    }

    /**
     * Check if all required fields are filled.
     */
    public function hasRequiredFields(): bool
    {
        return !empty($this->customer_name) &&
               !empty($this->phone) &&
               !empty($this->city) &&
               !empty($this->address) &&
               !empty($this->product) &&
               $this->quantity > 0;
    }

    /**
     * Get missing required fields.
     */
    public function getMissingFields(): array
    {
        $missing = [];
        $fields = [
            'customer_name' => 'الاسم الكامل',
            'phone' => 'رقم الهاتف',
            'city' => 'المدينة',
            'address' => 'العنوان',
            'product' => 'المنتج',
            'quantity' => 'الكمية',
        ];

        foreach ($fields as $field => $label) {
            if (empty($this->$field) || ($field === 'quantity' && $this->quantity <= 0)) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * Get order summary in Darija.
     */
    public function getSummaryForDarija(): string
    {
        $summary = "📋 *تفاصيل الطلب*\n\n";
        $summary .= "👤 *الاسم:* {$this->customer_name}\n";
        $summary .= "📞 *الهاتف:* {$this->phone}\n";
        $summary .= "🏙️ *المدينة:* {$this->city}\n";
        $summary .= "📍 *العنوان:* {$this->address}\n";
        $summary .= "📦 *المنتج:* {$this->product}\n";
        $summary .= "🔢 *الكمية:* {$this->quantity}\n";
        
        if ($this->total_price) {
            $summary .= "💰 *الثمن الإجمالي:* {$this->total_price} درهم\n";
        }
        
        $summary .= "📊 *الحالة:* " . $this->getStatusLabelInDarija() . "\n";
        
        return $summary;
    }

    /**
     * Get status label in Darija.
     */
    public function getStatusLabelInDarija(): string
    {
        $labels = [
            self::STATUS_NEW => 'جديد ⏳',
            self::STATUS_COLLECTING_INFO => 'جاري جمع المعلومات 📝',
            self::STATUS_AWAITING_CONFIRM => 'في انتظار التأكيد ⏸️',
            self::STATUS_CONFIRMED => 'مؤكد ✅',
            self::STATUS_PROCESSING => 'قيد المعالجة 🔄',
            self::STATUS_SHIPPED => 'تم الشحن 🚚',
            self::STATUS_DELIVERED => 'تم التوصيل ✓',
            self::STATUS_CANCELLED => 'ملغي ❌',
        ];

        return $labels[$this->status] ?? $this->status;
    }

    /**
     * Confirm the order.
     */
    public function confirm(): bool
    {
        if (!$this->hasRequiredFields()) {
            return false;
        }

        $this->status = self::STATUS_CONFIRMED;
        $this->save();

        return true;
    }

    /**
     * Mark order as cancelled.
     */
    public function cancel(string $reason = null): void
    {
        $this->status = self::STATUS_CANCELLED;
        if ($reason) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . "سبب الإلغاء: {$reason}";
        }
        $this->save();
    }
}
