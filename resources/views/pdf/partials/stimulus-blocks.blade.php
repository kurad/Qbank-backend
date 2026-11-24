{{-- Shared context for a parent question, or additional content for a child. --}}
@foreach(($stimulus ?? []) as $block)
    @if(($block['type'] ?? '') === 'code')
        <div class="stimulus-code-block">
            @if(!empty($block['title']))<div class="stimulus-caption">{{ $block['title'] }}</div>@endif
            <div class="stimulus-language">{{ strtoupper($block['language'] ?? 'CODE') }}</div>
            <pre class="stimulus-code">{{ $block['content'] ?? '' }}</pre>
        </div>
    @elseif(($block['type'] ?? '') === 'table')
        <div class="stimulus-table-wrap">
            @if(!empty($block['title']))<div class="stimulus-caption">{{ $block['title'] }}</div>@endif
            @php
                $columns = $block['columns'] ?? [];
                $rows = $block['rows'] ?? [];
            @endphp
            @if(is_array($columns) && count($columns))
                <table class="stimulus-table">
                    <thead><tr>
                        @foreach($columns as $column)
                            <th>{{ is_array($column) ? ($column['label'] ?? $column['key'] ?? '') : $column }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                @foreach($columns as $index => $column)
                                    @php $key = is_array($column) ? ($column['key'] ?? $index) : $column; @endphp
                                    <td>{{ is_array($row) ? ($row[$key] ?? $row[$index] ?? '') : '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @elseif(($block['type'] ?? '') === 'image' && !empty($block['image_base64']))
        <div class="stimulus-image">
            @if(!empty($block['title']))<div class="stimulus-caption">{{ $block['title'] }}</div>@endif
            <img src="{{ $block['image_base64'] }}" alt="Shared question illustration" />
        </div>
    @endif
@endforeach
