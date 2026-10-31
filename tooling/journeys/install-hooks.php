<?php

/*
 * Points git at the versioned hooks in .githooks/. Runs from `composer install`
 * and must never fail it: production installs (--no-dev), CI, non-git
 * checkouts and repositories that already use another hooks path are skipped.
 */

$root = dirname(__DIR__, 2);

$skip = function (string $why): never {
    fwrite(STDOUT, "journeys: git hooks not installed ({$why}).\n");
    exit(0);
};

if (getenv('CI') || getenv('JOURNEYS_NO_HOOKS')) {
    $skip('CI or JOURNEYS_NO_HOOKS');
}
if (! is_dir($root.'/vendor/pestphp')) {
    $skip('dev dependencies not installed');
}
if (! is_dir($root.'/.githooks')) {
    $skip('.githooks directory missing');
}

exec('git -C '.escapeshellarg($root).' rev-parse --is-inside-work-tree 2>/dev/null', $out, $code);
if ($code !== 0) {
    $skip('not a git work tree');
}

$current = trim((string) shell_exec('git -C '.escapeshellarg($root).' config --get core.hooksPath'));
if ($current === '.githooks') {
    exit(0);
}
if ($current !== '') {
    $skip("core.hooksPath is already '{$current}'; add .githooks/* to it manually");
}

exec('git -C '.escapeshellarg($root).' config core.hooksPath .githooks', $out, $code);
fwrite(STDOUT, $code === 0
    ? "journeys: installed git hooks (core.hooksPath=.githooks). Bypass once with --no-verify.\n"
    : "journeys: could not set core.hooksPath.\n");
exit(0);
