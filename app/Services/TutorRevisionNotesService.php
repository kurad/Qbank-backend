<?php

namespace App\Services;

use App\Models\StudentTopicLearningState;
use App\Models\TutorSession;
use App\Models\User;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Persist a single revision handout per covered learner/topic/period. */
class TutorRevisionNotesService
{
    public function __construct(
        protected AIGateway $ai,
        protected TutorKnowledgeService $knowledge,
        protected TutorSupportingSourceService $supportingSources
    ) {}

    public function existing(User $student, int $periodId, int $topicId): ?object
    {
        $this->assertCovered($student, $periodId, $topicId);
        return DB::table('student_topic_revision_notes')
            ->where('student_id', $student->id)
            ->where('learning_period_id', $periodId)
            ->where('topic_id', $topicId)
            ->first();
    }

    public function generate(User $student, int $periodId, int $topicId): object
    {
        $this->assertCovered($student, $periodId, $topicId);
        $existing = $this->existing($student, $periodId, $topicId);
        if ($existing) return $existing;

        // Lock prevents two tabs from paying to generate the same handout.
        $lock = Cache::lock("revision-notes:{$student->id}:{$periodId}:{$topicId}", 180);
        if (!$lock->get()) {
            throw ValidationException::withMessages([
                'notes' => 'Your revision notes are already being prepared. Please retry shortly.',
            ]);
        }

        try {
            $existing = $this->existing($student, $periodId, $topicId);
            if ($existing) return $existing;
            $session = TutorSession::query()
                ->where('student_id', $student->id)
                ->where('learning_period_id', $periodId)
                ->where('topic_id', $topicId)
                ->where('status', 'completed')
                ->where('session_mode', 'learning')
                ->with(['topic', 'unit', 'objectives.learningObjective', 'student'])
                ->latest('id')->first();
            if (!$session) {
                throw ValidationException::withMessages(['notes' => 'No completed learning session was found for this topic.']);
            }

            $goals = $session->objectives->sortBy('objective_order')->map(function ($item) {
                $goal = trim((string) ($item->learningObjective?->objective ?? ''));
                return $goal === '' ? null : ('- ' . $goal . ' [learning record: ' . $item->status . ']');
            })->filter()->implode("\n");

            $search = trim(($session->topic?->topic_name ?? '') . ' ' . $goals);
            $chunks = $this->knowledge->retrieve($session, $search, 6);
            $sources = $chunks->map(fn ($c) => trim((string) ($c->content ?? '')))
                ->filter()->map(fn ($v) => mb_substr($v, 0, 1400))->implode("\n\n---\n\n");
            // Approved addenda and source excerpts attached to completed
            // objectives may be omitted by ordinary retrieval (no active goal).
            // Include short, audited excerpts for the revision handout.
            $supplements = [];
            foreach ($session->objectives as $item) {
                if (!$item->learning_objective_id) continue;
                $teacherNote = DB::table('objective_support_notes')
                    ->where('learning_objective_id', $item->learning_objective_id)
                    ->where('grade_subject_id', $session->grade_subject_id)
                    ->whereNotNull('approved_at')->first();
                if ($teacherNote && trim((string) $teacherNote->content) !== '') {
                    $supplements[] = mb_substr((string) $teacherNote->content, 0, 900);
                }
                foreach ($this->supportingSources->sourcesForObjective($item)->take(2) as $source) {
                    $excerpt = trim((string) ($source->tutor_support_excerpt ?? ''));
                    if ($excerpt !== '') $supplements[] = mb_substr($excerpt, 0, 900);
                }
            }
            if ($supplements) $sources .= "\n\nVERIFIED SUPPORTING EXCERPTS:\n" . implode("\n---\n", array_slice($supplements, 0, 8));
            $sources = mb_substr($sources, 0, 11500);
            if (trim($sources) === '') {
                throw ValidationException::withMessages(['notes' => 'Approved learning material is needed before revision notes can be generated.']);
            }

            // Deliberately do not resend full chats. Objective statuses are evidence,
            // not claims of mastery, and the saved notes are NOT assessment evidence.
            $content = $this->ai->text([
                ['role' => 'system', 'content' => 'Create concise, accurate student revision notes using ONLY the approved teacher material below. Do not invent information beyond it. Include a clear topic title, key ideas for each supported learning goal, concise examples, common pitfalls, and 3-5 self-check questions with brief answers. If an objective is unsupported by the material, explicitly say it needs teacher clarification. Never say a student mastered or passed an objective based on these notes. Use Markdown; do not include hidden grading data or personal student information.'],
                ['role' => 'user', 'content' => "TOPIC: {$session->topic?->topic_name}\nUNIT: {$session->unit?->name}\nLEARNING GOALS / STATES:\n{$goals}\n\nAPPROVED MATERIAL:\n{$sources}"],
            ], [
                '_usage_feature' => 'Tutor Revision Notes',
                '_usage_user_id' => $student->id,
                '_usage_school_id' => $student->school_id,
                '_usage_role' => 'student',
                '_usage_tutor_session_id' => $session->id,
                '_usage_learning_period_id' => $periodId,
                '_usage_topic_id' => $topicId,
                '_usage_subject_id' => $session->subject_id,
                'temperature' => 0.2,
                'max_tokens' => 1700,
            ]);
            if (trim($content) === '') throw new RuntimeException('AI returned empty revision notes.');

            DB::table('student_topic_revision_notes')->insertOrIgnore([
                'student_id' => $student->id,
                'learning_period_id' => $periodId,
                'topic_id' => $topicId,
                'source_session_id' => $session->id,
                'content' => $content,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->existing($student, $periodId, $topicId);
        } finally {
            optional($lock)->release();
        }
    }

    private function assertCovered(User $student, int $periodId, int $topicId): void
    {
        $covered = StudentTopicLearningState::query()
            ->where('student_id', $student->id)
            ->where('learning_period_id', $periodId)
            ->where('topic_id', $topicId)
            ->where('progression_status', 'covered')
            ->exists();
        abort_unless($covered, 403, 'Revision notes become available after you cover this topic.');
    }
}
