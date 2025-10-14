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
        $subjects = Subject::with('gradeLevels')->get();
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

    public function createSubject(Request $request)
    {
        // Validate the incoming request
        $request->validate([
            'grade_levels'       => 'required|array',
            'grade_levels.*'    => 'integer|exists:grade_levels,id',
            'name'              => 'required|string|max:255|unique:subjects,name,',
        ]);

        $subject = Subject::create([
            'name' => $request['name'],
        ]);

        foreach ($request->grade_levels as $grade_level_id) {
            GradeSubject::create([
                'grade_level_id' => $grade_level_id,
                'subject_id' => $subject->id,

            ]);
        }
        // Return the grade_subject, either newly created or existing
        return response()->json([
            'data' => $subject
        ], 201);
    }
    public function searchSubjects(Request $request)
    {
        $search = $request->input('search', '');
        $query = Subject::query();

        if (!empty($search)) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Join with grade_levels to include grade information
        $subjects = $query->with('gradeLevels')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(function ($subject) {
                // Format each subject with its grade level
                $gradeLevels = $subject->gradeLevels->pluck('name')->join(', ');

                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'grade_level' => $gradeLevels ?: 'All Levels'
                ];
            });

        return response()->json($subjects);
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
    public function getSubjectsByGrade($gradeId)
    {
        $subjects = Subject::whereHas('gradeLevels', function ($query) use ($gradeId) {
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
    public function update(Request $request, $id)
    {
        // Validate the incoming request
        $request->validate([
            'name' => 'required|string|max:255|unique:subjects,name,' . $id,
            'grade_levels' => 'required|array',
            'grade_levels.*' => 'integer|exists:grade_levels,id', // Each ID must exist
        ]);

        // Find the subject
        $subject = Subject::find($id);
        if (!$subject) {
            return response()->json(['error' => 'Subject not found'], 404);
        }

        // Update the subject name
        $subject->name = $request->name;
        $subject->save();

        // Sync the grade levels
        $subject->gradeLevels()->sync($request->grade_levels);

        return response()->json(['data' => $subject], 200);
    }
}
