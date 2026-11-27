<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Weekly monitoring picture of one school for its administrator.
 *
 * Read-only. Everything is derived from existing activity (assessment
 * submissions, practice, tutor sessions, formative attempts), so no teacher
 * has to report anything. The week is the last 7 days compared with the 7
 * days before it.
 */
class SchoolInsightsService
{
    private const SUBMITTED = ['completed', 'graded', 'under_review'];
    private const SCORED = ['completed', 'graded'];
    private const LOW_AVERAGE = 50;
    private const INACTIVE_DAYS = 14;
    private const MAX_ATTENTION_ROWS = 40;

    public function forSchool(int $schoolId): array
    {
        $now = now();
        $weekStart = $now->copy()->subDays(7);
        $prevStart = $now->copy()->subDays(14);

        // ---- classes and people in this school ------------------------------
        $groups = Group::query()
            ->with([
                'creator:id,name',
                'gradeSubject:id,subject_id,grade_level_id',
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ])
            ->where(function ($q) use ($schoolId) {
                $q->whereHas('creator', fn ($c) => $c->where('school_id', $schoolId))
                    ->orWhereHas('gradeSubject', fn ($g) => $g->where('school_id', $schoolId));
            })
            ->get();

        $groupIds = $groups->pluck('id')->all();

        $memberships = $groupIds
            ? DB::table('group_students')->whereIn('group_id', $groupIds)->get(['group_id', 'student_id'])
            : collect();

        $students = $this->activeStudents($memberships->pluck('student_id')->unique()->values()->all());
        $studentIds = $students->keys()->all();

        $membersByGroup = [];
        $groupsByStudent = [];
        foreach ($memberships as $row) {
            if (!$students->has($row->student_id)) {
                continue;
            }
            $membersByGroup[$row->group_id][] = (int) $row->student_id;
            $groupsByStudent[$row->student_id][] = (int) $row->group_id;
        }

        // ---- activity ---------------------------------------------------------
        $activity = $this->activityByStudent($studentIds, $weekStart, $prevStart);

        $activeThisWeek = collect($studentIds)->filter(fn ($id) => ($activity[$id]['this'] ?? 0) > 0)->count();
        $activePrevWeek = collect($studentIds)->filter(fn ($id) => ($activity[$id]['prev'] ?? 0) > 0)->count();

        // ---- results (last 42 days of scored submissions) -----------------------
        $results = $this->scoredResults($studentIds, $now->copy()->subDays(42));

        $thisWeek = $results->filter(fn ($r) => $r->at->greaterThanOrEqualTo($weekStart));
        $prevWeek = $results->filter(fn ($r) => $r->at->lt($weekStart) && $r->at->greaterThanOrEqualTo($prevStart));
        $last30 = $results->filter(fn ($r) => $r->at->greaterThanOrEqualTo($now->copy()->subDays(30)));

        $avg30ByStudent = $last30->groupBy('student_id')->map(fn (Collection $rows) => [
            'average' => round($rows->avg('pct'), 1),
            'count' => $rows->count(),
        ]);

        // ---- overdue, not submitted ---------------------------------------------
        [$overdue, $overdueByStudent, $overdueByGroup] = $this->overdueAssessments(
            $groupIds, $membersByGroup, $now
        );

        // ---- waiting for marking -------------------------------------------------
        $marking = $this->awaitingMarking($groupIds, $now);

        // ---- students who need attention ------------------------------------------
        $attention = $this->attentionStudents(
            $students, $groupsByStudent, $groups, $activity, $avg30ByStudent, $overdueByStudent, $now
        );

        // ---- class table ------------------------------------------------------------
        $classRows = $groups->map(function (Group $group) use (
            $membersByGroup, $activity, $avg30ByStudent, $overdueByGroup, $marking
        ) {
            $ids = $membersByGroup[$group->id] ?? [];
            $averages = collect($ids)->map(fn ($id) => $avg30ByStudent[$id]['average'] ?? null)->filter(fn ($v) => $v !== null);
            $active = collect($ids)->filter(fn ($id) => ($activity[$id]['this'] ?? 0) > 0)->count();

            return [
                'id' => $group->id,
                'name' => $group->group_name,
                'teacher' => $group->creator?->name,
                'creator_id' => $group->created_by,
                'grade_subject_id' => $group->grade_subject_id,
                'area' => $this->areaLabel($group),
                'students' => count($ids),
                'active_this_week' => $active,
                'participation' => count($ids) ? (int) round($active / count($ids) * 100) : null,
                'average' => $averages->isNotEmpty() ? round($averages->avg(), 1) : null,
                'overdue_missing' => $overdueByGroup[$group->id] ?? 0,
                'awaiting_marking' => $marking[$group->id]['count'] ?? 0,
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        // ---- subject/grade table -------------------------------------------------------
        $areaRows = $classRows->groupBy(fn ($r) => $r['grade_subject_id'] ?? 0)->map(function (Collection $rows) use ($membersByGroup, $activity, $avg30ByStudent) {
            $ids = $rows->flatMap(fn ($r) => $membersByGroup[$r['id']] ?? [])->unique()->values();
            $averages = $ids->map(fn ($id) => $avg30ByStudent[$id]['average'] ?? null)->filter(fn ($v) => $v !== null);
            $active = $ids->filter(fn ($id) => ($activity[$id]['this'] ?? 0) > 0)->count();

            return [
                'area' => $rows->first()['area'] ?: 'No teaching area',
                'classes' => $rows->count(),
                'students' => $ids->count(),
                'participation' => $ids->count() ? (int) round($active / $ids->count() * 100) : null,
                'average' => $averages->isNotEmpty() ? round($averages->avg(), 1) : null,
                'overdue_missing' => $rows->sum('overdue_missing'),
            ];
        })->sortBy('area', SORT_NATURAL | SORT_FLAG_CASE)->values();

        // ---- teacher table -----------------------------------------------------------------
        $teacherRows = $this->teacherRows($schoolId, $classRows, $marking, $groups, $weekStart, $now);

        return [
            'generated_at' => $now->toIso8601String(),
            'window' => [
                'from' => $weekStart->toDateString(),
                'to' => $now->toDateString(),
                'label' => 'Last 7 days compared with the 7 days before',
            ],
            'engagement' => [
                'students' => count($studentIds),
                'active_this_week' => $activeThisWeek,
                'active_previous_week' => $activePrevWeek,
                'participation' => count($studentIds) ? (int) round($activeThisWeek / count($studentIds) * 100) : null,
                'inactive_students' => collect($studentIds)->filter(fn ($id) => $this->isInactive($id, $students, $activity, $now))->count(),
                'submissions_this_week' => $thisWeek->count(),
                'submissions_previous_week' => $prevWeek->count(),
                'average_this_week' => $thisWeek->isNotEmpty() ? round($thisWeek->avg('pct'), 1) : null,
                'average_previous_week' => $prevWeek->isNotEmpty() ? round($prevWeek->avg('pct'), 1) : null,
                'late_rate_this_week' => $thisWeek->isNotEmpty()
                    ? (int) round($thisWeek->where('late', true)->count() / $thisWeek->count() * 100)
                    : null,
            ],
            'weekly_trend' => $this->weeklyTrend($results, $now),
            'attention' => [
                'total' => $attention->count(),
                'students' => $attention->take(self::MAX_ATTENTION_ROWS)->values()->all(),
            ],
            'overdue_assessments' => $overdue->take(15)->values()->all(),
            'awaiting_marking' => [
                'total' => collect($marking)->sum('count'),
                'oldest_days' => collect($marking)->max('oldest_days'),
            ],
            'classes' => $classRows->all(),
            'teaching_areas' => $areaRows->all(),
            'teachers' => $teacherRows->all(),
        ];
    }

    // ------------------------------------------------------------------------

    private function activeStudents(array $ids): Collection
    {
        $out = collect();
        foreach (array_chunk($ids, 1000) as $chunk) {
            User::query()
                ->whereIn('id', $chunk)
                ->where('role', 'student')
                ->where('status', 'active')
                ->get(['id', 'name', 'email', 'created_at'])
                ->each(fn ($u) => $out->put($u->id, $u));
        }

        return $out;
    }

    /**
     * Per student: last activity (ever) and the number of activity events in
     * this week and the previous week, across assessments, practice, tutor
     * sessions and formative attempts.
     */
    private function activityByStudent(array $studentIds, Carbon $weekStart, Carbon $prevStart): array
    {
        $sources = [
            ['student_assessments', 'completed_at'],
            ['practice_attempts', 'started_at'],
            ['tutor_sessions', 'COALESCE(last_activity_at, started_at)'],
            ['learner_formative_attempts', 'started_at'],
        ];

        $out = [];
        foreach (array_chunk($studentIds, 1000) as $chunk) {
            foreach ($sources as [$table, $column]) {
                $rows = DB::table($table)
                    ->whereIn('student_id', $chunk)
                    ->whereRaw("$column IS NOT NULL")
                    ->groupBy('student_id')
                    ->selectRaw(
                        "student_id, MAX($column) AS last_at,
                         SUM(CASE WHEN $column >= ? THEN 1 ELSE 0 END) AS this_week,
                         SUM(CASE WHEN $column >= ? AND $column < ? THEN 1 ELSE 0 END) AS prev_week",
                        [$weekStart, $prevStart, $weekStart]
                    )
                    ->get();

                foreach ($rows as $row) {
                    $entry = $out[$row->student_id] ?? ['last' => null, 'this' => 0, 'prev' => 0];
                    $last = $row->last_at ? Carbon::parse($row->last_at) : null;
                    if ($last && (!$entry['last'] || $last->greaterThan($entry['last']))) {
                        $entry['last'] = $last;
                    }
                    $entry['this'] += (int) $row->this_week;
                    $entry['prev'] += (int) $row->prev_week;
                    $out[$row->student_id] = $entry;
                }
            }
        }

        return $out;
    }

    private function scoredResults(array $studentIds, Carbon $since): Collection
    {
        $rows = collect();
        foreach (array_chunk($studentIds, 1000) as $chunk) {
            $rows = $rows->concat(
                DB::table('student_assessments')
                    ->whereIn('student_id', $chunk)
                    ->whereIn('status', self::SCORED)
                    ->whereNotNull('score')
                    ->where('max_score', '>', 0)
                    ->where('completed_at', '>=', $since)
                    ->get(['student_id', 'score', 'max_score', 'completed_at', 'is_late'])
            );
        }

        return $rows->map(fn ($r) => (object) [
            'student_id' => (int) $r->student_id,
            'pct' => ((float) $r->score / (float) $r->max_score) * 100,
            'at' => Carbon::parse($r->completed_at),
            'late' => (bool) $r->is_late,
        ]);
    }

    /** Six weekly points, oldest first. */
    private function weeklyTrend(Collection $results, Carbon $now): array
    {
        $points = [];
        for ($i = 5; $i >= 0; $i--) {
            $end = $now->copy()->subDays($i * 7);
            $start = $end->copy()->subDays(7);
            $rows = $results->filter(fn ($r) => $r->at->gt($start) && $r->at->lte($end));
            $points[] = [
                'label' => $end->format('d M'),
                'submissions' => $rows->count(),
                'average' => $rows->isNotEmpty() ? round($rows->avg('pct'), 1) : null,
            ];
        }

        return $points;
    }

    /**
     * Assessments past their due date with students who have not submitted.
     * Whole-class assignments count every class member; "selected students"
     * assignments count only the students who were given it.
     *
     * @return array{0: Collection, 1: array, 2: array}
     */
    private function overdueAssessments(array $groupIds, array $membersByGroup, Carbon $now): array
    {
        if (!$groupIds) {
            return [collect(), [], []];
        }

        $links = DB::table('assessment_groups as ag')
            ->join('assessments as a', 'a.id', '=', 'ag.assessment_id')
            ->whereIn('ag.group_id', $groupIds)
            ->where('a.status', 'published')
            ->whereNotNull('a.due_date')
            ->where('a.due_date', '<', $now)
            ->where('a.due_date', '>=', $now->copy()->subDays(60))
            ->where(function ($q) {
                $q->whereNull('a.is_system_generated')->orWhere('a.is_system_generated', false);
            })
            ->get(['ag.assessment_id', 'ag.group_id', 'ag.audience', 'a.title', 'a.type', 'a.due_date']);

        if ($links->isEmpty()) {
            return [collect(), [], []];
        }

        $assessmentIds = $links->pluck('assessment_id')->unique()->values()->all();
        $rows = DB::table('student_assessments')
            ->whereIn('assessment_id', $assessmentIds)
            ->get(['assessment_id', 'student_id', 'status']);

        $submitted = [];
        $assigned = [];
        foreach ($rows as $row) {
            $assigned[$row->assessment_id][$row->student_id] = true;
            if (in_array($row->status, self::SUBMITTED, true)) {
                $submitted[$row->assessment_id][$row->student_id] = true;
            }
        }

        $groupNames = Group::whereIn('id', $groupIds)->pluck('group_name', 'id');

        $list = collect();
        $byStudent = [];
        $byGroup = [];

        foreach ($links as $link) {
            $members = $membersByGroup[$link->group_id] ?? [];
            $recipients = ($link->audience ?? 'all') === 'selected'
                ? array_values(array_filter($members, fn ($id) => isset($assigned[$link->assessment_id][$id])))
                : $members;

            if (!$recipients) {
                continue;
            }

            $missing = array_values(array_filter(
                $recipients,
                fn ($id) => !isset($submitted[$link->assessment_id][$id])
            ));

            if (!$missing) {
                continue;
            }

            foreach ($missing as $id) {
                $byStudent[$id][] = $link->title;
            }
            $byGroup[$link->group_id] = ($byGroup[$link->group_id] ?? 0) + count($missing);

            $list->push([
                'assessment_id' => (int) $link->assessment_id,
                'title' => $link->title,
                'type' => $link->type,
                'class' => $groupNames[$link->group_id] ?? 'Class',
                'group_id' => (int) $link->group_id,
                'due_date' => Carbon::parse($link->due_date)->toDateString(),
                'days_overdue' => max(0, (int) Carbon::parse($link->due_date)->diffInDays($now)),
                'recipients' => count($recipients),
                'not_submitted' => count($missing),
                'submitted' => count($recipients) - count($missing),
            ]);
        }

        return [
            $list->sortByDesc('not_submitted')->values(),
            $byStudent,
            $byGroup,
        ];
    }

    /** @return array<int, array{count:int, oldest_days:int|null}> keyed by group id */
    private function awaitingMarking(array $groupIds, Carbon $now): array
    {
        if (!$groupIds) {
            return [];
        }

        $rows = DB::table('student_assessments as sa')
            ->join('assessment_groups as ag', 'ag.assessment_id', '=', 'sa.assessment_id')
            ->join('group_students as gs', function ($join) {
                $join->on('gs.group_id', '=', 'ag.group_id')->on('gs.student_id', '=', 'sa.student_id');
            })
            ->whereIn('ag.group_id', $groupIds)
            ->where('sa.status', 'under_review')
            ->groupBy('ag.group_id')
            ->selectRaw('ag.group_id, COUNT(*) AS waiting, MIN(sa.completed_at) AS oldest')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->group_id] = [
                'count' => (int) $row->waiting,
                'oldest_days' => $row->oldest ? (int) Carbon::parse($row->oldest)->diffInDays($now) : null,
            ];
        }

        return $out;
    }

    private function isInactive(int $id, Collection $students, array $activity, Carbon $now): bool
    {
        $last = $activity[$id]['last'] ?? null;
        if ($last) {
            return $last->lt($now->copy()->subDays(self::INACTIVE_DAYS));
        }

        // Never active: only flag once the account has existed long enough.
        $created = $students[$id]->created_at ?? null;

        return $created && Carbon::parse($created)->lt($now->copy()->subDays(self::INACTIVE_DAYS));
    }

    private function attentionStudents(
        Collection $students,
        array $groupsByStudent,
        Collection $groups,
        array $activity,
        Collection $avg30,
        array $overdueByStudent,
        Carbon $now
    ): Collection {
        $groupNames = $groups->pluck('group_name', 'id');
        $rows = collect();

        foreach ($students as $id => $student) {
            $reasons = [];
            $score = 0;

            $avg = $avg30[$id] ?? null;
            if ($avg && $avg['average'] < self::LOW_AVERAGE) {
                $reasons[] = "Averaging {$avg['average']}% over the last 30 days";
                $score += 3;
            }

            if ($this->isInactive((int) $id, $students, $activity, $now)) {
                $last = $activity[$id]['last'] ?? null;
                $reasons[] = $last
                    ? 'No activity for ' . (int) $last->diffInDays($now) . ' days'
                    : 'No activity recorded yet';
                $score += 2;
            }

            $missing = $overdueByStudent[$id] ?? [];
            if (count($missing) >= 2) {
                $reasons[] = count($missing) . ' overdue assessments not submitted';
                $score += 2;
            } elseif (count($missing) === 1) {
                $score += 1;
                $reasons[] = '1 overdue assessment not submitted';
            }

            if (!$reasons) {
                continue;
            }

            $rows->push([
                'id' => (int) $id,
                'name' => $student->name,
                'classes' => collect($groupsByStudent[$id] ?? [])->map(fn ($gid) => $groupNames[$gid] ?? null)->filter()->values()->all(),
                'average' => $avg['average'] ?? null,
                'last_active' => ($activity[$id]['last'] ?? null)?->toDateString(),
                'overdue' => count($missing),
                'reasons' => $reasons,
                '_score' => $score,
            ]);
        }

        return $rows
            ->sortBy([['_score', 'desc'], ['average', 'asc']])
            ->map(function ($row) {
                unset($row['_score']);

                return $row;
            })
            ->values();
    }

    private function teacherRows(int $schoolId, Collection $classRows, array $marking, Collection $groups, Carbon $weekStart, Carbon $now): Collection
    {
        $teachers = User::query()
            ->where('role', 'teacher')
            ->where('school_id', $schoolId)
            ->where('teacher_approval_status', 'approved')
            ->get(['id', 'name', 'status']);

        $createdThisWeek = $teachers->isEmpty() ? collect() : Assessment::query()
            ->whereIn('creator_id', $teachers->pluck('id'))
            ->where('created_at', '>=', $weekStart)
            ->groupBy('creator_id')
            ->selectRaw('creator_id, COUNT(*) AS total')
            ->pluck('total', 'creator_id');

        $lastCreated = $teachers->isEmpty() ? collect() : Assessment::query()
            ->whereIn('creator_id', $teachers->pluck('id'))
            ->groupBy('creator_id')
            ->selectRaw('creator_id, MAX(created_at) AS last_at')
            ->pluck('last_at', 'creator_id');

        $groupsByTeacher = $groups->groupBy('created_by');

        return $teachers->map(function (User $teacher) use ($classRows, $marking, $groupsByTeacher, $createdThisWeek, $lastCreated, $now) {
            $theirGroups = $groupsByTeacher[$teacher->id] ?? collect();
            $theirRows = $classRows->whereIn('id', $theirGroups->pluck('id')->all());
            $waiting = $theirGroups->sum(fn ($g) => $marking[$g->id]['count'] ?? 0);
            $oldest = $theirGroups->map(fn ($g) => $marking[$g->id]['oldest_days'] ?? null)->filter(fn ($v) => $v !== null)->max();
            $last = $lastCreated[$teacher->id] ?? null;

            return [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'active' => $teacher->status === 'active',
                'classes' => $theirGroups->count(),
                'students' => $theirRows->sum('students'),
                'assessments_this_week' => (int) ($createdThisWeek[$teacher->id] ?? 0),
                'awaiting_marking' => (int) $waiting,
                'oldest_waiting_days' => $oldest,
                'last_assessment_at' => $last ? Carbon::parse($last)->toDateString() : null,
                'days_since_assessment' => $last ? (int) Carbon::parse($last)->diffInDays($now) : null,
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    private function areaLabel(Group $group): string
    {
        return trim(implode(' - ', array_filter([
            $group->gradeSubject?->subject?->name,
            $group->gradeSubject?->gradeLevel?->grade_name,
        ])));
    }
}
