<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

echo bin2hex(random_bytes(32));