<?php
namespace App\Services;

use App\Models\CurriculumAnalysis;
use App\Models\LearningObjective;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\DB;

/** Read-only verification: never edits approved materials, curriculum, or mastery. */
class ObjectiveCoverageService
{
    public function __construct(private AIGateway $ai) {}

    public function objectives(CurriculumAnalysis $analysis)
    {
        return LearningObjective::query()->whereHas('topic', function ($q) use ($analysis) {
            $q->where('curriculum_analysis_id', $analysis->id)->where('status', 'active');
        })->where('status', 'active')->orderBy('topic_id')->orderBy('order')->get();
    }

    public function evidence(LearningObjective $objective): array
    {
        $topic = $objective->topic;
        $unitId = $topic->unit_id;
        $subjectId = $topic->gradeSubject?->subject_id;
        // Use unit/topic-scoped approved materials and approved supplements explicitly mapped to this goal.
        $mappedIds = DB::table('course_material_objective_supports')
            ->where('learning_objective_id', $objective->id)->pluck('course_material_id')->all();
        $chunks = DB::table('knowledge_chunks as kc')
            ->join('course_materials as cm', 'cm.id', '=', 'kc.course_material_id')
            ->where('kc.status', 'ready')->where('cm.status', 'approved')
            ->whereIn('cm.processing_status', ['ready', 'completed'])
            ->where(function ($q) use ($unitId, $topic, $mappedIds) {
                $q->where(function ($p) use ($unitId, $topic) {
                    $p->where('kc.unit_id', $unitId)
                      ->where(function ($s) use ($topic) {
                          $s->whereNull('kc.topic_id')->orWhere('kc.topic_id', $topic->id);
                      });
                });
                if ($mappedIds) $q->orWhereIn('kc.course_material_id', $mappedIds);
            })
            ->orderBy('kc.course_material_id')->orderBy('kc.chunk_index')
            ->select('kc.content', 'kc.course_material_id', 'kc.updated_at')->get();
        $query = strtolower(trim($objective->code.' '.$objective->objective.' '.$objective->description));
        $terms = array_values(array_unique(array_filter(preg_split('/[^\\pL\\pN]+/u', $query), fn($t) => mb_strlen($t) >= 4)));
        $ranked = $chunks->map(function ($row) use ($terms) {
            $content = mb_strtolower($row->content);
            $row->score = array_sum(array_map(fn ($term) => mb_substr_count($content, $term), $terms));
            return $row;
        })->sortByDesc('score')->take(12);
        $evidence = $ranked->map(fn ($c) => 'Approved material #'.$c->course_material_id.":\n".mb_substr($c->content, 0, 3500))->values()->all();
        // Include validated supporting website excerpts linked to approved course materials.
        $materialIds = $chunks->pluck('course_material_id')->merge($mappedIds)->unique()->all();
        if ($materialIds) {
            $sources = DB::table('course_material_supporting_sources')
                ->whereIn('course_material_id', $materialIds)->where('status', 'ready')
                ->whereNotNull('validated_at')->whereNotNull('cached_content')
                ->select('id', 'cached_content', 'updated_at')->get();
            foreach ($sources as $source) {
                $evidence[] = 'Approved supporting source #'.$source->id.":\n".mb_substr($source->cached_content, 0, 4500);
            }
        }
        // Teacher-authored, explicitly approved supplements are objective-scoped.
        // Include them in the coverage evidence and therefore in the content freshness hash.
        $note = DB::table('objective_support_notes')
            ->where('learning_objective_id', $objective->id)
            ->whereNotNull('approved_at')->first();
        if ($note && trim((string) $note->content) !== '') {
            array_unshift($evidence, 'Approved teacher supplement for objective #'.$objective->id.":\n".$note->content);
        }
        $evidence = array_slice($evidence, 0, 16);
        return ['text' => implode("\n\n---\n\n", $evidence),
            'hash' => hash('sha256', $objective->id.'|'.$objective->updated_at.'|'.implode('|', $evidence))];
    }

    public function verify(LearningObjective $objective): void
    {
        $objective->loadMissing('topic.gradeSubject');
        $evidence = $this->evidence($objective);
        $result = ['status' => 'unsupported', 'reason' => 'No relevant approved teaching material was found.',
            'supported_focus' => [], 'missing_focus' => [$objective->objective]];
        try {
            if (trim($evidence['text']) !== '') {
                $result = $this->ai->json([
                    ['role'=>'system','content'=>"Check whether the approved teacher-provided evidence is sufficient to teach and fairly assess the learning objective. Use ONLY this evidence; no outside knowledge. supported means full coverage, partial means some but not all essential skills, unsupported means insufficient essentials. Return JSON.\n\nObjective: {$objective->objective}\nDescription: {$objective->description}\n\nAPPROVED EVIDENCE:\n".$evidence['text']],
                    ['role'=>'user','content'=>'Identify missing requirements precisely, without supplying the missing subject matter.']
                ], ['type'=>'object','additionalProperties'=>false,'properties'=>[
                    'status'=>['type'=>'string','enum'=>['supported','partial','unsupported']],
                    'reason'=>['type'=>'string'],
                    'supported_focus'=>['type'=>'array','items'=>['type'=>'string']],
                    'missing_focus'=>['type'=>'array','items'=>['type'=>'string']],
                ],'required'=>['status','reason','supported_focus','missing_focus']],
                ['temperature'=>0,'max_tokens'=>700]);
            }
            if (!in_array($result['status'] ?? '', ['supported','partial','unsupported'], true)) {
                throw new \RuntimeException('AI returned an invalid coverage status.');
            }
            DB::table('objective_coverage_checks')->updateOrInsert(['learning_objective_id'=>$objective->id], [
                'status'=>$result['status'], 'reason'=>(string)($result['reason'] ?? ''),
                'supported_focus'=>json_encode($result['supported_focus'] ?? []),
                'missing_focus'=>json_encode($result['missing_focus'] ?? []),
                'content_hash'=>$evidence['hash'], 'checked_at'=>now(), 'updated_at'=>now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Objective content verification failed', ['objective_id'=>$objective->id,'error'=>$e->getMessage()]);
            DB::table('objective_coverage_checks')->updateOrInsert(['learning_objective_id'=>$objective->id], [
                'status'=>'failed','reason'=>'Verification could not be completed. Please retry.',
                'supported_focus'=>json_encode([]),'missing_focus'=>json_encode([]),
                'content_hash'=>null,'checked_at'=>now(),'updated_at'=>now(),'created_at'=>now(),
            ]);
        }
    }
}
