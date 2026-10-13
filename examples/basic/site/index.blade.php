@php
    \Hanji::meta([
        'title' => '한지',
        'tags' => ['pages'],
        'permalink' => '/',
    ]);
@endphp

<main>
    <h1>{{ \Hanji::meta('title') }}</h1>
    <p>PHP로 만드는 정적 사이트.</p>

    <ul>
        @foreach ($collections['posts'] ?? [] as $post)
            <li><a href="{{ $post->url }}">{{ $post->data['title'] }}</a></li>
        @endforeach
    </ul>
</main>
