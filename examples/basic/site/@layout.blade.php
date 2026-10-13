<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \Hanji::meta('title') ?? $site['title'] ?? 'Hanji' }}</title>
    <link rel="stylesheet" href="./assets/css/app.css">
</head>
<body>
    @include('partials.header')
    {{ \Hanji::children() }}
</body>
</html>
