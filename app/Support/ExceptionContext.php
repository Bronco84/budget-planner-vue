<?php

namespace App\Support;

use Throwable;

/**
 * Log context for a caught exception on a path whose stack frames can carry
 * provider secrets (Plaid access tokens, Google OAuth codes and tokens,
 * Airtable keys) as call arguments.
 *
 * The throwable itself is not handed to the logger because its formatted
 * trace includes those arguments when zend.exception_ignore_args is off.
 * Instead this keeps the class, message, origin and a file:line trace, which
 * is enough to locate the failure without any argument values.
 *
 * Elsewhere, pass the throwable as `'exception' => $e` so the full trace is kept.
 */
final class ExceptionContext
{
    /**
     * @return array{exception: class-string<Throwable>, error: string, at: string, trace: list<string>}
     */
    public static function withoutArguments(Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'error' => $e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
            'trace' => array_map(
                fn (array $frame) => ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? 0),
                $e->getTrace()
            ),
        ];
    }
}
