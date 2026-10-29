<?php

declare(strict_types=1);

namespace Dodo\Deploy;

use RuntimeException;
use Throwable;

/** A deploy failure with the HTTP status the API should answer with. */
final class DeployException extends RuntimeException
{
    /** Summary of the recorded deployment, when the failure happened after it was created. */
    public ?array $deployment = null;

    public function __construct(string $message, public readonly int $status = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
