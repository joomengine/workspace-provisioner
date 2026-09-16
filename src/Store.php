<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

interface Store
{
    /** Atomic short transaction. The callback receives mutable state by reference. */
    public function transaction(callable $callback): mixed;

    /** One active executor; do not hold a SQL transaction across remote work. */
    public function exclusive(callable $callback): mixed;
}
