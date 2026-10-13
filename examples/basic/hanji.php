<?php

declare(strict_types=1);

use Hanji\Config;

return (new Config())
    ->input('site')
    ->output('_site')
    ->copy('assets/images', 'static/images');
