@props(['items'])

<nav aria-label="Breadcrumb">
    <ol class="breadcrumb">
        @foreach ($items as $item)
            <li>
                @if (! empty($item['href']))<a href="{{ $item['href'] }}">{{ $item['label'] }}</a>@else<span @if ($loop->last) aria-current="page" @endif>{{ $item['label'] }}</span>@endif
            </li>
        @endforeach
    </ol>
</nav>
