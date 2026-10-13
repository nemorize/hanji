@php
    \Hanji::meta([
        'title' => '제품: ' . \Hanji::params('foo'),
        'tags' => ['products'],
    ]);
@endphp

<h1>{{ \Hanji::meta('title') }}</h1>
<img src="./cover.svg" alt="{{ \Hanji::params('foo') }} 제품 표지">
<p>현재 제품: {{ \Hanji::params('foo') }}</p>
