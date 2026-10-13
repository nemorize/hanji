@php
    \Hanji::extension('xml');
@endphp
{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach ($collections['all'] ?? [] as $entry)
        @if ($entry->extension === 'html')
            <url>
                <loc>{{ rtrim($site['baseUrl'] ?? 'https://example.com', '/') . $entry->url }}</loc>
            </url>
        @endif
    @endforeach
</urlset>
