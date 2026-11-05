<?php

namespace App\Http\Controllers;

use App\Models\CourseMaterial;
use App\Models\CourseMaterialSupportingSource;
use App\Services\SupportingSourceContentService;
use Illuminate\Http\Request;

class CourseMaterialSupportingSourceController extends Controller
{
    public function store(
        Request $request,
        CourseMaterial $courseMaterial,
        SupportingSourceContentService $contentService
    ) {
        $this->authorizeMaterial($request, $courseMaterial);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2000'],
        ]);

        $duplicate = $courseMaterial
            ->supportingSources()
            ->where('url', trim($data['url']))
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'This supporting source has already been added to the material.',
            ], 422);
        }

        $inspection = $contentService->inspect($data['url']);

        $source = $courseMaterial->supportingSources()->create([
            'teacher_id' => $request->user()->id,
            'title' => trim($data['title']),
            'url' => trim($data['url']),
            'status' => 'ready',
            'last_error' => null,
            ...$inspection,
        ]);

        return response()->json([
            'message' => 'Supporting source validated and added.',
            'data' => $this->sourceData($source),
        ], 201);
    }

    public function refresh(
        Request $request,
        CourseMaterialSupportingSource $supportingSource,
        SupportingSourceContentService $contentService
    ) {
        $supportingSource->loadMissing('courseMaterial');

        $this->authorizeMaterial(
            $request,
            $supportingSource->courseMaterial
        );

        $source = $contentService->refresh($supportingSource);

        return response()->json([
            'message' => 'Supporting source refreshed successfully.',
            'data' => $this->sourceData($source),
        ]);
    }

    public function destroy(
        Request $request,
        CourseMaterialSupportingSource $supportingSource
    ) {
        $supportingSource->loadMissing('courseMaterial');

        $this->authorizeMaterial(
            $request,
            $supportingSource->courseMaterial
        );

        $supportingSource->delete();

        return response()->json([
            'message' => 'Supporting source removed.',
        ]);
    }

    protected function authorizeMaterial(
        Request $request,
        CourseMaterial $courseMaterial
    ): void {
        $user = $request->user();

        $courseMaterial->loadMissing([
            'teacher:id,school_id',
            'unit.gradeSubject:id,school_id,teacher_id',
        ]);

        if ($user->role === 'admin') {
            if ($user->admin_scope === 'system') {
                return;
            }

            $schoolId =
                $courseMaterial->unit?->gradeSubject?->school_id
                ?? $courseMaterial->teacher?->school_id;

            abort_unless(
                (int) $schoolId === (int) $user->school_id,
                403
            );

            return;
        }

        abort_unless($user->role === 'teacher', 403);

        $ownsTeachingArea =
            (int) ($courseMaterial->unit?->gradeSubject?->teacher_id ?? 0)
            === (int) $user->id;

        $ownsMaterial =
            (int) $courseMaterial->teacher_id === (int) $user->id;

        abort_unless($ownsTeachingArea || $ownsMaterial, 403);
    }

    protected function sourceData(
        CourseMaterialSupportingSource $source
    ): array {
        return [
            'id' => $source->id,
            'course_material_id' => $source->course_material_id,
            'title' => $source->title,
            'url' => $source->url,
            'domain' => $source->domain,
            'status' => $source->status,
            'cached_title' => $source->cached_title,
            'content_type' => $source->content_type,
            'validated_at' => optional($source->validated_at)?->toIso8601String(),
            'last_error' => $source->last_error,
        ];
    }
}
