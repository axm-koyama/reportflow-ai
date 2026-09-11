@props(['variant', 'label'])

<span {{ $attributes->class(['badge', 'badge-'.$variant]) }}>{{ $label }}</span>
