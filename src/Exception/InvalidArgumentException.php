<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Exception;

use Symfony\Component\Cache\Exception\InvalidArgumentException as SymfonyInvalidArgumentException;

/**
 * Extends Symfony Cache's InvalidArgumentException, so code that already
 * catches Symfony's cache exceptions catches this one too.
 */
class InvalidArgumentException extends SymfonyInvalidArgumentException
{
}
