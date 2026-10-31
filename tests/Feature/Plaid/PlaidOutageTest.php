<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use App\Services\PlaidService;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-010: Plaid unavailable or misconfigured. Plaid is faked at the Guzzle
 * boundary by Tests\Support\FakePlaidApi; nothing here touches the network.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

/**
 * GET each connect page and assert it renders with no link token.
 */
function assertPlaidConnectPagesRenderWithoutLinkToken($test, Budget $budget, User $user): void
{
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);

    $test->actingAs($user)->get(route('plaid.discover', $budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Plaid/Discover')->where('linkToken', null));

    $test->actingAs($user)->get(route('plaid.link', [$budget, $account]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Plaid/Link')->where('linkToken', null)->where('isLinked', false));

    // Setup only renders for a budget with no accounts.
    $account->delete();
    $test->actingAs($user)->get(route('budgets.setup', $budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Budgets/Setup')->where('linkToken', null));
}

function plaidOutageImportPayload(): array
{
    return [
        'public_token' => 'public-sandbox-abc',
        'metadata' => ['institution' => ['name' => 'First Platypus Bank', 'institution_id' => 'ins_1']],
        'selected_accounts' => ['acc-checking'],
    ];
}

test('connect pages render without a link token when Plaid returns an error', function () {
    $plaid = FakePlaidApi::install()
        ->on('/link/token/create', FakePlaidApi::error('INTERNAL_SERVER_ERROR', 'an unexpected error occurred', 500));

    assertPlaidConnectPagesRenderWithoutLinkToken($this, $this->budget, $this->user);

    expect($plaid->paths())->toBe(['/link/token/create', '/link/token/create', '/link/token/create']);
});

test('unconfigured Plaid refuses link token creation without calling the API', function () {
    $plaid = FakePlaidApi::install(configured: false);

    expect($plaid->service->isConfigured())->toBeFalse();

    assertPlaidConnectPagesRenderWithoutLinkToken($this, $this->budget, $this->user);

    expect($plaid->requests)->toBe([]);
});

test('Plaid requests are bounded by connect and request timeouts', function () {
    config(['services.plaid.client_id' => 'id', 'services.plaid.secret' => 'secret', 'services.plaid.environment' => 'sandbox']);

    $client = (new ReflectionProperty(PlaidService::class, 'client'))->getValue(new PlaidService);

    expect($client->getConfig('connect_timeout'))->toBe(5)
        ->and($client->getConfig('timeout'))->toBe(30);
});

test('a connect timeout while creating a link token is logged with a friendly message', function () {
    FakePlaidApi::install()->on('/link/token/create', FakePlaidApi::TIMEOUT);
    Log::spy();

    $this->actingAs($this->user)->get(route('plaid.discover', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Plaid/Discover')->where('linkToken', null));

    Log::shouldHaveReceived('error')
        ->with('Failed to create link token for discovery', Mockery::on(
            fn (array $context) => str_starts_with($context['error'], 'Unable to connect to Plaid. Connection timed out.')
        ))
        ->once();
});

test('a failed token exchange on import redirects back with an error and no connection', function (string $failure) {
    $plaid = FakePlaidApi::install()->on('/item/public_token/exchange', match ($failure) {
        'api_error' => FakePlaidApi::error('INVALID_PUBLIC_TOKEN', 'provided public token is expired'),
        'timeout' => FakePlaidApi::TIMEOUT,
    });

    $this->actingAs($this->user)
        ->from(route('plaid.discover', $this->budget))
        ->post(route('plaid.import', $this->budget), plaidOutageImportPayload())
        ->assertRedirect(route('plaid.discover', $this->budget))
        ->assertSessionHas('error', fn (string $error) => str_starts_with($error, 'Failed to import accounts'));

    expect($plaid->paths())->toBe(['/item/public_token/exchange'])
        ->and(PlaidConnection::count())->toBe(0)
        ->and($this->budget->accounts()->count())->toBe(0);
})->with(['api_error', 'timeout']);

test('a failed token exchange when linking one account redirects back with an error and no connection', function (string $failure) {
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $plaid = FakePlaidApi::install()->on('/item/public_token/exchange', match ($failure) {
        'api_error' => FakePlaidApi::error('INVALID_PUBLIC_TOKEN', 'provided public token is expired'),
        'timeout' => FakePlaidApi::TIMEOUT,
    });

    $this->actingAs($this->user)
        ->from(route('plaid.link', [$this->budget, $account]))
        ->post(route('plaid.store', [$this->budget, $account]), [
            'public_token' => 'public-sandbox-abc',
            'metadata' => [
                'institution' => ['name' => 'First Platypus Bank'],
                'account' => ['id' => 'acc-checking'],
            ],
        ])
        ->assertRedirect(route('plaid.link', [$this->budget, $account]))
        ->assertSessionHas('error', fn (string $error) => str_starts_with($error, 'Failed to link account'));

    expect($plaid->paths())->toBe(['/item/public_token/exchange'])
        ->and(PlaidConnection::count())->toBe(0)
        ->and(PlaidAccount::where('account_id', $account->id)->exists())->toBeFalse();
})->with(['api_error', 'timeout']);
