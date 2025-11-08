<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8"/>
    <title>{{ $title }} - {{ $school['school_name'] ?? 'School Name' }}</title>
    <style>
        @page { margin: 25.4mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.4; color: #333; }
        .header { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; text-align:center; }
        .school-name { font-size: 18px; font-weight: bold; margin: 5px 0; }
        .school-details { font-size: 11px; color: #555; margin-bottom: 5px; }
        .assessment-title { font-size: 16px; font-weight: bold; text-align: center; margin: 15px 0; text-transform: uppercase; }
        .assessment-meta { display:flex; justify-content:space-between; margin-bottom:15px; font-size:11px; }
        .question { margin-bottom:20px; page-break-inside: avoid; }
        .question-text { margin-bottom: 10px; font-weight:bold; }
        .question-number { font-weight:bold; margin-right:5px; }
        .options { margin-left:20px; margin-bottom:10px; }
        /* Use table layout for better PDF engine support */
        .option { margin-bottom:5px; display:table; width:100%; }
        .option-label { display:table-cell; width:20px; padding-right:8px; vertical-align:top; }
        .option-text { display:table-cell; vertical-align:top; }
        .answer-space { border-bottom:1px solid #000; min-width:200px; display:inline-block; margin-left:10px; height:15px; }
        .marks { float:right; font-weight:bold; }
        .footer { margin-top:30px; font-size:10px; color:#666; text-align:center; border-top:1px solid #eee; padding-top:10px; }
        .student-info { margin:15px 0; padding:10px; border:1px solid #ddd; background-color:#f9f9f9; }
        .student-info-row { margin-bottom:5px; display:flex; }
        .student-info-label { font-weight:bold; min-width:100px; }
        .total-marks { text-align:right; font-weight:bold; margin-top:10px; font-size:14px; }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="header">
        @if(!empty($school['logo']) && file_exists($school['logo']))
            <div><img src="{{ $school['logo'] }}" alt="Logo" style="max-height:80px;"></div>
        @endif
        <div class="school-name">{{ $school['school_name'] ?? 'School Name' }}</div>
        <!-- <div class="school-details">
            {{ $school['address'] ?? 'School Address' }} |
            Tel: {{ $school['phone'] ?? 'Phone Number' }} |
            Email: {{ $school['email'] ?? 'school@example.com' }}
        </div> -->
        <div class="assessment-title">{{ $title }}</div>
        <div class="assessment-meta">
            <div>Subject: {{ $subject ?? 'General' }}</div>
            <div>Date: {{ $created_at ?? date('F j, Y') }}</div>
        </div>
    </div>

    <!-- Student info -->
    <div class="student-info">
        <div class="student-info-row">
            <div class="student-info-label">Student Name:<div class="answer-space"></div></div>
        </div>
        <div class="student-info-row">
            <div class="student-info-label">Class/Grade:<div class="answer-space" style="width:200px;"></div></div>
        </div>
        <div class="student-info-row">
            <div class="student-info-label">Date:<div class="answer-space" style="width:200px;"></div></div>
        </div>
    </div>

    <!-- Instructions -->
    <div style="margin:15px 0; padding:10px; background-color:#f0f0f0; border-left:4px solid #333;">
        <strong>Instructions:</strong>
        <ul style="margin:5px 0 0 20px; padding:0;">
            <li>Write your name and class/grade in the spaces provided above.</li>
            <li>Answer all questions in the spaces provided.</li>
            <li>For multiple choice questions, circle the correct answer.</li>
            <li>Show all working where necessary.</li>
            <li>Total marks: {{ $total_marks ?? 'N/A' }}</li>
        </ul>
    </div>

    <!-- Questions -->
    @foreach($questions as $q)
        <div class="question">
            <div class="question-text">
                <span class="question-number">{{ $q['number'] }}.</span>
                @if(!empty($q['image']))
                    <div><img src="{{ $q['image'] }}" style="max-width:350px; max-height:180px;"></div>
                @endif
                {!! $q['text'] !!}
                <span class="marks">[{{ $q['marks'] }} mark{{ $q['marks'] > 1 ? 's' : '' }}]</span>
            </div>

            @if(!empty($q['options']))
                <div class="options">
                    @foreach($q['options'] as $i => $opt)
                        <div class="option">
                            <div class="option-label">{{ chr(65 + $i) }}.</div>
                                <div class="option-text">
                                @if(!empty($opt['image']))
                                    <img src="{{ $opt['image'] }}" style="max-height:50px;">
                                @else
                                    {!! $opt['text'] !!}
                                @endif
                                </div>
                        </div>
                    @endforeach
                </div>
            @elseif($q['type'] === 'short_answer')
                <div style="margin-top:10px;">
                    <div style="font-size:11px; color:#555; margin-bottom:3px;">Working space & answer:</div>
                    @for($i=0;$i<5;$i++)
                        <div class="answer-space" style="width:100%; min-height:20px; margin-bottom:6px;"></div>
                    @endfor
                </div>
            @else
                <div style="margin-top:10px; min-height:50px;">
                    <div class="answer-space" style="width:100%; min-height:50px;"></div>
                </div>
            @endif
        </div>
    @endforeach

    <div class="total-marks">Total: {{ $total_marks ?? 'N/A' }} marks</div>

    <div class="footer">
        {{ $school['school_name'] ?? 'School Name' }} | {{ $title }} | Page <span class="page-number">{{ $page ?? 1 }}</span>
    </div>

    <script>
        // Page numbers
        document.addEventListener('DOMContentLoaded', function(){
            const pages = document.getElementsByClassName('page-number');
            for(let i=0;i<pages.length;i++){
                pages[i].textContent = i+1;
            }
        });
    </script>

</body>
</html>
