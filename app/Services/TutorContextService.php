<?php

namespace App\Services;

use App\Models\GradeSubject;
use App\Models\Group;
use App\Models\LearningPeriod;
use App\Models\Topic;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TutorContextService
{
    public function studentGroups(User $student)
    {
        return $student->groups()
            ->select(
                'groups.id',
                'groups.group_name',
                'groups.class_code',
                'groups.academic_year',
                'groups.grade_subject_id'
            )
            ->with([
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ])
            ->orderByDesc('groups.academic_year')
            ->orderBy('groups.group_name')
            ->get();
    }

    public function subjectsForStudentGroup(
        User $student,
        Group $group
    ) {
        $this->ensureMembership(
            $student,
            $group
        );

        $learningPeriods = LearningPeriod::query()
            ->where('group_id', $group->id)
            ->where('status', 'published')
            ->whereHas('gradeSubject', function ($query) use ($student) {
                $query->where(
                    'school_id',
                    $student->school_id
                );
            })
            ->with([
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ])
            ->orderByDesc('start_date')
            ->get();

        return $learningPeriods
            ->map(function (LearningPeriod $period) {
                $gradeSubject = $period->gradeSubject;

                if (!$gradeSubject) {
                    return null;
                }

                return [
                    'id' => $gradeSubject->id,

                    'grade_subject_id' =>
                        $gradeSubject->id,

                    'subject_id' =>
                        $gradeSubject->subject_id,

                    'subject' =>
                        $gradeSubject->subject,

                    'grade_level' =>
                        $gradeSubject->gradeLevel,

                    'learning_period_id' =>
                        $period->id,

                    'learning_period' => [
                        'id' => $period->id,
                        'title' => $period->title,
                        'description' => $period->description,
                        'start_date' => $period->start_date,
                        'end_date' => $period->end_date,
                        'status' => $period->status,
                    ],
                ];
            })
            ->filter()
            ->unique('grade_subject_id')
            ->values();
    }

    /**
     * ---------------------------------------------------------
     * UNITS
     * ---------------------------------------------------------
     *
     * Legacy endpoint retained for compatibility.
     *
     * The recommended student workflow is:
     *
     * Group
     * → Learning Period
     * → Scheduled Topics
     */
    public function units(
        User $student,
        Group $group,
        GradeSubject $gradeSubject
    ) {
        $this->ensureMembership(
            $student,
            $group
        );

        $this->ensureGradeSubject(
            $student,
            $gradeSubject
        );

        /*
         * Make sure this subject is actually available
         * in this group through a published learning period.
         */
        $this->ensureSubjectAvailableInGroup(
            $group,
            $gradeSubject
        );

        return $gradeSubject
            ->units()
            ->where('status', 'active')
            ->orderBy('order')
            ->get([
                'id',
                'grade_subject_id',
                'name',
                'description',
                'order',
            ]);
    }

    /**
     * ---------------------------------------------------------
     * TOPICS
     * ---------------------------------------------------------
     *
     * Legacy endpoint retained for compatibility.
     */
    public function topics(
        User $student,
        Group $group,
        GradeSubject $gradeSubject,
        Unit $unit
    ) {
        $this->ensureMembership(
            $student,
            $group
        );

        $this->ensureGradeSubject(
            $student,
            $gradeSubject
        );

        $this->ensureSubjectAvailableInGroup(
            $group,
            $gradeSubject
        );

        abort_unless(
            (int) $unit->grade_subject_id ===
                (int) $gradeSubject->id,
            422,
            'The selected unit does not belong to the selected subject.'
        );

        return $unit
            ->topics()
            ->where('status', 'active')
            ->orderBy('order')
            ->get([
                'id',
                'grade_subject_id',
                'unit_id',
                'topic_name',
                'order',
                'status',
            ]);
    }

    /**
     * ---------------------------------------------------------
     * OBJECTIVES
     * ---------------------------------------------------------
     *
     * Legacy endpoint retained for compatibility.
     *
     * Students do not select objectives.
     */
    public function objectives(
        User $student,
        Group $group,
        GradeSubject $gradeSubject,
        Unit $unit,
        Topic $topic
    ) {
        $this->ensureMembership(
            $student,
            $group
        );

        $this->ensureGradeSubject(
            $student,
            $gradeSubject
        );

        $this->ensureSubjectAvailableInGroup(
            $group,
            $gradeSubject
        );

        abort_unless(
            (int) $unit->grade_subject_id ===
                (int) $gradeSubject->id,
            422,
            'The selected unit does not belong to the selected subject.'
        );

        abort_unless(
            (int) $topic->unit_id ===
                (int) $unit->id,
            422,
            'The selected topic does not belong to the selected unit.'
        );

        return $topic
            ->learningObjectives()
            ->where('status', 'active')
            ->orderBy('order')
            ->get([
                'id',
                'topic_id',
                'code',
                'objective',
                'description',
                'order',
                'status',
            ]);
    }

    /**
     * ---------------------------------------------------------
     * LEARNING PERIODS
     * ---------------------------------------------------------
     *
     * Return published learning periods assigned to a group.
     */
    public function learningPeriodsForStudent(
        User $student,
        Group $group,
        bool $currentOnly = false
    ) {
        $this->ensureMembership(
            $student,
            $group
        );

        $query = LearningPeriod::query()
            ->where('group_id', $group->id)
            ->where('status', 'published')
            ->whereHas('gradeSubject', function ($query) use ($student) {
                $query->where(
                    'school_id',
                    $student->school_id
                );
            })
            ->with([
                'gradeSubject.subject:id,name',

                'gradeSubject.gradeLevel:id,grade_name',

                'topics' => function ($query) {
                    $query
                        ->orderBy('display_order')
                        ->with([
                            'unit:id,name',

                            'topic:id,topic_name,unit_id,grade_subject_id',
                        ]);
                },
            ])
            ->orderByDesc('start_date');

        if ($currentOnly) {
            $today = now()->toDateString();

            $query
                ->whereDate(
                    'start_date',
                    '<=',
                    $today
                )
                ->whereDate(
                    'end_date',
                    '>=',
                    $today
                );
        }

        return $query->get();
    }

    /**
     * ---------------------------------------------------------
     * CURRENT LEARNING PERIODS
     * ---------------------------------------------------------
     */
    public function currentLearningPeriods(
        User $student,
        Group $group
    ) {
        return $this->learningPeriodsForStudent(
            $student,
            $group,
            true
        );
    }

    /**
     * ---------------------------------------------------------
     * LEARNING PERIOD TOPICS
     * ---------------------------------------------------------
     *
     * Only topics explicitly scheduled by the teacher
     * in this learning period are returned.
     */
    public function learningPeriodTopics(
        User $student,
        Group $group,
        LearningPeriod $learningPeriod
    ) {
        $this->ensureMembership(
            $student,
            $group
        );

        $this->ensureLearningPeriodBelongsToGroup(
            $learningPeriod,
            $group
        );

        $this->ensureStudentCanAccessLearningPeriod(
            $student,
            $learningPeriod
        );

        return $learningPeriod
            ->topics()
            ->with([
                'unit:id,name',

                'topic:id,topic_name,unit_id,grade_subject_id',
            ])
            ->orderBy('display_order')
            ->get();
    }

    /**
     * ---------------------------------------------------------
     * RESOLVE TUTOR SESSION CONTEXT
     * ---------------------------------------------------------
     *
     * Required:
     *
     * group_id
     * learning_period_id
     * grade_subject_id
     * topic_id
     *
     * The unit is resolved automatically from the
     * LearningPeriodTopic.
     *
     * Objectives are automatically resolved from
     * the selected topic.
     */
    public function resolveSessionContext(
        User $student,
        array $data
    ): array {
        /*
         * -----------------------------------------------------
         * 1. GROUP
         * -----------------------------------------------------
         */
        $group = Group::findOrFail(
            $data['group_id']
        );

        $this->ensureMembership(
            $student,
            $group
        );

        /*
         * -----------------------------------------------------
         * 2. LEARNING PERIOD
         * -----------------------------------------------------
         */
        $learningPeriod = LearningPeriod::query()
            ->whereKey(
                $data['learning_period_id']
            )
            ->where(
                'group_id',
                $group->id
            )
            ->where(
                'status',
                'published'
            )
            ->with([
                'gradeSubject.subject:id,name',

                'gradeSubject.gradeLevel:id,grade_name',
            ])
            ->firstOrFail();

        $this->ensureStudentCanAccessLearningPeriod(
            $student,
            $learningPeriod
        );

        /*
         * -----------------------------------------------------
         * 3. GRADE SUBJECT
         * -----------------------------------------------------
         */
        $gradeSubject = GradeSubject::query()
            ->with([
                'subject:id,name',

                'gradeLevel:id,grade_name',
            ])
            ->whereKey(
                $data['grade_subject_id']
            )
            ->firstOrFail();

        $this->ensureGradeSubject(
            $student,
            $gradeSubject
        );

        /*
         * Subject must belong to the selected
         * learning period.
         */
        abort_unless(
            (int) $learningPeriod->grade_subject_id ===
                (int) $gradeSubject->id,
            422,
            'The selected subject does not belong to the selected learning period.'
        );

        /*
         * -----------------------------------------------------
         * 4. SCHEDULED TOPIC
         * -----------------------------------------------------
         *
         * The student cannot simply select any topic
         * from the curriculum.
         *
         * The topic must have been explicitly scheduled
         * by the teacher in this learning period.
         */
        $periodTopic = $learningPeriod
            ->topics()
            ->where(
                'topic_id',
                $data['topic_id']
            )
            ->with([
                'unit',

                'topic',
            ])
            ->first();

        if (!$periodTopic) {
            throw ValidationException::withMessages([
                'topic_id' => [
                    'The selected topic is not part of the current learning period.',
                ],
            ]);
        }

        /*
         * -----------------------------------------------------
         * 5. UNIT
         * -----------------------------------------------------
         */
        $unit = $periodTopic->unit;

        abort_unless(
            $unit,
            422,
            'The scheduled topic does not have a valid unit.'
        );

        /*
         * -----------------------------------------------------
         * 6. TOPIC
         * -----------------------------------------------------
         */
        $topic = $periodTopic->topic;

        abort_unless(
            $topic,
            422,
            'The scheduled topic could not be found.'
        );

        /*
         * -----------------------------------------------------
         * 7. DATA CONSISTENCY CHECKS
         * -----------------------------------------------------
         */

        abort_unless(
            (int) $unit->grade_subject_id ===
                (int) $gradeSubject->id,
            422,
            'The scheduled unit does not belong to the selected subject.'
        );

        abort_unless(
            (int) $topic->unit_id ===
                (int) $unit->id,
            422,
            'The selected topic does not belong to the scheduled unit.'
        );

        abort_unless(
            (int) $topic->grade_subject_id ===
                (int) $gradeSubject->id,
            422,
            'The selected topic does not belong to the selected subject.'
        );

        /*
         * -----------------------------------------------------
         * 8. OBJECTIVES
         * -----------------------------------------------------
         *
         * Students do not select objectives.
         *
         * All active objectives for the scheduled topic
         * are automatically attached to the Tutor context.
         */
        $objectives = $topic
            ->learningObjectives()
            ->where(
                'status',
                'active'
            )
            ->orderBy('order')
            ->get();

        return [
            'group' => $group,

            'learningPeriod' =>
                $learningPeriod,

            'periodTopic' =>
                $periodTopic,

            'gradeSubject' =>
                $gradeSubject,

            'unit' =>
                $unit,

            'topic' =>
                $topic,

            'objectives' =>
                $objectives,
        ];
    }

    /**
     * ---------------------------------------------------------
     * MEMBERSHIP
     * ---------------------------------------------------------
     *
     * This check is ONLY for students accessing Tutor content.
     *
     * Teachers creating/managing groups and learning periods
     * do NOT use this method.
     */
    private function ensureMembership(
        User $student,
        Group $group
    ): void {
        $isMember = $student
            ->groups()
            ->where(
                'groups.id',
                $group->id
            )
            ->exists();

        abort_unless(
            $isMember,
            403,
            'You are not enrolled in the selected class.'
        );
    }

    /**
     * ---------------------------------------------------------
     * GRADE SUBJECT ACCESS
     * ---------------------------------------------------------
     */
    private function ensureGradeSubject(
        User $student,
        GradeSubject $gradeSubject
    ): void {
        /*
         * Grade is intentionally NOT read from the student account.
         * The selected class / teaching area supplies the grade context.
         * We only enforce the student's school boundary here.
         */
        abort_unless(
            (int) $gradeSubject->school_id ===
                (int) $student->school_id,
            403,
            'The selected subject is not available in your school.'
        );

    }

    /**
     * ---------------------------------------------------------
     * SUBJECT AVAILABLE IN GROUP
     * ---------------------------------------------------------
     *
     * A subject is considered available to a student in a group
     * only if the teacher has published at least one learning
     * period for that subject in that group.
     */
    private function ensureSubjectAvailableInGroup(
        Group $group,
        GradeSubject $gradeSubject
    ): void {
        $exists = LearningPeriod::query()
            ->where(
                'group_id',
                $group->id
            )
            ->where(
                'grade_subject_id',
                $gradeSubject->id
            )
            ->where(
                'status',
                'published'
            )
            ->exists();

        abort_unless(
            $exists,
            403,
            'This subject has not been assigned to the selected class.'
        );
    }

    /**
     * ---------------------------------------------------------
     * LEARNING PERIOD → GROUP
     * ---------------------------------------------------------
     */
    private function ensureLearningPeriodBelongsToGroup(
        LearningPeriod $learningPeriod,
        Group $group
    ): void {
        abort_unless(
            (int) $learningPeriod->group_id ===
                (int) $group->id,
            403,
            'The selected learning period does not belong to this class.'
        );
    }

    /**
     * ---------------------------------------------------------
     * LEARNING PERIOD ACCESS
     * ---------------------------------------------------------
     */
    private function ensureStudentCanAccessLearningPeriod(
        User $student,
        LearningPeriod $learningPeriod
    ): void {
        /*
         * Only published periods are visible to students.
         */
        abort_unless(
            $learningPeriod->isPublished(),
            403,
            'This learning period is not available to students.'
        );

        $gradeSubject =
            $learningPeriod->gradeSubject;

        /*
         * Verify school.
         */
        if ($gradeSubject) {
            abort_unless(
                (int) $gradeSubject->school_id ===
                    (int) $student->school_id,
                403,
                'This learning period is not available to you.'
            );
        }

    }
}