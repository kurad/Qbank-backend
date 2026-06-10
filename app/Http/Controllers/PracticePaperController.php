<?php

namespace App\Http\Controllers;

use App\Models\PracticePaper;
use App\Models\Subject;
use App\Services\PdfWatermarkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PracticePaperController extends Controller
{
    /**
     * Resolve local storage path safely.
     *
     * Some Laravel installations store local files in:
     * storage/app/...
     *
     * Others may store them in:
     * storage/app/private/...
     */
    private function localStoragePath(string $relativePath): string
    {
        $path1 = storage_path('app/' . $relativePath);
        $path2 = storage_path('app/private/' . $relativePath);

        if (file_exists($path1)) {
            return $path1;
        }

        if (file_exists($path2)) {
            return $path2;
        }

        // Default fallback for normal local disk.
        return $path1;
    }

    private function canManagePaper(PracticePaper $practicePaper): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->role === 'admin' || (int) $practicePaper->teacher_id === (int) $user->id;
    }

    private function canViewPaper(PracticePaper $practicePaper): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'teacher') {
            return (int) $practicePaper->teacher_id === (int) $user->id;
        }

        if ($user->role === 'student') {
            return $practicePaper->status === 'published';
        }

        return false;
    }

    private function makeUniquePdfName(string $originalName): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $safeName = Str::slug($baseName);

        if (! $safeName) {
            $safeName = 'practice-paper';
        }

        return $safeName . '-' . time() . '-' . Str::random(6) . '.pdf';
    }

    private function validateGeneratedPdf(string $outputPath): void
    {
        if (! file_exists($outputPath)) {
            throw new \Exception('Watermarked PDF was not generated.');
        }

        if (filesize($outputPath) === 0) {
            throw new \Exception('Watermarked PDF was generated but it is empty.');
        }
    }

    private function watermarkUploadedPdf(
        Request $request,
        Subject $subject,
        PdfWatermarkService $watermarkService
    ): array {
        $uploadedFile = $request->file('paper_file');

        if (! $uploadedFile) {
            throw new \Exception('No PDF file was uploaded.');
        }

        $uniqueName = $this->makeUniquePdfName(
            $uploadedFile->getClientOriginalName()
        );

        $originalRelativePath = $uploadedFile->storeAs(
            'practice-papers/original',
            $uniqueName,
            'local'
        );

        if (! $originalRelativePath) {
            throw new \Exception('Failed to store the uploaded PDF.');
        }

        $watermarkedRelativePath = 'practice-papers/watermarked/' . $uniqueName;

        $sourcePath = $this->localStoragePath($originalRelativePath);
        $outputPath = $this->localStoragePath($watermarkedRelativePath);

        if (! file_exists($sourcePath)) {
            Storage::disk('local')->delete($originalRelativePath);

            throw new \Exception('Uploaded PDF was not found after storage.');
        }

        $outputDirectory = dirname($outputPath);

        if (! is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0755, true);
        }

        $watermarkText = 'Revision Hub - ' . $subject->name;

        try {
            $watermarkService->applyWatermark(
                $sourcePath,
                $outputPath,
                $watermarkText
            );

            $this->validateGeneratedPdf($outputPath);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($originalRelativePath);

            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }

            throw new \Exception($e->getMessage());
        }

        return [
            'original_file_path' => $originalRelativePath,
            'watermarked_file_path' => $watermarkedRelativePath,
            'file_name' => $uploadedFile->getClientOriginalName(),
            'file_type' => $uploadedFile->getClientMimeType(),
        ];
    }

    public function index(Request $request)
    {
        $query = PracticePaper::with([
            'teacher:id,name',
            'subject:id,name',
        ]);

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->academic_year);
        }

        if ($request->filled('term')) {
            $query->where('term', $request->term);
        }

        $user = Auth::user();

        if ($user && $user->role === 'student') {
            $query->where('status', 'published');
        }

        if ($user && $user->role === 'teacher') {
            $query->where('teacher_id', $user->id);
        }

        return response()->json([
            'data' => $query->latest()->paginate(15),
        ]);
    }

    public function store(Request $request, PdfWatermarkService $watermarkService)
    {
        $data = $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'term' => ['nullable', 'string', 'max:50'],
            'status' => ['required', 'in:draft,published'],

            'paper_file' => [
                'required',
                'file',
                'mimes:pdf',
                'max:20480',
            ],
        ]);

        $subject = Subject::findOrFail($data['subject_id']);

        try {
            $fileData = $this->watermarkUploadedPdf(
                $request,
                $subject,
                $watermarkService
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to process the uploaded PDF.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $paper = PracticePaper::create([
            'teacher_id' => Auth::id(),
            'subject_id' => $subject->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'academic_year' => $data['academic_year'] ?? null,
            'term' => $data['term'] ?? null,
            'status' => $data['status'],

            'original_file_path' => $fileData['original_file_path'],
            'watermarked_file_path' => $fileData['watermarked_file_path'],
            'file_name' => $fileData['file_name'],
            'file_type' => $fileData['file_type'],
        ]);

        return response()->json([
            'message' => 'Practice paper uploaded and watermarked successfully.',
            'data' => $paper->load([
                'teacher:id,name',
                'subject:id,name',
            ]),
        ], 201);
    }

    public function show(PracticePaper $practicePaper)
    {
        if (! $this->canViewPaper($practicePaper)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        return response()->json([
            'data' => $practicePaper->load([
                'teacher:id,name',
                'subject:id,name',
            ]),
        ]);
    }

    public function update(Request $request, PracticePaper $practicePaper, PdfWatermarkService $watermarkService)
    {
        if (! $this->canManagePaper($practicePaper)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $data = $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'term' => ['nullable', 'string', 'max:50'],
            'status' => ['required', 'in:draft,published'],

            'paper_file' => [
                'nullable',
                'file',
                'mimes:pdf',
                'max:20480',
            ],
        ]);

        $subject = Subject::findOrFail($data['subject_id']);

        $subjectChanged = (int) $practicePaper->subject_id !== (int) $subject->id;

        $updateData = [
            'subject_id' => $subject->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'academic_year' => $data['academic_year'] ?? null,
            'term' => $data['term'] ?? null,
            'status' => $data['status'],
        ];

        /*
        |--------------------------------------------------------------------------
        | Case 1: Teacher uploaded a new PDF
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile('paper_file')) {
            try {
                $fileData = $this->watermarkUploadedPdf(
                    $request,
                    $subject,
                    $watermarkService
                );
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Failed to process the uploaded PDF.',
                    'error' => $e->getMessage(),
                ], 422);
            }

            $oldOriginalPath = $practicePaper->original_file_path;
            $oldWatermarkedPath = $practicePaper->watermarked_file_path;

            $updateData = array_merge($updateData, [
                'original_file_path' => $fileData['original_file_path'],
                'watermarked_file_path' => $fileData['watermarked_file_path'],
                'file_name' => $fileData['file_name'],
                'file_type' => $fileData['file_type'],
            ]);

            $practicePaper->update($updateData);

            if ($oldOriginalPath) {
                Storage::disk('local')->delete($oldOriginalPath);
            }

            if ($oldWatermarkedPath) {
                Storage::disk('local')->delete($oldWatermarkedPath);
            }

            return response()->json([
                'message' => 'Practice paper updated successfully.',
                'data' => $practicePaper->fresh()->load([
                    'teacher:id,name',
                    'subject:id,name',
                ]),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Case 2: Subject changed but no new PDF uploaded
        |--------------------------------------------------------------------------
        | The original PDF must be watermarked again using the new subject name.
        |--------------------------------------------------------------------------
        */
        if ($subjectChanged && $practicePaper->original_file_path) {
            if (! Storage::disk('local')->exists($practicePaper->original_file_path)) {
                return response()->json([
                    'message' => 'Original paper file not found. Please upload the paper again.',
                ], 404);
            }

            $oldWatermarkedPath = $practicePaper->watermarked_file_path;

            $uniqueName = Str::slug($data['title'] ?: 'practice-paper');

            if (! $uniqueName) {
                $uniqueName = 'practice-paper';
            }

            $uniqueName = $uniqueName . '-' . time() . '-' . Str::random(6) . '.pdf';

            $newWatermarkedRelativePath = 'practice-papers/watermarked/' . $uniqueName;

            $sourcePath = $this->localStoragePath($practicePaper->original_file_path);
            $outputPath = $this->localStoragePath($newWatermarkedRelativePath);

            $outputDirectory = dirname($outputPath);

            if (! is_dir($outputDirectory)) {
                mkdir($outputDirectory, 0755, true);
            }

            try {
                $watermarkService->applyWatermark(
                    $sourcePath,
                    $outputPath,
                    'Revision Hub - ' . $subject->name
                );

                $this->validateGeneratedPdf($outputPath);
            } catch (\Throwable $e) {
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }

                return response()->json([
                    'message' => 'Failed to regenerate the watermarked PDF.',
                    'error' => $e->getMessage(),
                ], 422);
            }

            $updateData['watermarked_file_path'] = $newWatermarkedRelativePath;

            $practicePaper->update($updateData);

            if ($oldWatermarkedPath) {
                Storage::disk('local')->delete($oldWatermarkedPath);
            }

            return response()->json([
                'message' => 'Practice paper updated successfully.',
                'data' => $practicePaper->fresh()->load([
                    'teacher:id,name',
                    'subject:id,name',
                ]),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Case 3: Metadata only update
        |--------------------------------------------------------------------------
        */
        $practicePaper->update($updateData);

        return response()->json([
            'message' => 'Practice paper updated successfully.',
            'data' => $practicePaper->fresh()->load([
                'teacher:id,name',
                'subject:id,name',
            ]),
        ]);
    }

    public function destroy(PracticePaper $practicePaper)
    {
        if (! $this->canManagePaper($practicePaper)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        if ($practicePaper->original_file_path) {
            Storage::disk('local')->delete($practicePaper->original_file_path);
        }

        if ($practicePaper->watermarked_file_path) {
            Storage::disk('local')->delete($practicePaper->watermarked_file_path);
        }

        $practicePaper->delete();

        return response()->json([
            'message' => 'Practice paper deleted successfully.',
        ]);
    }

    public function view(PracticePaper $practicePaper)
    {
        if (! $this->canViewPaper($practicePaper)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        if (! $practicePaper->watermarked_file_path) {
            return response()->json([
                'message' => 'Paper file path is missing.',
            ], 404);
        }

        if (! Storage::disk('local')->exists($practicePaper->watermarked_file_path)) {
            return response()->json([
                'message' => 'Paper file not found.',
            ], 404);
        }

        $path = $this->localStoragePath($practicePaper->watermarked_file_path);

        if (! file_exists($path)) {
            return response()->json([
                'message' => 'Paper file could not be resolved.',
            ], 404);
        }

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="practice-paper.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}