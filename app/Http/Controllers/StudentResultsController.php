<?php

namespace App\Http\Controllers;

use App\Models\StudentAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentResultsController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:120',
            'type' => 'nullable|string|max:50',
            'subject' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $query = StudentAssessment::query()
            ->with([
                'assessment:id,title,type,purpose,learning_period_id,grade_subject_id',
                'assessment.gradeSubject:id,subject_id,grade_level_id',
                'assessment.gradeSubject.subject:id,name',
                'assessment.units:id,name,grade_subject_id',
                'assessment.units.gradeSubject:id,subject_id,grade_level_id',
                'assessment.units.gradeSubject.subject:id,name',
                'assessment.topics:id,topic_name,grade_subject_id,unit_id',
                'assessment.topics.gradeSubject:id,subject_id,grade_level_id',
                'assessment.topics.gradeSubject.subject:id,name',
                'assessment.learningPeriod:id,title,group_id,grade_subject_id',
                'assessment.learningPeriod.group:id,group_name',
                'assessment.groups:id,group_name',
            ])
            ->where('student_id', Auth::id())
            ->whereIn('status', ['completed', 'graded'])
            ->orderByDesc('completed_at')
            ->orderByDesc('id');

        if ($request->filled('type')) {
            $type = strtolower(trim((string) $request->type));
            $query->whereHas('assessment', fn ($q) => $q->where('type', $type));
        }

        if ($request->filled('subject')) {
            $subject = trim((string) $request->subject);
            $query->whereHas('assessment', function ($assessmentQuery) use ($subject) {
                $assessmentQuery->where(function ($q) use ($subject) {
                    $q->whereHas('gradeSubject.subject', fn ($sq) => $sq->where('name', $subject))
                        ->orWhereHas('units.gradeSubject.subject', fn ($sq) => $sq->where('name', $subject))
                        ->orWhereHas('topics.gradeSubject.subject', fn ($sq) => $sq->where('name', $subject));
                });
            });
        }

        if ($request->filled('q')) {
            $needle = trim((string) $request->q);
            $query->whereHas('assessment', function ($q) use ($needle) {
                $q->where('title', 'like', "%{$needle}%")
                    ->orWhere('type', 'like', "%{$needle}%");
            });
        }

        $perPage = (int) ($request->per_page ?: 20);
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function (StudentAssessment $item) {
            $assessment = $item->assessment;
            $subject = $assessment?->gradeSubject?->subject?->name
                ?: $assessment?->units?->first()?->gradeSubject?->subject?->name
                ?: $assessment?->topics?->first()?->gradeSubject?->subject?->name;

            $className = $assessment?->learningPeriod?->group?->group_name
                ?: $assessment?->groups?->first()?->group_name;

            $percentage = null;
            if ((float) $item->max_score > 0) {
                $percentage = round(((float) $item->score / (float) $item->max_score) * 100, 1);
            } elseif ($item->score !== null && (float) $item->score >= 0 && (float) $item->score <= 100) {
                $percentage = round((float) $item->score, 1);
            }

            return [
                'id' => $item->id,
                'assessment_id' => $item->assessment_id,
                'title' => $assessment?->title ?? 'Assessment',
                'type' => $assessment?->type,
                'purpose' => $assessment?->purpose,
                'subject' => $subject,
                'class_name' => $className,
                'learning_period' => $assessment?->learningPeriod?->title,
                'score' => $item->score,
                'max_score' => $item->max_score,
                'percentage' => $percentage,
                'status' => $item->status,
                'completed_at' => optional($item->completed_at)->toIso8601String(),
            ];
        });

        $filterSource = StudentAssessment::query()
            ->with([
                'assessment.gradeSubject.subject:id,name',
                'assessment.units.gradeSubject.subject:id,name',
                'assessment.topics.gradeSubject.subject:id,name',
            ])
            ->where('student_id', Auth::id())
            ->whereIn('status', ['completed', 'graded'])
            ->get();

        $availableSubjects = $filterSource
            ->map(function (StudentAssessment $item) {
                $assessment = $item->assessment;
                return $assessment?->gradeSubject?->subject?->name
                    ?: $assessment?->units?->first()?->gradeSubject?->subject?->name
                    ?: $assessment?->topics?->first()?->gradeSubject?->subject?->name;
            })
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $payload = $page->toArray();
        $payload['filters'] = [
            'subjects' => $availableSubjects,
            'types' => $filterSource->pluck('assessment.type')->filter()->unique()->sort()->values(),
        ];

        return response()->json($payload);
    }
}
