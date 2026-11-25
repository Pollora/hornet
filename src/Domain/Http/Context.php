<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Http;

/**
 * Where a response is going: the public site, the dashboard, or the login screen. The three have different needs
 * (the dashboard cannot run under a strict Content-Security-Policy), and WordPress fires different hooks for each.
 */
enum Context: string
{
    case Front = 'front';
    case Admin = 'admin';
    case Login = 'login';
}
