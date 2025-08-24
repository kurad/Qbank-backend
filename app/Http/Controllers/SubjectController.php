<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use App\Models\Subject;
use App\Models\GradeLevel;
use App\Models\GradeSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubjectController extends Controller
{
    public function index()
    {
        $subjects = Subject::all();
        return response()->json($subjects);
    }
    public function subjectsByGrade($gradeId)
{
    $gradeSubjects = GradeSubject::with(['subject', 'gradeLevel', 'topics.questions'])
        ->where('grade_level_id', $gradeId)
        ->get();

    return $gradeSubjects->map(function ($gs) {
        return [
            'id' => $gs->subject->id,
            'name' => $gs->subject->name,
            'grade_level' => $gs->gradeLevel->grade_name,
            'topics_count' => $gs->topics->count(),
            'questions_count' => $gs->topics->sum(fn($t) => $t->questions->count()),
        ];
    });
}

    public function subjectWithTopicsAndQuestions()
    {
         $subjects = Subject::with(['gradeLevel', 'topics.questions'])
            ->get()
            ->map(function ($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'grade_level' => $subject->gradeLevel->name ?? null,
                    'topics_count' => $subject->topics->count(),
                    'questions_count' => $subject->topics->sum(fn($t) => $t->questions->count()),
                ];
            });

        return response()->json($subjects);
    
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:subjects,name',
        ]);
        $subject = Subject::create($validated);
        return response()->json($subject, 201);
    }
    public function getSubjectsByGrade($gradeId) {
        $subjects = Subject::whereHas('gradeLevels', function($query) use ($gradeId){
            $query->where('grade_levels.id', $gradeId);
        })->get();
        return response()->json($subjects);
    }
    public function gradesForSubject($subjectId)
    {
        // Get all grades that have topics for this subject (via grade_topic pivot)
        $gradeIds = DB::table('grade_topic')
            ->join('topics', 'grade_topic.topic_id', '=', 'topics.id')
            ->where('topics.subject_id', $subjectId)
            ->pluck('grade_topic.grade_level_id')
            ->unique();

        $grades = \App\Models\GradeLevel::whereIn('id', $gradeIds)->get();
        return response()->json($grades);
    }

    public function topicsForSubjectAndGrade($subjectId, $gradeId)
    {
        // Get all topics for this subject and grade (via grade_topic pivot)
        $topicIds = DB::table('grade_topic')
            ->join('topics', 'grade_topic.topic_id', '=', 'topics.id')
            ->where('topics.subject_id', $subjectId)
            ->where('grade_topic.grade_level_id', $gradeId)
            ->pluck('topics.id');

        $topics = Topic::whereIn('id', $topicIds)->get();
        return response()->json($topics);
    }
    }
