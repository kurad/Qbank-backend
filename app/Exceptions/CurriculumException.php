<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An error whose message is safe and useful to show to a teacher.
 *
 * Anything that is NOT a CurriculumException (SQL errors, provider errors,
 * programming errors) must never be echoed to the browser; controllers
 * replace those with a generic message and report the real exception.
 */
class CurriculumException extends RuntimeException
{
    /** Extra machine-readable data returned to the client (e.g. conflicts). */
    protected array $context = [];

    public function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function context(): array
    {
        return $this->context;
    }
}
