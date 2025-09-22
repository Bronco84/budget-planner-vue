<?php

namespace App\Services\BankFeed;

use App\Models\BankFeed;
use Carbon\Carbon;

interface BankFeedImportInterface
{
    /**
     * Connect to the bank feed source with provided credentials.
     *
     * @param array $credentials
     * @return array Returns connection result with success status and config
     */
    public function connect(array $credentials): array;

    /**
     * Test the connection to verify it's working.
     *
     * @param BankFeed $bankFeed
     * @return array Returns test result with success status
     */
    public function testConnection(BankFeed $bankFeed): array;

    /**
     * Get transactions from the bank feed source.
     *
     * @param BankFeed $bankFeed
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array Returns array of normalized transaction data
     */
    public function getTransactions(BankFeed $bankFeed, Carbon $startDate, Carbon $endDate): array;

    /**
     * Update account balance from the bank feed source.
     *
     * @param BankFeed $bankFeed
     * @return array Returns balance data (current_balance_cents, available_balance_cents)
     */
    public function updateBalance(BankFeed $bankFeed): array;

    /**
     * Disconnect from the bank feed source.
     *
     * @param BankFeed $bankFeed
     * @return bool Returns true if disconnected successfully
     */
    public function disconnect(BankFeed $bankFeed): bool;

    /**
     * Get the display name for this import source.
     *
     * @return string
     */
    public function getDisplayName(): string;

    /**
     * Get the configuration fields required for connection.
     *
     * @return array Returns array of field definitions
     */
    public function getConfigFields(): array;

    /**
     * Validate the provided credentials/config.
     *
     * @param array $credentials
     * @return array Returns validation result
     */
    public function validateCredentials(array $credentials): array;

    /**
     * Normalize transaction data from the source into a standard format.
     *
     * @param array $sourceTransaction
     * @param BankFeed $bankFeed
     * @return array Returns normalized transaction data
     */
    public function normalizeTransaction(array $sourceTransaction, BankFeed $bankFeed): array;
}
