<?php

namespace App\Policies;

use App\Models\GradeSubject;
use App\Models\User;

class GradeSubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'teacher'], true) && $user->school_id !== null;
    }

    public function view(User $user, GradeSubject $gradeSubject): bool
    {
        if ((int) $gradeSubject->school_id !== (int) $user->school_id) {
            return false;
        }

        return $user->role === 'admin'
            || ($user->role === 'teacher' && (int) $gradeSubject->teacher_id === (int) $user->id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'teacher'], true)
            && $user->school_id !== null;
    }

    public function update(User $user, GradeSubject $gradeSubject): bool
    {
        return $this->view($user, $gradeSubject);
    }

    public function delete(User $user, GradeSubject $gradeSubject): bool
    {
        return $this->view($user, $gradeSubject);
    }
}
