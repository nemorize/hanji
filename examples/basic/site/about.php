<?php

\Hanji::meta(['title' => 'PHP로 작성한 페이지']);
?>
<main>
    <h1><?= htmlspecialchars(\Hanji::meta('title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <p>한지는 일반 PHP 파일의 출력 버퍼를 HTML로 사용한다.</p>
    <img src="./posts/cover.svg" alt="한지 표지">
</main>
