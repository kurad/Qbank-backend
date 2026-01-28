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
        // Helper: process a single option (mcq text OR matching left/right) + embed option image
        $processOption = function (&$opt) {
            // If a plain string option, normalize to ['text'=>...]
            if (is_string($opt)) {
                $opt = ['text' => $opt];
            }

            if (!is_array($opt)) {
                return;
            }

            // MCQ / generic text option
            if (!empty($opt['text'])) {
                $opt['text'] = MathRenderer::processHtmlWithLatex((string) $opt['text']);
            }

            // Matching option (left/right)
            // IMPORTANT: do not rebuild/overwrite pairs — only process if keys exist
            if (array_key_exists('left', $opt)) {
                $opt['left'] = MathRenderer::processHtmlWithLatex((string) ($opt['left'] ?? ''));
            }
            if (array_key_exists('right', $opt)) {
                $opt['right'] = MathRenderer::processHtmlWithLatex((string) ($opt['right'] ?? ''));
            }

            // Option image → base64 for DOMPDF
            if (!empty($opt['image']) && empty($opt['image_base64'])) {
                $resolved = $this->resolveImagePath((string) $opt['image']);
                $opt['image_base64'] = $this->embedImageAsBase64($resolved, null);
            }
        };

        // Helper: process a question or sub-question node
        $processNode = function (&$node) use ($processOption) {
            // Only process clean_html if it still contains LaTeX delimiters (avoid double-processing)
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

            // Options
            if (!empty($node['options']) && is_array($node['options'])) {
                foreach ($node['options'] as &$opt) {
                    $processOption($opt);
                }
                unset($opt);
            }
        };

        foreach ($questions as &$q) {
            $processNode($q);

            // Sub-questions
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

            // Clean & KaTeX render main HTML (ensure it's not empty!)
            $q['clean_html'] = $this->toHtmlWithKatex($q['raw_html'] ?? '');

            // Debug: if clean_html is still empty, use raw_html as fallback
            if (empty($q['clean_html']) && !empty($q['raw_html'])) {
                $q['clean_html'] = htmlspecialchars($q['raw_html']);
            }

            // Image to base64 (if present)
            $q['image_base64'] = $this->embedImageAsBase64(
                $q['image_path'] ?? null,
                $q['image_base64'] ?? null
            );

            // Normalize options (MCQ, matching, or true/false)
            // IMPORTANT: Always normalize for true_false, even if no options provided
            if (!empty($q['options']) || $q['type'] === 'true_false') {
                $q['options'] = $this->normalizeOptions($q['options'], $q['type'] ?? null);
            } else {
                $q['options'] = [];
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

                    // Debug: if clean_html is still empty, use raw_html as fallback
                    if (empty($sub['clean_html']) && !empty($sub['raw_html'])) {
                        $sub['clean_html'] = htmlspecialchars($sub['raw_html']);
                    }

                    $sub['image_base64'] = $this->embedImageAsBase64(
                        $sub['image_path'] ?? null,
                        $sub['image_base64'] ?? null
                    );

                    if (!empty($sub['options']) || $sub['type'] === 'true_false') {
                        $sub['options'] = $this->normalizeOptions($sub['options'], $sub['type'] ?? null);
                    } else {
                        $sub['options'] = [];
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
     * Handles the actual data structure from Assessment->questions->question relationships.
     */
    private function transformQuestionModelToArray($qModel): array
    {
        // Handle multiple possible structures
        $question = null;

        // Try to access nested question object (if $qModel is pivot)
        if (isset($qModel->question) && is_object($qModel->question)) {
            $question = $qModel->question;
        } elseif (is_object($qModel)) {
            // $qModel might be the question itself
            $question = $qModel;
        }

        // Fallback: if $qModel is an array or doesn't have question relationship
        if (!$question && is_array($qModel)) {
            $question = (object) $qModel;
        }

        if (!$question) {
            $question = $qModel;
        }

        // Extract question text - try multiple possible fields
        $rawHtml = '';
        if (isset($question->question) && !empty($question->question)) {
            $rawHtml = (string) $question->question;
        } elseif (isset($question->text) && !empty($question->text)) {
            $rawHtml = (string) $question->text;
        } elseif (isset($question->content) && !empty($question->content)) {
            $rawHtml = (string) $question->content;
        }

        // Get question type
        $type = null;
        if (isset($question->question_type)) {
            $type = $question->question_type;
        } elseif (isset($question->type)) {
            $type = $question->type;
        }

        // Get marks (could be string or int)
        $marks = 0;
        if (isset($question->marks)) {
            $marks = (int) $question->marks;
        }

        // Get image path
        $imagePath = null;
        if (isset($question->question_image)) {
            $imagePath = $question->question_image;
        }

        $imageBase64 = null;

        // Get options - handle multiple formats
        $options = null;
        if (isset($question->options)) {
            $options = $question->options;
        }

        // Get sub-questions if they exist
        $subRaw = [];
        if (isset($question->sub_questions)) {
            $subRaw = $question->sub_questions;
        }

        // Auto‑derive type when not explicitly set
        if (!$type) {
            $type = $this->inferType($rawHtml, $options);
        }

        return [
            'raw_html'     => $rawHtml,
            'type'         => $type,
            'marks'        => $marks,
            'options'      => $options,
            'image_path'   => $imagePath,
            'image_base64' => $imageBase64,
            'sub_raw'      => is_iterable($subRaw) ? $subRaw : [],
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

        try {
            return MathRenderer::processHtmlWithLatex($html);
        } catch (\Exception $e) {
            // If KaTeX processing fails, return HTML-escaped version
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }
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
     * NOTE: KaTeX processing happens separately in processQuestionsForKatex()
     */
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
                // fallback split
                $rawOptions = preg_split('/\r\n|\r|\n/', trim($rawOptions)) ?: [];
            }
        }

        // Convert Collections
        if ($rawOptions instanceof \Illuminate\Support\Collection) {
            $rawOptions = $rawOptions->all();
        }

        // ----------------------------
        // MATCHING: support BOTH formats:
        // 1) ['left'=>[...], 'right'=>[...]]
        // 2) [['left'=>'..','right'=>'..'], ...]
        // 3) "left|right" strings
        // ----------------------------
        if ($type === 'matching' || (is_array($rawOptions) && $this->looksLikeMatching($rawOptions))) {

            // Format A: { left:[], right:[] }
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

            // Format B/C: list of pairs or "left|right"
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

        // ----------------------------
        // DEFAULT: MCQ list
        // ----------------------------
        $normalized = [];
        foreach ((array) $rawOptions as $item) {
            if (is_array($item)) {
                $text = (string) ($item['text'] ?? $item['value'] ?? '');
                $normalized[] = ['text' => $text];
            } else {
                $normalized[] = ['text' => (string) $item];
            }
        }

        return $normalized;
    }

    /**
     * Guess question type based on presence of options and matching-like structure.
     */
    private function inferType(string $rawHtml, $options): ?string
    {
        // If no options, it's a short answer
        if (empty($options)) {
            return 'short_answer';
        }

        // Check if it looks like matching
        $arr = is_array($options) ? $options : (is_string($options) ? json_decode($options, true) ?? [$options] : []);

        if ($this->looksLikeMatching($arr)) {
            return 'matching';
        }

        // Check if it's true/false (only 2 items that are true/false)
        if (is_array($arr) && count($arr) === 2) {
            $vals = array_map(fn($item) => is_array($item) ? strtolower($item['text'] ?? '') : strtolower((string) $item), $arr);
            $vals = array_filter(array_unique($vals));
            if (count($vals) === 2 && in_array('true', $vals) && in_array('false', $vals)) {
                return 'true_false';
            }
        }

        // Default to MCQ if options exist
        return 'mcq';
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
