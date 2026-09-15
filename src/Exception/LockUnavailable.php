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
 * Exclusivity was asked for and could not be given.
 *
 * Everything else in this package degrades quietly: a cache that cannot take a
 * lock computes anyway, because a duplicated computation is worse than a
 * missing one but far better than a failed request. {@see MemoLock::exclusively()}
 * cannot do that. A caller who asked that two workers never run something at
 * the same time would rather hear that it did not happen than be told nothing
 * while it happens twice.
 */
final class LockUnavailable extends \RuntimeException
{
}
