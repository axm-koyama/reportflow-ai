@props(['message', 'actionLabel' => null, 'actionHref' => null])

<div {{ $attributes->class(['card', 'empty-state']) }}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M4 7h16M6 4h12l2 16H4L6 4Z" /></svg>
    <p>{{ $message }}</p>
    @if ($actionLabel && $actionHref)
        <a href="{{ $actionHref }}" class="btn-link">{{ $actionLabel }}</a>
    @endif
</div>
