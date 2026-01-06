<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\StudentAssessment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AssessmentAssigner
{
    public function assignToStudent(int $studentId, int $assessmentId): bool
    {
        $alreadyAssigned = StudentAssessment::where('student_id', $studentId)
            ->where('assessment_id', $assessmentId)
            ->exists();

        if ($alreadyAssigned) {
            return false;
        }

        StudentAssessment::create([
            'student_id' => $studentId,
            'assessment_id' => $assessmentId,
            'assigned_by' => Auth::id(),
            'assigned_at' => now(),
        ]);

        return true;
    }

    public function assignToGrade(int $gradeLevelId, int $assessmentId): int
    {
        $students = \App\Models\User::where('grade_level_id', $gradeLevelId)
            ->where('role', 'student')
            ->pluck('id');

        $count = 0;

        foreach ($students as $studentId) {
            if ($this->assignToStudent($studentId, $assessmentId)) {
                $count++;
            }
        }

        return $count;
    }
    public function questionsForPractice($id)
    {
        // Eager load each question and its sub-questions
        $assessment = Assessment::with([
            'questions.question.subQuestions',
            'questions.question.parent',
            'topics.gradeSubject.subject',
            'topics.gradeSubject.gradeLevel',
            'creator.school'
        ])->findOrFail($id);

        // Get all subject/grade level pairs for the assessment's topics
        $subjectGradeLevels = $assessment->topics->map(function ($topic) {
            $gradeSubject = $topic->gradeSubject;
            $subjectName = $gradeSubject?->subject?->name;
            $gradeLevelName = $gradeSubject?->gradeLevel?->grade_name;
            if ($subjectName && $gradeLevelName) {
                return [
                    'subject' => $subjectName,
                    'grade_level' => $gradeLevelName
                ];
            }
            return null;
        })->filter()->unique()->values();

        $student = Auth::user();
        $studentAssessment = StudentAssessment::where('assessment_id', $assessment->id)
            ->where('student_id', $student->id)
            ->first();
        $assessment->student_assessment_id = $studentAssessment ? $studentAssessment->id : null;

        // Get topic object from the first question's topic (if available)
        $topic = null;
        if ($assessment->questions && $assessment->questions->count() > 0) {
            $firstQuestion = $assessment->questions->first()->question;
            if ($firstQuestion && $firstQuestion->topic) {
                $topic = $firstQuestion->topic;
            }
        }

        // Use the first subject/grade level as primary, or fallback
        $primarySubject = $subjectGradeLevels->first()['subject'] ?? 'General';
        $primaryGradeLevel = $subjectGradeLevels->first()['grade_level'] ?? null;

        // Get logo_path from creator's school if available
        $logoPath = $assessment->creator && $assessment->creator->school ? $assessment->creator->school->logo_path : null;

        // Normalize questions: return parent questions with nested sub_questions
        $normalizedQuestions = [];

        // Ensure assessment questions are processed in stable order (AssessmentQuestion.order)
        $assessmentQuestions = $assessment->questions->sortBy(function ($aq) {
            return $aq->order ?? 0;
        })->values();

        $seenParents = [];
        foreach ($assessmentQuestions as $aq) {
            $q = $aq->question;
            if (! $q) continue;
            // CASE 1: Parent question directely attached

            if (!$q->parent_question_id) {
                $item = $this->normalizeQuestion($q, $aq->order ?? null);

                $item['sub_questions'] = $q->subQuestions
                    ->sortBy('id')
                    ->map(fn($sq) => $this->normalizeQuestion($sq, $aq->order ?? null))
                    ->values();

                $normalizedQuestions[] = $item;
                $seenParents[$q->id] = true;
                continue;
            }

            // CASE 2: Child attached but parent missing

            $parent = $q->parentQuestion;

            if ($parent && empty($seenParents[$parent->id])) {
                $item = $this->normalizeQuestion($parent, $aq->order ?? null);

                $item['sub_questions'] = $parent->subQuestions
                    ->sortBy('id')
                    ->map(fn($sq) => $this->normalizeQuestion($sq, $aq->order ?? null))
                    ->values();

                $normalizedQuestions[] = $item;
                $seenParents[$parent->id] = true;
            }
        }

        // Replace the questions relation in the returned assessment with normalized structure
        $assessment->questions = collect($normalizedQuestions);

        return [
            'assessment' => $assessment,
            'topic' => $topic,
            'subject' => $primarySubject,
            'grade_level' => $primaryGradeLevel,
            'subject_grade_levels' => $subjectGradeLevels,
            'logo_path' => $logoPath
        ];
    }

    private function normalizeOptions($options)
    {
        if (isset($options) && is_string($options)) {
            $decoded = json_decode($options, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $options = $decoded;
            } else {
                Log::warning('Failed to decode options JSON: ' . json_last_error_msg());
                $options = [];
            }
        }

        if (is_array($options)) {
            $options = array_map(function ($opt) {
                if (is_array($opt)) {
                    if (!empty($opt['image'])) {
                        $opt['image_url'] = asset('storage/' . $opt['image']);
                    }
                    return $opt;
                }
                return $opt;
            }, $options);
        }

        return $options;
    }

    private function normalizeQuestion($question, $assessmentOrder = null)
    {
        $item = $question->toArray();
        $item['assessment_order'] = $assessmentOrder;
        $item['options'] = $this->normalizeOptions($item['options'] ?? null);
        $item['question_image_url'] = $question->question_image ? asset('storage/' . $question->question_image) : null;
        $item['correct_answer_image_url'] = $question->correct_answer_image ? asset('storage/' . $question->correct_answer_image) : null;
        return $item;
    }
}
