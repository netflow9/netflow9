<?php

declare(strict_types=1);

namespace sys\J;

/** Step / time limit hit. Deliberately NOT catchable by script try/catch. */
final class LimitExceeded extends \RuntimeException
{
}
