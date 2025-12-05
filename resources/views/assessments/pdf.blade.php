<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }} - {{ $school['school_name'] }}</title>
    <style>
        @page {
            margin: 1.5cm 1cm;
            @bottom-center {
                content: "Page " counter(page) " of " counter(pages);
                font-size: 10px;
                color: #666;
            }
        }
        
        body { 
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #333;
            margin: 0;
            padding: 0;
        }
        
        .header {
            border-bottom: 2px solid #1a5276;
            padding-bottom: 8px;
            margin-bottom: 10px;
            position: relative;
            background: #f8f9fa;
        }
        .school-logo {
            max-height: 50px;
            margin-bottom: 4px;
            border-radius: 50%;
            border: 1px solid #e0e0e0;
        }
        .school-info {
            text-align: center;
            margin-bottom: 4px;
        }
        .school-name {
            font-size: 14pt;
            font-weight: bold;
            color: #1a5276;
            margin: 2px 0 2px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .school-details {
            font-size: 8.5pt;
            color: #555;
            margin-bottom: 2px;
            line-height: 1.2;
        }
        
        .assessment-title {
            font-size: 11pt;
            font-weight: bold;
            text-align: center;
            margin: 6px 0 4px 0;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .assessment-meta {
            margin: 6px 0 4px 0;
            font-size: 9pt;
            background-color: #f8f9fa;
            padding: 4px 8px;
            border-radius: 3px;
            border-left: 2px solid #1a5276;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .meta-row {
            display: flex;
            align-items: center;
            gap: 2px;
        }
        .meta-label {
            font-weight: 600;
            color: #2c3e50;
            min-width: 50px;
            display: inline-block;
        }
        .meta-value {
            color: #1a5276;
            font-weight: 500;
        }
        
        .total-marks {
            text-align: right;
            margin: 6px 0 4px 0;
            font-size: 10pt;
            color: #1a5276;
            padding: 3px 8px;
            background: #f0f7ff;
            border-radius: 5px;
            border-left: 2px solid #1a5276;
            font-weight: bold;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        
        .marking-notes {
            margin-top: 4px;
            padding: 5px;
            background-color: #f9f9f9;
            border-radius: 2px;
            border-left: 2px solid #1a5276;
            font-size: 8.5pt;
        }
        
        .marking-points {
            margin-top: 2px;
            margin-left: 4px;
            font-size: 8.5pt;
            line-height: 1.3;
        }
        
        .marking-points div {
            margin: 1px 0;
        }
        
        .question {
            margin-bottom: 6px;
            border: 1px solid #e0e0e0;
            padding: 5px 7px 4px 7px;
            border-radius: 3px;
            background-color: #fff;
            position: relative;
        }
        
        .question-number {
            font-weight: bold;
            color: #1a5276;
            background: #eaf6fb;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 5px;
            font-size: 9pt;
        }
        
        .question-text {
            margin-bottom: 3px;
            font-size: 9.5pt;
            color: #2c3e50;
            line-height: 1.3;
        }
        
        .question-text img {
            max-width: 100%;
            height: auto;
            margin: 2px 0;
        }
        
        .options {
            margin: 3px 0 0 12px;
        }
        
        .option {
            margin-bottom: 2px;
            padding: 1px 0 1px 16px;
            position: relative;
            page-break-inside: avoid;
        }
        
        .option:before {
            content: "";
            position: absolute;
            left: 0;
            top: 3px;
            width: 10px;
            height: 10px;
            border: 1px solid #999;
            border-radius: 2px;
            background-color: #fff;
        }
        
        .option.correct-answer:before {
            background-color: #28a745;
            border-color: #28a745;
            content: "✓";
            color: white;
            text-align: center;
            line-height: 10px;
            font-size: 8px;
            font-weight: bold;
        }
        
        .correct-answer {
            color: #28a745;
            font-weight: 500;
        }
        
        .marks {
            float: right;
            font-weight: bold;
            color: #1a5276;
            background-color: #e8f0f7;
            padding: 1px 4px;
            border-radius: 5px;
            font-size: 8pt;
            margin-left: 4px;
        }
        
        .student-answer-space {
            margin-top: 15px;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 4px;
            border-left: 3px solid #1a5276;
        }
        
        .student-answer-line {
            border-bottom: 1px dashed #999;
            display: block;
            width: 100%;
            margin: 15px 0;
            height: 20px;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8pt;
            color: #fff;
            padding: 4px 0;
            background: #1a5276;
            border-top: 1px solid #1a5276;
            letter-spacing: 0.5px;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .clearfix:after {
            content: "";
            display: table;
            clear: both;
        }
        
        @media print {
            .no-print {
                display: none;
            }
            
            body {
                padding-bottom: 50px;
            }
        }
    </style>
</head>
<body>
    <!-- Header with School Info -->
    <div class="header">
        <div class="school-info">
            @if(isset($school['logo']) && $school['logo'] && file_exists($school['logo']))
                <img src="{{ $school['logo'] }}" alt="School Logo" class="school-logo">
            @endif
            
            <h1 class="school-name">{{ $school['school_name'] ?? 'School Name' }}</h1>
            <div class="school-details">
                {{ $school['address'] ?? 'School Address' }}<br>
                Tel: {{ $school['phone'] ?? 'N/A' }} | Email: {{ $school['email'] ?? 'N/A' }}
            </div>
        </div>
        
        <h2 class="assessment-title">{{ $title }}</h2>
        
        <div class="assessment-meta">
            <div class="meta-row">
                <span class="meta-label">Subject:</span>
                <span class="meta-value">{{ $subject ?? 'General' }}</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Topic:</span>
                <span class="meta-value">{{ $topic ?? 'General' }}</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Date:</span>
                <span class="meta-value">{{ $created_at ?? date('F j, Y') }}</span>
            </div>
        </div>
        
        @if(isset($total_marks))
            <div class="total-marks">
                <div>Total Marks: <strong>{{ $total_marks }}</strong></div>
            </div>
        @endif
        
        <div class="clearfix"></div>
    </div>

    <!-- Questions Section -->
    <div class="questions-container">
        @if(!empty($sections))
            @foreach($sections as $section)
                <h3 style="margin:8px 0 4px 0; font-size:11pt; color:#2c3e50;">
                    {{ $section['title'] }}
                </h3>
                @if(!empty($section['instruction']))
                    <p style="font-size:9pt; color:#555; margin:2px 0 6px 0;">
                        {{ $section['instruction'] }}
                    </p>
                @endif

                @foreach($section['questions'] as $index => $question)
                    <div class="question">
                        @if(!empty($question['sub_questions']))
                            {{-- Parent question with sub-questions --}}
                            <div class="question-text">
                                <span class="question-number">Q{{ $question['number'] }}.</span>
                                <span>{!! nl2br(e($question['text'])) !!}</span>
                            </div>

                            @if(!empty($question['image']) && file_exists($question['image']))
                                <div class="question-image">
                                    <img src="{{ $question['image'] }}" alt="Question Image" style="max-width: 100%; max-height: 200px; display: block; margin: 10px 0; border: 1px solid #ddd;" />
                                </div>
                            @endif

                            @foreach($question['sub_questions'] as $sub)
                                <div style="margin-left:15px; margin-top:4px;">
                                    <strong>({{ $sub['label'] }})</strong>
                                    <span>{!! nl2br(e($sub['text'])) !!}</span>
                                    <span class="marks">{{ $sub['marks'] }} mark{{ $sub['marks'] > 1 ? 's' : '' }}</span>
                                </div>

                                @if($sub['type'] === 'matching' && !empty($sub['options']))
                                    <table style="width:100%; border-collapse:collapse; margin:4px 0 6px 20px; font-size:9pt;">
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
                                    <div class="options" style="margin-left:25px;">
                                        @foreach($sub['options'] as $optionIndex => $option)
                                            <div class="option {{ isset($option['is_correct']) && $option['is_correct'] ? 'correct-answer' : '' }}">
                                                {{ $option['text'] }}
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="marking-notes" style="margin-top:4px;">
                                    <div><strong>Marking Guide ({{ $sub['label'] }}):</strong></div>
                                    <div class="marking-points">
                                        @if($sub['type'] === 'true_false' && !empty($sub['options']))
                                            <div>• Correct answer: <strong>{{ $sub['options'][0]['is_correct'] ? 'True' : 'False' }}</strong></div>
                                        @elseif($sub['type'] === 'mcq' && !empty($sub['options']))
                                            @php
                                                $correctOption = collect($sub['options'])->firstWhere('is_correct', true);
                                                $correctAnswer = $correctOption ? $correctOption['text'] : 'No correct answer specified';
                                            @endphp
                                            <div>• Correct answer: <strong>{{ $correctAnswer }}</strong></div>
                                        @endif
                                        <div>• Marks: <strong>{{ $sub['marks'] }} mark{{ $sub['marks'] > 1 ? 's' : '' }}</strong></div>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            {{-- Standalone question (no sub-questions) --}}
                            <div class="question-text">
                                <span class="question-number">Q{{ $question['number'] }}.</span>
                                <span>{!! nl2br(e($question['text'])) !!}</span>
                                <span class="marks">{{ $question['marks'] }} mark{{ $question['marks'] > 1 ? 's' : '' }}</span>
                            </div>

                            @if(!empty($question['image']) && file_exists($question['image']))
                                <div class="question-image">
                                    <img src="{{ $question['image'] }}" alt="Question Image" style="max-width: 100%; max-height: 200px; display: block; margin: 10px 0; border: 1px solid #ddd;" />
                                </div>
                            @endif

                            @if(!empty($question['options']))
                                <div class="options">
                                    @foreach($question['options'] as $optionIndex => $option)
                                        <div class="option {{ isset($option['is_correct']) && $option['is_correct'] ? 'correct-answer' : '' }}">
                                            {{ $option['text'] }}
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="marking-notes">
                                <div><strong>Marking Guide:</strong></div>
                                <div class="marking-points">
                                    @if($question['type'] === 'true_false' && !empty($question['options']))
                                        <div>• Correct answer: <strong>{{ $question['options'][0]['is_correct'] ? 'True' : 'False' }}</strong></div>
                                    @elseif($question['type'] === 'mcq' && !empty($question['options']))
                                        @php
                                            $correctOption = collect($question['options'])->firstWhere('is_correct', true);
                                            $correctAnswer = $correctOption ? $correctOption['text'] : 'No correct answer specified';
                                        @endphp
                                        <div>• Correct answer: <strong>{{ $correctAnswer }}</strong></div>
                                    @endif
                                    <div>• Marks: <strong>{{ $question['marks'] }} mark{{ $question['marks'] > 1 ? 's' : '' }}</strong></div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            @endforeach
        @else
            @foreach($questions as $index => $question)
                <div class="question">
                    @if(!empty($question['sub_questions']))
                        {{-- Parent question with sub-questions --}}
                        <div class="question-text">
                            <span class="question-number">Q{{ $question['number'] }}.</span>
                            <span>{!! nl2br(e($question['text'])) !!}</span>
                        </div>

                        @if(!empty($question['image']) && file_exists($question['image']))
                            <div class="question-image">
                                <img src="{{ $question['image'] }}" alt="Question Image" style="max-width: 100%; max-height: 200px; display: block; margin: 10px 0; border: 1px solid #ddd;" />
                            </div>
                        @endif

                        @foreach($question['sub_questions'] as $sub)
                            <div style="margin-left:15px; margin-top:4px;">
                                <strong>({{ $sub['label'] }})</strong>
                                <span>{!! nl2br(e($sub['text'])) !!}</span>
                                <span class="marks">{{ $sub['marks'] }} mark{{ $sub['marks'] > 1 ? 's' : '' }}</span>
                            </div>

                            @if($sub['type'] === 'matching' && !empty($sub['options']))
                                <table style="width:100%; border-collapse:collapse; margin:4px 0 6px 20px; font-size:9pt;">
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
                                <div class="options" style="margin-left:25px;">
                                    @foreach($sub['options'] as $optionIndex => $option)
                                        <div class="option {{ isset($option['is_correct']) && $option['is_correct'] ? 'correct-answer' : '' }}">
                                            {{ $option['text'] }}
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="marking-notes" style="margin-top:4px;">
                                <div><strong>Marking Guide ({{ $sub['label'] }}):</strong></div>
                                <div class="marking-points">
                                    @if($sub['type'] === 'true_false' && !empty($sub['options']))
                                        <div>• Correct answer: <strong>{{ $sub['options'][0]['is_correct'] ? 'True' : 'False' }}</strong></div>
                                    @elseif($sub['type'] === 'mcq' && !empty($sub['options']))
                                        @php
                                            $correctOption = collect($sub['options'])->firstWhere('is_correct', true);
                                            $correctAnswer = $correctOption ? $correctOption['text'] : 'No correct answer specified';
                                        @endphp
                                        <div>• Correct answer: <strong>{{ $correctAnswer }}</strong></div>
                                    @endif
                                    <div>• Marks: <strong>{{ $sub['marks'] }} mark{{ $sub['marks'] > 1 ? 's' : '' }}</strong></div>
                                </div>
                            </div>
                        @endforeach
                    @else
                        {{-- Standalone question (no sub-questions) --}}
                        <div class="question-text">
                            <span class="question-number">Q{{ $question['number'] }}.</span>
                            <span>{!! nl2br(e($question['text'])) !!}</span>
                            <span class="marks">{{ $question['marks'] }} mark{{ $question['marks'] > 1 ? 's' : '' }}</span>
                        </div>

                        @if(!empty($question['image']) && file_exists($question['image']))
                            <div class="question-image">
                                <img src="{{ $question['image'] }}" alt="Question Image" style="max-width: 100%; max-height: 200px; display: block; margin: 10px 0; border: 1px solid #ddd;" />
                            </div>
                        @endif

                        @if(!empty($question['options']))
                            <div class="options">
                                @foreach($question['options'] as $optionIndex => $option)
                                    <div class="option {{ isset($option['is_correct']) && $option['is_correct'] ? 'correct-answer' : '' }}">
                                        {{ $option['text'] }}
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="marking-notes">
                            <div><strong>Marking Guide:</strong></div>
                            <div class="marking-points">
                                @if($question['type'] === 'true_false' && !empty($question['options']))
                                    <div>• Correct answer: <strong>{{ $question['options'][0]['is_correct'] ? 'True' : 'False' }}</strong></div>
                                @elseif($question['type'] === 'mcq' && !empty($question['options']))
                                    @php
                                        $correctOption = collect($question['options'])->firstWhere('is_correct', true);
                                        $correctAnswer = $correctOption ? $correctOption['text'] : 'No correct answer specified';
                                    @endphp
                                    <div>• Correct answer: <strong>{{ $correctAnswer }}</strong></div>
                                @endif
                                <div>• Marks: <strong>{{ $question['marks'] }} mark{{ $question['marks'] > 1 ? 's' : '' }}</strong></div>
                            </div>
                        </div>
                    @endif
                </div>

                @if(($index + 1) % 3 === 0 && !$loop->last)
                    <div class="page-break"></div>
                @endif
            @endforeach
        @endif
    </div>

    <!-- Footer -->
    <div class="footer">
        <span style="font-weight:600;">{{ $school['school_name'] ?? 'School' }}</span>
        &nbsp;|&nbsp;
        <span>{{ $title }}</span>
        &nbsp;|&nbsp;
        <span>{{ date('F j, Y') }}</span>
        <span style="float:right; font-size:9pt; color:#e0e0e0; margin-right:20px;">Powered by Lesson Plan App</span>
    </div>
    
    <script type="text/php">
        if (isset($pdf)) {
            $text = "Page {PAGE_NUM} of {PAGE_COUNT}";
            $size = 10;
            $font = $fontMetrics->getFont("DejaVu Sans");
            $width = $fontMetrics->get_text_width($text, $font, $size) / 2;
            $x = ($pdf->get_width() - $width) / 2;
            $y = $pdf->get_height() - 15;
            $pdf->page_text($x, $y, $text, $font, $size);
        }
    </script>
</body>
</html>
