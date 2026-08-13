@props(['name', 'tagline' => '', 'addressLines' => [], 'kvRows' => []])

<tr class="headline">
    <td>
        <table class="split"><tr>
            <td class="left">
                <div class="company-name">{{ $name }}</div>
                @if($tagline)
                    <div class="company-tagline">{{ $tagline }}</div>
                @endif
            </td>
            <td class="right header-right">
                @foreach($addressLines as $addressLine)
                    {!! $addressLine !== '' ? e($addressLine) : '&nbsp;' !!}@if(! $loop->last)<br>@endif
                @endforeach
                <table class="kv" style="margin-top: 2px">
                    @foreach($kvRows as [$label, $value])
                        <tr>
                            <td class="k">{{ $value ? $label : '' }}</td>
                            <td class="c">{{ $value ? ':' : '' }}</td>
                            <td>{!! $value ? e($value) : '&nbsp;' !!}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr></table>
    </td>
</tr>
