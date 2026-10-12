<?php

namespace App\Jobs;

use App\Models\Assessment;
use App\Models\LearningPeriod;
use App\Services\LearningPeriodFormativeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareLearningPeriodFormativeAssessment implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $uniqueFor = 900;

    public function __construct(
        public int $learningPeriodId
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'period-formative:' . $this->learningPeriodId;
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(
        LearningPeriodFormativeService $service
    ): void {
        $period = LearningPeriod::find(
            $this->learningPeriodId
        );

        if (!$period) {
            return;
        }

        $exists = Assessment::query()
            ->where(
                'learning_period_id',
                $period->id
            )
            ->where(
                'purpose',
                'period_formative'
            )
            ->exists();

        if ($exists) {
            return;
        }

        $service->ensureForPeriod($period);

        Log::info(
            'Mandatory period formative prepared in background.',
            [
                'learning_period_id' => $period->id,
            ]
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error(
            'Background period formative preparation failed.',
            [
                'learning_period_id' =>
                    $this->learningPeriodId,
                'error' =>
                    $exception->getMessage(),
            ]
        );
    }
}
