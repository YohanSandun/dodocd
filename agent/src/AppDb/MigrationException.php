<?php

declare(strict_types=1);

namespace Dodo\AppDb;

use RuntimeException;

/** A migration problem, with the HTTP status a deploy should fail with. */
final class MigrationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        /** Statements of the failing file that had already run, so the database may be partly changed. */
        public readonly int $statementsRun = 0,
    ) {
        parent::__construct($message);
    }
}
