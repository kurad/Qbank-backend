<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use App\Support\MathRenderer;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;


class PaperGeneratorController extends Controller
{
    /**
     * Generate a PDF version of the assessment (student paper).
     *
     * Chooses between the "normal" and "standard" layouts based on
     * a "layout" query parameter: ?layout=normal|standard (default: normal).
     */
    public function generatePdf(Request $request, $id)
    {
        $layout = $request->query('layout', 'normal');

        if ($layout === 'standard') {
            return $this->generateStandardPdf($request, $id);
        } else {
            return $this->generateNormalPdf($request, $id);
        }
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
            return response()->json([
                'message' => 'You are not authorized to view this assessment.',
            ], 403);
        }

        $school      = $assessment->creator->school;
        $subjects    = $assessment->topics->pluck('gradeSubject.subject.name')->filter()->unique();
        $gradeLevels = $assessment->topics->pluck('gradeSubject.gradeLevel.grade_name')->filter()->unique();
        $questionNumber = 1; // optional if Blade uses $loop->iteration
        $totalMarks     = 0;

        // Build instructions
        $instructions = [];
        if (!empty($assessment->instructions)) {
            $instructions = preg_split('/\r\n|\r|\n/', trim($assessment->instructions));
            $instructions = array_values(array_filter($instructions, fn($line) => trim($line) !== ''));
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

                // Process section instruction to render any math in it
                $sectionInstruction = $section->instruction ?? '';
                $sectionInstruction = MathRenderer::processHtmlWithLatex($sectionInstruction);

                $formattedQuestions = $this->formatQuestions(
                    $section->questions->sortBy('order'),
                    $questionNumber,
                    $totalMarks
                );

                // Process KaTeX on all question text and options
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
                $questionNumber,
                $totalMarks
            );

            // Process KaTeX on all question text and options
            $data['questions'] = $this->processQuestionsForKatex($data['questions']);
        }

        // Update total marks after formatting
        $data['total_marks'] = $totalMarks;

        // Inline KaTeX CSS (so DOMPDF can style KaTeX)
        $katexCssPath = base_path('node_modules/katex/dist/katex.min.css');
        $data['katexCss'] = file_exists($katexCssPath) ? file_get_contents($katexCssPath) : '';

        // Render and return DOMPDF (no Browsershot/JS needed)
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
            return response()->json([
                'message' => 'You are not authorized to view this assessment.',
            ], 403);
        }

        $school = $assessment->creator->school;
        $subjects = $assessment->topics->pluck('gradeSubject.subject.name')->filter()->unique();
        $gradeLevels = $assessment->topics->pluck('gradeSubject.gradeLevel.grade_name')->filter()->unique();
        $questionNumber = 1;
        $totalMarks = 0;

        // Build instructions
        $instructions = [];
        if (!empty($assessment->instructions)) {
            $instructions = preg_split('/\r\n|\r|\n/', trim($assessment->instructions));
            $instructions = array_values(array_filter($instructions, fn($line) => trim($line) !== ''));
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
            'questions'    => $this->formatQuestions(
                $assessment->questions->sortBy('order'),
                $questionNumber,
                $totalMarks
            ),
            'total_marks'  => $totalMarks,
        ];

        // Embed school logo as base64 for DOMPDF reliability
        if (!empty($data['school']['logo']) && file_exists($data['school']['logo'])) {
            $mime = mime_content_type($data['school']['logo']) ?: 'image/png';
            $data['school']['logo_base64'] = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($data['school']['logo']));
        } else {
            $data['school']['logo_base64'] = null;
        }

        // Process KaTeX on all question text fields
        $data['questions'] = $this->processQuestionsForKatex($data['questions']);

        // Inline KaTeX CSS so DOMPDF can style the math
        $katexCssPath = base_path('node_modules/katex/dist/katex.min.css');
        $data['katexCss'] = file_exists($katexCssPath) ? file_get_contents($katexCssPath) : '';

        // Render view and PDF via DOMPDF
        $view = 'pdf.standard-paper';
        $html = view($view, $data)->render();

        $pdf = Pdf::loadHTML($html)->setPaper('A4');

        return $pdf->download('assessment-' . Str::slug($assessment->title) . '.pdf');
    }

    /**
     * Walk the questions structure and apply KaTeX processing to any HTML text fields.
     * Also converts option images to base64 for DOMPDF compatibility.
     */
    private function processQuestionsForKatex(array $questions): array
    {
        foreach ($questions as &$q) {
            $q['clean_html'] = MathRenderer::processHtmlWithLatex($q['clean_html'] ?? '');

            // Options on main question
            if (!empty($q['options'])) {
                foreach ($q['options'] as &$opt) {
                    if (is_array($opt)) {
                        // Process text with KaTeX if present
                        if (isset($opt['text'])) {
                            $opt['text'] = MathRenderer::processHtmlWithLatex($opt['text'] ?? '');
                        }
                        // Process left/right for matching questions
                        if (isset($opt['left'])) {
                            $opt['left'] = MathRenderer::processHtmlWithLatex($opt['left'] ?? '');
                        }
                        if (isset($opt['right'])) {
                            $opt['right'] = MathRenderer::processHtmlWithLatex($opt['right'] ?? '');
                        }
                        // Convert option images to base64
                        if (!empty($opt['image']) && empty($opt['image_base64'])) {
                            $resolved = $this->resolveImagePath($opt['image']);
                            $opt['image_base64'] = $this->embedImageAsBase64($resolved, null);
                        }
                    } elseif (is_string($opt)) {
                        $opt = MathRenderer::processHtmlWithLatex($opt);
                    }
                }
                unset($opt);
            }

            // Sub-questions
            if (!empty($q['sub_questions'])) {
                foreach ($q['sub_questions'] as &$sub) {
                    $sub['clean_html'] = MathRenderer::processHtmlWithLatex($sub['clean_html'] ?? '');

                    // Options on sub-question
                    if (!empty($sub['options'])) {
                        foreach ($sub['options'] as &$op) {
                            if (is_array($op)) {
                                // Process text with KaTeX if present
                                if (isset($op['text'])) {
                                    $op['text'] = MathRenderer::processHtmlWithLatex($op['text'] ?? '');
                                }
                                // Process left/right for matching questions
                                if (isset($op['left'])) {
                                    $op['left'] = MathRenderer::processHtmlWithLatex($op['left'] ?? '');
                                }
                                if (isset($op['right'])) {
                                    $op['right'] = MathRenderer::processHtmlWithLatex($op['right'] ?? '');
                                }
                                // Convert option images to base64
                                if (!empty($op['image']) && empty($op['image_base64'])) {
                                    $resolved = $this->resolveImagePath($op['image']);
                                    $op['image_base64'] = $this->embedImageAsBase64($resolved, null);
                                }
                            } elseif (is_string($op)) {
                                $op = MathRenderer::processHtmlWithLatex($op);
                            }
                        }
                        unset($op);
                    }
                }
                unset($sub);
            }
        }
        unset($q);

        return $questions;
    }


    /**
     * Format questions + parent/child structure
     */
    private function formatQuestions_old($questions, &$questionNumber, &$totalMarks)
    {
        $output = [];
        $groups = [];
        $parentIds = $questions->pluck('parent_question_id')->filter()->unique()->all();

        foreach ($questions as $q) {

            if (!$q) continue;

            $isParent = in_array($q->id, $parentIds);
            $parentId = $q->parent_question_id;

            //--------------------------------------
            // IMAGE PATH for PDF views
            //--------------------------------------
            $imagePath = null;
            if ($q->question_image) {
                $img = $q->question_image;
                $relative = ltrim($img, '/');

                // 1) Check if file is directly under public/<relative>
                $directPublicPath = public_path($relative);
                if (file_exists($directPublicPath)) {
                    $imagePath = $directPublicPath;
                } else {
                    // 2) Fallback: treat as public/storage/<relative>
                    $storagePublicPath = public_path('storage/' . $relative);
                    if (file_exists($storagePublicPath)) {
                        $imagePath = $storagePublicPath;
                    }
                }
            }

            //--------------------------------------
            // MAIN PARENT QUESTION
            //--------------------------------------
            if (!$parentId && $isParent) {

                $groups[$q->id] = count($output);

                $parentText = $this->extractQuestionText($q->question);

                $output[] = [
                    'number'        => $questionNumber++,
                    // Clean HTML / text for parent stem
                    'clean_html'    => $parentText,
                    'text'          => $parentText,
                    'type'          => 'parent_group',
                    'is_math'       => $q->is_math,
                    'image'         => $imagePath,
                    'sub_questions' => []
                ];
                continue;
            }

            //--------------------------------------
            // STANDALONE QUESTION
            //--------------------------------------
            if (!$parentId && !$isParent) {

                $standaloneText = $this->extractQuestionText($q->question);

                $formatted = [
                    'number'       => $questionNumber++,
                    'clean_html'   => $standaloneText,
                    'text'         => $standaloneText,
                    'marks'        => $q->marks ?? 1,
                    'type'         => $q->question_type,
                    'image'        => $imagePath,
                    'is_math'      => $q->is_math,
                    'options'      => $this->parseOptions($q)
                ];

                $totalMarks += $formatted['marks'];
                $output[] = $formatted;

                continue;
            }

            //--------------------------------------
            // CHILD QUESTION
            //--------------------------------------
            if (!isset($groups[$parentId])) {
                $parent = $q->parent;

                // IMAGE PATH for parent
                $parentImagePath = null;
                if ($parent && $parent->question_image) {
                    $img = $parent->question_image;
                    $relative = ltrim($img, '/');
                    $directPublicPath = public_path($relative);
                    if (file_exists($directPublicPath)) {
                        $parentImagePath = $directPublicPath;
                    } else {
                        $storagePublicPath = public_path('storage/' . $relative);
                        if (file_exists($storagePublicPath)) {
                            $parentImagePath = $storagePublicPath;
                        }
                    }
                }

                $groups[$parentId] = count($output);

                $parentSource = $parent ? $parent->question : $q->question;
                $parentStem   = $this->extractQuestionText($parentSource);

                $output[] = [
                    'number'        => $questionNumber++,
                    'clean_html'    => $parentStem,
                    'text'          => $parentStem,
                    'type'          => 'parent_group',
                    'is_math'       => $parent ? $parent->is_math : 0,
                    'image'         => $parentImagePath,
                    'sub_questions' => []
                ];
            }

            // IMAGE PATH for sub-question
            $subImagePath = null;
            if ($q->question_image) {
                $img = $q->question_image;
                $relative = ltrim($img, '/');
                $directPublicPath = public_path($relative);
                if (file_exists($directPublicPath)) {
                    $subImagePath = $directPublicPath;
                } else {
                    $storagePublicPath = public_path('storage/' . $relative);
                    if (file_exists($storagePublicPath)) {
                        $subImagePath = $storagePublicPath;
                    }
                }
            }

            $groupIndex = $groups[$parentId];
            $sub = &$output[$groupIndex]['sub_questions'];
            $label = chr(ord('a') + count($sub));

            $subText = $this->extractQuestionText($q->question);

            $sub[] = [
                'label'        => $label,
                'clean_html'   => $subText,
                'text'         => $subText,
                'type'         => $q->question_type,
                'marks'        => $q->marks ?? 1,
                'is_math'      => $q->is_math,
                'image'        => $subImagePath,
                'options'      => $this->parseOptions($q)
            ];

            $totalMarks += $q->marks ?? 0;
        }

        return $output;
    }


////////////////////////////////////////////////////////////////////////
// FORMAT QUESTIONS
////////////////////////////////////////////////////////////////////////
    /**
     * Transform the Eloquent questions collection into a Blade‑friendly array:
     *  - Renders LaTeX to KaTeX HTML+MathML
     *  - Base64 embeds images
     *  - Normalizes options (MCQ, matching, true/false)
     *  - Handles sub‑questions recursively
     *  - Accumulates total marks (by reference)
     *
     * @param \Illuminate\Support\Collection $questions
     * @param int $questionNumber (kept for API parity; not used if Blade uses $loop->iteration)
     * @param int &$totalMarks
     * @return array
     */
    private function formatQuestions(Collection $questions, int $questionNumber, int &$totalMarks): array
    {
        $out = [];

        foreach ($questions->values() as $qModel) {
            $q = $this->transformQuestionModelToArray($qModel);

            // Ensure image path is resolved to absolute path
            if (!empty($q['image_path'])) {
                $q['image_path'] = $this->resolveImagePath($q['image_path']);
            }

            // Clean & KaTeX render main HTML
            $q['clean_html'] = $this->toHtmlWithKatex($q['raw_html'] ?? '');

            // Image to base64 (if present)
            $q['image_base64'] = $this->embedImageAsBase64(
                $q['image_path'] ?? null,
                $q['image_base64'] ?? null
            );

            // Normalize options (MCQ, matching, or true/false)
            if (!empty($q['options']) || $q['type'] === 'true_false') {
                $q['options'] = $this->normalizeOptions($q['options'], $q['type'] ?? null);
            }

            // Sub‑questions
            $q['sub_questions'] = [];
            if (!empty($q['sub_raw'])) {
                foreach ($q['sub_raw'] as $subModel) {
                    $sub = $this->transformQuestionModelToArray($subModel);

                    // Ensure image path is resolved
                    if (!empty($sub['image_path'])) {
                        $sub['image_path'] = $this->resolveImagePath($sub['image_path']);
                    }

                    $sub['clean_html'] = $this->toHtmlWithKatex($sub['raw_html'] ?? '');
                    $sub['image_base64'] = $this->embedImageAsBase64(
                        $sub['image_path'] ?? null,
                        $sub['image_base64'] ?? null
                    );

                    if (!empty($sub['options']) || $sub['type'] === 'true_false') {
                        $sub['options'] = $this->normalizeOptions($sub['options'], $sub['type'] ?? null);
                    }

                    // Minimal sub‑question payload for Blade
                    $q['sub_questions'][] = [
                        'clean_html'   => $sub['clean_html'],
                        'image_base64' => $sub['image_base64'],
                        'options'      => $sub['options'] ?? [],
                        'type'         => $sub['type'] ?? null,
                        'marks'        => (int) ($sub['marks'] ?? 0),
                    ];

                    // Add marks
                    $totalMarks += (int) ($sub['marks'] ?? 0);
                }
            }

            // Build final question payload
            $out[] = [
                'clean_html'   => $q['clean_html'],
                'image_base64' => $q['image_base64'],
                'options'      => $q['options'] ?? [],
                'type'         => $q['type'] ?? null,
                'marks'        => (int) ($q['marks'] ?? 0),
                'sub_questions' => $q['sub_questions'],
            ];

            // Add marks
            $totalMarks += (int) ($q['marks'] ?? 0);
        }

        return $out;
    }

////////////////////////////////////////////////////////////////////////
// HELPERS
////////////////////////////////////////////////////////////////////////

    /**
     * Safely extract fields from your question model regardless of nesting.
     * Adjust keys to match your schema if needed.
     */
    private function transformQuestionModelToArray($qModel): array
    {
        // Common sources (adjust according to your DB schema):
        // - $qModel->question->content_html or ->text/html/body
        // - $qModel->content / $qModel->text / $qModel->html
        // - $qModel->image_path / $qModel->question->image_path
        // - $qModel->options (array|string|json) / $qModel->question->options
        // - $qModel->marks / $qModel->question->marks
        // - $qModel->type  (e.g., 'mcq', 'matching', 'short_answer')
        // - $qModel->children / $qModel->subQuestions

        $rawHtml = Arr::get($qModel, 'question.content_html')
            ?? Arr::get($qModel, 'question.text')
            ?? Arr::get($qModel, 'content')
            ?? Arr::get($qModel, 'text')
            ?? Arr::get($qModel, 'html')
            ?? '';

        $type = Arr::get($qModel, 'type')
            ?? Arr::get($qModel, 'question.type')
            ?? null;

        $marks = Arr::get($qModel, 'marks')
            ?? Arr::get($qModel, 'question.marks')
            ?? 0;

        $imagePath = Arr::get($qModel, 'image_path')
            ?? Arr::get($qModel, 'question.image_path');

        $imageBase64 = Arr::get($qModel, 'image_base64')
            ?? Arr::get($qModel, 'question.image_base64');

        $options = Arr::get($qModel, 'options')
            ?? Arr::get($qModel, 'question.options');

        // Sub questions list; support multiple conventions
        $subRaw = Arr::get($qModel, 'subQuestions')
            ?? Arr::get($qModel, 'children')
            ?? Arr::get($qModel, 'question.children')
            ?? [];

        // Auto‑derive type when not explicitly set
        if (!$type) {
            $type = $this->inferType($rawHtml, $options);
        }

        return [
            'raw_html'    => $rawHtml,
            'type'        => $type,
            'marks'       => $marks,
            'options'     => $options,
            'image_path'  => $imagePath,
            'image_base64' => $imageBase64,
            'sub_raw'     => is_iterable($subRaw) ? $subRaw : [],
        ];
    }

    /**
     * Convert LaTeX delimiters into KaTeX HTML+MathML using the server‑side renderer.
     * Also handles empty input gracefully.
     */
    private function toHtmlWithKatex(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        // Optional: escape currency $ to avoid false positives if needed.
        // $html = preg_replace('/\$(\d[\d,\.]*)/', '\\\$$1', $html);

        return MathRenderer::processHtmlWithLatex($html);
    }

    /**
     * Resolve image path to absolute filesystem path.
     * Tries multiple locations: direct public, storage, etc.
     */
    private function resolveImagePath(?string $imagePath): ?string
    {
        if (empty($imagePath)) {
            return null;
        }

        $relative = ltrim($imagePath, '/');

        // Try direct public path first
        $directPublicPath = public_path($relative);
        if (file_exists($directPublicPath)) {
            return $directPublicPath;
        }

        // Try storage/public path
        $storagePublicPath = public_path('storage/' . $relative);
        if (file_exists($storagePublicPath)) {
            return $storagePublicPath;
        }

        // Try storage/app/public path
        $storagePath = storage_path('app/public/' . $relative);
        if (file_exists($storagePath)) {
            return $storagePath;
        }

        // Return original if nothing found (will handle gracefully downstream)
        return $imagePath;
    }

    /**
     * Convert a local image path to base64 (preferred for DOMPDF).
     * If a base64 is already provided, use it.
     */
    private function embedImageAsBase64(?string $path, ?string $existingBase64): ?string
    {
        if (!empty($existingBase64)) {
            return $existingBase64;
        }
        if (empty($path)) {
            return null;
        }

        // Resolve path if not absolute
        if (!file_exists($path)) {
            $path = $this->resolveImagePath($path);
            if (empty($path) || !file_exists($path)) {
                return null;
            }
        }

        $mime = mime_content_type($path) ?: 'image/png';
        $data = base64_encode(@file_get_contents($path));
        return $data ? ('data:' . $mime . ';base64,' . $data) : null;
    }

    /**
     * Normalize options:
     *  - If true_false: return array of ['text' => 'True'] and ['text' => 'False']
     *  - If matching: produce array of ['left' => html, 'right' => html]
     *  - Else: return array of strings or ['text' => html]
     * Runs KaTeX on each option text.
     */
    private function normalizeOptions($rawOptions, ?string $type): array
    {
        // Handle true/false questions
        if ($type === 'true_false') {
            return [
                ['text' => 'True'],
                ['text' => 'False'],
            ];
        }

        // If options come as JSON string, decode
        if (is_string($rawOptions)) {
            // Try JSON decode first
            $decoded = json_decode($rawOptions, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $rawOptions = $decoded;
            } else {
                // Fallback: split by newlines
                $rawOptions = preg_split('/\r\n|\r|\n/', trim($rawOptions)) ?: [];
            }
        }

        // Ensure array
        $opts = [];
        if (is_array($rawOptions)) {
            $opts = $rawOptions;
        } elseif ($rawOptions instanceof Collection) {
            $opts = $rawOptions->all();
        }

        // Matching type: each item should be a pair
        if (($type === 'matching') || $this->looksLikeMatching($opts)) {
            $pairs = [];
            foreach ($opts as $item) {
                if (is_array($item) && (isset($item['left']) || isset($item['right']))) {
                    $pairs[] = [
                        'left'  => $this->toHtmlWithKatex((string) ($item['left'] ?? '')),
                        'right' => $this->toHtmlWithKatex((string) ($item['right'] ?? '')),
                    ];
                } elseif (is_string($item) && str_contains($item, '|')) {
                    [$left, $right] = array_pad(explode('|', $item, 2), 2, '');
                    $pairs[] = [
                        'left'  => $this->toHtmlWithKatex(trim($left)),
                        'right' => $this->toHtmlWithKatex(trim($right)),
                    ];
                } else {
                    // If unexpected, treat as single text (MCQ) to avoid losing data
                    $pairs[] = [
                        'left'  => $this->toHtmlWithKatex(is_array($item) ? ($item['text'] ?? '') : (string) $item),
                        'right' => '',
                    ];
                }
            }
            return $pairs;
        }

        // Default: MCQ/choice list
        $normalized = [];
        foreach ($opts as $item) {
            if (is_array($item)) {
                $text = (string) ($item['text'] ?? $item['value'] ?? '');
                $normalized[] = ['text' => $this->toHtmlWithKatex($text)];
            } else {
                $normalized[] = $this->toHtmlWithKatex((string) $item);
            }
        }
        return $normalized;
    }

    /**
     * Guess question type based on presence of options and matching-like structure.
     */
    private function inferType(string $rawHtml, $options): ?string
    {
        if (!empty($options)) {
            $arr = is_array($options) ? $options : (is_string($options) ? [$options] : []);
            if ($this->looksLikeMatching($arr)) {
                return 'matching';
            }
            return 'mcq';
        }

        // Heuristic: if it looks like "fill in", "short answer", etc., mark short_answer
        // You can tighten this based on your taxonomy.
        return 'short_answer';
    }

    /**
     * Detect if options resemble matching pairs.
     */
    private function looksLikeMatching(array $opts): bool
    {
        if (empty($opts)) return false;

        // If any item has left/right keys → matching
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

    /**
     * Simple math detection (if you need to flag is_math elsewhere).
     */
    private function hasMath(string $html): bool
    {
        return (bool) preg_match('/(\$\$.*?\$\$|\\\\\[.*?\\\\\]|(?<!\$)\$.*?(?<!\$)\$|\\\\\(.*?\\\\\))/s', $html);
    }

    /**
     * Parse MCQ / True False / Matching options
     */
    private function parseOptions($q)
    {
        if ($q->question_type === 'true_false') {
            return [
                ['text' => 'True',  'is_correct' => $q->correct_answer === 'True'],
                ['text' => 'False', 'is_correct' => $q->correct_answer === 'False'],
            ];
        }

        if ($q->question_type === 'mcq') {
            $raw = $q->options;

            if (is_string($raw)) $raw = json_decode($raw, true) ?? [];
            if (!is_array($raw)) $raw = [];

            // Normalize each option to an array with at least a 'text' key,
            // preserving 'image' or other fields when present so that
            // normal-paper.blade.php can safely access $opt['text'] / $opt['image'].
            return array_map(function ($opt) {
                if (is_array($opt)) {
                    // Already in expected shape or similar
                    if (array_key_exists('text', $opt) || array_key_exists('image', $opt)) {
                        $opt['text'] = $opt['text'] ?? '';
                        // Convert image to absolute path if present
                        if (!empty($opt['image'])) {
                            $img = $opt['image'];
                            $relative = ltrim($img, '/');
                            $directPublicPath = public_path($relative);
                            if (file_exists($directPublicPath)) {
                                $opt['image'] = $directPublicPath;
                            } else {
                                $storagePublicPath = public_path('storage/' . $relative);
                                if (file_exists($storagePublicPath)) {
                                    $opt['image'] = $storagePublicPath;
                                }
                            }
                        }
                        return $opt;
                    }

                    // Plain array value, wrap into text
                    return [
                        'text' => implode(' ', array_values($opt)),
                    ];
                }

                // Scalar string/bool/number
                return [
                    'text' => (string) $opt,
                ];
            }, $raw);
        }

        if ($q->question_type === 'matching') {
            $raw = $q->options;
            if (is_string($raw)) $raw = json_decode($raw, true) ?? [];

            $pairs = [];
            if (isset($raw['left'], $raw['right'])) {
                $lefts  = $raw['left'];
                $rights = $raw['right'];
                $max = max(count($lefts), count($rights));

                for ($i = 0; $i < $max; $i++) {
                    $pairs[] = [
                        'left'  => $lefts[$i]  ?? '',
                        'right' => $rights[$i] ?? ''
                    ];
                }
            } else {
                foreach ($raw as $pair) {
                    $pairs[] = [
                        'left'  => $pair['left']  ?? '',
                        'right' => $pair['right'] ?? ''
                    ];
                }
            }

            return $pairs;
        }

        return [];
    }

    /**
     * Safely extract the textual question content from various stored formats.
     * - If already an array, prefer ['question'].
     * - If a JSON string, decode and prefer ['question'].
     * - Otherwise treat as a plain string.
     */
    private function extractQuestionText($raw)
    {
        // If this is an object (e.g. Eloquent Question model or pivot relation),
        // try to tunnel into its 'question' attribute or array form first.
        if (is_object($raw)) {
            if (isset($raw->question)) {
                return $this->extractQuestionText($raw->question);
            }

            if (method_exists($raw, 'toArray')) {
                $asArray = $raw->toArray();
                if (is_array($asArray) && array_key_exists('question', $asArray)) {
                    return $this->extractQuestionText($asArray['question']);
                }
            }

            // Fallback to string cast if we can't find a question field
            $raw = (string) $raw;
        }

        if (is_array($raw)) {
            return $raw['question'] ?? '';
        }

        if (is_string($raw)) {
            $trimmed = ltrim($raw);

            // Heuristic: looks like JSON object/array
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    // Either direct question array or wrapped inside
                    if (array_key_exists('question', $decoded)) {
                        return $decoded['question'] ?? '';
                    }

                    // If it's a list of questions, take first element's question
                    $first = reset($decoded);
                    if (is_array($first) && array_key_exists('question', $first)) {
                        return $first['question'] ?? '';
                    }
                }
            }

            return $raw;
        }

        return (string) $raw;
    }
}
