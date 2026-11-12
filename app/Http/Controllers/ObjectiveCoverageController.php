<?php
namespace App\Http\Controllers;
use App\Jobs\CheckCurriculumObjectiveCoverage;
use App\Jobs\RecheckCurriculumObjectiveCoverage;
use App\Models\CurriculumAnalysis;
use App\Models\LearningObjective;
use App\Services\ObjectiveCoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ObjectiveCoverageController extends CurriculumAnalysisController
{
    private function guard(CurriculumAnalysis $analysis): void
    {
        $this->authorizeAnalysis($analysis);
        abort_unless($analysis->status === 'approved', 422, 'Approve the curriculum first.');
    }
    public function index(CurriculumAnalysis $curriculumAnalysis, ObjectiveCoverageService $service): JsonResponse
    {
        $this->guard($curriculumAnalysis);
        $objectives = $service->objectives($curriculumAnalysis);
        $checks=DB::table('objective_coverage_checks')->whereIn('learning_objective_id',$objectives->pluck('id'))->get()->keyBy('learning_objective_id');
        $records=$objectives->map(function ($objective) use ($checks,$service) {
            $record=$checks->get($objective->id);
            $stale=$record && $record->content_hash && $record->content_hash !== $service->evidence($objective)['hash'];
            return ['id'=>$objective->id,'topic'=>$objective->topic?->topic_name,'objective'=>$objective->objective,
                'status'=>$stale?'stale':($record->status ?? 'unchecked'),
                'reason'=>$record->reason ?? null,'missing_focus'=>json_decode($record->missing_focus ?? '[]',true),
                'checked_at'=>$record->checked_at ?? null];
        });
        $batch=DB::table('objective_coverage_batches')->where('curriculum_analysis_id',$curriculumAnalysis->id)->latest('id')->first();
        return response()->json(['data'=>['objectives'=>$records,'batch'=>$batch,
            'total'=>$records->count(),'flagged'=>$records->whereIn('status',['partial','unsupported','failed','stale'])->count()]]);
    }
    public function checkAll(CurriculumAnalysis $curriculumAnalysis, ObjectiveCoverageService $service): JsonResponse
    {
        $this->guard($curriculumAnalysis);
        $busy=DB::table('objective_coverage_batches')->where('curriculum_analysis_id',$curriculumAnalysis->id)
            ->whereIn('status',['queued','running'])->exists();
        abort_if($busy,409,'A verification is already running.');
        $batch=DB::table('objective_coverage_batches')->insertGetId([
            'curriculum_analysis_id'=>$curriculumAnalysis->id,'requested_by'=>auth()->id(),
            'status'=>'queued','total'=>$service->objectives($curriculumAnalysis)->count(),
            'processed'=>0,'created_at'=>now(),'updated_at'=>now(),
        ]);
        CheckCurriculumObjectiveCoverage::dispatch($curriculumAnalysis->id,$batch)->onQueue('default');
        return response()->json(['message'=>'Objective coverage verification queued.','batch_id'=>$batch],202);
    }
    public function recheck(CurriculumAnalysis $curriculumAnalysis, LearningObjective $learningObjective, ObjectiveCoverageService $service): JsonResponse
    {
        $this->guard($curriculumAnalysis);
        abort_unless($service->objectives($curriculumAnalysis)->contains('id',$learningObjective->id),404);
        DB::table('objective_coverage_checks')->updateOrInsert(['learning_objective_id'=>$learningObjective->id], [
            'status'=>'checking','reason'=>null,'content_hash'=>null,'checked_at'=>null,
            'supported_focus'=>json_encode([]),'missing_focus'=>json_encode([]),
            'updated_at'=>now(),
        ]);
        RecheckCurriculumObjectiveCoverage::dispatch($learningObjective->id)->onQueue('default');
        return response()->json(['message'=>'Objective recheck queued.'],202);
    }
    private function checkedObjective(CurriculumAnalysis $analysis, LearningObjective $objective, ObjectiveCoverageService $service): void
    {
        $this->guard($analysis);
        abort_unless($service->objectives($analysis)->contains('id', $objective->id), 404);
    }

    public function content(CurriculumAnalysis $curriculumAnalysis, LearningObjective $learningObjective, ObjectiveCoverageService $service): JsonResponse
    {
        $this->checkedObjective($curriculumAnalysis, $learningObjective, $service);
        $gradeSubjectId = $learningObjective->topic?->grade_subject_id;
        abort_unless($gradeSubjectId, 422, 'Objective must belong to a teaching area.');
        $note = DB::table('objective_support_notes')->where('learning_objective_id', $learningObjective->id)
            ->where('grade_subject_id', $gradeSubjectId)->first();
        return response()->json(['data' => ['content' => $note->content ?? '', 'approved_at' => $note->approved_at ?? null]]);
    }

    public function saveContent(Request $request, CurriculumAnalysis $curriculumAnalysis, LearningObjective $learningObjective, ObjectiveCoverageService $service): JsonResponse
    {
        $this->checkedObjective($curriculumAnalysis, $learningObjective, $service);
        $data = $request->validate(['content' => ['required', 'string', 'min:20', 'max:30000']]);
        $now = now();
        $gradeSubjectId = $learningObjective->topic?->grade_subject_id;
        abort_unless($gradeSubjectId, 422, 'Objective must belong to a teaching area.');
        DB::table('objective_support_notes')->updateOrInsert(
            ['learning_objective_id' => $learningObjective->id, 'grade_subject_id' => $gradeSubjectId],
            ['teacher_id' => $request->user()->id, 'content' => trim($data['content']),
             'approved_by' => $request->user()->id, 'approved_at' => $now,
             'updated_at' => $now]
        );
        // Deliberately do not run verification automatically. The teacher rechecks this objective when ready.
        return response()->json(['message' => 'Supporting content approved. Recheck this objective to refresh its coverage result.']);
    }

}
