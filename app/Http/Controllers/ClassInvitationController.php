<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClassInvitationController extends Controller
{
    /**
     * Public invitation preview.
     *
     * Only safe class information is returned here.
     */
    public function show(string $classCode)
    {
        $classCode = strtoupper(trim($classCode));

        $group = Group::with([
            'creator:id,name',
            'gradeSubject.subject:id,name',
            'gradeSubject.gradeLevel:id,grade_name',
            'gradeSubject.school:id,school_name',
        ])
            ->whereRaw('UPPER(class_code) = ?', [$classCode])
            ->firstOrFail();

        return response()->json([
            'valid' => true,
            'class' => [
                'id' => $group->id,
                'group_name' => $group->group_name,
                'class_code' => $group->class_code,
                'subject' => $group->gradeSubject?->subject?->name,
                'grade' => $group->gradeSubject?->gradeLevel?->grade_name,
                'teacher' => $group->creator?->name,
                'school' => $group->gradeSubject?->school?->school_name,
                'academic_year' => $group->academic_year,
            ],
        ]);
    }

    /**
     * Shared class-code enrollment used by both the existing manual
     * join flow and the new invitation-link flow.
     */

    /**
     * Resolve the next step for a student invitation using the email address.
     *
     * This endpoint intentionally does not authenticate the user. It only tells
     * the invitation page whether the student should sign in or create a new
     * student account. The invitation itself scopes the lookup to one school.
     */
    public function resolveAccount(Request $request, string $classCode)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $classCode = strtoupper(trim($classCode));
        $email = strtolower(trim($validated['email']));

        $group = Group::with([
            'gradeSubject.school:id,school_name',
        ])
            ->whereRaw('UPPER(class_code) = ?', [$classCode])
            ->first();

        if (!$group || !$group->gradeSubject) {
            throw ValidationException::withMessages([
                'class_code' => ['This class invitation is invalid or no longer exists.'],
            ]);
        }

        $schoolId = (int) $group->gradeSubject->school_id;

        $user = \App\Models\User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (!$user) {
            return response()->json([
                'action' => 'register',
                'email' => $email,
                'message' => 'No RevisionHub student account was found for this email. Continue to create one.',
            ]);
        }

        if ($user->role !== 'student') {
            return response()->json([
                'action' => 'blocked',
                'email' => $email,
                'message' => 'This email is already linked to a non-student RevisionHub account. Please use a student email address.',
            ], 422);
        }

        if ($schoolId && (int) $user->school_id !== $schoolId) {
            return response()->json([
                'action' => 'blocked',
                'email' => $email,
                'message' => 'This student account belongs to a different school.',
            ], 403);
        }

        $alreadyJoined = $group->students()
            ->where('users.id', $user->id)
            ->exists();

        return response()->json([
            'action' => 'login',
            'email' => $email,
            'already_joined' => $alreadyJoined,
            'message' => $alreadyJoined
                ? 'Your account already belongs to this class. Sign in to open it.'
                : 'We found your RevisionHub account. Sign in to join this class.',
        ]);
    }

    public function join(Request $request, ?string $classCode = null)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'student') {
            return response()->json([
                'message' => 'Only student accounts can join a class.',
            ], 403);
        }

        $submittedCode = $classCode ?: $request->input('class_code');

        if (!$submittedCode) {
            throw ValidationException::withMessages([
                'class_code' => ['The class code is required.'],
            ]);
        }

        $submittedCode = strtoupper(trim((string) $submittedCode));

        $group = Group::with([
            'creator:id,name',
            'gradeSubject.subject:id,name',
            'gradeSubject.gradeLevel:id,grade_name',
            'gradeSubject.school:id,school_name',
        ])
            ->whereRaw('UPPER(class_code) = ?', [$submittedCode])
            ->first();

        if (!$group) {
            throw ValidationException::withMessages([
                'class_code' => ['This class invitation is invalid or no longer exists.'],
            ]);
        }

        if (!$group->gradeSubject) {
            return response()->json([
                'message' => 'This class is not linked to a teaching area yet. Ask the teacher to update the class.',
            ], 422);
        }

        if (
            $group->gradeSubject->school_id
            && (int) $user->school_id !== (int) $group->gradeSubject->school_id
        ) {
            return response()->json([
                'message' => 'This invitation belongs to a different school.',
            ], 403);
        }


        $alreadyJoined = $group->students()
            ->where('users.id', $user->id)
            ->exists();

        if ($alreadyJoined) {
            return response()->json([
                'message' => 'You have already joined this class.',
                'already_joined' => true,
                'group' => $this->groupPayload($group),
            ]);
        }

        $group->students()->attach($user->id);

        return response()->json([
            'message' => 'Successfully joined the class!',
            'already_joined' => false,
            'group' => $this->groupPayload($group),
        ]);
    }

    protected function groupPayload(Group $group): array
    {
        return [
            'id' => $group->id,
            'group_name' => $group->group_name,
            'class_code' => $group->class_code,
            'subject' => $group->gradeSubject?->subject?->name,
            'grade' => $group->gradeSubject?->gradeLevel?->grade_name,
            'teacher' => $group->creator?->name,
            'school' => $group->gradeSubject?->school?->school_name,
            'academic_year' => $group->academic_year,
        ];
    }
}
