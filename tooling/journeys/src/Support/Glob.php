<?php

namespace Tooling\Journeys\Support;

/**
 * Minimal gitignore-style globbing: `**` spans directories, `*` and `?` do not.
 * A pattern ending in `/` matches everything beneath that directory.
 */
final class Glob
{
    /** @var array<string, string> */
    private static array $compiled = [];

    public static function matches(string $pattern, string $path): bool
    {
        return (bool) preg_match(self::regex($pattern), $path);
    }

    /**
     * @param  iterable<string>  $patterns
     */
    public static function matchesAny(iterable $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public static function isPattern(string $value): bool
    {
        return strpbrk($value, '*?') !== false || str_ends_with($value, '/');
    }

    private static function regex(string $pattern): string
    {
        if (isset(self::$compiled[$pattern])) {
            return self::$compiled[$pattern];
        }

        $glob = str_ends_with($pattern, '/') ? $pattern.'**' : $pattern;
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];
            if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
                $i++;
                if (($glob[$i + 1] ?? '') === '/') {
                    $i++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return self::$compiled[$pattern] = '#^'.$regex.'$#';
    }
}
