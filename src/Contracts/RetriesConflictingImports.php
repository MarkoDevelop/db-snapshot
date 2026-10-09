<?php

namespace Overthink\DbSnapshot\Contracts;

/**
 * Optional for drivers: recognize imports that failed only because they ran
 * in parallel with others (e.g. a deadlock while creating foreign keys), so
 * the restore retries them one at a time instead of failing.
 */
interface RetriesConflictingImports
{
    public function isLockConflict(string $errorOutput): bool;
}
