<?php

declare(strict_types=1);

namespace Dodo\Update;

use RuntimeException;

/** An update package that can't be installed, with a message safe to show in the dashboard. */
final class UpdateException extends RuntimeException
{
}
