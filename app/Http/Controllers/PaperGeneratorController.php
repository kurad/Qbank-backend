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
        $questionNumber = 1;

        //--------------------------------------
        // BUILD MAIN DATA
        //--------------------------------------
        $data = [
            'title'        => $assessment->title,
            'subject'      => $subjects->first() ?? 'General',
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
        // DETECT IF ANY QUESTION HAS MATH
        //--------------------------------------
        $containsMath = $assessment->questions
            ->contains(fn($q) => $q->is_math == 1);

        //--------------------------------------
        // RENDER HTML (choose layout)
        //--------------------------------------
        $layout = $request->query('layout', 'normal');
        $view   = $layout === 'standard' ? 'pdf.standard-paper' : 'pdf.normal-paper';

        $html = view($view, $data)->render();
        $filePath = storage_path('app/papers/assessment-' . Str::slug($assessment->title) . '.pdf');

        //--------------------------------------
        // IF MATH → USE BROWSERSHOT (MathJax)
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
            // IMAGE PATH (public URL for PDF engines)
            //--------------------------------------
            $imagePath = null;
            if ($q->question_image) {
                $img = $q->question_image;

                // If already an absolute URL, use as-is
                if (preg_match('#^https?://#', $img)) {
                    $imagePath = $img;
                } else {
                    $relative = ltrim($img, '/');

                    // 1) Check if file is directly under public/<relative>
                    $directPublicPath = public_path($relative);
                    if (file_exists($directPublicPath)) {
                        $imagePath = asset($relative);
                    } else {
                        // 2) Fallback: treat as public/storage/<relative>
                        $storagePublicPath = public_path('storage/' . $relative);
                        if (file_exists($storagePublicPath)) {
                            $imagePath = asset('storage/' . $relative);
                        }
                    }
                }
            }

            //--------------------------------------
            // MAIN PARENT QUESTION
            //--------------------------------------
            if (!$parentId && $isParent) {

                $groups[$q->id] = count($output);

                $output[] = [
                    'number'        => $questionNumber++,
                    'text'          => $q->question,
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

                $formatted = [
                    'number'  => $questionNumber++,
                    'text'    => $q->question,
                    'marks'   => $q->marks ?? 1,
                    'type'    => $q->question_type,
                    'image'   => $imagePath,
                    'is_math' => $q->is_math,
                    'options' => $this->parseOptions($q)
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

                $groups[$parentId] = count($output);

                $output[] = [
                    'number'        => $questionNumber++,
                    'text'          => $parent ? $parent->question : $q->question,
                    'type'          => 'parent_group',
                    'is_math'       => $parent ? $parent->is_math : 0,
                    'image'         => null,
                    'sub_questions' => []
                ];
            }

            $groupIndex = $groups[$parentId];
            $sub = &$output[$groupIndex]['sub_questions'];
            $label = chr(ord('a') + count($sub));

            $sub[] = [
                'label'    => $label,
                'text'     => $q->question,
                'type'     => $q->question_type,
                'marks'    => $q->marks ?? 1,
                'is_math'  => $q->is_math,
                'options'  => $this->parseOptions($q)
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

            return array_map(function ($opt) use ($q) {
                $text = is_array($opt) ? ($opt['text'] ?? '') : $opt;
                return [
                    'text'       => $text,
                    'is_correct' => $opt === $q->correct_answer
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

}
