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

        .school-name { font-size: 18px; font-weight: bold; margin: 5px 0; }
        .school-details { font-size: 11px; color: #555; margin-bottom: 5px; }

        .assessment-title {
            font-size: 16px; font-weight: bold; text-align: center;
            margin: 15px 0; text-transform: uppercase;
        }

        .assessment-meta { display:flex; justify-content:space-between; margin-bottom:15px; font-size:11px; }

        /* ======== QUESTIONS & LAYOUT ======== */
        .question { margin-bottom:20px; page-break-inside: avoid; }
        .question-text { margin-bottom: 10px; font-weight: bold; }
        .question-number { font-weight: bold; margin-right: 5px; }
        .marks { float: right; font-weight: bold; }

        .options { margin-left:20px; margin-bottom:10px; }
        /* Table-like layout for better PDF rendering */
        .option { margin-bottom:5px; display:table; width:100%; }
        .option-label { display:table-cell; width:20px; padding-right:8px; vertical-align:top; }
        .option-text { display:table-cell; vertical-align:top; }

        .answer-space {
            border-bottom: 1px solid #000;
            min-width: 200px; display: inline-block;
            margin-left: 10px; height: 15px;
        }

        /* ======== IMAGES ======== */
        img {
            max-width: 350px; max-height: 200px; margin-top: 8px;
            page-break-inside: avoid;
        }

        /* ======== LISTS ======== */
        ul, ol { margin: 5px 0 5px 20px; }

        /* ======== FOOTER ======== */
        .footer {
            position: fixed; left: 0; right: 0; bottom: 10px;
            font-size: 10px; color: #666; text-align: center;
            border-top: 1px solid #eee; padding-top: 10px;
        }

        .student-info { margin:15px 0; padding:10px; border:1px solid #ddd; background-color:#f9f9f9; }
        .student-info-row { margin-bottom:5px; display:flex; gap: 8px; align-items: center; }
        .student-info-label { font-weight:bold; min-width:120px; }

        .total-marks { text-align:right; font-weight:bold; margin-top:10px; font-size:14px; }

        /* ======================================================
           ===============   KATEX STYLING   ====================
           ====================================================== */

        /* Inline KaTeX stylesheet provided by controller */
        @php echo $katexCss ?? ''; @endphp

        /* Slightly larger KaTeX for readability in print */
        .katex { font-size: 1.08em; }

        /* Center display equations */
        .katex-display { margin: 6px 0 8px 0; text-align: center; }
        .katex-display .katex { display: inline-block; }
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
            <div>
                Subject: {{ $subject ?? 'General' }}
                @if(!empty($grade_level)) — Grade: {{ $grade_level }} @endif
            </div>
        </div>
    </div>

    {{-- Student info --}}
    <div class="student-info">
        <div class="student-info-row">
            <div class="student-info-label">Student Name:</div>
            <div class="answer-space" style="flex:1"></div>
        </div>
        <div class="student-info-row">
            <div class="student-info-label">Class/Grade:</div>
            <div class="answer-space" style="width: 240px;"></div>
        </div>
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

                    @if(!empty($q['sub_questions']))
                        {{-- Parent question with sub-questions --}}
                        <div class="question-text">
                            <span class="question-number">{{ $loop->iteration }}.</span>
                            <span>{!! $q['clean_html'] !!}</span>

                            @if(!empty($q['image_base64']))
                                <div><img src="{{ $q['image_base64'] }}" alt=""></div>
                            @endif
                        </div>

                        @foreach($q['sub_questions'] as $sub)
                            <div class="question-text" style="margin-left:15px; margin-top:4px; font-weight: normal;">
                                {{-- a), b), c) … --}}
                                <span class="question-number">({{ chr(96 + $loop->iteration) }})</span>
                                <span>{!! $sub['clean_html'] !!}</span>

                                @if(isset($sub['marks']))
                                    <span class="marks">[{{ $sub['marks'] }} mark{{ ($sub['marks'] ?? 0) > 1 ? 's' : '' }}]</span>
                                @endif

                                @if(!empty($sub['image_base64']))
                                    <div><img src="{{ $sub['image_base64'] }}" alt=""></div>
                                @endif
                            </div>

                            {{-- Sub options --}}
                            @if(($sub['type'] ?? null) === 'matching' && !empty($sub['options']))
                                <table style="width:100%; border-collapse:collapse; margin-top:4px; font-size:11px; margin-left:15px;">
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
                                        @foreach($sub['options'] as $i => $pair)
                                            <tr>
                                                <td style="padding:3px 4px; vertical-align:top;">{{ $i + 1 }}. {!! $pair['left'] ?? '' !!}</td>
                                                <td style="padding:3px 4px; vertical-align:top;">{{ chr(65 + $i) }}. {!! $pair['right'] ?? '' !!}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @elseif(!empty($sub['options']))
                                <div class="options" style="margin-left:20px;">
                                    @foreach($sub['options'] as $i => $opt)
                                        @php
                                            $optText = is_array($opt) ? ($opt['text'] ?? $opt['value'] ?? $opt['label'] ?? '') : $opt;
                                        @endphp
                                        <div class="option">
                                            <div class="option-label">{{ chr(65 + $i) }}.</div>
                                            <div class="option-text">
                                                @if(is_array($opt) && !empty($opt['image_base64']))
                                                    <img src="{{ $opt['image_base64'] }}" style="max-height:50px;" alt="">
                                                @else
                                                    {!! $optText !!}
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @elseif(($sub['type'] ?? null) === 'short_answer')
                                <div style="margin-top:10px; margin-left:15px;">
                                    <div style="font-size:11px; color:#555; margin-bottom:3px;">Working space &amp; answer:</div>
                                    @for($i=0;$i<5;$i++)
                                        <div class="answer-space" style="width:100%; min-height:20px; margin-bottom:6px;"></div>
                                    @endfor
                                </div>
                            @else
                                <div style="margin-top:10px; min-height:50px; margin-left:15px;">
                                    <div class="answer-space" style="width:100%; min-height:50px;"></div>
                                </div>
                            @endif
                        @endforeach

                    @else
                        {{-- Standalone question --}}
                        <div class="question-text">
                            <span class="question-number">{{ $loop->iteration }}.</span>
                            <span>{!! $q['clean_html'] !!}</span>
                            @if(isset($q['marks']))
                                <span class="marks">[{{ $q['marks'] }} mark{{ ($q['marks'] ?? 0) > 1 ? 's' : '' }}]</span>
                            @endif

                            @if(!empty($q['image_base64']))
                                <div><img src="{{ $q['image_base64'] }}" alt=""></div>
                            @endif
                        </div>

                        @if(($q['type'] ?? null) === 'matching' && !empty($q['options']))
                            {{-- Matching: show left/right pairs --}}
                            <table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:11px;">
                                <colgroup>
                                    <col style="width:40%"><col style="width:60%">
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
                                            <td style="padding:3px 4px; vertical-align:top;">{{ $i + 1 }}. {!! $pair['left'] ?? '' !!}</td>
                                            <td style="padding:3px 4px; vertical-align:top;">{{ chr(65 + $i) }}. {!! $pair['right'] ?? '' !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @elseif(!empty($q['options']))
                            <div class="options">
                                @foreach($q['options'] as $i => $opt)
                                    @php
                                        $optText = is_array($opt) ? ($opt['text'] ?? $opt['value'] ?? $opt['label'] ?? '') : $opt;
                                    @endphp
                                    <div class="option">
                                        <div class="option-label">{{ chr(65 + $i) }}.</div>
                                        <div class="option-text">
                                            @if(is_array($opt) && !empty($opt['image_base64']))
                                                <img src="{{ $opt['image_base64'] }}" style="max-height:50px;" alt="">
                                            @else
                                                {!! $optText !!}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @elseif(($q['type'] ?? null) === 'short_answer')
                            <div style="margin-top:10px;">
                                <div style="font-size:11px; color:#555; margin-bottom:3px;">Working space &amp; answer:</div>
                                @for($i=0;$i<5;$i++)
                                    <div class="answer-space" style="width:100%; min-height:20px; margin-bottom:6px;"></div>
                                @endfor
                            </div>
                        @else
                            <div style="margin-top:10px; min-height:50px;">
                                <div class="answer-space" style="width:100%; min-height:50px;"></div>
                            </div>
                        @endif
                    @endif
               tions']))
                    <div class="question-text">
                        <span class="question-number">{{ $loop->iteration }}.</span>
                        <span>{!! $q['clean_html'] !!}</span>
                        @if(!empty($q['image_base64']))
                            <div>{{ $q[</div>
                        @endif
                    </div>

                    @foreach($q['sub_questions'] as $sub)
                        <div class="question-text" style="margin-left:15px; margin-top:4px; font-weight: normal;">
                            <span class="question-number">({{ chr(96 + $loop->iteration) }})</span>
                            <span>{!! $sub['clean_html'] !!}</span>
                            @if(isset($sub['marks']))
                                <span class="marks">[{{ $sub['marks'] }} mark{{ ($sub['marks'] ?? 0) > 1 ? 's' : '' }}]</span>
                            @endif
                            @if(!empty($sub['image_base64']))
                                <div>{{ $sub[</div>
                            @endif
                        </div>

                        @if(($sub['type'] ?? null) === 'matching' && !empty($sub['options']))
                            <table style="width:100%; border-collapse:collapse; margin-top:4px; font-size:11px; margin-left:15px;">
                                <colgroup>
                                    <col style="width:40%"><col style="width:60%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th style="text-align:left; padding:4px; border-bottom:1px solid #ccc;">Left Column</th>
                                        <th style="text-align:left; padding:4px; border-bottom:1px solid #ccc;">Right Column</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($sub['options'] as $i => $pair)
                                        <tr>
                                            <td style="padding:3px 4px; vertical-align:top;">{{ $i + 1 }}. {!! $pair['left'] ?? '' !!}</td>
                                            <td style="padding:3px 4px; vertical-align:top;">{{ chr(65 + $i) }}. {!! $pair['right'] ?? '' !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @elseif(!empty($sub['options']))
                            <div class="options" style="margin-left:20px;">
                                @foreach($sub['options'] as $i => $opt)
                                    @php
                                        $optText = is_array($opt) ? ($opt['text'] ?? $opt['value'] ?? $opt['label'] ?? '') : $opt;
                                    @endphp
                                    <div class="option">
                                        <div class="option-label">{{ chr(65 + $i) }}.</div>
                                        <div class="option-text">
                                            @if(is_array($opt) && !empty($opt['image_base64']))
                                                {{ $opt[
                                            @else
                                                {!! $optText !!}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @elseif(($sub['type'] ?? null) === 'short_answer')
                            <div style="margin-top:10px; margin-left:15px;">
                                <div style="font-size:11px; color:#555; margin-bottom:3px;">Working space &amp; answer:</div>
                                @for($i=0;$i<5;$i++)
                                    <div class="answer-space" style="width:100%; min-height:20px; margin-bottom:6px;"></div>
                                @endfor
                            </div>
                        @else
                            <div style="margin-top:10px; min-height:50px; margin-left:15px;">
                                <div class="answer-space" style="width:100%; min-height:50px;"></div>
                            </div>
                        @endif
                    @endforeach
                @else
                    <div class="question-text">
                        <span class="question-number">{{ $loop->iteration }}.</span>
                        <span>{!! $q['clean_html'] !!}</span>
                        @if(isset($q['marks']))
                            <span class="marks">[{{ $q['marks'] }} mark{{ ($q['marks'] ?? 0) > 1 ? 's' : '' }}]</span>
                        @endif
                        @if(!empty($q['image_base64']))
                            <div>{{ $q[</div>
                        @endif
                    </div>

                    @if(($q['type'] ?? null) === 'matching' && !empty($q['options']))
                        <table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:11px;">
                            <colgroup>
                                <col style="width:40%"><col style="width:60%">
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
                                        <td style="padding:3px 4px; vertical-align:top;">{{ $i + 1 }}. {!! $pair['left'] ?? '' !!}</td>
                                        <td style="padding:3px 4px; vertical-align:top;">{{ chr(65 + $i) }}. {!! $pair['right'] ?? '' !!}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @elseif(!empty($q['options']))
                        <div class="options">
                            @foreach($q['options'] as $i => $opt)
                                @php
                                    $optText = is_array($opt) ? ($opt['text'] ?? $opt['value'] ?? $opt['label'] ?? '') : $opt;
                                @endphp
                                <div class="option">
                                    <div class="option-label">{{ chr(65 + $i) }}.</div>
                                    <div class="option-text">
                                        @if(is_array($opt) && !empty($opt['image_base64']))
                                            {{ $opt[
                                        @else
                                            {!! $optText !!}
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif(($q['type'] ?? null) === 'short_answer')
                        <div style="margin-top:10px;">
                            <div style="font-size:11px; color:#555; margin-bottom:3px;">Working space &amp; answer:</div>
                            @for($i=0;$i<5;$i++)
                                <div class="answer-space" style="width:100%; min-height:20px; margin-bottom:6px;"></div>
                            @endfor
                        </div>
                    @else
                        <div style="margin-top:10px; min-height:50px;">
                            <div class="answer-space" style="width:100%; min-height:50px;"></div>
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