<?php

use App\Support\ExceptionContext;
use Illuminate\Support\Facades\Log;

/**
 * Every Log call (Log::x(...), \Log::x(...), logger()->x(...)) in app/ with its
 * full argument text, as [relative path, line, call text].
 *
 * @return list<array{0: string, 1: int, 2: string}>
 */
function appLogCalls(): array
{
    $root = dirname(__DIR__, 2);
    $calls = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (! preg_match_all('/(?:\\\\?Log::|logger\(\)->)\w+\(/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches[0] as [$opening, $offset]) {
            $end = $offset + strlen($opening);

            for ($depth = 1; $depth > 0 && $end < strlen($source); $end++) {
                $depth += match ($source[$end]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
            }

            $calls[] = [
                substr($file->getPathname(), strlen($root) + 1),
                substr_count(substr($source, 0, $offset), "\n") + 1,
                substr($source, $offset, $end - $offset),
            ];
        }
    }

    return $calls;
}

test('caught exceptions are logged with their throwable or an argument-free trace, not the message alone', function () {
    $offenders = collect(appLogCalls())
        ->filter(fn (array $call) => str_contains($call[2], 'getMessage()')
            && ! str_contains($call[2], "'exception' =>")
            && ! str_contains($call[2], 'ExceptionContext::withoutArguments('))
        ->map(fn (array $call) => $call[0].':'.$call[1])
        ->values()
        ->all();

    expect($offenders)->toBe([], "Log the throwable ('exception' => \$e), or ...ExceptionContext::withoutArguments(\$e) on Plaid/Google/Airtable paths");
});

test('provider and secret-bearing paths never hand the raw throwable to the logger', function () {
    // Stack frames on these paths carry Plaid access tokens, Google OAuth codes and
    // tokens, Airtable keys, or invitation / magic-link URLs as string arguments,
    // which a logged throwable's formatted trace would print.
    $providerPaths = [
        'app/Http/Controllers/AccountController.php',
        'app/Http/Controllers/BudgetController.php',
        'app/Http/Controllers/CalendarConnectionController.php',
        'app/Http/Controllers/MagicLinkController.php',
        'app/Http/Controllers/PlaidController.php',
        'app/Http/Controllers/PlaidLiabilitiesController.php',
        'app/Services/BankFeed/AirtableImportStrategy.php',
        'app/Services/BankFeed/PlaidImportStrategy.php',
        'app/Services/BankFeedService.php',
        'app/Services/BudgetSharingService.php',
        'app/Services/GoogleCalendarService.php',
        'app/Services/PlaidService.php',
    ];

    $offenders = collect(appLogCalls())
        ->filter(fn (array $call) => in_array($call[0], $providerPaths, true)
            && preg_match("/'exception'\s*=>\s*\\$/", $call[2]))
        ->map(fn (array $call) => $call[0].':'.$call[1])
        ->values()
        ->all();

    expect($offenders)->toBe([], 'Use ...ExceptionContext::withoutArguments($e) on provider paths');
});

test('the argument-free context keeps the origin and trace but drops frame arguments', function () {
    $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    $secret = 'access-sandbox-'.str_repeat('s3cr3t', 4);
    $callPlaid = function (string $accessToken): never {
        throw new RuntimeException('Plaid request failed');
    };

    try {
        $callPlaid($secret);
    } catch (RuntimeException $e) {
        Log::spy();
        Log::error('Plaid call failed', ExceptionContext::withoutArguments($e));
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
    }

    // The raw throwable would carry the token as a frame argument.
    expect(json_encode(array_column($e->getTrace(), 'args')))->toContain($secret);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context) => $context['exception'] === RuntimeException::class
            && $context['error'] === 'Plaid request failed'
            && $context['at'] === __FILE__.':'.$e->getLine()
            && $context['trace'] !== []
            && ! str_contains(json_encode($context), $secret))
        ->once();
});
