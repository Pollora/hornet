<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * Where a text of the database was read.
 */
enum ContentKind: string
{
    case Post = 'post';
    case Option = 'option';
    /** The name, the description and the addresses of the site: no code has any place there. */
    case SiteIdentity = 'site-identity';
    case Comment = 'comment';
    case Profile = 'profile';
}
