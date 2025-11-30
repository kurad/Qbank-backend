<?php
namespace App\Http\Controllers;

use Log;
use PDF;
use App\Models\User;
use App\Models\Group;
use App\Models\Topic;
use App\Models\Question;
use App\Models\Assessment;
use Illuminate\Support\Str;
use App\Models\GradeSubject;
use Illuminate\Http\Request;
use App\Models\StudentAnswer;
use App\Models\StudentAssessment;
use App\Models\AssessmentQuestion;
use App\Models\QuestionUsage;
use Illuminate\Support\Facades\DB;
use App\Services\AssessmentAssigner;
use Illuminate\Support\Facades\Auth;
use App\Models\StudentQuestionHistory;

class AssessmentController extends Controller
{
    // List all assessments for the authenticated user      // List all assessments created by the authenticated user
    public function listCreatedAssessments()
    {
        $user = Auth::user();
        $assessments = Assessment::where('creator_id', $user->id)
            ->with(['questions.question', 'topics.gradeSubject.subject', 'topics.gradeSubject.gradeLevel'])
            ->latest()
            ->get();

        // For each assessment, get unique topics from its questions
        $createdAssessments = $assessments->map(function ($assessment) {
            $topicIds = $assessment->questions->pluck('question.topic_id')->unique()->filter();
            $topics = Topic::whereIn('id', $topicIds)->get();
            $assessment->topics = $topics;
            //Compute subject/grade-level pairs from topics

            $subjectGradeLevels = $assessment->topics->map(function ($topic) {
        $gradeSubject = $topic->gradeSubject;
        $subjectName = $gradeSubject?->subject?->name;
        $gradeLevelName = $gradeSubject?->gradeLevel?->grade_name;

        if ($subjectName && $gradeLevelName) {
            return [
                'subject' => $subjectName,
                'grade_level' => $gradeLevelName,
            ];
        }
        return null;
    })->filter()->unique()->values();

    // Optional: primary subject/grade for quick display
    $assessment->primary_subject = $subjectGradeLevels->first()['subject'] ?? null;
    $assessment->primary_grade_level = $subjectGradeLevels->first()['grade_level'] ?? null;

    // All pairs if you need them on the frontend
    $assessment->subject_grade_levels = $subjectGradeLevels;


            return $assessment;
        });
        return response()->json(['created_assessments' => $createdAssessments]);
    }
    // List all practice assessments for the authenticated student
    public function listPracticeAssessments(Request $request)
    {
        $student = Auth::user();
        if (!$student || $student->role !== 'student') {
            return response()->json(['message' => 'Only students can view their practice assessments.'], 403);
        }
        $practiceAssessments = Assessment::where('type', 'practice')
            ->whereHas('studentAssessments', function ($q) use ($student) {
                $q->where('student_id', $student->id);
            })
            ->with(['questions.question', 'subject', 'studentAssessments' => function ($q) use ($student) {
                $q->where('student_id', $student->id);
            }])
            ->latest()
            ->get();

        $practiceAssessments = $practiceAssessments->map(function ($assessment) {
            $topicIds = $assessment->questions->pluck('question.topic_id')->unique()->filter();
            $topics = Topic::whereIn('id', $topicIds)->get();
            $assessment->topics = $topics;
            // Attach student_assessment details for this student
            $studentAssessment = $assessment->studentAssessments->first();
            $assessment->student_assessment = $studentAssessment;
            // Attach student results if available
            if ($studentAssessment) {
                $assessment->student_results = [
                    'score' => $studentAssessment->score,
                    'max_score' => $studentAssessment->max_score,
                    'status' => $studentAssessment->status,
                    'completed_at' => $studentAssessment->completed_at,
                    'answers' => $studentAssessment->studentAnswers ?? []
                ];
            } else {
                $assessment->student_results = null;
            }
            unset($assessment->studentAssessments);
            return $assessment;
        });

        // Assigned assessments (non-practice types) for the student
        $assignedAssessments = Assessment::where('type', '!=', 'practice')
            ->whereHas('studentAssessments', function ($q) use ($student) {
                $q->where('student_id', $student->id);
            })
            ->with(['questions.question', 'subject', 'studentAssessments' => function ($q) use ($student) {
                $q->where('student_id', $student->id);
            }])
            ->latest()
            ->get();

        $assignedAssessments = $assignedAssessments->map(function ($assessment) {
            $topicIds = $assessment->questions->pluck('question.topic_id')->unique()->filter();
            $topics = Topic::whereIn('id', $topicIds)->get();
            $assessment->topics = $topics;
            // Attach student_assessment details for this student
            $studentAssessment = $assessment->studentAssessments->first();
            $assessment->student_assessment = $studentAssessment;
            // Attach student results if available
            if ($studentAssessment) {
                $assessment->student_results = [
                    'score' => $studentAssessment->score,
                    'max_score' => $studentAssessment->max_score,
                    'status' => $studentAssessment->status,
                    'completed_at' => $studentAssessment->completed_at,
                    'answers' => $studentAssessment->studentAnswers ?? []
                ];
            } else {
                $assessment->student_results = null;
            }
            unset($assessment->studentAssessments);
            return $assessment;
        });

        return response()->json([
            'practice_assessments' => $practiceAssessments,
            'assigned_assessments' => $assignedAssessments
        ]);
    }

    public function updateTitle($id, Request $request)
    {
        $data = $request->validate([
            'title' => ['required','string','max:255'],
        ]);

        $assessment = Assessment::find($id);
        if (!$assessment) {
            return response()->json(['message' => 'Assessment not found'], 404);
        }

        $assessment->title = $data['title'];
        $assessment->save();

        return response()->json(['assessment' => $assessment], 200);
    }
    // Get assessment details (questions) for answering
    public function show($id)
    {
        // Eager load each question and its sub-questions
        $assessment = Assessment::with([
            'questions.question.subQuestions',
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

        return response()->json([
            'assessment' => $assessment,
            'topic' => $topic,
            'subject' => $primarySubject,
            'grade_level' => $primaryGradeLevel,
            'subject_grade_levels' => $subjectGradeLevels,
            'logo_path' => $logoPath
        ]);
    }

    // Create a practice assessment for a student with all questions from a selected topic
    public function createPracticeForTopic(Request $request)
    {
        $validated = $request->validate([
            'topic_id' => 'required|exists:topics,id',
            'mode' => 'required|in:all,custom,unpracticed',
            'limit' => 'nullable|integer|min:1'
        ]);

        $student = Auth::user();
        if (!$student || $student->role !== 'student') {
            return response()->json(['message' => 'Only students can start a practice session.'], 403);
        }

        $topic = Topic::find($validated['topic_id']);
        if (!$topic) {
            return response()->json(['message' => 'Topic not found.', 404]);
        }

        // Total questions available for the topic (leaf questions only: no sub-questions)
        $totalQuestions = Question::where('topic_id', $topic->id)
            ->whereDoesntHave('subQuestions')
            ->count();

        //Business rule: if <=10, force mode = all

        if ($totalQuestions <= 10) {
            $validated['mode'] = 'all';
            $validated['limit'] = null;
        }
        // Build base query (leaf questions only)
        $questionQuery = Question::where('topic_id', $topic->id)
            ->whereDoesntHave('subQuestions');


        if ($validated['mode'] === 'unpracticed') {
            $practicedQuestionIds = StudentQuestionHistory::where('student_id', $student->id)
                ->where('topic_id', $topic->id)
                ->pluck('question_id');
            $questionQuery->whereNotIn('id', $practicedQuestionIds);
        }

        // Select questions
        $questions = match ($validated['mode']) {
            'all' => $questionQuery->get(),
            default => $questionQuery->inRandomOrder()->take($validated['limit'])->get(),
        };

        if ($questions->isEmpty()) {
            return response()->json(['message' => 'No questions found for this topic.'], 404);
        }

        // Create the title suffix
        $titleSuffix = match ($validated['mode']) {
            'all' => 'Practice All Questions',
            'custom' => "{$validated['limit']} Questions",
            'unpracticed' => "{$validated['limit']} Unpracticed Questions",
        };

        // Create the practice assessment
        $practiceAssessment = Assessment::create([
            'type' => 'practice',
            'title' => "Practice: {$titleSuffix}",
            'creator_id' => $student->id,
            'question_count' => $questions->count(),
            'due_date' => now()->addDay(), // Use due_date instead of start_time/end_time
            'is_timed' => false,
            'time_limit' => null,
        ]);
        // Attach topic via pivot
        $practiceAssessment->topics()->attach($topic->id);

        // Associate questions with the assessment
        foreach ($questions as $order => $question) {
            AssessmentQuestion::create([
                'assessment_id' => $practiceAssessment->id,
                'question_id' => $question->id,
                'order' => $order + 1,
            ]);
        }

        // Optionally, assign the assessment to the student
        $studentAssessment = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_id' => $practiceAssessment->id,
            'started_at' => now(),
        ]);

        return response()->json([
            'message' => 'Practice assessment created',
            'assessment' => $practiceAssessment,
            'student_assessment' => $studentAssessment,
        ], 201);
    }

    // Create or initiate an assessment (quiz or practice)
    public function createAssessment(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:quiz,homework,exam',
            'title' => 'required|string|max:255',
            'topic_id' => 'required|exists:topics,id',
            'delivery_mode' => 'required|in:online,offline',
            'due_date' => 'nullable|date|after_or_equal:start_time',
            'is_timed' => 'boolean',
            'time_limit' => 'nullable|integer|min:1',
            'use_default_sections' => 'sometimes|boolean',

        ]);
        // Adjust field based on delivery mode
        if ($validated['delivery_mode'] === 'online') {
            $request->validate([
                'due_date' => 'required|date|after_or_equal:today',
                'is_timed' => 'required|boolean',
                'time_limit' => 'nullable|integer|min:1',
            ]);
        } else {
            // Set fields to null/false for offline assessments
            $validated['due_date'] = null;
            $validated['is_timed'] = false;
            $validated['time_limit'] = null;
        }

        // Create the assessment
        $assessment = Assessment::create([
            'type' => $validated['type'],
            'title' => $validated['title'],
            'creator_id' => Auth::id(), // Use authenticated user ID
            'question_count' => 0, // Will be updated later
            'due_date' => $validated['due_date'] ?? now(),
            'is_timed' => $validated['is_timed'] ?? false,
            'time_limit' => $validated['time_limit'] ?? null,
            'delivery_mode' => $validated['delivery_mode'],
        ]);
        // Attach the provided topic via pivot
        $assessment->topics()->attach($validated['topic_id']);

        // Optionally create default sections for "standard" assessments
        if (!empty($validated['use_default_sections'])) {
            $defaultSections = AssessmentDefaultSection::orderBy('ordering')->get();

            foreach ($defaultSections as $default) {
                AssessmentSection::create([
                    'assessment_id' => $assessment->id,
                    'title' => $default->title,
                    'instruction' => $default->default_instruction,
                    'ordering' => $default->ordering,
                ]);
            }
        }


        return response()->json([
            'message' => 'Assessment created successfully',
            'assessment' => $assessment
        ], 201);
    }

    // Add questions to an assessment
    public function addQuestions(Request $request)
    {
        $validated = $request->validate([
            'assessment_id' => 'required|exists:assessments,id',
            'question_ids' => 'required|array|min:1',
            'question_ids.*' => 'exists:questions,id',
        ]);

        $assessment = Assessment::findOrFail($validated['assessment_id']);
        $now = now();
        $userId = auth()->id();

        // Get all current assessment_question rows for this assessment
        $existing = $assessment->questions()->pluck('id', 'question_id')->toArray(); // [question_id => assessment_question_id]
        $warnings = []; // <--- store warnings to return to frontend

        foreach (array_values($validated['question_ids']) as $order => $qid) {
            // Check usage by same user (limit = 3)
            $usageCount = QuestionUsage::where('question_id', $qid)
                ->whereHas('assessment', function($q) use ($userId) {
                    $q->where('creator_id', $userId);
                })->count();
                if ($usageCount >= 3) {
                    $warnings[] = "Question ID {$qid} has been used in 3 or more assessments created by you.";
                }
            // -------------------------------------------------------
            if (isset($existing[$qid])) {
                // Update order for existing question
                AssessmentQuestion::where('id', $existing[$qid])
                    ->update(['order' => $order + 1, 'updated_at' => $now]);
            } else {
                // Insert new question
                AssessmentQuestion::create([
                    'assessment_id' => $assessment->id,
                    'question_id' => $qid,
                    'order' => $order + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Log usage when a new question is added

                QuestionUsage::create([
                    'question_id' => $qid,
                    'assessment_id' => $assessment->id,
                    'used_at' => $now,
                ]);
            }
        }

        // Remove any questions not in the new list
        $toRemove = array_diff(array_keys($existing), $validated['question_ids']);
        if (!empty($toRemove)) {
            AssessmentQuestion::where('assessment_id', $assessment->id)
                ->whereIn('question_id', $toRemove)
                ->delete();
        }

        // Update question count
        $assessment->question_count = $assessment->questions()->count();
        $assessment->save();

        return response()->json([
            'message' => 'Questions added/updated successfully',
            'warnings' => $warnings,
            'assessment' => $assessment->load('questions.question')
        ], 200);
    }
    public function getAssessmentQuestions($id)
    {
        $assessment = Assessment::with('questions')->findOrFail($id);
        return response()->json(['questions' => $assessment->questions]);
    }

    public function removeQuestion($assessmentId, $questionId)
    {
        AssessmentQuestion::where('assessment_id', $assessmentId)
            ->where('question_id', $questionId)
            ->delete();

        // Update count
        $assessment = Assessment::findOrFail($assessmentId);
        $assessment->question_count = $assessment->questions()->count();
        $assessment->save();

        return response()->json(['message' => 'Question removed']);
    }
    // Assign an assessment (quiz or practice) to a student or a group of students
    public function assign(Request $request, AssessmentAssigner $assigner)
    {
        $request->validate([
            'assessment_id' => 'required|exists:assessments,id',
            'student_id' => 'nullable|exists:users,id',
            'grade_level_id' => 'nullable|exists:grade_levels,id',
        ]);

        $assessmentId = $request->assessment_id;
        $assignedCount = 0;

        if ($request->filled('student_id')) {
            $success = $assigner->assignToStudent($request->student_id, $assessmentId);

            return response()->json([
                'message' => $success
                    ? 'Assessment assigned to student'
                    : 'Student already has this assessment',
                'assigned_count' => $success ? 1 : 0,
            ]);
        }

        if ($request->filled('grade_level_id')) {
            $assignedCount = $assigner->assignToGrade($request->grade_level_id, $assessmentId);

            return response()->json([
                'message' => "Assessment assigned to {$assignedCount} students",
                'assigned_count' => $assignedCount,
            ]);
        }

        return response()->json([
            'error' => 'You must provide either student_id or grade_level_id',
        ], 422);
    }

    public function assignGroup(Request $request, $assessmentId)
    {
        $validated = $request->validate([
            'group_id' => 'required|exists:groups,id',
        ]);

        $assessment = Assessment::findOrFail($assessmentId);
        $group = Group::with('students')->findOrFail($validated['group_id']);

        // Attach all group students to this assessment
        foreach ($group->students as $student) {
            $assessment->students()->syncWithoutDetaching([$student->id]);
        }

        return response()->json(['message' => 'Group assigned successfully']);
    }


    // Start a practice assessment for the authenticated student
    public function startPractice(Request $request)
    {
        $request->validate([
            'assessment_id' => 'required|exists:assessments,id',
        ]);

        $assessment = Assessment::where('id', $request->assessment_id)->where('type', 'practice')->firstOrFail();
        $student = Auth::user();
        $studentAssessment = StudentAssessment::create([
            'student_id' => $student->id,
            'assessment_id' => $assessment->id,
            'started_at' => now(),
        ]);

        return response()->json(['message' => 'Practice started', 'student_assessment' => $studentAssessment]);
    }

    public function showResults($studentAssessmentId)
    {
        $studentAssessment = StudentAssessment::with(['assessment', 'studentAnswers.question'])
            ->findOrFail($studentAssessmentId);

        // Ensure the authenticated user is authorized to view these results
        if ($studentAssessment->student_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Ensure the assessment is completed
        if ($studentAssessment->status !== 'completed') {
            return response()->json(['message' => 'Assessment not completed yet'], 400);
        }

        $assessment = $studentAssessment->assessment;
        $studentAnswers = $studentAssessment->studentAnswers;

        // Convert completed_at and started_at to Carbon instances if they are strings
        $completedAt = $studentAssessment->completed_at;
        $startedAt = $studentAssessment->started_at;
        if ($completedAt && is_string($completedAt)) {
            $completedAt = \Carbon\Carbon::parse($completedAt);
        }
        if ($startedAt && is_string($startedAt)) {
            $startedAt = \Carbon\Carbon::parse($startedAt);
        }

        $results = [
            'assessment_title' => $assessment->title,
            'assessment_type' => $assessment->type,
            'total_questions' => $assessment->question_count,
            'score' => $studentAssessment->score,
            'max_score' => $studentAssessment->max_score,
            'percentage' => $studentAssessment->max_score > 0
                ? ($studentAssessment->score / $studentAssessment->max_score) * 100
                : 0,
            'completed_at' => $studentAssessment->completed_at,
            'time_taken' => ($completedAt && $startedAt)
                ? $completedAt->diffInMinutes($startedAt)
                : null,
            'questions' => []
        ];

        foreach ($studentAnswers as $answer) {
            $question = $answer->question;
            $results['questions'][] = [
                'question_text' => $question->question,
                'question_type' => $question->question_type,
                'student_answer' => $answer->answer,
                'correct_answer' => $question->correct_answer,
                'is_correct' => $answer->is_correct,
                'points_earned' => $answer->points_earned,
                'max_points' => $question->marks,
                'explanation' => $question->explanation
            ];
        }

        return response()->json($results);
    }

    public function getPracticeQuestions(Request $request)
    {
        $request->validate([
            'topic_id' => 'required|exists:topics,id',
            'mode' => 'required|in:all,custom,unpracticed',
            'limit' => 'required_if:mode,custom,unpracticed|integer|min:1'
        ]);

        $topicId = $request->topic_id;
        $mode = $request->mode;
        $studentId = auth()->id(); // Assume authenticated student

        if ($mode === 'all') {
            $questions = Question::where('topic_id', $topicId)
                ->whereDoesntHave('subQuestions')
                ->get();
        } elseif ($mode === 'custom') {
            $questions = Question::where('topic_id', $topicId)
                ->whereDoesntHave('subQuestions')
                ->inRandomOrder()
                ->take($request->limit)
                ->get();
        } else {
            // Unpracticed questions only
            $practicedQuestionIds = StudentQuestionHistory::where('student_id', $studentId)
                ->pluck('question_id');

            $questions = Question::where('topic_id', $topicId)
                ->whereDoesntHave('subQuestions')
                ->whereNotIn('id', $practicedQuestionIds)
                ->inRandomOrder()
                ->take($request->limit)
                ->get();
        }

        return response()->json($questions);
    }
    public function reorderQuestions(Request $request, $id)
    {
        $request->validate([
            'questions' => 'required|array',
            'questions.*.id' => 'required|exists:assessment_questions,id',
            'questions.*.position' => 'required|integer|min:1',
        ]);
        $assessment = Assessment::findOrFail($id);
        $user = Auth::user();

        // Verify the user is the creator of the assessment
        if ($assessment->creator_id !== $user->id) {
            return response()->json([
                'message' => 'You are not authorized to reorder questions for this assessment.',
            ], 403);
        }

        try {
            DB::beginTransaction();
            foreach ($request->questions as $item) {
                AssessmentQuestion::where('id', $item['id'])
                    ->where('assessment_id', $id)
                    ->update(['order' => $item['position']]);
            }
            DB::commit();
            return response()->json([
                'message' => 'Questions reordered successfully',
                'assessment' => $assessment->load('questions')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to reorder questions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a PDF version of the assessment
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function generatePdf($id)
    {
        $assessment = Assessment::with([
            'questions.question',
            'topics.gradeSubject.subject',
            'creator.school'
        ])
            ->findOrFail($id);

        // Check if user is authorized to view this assessment
        if ($assessment->creator_id !== Auth::id()) {
            return response()->json([
                'message' => 'You are not authorized to view this assessment.',
            ], 403);
        }

        // Get school details
        $school = $assessment->creator->school ?? null;

        $subjects = $assessment->topics->map(function ($topic) {
            return $topic->gradeSubject?->subject?->name;
        })->filter()->unique()->values();
        // Format the data for the PDF
        $data = [
            'title' => $assessment->title,
            'subject' => $subjects->first() ?? 'General',
            'topic' => $assessment->topic ? $assessment->topic->name : 'General',
            'created_at' => $assessment->created_at->format('F j, Y'),
            'school' => [
                'school_name' => $school?->school_name ?? 'School Name',
                'address' => $school?->address ?? 'School Address',
                'phone' => $school?->phone ?? 'Phone Number',
                'email' => $school?->email ?? 'school@example.com',
                'logo' => $school && $school->logo_path ? storage_path('app/public/' . $school->logo_path) : null,
            ],
            'questions' => [],
            'total_marks' => 0
        ];

        // Process each question
        foreach ($assessment->questions->sortBy('order') as $index => $aq) {
            $question = $aq->question;
            if (!$question) continue;

            // Get the local file path for the question image if it exists
            $imagePath = null;
            if ($question->question_image) {
                $relativePath = ltrim($question->question_image, '/');
                $imagePath = storage_path('app/public/' . $relativePath);
                // Only include the path if the file exists
                if (!file_exists($imagePath)) {
                    $imagePath = null;
                }
            }

            $formattedQuestion = [
                'number' => $index + 1,
                'text' => $question->question,
                'marks' => $question->marks ?? 1,
                'type' => $question->question_type,
                'image' => $imagePath,
                'options' => []
            ];

            // Handle different question types
            if ($question->question_type === 'true_false') {
                $formattedQuestion['options'] = [
                    ['text' => 'True', 'is_correct' => $question->correct_answer === 'True'],
                    ['text' => 'False', 'is_correct' => $question->correct_answer === 'False']
                ];
            } else {
                // For MCQ questions
                try {
                    $rawOptions = $question->options;
                    if (is_string($rawOptions)) {
                        $options = json_decode($rawOptions, true) ?? [];
                    } elseif (is_array($rawOptions)) {
                        $options = $rawOptions;
                    } else {
                        $options = [];
                    }
                    $formattedQuestion['options'] = array_map(function ($option) use ($question) {
                        return [
                            'text' => is_array($option) ? ($option['text'] ?? '') : $option,
                            'is_correct' => $option === $question->correct_answer
                        ];
                    }, $options);
                } catch (\Exception $e) {
                    $formattedQuestion['options'] = [];
                }
            }

            $data['questions'][] = $formattedQuestion;
            $data['total_marks'] += $formattedQuestion['marks'];
        }

        // Generate PDF
        $pdf = PDF::loadView('assessments.pdf', $data);

        // Return the PDF as a download
        $filename = 'assessment-' . Str::slug($assessment->title) . '.pdf';
        return $pdf->download($filename);
    }


    function renderKatex($latex)
    {
        // escape quotes to avoid shell issues
        $latex = escapeshellarg($latex);
        $html = shell_exec("katex --inline $latex 2>&1");
        return $html ?: $latex; // fallback to raw LaTeX if rendering fails
    }

    /**
     * Render chemistry equations using KaTeX with mhchem extension
     * 
     * @param string $equation The chemistry equation to render
     * @return string The rendered HTML
     */

    /**
     * Generate a student version of the assessment PDF (without answers)
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function generatePdfStudent1($id)
    {
        $assessment = Assessment::with([
            'questions.question',
            'subject',

            'creator.school' // Eager load the creator's school
        ])
            ->findOrFail($id);

        // Check if user is authorized to view this assessment
        $user = Auth::user();
        if ($assessment->creator_id !== $user->id) {
            return response()->json([
                'message' => 'You are not authorized to view this assessment.',
            ], 403);
        }

        // Get school details
        $school = $assessment->creator->school ?? null;

        // Format the data for the PDF
        $data = [
            'title' => $assessment->title . ' - Question Paper',
            'subject' => $assessment->subject ? $assessment->subject->name : 'General',
            'topic' => $assessment->topic ? $assessment->topic->name : 'General',
            'created_at' => $assessment->created_at->format('F j, Y'),
            'school' => [
                'school_name' => $school->school_name ?? 'School Name',
                'address' => $school->address ?? 'School Address',
                'phone' => $school->phone ?? 'Phone Number',
                'email' => $school->email ?? 'school@example.com',
                'logo' => $school->logo_path ? storage_path('app/public/' . $school->logo_path) : null,
            ],
            'questions' => [],
            'is_student_version' => true,
            'total_marks' => 0
        ];

        // Process questions and render math
        foreach ($assessment->questions->sortBy('order') as $index => $aq) {
            $question = $aq->question;
            if (!$question) continue;

            $renderedText = $question->question;
            if ($question->is_math) {
                $renderedText = preg_replace_callback('/\\\((.*?)\\\)/', function ($matches) {
                    return $this->renderKatex($matches[1]);
                }, $question->question);
            } elseif ($question->is_chemistry) {
                $renderedText = preg_replace_callback('/\\\[(.*?)\\\]/', function ($matches) {
                    return $this->renderChemistry($matches[1]);
                }, $question->question);
            }
            // Render options for MCQ/ True-False

            $options = [];
            if (in_array($question->question_type, ['mcq', 'true_false'])) {
                if ($question->question_type === 'true_false') {
                    $options = [
                        ['text' => 'True'],
                        ['text' => 'False'],
                    ];
                } else {
                    $rawOptions = json_decode($question->options, true) ?? [];
                    foreach ($rawOptions as $opt) {
                        if ($question->is_math) {
                            $opt = preg_replace_callback('/\\\\\((.*?)\\\\\)/', function ($m) {
                                return $this->renderKatex($m[1]);
                            }, $opt);
                        } elseif ($question->is_chemistry) {
                            $opt = preg_replace_callback('/\\\\\[(.*?)\\\\\]/', function ($m) {
                                return $this->renderChemistry($m[1]);
                            }, $opt);
                        }
                        $options[] = ['text' => $opt];
                    }
                }
            }

            // Get the local file path for the question image if it exists
            $imagePath = null;
            if ($question->question_image) {
                $relativePath = ltrim($question->question_image, '/');
                $imagePath = storage_path('app/public/' . $relativePath);
                // Only include the path if the file exists
                if (!file_exists($imagePath)) {
                    $imagePath = null;
                }
            }

            $formattedQuestion = [
                'number' => $index + 1,
                'text' => $question->question,
                'type' => $question->question_type,
                'marks' => $question->marks ?? 1,
                'image' => $imagePath,
                'options' => []
            ];

            // For all question types, include options without correctness indicators
            if (in_array($question->question_type, ['true_false', 'mcq'])) {
                if ($question->question_type === 'true_false') {
                    $formattedQuestion['options'] = [
                        ['text' => 'True'],
                        ['text' => 'False']
                    ];
                } else {
                    // For MCQ questions
                    try {
                        $options = json_decode($question->options, true) ?? [];
                        $formattedQuestion['options'] = array_map(function ($option) {
                            return ['text' => $option];
                        }, $options);
                    } catch (\Exception $e) {
                        $formattedQuestion['options'] = [];
                    }
                }
            }

            $data['questions'][] = $formattedQuestion;
            $data['total_marks'] += $formattedQuestion['marks'];
        }

        // Generate PDF using a student-specific view
        $pdf = PDF::loadView('assessments.pdf_student', $data);

        // Set PDF options
        $pdf->setPaper('a4');
        $pdf->setOption('margin-top', 20);
        $pdf->setOption('margin-bottom', 20);
        $pdf->setOption('margin-left', 15);
        $pdf->setOption('margin-right', 15);

        // Return the PDF as a download
        $filename = 'student-assessment-' . Str::slug($assessment->title) . '.pdf';
        return $pdf->download($filename);
    }

    public function generatePdfStudent($id)
    {
        $assessment = Assessment::with(['questions.question', 'topics.gradeSubject.subject', 'creator.school'])->findOrFail($id);

        // Authorization
        $user = Auth::user();
        if ($assessment->creator_id !== $user->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        $school = $assessment->creator->school ?? null;

        // Get all subjects for the assessment's topics
        $subjects = $assessment->topics->map(function ($topic) {
            return $topic->gradeSubject?->subject?->name;
        })->filter()->unique()->values();
        $data = [
            'title' => $assessment->title . ' - Question Paper',
            'subject' => $subjects->first() ?? 'General',
            'created_at' => $assessment->created_at->format('F j, Y'),
            'school' => [
                'school_name' => $school?->school_name ?? 'School Name',
                'address' => $school?->address ?? 'School Address',
                'phone' => $school?->phone ?? 'Phone Number',
                'email' => $school?->email ?? 'school@example.com',
                'logo' => $school && $school->logo_path ? storage_path('app/public/' . $school->logo_path) : null,
            ],
            'questions' => [],
            'total_marks' => 0,
        ];

        foreach ($assessment->questions->sortBy('order') as $index => $aq) {
            $question = $aq->question;
            if (!$question) continue;

            // Render math or chemistry in question text
            $renderedText = $question->question;
            if ($question->is_math) {
                $renderedText = preg_replace_callback('/\\\((.*?)\\\)/', function ($matches) {
                    return $this->renderKatex($matches[1]);
                }, $question->question);
            } elseif ($question->is_chemistry) {
                $renderedText = preg_replace_callback('/\\\[(.*?)\\\]/', function ($matches) {
                    return $this->renderChemistry($matches[1]);
                }, $question->question);
            }

            // Prepare options with math rendering if needed
            $options = [];
            if (in_array($question->question_type, ['mcq', 'true_false'])) {
                if ($question->question_type === 'true_false') {
                    $options = [
                        ['text' => 'True'],
                        ['text' => 'False'],
                    ];
                } else {
                    $rawOptions = $question->options;
                    if (is_string($rawOptions)) {
                        $decodedOptions = json_decode($rawOptions, true) ?? [];
                    } elseif (is_array($rawOptions)) {
                        $decodedOptions = $rawOptions;
                    } else {
                        $decodedOptions = [];
                    }
                    foreach ($decodedOptions as $opt) {
                        $optText = $opt;
                        if ($question->is_math) {
                            $optText = preg_replace_callback('/\\\((.*?)\\\)/', function ($m) {
                                return $this->renderKatex($m[1]);
                            }, $opt);
                        } elseif ($question->is_chemistry) {
                            $optText = preg_replace_callback('/\\\[(.*?)\\\]/', function ($m) {
                                return $this->renderChemistry($m[1]);
                            }, $opt);
                        }
                        $options[] = ['text' => $optText];
                    }
                }
            }

            $data['questions'][] = [
                'number' => $index + 1,
                'text' => $renderedText,
                'type' => $question->question_type,
                'marks' => $question->marks ?? 1,
                'options' => $options,
            ];

            $data['total_marks'] += $question->marks ?? 0;
        }

        $pdf = PDF::loadView('assessments.pdf_student', $data);
        $pdf->setPaper('a4');
        $pdf->setOption('margin-top', 20);
        $pdf->setOption('margin-bottom', 20);
        $pdf->setOption('margin-left', 15);
        $pdf->setOption('margin-right', 15);

        $filename = 'student-assessment-' . Str::slug($assessment->title) . '.pdf';
        return $pdf->download($filename);
    }


    public function assignedAssessments(Request $request)
    {
        $studentId = $request->user()->id;
        // Only return assessments assigned to the student (not created by them)
        $assignedAssessments = StudentAssessment::with([
            'assessment' => function ($query) {
                $query->select('id', 'type', 'title', 'subject_id', 'creator_id', 'due_date', 'time_limit')
                    ->with('subject:id,name', 'creator:id,name');
            }
        ])
            ->where('student_id', $studentId)
            ->whereHas('assessment', function ($q) use ($studentId) {
                $q->where('creator_id', '!=', $studentId);
            })
            ->orderByDesc('assigned_at')
            ->paginate(5);

        return response()->json($assignedAssessments);
    }
    /**
     * Render chemistry equations using KaTeX with mhchem extension
     *
     * @param string $latex The LaTeX content to render
     * @return string The rendered HTML
     */
    private function renderChemistry($latex)
    {
        // If the equation is not already wrapped in \ce{}, wrap it
        if (strpos($latex, '\\ce{') === false) {
            $latex = '\\ce{' . $latex . '}';
        }

        // Escape the LaTeX for shell execution
        $escapedLatex = escapeshellarg($latex);

        // Use KaTeX with the mhchem extension to render the chemistry equation
        // The --trust flag is needed to allow the mhchem extension
        // The --macros flag is used to define macros for chemistry notation
        $html = shell_exec("katex --trust --macros '{\"\\\\ce\":{\"\\\\ce\":\"true\"}}' $escapedLatex 2>&1");

        return $html ?: $latex;
    }

    public function practice(Request $request)
    {
        $studentId = $request->user()->id;
        // Only return practice assessments initiated by the student
        $practiceAssessments = Assessment::with([
            'subject:id,name',
            'subject.topic:id,subject_id,topic_name',
            'creator:id,name',
            'studentAssessments' => function ($q) use ($studentId) {
                $q->where('student_id', $studentId);
            }
        ])
            ->where('type', 'practice')
            ->where('creator_id', $studentId)
            ->orderByDesc('created_at')
            ->paginate(5);

        // Attach the StudentAssessment (like assignedAssessments)
        $practiceAssessments->getCollection()->transform(function ($assessment) use ($studentId) {
            $studentAssessment = $assessment->studentAssessments->first();
            $assessment->student_assessment = $studentAssessment;
            unset($assessment->studentAssessments);
            return $assessment;
        });

        return response()->json($practiceAssessments);
    }
        /**
     * Delete an assessment by ID
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $assessment = Assessment::findOrFail($id);
        // Only the creator can delete
        if ($assessment->creator_id !== $user->id) {
            return response()->json(['message' => 'You are not authorized to delete this assessment.'], 403);
        }

        // Count related topics and questions
        $topicCount = $assessment->topics()->count();
        $questionCount = $assessment->questions()->count();

        // Detach topics (assessment_topic pivot)
        $assessment->topics()->detach();
        // Delete assessment_questions
        $assessment->questions()->delete();

        // Delete the assessment
        $assessment->delete();

        return response()->json([
            'message' => 'Assessment and all related topics/questions deleted successfully.',
            'topics_unlinked' => $topicCount,
            'questions_deleted' => $questionCount
        ]);
    }
}
