<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversation Model
 * 
 * Tracks conversation state for each WhatsApp user.
 * Maintains context and order flow across multiple messages.
 * 
 * @property int $id
 * @property string $user_phone
 * @property string|null $profile_name
 * @property string $current_state
 * @property int|null $active_order_id
 * @property array|null $context_data
 * @property int $message_count
 * @property int $order_count
 * @property \Carbon\Carbon|null $session_started_at
 * @property \Carbon\Carbon|null $session_expires_at
 * @property bool $is_active
 * @property string|null $first_message_source
 * @property string $language_preference
 * @property \Carbon\Carbon|null $last_message_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Conversation extends Model
{
    use HasFactory;

    /**
     * Conversation state constants
     */
    public const STATE_IDLE = 'idle';
    public const STATE_GREETING = 'greeting';
    public const STATE_PRODUCT_INQUIRY = 'product_inquiry';
    public const STATE_COLLECTING_INFO = 'collecting_info';
    public const STATE_AWAITING_CONFIRM = 'awaiting_confirm';
    public const STATE_ORDER_CONFIRMED = 'order_confirmed';
    public const STATE_SUPPORT = 'support';
    public const STATE_COMPLAINT = 'complaint';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_phone',
        'profile_name',
        'current_state',
        'active_order_id',
        'context_data',
        'message_count',
        'order_count',
        'session_started_at',
        'session_expires_at',
        'is_active',
        'first_message_source',
        'language_preference',
        'last_message_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'context_data' => 'array',
        'message_count' => 'integer',
        'order_count' => 'integer',
        'session_started_at' => 'datetime',
        'session_expires_at' => 'datetime',
        'is_active' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    /**
     * Session duration in minutes.
     */
    protected const SESSION_DURATION_MINUTES = 30;

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($conversation) {
            if (empty($conversation->current_state)) {
                $conversation->current_state = self::STATE_IDLE;
            }
            if (empty($conversation->context_data)) {
                $conversation->context_data = [];
            }
            if (empty($conversation->session_started_at)) {
                $conversation->session_started_at = now();
            }
            if (empty($conversation->session_expires_at)) {
                $conversation->session_expires_at = now()->addMinutes(self::SESSION_DURATION_MINUTES);
            }
        });
    }

    /**
     * Get the active order for this conversation.
     */
    public function activeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'active_order_id');
    }

    /**
     * Get all orders for this user.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'phone', 'user_phone');
    }

    /**
     * Get messages for this conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'user_phone', 'user_phone');
    }

    /**
     * Scope: Get active conversations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Get conversations by state.
     */
    public function scopeByState($query, $state)
    {
        return $query->where('current_state', $state);
    }

    /**
     * Scope: Get conversations with active orders.
     */
    public function scopeWithActiveOrder($query)
    {
        return $query->whereNotNull('active_order_id');
    }

    /**
     * Scope: Get expired sessions.
     */
    public function scopeExpired($query)
    {
        return $query->where('session_expires_at', '<', now());
    }

    /**
     * Scope: Get recent conversations.
     */
    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('last_message_at', '>=', now()->subHours($hours));
    }

    /**
     * Check if session is expired.
     */
    public function isSessionExpired(): bool
    {
        return $this->session_expires_at && $this->session_expires_at->isPast();
    }

    /**
     * Renew the session.
     */
    public function renewSession(): void
    {
        $this->update([
            'session_expires_at' => now()->addMinutes(self::SESSION_DURATION_MINUTES),
            'is_active' => true,
        ]);
    }

    /**
     * End the session.
     */
    public function endSession(): void
    {
        $this->update([
            'is_active' => false,
            'current_state' => self::STATE_IDLE,
            'active_order_id' => null,
        ]);
    }

    /**
     * Update conversation state.
     */
    public function transitionTo(string $newState, array $context = []): void
    {
        $update = [
            'current_state' => $newState,
            'last_message_at' => now(),
        ];

        if (!empty($context)) {
            $currentContext = $this->context_data ?? [];
            $update['context_data'] = array_merge($currentContext, $context);
        }

        $this->update($update);
    }

    /**
     * Increment message count.
     */
    public function incrementMessageCount(): void
    {
        $this->increment('message_count');
        $this->update(['last_message_at' => now()]);
    }

    /**
     * Increment order count.
     */
    public function incrementOrderCount(): void
    {
        $this->increment('order_count');
    }

    /**
     * Set active order.
     */
    public function setActiveOrder(int $orderId): void
    {
        $this->update([
            'active_order_id' => $orderId,
        ]);
    }

    /**
     * Clear active order.
     */
    public function clearActiveOrder(): void
    {
        $this->update([
            'active_order_id' => null,
        ]);
    }

    /**
     * Get context data value.
     */
    public function getContext(string $key, $default = null)
    {
        return data_get($this->context_data, $key, $default);
    }

    /**
     * Set context data value.
     */
    public function setContext(string $key, $value): void
    {
        $context = $this->context_data ?? [];
        $context[$key] = $value;
        $this->update(['context_data' => $context]);
    }

    /**
     * Check if in ordering flow.
     */
    public function isInOrderFlow(): bool
    {
        return in_array($this->current_state, [
            self::STATE_PRODUCT_INQUIRY,
            self::STATE_COLLECTING_INFO,
            self::STATE_AWAITING_CONFIRM,
        ]);
    }

    /**
     * Check if has active order.
     */
    public function hasActiveOrder(): bool
    {
        return $this->active_order_id !== null;
    }

    /**
     * Get state label in Arabic.
     */
    public function getStateLabel(): string
    {
        $labels = [
            self::STATE_IDLE => 'في الانتظار',
            self::STATE_GREETING => 'الترحيب',
            self::STATE_PRODUCT_INQUIRY => 'استفسار عن منتج',
            self::STATE_COLLECTING_INFO => 'جمع المعلومات',
            self::STATE_AWAITING_CONFIRM => 'انتظار التأكيد',
            self::STATE_ORDER_CONFIRMED => 'الطلب مؤكد',
            self::STATE_SUPPORT => 'دعم فني',
            self::STATE_COMPLAINT => 'شكوى',
        ];

        return $labels[$this->current_state] ?? $this->current_state;
    }

    /**
     * Find or create conversation by phone.
     */
    public static function findOrCreateByPhone(string $phone, string $profileName = null): self
    {
        $conversation = self::where('user_phone', $phone)->first();

        if (!$conversation) {
            $conversation = self::create([
                'user_phone' => $phone,
                'profile_name' => $profileName,
                'current_state' => self::STATE_IDLE,
                'is_active' => true,
            ]);
        }

        return $conversation;
    }

    /**
     * Reset conversation to idle state.
     */
    public function reset(): void
    {
        $this->update([
            'current_state' => self::STATE_IDLE,
            'active_order_id' => null,
            'context_data' => [],
        ]);
    }

    /**
     * Get recent messages for context.
     */
    public function getRecentMessages(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $this->messages()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get last interaction time in human-readable format.
     */
    public function getLastInteractionForHumans(): string
    {
        if (!$this->last_message_at) {
            return 'لم يتم التفاعل بعد';
        }

        return $this->last_message_at->diffForHumans();
    }
}
