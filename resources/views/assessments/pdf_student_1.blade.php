<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>{{ $title }} - {{ $school['school_name'] }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
    .question { margin-bottom: 20px; page-break-inside: avoid; }
    .question-number { font-weight: bold; margin-right: 5px; }
    .marks { float: right; font-weight: bold; }
    .options { margin-left: 20px; margin-top: 5px; }
    .option { margin-bottom: 5px; }
</style>
</head>
<body>

<h2 style="text-align:center">{{ $title }}</h2>
<p>Subject: {{ $subject }}</p>
<p>Date: {{ $created_at }}</p>

@foreach($questions as $question)
    <div class="question">
        <div class="question-text">
            <span class="question-number">{{ $question['number'] }}.</span>
            @if(file_exists($question['text']))
                <img src="{{ $question['text'] }}" style="max-height: 40px;">
            @else
                {!! $question['text'] !!}
            @endif
            <span class="marks">[{{ $question['marks'] }} mark{{ $question['marks']>1?'s':'' }}]</span>
        </div>

        @if(!empty($question['options']))
            <div class="options">
                @foreach($question['options'] as $index => $option)
                    <div class="option">
                        {{ chr(65 + $index) }}.
                        @if(!empty($option['image']) && file_exists($option['image']))
                            <img src="{{ $option['image'] }}" style="max-height: 25px;">
                        @else
                            {!! $option['text'] !!}
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endforeach

<p>Total Marks: {{ $total_marks }}</p>

</body>
</html>
