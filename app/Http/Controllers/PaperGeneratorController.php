<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;

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

        $school = $assessment->creator->school;
        $subjects = $assessment->topics->pluck('gradeSubject.subject.name')->filter()->unique();
        $gradeLevels = $assessment->topics
            ->pluck('gradeSubject.gradeLevel.grade_name')
            ->filter()
            ->unique();
        $questionNumber = 1;

        // Build instructions list (either custom per assessment or default set)
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

        //--------------------------------------
        // BUILD MAIN DATA
        //--------------------------------------
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
            'total_marks'  => 0
        ];

        //--------------------------------------
        // HANDLE SECTIONED ASSESSMENT
        //--------------------------------------
        if ($assessment->sections->count() > 0) {

            foreach ($assessment->sections->sortBy('ordering') as $section) {
                $sectionBlock = [
                    'title'       => $section->title,
                    'instruction' => $section->instruction,
                    'questions'   => []
                ];

                $formatted = $this->formatQuestions(
                    $section->questions,
                    $questionNumber,
                    $data['total_marks']
                );

                $sectionBlock['questions'] = $formatted;

                if (!empty($formatted)) {
                    $data['sections'][] = $sectionBlock;
                }
            }

        } else {
            //--------------------------------------
            // HANDLE NON-SECTION ASSESSMENT
            //--------------------------------------
            $data['questions'] = $this->formatQuestions(
                $assessment->questions->sortBy('order'),
                $questionNumber,
                $data['total_marks']
            );
        }

        //--------------------------------------
        // DETECT IF ANY QUESTION HAS MATH OR IMAGES
        //--------------------------------------
        $containsMath = $assessment->questions
            ->contains(fn($q) => $q->is_math == 1);

        $containsImages = collect($data['questions'])
            ->merge(collect($data['sections'])->pluck('questions')->flatten())
            ->contains(fn($q) => !empty($q['image']));

        //--------------------------------------
        // RENDER HTML
        //--------------------------------------
        $view = 'pdf.normal-paper';

        $html = view($view, $data)->render();
        $filePath = storage_path('app/papers/assessment-' . Str::slug($assessment->title) . '.pdf');

        //--------------------------------------
        // IF MATH → USE BROWSERSHOT (MathJax)
        // ELSE → USE DOMPDF (faster, with base64 images)
        //--------------------------------------
        if ($containsMath) {

            Browsershot::html($html)
                ->format('A4')
                ->margins(10, 10, 10, 10)
                ->timeout(120)
                ->waitUntilNetworkIdle()   // wait for MathJax to finish rendering
                ->save($filePath);

            return response()->download($filePath);
        }

        //--------------------------------------
        // ELSE → USE DOMPDF (FAST)
        //--------------------------------------
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
        $gradeLevels = $assessment->topics
            ->pluck('gradeSubject.gradeLevel.grade_name')
            ->filter()
            ->unique();
        $questionNumber = 1;
        $totalMarks = 0;

        // Build instructions list (either custom per assessment or default set)
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

        //--------------------------------------
        // BUILD MAIN DATA (only flat questions)
        //--------------------------------------
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
            'sections'     => [], // empty
            'questions'    => $this->formatQuestions(
                $assessment->questions->sortBy('order'),
                $questionNumber,
                $totalMarks
            ),
            'total_marks'  => $totalMarks
        ];

        //--------------------------------------
        // DETECT IF ANY QUESTION HAS MATH OR IMAGES
        //--------------------------------------
        $containsMath = $assessment->questions
            ->contains(fn($q) => $q->is_math == 1);

        $containsImages = collect($data['questions'])
            ->contains(fn($q) => !empty($q['image']));

        //--------------------------------------
        // RENDER HTML
        //--------------------------------------
        $view = 'pdf.standard-paper';

        $html = view($view, $data)->render();
        $filePath = storage_path('app/papers/assessment-' . Str::slug($assessment->title) . '.pdf');

        //--------------------------------------
        // IF MATH → USE BROWSERSHOT (MathJax)
        // ELSE → USE DOMPDF (faster, with base64 images)
        //--------------------------------------
        if ($containsMath) {

            Browsershot::html($html)
                ->format('A4')
                ->margins(10, 10, 10, 10)
                ->timeout(120)
                ->waitUntilNetworkIdle()   // wait for MathJax to finish rendering
                ->save($filePath);

            return response()->download($filePath);
        }

        //--------------------------------------
        // ELSE → USE DOMPDF (FAST)
        //--------------------------------------
        $pdf = Pdf::loadHTML($html)->setPaper('A4');
        return $pdf->download('assessment-' . Str::slug($assessment->title) . '.pdf');
    }


    /**
     * Format questions + parent/child structure
     */
    private function formatQuestions($questions, &$questionNumber, &$totalMarks)
    {
        $output = [];
        $groups = [];
        $parentIds = $questions->pluck('parent_question_id')->filter()->unique()->all();

        foreach ($questions as $q) {

            if (!$q) continue;

            $isParent = in_array($q->id, $parentIds);
            $parentId = $q->parent_question_id;

            //--------------------------------------
            // IMAGE BASE64 for PDF views
            //--------------------------------------
            $imageBase64 = null;
            if ($q->question_image) {
                $img = $q->question_image;
                $relative = ltrim($img, '/');

                // 1) Check if file is directly under public/<relative>
                $directPublicPath = public_path($relative);
                if (file_exists($directPublicPath)) {
                    $imageBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($directPublicPath));
                } else {
                    // 2) Fallback: treat as public/storage/<relative>
                    $storagePublicPath = public_path('storage/' . $relative);
                    if (file_exists($storagePublicPath)) {
                        $imageBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($storagePublicPath));
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
                    'image_base64'  => $imageBase64,
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
                    'image_base64' => $imageBase64,
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
                        $parentImagePath = $relative;
                    } else {
                        $storagePublicPath = public_path('storage/' . $relative);
                        if (file_exists($storagePublicPath)) {
                            $parentImagePath = 'storage/' . $relative;
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
                    'image_base64'  => $parentImagePath,
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
                    $subImagePath = $relative;
                } else {
                    $storagePublicPath = public_path('storage/' . $relative);
                    if (file_exists($storagePublicPath)) {
                        $subImagePath = 'storage/' . $relative;
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
                'image_base64' => $subImagePath,
                'options'      => $this->parseOptions($q)
            ];

            $totalMarks += $q->marks ?? 0;
        }

        return $output;
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
