<?php

namespace App\Http\Controllers;

use App\Models\CourseMaterialVisual;
use App\Models\TutorSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TutorVisualController extends Controller
{
    public function show(
        Request $request,
        TutorSession $tutorSession,
        CourseMaterialVisual $visual
    ) {
        abort_unless(
            $request->user()
            && (int) $tutorSession->student_id === (int) $request->user()->id,
            403
        );

        $visual->load('courseMaterial');
        $material = $visual->courseMaterial;

        abort_unless($material, 404);
        abort_unless($material->status === 'approved', 404);
        abort_unless($material->processing_status === 'completed', 404);

        // The material must belong to the same curriculum scope as this session.
        abort_unless(
            (int) $material->subject_id === (int) $tutorSession->subject_id,
            403
        );

        if (!empty($material->topic_id)) {
            abort_unless(
                (int) $material->topic_id === (int) $tutorSession->topic_id,
                403
            );
        } elseif (!empty($material->unit_id)) {
            abort_unless(
                (int) $material->unit_id === (int) $tutorSession->unit_id,
                403
            );
        }

        $disk = Storage::disk('local');

        abort_unless(
            $disk->exists($visual->file_path),
            404
        );

        return response()->file(
            $disk->path($visual->file_path),
            [
                'Content-Type' => $visual->mime_type ?: 'image/png',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
