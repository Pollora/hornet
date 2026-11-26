<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Notification;

/**
 * What happened on one channel during one run.
 *
 * @internal
 */
enum ChannelStatus: string
{
    /** The message went out. */
    case Sent = 'sent';

    /** Nothing new for this channel: below its severity, or already told within the cooldown. */
    case Nothing = 'nothing';

    /** The channel got its share for the hour; the alerts are not lost, the next run tells them. */
    case RateLimited = 'rate-limited';

    /** The service failed for now: the message is queued for another attempt. */
    case Queued = 'queued';

    /** The message could not be sent and will not be tried again on its own. */
    case Failed = 'failed';
}
