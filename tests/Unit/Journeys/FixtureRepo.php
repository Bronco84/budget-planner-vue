<?php

namespace Tests\Unit\Journeys;

use Tooling\Journeys\Analysis\FactStore;
use Tooling\Journeys\Catalog\Catalog;
use Tooling\Journeys\Config;
use Tooling\Journeys\Git\Git;
use Tooling\Journeys\Git\Snapshot;
use Tooling\Journeys\Impact\ChangeSet;
use Tooling\Journeys\Impact\ImpactAnalyzer;
use Tooling\Journeys\Index\IndexWriter;
use Tooling\Journeys\Workspace;

/**
 * A throwaway git repository holding a miniature Laravel/Inertia app with
 * three journeys, used to exercise the analyzer end to end against real git.
 */
final class FixtureRepo
{
    public readonly string $root;

    public readonly Git $git;

    private function __construct()
    {
        $this->root = sys_get_temp_dir().'/journeys-fixture-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        // Isolated: when the suite runs inside a git hook, inherited GIT_INDEX_FILE
        // etc. would otherwise redirect fixture commands into the outer repository.
        $this->git = new Git($this->root, isolated: true);
    }

    public static function create(): self
    {
        $repo = new self;
        $repo->git('init', '-q', '-b', 'main');
        foreach (self::files() as $path => $content) {
            $repo->write($path, $content);
        }
        $repo->regenerateIndex();
        $repo->commit('initial');

        return $repo;
    }

    public static function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $out, $code);

        return $code === 0 && is_dir(dirname(__DIR__, 3).'/node_modules/@vue/compiler-sfc');
    }

    public function write(string $path, string $content): void
    {
        $full = $this->root.'/'.$path;
        is_dir(dirname($full)) || mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
    }

    public function read(string $path): string
    {
        return (string) file_get_contents($this->root.'/'.$path);
    }

    public function replace(string $path, string $search, string $replace): void
    {
        $content = $this->read($path);
        if (! str_contains($content, $search)) {
            throw new \RuntimeException("'{$search}' not found in {$path}");
        }
        $this->write($path, str_replace($search, $replace, $content));
    }

    public function delete(string $path): void
    {
        unlink($this->root.'/'.$path);
    }

    public function git(string ...$args): string
    {
        return $this->git->run(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.com', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...$args]);
    }

    public function stage(string ...$paths): void
    {
        $this->git('add', '-A', '--', ...$paths);
    }

    public function commit(string $message): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $message);
    }

    public function regenerateIndex(): void
    {
        $ws = $this->workspace(Snapshot::WORKTREE);
        $catalog = Catalog::load($ws->snapshot);
        $this->write(IndexWriter::INDEX_PATH, IndexWriter::render($catalog, $ws->config));
        $this->write(IndexWriter::BASELINE_PATH, IndexWriter::renderBaseline($catalog));
    }

    public function workspace(string $kind, ?string $ref = null): Workspace
    {
        return Workspace::open($this->git, $kind, $ref, new FactStore($this->root, null));
    }

    /**
     * @return array<string, mixed>
     */
    public function impact(ChangeSet $set): array
    {
        return (new ImpactAnalyzer($this->git, new FactStore($this->root, null)))->analyze($set);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    public static function journeyIds(array $report): array
    {
        $ids = array_column($report['journeys'], 'id');
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function journey(array $report, string $id): ?array
    {
        foreach ($report['journeys'] as $journey) {
            if ($journey['id'] === $id) {
                return $journey;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function classification(array $report, string $path): ?string
    {
        foreach ($report['files'] as $file) {
            if ($file['path'] === $path) {
                return $file['classification'];
            }
        }

        return null;
    }

    public function __destruct()
    {
        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }
    }

    /**
     * @return array<string, string>
     */
    private static function files(): array
    {
        $php = fn (string $body) => "<?php\n\n".$body."\n";

        return [
            Config::PATH => <<<'YAML'
                schema: 1
                route_files: [routes/web.php]
                shared_infrastructure:
                  - { pattern: bootstrap/**, scope: all, reason: bootstrap }
                  - { pattern: resources/js/app.js, scope: frontend, reason: frontend entry }
                non_journey:
                  - { pattern: app/Console/**, reason: operator commands }
                ignore: ['**/*.md']
                commands:
                  php_tests: php -r "exit((int) getenv('FIXTURE_TESTS_FAIL'));" --
                excluded_routes:
                  'GET /health': uptime probe
                YAML,
            'bootstrap/app.php' => $php('return [];'),
            'routes/web.php' => $php(<<<'PHP'
                use App\Http\Controllers\InvoiceController;
                use App\Http\Controllers\LoginController;
                use App\Http\Controllers\OrderController;
                use Illuminate\Support\Facades\Route;

                Route::get('/login', [LoginController::class, 'show'])->name('login');

                Route::get('/health', fn () => 'ok');

                Route::middleware('auth')->prefix('app')->name('app.')->group(function () {
                    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
                    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');

                    Route::resource('invoices', InvoiceController::class)->only(['index', 'show']);
                });
                PHP),
            'app/Http/Controllers/LoginController.php' => $php(<<<'PHP'
                namespace App\Http\Controllers;

                use Inertia\Inertia;

                class LoginController
                {
                    public function show()
                    {
                        return Inertia::render('Auth/Login');
                    }
                }
                PHP),
            'app/Http/Controllers/OrderController.php' => $php(<<<'PHP'
                namespace App\Http\Controllers;

                use App\Http\Requests\StoreOrderRequest;
                use App\Services\OrderService;
                use Inertia\Inertia;

                class OrderController
                {
                    public function index(OrderService $orders)
                    {
                        return Inertia::render('Orders/Index', ['orders' => $orders->all()]);
                    }

                    public function store(StoreOrderRequest $request, OrderService $orders)
                    {
                        $orders->place($request->validated());

                        return redirect()->route('app.orders.index');
                    }
                }
                PHP),
            'app/Http/Controllers/InvoiceController.php' => $php(<<<'PHP'
                namespace App\Http\Controllers;

                use App\Services\InvoiceService;
                use Inertia\Inertia;

                class InvoiceController
                {
                    public function index(InvoiceService $invoices)
                    {
                        return Inertia::render('Invoices/Index', ['invoices' => $invoices->all()]);
                    }

                    public function show(int $id)
                    {
                        return Inertia::render('Invoices/Show');
                    }
                }
                PHP),
            'app/Http/Requests/StoreOrderRequest.php' => $php("namespace App\\Http\\Requests;\n\nclass StoreOrderRequest\n{\n}"),
            'app/Services/OrderService.php' => $php(<<<'PHP'
                namespace App\Services;

                use App\Models\Order;

                class OrderService
                {
                    public function all()
                    {
                        return Order::all();
                    }

                    public function place(array $data)
                    {
                        return (new PriceCalculator)->total($data);
                    }
                }
                PHP),
            'app/Services/PriceCalculator.php' => $php("namespace App\\Services;\n\nclass PriceCalculator\n{\n    public function total(array \$data): int\n    {\n        return 0;\n    }\n}"),
            'app/Services/InvoiceService.php' => $php("namespace App\\Services;\n\nclass InvoiceService\n{\n    public function all(): array\n    {\n        return [];\n    }\n}"),
            'app/Models/Order.php' => $php("namespace App\\Models;\n\nclass Order\n{\n    public function user()\n    {\n        return \$this->belongsTo(User::class);\n    }\n}"),
            'app/Models/User.php' => $php("namespace App\\Models;\n\nclass User\n{\n    public function orders()\n    {\n        return \$this->hasMany(Order::class);\n    }\n}"),
            'app/Console/Commands/Cleanup.php' => $php("namespace App\\Console\\Commands;\n\nclass Cleanup\n{\n}"),
            'database/migrations/2024_01_01_000000_create_orders_table.php' => $php("use Illuminate\\Support\\Facades\\Schema;\n\nSchema::create('orders', function (\$table) {\n    \$table->id();\n});"),
            'resources/js/app.js' => "import './bootstrap';\n",
            'resources/js/bootstrap.js' => "export default {};\n",
            'resources/js/Layouts/AppLayout.vue' => "<template><main><slot /></main></template>\n",
            'resources/js/Components/Money.vue' => "<template><span>{{ value }}</span></template>\n<script setup>\ndefineProps({ value: Number });\n</script>\n",
            'resources/js/Components/OrderTable.vue' => "<template><table><Money :value=\"1\" /></table></template>\n<script setup>\nimport Money from './Money.vue';\n</script>\n",
            'resources/js/Pages/Orders/Index.vue' => "<template><AppLayout><OrderTable /><a :href=\"route('app.orders.store')\">new</a></AppLayout></template>\n<script setup>\nimport AppLayout from '@/Layouts/AppLayout.vue';\nimport OrderTable from '@/Components/OrderTable.vue';\n</script>\n",
            'resources/js/Pages/Invoices/Index.vue' => "<template><AppLayout>invoices</AppLayout></template>\n<script setup>\nimport AppLayout from '@/Layouts/AppLayout.vue';\n</script>\n",
            'resources/js/Pages/Invoices/Show.vue' => "<template><AppLayout>invoice</AppLayout></template>\n<script setup>\nimport AppLayout from '@/Layouts/AppLayout.vue';\n</script>\n",
            'resources/js/Pages/Auth/Login.vue' => "<template><form>login</form></template>\n",
            'tests/Feature/OrderTest.php' => $php(<<<'PHP'
                test('user can list orders', function () {
                    $this->get(route('app.orders.index'))->assertOk();
                });

                test('placing an order calls the service', function () {
                    $this->post('/app/orders', [])->assertRedirect();
                });

                test('smoke without assertions', function () {
                    $this->get('/app/orders');
                });
                PHP),
            'tests/Feature/InvoiceTest.php' => $php(<<<'PHP'
                use App\Services\InvoiceService;

                test('invoices are listed', function () {
                    expect((new InvoiceService)->all())->toBe([]);
                });
                PHP),
            'README.md' => "# fixture\n",
            'docs/user-journeys/journeys/orders/ORDER-001-place-order.yaml' => <<<'YAML'
                schema: 1
                id: ORDER-001
                name: Customer places an order
                domain: orders
                status: implemented
                priority: critical
                summary: Lists orders and places a new one.
                actors: [customer]
                routes: [app.orders.index, app.orders.store]
                scenarios:
                  - id: list
                    kind: happy_path
                    description: Orders are listed.
                    coverage: covered
                    tests: ['tests/Feature/OrderTest.php::user can list orders']
                  - id: place
                    kind: happy_path
                    description: An order is placed.
                    coverage: covered
                    tests: ['tests/Feature/OrderTest.php::placing an order calls the service']
                  - id: invalid
                    kind: validation
                    description: Invalid orders are rejected.
                    coverage: uncovered
                  - id: authz
                    kind: authorization
                    description: Guests are redirected.
                    coverage: not_applicable
                    note: fixture
                YAML,
            'docs/user-journeys/journeys/invoices/INV-001-view-invoices.yaml' => <<<'YAML'
                schema: 1
                id: INV-001
                name: Customer views invoices
                domain: invoices
                status: implemented
                priority: medium
                summary: Lists and shows invoices.
                actors: [customer]
                routes: [app.invoices.index, app.invoices.show]
                scenarios:
                  - id: list
                    kind: happy_path
                    description: Invoices are listed.
                    coverage: covered
                    tests: ['tests/Feature/InvoiceTest.php::invoices are listed']
                  - id: none
                    kind: empty_state
                    description: No invoices.
                    coverage: not_applicable
                    note: fixture
                  - id: authz
                    kind: authorization
                    description: Owner only.
                    coverage: not_applicable
                    note: fixture
                YAML,
            'docs/user-journeys/journeys/auth/AUTH-001-login.yaml' => <<<'YAML'
                schema: 1
                id: AUTH-001
                name: Guest logs in
                domain: auth
                status: implemented
                priority: critical
                summary: Shows the login page.
                actors: [guest]
                routes: [login]
                scenarios:
                  - id: page
                    kind: happy_path
                    description: Login page renders.
                    coverage: uncovered
                  - id: bad
                    kind: validation
                    description: Bad credentials.
                    coverage: uncovered
                  - id: authz
                    kind: authorization
                    description: Public.
                    coverage: not_applicable
                    note: fixture
                YAML,
        ];
    }
}
