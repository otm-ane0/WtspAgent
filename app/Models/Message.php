<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message Model
 * 
 * Represents all incoming and outgoing WhatsApp messages.
 * Tracks message processing and AI responses.
 * 
 * @property int $id
 * @property string $message_id
 * @property string $user_phone
 * @property string|null $whatsapp_profile_name
 * @property string $message_type
 * @property string|null $content
 * @property array|null $raw_payload
 * @property string|null $media_url
 * @property string|null $media_mime_type
 * @property string|null $media_caption
 * @property int|null $media_size
 * @property string|null $processed_content
 * @property string $processing_status
 * @property string|null $ai_response
 * @property array|null $ai_metadata
 * @property float|null $ai_confidence
 * @property int|null $order_id
 * @property \Carbon\Carbon|null $sent_at
 * @property \Carbon\Carbon|null $received_at
 * @property \Carbon\Carbon|null $processed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Message extends Model
{
    use HasFactory;

    /**
     * Message type constants
     */
    public const TYPE_TEXT = 'text';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_LOCATION = 'location';
    public const TYPE_CONTACT = 'contact';
    public const TYPE_BUTTON = 'button';
    public const TYPE_INTERACTIVE = 'interactive';
    public const TYPE_TEMPLATE = 'template';
    public const TYPE_UNKNOWN = 'unknown';

    /**
     * Processing status constants
     */
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'message_id',
        'user_phone',
        'whatsapp_profile_name',
        'message_type',
        'content',
        'raw_payload',
        'media_url',
        'media_mime_type',
        'media_caption',
        'media_size',
        'processed_content',
        'processing_status',
        'ai_response',
        'ai_metadata',
        'ai_confidence',
        'order_id',
        'sent_at',
        'received_at',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'raw_payload' => 'array',
        'ai_metadata' => 'array',
        'ai_confidence' => 'float',
        'media_size' => 'integer',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($message) {
            if (empty($message->processing_status)) {
                $message->processing_status = self::STATUS_RECEIVED;
            }
            if (empty($message->received_at)) {
                $message->received_at = now();
            }
        });
    }

    /**
     * Get the order associated with this message.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get AI interactions associated with this message.
     */
    public function aiInteractions()
    {
        return $this->hasMany(AiInteraction::class);
    }

    /**
     * Scope: Get messages by phone number.
     */
    public function scopeByPhone($query, $phone)
    {
        return $query->where('user_phone', $phone);
    }

    /**
     * Scope: Get messages by type.
     */
    public function scopeByType($query, $type)
    {
        return $query->where('message_type', $type);
    }

    /**
     * Scope: Get messages by processing status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('processing_status', $status);
    }

    /**
     * Scope: Get recent messages.
     */
    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope: Get unprocessed messages.
     */
    public function scopeUnprocessed($query)
    {
        return $query->whereIn('processing_status', [
            self::STATUS_RECEIVED,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Check if message is text type.
     */
    public function isText(): bool
    {
        return $this->message_type === self::TYPE_TEXT;
    }

    /**
     * Check if message is audio type.
     */
    public function isAudio(): bool
    {
        return $this->message_type === self::TYPE_AUDIO;
    }

    /**
     * Check if message is image type.
     */
    public function isImage(): bool
    {
        return $this->message_type === self::TYPE_IMAGE;
    }

    /**
     * Check if message contains media.
     */
    public function hasMedia(): bool
    {
        return in_array($this->message_type, [
            self::TYPE_AUDIO,
            self::TYPE_IMAGE,
            self::TYPE_VIDEO,
            self::TYPE_DOCUMENT,
        ]);
    }

    /**
     * Check if message needs AI processing.
     */
    public function needsProcessing(): bool
    {
        return in_array($this->message_type, [
            self::TYPE_TEXT,
            self::TYPE_AUDIO,
            self::TYPE_IMAGE,
        ]);
    }

    /**
     * Mark message as processing.
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'processing_status' => self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Mark message as processed.
     */
    public function markAsProcessed(string $aiResponse = null, array $metadata = null): void
    {
        $update = [
            'processing_status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
        ];

        if ($aiResponse) {
            $update['ai_response'] = $aiResponse;
        }

        if ($metadata) {
            $update['ai_metadata'] = $metadata;
        }

        $this->update($update);
    }

    /**
     * Mark message as failed.
     */
    public function markAsFailed(string $errorMessage = null): void
    {
        $update = [
            'processing_status' => self::STATUS_FAILED,
        ];

        if ($errorMessage) {
            $metadata = $this->ai_metadata ?? [];
            $metadata['error'] = $errorMessage;
            $update['ai_metadata'] = $metadata;
        }

        $this->update($update);
    }

    /**
     * Get display content for AI processing.
     */
    public function getContentForProcessing(): ?string
    {
        if ($this->processed_content) {
            return $this->processed_content;
        }

        if ($this->content) {
            return $this->content;
        }

        if ($this->media_caption) {
            return $this->media_caption;
        }

        return null;
    }

    /**
     * Get message summary for logging.
     */
    public function getSummary(): string
    {
        return sprintf(
            "Message[id=%s, type=%s, phone=%s, status=%s]",
            $this->message_id,
            $this->message_type,
            $this->user_phone,
            $this->processing_status
        );
    }

    /**
     * Get formatted timestamp for display.
     */
    public function getFormattedTime(): string
    {
        return $this->created_at->format('Y-m-d H:i:s');
    }

    /**
     * Get all valid message types.
     */
    public static function getValidTypes(): array
    {
        return [
            self::TYPE_TEXT,
            self::TYPE_AUDIO,
            self::TYPE_IMAGE,
            self::TYPE_VIDEO,
            self::TYPE_DOCUMENT,
            self::TYPE_LOCATION,
            self::TYPE_CONTACT,
            self::TYPE_BUTTON,
            self::TYPE_INTERACTIVE,
            self::TYPE_TEMPLATE,
            self::TYPE_UNKNOWN,
        ];
    }

    /**
     * Check if message type requires media download.
     */
    public function requiresMediaDownload(): bool
    {
        return $this->hasMedia() && empty($this->media_url);
    }
}
