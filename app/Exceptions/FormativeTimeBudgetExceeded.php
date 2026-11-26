<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown between objectives when a preparation run has used its time budget.
 *
 * Nothing is lost: questions generated so far are already stored and are
 * reused by the next run, so the job simply releases itself and carries on.
 */
class FormativeTimeBudgetExceeded extends RuntimeException
{
}
