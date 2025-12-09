<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8"/>
    <title>{{ $title }} - {{ $school['school_name'] ?? 'School Name' }}</title>

    <style>
        @page { margin: 25.4mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.45;
            color: #333;
        }

        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
            text-align:center;
        }

        .school-name { font-size: 18px; font-weight: bold; margin-top: 10px; }
        .assessment-title { font-size: 16px; font-weight: bold; margin: 15px 0; text-transform: uppercase; }

        .section-title {
            font-size: 15px;
            font-weight: bold;
            margin: 20px 0 5px 0;
            padding: 5px 0;
            border-bottom: 1px solid #999;
        }

        .section-instruction {
            font-size: 11px;
            margin-bottom: 10px;
            color: #555;
        }

        .question { margin-bottom: 18px; }
        .question-number { font-weight: bold; margin-right: 5px; }
        .question-text { margin-bottom: 8px; }

        .options { margin-left: 20px; }
        .option { display: table; margin-bottom: 4px; }
        .option-label { display: table-cell; width: 20px; }
        .option-text { display: table-cell; }

        .answer-space {
            border-bottom: 1px solid #333;
            height: 18px;
            margin-top: 6px;
        }

        .marks { float: right; font-weight: bold; }
        .footer { margin-top: 30px; text-align:center; font-size:10px; color:#777; border-top:1px solid #ddd; padding-top:8px; }

    </style>
</head>

<body>

    {{-- HEADER --}}
    <div class="header">
        @if(!empty($school['logo']) && file_exists($school['logo']))
            <img src="{{ $school['logo'] }}" style="max-height:80px;">
        @endif

        <div class="school-name">{{ $school['school_name'] ?? '' }}</div>
        <div class="assessment-title">{{ $title }}</div>

        <div style="font-size:12px;">
            Subject: {{ $subject ?? 'General' }}
        </div>
    </div>

    {{-- STUDENT INFO --}}
    <div style="border:1px solid #ccc; padding:10px; background:#fafafa; margin-bottom:20px;">
        <div style="margin-bottom:6px;"><strong>Student Name:</strong> __________________________________________</div>
        <div><strong>Class/Grade:</strong> __________________________________________</div>
    </div>

    {{-- INSTRUCTIONS --}}
    <div style="padding:10px; background:#f5f5f5; border-left:4px solid #333; margin-bottom:20px;">
        <strong>Instructions:</strong>
        <ul style="margin:5px 0 0 20px; font-size:12px;">
            <li>Attempt all questions.</li>
            <li>Show all working where required.</li>
            <li>For multiple-choice questions, circle the correct answer.</li>
        </ul>
    </div>

    {{-- ========== QUESTIONS (FLAT ONLY - NO SECTIONS) ========== --}}

    @foreach($questions as $q)
        <div class="question">
            @if(!empty($q['sub_questions']))

                <div class="question-text">
                    <span class="question-number">{{ is_array($q) ? $q['number'] : $q->id }}.</span>
                    {!! is_array($q) ? $q['text'] : $q->question !!}
                    @if(!empty(is_array($q) ? $q['image'] : $q->question_image) && file_exists(public_path(is_array($q) ? $q['image'] : $q->question_image)))
                        <div><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path(is_array($q) ? $q['image'] : $q->question_image))) }}" style="max-width:350px; max-height:180px;"></div>
                    @endif
                </div>

                @foreach(is_array($q) ? $q['sub_questions'] : $q->subQuestions as $sub)
                    <div class="question-text" style="margin-left:15px;">
                        <span class="question-number">({{ is_array($sub) ? $sub['label'] : chr(97 + $loop->index) }})</span>
                        {!! is_array($sub) ? $sub['text'] : $sub->question !!}
                        <span class="marks">[{{ is_array($sub) ? $sub['marks'] : $sub->marks }}]</span>
                        @if(!empty(is_array($sub) ? $sub['image'] : $sub->question_image) && file_exists(public_path(is_array($sub) ? $sub['image'] : $sub->question_image)))
                            <div><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path(is_array($sub) ? $sub['image'] : $sub->question_image))) }}" style="max-width:350px; max-height:180px;"></div>
                        @endif
                    </div>

                    @php
                        $subOptions = is_array($sub)
                            ? ($sub['options'] ?? [])
                            : ($sub->options ?? []);

                        $subType = is_array($sub)
                            ? ($sub['type'] ?? null)
                            : ($sub->type ?? ($sub->question_type ?? null));
                    @endphp

                    @if(!empty($subOptions))
                        <div class="options" style="margin-left:25px;">
                            @foreach($subOptions as $i => $opt)
                                <div class="option">
                                    <div class="option-label">{{ chr(65 + $i) }}.</div>
                                    <div class="option-text">
                                        {!! is_array($opt) ? ($opt['text'] ?? '') : $opt !!}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($subType === 'short_answer')
                        @for($i=0; $i<4; $i++)
                            <div class="answer-space"></div>
                        @endfor
                    @endif

                @endforeach

            @else
                <div class="question-text">
                    <span class="question-number">{{ is_array($q) ? $q['number'] : $q->id }}.</span>
                    {!! is_array($q) ? $q['text'] : $q->question !!}
                    <span class="marks">[{{ is_array($q) ? $q['marks'] : $q->marks }}]</span>
                </div>

                @if(!empty(is_array($q) ? $q['options'] : $q->options))
                    <div class="options">
                        @foreach(is_array($q) ? $q['options'] : $q->options as $i => $opt)
                            <div class="option">
                                <div class="option-label">{{ chr(65 + $i) }}.</div>
                                <div class="option-text">
                                    {!! is_array($opt) ? ($opt['text'] ?? '') : $opt !!}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if((is_array($q) ? $q['type'] : $q->question_type) === 'short_answer')
                    @for($i=0; $i<4; $i++)
                        <div class="answer-space"></div>
                    @endfor
                @endif

            @endif
        </div>
    @endforeach

    <div class="footer">
        {{ $school['school_name'] ?? '' }} | {{ $title }} | {{ date('F j, Y') }}
    </div>

</body>
</html>
