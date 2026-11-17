<?php

namespace Tests\Feature;

use App\Services\WalletLedger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.api_token' => 'test-only-token']);
    }

    public function test_credit_debit_and_balance_match_the_ledger(): void
    {
        $this->withToken('test-only-token')->postJson('/credit', ['user_id' => 'User_1', 'amount' => 10000, 'ignored' => true])
            ->assertOk()->assertExactJson(['user_id' => 'User_1', 'balance' => 10000]);
        $this->postJson('/debit', ['user_id' => 'User_1', 'amount' => 4000])
            ->assertOk()->assertExactJson(['user_id' => 'User_1', 'balance' => 6000]);
        $this->getJson('/balance/User_1')->assertOk()->assertExactJson(['user_id' => 'User_1', 'balance' => 6000]);
        $this->assertDatabaseHas('wallets', ['user_id' => 'User_1', 'balance' => 6000]);
        $this->assertDatabaseHas('ledger', ['user_id' => 'User_1', 'type' => 'credit', 'amount' => 10000]);
        $this->assertDatabaseHas('ledger', ['user_id' => 'User_1', 'type' => 'debit', 'amount' => 4000]);
        $this->assertDatabaseCount('ledger', 2);
    }

    public function test_returns_409_without_writes_for_insufficient_funds_and_allows_zero_balance(): void
    {
        $this->withToken('test-only-token')->postJson('/credit', ['user_id' => 'u', 'amount' => 100])->assertOk();

        $this->postJson('/debit', ['user_id' => 'u', 'amount' => 101])->assertConflict()
            ->assertExactJson(['error' => 'insufficient_funds', 'message' => 'Balance is insufficient for this debit.']);
        $this->assertDatabaseCount('ledger', 1);
        $this->assertDatabaseHas('wallets', ['user_id' => 'u', 'balance' => 100]);
        $this->postJson('/debit', ['user_id' => 'u', 'amount' => 100])->assertOk()->assertJsonPath('balance', 0);
        $this->getJson('/balance/u')->assertOk()->assertJsonPath('balance', 0);
    }

    public function test_returns_404_for_users_never_credited_and_preserves_case(): void
    {
        $this->withToken('test-only-token')->postJson('/credit', ['user_id' => 'Alice', 'amount' => 1])->assertOk();

        $this->getJson('/balance/alice')->assertNotFound()->assertExactJson(['error' => 'user_not_found', 'message' => 'User has never been credited.']);
        $this->postJson('/debit', ['user_id' => 'missing', 'amount' => 1])->assertNotFound()->assertJsonPath('error', 'user_not_found');
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('ledger', 1);
    }

    #[DataProvider('invalidBodies')]
    public function test_returns_400_for_invalid_json_input(string $body): void
    {
        $this->call('POST', '/credit', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-only-token'], content: $body)
            ->assertBadRequest()->assertJsonPath('error', 'invalid_request')->assertJsonStructure(['error', 'message']);
        $this->assertDatabaseCount('ledger', 0);
        $this->assertDatabaseCount('wallets', 0);
    }

    public static function invalidBodies(): array
    {
        return [
            'malformed' => ['{"user_id":'], 'array' => ['[]'], 'null' => ['null'],
            'empty object' => ['{}'], 'missing amount' => ['{"user_id":"u"}'],
            'missing user' => ['{"amount":1}'], 'zero' => ['{"user_id":"u","amount":0}'],
            'negative' => ['{"user_id":"u","amount":-1}'], 'too large' => ['{"user_id":"u","amount":1000000001}'],
            'float' => ['{"user_id":"u","amount":1.5}'], 'whole float' => ['{"user_id":"u","amount":1.0}'],
            'exponent' => ['{"user_id":"u","amount":1e2}'], 'string' => ['{"user_id":"u","amount":"10"}'],
            'boolean' => ['{"user_id":"u","amount":true}'], 'null amount' => ['{"user_id":"u","amount":null}'],
            'empty user' => ['{"user_id":"","amount":1}'], 'integer user' => ['{"user_id":123,"amount":1}'],
            'spaces' => ['{"user_id":" u ","amount":1}'], 'invalid punctuation' => ['{"user_id":"u!","amount":1}'],
            'unicode' => ['{"user_id":"?","amount":1}'], 'long user' => [json_encode(['user_id' => str_repeat('a', 65), 'amount' => 1])],
        ];
    }

    public function test_accepts_boundary_values_and_does_not_take_fields_from_query_string(): void
    {
        $userId = str_repeat('a', 64);
        $this->withToken('test-only-token')->postJson('/credit', ['user_id' => $userId, 'amount' => 1000000000])
            ->assertOk()->assertJsonPath('balance', 1000000000);
        $this->postJson('/credit', ['user_id' => $userId, 'amount' => 1000000000])->assertOk()->assertJsonPath('balance', 2000000000);
        $this->postJson('/credit', ['user_id' => $userId, 'amount' => 1000000000])->assertOk()->assertJsonPath('balance', 3000000000);
        $this->postJson('/credit?user_id=injected&amount=1', [])->assertBadRequest();
        $this->getJson('/balance/bad!')->assertBadRequest();
    }

    #[DataProvider('authenticationCases')]
    public function test_returns_401_before_database_access_for_invalid_authentication(string $method, string $uri, string $header): void
    {
        DB::purge();
        config(['database.connections.mysql.host' => '192.0.2.1', 'database.connections.mysql.options' => [\PDO::ATTR_TIMEOUT => 1]]);

        $this->call($method, $uri, server: ['HTTP_AUTHORIZATION' => $header], content: '{broken')
            ->assertUnauthorized()->assertExactJson(['error' => 'unauthorized', 'message' => 'A valid bearer token is required.']);
    }

    public static function authenticationCases(): array
    {
        $cases = [];
        foreach (['POST /credit', 'POST /debit', 'GET /balance/u', 'GET /unknown'] as $route) {
            [$method, $uri] = explode(' ', $route);
            foreach (['missing' => '', 'wrong scheme' => 'Basic test-only-token', 'wrong token' => 'Bearer wrong', 'extra text' => 'Bearer test-only-token extra'] as $label => $header) {
                $cases[$route.' '.$label] = [$method, $uri, $header];
            }
        }

        return $cases;
    }

    public function test_rolls_back_balance_and_new_user_if_ledger_insert_fails(): void
    {
        $ledger = app(WalletLedger::class);
        $ledger->credit('existing', 100);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into `ledger`')) {
                throw new \RuntimeException('Injected failure before commit');
            }
        });

        foreach (['existing', 'new'] as $userId) {
            try {
                $ledger->credit($userId, 50);
                $this->fail('Expected ledger insert to fail.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Injected failure', $exception->getMessage());
            }
        }
        $this->assertDatabaseHas('wallets', ['user_id' => 'existing', 'balance' => 100]);
        $this->assertDatabaseMissing('wallets', ['user_id' => 'new']);
        $this->assertDatabaseCount('ledger', 1);
    }

    public function test_ledger_entries_cannot_be_updated_or_deleted(): void
    {
        app(WalletLedger::class)->credit('immutable', 100);

        foreach (['UPDATE ledger SET amount = 1', 'DELETE FROM ledger'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail('Expected immutable ledger protection.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Ledger rows are immutable', $exception->getMessage());
            }
        }
        $this->assertDatabaseHas('ledger', ['user_id' => 'immutable', 'amount' => 100]);
    }
}
