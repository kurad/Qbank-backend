<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use App\Models\GradeSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopicController extends Controller
{
    public function index()
    {

        $topics = Topic::with(['gradeSubject.subject', 'gradeSubject.gradeLevel'])
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($topics);
    }
    public function createOrGet(Request $request)
    {
        // Validate the incoming request
        $validated = $request->validate([
            'grade_level_id' => 'required|exists:grade_levels,id',
            'subject_id' => 'required|exists:subjects,id',
            'topic_name' => 'required|string|max:255',
        ]);

        // Attempt to find an existing grade_subject
        $gradeSubject = GradeSubject::firstOrCreate([
            'grade_level_id' => $validated['grade_level_id'],
            'subject_id' => $validated['subject_id'],
        ]);

        // Return the grade_subject, either newly created or existing
        return response()->json($gradeSubject, 201);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'grade_level_id' => 'required|exists:grade_levels,id',
            'subject_id' => 'required|exists:subjects,id',
            'topic_name' => 'required|string|max:255',
        ]);

        // Attempt to find an existing grade_subject
        $gradeSubject = GradeSubject::firstOrCreate([
            'grade_level_id' => $validated['grade_level_id'],
            'subject_id' => $validated['subject_id'],
        ]);

        // Create the topic associated with the grade_subject

        $topic =  Topic::create([
            'grade_subject_id' => $gradeSubject->id,
            'topic_name' => $validated['topic_name'],
        ]);

        return response()->json([
            'message' => 'Topic created successfully',
            'data' => $topic->load('gradeSubject.subject', 'gradeSubject.gradeLevel'),
        ], 201);
    }
    public function update(Request $request, Topic $topic)
    {
        $validated = $request->validate([
            'topic_name' => 'required|string|max:255',
        ]);

        $topic->update([
            'topic_name' => $validated['topic_name'],
        ]);

        return response()->json([
            'message' => 'Topic updated successfully',
            'data' => $topic->load('gradeSubject.subject', 'gradeSubject.gradeLevel'),
        ], 200);
    }

    public function topicsBySubject($subjectId)
    {
        $topics = Topic::whereHas('gradeSubject', function ($query) use ($subjectId) {
            $query->where('grade_subjects.subject_id', $subjectId);
        })
            ->with(['gradeSubject.subject', 'gradeSubject.gradeLevel'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($topics);
    }

    public function topicsByGradeAndSubject($subjectId, $gradeId)
    {
        $gradeSubject = GradeSubject::where('grade_level_id', $gradeId)
            ->where('subject_id', $subjectId)
            ->first();

        if (!$gradeSubject) return response()->json([]);

        $topics = Topic::where('grade_subject_id', $gradeSubject->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($topics);
    }
    public function topicsBySubjectAndGrade($subjectId, $gradeId)
    {
        $perPage = request()->input('limit', 5);
        $page = request()->input('page', 1);

        $topics = Topic::whereHas('gradeSubject', function ($query) use ($subjectId, $gradeId) {
            $query->where('grade_subjects.subject_id', $subjectId)
                ->where('grade_subjects.grade_level_id', $gradeId);
        })
            ->with(['gradeSubject.subject', 'gradeSubject.gradeLevel'])
            ->orderBy('created_at', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($topics);
    }
    public function topicsBySubjectGrade($subjectId, $gradeId) // For Cascading selection
    {
        $topics = Topic::whereHas('gradeSubject', function ($query) use ($subjectId, $gradeId) {
            $query->where('grade_subjects.subject_id', $subjectId)
                ->where('grade_subjects.grade_level_id', $gradeId);
        })
            ->with(['gradeSubject.subject', 'gradeSubject.gradeLevel'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($topics);
    }
    public function destroy($id)
    {
        $topic = Topic::findOrFail($id);

        // Check if any questions are associated

        if($topic->questions()->exists()){
            return response()->json([
                'message' => 'Cannot delete topic with associated questions',
            ], 400);
        }
        // Delete the topic if no questions exist
        $topic->delete();

        return response()->json([
            'message' => 'Topic deleted successfully',
        ], 200);
    }
}
