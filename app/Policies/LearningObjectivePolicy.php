<?php

namespace App\Policies;

use App\Models\LearningObjective;
use App\Models\User;

class LearningObjectivePolicy
{
    public function view(User $user, LearningObjective $objective): bool
    {
        $objective->loadMissing('topic.gradeSubject');
        $area = $objective->topic?->gradeSubject;

        if (!$area || (int) $area->school_id !== (int) $user->school_id) {
            return false;
        }

        return $user->role === 'admin'
            || ($user->role === 'teacher' && (int) $area->teacher_id === (int) $user->id);
    }

    public function update(User $user, LearningObjective $objective): bool
    {
        return $this->view($user, $objective);
    }

    public function delete(User $user, LearningObjective $objective): bool
    {
        return $this->view($user, $objective);
    }
}
