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
            padding-bottom: 10px;
            margin-bottom: 20px;
            position: relative;
        }
        
        .school-logo {
            max-height: 80px;
            margin-bottom: 10px;
        }
        
        .school-info {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .school-name {
            font-size: 18pt;
            font-weight: bold;
            color: #1a5276;
            margin: 5px 0;
            text-transform: uppercase;
        }
        
        .school-details {
            font-size: 9pt;
            color: #555;
            margin-bottom: 5px;
            line-height: 1.3;
        }
        
        .assessment-title {
            font-size: 14pt;
            font-weight: bold;
            text-align: center;
            margin: 15px 0;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .assessment-meta {
            margin: 15px 0;
            font-size: 10pt;
            background-color: #f8f9fa;
            padding: 12px 20px;
            border-radius: 4px;
            border-left: 4px solid #1a5276;
        }
        
        .meta-row {
            display: flex;
            margin-bottom: 6px;
            align-items: center;
        }
        
        .meta-row:last-child {
            margin-bottom: 0;
        }
        
        .meta-label {
            font-weight: 600;
            color: #2c3e50;
            min-width: 70px;
            display: inline-block;
        }
        
        .meta-value {
            color: #1a5276;
            font-weight: 500;
        }
        
        .total-marks {
            text-align: right;
            margin: 20px 0;
            font-size: 12pt;
            color: #1a5276;
            padding: 10px 15px;
            background-color: #f0f7ff;
            border-radius: 4px;
            border-left: 4px solid #1a5276;
        }
        
        .total-marks div {
            margin: 5px 0;
        }
        
        .marking-notes {
            margin-top: 15px;
            padding: 12px;
            background-color: #f9f9f9;
            border-radius: 4px;
            border-left: 3px solid #1a5276;
        }
        
        .marking-points {
            margin-top: 8px;
            margin-left: 10px;
            font-size: 10.5pt;
            line-height: 1.6;
        }
        
        .marking-points div {
            margin: 4px 0;
        }
        
        .question {
            margin-bottom: 25px;
            page-break-inside: avoid;
            border: 1px solid #e0e0e0;
            padding: 15px;
            border-radius: 6px;
            background-color: #fff;
            position: relative;
        }
        
        .question-number {
            font-weight: bold;
            color: #1a5276;
            margin-right: 5px;
        }
        
        .question-text {
            margin-bottom: 12px;
            font-size: 11pt;
            color: #2c3e50;
            line-height: 1.5;
        }
        
        .question-text img {
            max-width: 100%;
            height: auto;
            margin: 5px 0;
        }
        
        .options {
            margin: 10px 0 0 20px;
        }
        
        .option {
            margin-bottom: 8px;
            padding: 5px 0 5px 30px;
            position: relative;
            page-break-inside: avoid;
        }
        
        .option:before {
            content: "";
            position: absolute;
            left: 0;
            top: 8px;
            width: 16px;
            height: 16px;
            border: 2px solid #999;
            border-radius: 3px;
            background-color: #fff;
        }
        
        .option.correct-answer:before {
            background-color: #28a745;
            border-color: #28a745;
            content: "✓";
            color: white;
            text-align: center;
            line-height: 16px;
            font-size: 12px;
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
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9pt;
            margin-left: 10px;
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
            font-size: 9pt;
            color: #666;
            padding: 8px 0;
            background-color: #f8f9fa;
            border-top: 1px solid #e0e0e0;
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
                <div>Pass Mark: <strong>{{ ceil($total_marks * 0.5) }}</strong> (50%)</div>
            </div>
        @endif
        
        <div class="clearfix"></div>
    </div>

    <!-- Questions Section -->
    <div class="questions-container">
        @foreach($questions as $index => $question)
            <div class="question">
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
                        @if($question['type'] === 'true_false')
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
            </div>
            
            @if(($index + 1) % 3 === 0 && !$loop->last)
                <div class="page-break"></div>
            @endif
        @endforeach
    </div>

    <!-- Footer -->
    <div class="footer">
        {{ $school['school_name'] ?? 'School' }} | {{ $title }} | {{ date('F j, Y') }}
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
