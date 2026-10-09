<?php
declare(strict_types=1);

namespace Gallerix;

/** A config blob was modified by another request between our read and write (ETag mismatch). */
class ConfigConflictException extends \RuntimeException
{
}
