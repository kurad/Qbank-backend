<?php

namespace App\Http\Controllers;

use App\Models\LearningPeriod;
use App\Services\LearningPeriodFormativeService;
use Illuminate\Http\Request;

class StudentFormativeAssessmentController extends Controller
{
    public function show(
        Request $request,
        LearningPeriod $learningPeriod,
        LearningPeriodFormativeService $service
    ) {
        $student = $request->user();

        abort_unless(
            $student && $student->role === 'student',
            403
        );

        abort_unless(
            $student->groups()
                ->where('groups.id', $learningPeriod->group_id)
                ->exists(),
            403,
            'This learning period is outside your classes.'
        );

        return response()->json([
            'data' => $service->statusForStudent(
                $student,
                $learningPeriod
            ),
        ]);
    }
}
