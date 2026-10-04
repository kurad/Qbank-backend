<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function view(User $user, Unit $unit): bool
    {
        $unit->loadMissing('gradeSubject');
        $area = $unit->gradeSubject;

        if (!$area || (int) $area->school_id !== (int) $user->school_id) {
            return false;
        }

        return $user->role === 'admin'
            || ($user->role === 'teacher' && (int) $area->teacher_id === (int) $user->id);
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->view($user, $unit);
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $this->view($user, $unit);
    }
}
