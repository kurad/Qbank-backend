<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class PaperGeneratorController extends Controller
{
    /**
     * Generate a PDF version of the assessment (teacher/full version)
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function generatePdf($id)
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

        $school = $assessment->creator->school ?? null;

        $subjects = $assessment->topics->map(function ($topic) {
            return $topic->gradeSubject?->subject?->name;
        })->filter()->unique()->values();

        $data = [
            'title' => $assessment->title,
            'subject' => $subjects->first() ?? 'General',
            'topic' => $assessment->topic ? $assessment->topic->name : 'General',
            'created_at' => $assessment->created_at->format('F j, Y'),
            'school' => [
                'school_name' => $school?->school_name ?? 'School Name',
                'address' => $school?->address ?? 'School Address',
                'phone' => $school?->phone ?? 'Phone Number',
                'email' => $school?->email ?? 'school@example.com',
                'logo' => $school && $school->logo_path ? storage_path('app/public/' . $school->logo_path) : null,
            ],
            'sections' => [],
            'questions' => [],
            'total_marks' => 0,
        ];

        $questionNumber = 1;

        if ($assessment->sections && $assessment->sections->count() > 0) {
            foreach ($assessment->sections->sortBy('ordering') as $section) {
                $sectionBlock = [
                    'title' => $section->title,
                    'instruction' => $section->instruction,
                    'questions' => [],
                ];

                $sectionQuestions = $section->questions;
                $parentIds = $sectionQuestions->pluck('parent_question_id')->filter()->unique()->all();

                $groups = [];
                $questionsOut = [];

                foreach ($sectionQuestions as $question) {
                    if (!$question) {
                        continue;
                    }

                    if (in_array($question->id, $parentIds, true) && is_null($question->parent_question_id)) {
                        continue;
                    }

                    $parentId = $question->parent_question_id;

                    $imagePath = null;
                    if ($question->question_image) {
                        $relativePath = ltrim($question->question_image, '/');
                        $imagePath = storage_path('app/public/' . $relativePath);
                        if (!file_exists($imagePath)) {
                            $imagePath = null;
                        }
                    }

                    if (empty($parentId)) {
                        $formattedQuestion = [
                            'number' => $questionNumber++,
                            'text' => $question->question,
                            'marks' => $question->marks ?? 1,
                            'type' => $question->question_type,
                            'image' => $imagePath,
                            'options' => [],
                        ];

                        if ($question->question_type === 'true_false') {
                            $formattedQuestion['options'] = [
                                ['text' => 'True', 'is_correct' => $question->correct_answer === 'True'],
                                ['text' => 'False', 'is_correct' => $question->correct_answer === 'False'],
                            ];
                        } else {
                            try {
                                $rawOptions = $question->options;
                                if (is_string($rawOptions)) {
                                    $options = json_decode($rawOptions, true) ?? [];
                                } elseif (is_array($rawOptions)) {
                                    $options = $rawOptions;
                                } else {
                                    $options = [];
                                }

                                $formattedQuestion['options'] = array_map(function ($option) use ($question) {
                                    return [
                                        'text' => is_array($option) ? ($option['text'] ?? '') : $option,
                                        'is_correct' => $option === $question->correct_answer,
                                    ];
                                }, $options);
                            } catch (\Exception $e) {
                                $formattedQuestion['options'] = [];
                            }
                        }

                        $questionsOut[] = $formattedQuestion;
                        $data['total_marks'] += $formattedQuestion['marks'];
                    } else {
                        $parent = $question->parent;

                        if (!isset($groups[$parentId])) {
                            $groups[$parentId] = count($questionsOut);

                            $questionsOut[] = [
                                'number' => $questionNumber++,
                                'text' => $parent ? $parent->question : $question->question,
                                'type' => 'parent_group',
                                'image' => null,
                                'sub_questions' => [],
                            ];
                        }

                        $groupIndex = $groups[$parentId];
                        $subQuestions = &$questionsOut[$groupIndex]['sub_questions'];

                        $options = [];
                        if ($question->question_type === 'true_false') {
                            $options = [
                                ['text' => 'True', 'is_correct' => $question->correct_answer === 'True'],
                                ['text' => 'False', 'is_correct' => $question->correct_answer === 'False'],
                            ];
                        } elseif ($question->question_type === 'mcq') {
                            try {
                                $rawOptions = $question->options;
                                if (is_string($rawOptions)) {
                                    $rawOptions = json_decode($rawOptions, true) ?? [];
                                }

                                if (is_array($rawOptions)) {
                                    foreach ($rawOptions as $opt) {
                                        $options[] = [
                                            'text' => is_array($opt) ? ($opt['text'] ?? '') : $opt,
                                            'is_correct' => $opt === $question->correct_answer,
                                        ];
                                    }
                                }
                            } catch (\Exception $e) {
                                $options = [];
                            }
                        } elseif ($question->question_type === 'matching') {
                            $rawOptions = $question->options ?? [];
                            if (is_string($rawOptions)) {
                                $rawOptions = json_decode($rawOptions, true) ?? [];
                            }

                            $pairs = [];
                            if (is_array($rawOptions)) {
                                // Case 1: stored as { left: [...], right: [...] }
                                if (array_key_exists('left', $rawOptions) && array_key_exists('right', $rawOptions)) {
                                    $lefts  = is_array($rawOptions['left']) ? $rawOptions['left'] : [];
                                    $rights = is_array($rawOptions['right']) ? $rawOptions['right'] : [];
                                    $max = max(count($lefts), count($rights));

                                    for ($i = 0; $i < $max; $i++) {
                                        $pairs[] = [
                                            'left'  => $lefts[$i]  ?? '',
                                            'right' => $rights[$i] ?? '',
                                        ];
                                    }
                                } else {
                                    // Case 2: stored as array of pair objects
                                    foreach ($rawOptions as $pair) {
                                        if (is_array($pair)) {
                                            $left  = $pair['left']  ?? (array_values($pair)[0] ?? '');
                                            $right = $pair['right'] ?? (array_values($pair)[1] ?? '');
                                        } else {
                                            $left  = (string) $pair;
                                            $right = '';
                                        }
                                        $pairs[] = [
                                            'left'  => $left,
                                            'right' => $right,
                                        ];
                                    }
                                }
                            }

                            $options = $pairs;
                        }

                        $label = chr(ord('a') + count($subQuestions));

                        $subQuestions[] = [
                            'label' => $label,
                            'text' => $question->question,
                            'type' => $question->question_type,
                            'marks' => $question->marks ?? 1,
                            'options' => $options,
                        ];

                        $data['total_marks'] += $question->marks ?? 0;
                    }
                }

                $sectionBlock['questions'] = $questionsOut;
                if (!empty($questionsOut)) {
                    $data['sections'][] = $sectionBlock;
                }
            }
        } else {
            $assessmentQuestions = $assessment->questions->sortBy('order')->map(function ($aq) {
                return $aq->question;
            })->filter();

            $parentIds = $assessmentQuestions->pluck('parent_question_id')->filter()->unique()->all();

            $groups = [];
            $questionsOut = [];

            foreach ($assessmentQuestions as $index => $question) {
                if (in_array($question->id, $parentIds, true) && is_null($question->parent_question_id)) {
                    continue;
                }

                $parentId = $question->parent_question_id;

                $imagePath = null;
                if ($question->question_image) {
                    $relativePath = ltrim($question->question_image, '/');
                    $imagePath = storage_path('app/public/' . $relativePath);
                    if (!file_exists($imagePath)) {
                        $imagePath = null;
                    }
                }

                if (empty($parentId)) {
                    $formattedQuestion = [
                        'number' => $questionNumber++,
                        'text' => $question->question,
                        'marks' => $question->marks ?? 1,
                        'type' => $question->question_type,
                        'image' => $imagePath,
                        'options' => [],
                    ];

                    if ($question->question_type === 'true_false') {
                        $formattedQuestion['options'] = [
                            ['text' => 'True', 'is_correct' => $question->correct_answer === 'True'],
                            ['text' => 'False', 'is_correct' => $question->correct_answer === 'False'],
                        ];
                    } else {
                        try {
                            $rawOptions = $question->options;
                            if (is_string($rawOptions)) {
                                $options = json_decode($rawOptions, true) ?? [];
                            } elseif (is_array($rawOptions)) {
                                $options = $rawOptions;
                            } else {
                                $options = [];
                            }

                            $formattedQuestion['options'] = array_map(function ($option) use ($question) {
                                return [
                                    'text' => is_array($option) ? ($option['text'] ?? '') : $option,
                                    'is_correct' => $option === $question->correct_answer,
                                ];
                            }, $options);
                        } catch (\Exception $e) {
                            $formattedQuestion['options'] = [];
                        }
                    }

                    $questionsOut[] = $formattedQuestion;
                    $data['total_marks'] += $formattedQuestion['marks'];
                } else {
                    $parent = $question->parent;

                    if (!isset($groups[$parentId])) {
                        $groups[$parentId] = count($questionsOut);

                        $questionsOut[] = [
                            'number' => $questionNumber++,
                            'text' => $parent ? $parent->question : $question->question,
                            'type' => 'parent_group',
                            'image' => null,
                            'sub_questions' => [],
                        ];
                    }

                    $groupIndex = $groups[$parentId];
                    $subQuestions = &$questionsOut[$groupIndex]['sub_questions'];

                    $options = [];
                    if ($question->question_type === 'true_false') {
                        $options = [
                            ['text' => 'True', 'is_correct' => $question->correct_answer === 'True'],
                            ['text' => 'False', 'is_correct' => $question->correct_answer === 'False'],
                        ];
                    } elseif ($question->question_type === 'mcq') {
                        try {
                            $rawOptions = $question->options;
                            if (is_string($rawOptions)) {
                                $rawOptions = json_decode($rawOptions, true) ?? [];
                            }

                            if (is_array($rawOptions)) {
                                foreach ($rawOptions as $opt) {
                                    $options[] = [
                                        'text' => is_array($opt) ? ($opt['text'] ?? '') : $opt,
                                        'is_correct' => $opt === $question->correct_answer,
                                    ];
                                }
                            }
                        } catch (\Exception $e) {
                            $options = [];
                        }
                    } elseif ($question->question_type === 'matching') {
                        $rawOptions = $question->options ?? [];
                        if (is_string($rawOptions)) {
                            $rawOptions = json_decode($rawOptions, true) ?? [];
                        }

                        $pairs = [];
                        if (is_array($rawOptions)) {
                            if (array_key_exists('left', $rawOptions) && array_key_exists('right', $rawOptions)) {
                                $lefts  = is_array($rawOptions['left']) ? $rawOptions['left'] : [];
                                $rights = is_array($rawOptions['right']) ? $rawOptions['right'] : [];
                                $max = max(count($lefts), count($rights));

                                for ($i = 0; $i < $max; $i++) {
                                    $pairs[] = [
                                        'left'  => $lefts[$i]  ?? '',
                                        'right' => $rights[$i] ?? '',
                                    ];
                                }
                            } else {
                                foreach ($rawOptions as $pair) {
                                    if (is_array($pair)) {
                                        $left  = $pair['left']  ?? (array_values($pair)[0] ?? '');
                                        $right = $pair['right'] ?? (array_values($pair)[1] ?? '');
                                    } else {
                                        $left  = (string) $pair;
                                        $right = '';
                                    }
                                    $pairs[] = [
                                        'left'  => $left,
                                        'right' => $right,
                                    ];
                                }
                            }
                        }

                        $options = $pairs;
                    }

                    $label = chr(ord('a') + count($subQuestions));

                    $subQuestions[] = [
                        'label' => $label,
                        'text' => $question->question,
                        'type' => $question->question_type,
                        'marks' => $question->marks ?? 1,
                        'options' => $options,
                    ];

                    $data['total_marks'] += $question->marks ?? 0;
                }
            }

            $data['questions'] = $questionsOut;
        }

        $pdf = Pdf::loadView('assessments.pdf', $data);

        $filename = 'assessment-' . Str::slug($assessment->title) . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Generate a student version of the assessment PDF (without answers)
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function generatePdfStudent($id)
    {
        $assessment = Assessment::with([
            'questions.question.parent',
            'sections.questions.parent',
            'topics.gradeSubject.subject',
            'creator.school',
        ])->findOrFail($id);

        $user = Auth::user();
        if ($assessment->creator_id !== $user->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        $school = $assessment->creator->school ?? null;

        $subjects = $assessment->topics->map(function ($topic) {
            return $topic->gradeSubject?->subject?->name;
        })->filter()->unique()->values();

        $data = [
            'title' => $assessment->title . ' - Question Paper',
            'subject' => $subjects->first() ?? 'General',
            'created_at' => $assessment->created_at->format('F j, Y'),
            'school' => [
                'school_name' => $school?->school_name ?? 'School Name',
                'address' => $school?->address ?? 'School Address',
                'phone' => $school?->phone ?? 'Phone Number',
                'email' => $school?->email ?? 'school@example.com',
                'logo' => $school && $school->logo_path ? storage_path('app/public/' . $school->logo_path) : null,
            ],
            'sections' => [],
            'questions' => [],
            'total_marks' => 0,
        ];

        $questionNumber = 1;

        if ($assessment->sections && $assessment->sections->count() > 0) {
            foreach ($assessment->sections->sortBy('ordering') as $section) {
                $sectionBlock = [
                    'title' => $section->title,
                    'instruction' => $section->instruction,
                    'questions' => [],
                ];

                $sectionQuestions = $section->questions;
                $parentIds = $sectionQuestions->pluck('parent_question_id')->filter()->unique()->all();

                $groups = [];
                $questionsOut = [];

                foreach ($sectionQuestions as $question) {
                    if (!$question) {
                        continue;
                    }

                    if (in_array($question->id, $parentIds, true) && is_null($question->parent_question_id)) {
                        continue;
                    }

                    $parentId = $question->parent_question_id;

                    $renderedText = $question->question;
                    if ($question->is_math) {
                        $renderedText = preg_replace_callback('/\\((.*?)\\)/', function ($matches) {
                            return $this->renderKatex($matches[1]);
                        }, $question->question);
                    } elseif ($question->is_chemistry) {
                        $renderedText = preg_replace_callback('/\\[(.*?)\\]/', function ($matches) {
                            return $this->renderChemistry($matches[1]);
                        }, $question->question);
                    }

                    if (empty($parentId)) {
                        $options = [];
                        if (in_array($question->question_type, ['mcq', 'true_false'])) {
                            if ($question->question_type === 'true_false') {
                                $options = [
                                    ['text' => 'True'],
                                    ['text' => 'False'],
                                ];
                            } else {
                                $rawOptions = $question->options;
                                if (is_string($rawOptions)) {
                                    $decodedOptions = json_decode($rawOptions, true) ?? [];
                                } elseif (is_array($rawOptions)) {
                                    $decodedOptions = $rawOptions;
                                } else {
                                    $decodedOptions = [];
                                }

                                foreach ($decodedOptions as $opt) {
                                    $baseText = is_array($opt) ? ($opt['text'] ?? '') : $opt;

                                    $optText = $baseText;
                                    if ($question->is_math) {
                                        $optText = preg_replace_callback('/\\((.*?)\\)/', function ($m) {
                                            return $this->renderKatex($m[1]);
                                        }, $baseText);
                                    } elseif ($question->is_chemistry) {
                                        $optText = preg_replace_callback('/\\[(.*?)\\]/', function ($m) {
                                            return $this->renderChemistry($m[1]);
                                        }, $baseText);
                                    }
                                    $options[] = ['text' => $optText];
                                }
                            }
                        }

                        if ($question->question_type === 'matching') {
                            $rawOptions = $question->options ?? [];
                            if (is_string($rawOptions)) {
                                $rawOptions = json_decode($rawOptions, true) ?? [];
                            }

                            $pairs = [];
                            if (is_array($rawOptions)) {
                                if (array_key_exists('left', $rawOptions) && array_key_exists('right', $rawOptions)) {
                                    $lefts  = is_array($rawOptions['left']) ? $rawOptions['left'] : [];
                                    $rights = is_array($rawOptions['right']) ? $rawOptions['right'] : [];
                                    $max = max(count($lefts), count($rights));

                                    for ($i = 0; $i < $max; $i++) {
                                        $pairs[] = [
                                            'left'  => $lefts[$i]  ?? '',
                                            'right' => $rights[$i] ?? '',
                                        ];
                                    }
                                } else {
                                    foreach ($rawOptions as $pair) {
                                        if (is_array($pair)) {
                                            $left  = $pair['left']  ?? (array_values($pair)[0] ?? '');
                                            $right = $pair['right'] ?? (array_values($pair)[1] ?? '');
                                        } else {
                                            $left  = (string) $pair;
                                            $right = '';
                                        }
                                        $pairs[] = [
                                            'left'  => $left,
                                            'right' => $right,
                                        ];
                                    }
                                }
                            }

                            $options = $pairs;
                        }

                        $questionsOut[] = [
                            'number' => $questionNumber++,
                            'text' => $renderedText,
                            'type' => $question->question_type,
                            'marks' => $question->marks ?? 1,
                            'options' => $options,
                        ];

                        $data['total_marks'] += $question->marks ?? 0;
                    } else {
                        $parent = $question->parent;

                        if (!isset($groups[$parentId])) {
                            $groups[$parentId] = count($questionsOut);

                            $questionsOut[] = [
                                'number' => $questionNumber++,
                                'text' => $parent ? $parent->question : $question->question,
                                'type' => 'parent_group',
                                'sub_questions' => [],
                            ];
                        }

                        $groupIndex = $groups[$parentId];
                        $subQuestions = &$questionsOut[$groupIndex]['sub_questions'];

                        $childRendered = $renderedText;

                        $options = [];
                        if (in_array($question->question_type, ['mcq', 'true_false'])) {
                            if ($question->question_type === 'true_false') {
                                $options = [
                                    ['text' => 'True'],
                                    ['text' => 'False'],
                                ];
                            } else {
                                $rawOptions = $question->options;
                                if (is_string($rawOptions)) {
                                    $decodedOptions = json_decode($rawOptions, true) ?? [];
                                } elseif (is_array($rawOptions)) {
                                    $decodedOptions = $rawOptions;
                                } else {
                                    $decodedOptions = [];
                                }

                                foreach ($decodedOptions as $opt) {
                                    $baseText = is_array($opt) ? ($opt['text'] ?? '') : $opt;

                                    $optText = $baseText;
                                    if ($question->is_math) {
                                        $optText = preg_replace_callback('/\\((.*?)\\)/', function ($m) {
                                            return $this->renderKatex($m[1]);
                                        }, $baseText);
                                    } elseif ($question->is_chemistry) {
                                        $optText = preg_replace_callback('/\\[(.*?)\\]/', function ($m) {
                                            return $this->renderChemistry($m[1]);
                                        }, $baseText);
                                    }
                                    $options[] = ['text' => $optText];
                                }
                            }
                        }

                        if ($question->question_type === 'matching') {
                            $rawOptions = $question->options ?? [];
                            if (is_string($rawOptions)) {
                                $rawOptions = json_decode($rawOptions, true) ?? [];
                            }

                            $pairs = [];
                            if (is_array($rawOptions)) {
                                foreach ($rawOptions as $pair) {
                                    if (is_array($pair)) {
                                        $left  = $pair['left']  ?? (array_values($pair)[0] ?? '');
                                        $right = $pair['right'] ?? (array_values($pair)[1] ?? '');
                                    } else {
                                        $left  = (string) $pair;
                                        $right = '';
                                    }
                                    $pairs[] = [
                                        'left'  => $left,
                                        'right' => $right,
                                    ];
                                }
                            }

                            $options = $pairs;
                        }

                        $label = chr(ord('a') + count($subQuestions));

                        $subQuestions[] = [
                            'label' => $label,
                            'text' => $childRendered,
                            'type' => $question->question_type,
                            'marks' => $question->marks ?? 1,
                            'options' => $options,
                        ];

                        $data['total_marks'] += $question->marks ?? 0;
                    }
                }

                $sectionBlock['questions'] = $questionsOut;
                $data['sections'][] = $sectionBlock;
            }
        } else {
            $assessmentQuestions = $assessment->questions->sortBy('order')->map(function ($aq) {
                return $aq->question;
            })->filter();

            $parentIds = $assessmentQuestions->pluck('parent_question_id')->filter()->unique()->all();

            $groups = [];
            $questionsOut = [];

            foreach ($assessmentQuestions as $question) {
                if (in_array($question->id, $parentIds, true) && is_null($question->parent_question_id)) {
                    continue;
                }

                $parentId = $question->parent_question_id;

                $renderedText = $question->question;
                if ($question->is_math) {
                    $renderedText = preg_replace_callback('/\\((.*?)\\)/', function ($matches) {
                        return $this->renderKatex($matches[1]);
                    }, $question->question);
                } elseif ($question->is_chemistry) {
                    $renderedText = preg_replace_callback('/\\[(.*?)\\]/', function ($matches) {
                        return $this->renderChemistry($matches[1]);
                    }, $question->question);
                }

                if (empty($parentId)) {
                    $options = [];
                    if (in_array($question->question_type, ['mcq', 'true_false'])) {
                        if ($question->question_type === 'true_false') {
                            $options = [
                                ['text' => 'True'],
                                ['text' => 'False'],
                            ];
                        } else {
                            $rawOptions = $question->options;
                            if (is_string($rawOptions)) {
                                $decodedOptions = json_decode($rawOptions, true) ?? [];
                            } elseif (is_array($rawOptions)) {
                                $decodedOptions = $rawOptions;
                            } else {
                                $decodedOptions = [];
                            }

                            foreach ($decodedOptions as $opt) {
                                $baseText = is_array($opt) ? ($opt['text'] ?? '') : $opt;

                                $optText = $baseText;
                                if ($question->is_math) {
                                    $optText = preg_replace_callback('/\\((.*?)\\)/', function ($m) {
                                        return $this->renderKatex($m[1]);
                                    }, $baseText);
                                } elseif ($question->is_chemistry) {
                                    $optText = preg_replace_callback('/\\[(.*?)\\]/', function ($m) {
                                        return $this->renderChemistry($m[1]);
                                    }, $baseText);
                                }
                                $options[] = ['text' => $optText];
                            }
                        }
                    }

                    if ($question->question_type === 'matching') {
                        $rawOptions = $question->options ?? [];
                        if (is_string($rawOptions)) {
                            $rawOptions = json_decode($rawOptions, true) ?? [];
                        }

                        $pairs = [];
                        if (is_array($rawOptions)) {
                            if (array_key_exists('left', $rawOptions) && array_key_exists('right', $rawOptions)) {
                                $lefts  = is_array($rawOptions['left']) ? $rawOptions['left'] : [];
                                $rights = is_array($rawOptions['right']) ? $rawOptions['right'] : [];
                                $max = max(count($lefts), count($rights));

                                for ($i = 0; $i < $max; $i++) {
                                    $pairs[] = [
                                        'left'  => $lefts[$i]  ?? '',
                                        'right' => $rights[$i] ?? '',
                                    ];
                                }
                            } else {
                                foreach ($rawOptions as $pair) {
                                    if (is_array($pair)) {
                                        $left  = $pair['left']  ?? (array_values($pair)[0] ?? '');
                                        $right = $pair['right'] ?? (array_values($pair)[1] ?? '');
                                    } else {
                                        $left  = (string) $pair;
                                        $right = '';
                                    }
                                    $pairs[] = [
                                        'left'  => $left,
                                        'right' => $right,
                                    ];
                                }
                            }
                        }

                        $options = $pairs;
                    }

                    $questionsOut[] = [
                        'number' => $questionNumber++,
                        'text' => $renderedText,
                        'type' => $question->question_type,
                        'marks' => $question->marks ?? 1,
                        'options' => $options,
                    ];

                    $data['total_marks'] += $question->marks ?? 0;
                } else {
                    $parent = $question->parent;

                    if (!isset($groups[$parentId])) {
                        $groups[$parentId] = count($questionsOut);

                        $questionsOut[] = [
                            'number' => $questionNumber++,
                            'text' => $parent ? $parent->question : $question->question,
                            'type' => 'parent_group',
                            'sub_questions' => [],
                        ];
                    }

                    $groupIndex = $groups[$parentId];
                    $subQuestions = &$questionsOut[$groupIndex]['sub_questions'];

                    $childRendered = $renderedText;

                    $options = [];
                    if (in_array($question->question_type, ['mcq', 'true_false'])) {
                        if ($question->question_type === 'true_false') {
                            $options = [
                                ['text' => 'True'],
                                ['text' => 'False'],
                            ];
                        } else {
                            $rawOptions = $question->options;
                            if (is_string($rawOptions)) {
                                $decodedOptions = json_decode($rawOptions, true) ?? [];
                            } elseif (is_array($rawOptions)) {
                                $decodedOptions = $rawOptions;
                            } else {
                                $decodedOptions = [];
                            }

                            foreach ($decodedOptions as $opt) {
                                $baseText = is_array($opt) ? ($opt['text'] ?? '') : $opt;

                                $optText = $baseText;
                                if ($question->is_math) {
                                    $optText = preg_replace_callback('/\\((.*?)\\)/', function ($m) {
                                        return $this->renderKatex($m[1]);
                                    }, $baseText);
                                } elseif ($question->is_chemistry) {
                                    $optText = preg_replace_callback('/\\[(.*?)\\]/', function ($m) {
                                        return $this->renderChemistry($m[1]);
                                    }, $baseText);
                                }
                                $options[] = ['text' => $optText];
                            }
                        }
                    }

                    if ($question->question_type === 'matching') {
                        $rawOptions = $question->options ?? [];
                        if (is_string($rawOptions)) {
                            $rawOptions = json_decode($rawOptions, true) ?? [];
                        }

                        $pairs = [];
                        if (is_array($rawOptions)) {
                            foreach ($rawOptions as $pair) {
                                if (is_array($pair)) {
                                    $left  = $pair['left']  ?? (array_values($pair)[0] ?? '');
                                    $right = $pair['right'] ?? (array_values($pair)[1] ?? '');
                                } else {
                                    $left  = (string) $pair;
                                    $right = '';
                                }
                                $pairs[] = [
                                    'left'  => $left,
                                    'right' => $right,
                                ];
                            }
                        }

                        $options = $pairs;
                    }

                    $label = chr(ord('a') + count($subQuestions));

                    $subQuestions[] = [
                        'label' => $label,
                        'text' => $childRendered,
                        'type' => $question->question_type,
                        'marks' => $question->marks ?? 1,
                        'options' => $options,
                    ];

                    $data['total_marks'] += $question->marks ?? 0;
                }
            }

            $data['questions'] = $questionsOut;
        }

        $pdf = Pdf::loadView('assessments.pdf_student', $data);
        $pdf->setPaper('a4');
        $pdf->setOption('margin-top', 20);
        $pdf->setOption('margin-bottom', 20);
        $pdf->setOption('margin-left', 15);
        $pdf->setOption('margin-right', 15);

        $filename = 'student-assessment-' . Str::slug($assessment->title) . '.pdf';
        return $pdf->download($filename);
    }

    protected function renderKatex($latex)
    {
        $latex = escapeshellarg($latex);
        $html = shell_exec("katex --inline $latex 2>&1");
        return $html ?: $latex;
    }
}
