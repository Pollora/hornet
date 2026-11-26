<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use RuntimeException;

/**
 * The network did not answer. The message says why in general terms and never names the address: it often holds a token.
 *
 * @internal
 */
final class TransportException extends RuntimeException {}
