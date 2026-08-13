@props(['director' => '', 'regNo' => '', 'vatNo' => '', 'regAddress' => ''])

<div class="page-footer">
    @if($director)
        <div class="director">Director: {{ $director }}</div>
    @endif
    @if($regNo || $vatNo)
        <div>
            @if($regNo)Registered in England: {{ $regNo }}@endif
            @if($regNo && $vatNo), @endif
            @if($vatNo)VAT No. {{ $vatNo }}@endif
        </div>
    @endif
    @if($regAddress)
        <div>{{ $regAddress }}</div>
    @endif
</div>
