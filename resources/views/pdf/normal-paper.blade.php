<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>{{ $title }} - {{ $school['school_name'] ?? 'School Name' }}</title>

    <style>
        @page { margin: 25.4mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            padding-bottom: 40px;
        }

        /* ======== HEADER ======== */
        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
            text-align: center;
        }

        .school-name {
            font-size: 18px;
            font-weight: bold;
            margin: 5px 0;
        }

        .assessment-title {
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            margin: 15px 0;
            text-transform: uppercase;
        }

        .assessment-meta { width: 100%; margin-bottom: 15px; font-size: 11px; }
        .assessment-meta-table { width: 100%; border-collapse: collapse; }
        .assessment-meta-table td { vertical-align: top; }
        .assessment-meta-left { text-align: left; }
        .assessment-meta-right { text-align: right; }

        /* ======== QUESTIONS ======== */
        .question { margin-bottom: 20px; }
        .question-text { margin-bottom: 10px; font-weight: bold; page-break-inside: avoid; }
        .question-number { font-weight: bold; margin-right: 5px; }

        .marks { float: right; font-weight: bold; }

        .options { margin-left: 20px; margin-bottom: 10px; }

        /* Better PDF rendering for options */
        .option { margin-bottom: 5px; display: table; width: 100%; page-break-inside: avoid; }
        .option-label { display: table-cell; width: 20px; padding-right: 8px; vertical-align: top; }
        .option-text { display: table-cell; vertical-align: top; }

        /* Small answer line (used for student name/class) */
        .answer-space {
            border-bottom: 1px solid #000;
            min-width: 200px;
            display: inline-block;
            margin-left: 10px;
            height: 15px;
        }

        /* ======== IMAGES ======== */
        .question-image {
            max-width: 300px;
            max-height: 180px;
            margin-top: 8px;
            page-break-inside: avoid;
        }
        .option-image {
            max-height: 50px;
            page-break-inside: avoid;
        }

        /* ======== FOOTER ======== */
        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 10px;
            font-size: 10px;
            color: #666;
            text-align: center;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        .student-info {
            margin: 15px 0;
            padding: 10px;
            border: 1px solid #ddd;
            background-color: #f9f9f9;
        }
        .student-info-table { width: 100%; border-collapse: collapse; }
        .student-info-table td { padding: 3px 0; vertical-align: middle; }
        .student-info-label { font-weight: bold; width: 140px; }

        /* ======================================================
           ===============   KATEX STYLING   ====================
           ====================================================== */
        @php echo $katexCss ?? ''; @endphp

        .katex { font-size: 1.08em; }
        .katex-display { margin: 6px 0 8px 0; text-align: center; }
        .katex-display .katex { display: inline-block; }
        .math-inline img { height: 14px; vertical-align: middle; }
        .math-block { text-align: center; margin: 6px 0; }
        .math-block img { height: 22px; }

        /* ======== WORKING SPACE (SHORT ANSWER) ======== */
        .work-block { margin-top: 8px; page-break-inside: avoid; }
        .work-label { font-size: 11px; color: #555; margin-bottom: 4px; font-weight: normal; }
        .work-line {
            border-bottom: 1px solid #000;
            height: 28px;
            margin-bottom: 8px;
            width: 100%;
        }

        /* Matching table */
        .match-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 11px; page-break-inside: avoid; }
        .match-table th { text-align: left; padding: 4px; border-bottom: 1px solid #ccc; }
        .match-table td { padding: 3px 4px; vertical-align: top; border-bottom: 1px solid #eee; }
    </style>
</head>

<body>
    {{-- Header --}}
    <div class="header">
        @if(!empty($school['logo_base64']))
            <div><img src="{{ $school['logo_base64'] }}" alt="Logo" style="max-height:80px;"></div>
        @endif

        <div class="school-name">{{ $school['school_name'] ?? 'School Name' }}</div>
        <div class="assessment-title">{{ $title }}</div>

        <div class="assessment-meta">
            <table class="assessment-meta-table">
                <tr>
                    <td class="assessment-meta-left">
                        Subject: {{ $subject ?? 'General' }}
                        @if(!empty($grade_level)) — Grade: {{ $grade_level }} @endif
                    </td>
                    <td class="assessment-meta-right"></td>
                </tr>
            </table>
        </div>
    </div>

    {{-- Student info --}}
    <div class="student-info">
        <table class="student-info-table">
            <tr>
                <td class="student-info-label">Student Name:</td>
                <td><div class="answer-space" style="width:100%"></div></td>
            </tr>
            <tr>
                <td class="student-info-label">Class/Grade:</td>
                <td><div class="answer-space" style="width:240px"></div></td>
            </tr>
        </table>
    </div>

    {{-- Instructions --}}
    <div style="margin:15px 0; padding:10px; background-color:#f0f0f0; border-left:4px solid #333;">
        <strong>Instructions:</strong>
        <ul style="margin:5px 0 0 20px; padding:0;">
            @foreach(($instructions ?? []) as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </div>

    {{-- =========================
         Helper: working space
         ========================= --}}
    @php
        /**
         * Decide number of lines from marks.
         * 1 mark -> 3 lines, cap 10.
         */
        $calcLines = function ($marks) {
            $m = (int)($marks ?? 1);
            return min(10, max(3, (int) ceil($m * 3)));
        };
    @endphp

    {{-- Questions --}}
    @if(!empty($sections))

        @foreach($sections as $section)
            <h3 style="margin:10px 0 4px 0; font-size:14px; background-color:#f0f0f0; padding: 5px 10px;">
                {{ $section['title'] }}
            </h3>

            @if(!empty($section['instruction']))
                <p style="font-size:11px; color:#555; margin:2px 0 8px 0;">
                    {!! $section['instruction'] !!}
                </p>
            @endif

            @foreach($section['questions'] as $q)
                <div class="question">

                    {{-- Parent with sub-questions --}}
                    @if(!empty($q['sub_questions']))

                        <div class="question-text">
                            <span class="question-number">{{ $loop->iteration }}.</span>
                            <span>{!! $q['clean_html'] !!}</span>

                            @if(!empty($q['image_base64']))
                                <div><img class="question-image" src="{{ $q['image_base64'] }}" alt=""></div>
                            @endif
                        </div>

                        @foreach($q['sub_questions'] as $sub)
                            <div class="question-text" style="margin-left:15px; margin-top:4px; font-weight: normal;">
                                <span class="question-number">({{ chr(96 + $loop->iteration) }})</span>
                                <span>{!! $sub['clean_html'] !!}</span>

                                @if(isset($sub['marks']))
                                    <span class="marks">[{{ $sub['marks'] }} mark{{ ((int)($sub['marks'] ?? 0)) > 1 ? 's' : '' }}]</span>
                                @endif

                                @if(!empty($sub['image_base64']))
                                    <div><img class="question-image" src="{{ $sub['image_base64'] }}" alt=""></div>
                                @endif
                            </div>

                            {{-- Sub options --}}
                            @if(($sub['type'] ?? null) === 'matching' && !empty($sub['options']))
                                <table class="match-table" style="margin-left:15px;">
                                    <colgroup>
                                        <col style="width:40%">
                                        <col style="width:60%">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>Left Column</th>
                                            <th>Right Column</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($sub['options'] as $i => $pair)
                                            <tr style="page-break-inside:avoid;">
                                                <td>{{ $i + 1 }}. {!! $pair['left'] ?? '' !!}</td>
                                                <td>{{ chr(65 + $i) }}. {!! $pair['right'] ?? '' !!}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                            @elseif(!empty($sub['options']))
                                <div class="options" style="margin-left:20px;">
                                    @foreach($sub['options'] as $i => $opt)
                                        <div class="option">
                                            <div class="option-label">{{ chr(65 + $i) }}.</div>
                                            <div class="option-text">
                                                @if(is_array($opt) && !empty($opt['image_base64']))
                                                    <img class="option-image" src="{{ $opt['image_base64'] }}" alt="">
                                                @else
                                                    {!! is_array($opt) ? ($opt['text'] ?? '') : $opt !!}
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                            @elseif(($sub['type'] ?? null) === 'short_answer')
                                <div class="work-block" style="margin-left:15px;">
                                    <div class="work-label">Working space &amp; answer:</div>
                                    @php $lines = $calcLines($sub['marks'] ?? 1); @endphp
                                    @for($i = 0; $i < $lines; $i++)
                                        <div class="work-line"></div>
                                    @endfor
                                </div>

                            @else
                                <div class="work-block" style="margin-left:15px;">
                                    <div class="work-line" style="height:40px;"></div>
                                </div>
                            @endif
                        @endforeach

                    {{-- Standalone question --}}
                    @else
                        <div class="question-text">
                            <span class="question-number">{{ $loop->iteration }}.</span>
                            <span>{!! $q['clean_html'] !!}</span>

                            @if(isset($q['marks']))
                                <span class="marks">[{{ $q['marks'] }} mark{{ ((int)($q['marks'] ?? 0)) > 1 ? 's' : '' }}]</span>
                            @endif

                            @if(!empty($q['image_base64']))
                                <div><img class="question-image" src="{{ $q['image_base64'] }}" alt=""></div>
                            @endif
                        </div>

                        @if(($q['type'] ?? null) === 'matching' && !empty($q['options']))
                            <table class="match-table">
                                <colgroup>
                                    <col style="width:40%">
                                    <col style="width:60%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Left Column</th>
                                        <th>Right Column</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($q['options'] as $i => $pair)
                                        <tr style="page-break-inside:avoid;">
                                            <td>{{ $i + 1 }}. {!! $pair['left'] ?? '' !!}</td>
                                            <td>{{ chr(65 + $i) }}. {!! $pair['right'] ?? '' !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                        @elseif(!empty($q['options']))
                            <div class="options">
                                @foreach($q['options'] as $i => $opt)
                                    <div class="option">
                                        <div class="option-label">{{ chr(65 + $i) }}.</div>
                                        <div class="option-text">
                                            @if(is_array($opt) && !empty($opt['image_base64']))
                                                <img class="option-image" src="{{ $opt['image_base64'] }}" alt="">
                                            @else
                                                {!! is_array($opt) ? ($opt['text'] ?? '') : $opt !!}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                        @elseif(($q['type'] ?? null) === 'short_answer')
                            <div class="work-block">
                                <div class="work-label">Working space &amp; answer:</div>
                                @php $lines = $calcLines($q['marks'] ?? 1); @endphp
                                @for($i = 0; $i < $lines; $i++)
                                    <div class="work-line"></div>
                                @endfor
                            </div>

                        @else
                            <div class="work-block">
                                <div class="work-line" style="height:40px;"></div>
                            </div>
                        @endif
                    @endif

                </div>
            @endforeach

            <hr style="margin:20px 0; border:0; border-top:1px solid #ccc;" />
        @endforeach

    @else
        {{-- Flat (non-section) assessments --}}
        @foreach($questions as $q)
            <div class="question">

                @if(!empty($q['sub_questions']))
                    <div class="question-text">
                        <span class="question-number">{{ $loop->iteration }}.</span>
                        <span>{!! $q['clean_html'] !!}</span>
                        @if(!empty($q['image_base64']))
                            <div><img class="question-image" src="{{ $q['image_base64'] }}" alt=""></div>
                        @endif
                    </div>

                    @foreach($q['sub_questions'] as $sub)
                        <div class="question-text" style="margin-left:15px; margin-top:4px; font-weight: normal;">
                            <span class="question-number">({{ chr(96 + $loop->iteration) }})</span>
                            <span>{!! $sub['clean_html'] !!}</span>

                            @if(isset($sub['marks']))
                                <span class="marks">[{{ $sub['marks'] }} mark{{ ((int)($sub['marks'] ?? 0)) > 1 ? 's' : '' }}]</span>
                            @endif

                            @if(!empty($sub['image_base64']))
                                <div><img class="question-image" src="{{ $sub['image_base64'] }}" alt=""></div>
                            @endif
                        </div>

                        @if(($sub['type'] ?? null) === 'matching' && !empty($sub['options']))
                            <table class="match-table" style="margin-left:15px;">
                                <colgroup>
                                    <col style="width:40%">
                                    <col style="width:60%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Left Column</th>
                                        <th>Right Column</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($sub['options'] as $i => $pair)
                                        <tr style="page-break-inside:avoid;">
                                            <td>{{ $i + 1 }}. {!! $pair['left'] ?? '' !!}</td>
                                            <td>{{ chr(65 + $i) }}. {!! $pair['right'] ?? '' !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                        @elseif(!empty($sub['options']))
                            <div class="options" style="margin-left:20px;">
                                @foreach($sub['options'] as $i => $opt)
                                    <div class="option">
                                        <div class="option-label">{{ chr(65 + $i) }}.</div>
                                        <div class="option-text">
                                            @if(is_array($opt) && !empty($opt['image_base64']))
                                                <img class="option-image" src="{{ $opt['image_base64'] }}" alt="">
                                            @else
                                                {!! is_array($opt) ? ($opt['text'] ?? '') : $opt !!}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                        @elseif(($sub['type'] ?? null) === 'short_answer')
                            <div class="work-block" style="margin-left:15px;">
                                <div class="work-label">Working space &amp; answer:</div>
                                @php $lines = $calcLines($sub['marks'] ?? 1); @endphp
                                @for($i = 0; $i < $lines; $i++)
                                    <div class="work-line"></div>
                                @endfor
                            </div>

                        @else
                            <div class="work-block" style="margin-left:15px;">
                                <div class="work-line" style="height:40px;"></div>
                            </div>
                        @endif
                    @endforeach

                @else
                    <div class="question-text">
                        <span class="question-number">{{ $loop->iteration }}.</span>
                        <span>{!! $q['clean_html'] !!}</span>

                        @if(isset($q['marks']))
                            <span class="marks">[{{ $q['marks'] }} mark{{ ((int)($q['marks'] ?? 0)) > 1 ? 's' : '' }}]</span>
                        @endif

                        @if(!empty($q['image_base64']))
                            <div><img class="question-image" src="{{ $q['image_base64'] }}" alt=""></div>
                        @endif
                    </div>

                    @if(($q['type'] ?? null) === 'matching' && !empty($q['options']))
                        <table class="match-table">
                            <colgroup>
                                <col style="width:40%">
                                <col style="width:60%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Left Column</th>
                                    <th>Right Column</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($q['options'] as $i => $pair)
                                    <tr style="page-break-inside:avoid;">
                                        <td>{{ $i + 1 }}. {!! $pair['left'] ?? '' !!}</td>
                                        <td>{{ chr(65 + $i) }}. {!! $pair['right'] ?? '' !!}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                    @elseif(!empty($q['options']))
                        <div class="options">
                            @foreach($q['options'] as $i => $opt)
                                <div class="option">
                                    <div class="option-label">{{ chr(65 + $i) }}.</div>
                                    <div class="option-text">
                                        @if(is_array($opt) && !empty($opt['image_base64']))
                                            <img class="option-image" src="{{ $opt['image_base64'] }}" alt="">
                                        @else
                                            {!! is_array($opt) ? ($opt['text'] ?? '') : $opt !!}
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                    @elseif(($q['type'] ?? null) === 'short_answer')
                        <div class="work-block">
                            <div class="work-label">Working space &amp; answer:</div>
                            @php $lines = $calcLines($q['marks'] ?? 1); @endphp
                            @for($i = 0; $i < $lines; $i++)
                                <div class="work-line"></div>
                            @endfor
                        </div>

                    @else
                        <div class="work-block">
                            <div class="work-line" style="height:40px;"></div>
                        </div>
                    @endif
                @endif

            </div>
        @endforeach
    @endif

    {{-- Footer --}}
    <div class="footer">
        {{ $school['school_name'] ?? 'School Name' }} | {{ $title }} | {{ date('F j, Y') }}
    </div>
</body>
</html>
