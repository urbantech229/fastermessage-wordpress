<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Exception;

/** 429, voir retryAfter. */
class RateLimitException extends ApiException
{
}
