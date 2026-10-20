<?php

namespace App\Http\Controllers;

use App\Models\GradeSubject;
use App\Models\School;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function summary(Request $request)
    {
        $admin = $request->user();

        abort_unless($admin && $admin->role === 'admin', 403, 'Administrator access is required.');

        if (($admin->admin_scope ?? null) === 'system') {
            return $this->systemSummary();
        }

        abort_unless(
            ($admin->admin_scope ?? null) === 'school' && $admin->school_id,
            403,
            'This administrator is not attached to a school.'
        );

        return $this->schoolSummary((int) $admin->school_id);
    }

    private function systemSummary()
    {
        $schools = School::query();

        $totalClients = (clone $schools)->count();
        $activeClients = (clone $schools)->where('status', 'active')->count();
        $inactiveClients = (clone $schools)->where('status', 'inactive')->count();
        $newClients30Days = (clone $schools)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $schoolAdmins = User::query()
            ->where('role', 'admin')
            ->where('admin_scope', 'school')
            ->whereNotNull('school_id')
            ->count();

        $schoolsWithAdmin = User::query()
            ->where('role', 'admin')
            ->where('admin_scope', 'school')
            ->whereNotNull('school_id')
            ->distinct('school_id')
            ->count('school_id');

        $recentClients = School::query()
            ->orderByDesc('created_at')
            ->limit(6)
            ->get([
                'id',
                'school_name',
                'school_code',
                'district',
                'email',
                'status',
                'created_at',
            ]);

        return response()->json([
            'scope' => 'system',
            'summary' => [
                'clients_total' => $totalClients,
                'clients_active' => $activeClients,
                'clients_inactive' => $inactiveClients,
                'school_admins' => $schoolAdmins,
                'schools_with_admin' => $schoolsWithAdmin,
                'schools_without_admin' => max(0, $totalClients - $schoolsWithAdmin),
                'new_clients_30_days' => $newClients30Days,
            ],
            'recent_clients' => $recentClients,
        ]);
    }

    private function schoolSummary(int $schoolId)
    {
        $school = School::findOrFail($schoolId);

        $teacherQuery = User::query()
            ->where('role', 'teacher')
            ->where('school_id', $schoolId);

        $studentQuery = User::query()
            ->where('role', 'student')
            ->where('school_id', $schoolId);

        $subjectCount = Subject::query()
            ->where('school_id', $schoolId)
            ->count();

        $teachingAreaQuery = GradeSubject::query()
            ->where('school_id', $schoolId);

        $topicQuery = Topic::query()
            ->whereHas('gradeSubject', function ($query) use ($schoolId) {
                $query->where('school_id', $schoolId);
            });

        $recentTeachers = (clone $teacherQuery)
            ->where('teacher_approval_status', 'approved')
            ->orderByDesc('created_at')
            ->limit(4)
            ->get(['id', 'name', 'email', 'status', 'created_at']);

        $recentStudents = (clone $studentQuery)
            ->orderByDesc('created_at')
            ->limit(4)
            ->get(['id', 'name', 'email', 'status', 'created_at']);

        return response()->json([
            'scope' => 'school',
            'school' => $school->only([
                'id',
                'school_name',
                'school_code',
                'district',
                'email',
                'status',
            ]),
            'summary' => [
                'teachers' => (clone $teacherQuery)
                    ->where('teacher_approval_status', 'approved')
                    ->count(),
                'pending_teachers' => (clone $teacherQuery)
                    ->where('teacher_approval_status', 'pending')
                    ->count(),
                'students' => (clone $studentQuery)->count(),
                'active_students' => (clone $studentQuery)
                    ->where('status', 'active')
                    ->count(),
                'subjects' => $subjectCount,
                'teaching_areas' => (clone $teachingAreaQuery)->count(),
                'unassigned_teaching_areas' => (clone $teachingAreaQuery)
                    ->whereNull('teacher_id')
                    ->count(),
                'topics' => (clone $topicQuery)->count(),
                'active_topics' => (clone $topicQuery)
                    ->where('status', 'active')
                    ->count(),
            ],
            'recent_teachers' => $recentTeachers,
            'recent_students' => $recentStudents,
        ]);
    }
}
