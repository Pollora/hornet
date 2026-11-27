<?php

declare(strict_types=1);

/*
 * The SHA-1 of the password the breach tests use, cut as the service is asked. Here and not in a test file: more than one
 * file reads it, and a parallel run does not load them all in one process.
 */

define('PASSWORD_PREFIX', substr(strtoupper(sha1('password')), 0, 5));
define('PASSWORD_SUFFIX', substr(strtoupper(sha1('password')), 5));
