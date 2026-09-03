@include('site.sections._related_strip', [
    'kind' => \App\Enums\PageKind::Service->value,
    'stripAttribute' => 'data-related-services',
    'defaultEyebrow' => 'Services',
    'defaultTitle' => 'Related services',
])
