<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Journal;

/**
 * Where a request came from: the most useful thing a journal can say about a change. A role that is changed from the dashboard
 * by a person, from WP-CLI by an operator, or over the REST API by a script, is not the same story.
 *
 * @internal
 */
enum Source: string
{
    /** A visitor's request (wp-login.php, the front). */
    case Web = 'web';

    /** A page of the dashboard. */
    case Admin = 'admin';

    /** The REST API (an application password, a plugin, the block editor). */
    case Rest = 'rest';

    /** admin-ajax.php. */
    case Ajax = 'ajax';

    case XmlRpc = 'xmlrpc';

    /** WP-CLI. */
    case Cli = 'cli';

    /** WP-Cron. */
    case Cron = 'cron';
}
