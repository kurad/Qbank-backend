<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Question;
use App\Models\Assessment;
use App\Models\StudentAssessment;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\Topic;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function statistics()
    {
        $user = Auth::user(); // get currently logged-in user
         // Total students
        $totalStudents = User::where('role', 'student')->count(); // assuming role 1 = student

        // Total practices for this user only
        $totalPractices = StudentAssessment::where('student_id', $user->id)
            ->whereHas('assessment', function($q) {
                $q->where('type', 'practice');
            })
            ->count();
        // Total questions
        $totalQuestions = Question::count();

        // Total assignments assigned to this user
        $totalAssignments = StudentAssessment::where('student_id', $user->id)
            ->whereHas('assessment', function($q) {
                $q->where('type', 'assigned');
            })
            ->count();

        return response()->json([
            'totalStudents' => $totalStudents,
            'totalPractices' => $totalPractices,
            'totalQuestions' => $totalQuestions,
            'totalAssignments' => $totalAssignments,
        ]);
    }

    public function subjectsOverview()
    {
        $subjects = Subject::withCount(['topics', 'questions'])
            ->with(['topics.gradeLevels:id,grade_name']) // eager load grade levels
            ->select('id', 'name')
            ->get()
            ->flatMap(function ($subject) {
                // Get all grade levels for this subject's topics
                $gradeLevels = $subject->topics
                    ->flatMap(fn($topic) => $topic->gradeLevels)
                    ->unique('id');

                // If no grade levels, return the subject as is
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

                // Create a separate entry for each grade level
                return $gradeLevels->map(function ($grade) use ($subject) {
                    // Count topics and questions for this specific subject-grade combination
                    $topicsCount = $subject->topics->filter(function($topic) use ($grade) {
                        return $topic->gradeLevels->contains('id', $grade->id);
                    })->count();

                    $questionsCount = $subject->questions()
                        ->whereHas('topic.gradeLevels', function($q) use ($grade) {
                            $q->where('grade_levels.id', $grade->id);
                        })
                        ->count();

                    return [
                        'id' => $subject->id . '-' . $grade->id, // Combine subject and grade IDs for uniqueness
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
            'grade_id' => 'sometimes|exists:grade_levels,id'
        ]);

        $query = $subject->topics()
            ->withCount('questions')
            ->select('id', 'topic_name', 'subject_id');

        // If grade_id is provided, filter topics by grade level
        if ($request->has('grade_id')) {
            $query->whereHas('gradeLevels', function($q) use ($request) {
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
            ->select('id', 'question', 'question_type', 'difficulty_level','options','is_math')
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
        // Left-join from subjects so subjects with zero questions are included
        // Schema path: subjects -> grade_subjects (subject_id) -> topics (grade_subject_id) -> questions (topic_id)
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
