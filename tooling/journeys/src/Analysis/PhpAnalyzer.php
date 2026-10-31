<?php

namespace Tooling\Journeys\Analysis;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class PhpAnalyzer
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    /**
     * @param  array{prefix:string, name:string}|null  $routeFile  framework-level group attributes when this is a route file
     * @return array<string, mixed>
     */
    public function analyze(string $path, string $code, ?array $routeFile): array
    {
        try {
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error $e) {
            return ['kind' => 'php', 'error' => 'parse error: '.$e->getMessage()];
        }

        $resolver = new NodeTraverser(new NameResolver(null, ['replaceNodes' => true]));
        $stmts = $resolver->traverse($stmts);

        $visitor = new PhpReferenceVisitor;
        (new NodeTraverser($visitor))->traverse($stmts);

        $facts = ['kind' => 'php', 'error' => null] + $visitor->facts;

        if ($routeFile !== null) {
            $facts += (new RouteExtractor($path, $routeFile['prefix'], $routeFile['name']))->extract($stmts);
        }

        return $facts;
    }
}
