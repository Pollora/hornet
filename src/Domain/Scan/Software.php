<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * What a vulnerability can be in: the WordPress core, a plugin or a theme.
 */
enum Software: string
{
    case Core = 'core';
    case Plugin = 'plugin';
    case Theme = 'theme';
}
