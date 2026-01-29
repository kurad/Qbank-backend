<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use App\Support\MathRenderer;
use Illuminate\Support\Collection;

class PaperGeneratorController extends Controller
{
    /**
     * Generate a PDF version of the assessment (student paper).
     * ?layout=normal|standard (default: normal).
     */
    public function generatePdf(Request $request, $id)
    {
        $layout = $request->query('layout', 'normal');

        return $layout === 'standard'
            ? $this->generateStandardPdf($request, $id)
            : $this->generateNormalPdf($request, $id);
    }

    /**
     * Generate normal PDF (handles both sectioned and non-sectioned assessments).
     */
    private function generateNormalPdf(Request $request, $id)
    {
        $assessment = Assessment::with([
            'questions.question.parent',
            'sections.questions.parent',
            'topics.gradeSubject.subject',
            'creator.school',
        ])->findOrFail($id);

        if ($assessment->creator_id !== Auth::id()) {
            return response()->json(['message' => 'You are not authorized to view this assessment.'], 403);
        }

        $school      = $assessment->creator->school;
        $subjects    = $assessment->topics->pluck('gradeSubject.subject.name')->filter()->unique();
        $gradeLevels = $assessment->topics->pluck('gradeSubject.gradeLevel.grade_name')->filter()->unique();

        $totalMarks = 0;

        // Build instructions
        $instructions = [];
        if (!empty($assessment->instructions)) {
            $instructions = preg_split('/\r\n|\r|\n/', trim($assessment->instructions));
            $instructions = array_values(array_filter($instructions, fn ($line) => trim($line) !== ''));
        }
        if (empty($instructions)) {
            $instructions = [
                'Write your name and class/grade in the spaces provided above.',
                'Answer all questions in the spaces provided. Answer sheets can be used if the space is not enough.',
                'For multiple choice questions, circle the correct answer.',
                'Show all working where necessary.',
            ];
        }

        // Base data
        $data = [
            'title'        => $assessment->title,
            'subject'      => $subjects->first() ?? 'General',
            'grade_level'  => $gradeLevels->first() ?? null,
            'topic'        => $assessment->topics->pluck('name')->first() ?? 'General',
            'created_at'   => $assessment->created_at->format('F j, Y'),
            'school'       => [
                'school_name' => $school->school_name ?? 'School Name',
                'address'     => $school->address ?? 'School Address',
                'phone'       => $school->phone ?? 'Phone Number',
                'email'       => $school->email ?? 'school@example.com',
                'logo'        => $school && $school->logo_path
                    ? storage_path('app/public/' . $school->logo_path)
                    : null,
            ],
            'instructions' => $instructions,
            'sections'     => [],
            'questions'    => [],
            'total_marks'  => 0,
        ];

        // Embed school logo as base64 (DOMPDF-safe)
        if (!empty($data['school']['logo']) && file_exists($data['school']['logo'])) {
            $mime = mime_content_type($data['school']['logo']) ?: 'image/png';
            $data['school']['logo_base64'] = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($data['school']['logo']));
        } else {
            $data['school']['logo_base64'] = null;
        }

        // Handle sectioned assessments
        if ($assessment->sections->count() > 0) {
            foreach ($assessment->sections->sortBy('ordering') as $section) {
                $sectionInstruction = MathRenderer::processHtmlWithLatex($section->instruction ?? '');

                $formattedQuestions = $this->formatQuestions(
                    $section->questions->sortBy('order'),
                    $totalMarks
                );

                // (Optional) defensive KaTeX processing in case any raw latex slipped through
                $formattedQuestions = $this->processQuestionsForKatex($formattedQuestions);

                if (!empty($formattedQuestions)) {
                    $data['sections'][] = [
                        'title'       => $section->title,
                        'instruction' => $sectionInstruction,
                        'questions'   => $formattedQuestions,
                    ];
                }
            }
        } else {
            // Non-section assessments (flat list)
            $data['questions'] = $this->formatQuestions(
                $assessment->questions->sortBy('order'),
                $totalMarks
            );

            $data['questions'] = $this->processQuestionsForKatex($data['questions']);
        }

        $data['total_marks'] = $totalMarks;

        // Inline KaTeX CSS (so DOMPDF can style KaTeX)
        $katexCssPath = base_path('node_modules/katex/dist/katex.min.css');
        $data['katexCss'] = file_exists($katexCssPath) ? file_get_contents($katexCssPath) : '';

        $view = 'pdf.normal-paper';
        $html = view($view, $data)->render();

        $pdf = Pdf::loadHTML($html)->setPaper('A4');

        return $pdf->download('assessment-' . Str::slug($assessment->title) . '.pdf');
    }

    /**
     * Generate standard PDF (handles only non-sectioned assessments).
     */
    private function generateStandardPdf(Request $request, $id)
    {
        $assessment = Assessment::with([
            'questions.question.parent',
            'topics.gradeSubject.subject',
            'creator.school',
        ])->findOrFail($id);

        if ($assessment->creator_id !== Auth::id()) {
            return response()->json(['message' => 'You are not authorized to view this assessment.'], 403);
        }

        $school      = $assessment->creator->school;
        $subjects    = $assessment->topics->pluck('gradeSubject.subject.name')->filter()->unique();
        $gradeLevels = $assessment->topics->pluck('gradeSubject.gradeLevel.grade_name')->filter()->unique();

        $totalMarks = 0;

        // Build instructions
        $instructions = [];
        if (!empty($assessment->instructions)) {
            $instructions = preg_split('/\r\n|\r|\n/', trim($assessment->instructions));
            $instructions = array_values(array_filter($instructions, fn ($line) => trim($line) !== ''));
        }
        if (empty($instructions)) {
            $instructions = [
                'Attempt all questions.',
                'Show all working where required.',
                'For multiple-choice questions, circle the correct answer.',
            ];
        }

        // Prepare base data
        $data = [
            'title'        => $assessment->title,
            'subject'      => $subjects->first() ?? 'General',
            'grade_level'  => $gradeLevels->first() ?? null,
            'topic'        => $assessment->topics->pluck('name')->first() ?? 'General',
            'created_at'   => $assessment->created_at->format('F j, Y'),
            'school'       => [
                'school_name' => $school->school_name ?? 'School Name',
                'address'     => $school->address ?? 'School Address',
                'phone'       => $school->phone ?? 'Phone Number',
                'email'       => $school->email ?? 'school@example.com',
                'logo'        => $school && $school->logo_path
                    ? storage_path('app/public/' . $school->logo_path)
                    : null,
            ],
            'instructions' => $instructions,
            'sections'     => [],
            'questions'    => $this->formatQuestions($assessment->questions->sortBy('order'), $totalMarks),
            'total_marks'  => 0,
        ];

        // Embed school logo as base64 for DOMPDF reliability
        if (!empty($data['school']['logo']) && file_exists($data['school']['logo'])) {
            $mime = mime_content_type($data['school']['logo']) ?: 'image/png';
            $data['school']['logo_base64'] = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($data['school']['logo']));
        } else {
            $data['school']['logo_base64'] = null;
        }

        $data['questions'] = $this->processQuestionsForKatex($data['questions']);

        $data['total_marks'] = $totalMarks;

        // Inline KaTeX CSS so DOMPDF can style the math
        $katexCssPath = base_path('node_modules/katex/dist/katex.min.css');
        $data['katexCss'] = file_exists($katexCssPath) ? file_get_contents($katexCssPath) : '';

        $view = 'pdf.standard-paper';
        $html = view($view, $data)->render();

        $pdf = Pdf::loadHTML($html)->setPaper('A4');

        return $pdf->download('assessment-' . Str::slug($assessment->title) . '.pdf');
    }

    // =========================================================================
    // FORMAT QUESTIONS (MAIN)
    // =========================================================================

    /**
     * Transform the Eloquent questions collection into a Blade-friendly array.
     *
     * Key fixes:
     *  - Prefer pivot (assessment_questions) values for type/options/marks/image when present
     *  - If type is MCQ but there are NO real options, force short_answer (so working space shows)
     *  - Normalize options (matching formats included)
     *  - Render KaTeX server-side via MathRenderer
     *  - Base64 embed images for DOMPDF
     */
    private function formatQuestions(Collection $questions, int &$totalMarks): array
    {
        $out = [];

        foreach ($questions->values() as $qModel) {
            $q = $this->transformQuestionModelToArray($qModel);

            // Resolve image path to absolute filesystem path (if any)
            if (!empty($q['image_path'])) {
                $q['image_path'] = $this->resolveImagePath($q['image_path']);
            }

            // Render KaTeX into HTML
            $q['clean_html'] = $this->toHtmlWithKatex($q['raw_html'] ?? '');

            // Image to base64 (DOMPDF)
            $q['image_base64'] = $this->embedImageAsBase64(
                $q['image_path'] ?? null,
                $q['image_base64'] ?? null
            );

            // Normalize options (MCQ, matching, true/false)
            if (!empty($q['options']) || ($q['type'] ?? null) === 'true_false') {
                $q['options'] = $this->normalizeOptions($q['options'], $q['type'] ?? null);
            } else {
                $q['options'] = [];
            }

            // Sub-questions
            $subQuestions = [];
            if (!empty($q['sub_raw'])) {
                foreach ($q['sub_raw'] as $subModel) {
                    $sub = $this->transformQuestionModelToArray($subModel);

                    if (!empty($sub['image_path'])) {
                        $sub['image_path'] = $this->resolveImagePath($sub['image_path']);
                    }

                    $sub['clean_html'] = $this->toHtmlWithKatex($sub['raw_html'] ?? '');

                    $sub['image_base64'] = $this->embedImageAsBase64(
                        $sub['image_path'] ?? null,
                        $sub['image_base64'] ?? null
                    );

                    if (!empty($sub['options']) || ($sub['type'] ?? null) === 'true_false') {
                        $sub['options'] = $this->normalizeOptions($sub['options'], $sub['type'] ?? null);
                    } else {
                        $sub['options'] = [];
                    }

                    $subQuestions[] = [
                        'clean_html'   => $sub['clean_html'],
                        'image_base64' => $sub['image_base64'],
                        'options'      => $sub['options'] ?? [],
                        'type'         => $sub['type'] ?? null,
                        'marks'        => (int) ($sub['marks'] ?? 0),
                    ];

                    $totalMarks += (int) ($sub['marks'] ?? 0);
                }
            }

            $out[] = [
                'clean_html'    => $q['clean_html'],
                'image_base64'  => $q['image_base64'],
                'options'       => $q['options'] ?? [],
                'type'          => $q['type'] ?? null,
                'marks'         => (int) ($q['marks'] ?? 0),
                'sub_questions' => $subQuestions,
            ];

            $totalMarks += (int) ($q['marks'] ?? 0);
        }

        return $out;
    }

    // =========================================================================
    // TRANSFORM MODEL → ARRAY (FIXED: PIVOT PREFERRED + OVERRIDE MCQ W/NO OPTIONS)
    // =========================================================================

    private function transformQuestionModelToArray($qModel): array
    {
        // The "real" question object might live under $qModel->question (pivot relation)
        $question = null;

        if (isset($qModel->question) && is_object($qModel->question)) {
            $question = $qModel->question;
        } elseif (is_object($qModel)) {
            $question = $qModel;
        } elseif (is_array($qModel)) {
            $question = (object) $qModel;
        }

        // Extract stem HTML
        $rawHtml = '';
        if (isset($question->question) && !empty($question->question)) {
            $rawHtml = (string) $question->question;
        } elseif (isset($question->text) && !empty($question->text)) {
            $rawHtml = (string) $question->text;
        } elseif (isset($question->content) && !empty($question->content)) {
            $rawHtml = (string) $question->content;
        }

        // Sub-questions (if your model supplies them)
        $subRaw = [];
        if (isset($question->sub_questions)) {
            $subRaw = $question->sub_questions;
        }

        // Prefer pivot values if present (assessment_questions table)
        $pivotType    = $qModel->question_type ?? null;
        $pivotMarks   = $qModel->marks ?? null;
        $pivotOptions = $qModel->options ?? null;
        $pivotImage   = $qModel->question_image ?? null;

        // Question model values
        $modelType    = $question->question_type ?? ($question->type ?? null);
        $modelMarks   = $question->marks ?? null;
        $modelOptions = $question->options ?? null;
        $modelImage   = $question->question_image ?? null;

        // Choose pivot first, else model
        $type      = $pivotType ?? $modelType;
        $marks     = (int) ($pivotMarks ?? $modelMarks ?? 0);
        $options   = $pivotOptions ?? $modelOptions;
        $imagePath = $pivotImage ?? $modelImage;

        // If type missing, infer it
        if (!$type) {
            $type = $this->inferType($rawHtml, $options);
        }

        // CRITICAL FIX:
        // If type says MCQ but there are no real options, force short_answer
        if ($type === 'mcq' && !$this->hasRealOptions($options)) {
            $type = 'short_answer';
        }

        // Also: if it looks matching, force matching (even if DB says mcq)
        if ($type !== 'matching' && $this->looksLikeMatchingOptions($options)) {
            $type = 'matching';
        }

        // Also: if true/false detected, force true_false
        if ($type !== 'true_false' && $this->looksLikeTrueFalseOptions($options)) {
            $type = 'true_false';
        }

        return [
            'raw_html'     => $rawHtml,
            'type'         => $type,
            'marks'        => $marks,
            'options'      => $options,
            'image_path'   => $imagePath,
            'image_base64' => null,
            'sub_raw'      => is_iterable($subRaw) ? $subRaw : [],
        ];
    }

    // =========================================================================
    // KATEX POST-PROCESS (DEFENSIVE)
    // =========================================================================

    /**
     * Walk the questions structure and apply KaTeX processing to any HTML text fields.
     * Also converts option images to base64 for DOMPDF compatibility.
     */
    private function processQuestionsForKatex(array $questions): array
    {
        $processOption = function (&$opt) {
            if (is_string($opt)) {
                $opt = ['text' => $opt];
            }
            if (!is_array($opt)) return;

            if (!empty($opt['text'])) {
                $opt['text'] = MathRenderer::processHtmlWithLatex((string) $opt['text']);
            }
            if (array_key_exists('left', $opt)) {
                $opt['left'] = MathRenderer::processHtmlWithLatex((string) ($opt['left'] ?? ''));
            }
            if (array_key_exists('right', $opt)) {
                $opt['right'] = MathRenderer::processHtmlWithLatex((string) ($opt['right'] ?? ''));
            }

            if (!empty($opt['image']) && empty($opt['image_base64'])) {
                $resolved = $this->resolveImagePath((string) $opt['image']);
                $opt['image_base64'] = $this->embedImageAsBase64($resolved, null);
            }
        };

        $processNode = function (&$node) use ($processOption) {
            if (!empty($node['clean_html'])) {
                $html = (string) $node['clean_html'];

                $alreadyRendered =
                    str_contains($html, 'data:image/svg+xml;base64,') ||
                    str_contains($html, 'katex') ||
                    str_contains($html, 'math-inline') ||
                    str_contains($html, 'math-block');

                if (!$alreadyRendered) {
                    $node['clean_html'] = MathRenderer::processHtmlWithLatex($html);
                }
            }

            if (!empty($node['options']) && is_array($node['options'])) {
                foreach ($node['options'] as &$opt) {
                    $processOption($opt);
                }
                unset($opt);
            }
        };

        foreach ($questions as &$q) {
            $processNode($q);

            if (!empty($q['sub_questions']) && is_array($q['sub_questions'])) {
                foreach ($q['sub_questions'] as &$sub) {
                    $processNode($sub);
                }
                unset($sub);
            }
        }
        unset($q);

        return $questions;
    }

    // =========================================================================
    // OPTIONS NORMALIZATION (MATCHING + TRUE/FALSE + MCQ)
    // =========================================================================

    private function normalizeOptions($rawOptions, ?string $type): array
    {
        // true/false auto options
        if ($type === 'true_false') {
            return [
                ['text' => 'True'],
                ['text' => 'False'],
            ];
        }

        if (empty($rawOptions)) {
            return [];
        }

        // Decode JSON string if needed
        if (is_string($rawOptions)) {
            $decoded = json_decode($rawOptions, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $rawOptions = $decoded;
            } else {
                $rawOptions = preg_split('/\r\n|\r|\n/', trim($rawOptions)) ?: [];
            }
        }

        // Convert Collections
        if ($rawOptions instanceof \Illuminate\Support\Collection) {
            $rawOptions = $rawOptions->all();
        }

        // MATCHING: supports:
        // A) {left:[], right:[]}
        // B) [[left=>.., right=>..], ...]
        // C) ["left|right", ...]
        if ($type === 'matching' || (is_array($rawOptions) && $this->looksLikeMatching($rawOptions))) {
            // Format A
            if (is_array($rawOptions) && isset($rawOptions['left'], $rawOptions['right'])) {
                $lefts  = is_array($rawOptions['left']) ? $rawOptions['left'] : [$rawOptions['left']];
                $rights = is_array($rawOptions['right']) ? $rawOptions['right'] : [$rawOptions['right']];

                $max = max(count($lefts), count($rights));
                $pairs = [];

                for ($i = 0; $i < $max; $i++) {
                    $pairs[] = [
                        'left'  => (string) ($lefts[$i] ?? ''),
                        'right' => (string) ($rights[$i] ?? ''),
                    ];
                }
                return $pairs;
            }

            // Format B/C
            $pairs = [];
            foreach ((array) $rawOptions as $item) {
                if (is_array($item) && (array_key_exists('left', $item) || array_key_exists('right', $item))) {
                    $pairs[] = [
                        'left'  => (string) ($item['left'] ?? ''),
                        'right' => (string) ($item['right'] ?? ''),
                    ];
                } elseif (is_string($item) && str_contains($item, '|')) {
                    [$l, $r] = array_pad(explode('|', $item, 2), 2, '');
                    $pairs[] = ['left' => trim($l), 'right' => trim($r)];
                } else {
                    $pairs[] = ['left' => (string) $item, 'right' => ''];
                }
            }

            return $pairs;
        }

        // DEFAULT: MCQ list
        $normalized = [];
        foreach ((array) $rawOptions as $item) {
            if (is_array($item)) {
                $text = (string) ($item['text'] ?? $item['value'] ?? '');
                $normalized[] = ['text' => $text] + $item; // keep extra fields like image if present
            } else {
                $normalized[] = ['text' => (string) $item];
            }
        }

        return $normalized;
    }

    // =========================================================================
    // TYPE INFERENCE + DETECTION HELPERS
    // =========================================================================

    private function inferType(string $rawHtml, $options): ?string
    {
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = json_last_error() === JSON_ERROR_NONE ? $decoded : [$options];
        }
        if ($options instanceof \Illuminate\Support\Collection) {
            $options = $options->all();
        }

        // If no real options -> short answer
        if (!$this->hasRealOptions($options)) {
            return 'short_answer';
        }

        // Matching
        if ($this->looksLikeMatching((array) $options)) {
            return 'matching';
        }

        // True/False
        if ($this->looksLikeTrueFalseOptions($options)) {
            return 'true_false';
        }

        return 'mcq';
    }

    private function hasRealOptions($options): bool
    {
        if (empty($options)) return false;

        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = json_last_error() === JSON_ERROR_NONE ? $decoded : [$options];
        }

        if ($options instanceof \Illuminate\Support\Collection) {
            $options = $options->all();
        }

        // matching format
        if (is_array($options) && isset($options['left'], $options['right'])) {
            $lefts  = array_filter((array) $options['left'], fn ($x) => trim((string) $x) !== '');
            $rights = array_filter((array) $options['right'], fn ($x) => trim((string) $x) !== '');
            return count($lefts) > 0 || count($rights) > 0;
        }

        $clean = array_filter((array) $options, function ($opt) {
            if (is_string($opt)) {
                $t = trim($opt);
                return $t !== '' && !in_array($t, ['A.', 'B.', 'C.', 'D.'], true);
            }
            if (is_array($opt)) {
                $t = trim((string) ($opt['text'] ?? $opt['value'] ?? ''));
                return $t !== '';
            }
            return false;
        });

        return !empty($clean);
    }

    private function looksLikeMatchingOptions($options): bool
    {
        if (empty($options)) return false;

        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = json_last_error() === JSON_ERROR_NONE ? $decoded : [$options];
        }
        if ($options instanceof \Illuminate\Support\Collection) {
            $options = $options->all();
        }

        return is_array($options) && (
            (isset($options['left'], $options['right'])) || $this->looksLikeMatching((array) $options)
        );
    }

    private function looksLikeTrueFalseOptions($options): bool
    {
        if (empty($options)) return false;

        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = json_last_error() === JSON_ERROR_NONE ? $decoded : [$options];
        }
        if ($options instanceof \Illuminate\Support\Collection) {
            $options = $options->all();
        }

        $arr = (array) $options;

        // typical case: ["True","False"] or [{text:"True"},{text:"False"}]
        if (count($arr) !== 2) return false;

        $vals = array_map(function ($o) {
            return strtolower(is_array($o) ? (string) ($o['text'] ?? '') : (string) $o);
        }, $arr);

        return in_array('true', $vals, true) && in_array('false', $vals, true);
    }

    private function looksLikeMatching(array $opts): bool
    {
        if (empty($opts)) return false;

        foreach ($opts as $item) {
            if (is_array($item) && (isset($item['left']) || isset($item['right']))) {
                return true;
            }
            if (is_string($item) && str_contains($item, '|')) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // HTML + IMAGE HELPERS
    // =========================================================================

    private function toHtmlWithKatex(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') return '';

        try {
            return MathRenderer::processHtmlWithLatex($html);
        } catch (\Throwable $e) {
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }
    }

    private function resolveImagePath(?string $imagePath): ?string
    {
        if (empty($imagePath)) return null;

        $relative = ltrim($imagePath, '/');

        $directPublicPath = public_path($relative);
        if (file_exists($directPublicPath)) return $directPublicPath;

        $storagePublicPath = public_path('storage/' . $relative);
        if (file_exists($storagePublicPath)) return $storagePublicPath;

        $storagePath = storage_path('app/public/' . $relative);
        if (file_exists($storagePath)) return $storagePath;

        return $imagePath;
    }

    private function embedImageAsBase64(?string $path, ?string $existingBase64): ?string
    {
        if (!empty($existingBase64)) return $existingBase64;
        if (empty($path)) return null;

        if (!file_exists($path)) {
            $path = $this->resolveImagePath($path);
            if (empty($path) || !file_exists($path)) return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';
        $data = base64_encode(@file_get_contents($path));

        return $data ? ('data:' . $mime . ';base64,' . $data) : null;
    }
}
