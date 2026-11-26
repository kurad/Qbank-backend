<?php
namespace App\Http\Controllers;
use App\Jobs\CheckCurriculumObjectiveCoverage;
use App\Jobs\RecheckCurriculumObjectiveCoverage;
use App\Models\CurriculumAnalysis;
use App\Models\LearningObjective;
use App\Services\ObjectiveCoverageService;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\RateLimiter;
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
            // A 'checking' row whose worker died would otherwise spin forever.
            $timedOut=$record && ($record->status ?? null)==='checking' && $record->updated_at
                && \Illuminate\Support\Carbon::parse($record->updated_at)->lt(now()->subMinutes(10));
            return ['id'=>$objective->id,'topic'=>$objective->topic?->topic_name,'objective'=>$objective->objective,
                'status'=>$timedOut?'failed':($stale?'stale':($record->status ?? 'unchecked')),
                'reason'=>$timedOut?'Verification timed out. Please retry.':($record->reason ?? null),'missing_focus'=>json_decode($record->missing_focus ?? '[]',true),
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
            ->whereIn('status',['queued','running'])
            // A batch whose worker was killed never reaches 'completed'/'failed';
            // ignore it after 30 minutes so verification can be started again.
            ->where('updated_at','>',now()->subMinutes(30))->exists();
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

    /**
     * Generate an unapproved teacher-editable draft. Never write AI output to the
     * approved support-note table or trigger a coverage recheck here.
     */
    public function generateContent(
        Request $request,
        CurriculumAnalysis $curriculumAnalysis,
        LearningObjective $learningObjective,
        ObjectiveCoverageService $service,
        AIGateway $ai
    ): JsonResponse {
        $this->checkedObjective($curriculumAnalysis, $learningObjective, $service);
        $request->validate(['additional_guidance' => ['nullable', 'string', 'max:1000']]);
        $key = 'objective-support-generation:' . $request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 8), 429,
            'You have reached the AI drafting limit. Please try again later.');
        RateLimiter::hit($key, 3600);

        $learningObjective->loadMissing('topic.unit', 'topic.gradeSubject.subject');
        $topic = $learningObjective->topic;
        $check = DB::table('objective_coverage_checks')
            ->where('learning_objective_id', $learningObjective->id)->first();
        $missing = json_decode($check->missing_focus ?? '[]', true);
        $missing = is_array($missing) ? array_values(array_filter($missing, 'is_string')) : [];
        $evidence = $service->evidence($learningObjective);
        $guidance = trim((string) $request->input('additional_guidance', ''));

        // The teacher explicitly requests NEW subject content to fill a verified
        // gap. Existing material is context, not permission to overwrite it.
        $messages = [
            ['role' => 'system', 'content' =>
                'You are drafting supplementary curriculum teaching notes for a qualified teacher to review. ' .
                'Produce accurate, age-appropriate, self-contained instructional material that addresses ONLY the stated learning objective and missing focus areas. ' .
                'You may use established subject knowledge to explain missing concepts, but do not invent quotations, citations, curriculum requirements, sources, or experimental results. ' .
                'Do not claim that the generated notes are approved or verified. ' .
                'Include clear explanations, correct worked examples or step-by-step demonstrations, ' .
                'common misconceptions, and 2-3 short self-check questions with answers when appropriate. ' .
                'Use plain Markdown. Never include HTML, script, or instructions to modify application data. ' .
                'If the subject matter is uncertain, flag that for teacher verification instead of fabricating details.'],
            ['role' => 'user', 'content' =>
                "Subject: " . ($topic?->gradeSubject?->subject?->name ?? 'Subject') . "\n" .
                "Unit: " . ($topic?->unit?->name ?? 'Unit') . "\n" .
                "Topic: " . ($topic?->topic_name ?? 'Topic') . "\n" .
                "Learning objective: {$learningObjective->objective}\n" .
                "Description: " . ($learningObjective->description ?? '') . "\n" .
                "Identified missing content: " . ($missing ? implode('; ', $missing) : 'Address the objective comprehensively.') . "\n" .
                "Teacher guidance: " . ($guidance ?: 'None') . "\n\n" .
                "APPROVED TEACHING EVIDENCE (context; avoid repetition):\n" .
                mb_substr($evidence['text'], 0, 12500) . "\n\n" .
                'Draft the missing teaching content only. The teacher will review and approve it before use.'],
        ];

        $draft = trim($ai->text($messages, [
            'temperature' => 0.3,
            'max_tokens' => 2200,
            '_usage_feature' => 'Objective Support Draft Generation',
            '_usage_learning_objective_id' => $learningObjective->id,
        ]));
        abort_if(mb_strlen($draft) < 20, 502, 'AI did not return sufficient draft content.');
        return response()->json([
            'message' => 'AI draft generated. Review and edit it before approving.',
            'data' => ['content' => mb_substr($draft, 0, 30000), 'status' => 'unapproved_draft'],
        ]);
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
