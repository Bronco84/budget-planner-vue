<?php

use App\Enums\BudgetRole;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetInvitation;
use App\Models\Category;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\PayoffPlan;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Scenario;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePlaidApi;

/*
 * Budget sharing role matrix (SHARE-002). Every budget-scoped route is listed
 * with the minimum access it needs, and each role is checked against it:
 *
 *   view    any member (viewer, editor, owner)
 *   edit    editor or owner — writes to budget data, bank syncs, edit forms
 *   owner   owners only — settings, deletion, sharing, bank connections
 *   primary the primary owner only (transfer primary ownership)
 *   member  any member, even for a write (leaving the budget)
 *
 * "Allowed" means the request is not rejected with 403 (it may still redirect
 * or fail validation, as requests carry no payload); "denied" means 403.
 * A completeness test fails when a new {budget} route is not classified here.
 */

const ROLE_MATRIX_LEVELS = ['view' => 1, 'edit' => 2, 'owner' => 3, 'primary' => 4];

/** @var array<string, string> route name => required access */
const ROLE_MATRIX_ROUTES = [
    // Dashboard, statistics, reports, projections
    'budgets.show' => 'view',
    'budget.statistics.yearly' => 'view',
    'budget.statistics.monthly' => 'view',
    'reports.index' => 'view',
    'budget.projections' => 'view',
    'budget.projections.multi-account' => 'view',
    'budget.account.projections' => 'view',
    'budget.account.balance-projection' => 'view',
    'budgets.sharing.index' => 'view',
    // Budget settings and lifecycle
    'budgets.edit' => 'owner',
    'budgets.update' => 'owner',
    'budgets.destroy' => 'owner',
    'budgets.setup' => 'edit',
    'budgets.setup.manual' => 'view',
    'budgets.setup.connect' => 'owner',
    // Sharing
    'budgets.invitations.store' => 'owner',
    'budgets.invitations.destroy' => 'owner',
    'budgets.members.update' => 'owner',
    'budgets.members.destroy' => 'owner',
    'budgets.ownership.transfer' => 'primary',
    'budgets.membership.leave' => 'member',
    // Accounts
    'budgets.accounts.create' => 'edit',
    'budgets.accounts.store' => 'edit',
    'budgets.accounts.edit' => 'edit',
    'budgets.accounts.update' => 'edit',
    'budgets.accounts.destroy' => 'edit',
    'accounts.updateAutopay' => 'edit',
    'accounts.fetchLogo' => 'edit',
    'accounts.clearLogo' => 'edit',
    // Categories
    'budgets.categories.index' => 'view',
    'budgets.categories.store' => 'edit',
    'budgets.categories.update' => 'edit',
    'budgets.categories.destroy' => 'edit',
    // Properties
    'budgets.properties.index' => 'view',
    'budgets.properties.create' => 'edit',
    'budgets.properties.show' => 'view',
    'budgets.properties.edit' => 'edit',
    'budgets.properties.store' => 'edit',
    'budgets.properties.update' => 'edit',
    'budgets.properties.destroy' => 'edit',
    // Transactions
    'budget.transaction.index' => 'view',
    'budget.transaction.create' => 'edit',
    'budget.transaction.activity-log' => 'view',
    'budget.transaction.edit' => 'edit',
    'budget.transaction.store' => 'edit',
    'budget.transaction.update' => 'edit',
    'budget.transaction.destroy' => 'edit',
    // Transfers
    'budget.transfers.index' => 'view',
    'budget.transfers.create' => 'edit',
    'budget.transfers.show' => 'view',
    'budget.transfers.edit' => 'edit',
    'budget.transfers.store' => 'edit',
    'budget.transfers.update' => 'edit',
    'budget.transfers.destroy' => 'edit',
    // Recurring templates, rules and analysis
    'recurring-transactions.index' => 'view',
    'recurring-transactions.find-matching' => 'view',
    'recurring-transactions.edit' => 'edit',
    'recurring-transactions.diagnostics' => 'view',
    'recurring-transactions.test-matching' => 'view',
    'recurring-transactions.create' => 'edit',
    'recurring-transactions.store' => 'edit',
    'recurring-transactions.update' => 'edit',
    'recurring-transactions.destroy' => 'edit',
    'recurring-transactions.duplicate' => 'edit',
    'recurring-transactions.link-transactions' => 'edit',
    'recurring-transactions.rules.index' => 'view',
    'recurring-transactions.rules.preview' => 'view',
    'recurring-transactions.rules.linked' => 'view',
    'recurring-transactions.rules.store' => 'edit',
    'recurring-transactions.rules.update' => 'edit',
    'recurring-transactions.rules.destroy' => 'edit',
    'recurring-transactions.rules.test' => 'edit',
    'recurring-transactions.rules.apply' => 'edit',
    'recurring-transactions.rules.unlink' => 'edit',
    'recurring-transactions.analysis' => 'view',
    'recurring-transactions.analysis.analyze' => 'edit',
    'recurring-transactions.analysis.create-templates' => 'edit',
    // Scenarios
    'budgets.scenarios.index' => 'view',
    'budgets.scenarios.store' => 'edit',
    'budgets.scenarios.update' => 'edit',
    'budgets.scenarios.destroy' => 'edit',
    'budgets.scenarios.toggle' => 'edit',
    // Payoff plans
    'payoff-plans.index' => 'view',
    'payoff-plans.show' => 'view',
    'payoff-plans.create' => 'edit',
    'payoff-plans.edit' => 'edit',
    'payoff-plans.store' => 'edit',
    'payoff-plans.update' => 'edit',
    'payoff-plans.destroy' => 'edit',
    // Files
    'budgets.files.index' => 'view',
    'budgets.files.download' => 'view',
    'budgets.files.upload' => 'edit',
    'budgets.files.destroy' => 'edit',
    'transactions.files.index' => 'view',
    'transactions.files.upload' => 'edit',
    'files.download' => 'view',
    'files.delete' => 'edit',
    // Bank connections: syncing is an edit; connecting/re-authenticating/unlinking is owner-only
    'plaid.statement-history' => 'view',
    'plaid.sync' => 'edit',
    'plaid.sync-all' => 'edit',
    'plaid.discover' => 'owner',
    'plaid.import' => 'owner',
    'plaid.link' => 'owner',
    'plaid.store' => 'owner',
    'plaid.upgrade-link-token' => 'owner',
    'plaid.update-connection' => 'owner',
    'plaid.destroy' => 'owner',
    'admin.plaid-liabilities.update-connection' => 'owner',
];

beforeEach(function () {
    Mail::fake();
    Storage::fake('s3');
    Http::preventStrayRequests();
    Http::fake();
    $this->plaid = FakePlaidApi::install();

    $owner = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $owner->id]);

    $members = ['owner' => $owner];
    foreach (['co_owner' => BudgetRole::Owner, 'editor' => BudgetRole::Editor, 'viewer' => BudgetRole::Viewer] as $key => $role) {
        $members[$key] = User::factory()->create();
        $budget->connected_users()->attach($members[$key]->id, ['access_level' => $role->value]);
    }
    $members['stranger'] = User::factory()->create();
    $this->members = $members;

    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $other = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    $template = RecurringTransactionTemplate::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-matrix',
        'institution_name' => 'Matrix Bank',
        'access_token' => 'access-sandbox-matrix',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => 'acc-matrix',
        'account_name' => 'Checking',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
        'current_balance_cents' => 0,
    ]);
    $file = File::create([
        'hash' => 'hash-matrix',
        'original_name' => 'receipt.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 6,
        'extension' => 'pdf',
        'uploaded_by' => $owner->id,
    ]);
    Storage::disk('s3')->put('hash-matrix', 'matrix');

    $this->budget = $budget;
    $this->params = [
        'budget' => $budget,
        'account' => $account,
        'transaction' => $transaction,
        'transfer' => Transfer::create([
            'budget_id' => $budget->id,
            'from_account_id' => $account->id,
            'to_account_id' => $other->id,
            'amount_in_cents' => 1000,
            'date' => now()->addWeek()->toDateString(),
            'description' => 'Matrix transfer',
        ]),
        'recurring_transaction' => $template,
        'rule' => RecurringTransactionRule::create([
            'recurring_transaction_template_id' => $template->id,
            'field' => 'description',
            'operator' => 'contains',
            'value' => 'gym',
            'is_case_sensitive' => false,
            'priority' => 1,
            'is_active' => true,
        ]),
        'payoff_plan' => PayoffPlan::create([
            'budget_id' => $budget->id,
            'name' => 'Matrix plan',
            'strategy' => 'snowball',
            'monthly_extra_payment_cents' => 0,
            'is_active' => true,
            'start_date' => now()->toDateString(),
        ]),
        'property' => $budget->properties()->create([
            'name' => 'Matrix house',
            'type' => 'property',
            'current_value_cents' => 30000000,
            'value_updated_at' => now(),
            'api_source' => 'manual',
        ]),
        'category' => Category::create(['budget_id' => $budget->id, 'name' => 'Groceries', 'amount' => 100, 'order' => 1]),
        'scenario' => Scenario::factory()->create(['budget_id' => $budget->id, 'user_id' => $owner->id]),
        'fileAttachment' => FileAttachment::create([
            'file_id' => $file->id,
            'attachable_type' => Budget::class,
            'attachable_id' => $budget->id,
            'attached_by' => $owner->id,
        ]),
        'attachment' => FileAttachment::create([
            'file_id' => $file->id,
            'attachable_type' => Transaction::class,
            'attachable_id' => $transaction->id,
            'attached_by' => $owner->id,
        ]),
        'invitation' => $budget->invitations()->create([
            'email' => 'invitee@example.com',
            'role' => BudgetRole::Viewer,
            'token_hash' => BudgetInvitation::hashToken('matrix-token'),
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]),
        'member' => $members['viewer'],
        'connection' => $connection,
    ];
});

/**
 * Request a matrix route as the given member, filling every route parameter
 * from the budget's fixtures.
 */
function roleMatrixRequest(string $routeName, User $user)
{
    $route = Route::getRoutes()->getByName($routeName);
    $params = [];
    foreach ($route->parameterNames() as $name) {
        if (array_key_exists($name, test()->params)) {
            $params[$name] = test()->params[$name];
        }
    }

    $method = strtolower(collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first());

    return test()->actingAs($user)->{$method}(route($routeName, $params));
}

function roleMatrixAllows(string $role, string $required): bool
{
    if ($role === 'stranger') {
        return false;
    }
    if ($required === 'member') {
        return true;
    }

    $level = match ($role) {
        'viewer' => ROLE_MATRIX_LEVELS['view'],
        'editor' => ROLE_MATRIX_LEVELS['edit'],
        'co_owner' => ROLE_MATRIX_LEVELS['owner'],
        'owner' => ROLE_MATRIX_LEVELS['primary'],
    };

    return $level >= ROLE_MATRIX_LEVELS[$required];
}

dataset('role_matrix_routes', array_combine(array_keys(ROLE_MATRIX_ROUTES), array_map(
    fn (string $name, string $required) => [$name, $required],
    array_keys(ROLE_MATRIX_ROUTES),
    ROLE_MATRIX_ROUTES,
)));

test('each budget route enforces its role requirement', function (string $role, string $routeName, string $required) {
    if (! Route::has($routeName)) {
        $this->markTestSkipped("{$routeName} no longer exists");
    }

    $status = roleMatrixRequest($routeName, $this->members[$role])->getStatusCode();

    if (roleMatrixAllows($role, $required)) {
        expect($status)->not->toBe(403, "{$role} should be allowed on {$routeName} ({$required})");
    } else {
        expect($status)->toBe(403, "{$role} should be denied on {$routeName} ({$required})");
    }
})->with(['viewer', 'editor', 'co_owner', 'owner', 'stranger'])->with('role_matrix_routes');

test('every budget scoped route is classified in the role matrix', function () {
    $unclassified = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->getName() !== null
            && collect(['budget', 'transaction', 'attachment', 'connection'])->intersect($route->parameterNames())->isNotEmpty())
        ->map(fn ($route) => $route->getName())
        ->reject(fn ($name) => array_key_exists($name, ROLE_MATRIX_ROUTES))
        ->values()
        ->all();

    expect($unclassified)->toBe([]);
});

test('a viewer cannot change any budget data through a write route', function () {
    $viewer = $this->members['viewer'];
    $before = [
        Transaction::count(), Account::count(), Category::count(), Transfer::count(),
        RecurringTransactionTemplate::count(), Scenario::count(), PayoffPlan::count(), FileAttachment::count(),
    ];

    foreach (ROLE_MATRIX_ROUTES as $routeName => $required) {
        if (in_array($required, ['view', 'member'], true) || ! Route::has($routeName)) {
            continue;
        }
        roleMatrixRequest($routeName, $viewer);
    }

    expect([
        Transaction::count(), Account::count(), Category::count(), Transfer::count(),
        RecurringTransactionTemplate::count(), Scenario::count(), PayoffPlan::count(), FileAttachment::count(),
    ])->toBe($before)
        ->and($this->budget->fresh())->not->toBeNull()
        ->and($this->plaid->requests)->toBe([]);
});
