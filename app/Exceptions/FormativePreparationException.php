<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A formative-assessment preparation problem that retrying cannot fix
 * (no approved material, nothing assessable, storage not migrated ...).
 *
 * The message is written for a student and is safe to show them. The queue
 * job fails immediately on this exception instead of burning its retries.
 */
class FormativePreparationException extends RuntimeException
{
}
