@include('site.sections._related_strip', [
    'kind' => \App\Enums\PageKind::Guide->value,
    'stripAttribute' => 'data-related-guides',
    'defaultEyebrow' => 'Guides',
    'defaultTitle' => 'Related guides',
])
