<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** A deliberately sanitized error suitable for structured operator output. */
final class Fault extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
