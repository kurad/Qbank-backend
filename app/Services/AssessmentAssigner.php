<?php
namespace App\Services;

use App\Models\StudentAssessment;
use Illuminate\Support\Facades\Auth;

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
}