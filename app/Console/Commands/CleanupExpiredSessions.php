<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

class CleanupExpiredSessions extends Command
{
    protected $signature = 'sessions:cleanup {--hours=24 : Hours to consider a session expired}';

    protected $description = 'Clean up expired conversation sessions';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $expired = Conversation::where('last_message_at', '<', $cutoff)
            ->where('is_active', true)
            ->get();

        foreach ($expired as $conversation) {
            $conversation->endSession();
            $this->info("Ended session for: {$conversation->user_phone}");
        }

        $this->info("Cleaned up {$expired->count()} expired sessions.");

        return 0;
    }
}
