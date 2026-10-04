<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\StudentAssessment;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    /**
     * Student dashboard analytics.
     *
     * GET /student/statistics
     */
    public function statistics()
    {
        $user = Auth::user();

        $studentAssessments = StudentAssessment::query()
            ->with([
                'assessment:id,title,type,due_date',
                'assessment.units:id,name,grade_subject_id',
                'assessment.units.gradeSubject:id,subject_id,grade_level_id',
                'assessment.units.gradeSubject.subject:id,name',
                'assessment.topics:id,topic_name,grade_subject_id,unit_id',
                'assessment.topics.gradeSubject:id,subject_id,grade_level_id',
                'assessment.topics.gradeSubject.subject:id,name',
            ])
            ->where('student_id', $user->id)
            ->orderByDesc('completed_at')
            ->orderByDesc('assigned_at')
            ->get();

        $finishedStatuses = ['completed', 'graded'];
        $resolvedStatuses = ['completed', 'graded', 'under_review'];

        $finishedAssessments = $studentAssessments
            ->whereIn('status', $finishedStatuses)
            ->values();

        $resolvedAssessments = $studentAssessments
            ->whereIn('status', $resolvedStatuses)
            ->values();

        $scorePercentage = static function (StudentAssessment $item): ?float {
            if (!in_array($item->status, ['completed', 'graded'], true)) {
                return null;
            }

            if ((float) $item->max_score > 0) {
                return round(((float) $item->score / (float) $item->max_score) * 100, 1);
            }

            // Legacy rows may contain a percentage directly in score.
            if ($item->score !== null && (float) $item->score >= 0 && (float) $item->score <= 100) {
                return round((float) $item->score, 1);
            }

            return null;
        };

        $scoredPercentages = $finishedAssessments
            ->map($scorePercentage)
            ->filter(fn ($value) => $value !== null)
            ->values();

        $averageScore = $scoredPercentages->isNotEmpty()
            ? round((float) $scoredPercentages->avg(), 1)
            : null;

        $bestScore = $scoredPercentages->isNotEmpty()
            ? round((float) $scoredPercentages->max(), 1)
            : null;

        $totalAssessments = $studentAssessments->count();
        $completedAssessments = $resolvedAssessments->count();
        $pendingAssessments = max(0, $totalAssessments - $completedAssessments);
        $assessmentCompletionRate = $totalAssessments > 0
            ? round(($completedAssessments / $totalAssessments) * 100, 1)
            : 0;

        $totalPractices = $studentAssessments
            ->filter(fn ($item) => $item->assessment?->type === 'practice')
            ->count();

        $subjectPerformance = $finishedAssessments
            ->map(function (StudentAssessment $item) use ($scorePercentage) {
                $percentage = $scorePercentage($item);
                $subject = $this->assessmentSubjectName($item);

                if ($percentage === null || !$subject) {
                    return null;
                }

                return [
                    'subject' => $subject,
                    'score' => $percentage,
                ];
            })
            ->filter()
            ->groupBy('subject')
            ->map(function ($items, $subject) {
                return [
                    'subject' => $subject,
                    'avgScore' => round((float) $items->avg('score'), 1),
                    'attempts' => $items->count(),
                ];
            })
            ->sortByDesc('avgScore')
            ->values();

        $recentScores = $studentAssessments
            ->take(6)
            ->map(function (StudentAssessment $item) use ($scorePercentage) {
                return [
                    'id' => $item->id,
                    'assessment_id' => $item->assessment_id,
                    'title' => $item->assessment?->title ?? 'Assessment',
                    'type' => $item->assessment?->type,
                    'status' => $item->status,
                    'score' => $scorePercentage($item),
                    'date' => optional($item->completed_at ?? $item->assigned_at)?->toIso8601String(),
                    'subject' => $this->assessmentSubjectName($item),
                ];
            })
            ->values();

        $tutorObjectives = TutorSessionObjective::query()
            ->whereHas('session', fn ($query) => $query->where('student_id', $user->id))
            ->get(['id', 'tutor_session_id', 'status', 'attempts', 'correct_attempts', 'completed_at']);

        $totalTutorObjectives = $tutorObjectives->count();
        $masteredObjectives = $tutorObjectives->where('status', 'completed')->count();
        $reviewObjectives = $tutorObjectives->where('status', 'needs_review')->count();
        $activeObjectives = $tutorObjectives->where('status', 'in_progress')->count();
        $coveredObjectives = $masteredObjectives + $reviewObjectives;

        $masteryRate = $totalTutorObjectives > 0
            ? round(($masteredObjectives / $totalTutorObjectives) * 100, 1)
            : 0;

        $coverageRate = $totalTutorObjectives > 0
            ? round(($coveredObjectives / $totalTutorObjectives) * 100, 1)
            : 0;

        $activeTutorSession = TutorSession::query()
            ->with([
                'subject:id,name',
                'unit:id,name',
                'topic:id,topic_name',
                'objectives:id,tutor_session_id,learning_objective_id,objective_order,status',
                'objectives.learningObjective:id,objective,code',
            ])
            ->where('student_id', $user->id)
            ->where('status', 'active')
            ->orderByDesc('last_activity_at')
            ->first();

        $currentLearning = null;
        if ($activeTutorSession) {
            $sessionObjectives = $activeTutorSession->objectives;
            $currentObjective = $sessionObjectives->firstWhere('status', 'in_progress');
            $sessionCovered = $sessionObjectives
                ->whereIn('status', ['completed', 'needs_review'])
                ->count();

            $currentLearning = [
                'session_id' => $activeTutorSession->id,
                'title' => $activeTutorSession->title,
                'subject' => $activeTutorSession->subject?->name,
                'unit' => $activeTutorSession->unit?->name,
                'topic' => $activeTutorSession->topic?->topic_name,
                'current_objective' => $currentObjective?->learningObjective?->objective,
                'current_objective_code' => $currentObjective?->learningObjective?->code,
                'objective_position' => $currentObjective?->objective_order,
                'total_objectives' => $sessionObjectives->count(),
                'covered_objectives' => $sessionCovered,
                'mastered_objectives' => $sessionObjectives->where('status', 'completed')->count(),
                'review_objectives' => $sessionObjectives->where('status', 'needs_review')->count(),
                'progress_percent' => $sessionObjectives->count() > 0
                    ? round(($sessionCovered / $sessionObjectives->count()) * 100, 1)
                    : 0,
                'last_activity_at' => optional($activeTutorSession->last_activity_at)?->toIso8601String(),
            ];
        }

        $tutorSessions = TutorSession::query()
            ->where('student_id', $user->id)
            ->get(['id', 'status', 'started_at', 'last_activity_at', 'ended_at']);

        $lastSixWeeks = collect(range(5, 1))->map(function ($weeksAgo) use ($user) {
            $start = now()->startOfWeek()->subWeeks($weeksAgo);
            $end = (clone $start)->endOfWeek();

            $assessments = StudentAssessment::query()
                ->where('student_id', $user->id)
                ->whereIn('status', ['completed', 'graded'])
                ->whereBetween('completed_at', [$start, $end])
                ->count();

            $objectives = TutorSessionObjective::query()
                ->whereHas('session', fn ($query) => $query->where('student_id', $user->id))
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$start, $end])
                ->count();

            return [
                'label' => $start->format('M j'),
                'assessments' => $assessments,
                'objectives' => $objectives,
            ];
        })->push((function () use ($user) {
            $start = now()->startOfWeek();
            $end = now()->endOfWeek();

            return [
                'label' => $start->format('M j'),
                'assessments' => StudentAssessment::query()
                    ->where('student_id', $user->id)
                    ->whereIn('status', ['completed', 'graded'])
                    ->whereBetween('completed_at', [$start, $end])
                    ->count(),
                'objectives' => TutorSessionObjective::query()
                    ->whereHas('session', fn ($query) => $query->where('student_id', $user->id))
                    ->where('status', 'completed')
                    ->whereBetween('completed_at', [$start, $end])
                    ->count(),
            ];
        })());

        return response()->json([
            'student' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'summary' => [
                'total_assessments' => $totalAssessments,
                'completed_assessments' => $completedAssessments,
                'pending_assessments' => $pendingAssessments,
                'assessment_completion_rate' => $assessmentCompletionRate,
                'average_score' => $averageScore,
                'best_score' => $bestScore,
                'total_practices' => $totalPractices,
                'tutor_sessions' => $tutorSessions->count(),
                'active_tutor_sessions' => $tutorSessions->where('status', 'active')->count(),
            ],
            'tutor_progress' => [
                'total_objectives' => $totalTutorObjectives,
                'mastered_objectives' => $masteredObjectives,
                'needs_review' => $reviewObjectives,
                'active_objectives' => $activeObjectives,
                'covered_objectives' => $coveredObjectives,
                'mastery_rate' => $masteryRate,
                'coverage_rate' => $coverageRate,
            ],
            'current_learning' => $currentLearning,
            'subjectPerformance' => $subjectPerformance,
            'recentScores' => $recentScores,
            'weeklyProgress' => $lastSixWeeks->values(),

            // Compatibility fields for older dashboard clients.
            'totalAssessments' => $totalAssessments,
            'totalPractices' => $totalPractices,
            'completedAssessments' => $completedAssessments,
            'pendingAssessments' => $pendingAssessments,
        ]);
    }


    /**
     * Resolve an assessment subject through the curriculum relationships that
     * actually exist in this schema. Assessments do not own grade_subject_id.
     */
    private function assessmentSubjectName(StudentAssessment $studentAssessment): ?string
    {
        $assessment = $studentAssessment->assessment;

        if (!$assessment) {
            return null;
        }

        $unitSubject = $assessment->units
            ?->first()?->gradeSubject?->subject?->name;

        if ($unitSubject) {
            return $unitSubject;
        }

        return $assessment->topics
            ?->first()?->gradeSubject?->subject?->name;
    }

    public function subjectsOverview()
    {
        $subjects = Subject::withCount(['topics', 'questions'])
            ->with(['topics.gradeLevels:id,grade_name'])
            ->select('id', 'name')
            ->get()
            ->flatMap(function ($subject) {
                $gradeLevels = $subject->topics
                    ->flatMap(fn ($topic) => $topic->gradeLevels)
                    ->unique('id');

                if ($gradeLevels->isEmpty()) {
                    return [[
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'grade_level' => null,
                        'grade_name' => 'All Grades',
                        'topics_count' => $subject->topics_count,
                        'questions_count' => $subject->questions_count,
                    ]];
                }

                return $gradeLevels->map(function ($grade) use ($subject) {
                    $topicsCount = $subject->topics->filter(function ($topic) use ($grade) {
                        return $topic->gradeLevels->contains('id', $grade->id);
                    })->count();

                    $questionsCount = $subject->questions()
                        ->whereHas('topic.gradeLevels', function ($q) use ($grade) {
                            $q->where('grade_levels.id', $grade->id);
                        })
                        ->count();

                    return [
                        'id' => $subject->id . '-' . $grade->id,
                        'subject_id' => $subject->id,
                        'grade_id' => $grade->id,
                        'name' => $subject->name,
                        'grade_level' => $grade->grade_name,
                        'grade_name' => $grade->grade_name,
                        'topics_count' => $topicsCount,
                        'questions_count' => $questionsCount,
                    ];
                });
            });

        return response()->json($subjects);
    }

    public function subjectTopics(Subject $subject, Request $request)
    {
        $request->validate([
            'grade_id' => 'sometimes|exists:grade_levels,id',
        ]);

        $query = $subject->topics()
            ->withCount('questions')
            ->select('id', 'topic_name', 'subject_id');

        if ($request->has('grade_id')) {
            $query->whereHas('gradeLevels', function ($q) use ($request) {
                $q->where('grade_levels.id', $request->grade_id);
            });
        }

        $topics = $query->get();

        return response()->json([
            'subject' => $subject->name,
            'topics' => $topics,
        ]);
    }

    public function topicQuestions(Topic $topic)
    {
        $questions = $topic->questions()
            ->select('id', 'question', 'question_type', 'difficulty_level', 'options', 'is_math')
            ->get();

        return response()->json([
            'topic' => $topic->topic_name,
            'questions' => $questions,
        ]);
    }

    /**
     * Summarized report: number of questions per subject
     * GET /reports/questions-per-subject
     */
    public function questionsPerSubject()
    {
        $summary = Subject::query()
            ->leftJoin('grade_subjects', 'grade_subjects.subject_id', '=', 'subjects.id')
            ->leftJoin('grade_levels', 'grade_levels.id', '=', 'grade_subjects.grade_level_id')
            ->leftJoin('topics', 'topics.grade_subject_id', '=', 'grade_subjects.id')
            ->leftJoin('questions', 'questions.topic_id', '=', 'topics.id')
            ->select([
                'subjects.id as subject_id',
                'subjects.name as subject_name',
                'grade_levels.id as grade_id',
                'grade_levels.grade_name as grade_name',
                DB::raw('COUNT(questions.id) as questions_count'),
            ])
            ->groupBy('subjects.id', 'subjects.name', 'grade_levels.id', 'grade_levels.grade_name')
            ->orderBy('subjects.name')
            ->orderBy('grade_levels.grade_name')
            ->get();

        return response()->json($summary);
    }
}
