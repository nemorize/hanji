<?php

declare(strict_types=1);

return match (\Hanji::params('foo')) {
    'hanji' => ['0.1', '0.2'],
    'washi' => ['1.0'],
    default => [],
};
