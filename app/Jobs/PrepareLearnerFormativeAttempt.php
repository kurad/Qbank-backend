<?php

namespace App\Jobs;

use App\Models\LearnerFormativeAttempt;
use App\Services\LearnerFormativeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PrepareLearnerFormativeAttempt implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $attemptId)
    {
    }

    public function uniqueId(): string
    {
        return 'learner-formative-attempt:' . $this->attemptId;
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(LearnerFormativeService $service): void
    {
        $attempt = LearnerFormativeAttempt::find($this->attemptId);

        if (!$attempt || in_array($attempt->status, ['ready', 'in_progress', 'completed'], true)) {
            return;
        }

        $service->prepareAttempt($attempt);
    }

    public function failed(Throwable $exception): void
    {
        LearnerFormativeAttempt::whereKey($this->attemptId)->update([
            'status' => 'failed',
            'progress' => 0,
            'stage' => 'failed',
            'message' => 'We could not prepare your assessment. You can try again.',
            'error_message' => mb_substr($exception->getMessage(), 0, 5000),
        ]);
    }
}
