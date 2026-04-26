<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI Interaction Model
 * 
 * Tracks all AI API calls for monitoring and cost analysis.
 * 
 * @property int $id
 * @property int $message_id
 * @property int|null $order_id
 * @property string $model_name
 * @property string $service_type
 * @property string $input_prompt
 * @property string $output_response
 * @property array|null $system_prompt
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $total_tokens
 * @property float|null $latency_ms
 * @property float|null $cost_usd
 * @property string $status
 * @property string|null $error_message
 * @property int $retry_count
 * @property array|null $metadata
 * @property \Carbon\Carbon $processed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AiInteraction extends Model
{
    use HasFactory;

    /**
     * Service type constants
     */
    public const SERVICE_LLM = 'llm';
    public const SERVICE_STT = 'stt';
    public const SERVICE_VISION = 'vision';

    /**
     * Status constants
     */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_RATE_LIMITED = 'rate_limited';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'message_id',
        'order_id',
        'model_name',
        'service_type',
        'input_prompt',
        'output_response',
        'system_prompt',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'latency_ms',
        'cost_usd',
        'status',
        'error_message',
        'retry_count',
        'metadata',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'system_prompt' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'latency_ms' => 'float',
        'cost_usd' => 'decimal:6',
        'metadata' => 'array',
        'retry_count' => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * The attributes that are not mass assignable.
     *
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($interaction) {
            if (empty($interaction->processed_at)) {
                $interaction->processed_at = now();
            }
            if (empty($interaction->status)) {
                $interaction->status = self::STATUS_SUCCESS;
            }
        });

        static::saving(function ($interaction) {
            // Calculate total tokens
            if ($interaction->input_tokens && $interaction->output_tokens) {
                $interaction->total_tokens = $interaction->input_tokens + $interaction->output_tokens;
            }
        });
    }

    /**
     * Get the message associated with this interaction.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get the order associated with this interaction.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Scope: Get interactions by service type.
     */
    public function scopeByService($query, $service)
    {
        return $query->where('service_type', $service);
    }

    /**
     * Scope: Get interactions by model.
     */
    public function scopeByModel($query, $model)
    {
        return $query->where('model_name', $model);
    }

    /**
     * Scope: Get successful interactions.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * Scope: Get failed interactions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', '!=', self::STATUS_SUCCESS);
    }

    /**
     * Scope: Get interactions for a specific time period.
     */
    public function scopeInPeriod($query, $startDate, $endDate = null)
    {
        $query->where('created_at', '>=', $startDate);
        
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }
        
        return $query;
    }

    /**
     * Scope: Get recent interactions.
     */
    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Check if interaction was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if interaction is LLM service.
     */
    public function isLlm(): bool
    {
        return $this->service_type === self::SERVICE_LLM;
    }

    /**
     * Check if interaction is Speech-to-Text service.
     */
    public function isStt(): bool
    {
        return $this->service_type === self::SERVICE_STT;
    }

    /**
     * Check if interaction is Vision service.
     */
    public function isVision(): bool
    {
        return $this->service_type === self::SERVICE_VISION;
    }

    /**
     * Get formatted latency.
     */
    public function getFormattedLatency(): string
    {
        if (!$this->latency_ms) {
            return 'N/A';
        }

        if ($this->latency_ms < 1000) {
            return round($this->latency_ms, 2) . ' ms';
        }

        return round($this->latency_ms / 1000, 2) . ' s';
    }

    /**
     * Get formatted cost.
     */
    public function getFormattedCost(): string
    {
        if (!$this->cost_usd) {
            return '$0.000000';
        }

        return '$' . number_format($this->cost_usd, 6);
    }

    /**
     * Get service type label.
     */
    public function getServiceLabel(): string
    {
        $labels = [
            self::SERVICE_LLM => 'Language Model',
            self::SERVICE_STT => 'Speech to Text',
            self::SERVICE_VISION => 'Computer Vision',
        ];

        return $labels[$this->service_type] ?? $this->service_type;
    }

    /**
     * Get status label.
     */
    public function getStatusLabel(): string
    {
        $labels = [
            self::STATUS_SUCCESS => 'Success ✅',
            self::STATUS_ERROR => 'Error ❌',
            self::STATUS_TIMEOUT => 'Timeout ⏱️',
            self::STATUS_RATE_LIMITED => 'Rate Limited ⏳',
        ];

        return $labels[$this->status] ?? $this->status;
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Increment retry count.
     */
    public function incrementRetry(): void
    {
        $this->increment('retry_count');
    }

    /**
     * Get summary for logging.
     */
    public function getSummary(): string
    {
        return sprintf(
            "AI Interaction[id=%d, service=%s, model=%s, tokens=%d, status=%s]",
            $this->id,
            $this->service_type,
            $this->model_name,
            $this->total_tokens ?? 0,
            $this->status
        );
    }

    /**
     * Calculate cost based on token usage.
     * This is a placeholder - actual rates depend on the model.
     */
    public function calculateCost(): float
    {
        // NVIDIA API pricing (example rates - update with actual rates)
        $rates = [
            'meta/llama-3.1-405b-instruct' => [
                'input' => 0.000001,  // $ per token
                'output' => 0.000002,
            ],
            'nvidia/parakeet-rnnt-1.1b' => [
                'input' => 0.0000005,
                'output' => 0,
            ],
            'microsoft/kosmos-2' => [
                'input' => 0.000001,
                'output' => 0.000001,
            ],
        ];

        $modelRate = $rates[$this->model_name] ?? [
            'input' => 0.000001,
            'output' => 0.000002,
        ];

        $inputCost = ($this->input_tokens ?? 0) * $modelRate['input'];
        $outputCost = ($this->output_tokens ?? 0) * $modelRate['output'];

        return $inputCost + $outputCost;
    }

    /**
     * Static method to get usage statistics.
     */
    public static function getStatistics($startDate = null, $endDate = null): array
    {
        $query = self::query();

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return [
            'total_requests' => $query->count(),
            'successful_requests' => (clone $query)->where('status', self::STATUS_SUCCESS)->count(),
            'failed_requests' => (clone $query)->where('status', '!=', self::STATUS_SUCCESS)->count(),
            'total_tokens' => (clone $query)->sum('total_tokens'),
            'total_cost' => (clone $query)->sum('cost_usd'),
            'avg_latency' => (clone $query)->avg('latency_ms'),
            'by_service' => (clone $query)->selectRaw('service_type, count(*) as count, sum(total_tokens) as tokens')
                ->groupBy('service_type')
                ->get()
                ->toArray(),
        ];
    }
}
