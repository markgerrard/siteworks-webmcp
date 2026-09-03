@props([
    'siteId',
    'model' => 'mediaId',
    'kinds' => 'image',
    'aspect' => '16:9',
    'slotLabel' => 'Media',
])

<livewire:media.picker
    :site-id="$siteId"
    :model="$model"
    :kinds="$kinds"
    :aspect="$aspect"
    :slot-label="$slotLabel"
/>
