<?php

namespace App\Http\Controllers;

use App\Models\EnglishWritingDraft;
use App\Models\TutorSession;
use App\Services\AI\AIGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnglishWritingController extends Controller
{
    private function requireEnglishStudentSession(Request $request, TutorSession $session): void
    {
        abort_unless($request->user() && $request->user()->role === 'student'
            && (int) $session->student_id === (int) $request->user()->id, 403);
        $session->loadMissing('gradeSubject.subject');
        $name = (string) ($session->gradeSubject?->subject?->name ?? '');
        abort_unless(preg_match('/\benglish\b/i', $name) === 1, 404, 'English writing is available only for English sessions.');
    }

    public function index(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $this->requireEnglishStudentSession($request, $tutorSession);
        return response()->json(['data' => EnglishWritingDraft::where('tutor_session_id', $tutorSession->id)
            ->where('student_id', $request->user()->id)->orderBy('revision_number')->get()]);
    }

    public function store(Request $request, TutorSession $tutorSession, AIGateway $ai): JsonResponse
    {
        $this->requireEnglishStudentSession($request, $tutorSession);
        abort_unless($tutorSession->status === 'active', 422, 'Writing is read-only after the session ends.');
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'min:30', 'max:15000'],
            'analyze' => ['sometimes', 'boolean'],
        ]);
        $latest = EnglishWritingDraft::where('tutor_session_id', $tutorSession->id)->orderByDesc('revision_number')->first();
        if ($latest && trim($latest->body) === trim($data['body'])) {
            return response()->json(['data' => $latest, 'unchanged' => true]);
        }
        $feedback = null;
        if ($data['analyze'] ?? false) {
            $objective = $tutorSession->learningObjective?->objective;
            $feedback = $ai->text([
                ['role' => 'system', 'content' => 'You are a supportive secondary-school English writing coach. Give formative feedback on the student draft, not a rewritten essay. Be specific and constructive. Include short headings: Strengths, Grammar and punctuation, Organization and clarity, Vocabulary, Next revision steps. Identify up to three high-impact errors with concise explanations and short excerpts, and ask the learner to revise independently. Respect valid English variants, meaning, and context. Do not claim to have checked for plagiarism. Do not award a formal grade. Do not reveal private details.'],
                ['role' => 'user', 'content' => 'Current learning objective: '.($objective ?: 'English writing practice')."\nWriting title: ".($data['title'] ?? 'Untitled')."\nStudent draft:\n".$data['body']],
            ], ['temperature' => 0.25, 'max_tokens' => 950]);
        }
        // Atomic revision numbering per session; lock the parent row to serialize concurrent submissions.
        $draft = DB::transaction(function () use ($tutorSession, $request, $data, $feedback) {
            TutorSession::whereKey($tutorSession->id)->lockForUpdate()->firstOrFail();
            $lastNumber = EnglishWritingDraft::where('tutor_session_id', $tutorSession->id)->max('revision_number') ?? 0;
            return EnglishWritingDraft::create([
                'tutor_session_id' => $tutorSession->id,
                'student_id' => $request->user()->id,
                'revision_number' => $lastNumber + 1,
                'title' => $data['title'] ?? null,
                'body' => $data['body'],
                'feedback' => $feedback,
            ]);
        });
        return response()->json(['data' => $draft], 201);
    }
}
