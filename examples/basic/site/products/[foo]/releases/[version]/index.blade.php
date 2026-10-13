@php
    \Hanji::meta([
        'title' => \Hanji::params('foo') . ' ' . \Hanji::params('version'),
        'tags' => ['releases'],
    ]);
@endphp

<h1>{{ \Hanji::meta('title') }}</h1>
<img src="../../cover.svg" alt="{{ \Hanji::params('foo') }} 제품 표지">
<p>제품 {{ \Hanji::params('foo') }}의 {{ \Hanji::params('version') }} 버전.</p>
