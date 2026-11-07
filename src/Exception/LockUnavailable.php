<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Exception;

/**
 * Thrown when a caller asked to run alone, and that could not be guaranteed.
 *
 * Everything else in this package carries on without the lock: a cache that
 * cannot take a lock computes the value anyway, because computing it twice
 * is far better than a failed request. {@see MemoLock::exclusively()} cannot
 * do that. A caller who asked that two workers never run something at the
 * same time would rather hear that it did not run than have it run twice
 * without being told. A KeepAlive throws it too, when the lock it should
 * extend has expired or cannot be reached.
 */
final class LockUnavailable extends \RuntimeException
{
}
