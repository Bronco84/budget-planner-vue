<?php

namespace Tests\Support;

use App\Services\PlaidService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;

/**
 * Fakes the Plaid API at the Guzzle boundary for feature tests.
 *
 * PlaidService talks to Plaid through its own Guzzle client (not Laravel's HTTP
 * client), so Http::fake() cannot intercept it. install() builds a real, configured
 * PlaidService, swaps its client for one backed by an in-memory handler, and binds
 * that instance into the container. The handler never opens a socket: each request
 * is answered from the stubs registered with on(), and any path without a stub gets
 * a 500 and is recorded in $unexpected so tests can assert nothing else was called.
 */
final class FakePlaidApi
{
    public const TIMEOUT = '__connect_timeout__';

    /** @var array<string, mixed> */
    private array $stubs = [];

    /** @var list<array{path: string, body: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<string> */
    public array $unexpected = [];

    public PlaidService $service;

    /**
     * @param  bool  $configured  false simulates missing PLAID_CLIENT_ID / PLAID_SECRET
     */
    public static function install(bool $configured = true): self
    {
        config([
            'services.plaid.client_id' => $configured ? 'test-client-id' : null,
            'services.plaid.secret' => $configured ? 'test-secret' : null,
            'services.plaid.environment' => 'sandbox',
            'services.plaid.proxy' => null,
        ]);

        $fake = new self;
        $fake->service = new PlaidService;

        $client = new Client([
            'base_uri' => 'https://sandbox.plaid.com',
            'handler' => HandlerStack::create(fn (RequestInterface $request, array $options) => $fake->handle($request)),
            'http_errors' => true,
        ]);
        (new ReflectionProperty(PlaidService::class, 'client'))->setValue($fake->service, $client);

        app()->instance(PlaidService::class, $fake->service);

        return $fake;
    }

    /**
     * Stub a Plaid endpoint. $response may be an array (200 JSON body), a Response,
     * self::TIMEOUT (connect timeout), or a callable receiving the decoded request body.
     */
    public function on(string $path, mixed $response): self
    {
        $this->stubs[$path] = $response;

        return $this;
    }

    /**
     * A Plaid-style API error response.
     */
    public static function error(string $code, string $message = 'error', int $status = 400): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'error_type' => 'API_ERROR',
            'error_code' => $code,
            'error_message' => $message,
            'display_message' => null,
        ]));
    }

    /**
     * A /transactions/get stub that pages like Plaid: it honours options.count and
     * options.offset (Plaid defaults count to 100) and reports total_transactions.
     *
     * @param  list<array<string, mixed>>  $transactions
     */
    public static function pagedTransactions(array $transactions): callable
    {
        return function (array $body) use ($transactions) {
            $count = $body['options']['count'] ?? 100;
            $offset = $body['options']['offset'] ?? 0;

            return [
                'transactions' => array_slice($transactions, $offset, $count),
                'total_transactions' => count($transactions),
            ];
        };
    }

    /**
     * @return list<array<string, mixed>> the bodies of every request to $path, in order
     */
    public function bodiesFor(string $path): array
    {
        return array_values(array_map(
            fn (array $request) => $request['body'],
            array_filter($this->requests, fn (array $request) => $request['path'] === $path)
        ));
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_column($this->requests, 'path');
    }

    /**
     * @return array<string, mixed>|null the body of the first request to $path
     */
    public function bodyFor(string $path): ?array
    {
        foreach ($this->requests as $request) {
            if ($request['path'] === $path) {
                return $request['body'];
            }
        }

        return null;
    }

    private function handle(RequestInterface $request)
    {
        $path = $request->getUri()->getPath();
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $this->requests[] = ['path' => $path, 'body' => $body];

        if (! array_key_exists($path, $this->stubs)) {
            $this->unexpected[] = $path;

            return Create::promiseFor(self::error('UNEXPECTED_TEST_CALL', "No fake registered for {$path}", 500));
        }

        $stub = $this->stubs[$path];
        if (is_callable($stub)) {
            $stub = $stub($body);
        }

        if ($stub === self::TIMEOUT) {
            return Create::rejectionFor(new ConnectException(
                'cURL error 28: Connection timed out after 5001 milliseconds',
                $request
            ));
        }

        if (is_array($stub)) {
            $stub = new Response(200, ['Content-Type' => 'application/json'], json_encode($stub));
        }

        return Create::promiseFor($stub);
    }
}
