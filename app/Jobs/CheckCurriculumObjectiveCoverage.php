<?php
namespace App\Jobs;

use App\Models\CurriculumAnalysis;
use App\Services\ObjectiveCoverageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CheckCurriculumObjectiveCoverage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 1200;
    public int $tries = 2;
    public function __construct(public int $analysisId, public int $batchId) {}
    public function handle(ObjectiveCoverageService $service): void
    {
        $analysis = CurriculumAnalysis::find($this->analysisId);
        if (!$analysis || $analysis->status !== 'approved') return;
        DB::table('objective_coverage_batches')->where('id', $this->batchId)->update(['status'=>'running','updated_at'=>now()]);
        $objectives = $service->objectives($analysis);
        foreach ($objectives as $objective) {
            $service->verify($objective);
            DB::table('objective_coverage_batches')->where('id', $this->batchId)->increment('processed');
        }
        DB::table('objective_coverage_batches')->where('id', $this->batchId)->update(['status'=>'completed','finished_at'=>now(),'updated_at'=>now()]);
    }
    public function failed(\Throwable $e): void
    {
        DB::table('objective_coverage_batches')->where('id', $this->batchId)->update([
            'status'=>'failed','error'=>'The background job stopped unexpectedly. Check the Laravel worker logs.',
            'finished_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
