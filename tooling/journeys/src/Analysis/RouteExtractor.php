<?php

namespace Tooling\Journeys\Analysis;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;

/**
 * Statically evaluates Laravel route definitions (Route::get, ::resource,
 * ->prefix()->name()->group(...)) without booting the application, so routes
 * can be read from any snapshot. `journeys validate --with-app` cross-checks
 * the result against `php artisan route:list`.
 */
final class RouteExtractor
{
    private const VERBS = ['get' => ['GET', 'HEAD'], 'post' => ['POST'], 'put' => ['PUT'], 'patch' => ['PATCH'], 'delete' => ['DELETE'], 'options' => ['OPTIONS'], 'any' => ['ANY']];

    private const RESOURCE_ACTIONS = [
        'index' => [['GET', 'HEAD'], ''],
        'create' => [['GET', 'HEAD'], '/create'],
        'store' => [['POST'], ''],
        'show' => [['GET', 'HEAD'], '/{param}'],
        'edit' => [['GET', 'HEAD'], '/{param}/edit'],
        'update' => [['PUT', 'PATCH'], '/{param}'],
        'destroy' => [['DELETE'], '/{param}'],
    ];

    /** @var list<array<string, mixed>> */
    private array $routes = [];

    /** @var list<array{lines: array{int,int}, routes: list<string>}> */
    private array $groups = [];

    /** @var list<array{line:int, reason:string}> */
    private array $unresolved = [];

    public function __construct(
        private readonly string $file,
        private readonly string $prefix = '',
        private readonly string $namePrefix = '',
    ) {}

    /**
     * @param  list<Stmt>  $stmts  name-resolved statements
     * @return array{routes: list<array<string, mixed>>, groups: list<array{lines: array{int,int}, routes: list<string>}>, unresolved: list<array{line:int, reason:string}>}
     */
    public function extract(array $stmts): array
    {
        $this->walk($stmts, ['prefix' => $this->prefix, 'name' => $this->namePrefix, 'middleware' => [], 'controller' => null]);

        return ['routes' => $this->routes, 'groups' => $this->groups, 'unresolved' => $this->unresolved];
    }

    /**
     * @param  list<Node>  $stmts
     * @param  array{prefix:string, name:string, middleware:list<string>, controller:?string}  $ctx
     * @return list<string> ids of routes declared within
     */
    private function walk(array $stmts, array $ctx): array
    {
        $ids = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Expression) {
                $ids = [...$ids, ...$this->expression($stmt->expr, $ctx, $stmt)];
            } elseif ($stmt instanceof Stmt\Namespace_ || $stmt instanceof Stmt\Block) {
                $ids = [...$ids, ...$this->walk($stmt->stmts, $ctx)];
            }
        }

        return $ids;
    }

    /**
     * @param  array{prefix:string, name:string, middleware:list<string>, controller:?string}  $ctx
     * @return list<string>
     */
    private function expression(Expr $expr, array $ctx, Stmt $stmt): array
    {
        $chain = $this->chain($expr);
        if ($chain === null) {
            return [];
        }

        $calls = array_column($chain, 0);
        $groupIndex = array_search('group', $calls, true);

        if ($groupIndex !== false) {
            $inner = $this->applyAttributes($ctx, array_slice($chain, 0, $groupIndex));
            $closure = $this->closureArg($chain[$groupIndex][1]);
            foreach ($chain[$groupIndex][1] as $arg) {
                if ($arg->value instanceof Expr\Array_) {
                    $inner = $this->applyArrayAttributes($inner, $arg->value);
                }
            }
            if ($closure === null) {
                $this->unresolved[] = ['line' => $stmt->getStartLine(), 'reason' => 'route group without inline closure'];

                return [];
            }
            $ids = $this->walk($closure->stmts ?? [], $inner);
            $this->groups[] = ['lines' => [$stmt->getStartLine(), $stmt->getEndLine()], 'routes' => $ids];

            return $ids;
        }

        $verbIndex = null;
        foreach ($chain as $i => [$call]) {
            if (isset(self::VERBS[$call]) || in_array($call, ['match', 'resource', 'apiResource', 'redirect', 'view'], true)) {
                $verbIndex = $i;
                break;
            }
        }

        if ($verbIndex === null) {
            return [];
        }

        $before = $this->applyAttributes($ctx, array_slice($chain, 0, $verbIndex));
        $after = array_slice($chain, $verbIndex + 1);
        [$verb, $args] = $chain[$verbIndex];

        if ($verb === 'resource' || $verb === 'apiResource') {
            return $this->resource($verb, $args, $after, $before, $stmt);
        }

        $methods = self::VERBS[$verb] ?? (in_array($verb, ['redirect', 'view'], true) ? ['GET', 'HEAD'] : null);
        $uriIndex = 0;
        if ($verb === 'match') {
            $methods = $this->stringList($args[0]->value ?? null);
            $methods = array_map('strtoupper', $methods);
            $uriIndex = 1;
        }

        $uri = $this->string($args[$uriIndex]->value ?? null);
        if ($uri === null) {
            $this->unresolved[] = ['line' => $stmt->getStartLine(), 'reason' => 'route URI is not a string literal'];

            return [];
        }

        $route = [
            'methods' => $methods,
            'uri' => self::joinUri($before['prefix'], $uri),
            'name' => null,
            'action' => $this->action($args[$uriIndex + 1]->value ?? null, $before['controller'], $verb),
            'middleware' => $before['middleware'],
            'lines' => [$stmt->getStartLine(), $stmt->getEndLine()],
            'file' => $this->file,
        ];

        foreach ($after as [$call, $callArgs]) {
            if ($call === 'name' && ($n = $this->string($callArgs[0]->value ?? null)) !== null) {
                $route['name'] = $before['name'].$n;
            } elseif ($call === 'middleware') {
                $route['middleware'] = [...$route['middleware'], ...$this->middlewareArgs($callArgs)];
            }
        }

        return [$this->register($route)];
    }

    /**
     * @param  list<Node\Arg>  $args
     * @param  list<array{0:string,1:list<Node\Arg>}>  $after
     * @param  array{prefix:string, name:string, middleware:list<string>, controller:?string}  $ctx
     * @return list<string>
     */
    private function resource(string $verb, array $args, array $after, array $ctx, Stmt $stmt): array
    {
        $resource = $this->string($args[0]->value ?? null);
        $controller = $this->className($args[1]->value ?? null);
        if ($resource === null || $controller === null) {
            $this->unresolved[] = ['line' => $stmt->getStartLine(), 'reason' => 'resource route with non-literal name or controller'];

            return [];
        }

        $actions = array_keys(self::RESOURCE_ACTIONS);
        if ($verb === 'apiResource') {
            $actions = array_values(array_diff($actions, ['create', 'edit']));
        }
        $middleware = $ctx['middleware'];
        foreach ($after as [$call, $callArgs]) {
            if ($call === 'only') {
                $actions = array_values(array_intersect($actions, $this->stringList($callArgs[0]->value ?? null)));
            } elseif ($call === 'except') {
                $actions = array_values(array_diff($actions, $this->stringList($callArgs[0]->value ?? null)));
            } elseif ($call === 'middleware') {
                $middleware = [...$middleware, ...$this->middlewareArgs($callArgs)];
            }
        }

        // budgets.accounts => budgets/{budget}/accounts, last param {account}
        $segments = explode('.', $resource);
        $base = '';
        foreach ($segments as $i => $segment) {
            $base .= ($i > 0 ? '/' : '').$segment;
            if ($i < count($segments) - 1) {
                $base .= '/{'.Str::singular($segment).'}';
            }
        }
        $param = '{'.str_replace('-', '_', Str::singular(end($segments))).'}';

        $ids = [];
        foreach ($actions as $action) {
            [$methods, $suffix] = self::RESOURCE_ACTIONS[$action];
            $ids[] = $this->register([
                'methods' => $methods,
                'uri' => self::joinUri($ctx['prefix'], $base.str_replace('{param}', $param, $suffix)),
                'name' => $ctx['name'].$resource.'.'.$action,
                'action' => ['type' => 'controller', 'class' => $controller, 'method' => $action],
                'middleware' => $middleware,
                'lines' => [$stmt->getStartLine(), $stmt->getEndLine()],
                'file' => $this->file,
            ]);
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function register(array $route): string
    {
        $route['id'] = $route['name'] ?? self::unnamedId($route['methods'][0], $route['uri']);
        $this->routes[] = $route;

        return $route['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function action(?Expr $expr, ?string $groupController, string $verb): array
    {
        if ($expr instanceof Expr\Array_ && count($expr->items) === 2) {
            $class = $this->className($expr->items[0]?->value);
            $method = $this->string($expr->items[1]?->value);
            if ($class !== null && $method !== null) {
                return ['type' => 'controller', 'class' => $class, 'method' => $method];
            }
        }

        if (($class = $this->className($expr)) !== null) {
            return ['type' => 'controller', 'class' => $class, 'method' => '__invoke'];
        }

        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return ['type' => 'closure', 'closure' => self::closureFacts($expr)];
        }

        if (($string = $this->string($expr)) !== null) {
            if (str_contains($string, '@')) {
                [$class, $method] = explode('@', $string, 2);

                return ['type' => 'controller', 'class' => ltrim($class, '\\'), 'method' => $method];
            }
            if ($groupController !== null) {
                return ['type' => 'controller', 'class' => $groupController, 'method' => $string];
            }
            if (in_array($verb, ['redirect', 'view'], true)) {
                return ['type' => $verb, 'target' => $string];
            }
        }

        return ['type' => 'unresolved'];
    }

    /**
     * Facts for an inline closure action (refs, renders, redirects).
     *
     * @return array<string, mixed>
     */
    private static function closureFacts(Expr\Closure|Expr\ArrowFunction $closure): array
    {
        $visitor = new PhpReferenceVisitor;
        $traverser = new NodeTraverser($visitor);
        $traverser->traverse($closure instanceof Expr\Closure ? $closure->stmts : [new Stmt\Return_($closure->expr)]);

        return [
            'refs' => $visitor->facts['refs'],
            'renders' => $visitor->facts['renders'],
            'views' => $visitor->facts['views'],
            'route_refs' => $visitor->facts['route_refs'],
            'unresolved_renders' => $visitor->facts['unresolved_renders'],
        ];
    }

    /**
     * @param  array{prefix:string, name:string, middleware:list<string>, controller:?string}  $ctx
     * @param  list<array{0:string,1:list<Node\Arg>}>  $calls
     * @return array{prefix:string, name:string, middleware:list<string>, controller:?string}
     */
    private function applyAttributes(array $ctx, array $calls): array
    {
        foreach ($calls as [$call, $args]) {
            $value = $args[0]->value ?? null;
            match ($call) {
                'prefix' => $ctx['prefix'] = self::joinUri($ctx['prefix'], (string) $this->string($value)),
                'name', 'as' => $ctx['name'] .= (string) $this->string($value),
                'middleware' => $ctx['middleware'] = [...$ctx['middleware'], ...$this->middlewareArgs($args)],
                'controller' => $ctx['controller'] = $this->className($value) ?? $ctx['controller'],
                default => null,
            };
        }

        return $ctx;
    }

    /**
     * @param  array{prefix:string, name:string, middleware:list<string>, controller:?string}  $ctx
     * @return array{prefix:string, name:string, middleware:list<string>, controller:?string}
     */
    private function applyArrayAttributes(array $ctx, Expr\Array_ $array): array
    {
        foreach ($array->items as $item) {
            $key = $this->string($item?->key);
            if ($key === 'prefix') {
                $ctx['prefix'] = self::joinUri($ctx['prefix'], (string) $this->string($item->value));
            } elseif ($key === 'as') {
                $ctx['name'] .= (string) $this->string($item->value);
            } elseif ($key === 'middleware') {
                $ctx['middleware'] = [...$ctx['middleware'], ...$this->stringList($item->value)];
            }
        }

        return $ctx;
    }

    /**
     * Flatten `Route::a(...)->b(...)->c(...)` into [[a, args], [b, args], ...].
     *
     * @return list<array{0:string,1:list<Node\Arg>}>|null
     */
    private function chain(Expr $expr): ?array
    {
        $calls = [];
        while ($expr instanceof Expr\MethodCall) {
            if (! $expr->name instanceof Node\Identifier) {
                return null;
            }
            array_unshift($calls, [$expr->name->toString(), $expr->getArgs()]);
            $expr = $expr->var;
        }

        if (! $expr instanceof Expr\StaticCall || ! $expr->class instanceof Name || ! $expr->name instanceof Node\Identifier) {
            return null;
        }

        if (! in_array($expr->class->toString(), ['Route', 'Illuminate\\Support\\Facades\\Route'], true)) {
            return null;
        }

        array_unshift($calls, [$expr->name->toString(), $expr->getArgs()]);

        return $calls;
    }

    /**
     * @param  list<Node\Arg>  $args
     */
    private function closureArg(array $args): Expr\Closure|Expr\ArrowFunction|null
    {
        foreach ($args as $arg) {
            if ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * @param  list<Node\Arg>  $args
     * @return list<string>
     */
    private function middlewareArgs(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            $out = [...$out, ...$this->stringList($arg->value)];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function stringList(?Expr $expr): array
    {
        if ($expr instanceof Expr\Array_) {
            $out = [];
            foreach ($expr->items as $item) {
                $value = $this->string($item?->value) ?? $this->className($item?->value);
                if ($value !== null) {
                    $out[] = $value;
                }
            }

            return $out;
        }

        $single = $this->string($expr) ?? $this->className($expr);

        return $single === null ? [] : [$single];
    }

    private function string(?Node $expr): ?string
    {
        return $expr instanceof String_ ? $expr->value : null;
    }

    private function className(?Node $expr): ?string
    {
        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'class') {
            return $expr->class->toString();
        }

        return null;
    }

    /**
     * Identifier for routes without a name, e.g. "GET /csrf-token".
     */
    public static function unnamedId(string $method, string $uri): string
    {
        return $method.' /'.ltrim($uri, '/');
    }

    public static function joinUri(string $prefix, string $uri): string
    {
        $joined = trim(trim($prefix, '/').'/'.trim($uri, '/'), '/');

        return $joined === '' ? '/' : $joined;
    }
}
