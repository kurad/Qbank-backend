<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8" />
    <title>{{ $title }} - {{ $school['school_name'] ?? 'School Name' }}</title>

    <style>
        @page {
            margin: 25.4mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.45;
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
        }

        .assessment-title {
            font-size: 16px;
            font-weight: bold;
            margin: 15px 0;
            text-transform: uppercase;
        }

        /* ======== QUESTION BLOCKS ======== */
        .question {
            margin-bottom: 22px;
            page-break-inside: avoid;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            column-gap: 20px;
            margin-bottom: 8px;
        }

        .sub-question-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-left: 20px;
            margin-top: 10px;
            column-gap: 20px;
        }

        .options {
            margin-left: 25px;
            margin-top: 5px;
        }

        .option {
            margin-bottom: 4px;
        }

        .answer-space {
            border-bottom: 1px solid #333;
            height: 18px;
            margin-top: 6px;
        }

        /* ======== IMAGES ======== */
        img {
            max-width: 350px;
            max-height: 200px;
            margin-top: 8px;
            page-break-inside: avoid;
        }

        /* ======== LISTS ======== */
        ul,
        ol {
            margin: 5px 0 5px 20px;
        }

        /* ======== FOOTER ======== */
        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 10px;
            text-align: center;
            font-size: 10px;
            color: #777;
            border-top: 1px solid #ddd;
            padding-top: 8px;
        }

        /* ======================================================
           ===============   KATEX STYLING   ====================
           ====================================================== */

        /* Inline full KaTeX stylesheet provided by controller */
            @php echo $katexCss ?? ''; @endphp 

        /* Slightly larger KaTeX for readability in print */
        .katex {
            font-size: 1.08em;
        }

        /* Center display equations */
        .katex-display {
            margin: 6px 0 8px 0;
            text-align: center;
        }

        .katex-display .katex {
            display: inline-block;
        }
    </style>
</head>

<body>

    {{-- HEADER --}}
    <div class="header">
        @if(!empty($school['logo_base64']))
        <img src="{{ $school['logo_base64'] }}" style="max-height:80px;">
        @endif

        <div class="school-name">{{ $school['school_name'] ?? '' }}</div>
        <div class="assessment-title">{{ $title }}</div>

        <div style="font-size:12px;">
            Subject: {{ $subject ?? 'General' }}
            @if(!empty($grade_level))
            — Grade: {{ $grade_level }}
            @endif
        </div>
    </div>

    {{-- STUDENT INFO --}}
    <div style="border:1px solid #ccc; padding:10px; background:#fafafa; margin-bottom:20px;">
        <div style="margin-bottom:6px;">
            <strong>Student Name:</strong> __________________________________________
        </div>
        <div>
            <strong>Class/Grade:</strong> __________________________________________
        </div>
    </div>

    {{-- INSTRUCTIONS --}}
    <div style="padding:10px; background:#f5f5f5; border-left:4px solid #333; margin-bottom:25px;">
        <strong>Instructions:</strong>
        <ul style="margin:5px 0 0 20px;">
            @foreach(($instructions ?? []) as $line)
            <li>{{ $line }}</li>
            @endforeach
        </ul>
    </div>

    {{-- QUESTIONS --}}
    @foreach($questions as $q)
    <div class="question">

        {{-- MAIN QUESTION --}}
        <div class="question-header">
            <div>
                <strong>{{ $loop->iteration }}.</strong>
                <span>{!! $q['clean_html'] !!}</span>

                @if($q['image_base64'])
                <br>
                <img src="{{ $q['image_base64'] }}">
                @endif
            </div>

            @if($q['marks'])
            <div><strong>[{{ $q['marks'] }}]</strong></div>
            @endif
        </div>

        {{-- SUB‑QUESTIONS --}}
        @if(!empty($q['sub_questions']))
        @foreach($q['sub_questions'] as $i => $sub)
        <div class="sub-question-row">
            <div>
                <strong>({{ chr(97 + $i) }})</strong>
                <span>{!! $sub['clean_html'] !!}</span>

                @if($sub['image_base64'])
                <br>
                <img src="{{ $sub['image_base64'] }}">
                @endif

                {{-- OPTIONS --}}
                @if(!empty($sub['options']))
                <div class="options">
                    @foreach($sub['options'] as $x => $op)
                    <div class="option">
                        <strong>{{ chr(65 + $x) }}.</strong>
                        <span>{!! is_array($op) ? ($op['text'] ?? '') : $op !!}</span>
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- SHORT ANSWER SPACES --}}
                @if(($sub['type'] ?? null) === 'short_answer')
                @for($line = 0; $line < 4; $line++)
                    <div class="answer-space">
            </div>
            @endfor
            @endif
        </div>

        <div><strong>[{{ $sub['marks'] }}]</strong></div>
    </div>
    @endforeach
    @endif

    {{-- MAIN QUESTION OPTIONS --}}
    @if(($q['type'] ?? null) === 'matching' && !empty($q['options']))
    <table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:11px;">
        <colgroup>
            <col style="width:40%">
            <col style="width:60%">
        </colgroup>

        <thead>
            <tr>
                <th style="text-align:left; padding:4px; border-bottom:1px solid #ccc;">Left Column</th>
                <th style="text-align:left; padding:4px; border-bottom:1px solid #ccc;">Right Column</th>
            </tr>
        </thead>

        <tbody>
            @foreach($q['options'] as $i => $pair)
            <tr>
                <td style="padding:3px 4px; vertical-align:top;">
                    {{ $i + 1 }}. {!! $pair['left'] ?? '' !!}
                </td>
                <td style="padding:3px 4px; vertical-align:top;">
                    {{ chr(65 + $i) }}. {!! $pair['right'] ?? '' !!}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @elseif(!empty($q['options']))
    <div class="options">
        @foreach($q['options'] as $i => $opt)
        <div class="option">
            <strong>{{ chr(65 + $i) }}.</strong>
            <span>{!! is_array($opt) ? ($opt['text'] ?? '') : $opt !!}</span>
        </div>
        @endforeach
    </div>
    @endif

    {{-- MAIN SHORT ANSWER --}}
    @if(($q['type'] ?? null) === 'short_answer')
    @for($i = 0; $i < 4; $i++)
        <div class="answer-space">
        </div>
        @endfor
        @endif

        </div>
        @endforeach

        {{-- FOOTER --}}
        <div class="footer">
            {{ $school['school_name'] ?? '' }} | {{ $title }} | {{ date('F j, Y') }}
        </div>

</body>

</html>