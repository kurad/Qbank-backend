<?php
namespace App\Jobs;
use App\Models\LearningObjective;
use App\Services\ObjectiveCoverageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class RecheckCurriculumObjectiveCoverage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout=180;
    public function __construct(public int $objectiveId) {}
    public function handle(ObjectiveCoverageService $service): void
    {
        $objective=LearningObjective::find($this->objectiveId);
        if ($objective && $objective->status === 'active') $service->verify($objective);
    }
}
