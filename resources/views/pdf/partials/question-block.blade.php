<div class="question">

    <!-- Parent or standalone question -->
    <div class="question-text">
        <span class="question-number">{{ $q['number'] }}.</span>

        @if(!empty($q['is_math']))
            <span class="mathjax">{!! $q['text'] !!}</span>
        @else
            {!! $q['text'] !!}
        @endif

        @if(isset($q['marks']))
            <span class="marks">[{{ $q['marks'] }}]</span>
        @endif
    </div>

    @if(!empty($q['image']))
        <img src="{{ $q['image'] }}" style="max-width:350px; max-height:180px;">
    @endif

    <!-- Matching -->
    @if($q['type'] === 'matching' && !empty($q['options']))
        @include('pdf.partials.matching-table', ['options' => $q['options']])
    @endif

    <!-- MCQ Options -->
    @if(!empty($q['options']) && $q['type'] !== 'matching')
        <div class="options">
            @foreach($q['options'] as $i => $opt)
                <div class="option">
                    <div class="option-label">{{ chr(65 + $i) }}.</div>
                    <div class="option-text">
                        @if(!empty($opt['image']))
                            <img src="{{ $opt['image'] }}" style="max-height:50px;">
                        @else
                            {!! !empty($opt['is_math']) ? '<span class="mathjax">'.$opt['text'].'</span>' : $opt['text'] !!}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Sub Questions -->
    @if(!empty($q['sub_questions']))
        @foreach($q['sub_questions'] as $sub)
            <div style="margin-left:20px; margin-top:8px;">
                <strong>({{ $sub['label'] }})</strong>

                @if($sub['is_math'])
                    <span class="mathjax">{!! $sub['text'] !!}</span>
                @else
                    {!! $sub['text'] !!}
                @endif

                <span class="marks">[{{ $sub['marks'] }}]</span>

                @if($sub['type'] === 'matching')
                    @include('pdf.partials.matching-table', ['options' => $sub['options']])
                @endif
            </div>
        @endforeach
    @endif

</div>
