@props(['status'])

<flux:badge :color="$status->badgeColor()" size="sm">{{ $status->label() }}</flux:badge>
