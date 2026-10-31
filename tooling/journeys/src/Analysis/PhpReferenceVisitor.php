<?php

namespace Tooling\Journeys\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects structural facts from a name-resolved PHP AST. Facts are bucketed
 * per class method (so controller actions can be analysed individually) and
 * per Pest/PHPUnit test (so test references can be validated by name).
 */
final class PhpReferenceVisitor extends NodeVisitorAbstract
{
    private const ASSERTION = '/^(assert|expect|to[A-Z]|throws|fail)/';

    private const HTTP_VERBS = ['get', 'post', 'put', 'patch', 'delete', 'getjson', 'postjson', 'putjson', 'patchjson', 'deletejson', 'json', 'call'];

    /** @var array<string, mixed> */
    public array $facts = [
        'classes' => [],
        'class_refs' => [],
        'refs' => [],
        'methods' => [],
        'renders' => [],
        'unresolved_renders' => [],
        'views' => [],
        'route_refs' => [],
        'table' => null,
        'migration_tables' => [],
        'tests' => [],
        'uri_refs' => [],
    ];

    /** @var array<int, true> */
    private array $skipNames = [];

    private ?string $class = null;

    private ?string $method = null;

    /** @var list<string> */
    private array $describe = [];

    private ?int $test = null;

    /** @var array<int, string> spl id => role */
    private array $scopeNodes = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Expr\FuncCall || $node instanceof Expr\ConstFetch) {
            if ($node->name instanceof Name) {
                $this->skipNames[spl_object_id($node->name)] = true;
            }
        }

        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->class = $node->namespacedName?->toString() ?? $node->name->toString();
            $this->facts['classes'][] = $this->class;
            $this->scopeNodes[spl_object_id($node)] = 'class';
        }

        if ($node instanceof Stmt\ClassMethod && $this->class !== null) {
            $this->method = $node->name->toString();
            $this->facts['methods'][$this->method] ??= self::emptyBucket();
            $this->scopeNodes[spl_object_id($node)] = 'method';

            if ($this->isPhpUnitTest($node)) {
                $this->beginTest($node, $this->method);
            }
        }

        if ($node instanceof Stmt\Property) {
            foreach ($node->props as $prop) {
                if ($prop->name->toString() === 'table' && $prop->default instanceof String_) {
                    $this->facts['table'] = $prop->default->value;
                }
            }
        }

        if ($node instanceof Name\FullyQualified && ! isset($this->skipNames[spl_object_id($node)])) {
            $this->addRef($node->toString());
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->onFunctionCall($node, strtolower($node->name->getLast()));
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $this->onMethodCall($node, $node->name->toString());
        }

        if ($node instanceof Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $this->onStaticCall($node, $node->name->toString());
        }

        if ($node instanceof Expr\New_) {
            foreach ($node->args as $arg) {
                if ($arg instanceof Node\Arg && $arg->name !== null && in_array($arg->name->toString(), ['view', 'markdown', 'text', 'html'], true) && $arg->value instanceof String_) {
                    $this->add('views', $arg->value->value);
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        $role = $this->scopeNodes[spl_object_id($node)] ?? null;

        if ($role === 'class') {
            $this->class = null;
        } elseif ($role === 'method') {
            $this->method = null;
            $this->test = null;
        } elseif ($role === 'test') {
            $this->test = null;
        } elseif ($role === 'describe') {
            array_pop($this->describe);
        }

        return null;
    }

    public function afterTraverse(array $nodes)
    {
        foreach (['classes', 'class_refs', 'refs', 'renders', 'views', 'route_refs', 'migration_tables', 'uri_refs'] as $key) {
            $this->facts[$key] = self::unique($this->facts[$key]);
        }
        foreach ($this->facts['methods'] as $name => $bucket) {
            foreach ($bucket as $key => $values) {
                $this->facts['methods'][$name][$key] = self::unique($values);
            }
        }

        return null;
    }

    /**
     * @return array{refs: list<string>, calls: list<string>, renders: list<string>, views: list<string>, route_refs: list<string>}
     */
    public static function emptyBucket(): array
    {
        return ['refs' => [], 'calls' => [], 'renders' => [], 'views' => [], 'route_refs' => []];
    }

    private function onFunctionCall(Expr\FuncCall $node, string $name): void
    {
        $first = $this->firstString($node);

        if ($this->class === null && $this->method === null && in_array($name, ['test', 'it'], true) && $first !== null) {
            $this->beginTest($node, ($name === 'it' ? 'it ' : '').$first);
        } elseif ($this->class === null && $name === 'describe' && $first !== null) {
            $this->describe[] = $first;
            $this->scopeNodes[spl_object_id($node)] = 'describe';
        }

        if ($name === 'inertia') {
            $first !== null ? $this->add('renders', $first) : (isset($node->args[0]) && $this->facts['unresolved_renders'][] = $node->getStartLine());
        } elseif (in_array($name, ['route', 'to_route'], true) && $first !== null) {
            $this->add('route_refs', $first);
        } elseif ($name === 'view' && $first !== null) {
            $this->add('views', $first);
        }

        $this->markAssertion($name);
    }

    private function onMethodCall(Expr\MethodCall $node, string $name): void
    {
        $first = $this->firstString($node);

        if ($node->var instanceof Expr\Variable && $node->var->name === 'this' && $this->method !== null) {
            $this->facts['methods'][$this->method]['calls'][] = $name;
        }

        if ($name === 'route' && $first !== null) {
            $this->add('route_refs', $first);
        } elseif (in_array($name, ['view', 'markdown'], true) && $first !== null) {
            $this->add('views', $first);
        } elseif ($name === 'render' && $first !== null && $this->looksLikeInertia($node->var)) {
            $this->add('renders', $first);
        }

        if ($this->test !== null && in_array(strtolower($name), self::HTTP_VERBS, true) && $first !== null && str_starts_with($first, '/')) {
            $verb = strtoupper(str_replace('json', '', strtolower($name)));
            $this->facts['uri_refs'][] = (in_array($verb, ['CALL', ''], true) ? 'ANY' : $verb).' '.$first;
        }

        $this->markAssertion($name);
    }

    private function onStaticCall(Expr\StaticCall $node, string $name): void
    {
        $class = $node->class instanceof Name ? $node->class->toString() : null;
        $first = $this->firstString($node);

        if (in_array($class, ['self', 'static'], true) && $this->method !== null) {
            $this->facts['methods'][$this->method]['calls'][] = $name;
        }

        if ($class === 'Inertia\\Inertia' && $name === 'render') {
            $first !== null ? $this->add('renders', $first) : $this->facts['unresolved_renders'][] = $node->getStartLine();
        }

        if ($class === 'Illuminate\\Support\\Facades\\Redirect' && $name === 'route' && $first !== null) {
            $this->add('route_refs', $first);
        }

        if ($class === 'Illuminate\\Support\\Facades\\Schema' && in_array($name, ['create', 'table', 'drop', 'dropIfExists', 'rename'], true)) {
            foreach (array_slice($node->args, 0, $name === 'rename' ? 2 : 1) as $arg) {
                if ($arg instanceof Node\Arg && $arg->value instanceof String_) {
                    $this->facts['migration_tables'][] = $arg->value->value;
                }
            }
        }

        $this->markAssertion($name);
    }

    private function beginTest(Node $node, string $name): void
    {
        $full = implode(' → ', [...$this->describe, $name]);
        $this->facts['tests'][] = ['name' => $full, 'line' => $node->getStartLine(), 'end' => $node->getEndLine(), 'assertions' => false];
        $this->test = array_key_last($this->facts['tests']);
        $this->scopeNodes[spl_object_id($node)] = $node instanceof Stmt\ClassMethod ? 'method' : 'test';
    }

    private function isPhpUnitTest(Stmt\ClassMethod $node): bool
    {
        if (str_starts_with($node->name->toString(), 'test')) {
            return true;
        }
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === 'Test') {
                    return true;
                }
            }
        }

        return false;
    }

    private function markAssertion(string $name): void
    {
        if ($this->test !== null && preg_match(self::ASSERTION, $name)) {
            $this->facts['tests'][$this->test]['assertions'] = true;
        }
    }

    private function addRef(string $fqcn): void
    {
        $this->facts['refs'][] = $fqcn;

        if ($this->method !== null && $this->method !== '__construct') {
            $this->facts['methods'][$this->method]['refs'][] = $fqcn;
        } elseif ($this->class !== null) {
            $this->facts['class_refs'][] = $fqcn;
        }
    }

    private function add(string $key, string $value): void
    {
        $this->facts[$key][] = $value;
        if ($this->method !== null) {
            $this->facts['methods'][$this->method][$key][] = $value;
        }
    }

    private function looksLikeInertia(Expr $var): bool
    {
        return $var instanceof Expr\FuncCall && $var->name instanceof Name && $var->name->getLast() === 'inertia';
    }

    private function firstString(Expr\CallLike $node): ?string
    {
        if ($node->isFirstClassCallable()) {
            return null;
        }
        $arg = $node->getArgs()[0] ?? null;

        return $arg !== null && $arg->value instanceof String_ ? $arg->value->value : null;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
