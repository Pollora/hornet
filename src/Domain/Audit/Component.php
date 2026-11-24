<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * Another piece of software that already does part of the job: Hornet detects it and does not do the work twice.
 */
enum Component: string
{
    /** pollora/portcullis: secret login URL, brute-force lockout, account enumeration. */
    case Portcullis = 'portcullis';

    /** pollora/headless: XML-RPC, head clean-up, REST users, file editor. */
    case Headless = 'headless';

    /** The official Two-Factor plugin. */
    case TwoFactor = 'two-factor';
}
