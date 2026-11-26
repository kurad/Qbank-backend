<?php

namespace App\Jobs;

use App\Exceptions\FormativePreparationException;
use App\Exceptions\FormativeTimeBudgetExceeded;
use App\Models\LearnerFormativeAttempt;
use App\Services\LearnerFormativeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareLearnerFormativeAttempt implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A run that uses up its time budget releases itself and continues (its
     * stored questions are reused), so releases must not eat the retry
     * allowance: only real exceptions are capped by $maxExceptions.
     */
    public int $tries = 8;

    public int $maxExceptions = 3;

    /** Hard limit per run. config/queue.php retry_after must be larger. */
    public int $timeout = 300;

    /**
     * The uniqueness lock must expire. Without this a worker killed
     * mid-job left the lock behind and that learner could never be prepared
     * again until the cache was cleared.
     */
    public int $uniqueFor = 330;

    /** Seconds of a run after which no further objective is started. */
    private const TIME_BUDGET = 150;

    public function __construct(public int $attemptId)
    {
    }

    public function uniqueId(): string
    {
        return 'learner-formative-attempt:' . $this->attemptId;
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120, 180];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('learner-formative-' . $this->attemptId))
                ->releaseAfter(20)
                ->expireAfter(330),
        ];
    }

    public function handle(LearnerFormativeService $service): void
    {
        // Matters when QUEUE_CONNECTION=sync, where PHP's web limit applies.
        @set_time_limit($this->timeout);

        $attempt = LearnerFormativeAttempt::find($this->attemptId);

        if (!$attempt || in_array($attempt->status, ['ready', 'in_progress', 'completed'], true)) {
            return;
        }

        // A sync "queue" cannot release and resume, so it gets no budget.
        $deadline = config('queue.default') === 'sync'
            ? null
            : time() + self::TIME_BUDGET;

        try {
            $service->prepareAttempt($attempt, $deadline);
        } catch (FormativeTimeBudgetExceeded $e) {
            // Progress is stored; carry on shortly.
            $this->release(5);
        } catch (FormativePreparationException $e) {
            // Retrying cannot change the outcome.
            if ($this->job) {
                $this->fail($e);
            } else {
                $this->failed($e);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        // The technical reason belongs in the log. What the student (and the
        // status API) sees must never contain SQL or provider error text.
        Log::error('Learner formative preparation failed.', [
            'attempt_id' => $this->attemptId,
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
        ]);

        $message = $exception instanceof FormativePreparationException
            ? $exception->getMessage()
            : 'We could not prepare your assessment right now. Please press Try again in a moment. If it keeps failing, let your teacher know.';

        LearnerFormativeAttempt::whereKey($this->attemptId)
            ->whereNotIn('status', ['ready', 'in_progress', 'completed'])
            ->update([
                'status' => 'failed',
                'progress' => 0,
                'stage' => 'failed',
                'message' => $message,
                'error_message' => $message,
            ]);
    }
}
