@props(['workflow'])
<span {{ $attributes->class(['badge', 'spt-process-badge', 'text-bg-'.$workflow->badgeTone()]) }}>{{ $workflow->label() }}</span>
