<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LearningPeriod;
use App\Models\User;
use App\Services\TeacherProgressService;
use Illuminate\Http\Request;

class TeacherProgressController extends Controller
{
    public function __construct(
        protected TeacherProgressService $progress
    ) {}

    public function dashboard(Request $request)
    {
        return response()->json([
            'data' => $this->progress->dashboard(
                $request->user(),
                $request->only([
                    'grade_subject_id',
                    'learning_period_id',
                    'group_id',
                ])
            ),
        ]);
    }

    /**
     * Teaching areas available to this teacher.
     *
     * These are GradeSubjects, e.g.
     * Computer Science - Senior 5.
     */
    public function subjects(Request $request)
    {
        return response()->json([
            'data' => $this->progress->subjects(
                $request->user()
            ),
        ]);
    }

    /**
     * Learning periods available within the teacher's scope.
     */
    public function learningPeriods(Request $request)
    {
        return response()->json([
            'data' => $this->progress->learningPeriods(
                $request->user(),
                $request->only([
                    'grade_subject_id',
                    'group_id',
                ])
            ),
        ]);
    }

    /**
     * Student-by-student progress.
     */
    public function students(Request $request)
    {
        return response()->json([
            'data' => $this->progress
                ->students(
                    $request->user(),
                    $request->only([
                        'grade_subject_id',
                        'learning_period_id',
                        'group_id',
                    ])
                )
                ->values(),
        ]);
    }

    /**
     * Detailed analytics for one learning period.
     */
    public function learningPeriod(
        Request $request,
        LearningPeriod $learningPeriod
    ) {
        return response()->json([
            'data' => $this->progress->learningPeriod(
                $request->user(),
                $learningPeriod,
                $request->only([
                    'group_id',
                ])
            ),
        ]);
    }

    /**
     * Detailed analytics for one student.
     */
    public function student(
        Request $request,
        User $student
    ) {
        return response()->json([
            'data' => $this->progress->student(
                $request->user(),
                $student,
                $request->only([
                    'grade_subject_id',
                    'learning_period_id',
                    'group_id',
                ])
            ),
        ]);
    }

    /**
     * Recent tutor activity within the teacher's scope.
     */
    public function activity(Request $request)
    {
        $limit = max(
            1,
            min($request->integer('limit', 30), 100)
        );

        return response()->json([
            'data' => $this->progress->activity(
                $request->user(),
                $request->only([
                    'grade_subject_id',
                    'learning_period_id',
                    'group_id',
                ]),
                $limit
            ),
        ]);
    }
}