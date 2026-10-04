<?php

namespace App\Policies;

use App\Models\Topic;
use App\Models\User;

class TopicPolicy
{
    public function view(User $user, Topic $topic): bool
    {
        $topic->loadMissing('gradeSubject');
        $area = $topic->gradeSubject;

        if (!$area || (int) $area->school_id !== (int) $user->school_id) {
            return false;
        }

        return $user->role === 'admin'
            || ($user->role === 'teacher' && (int) $area->teacher_id === (int) $user->id);
    }

    public function update(User $user, Topic $topic): bool
    {
        return $this->view($user, $topic);
    }

    public function delete(User $user, Topic $topic): bool
    {
        return $this->view($user, $topic);
    }

    public function create(User $user, $unit): bool
    {
        return $user->role === 'admin' || ($user->role === 'teacher' && (int) $unit->gradeSubject?->teacher_id === (int) $user->id && (int) $unit->gradeSubject?->school_id === (int) $user->school_id);
    }
}
