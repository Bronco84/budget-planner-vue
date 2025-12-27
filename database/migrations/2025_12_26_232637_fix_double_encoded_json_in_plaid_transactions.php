<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix double-encoded JSON in plaid_transactions table.
     *
     * The counterparties, location, payment_meta, personal_finance_category,
     * metadata, and original_data columns were being double-encoded because
     * the PlaidService was calling json_encode() AND the model's 'array' casts
     * were also encoding them.
     */
    public function up(): void
    {
        // Get all plaid transactions with potentially double-encoded JSON
        $transactions = DB::table('plaid_transactions')
            ->whereNotNull('counterparties')
            ->orWhereNotNull('location')
            ->orWhereNotNull('payment_meta')
            ->orWhereNotNull('personal_finance_category')
            ->orWhereNotNull('metadata')
            ->orWhereNotNull('original_data')
            ->get();

        foreach ($transactions as $transaction) {
            $updates = [];

            // Check and fix each JSON column
            $updates['counterparties'] = $this->fixDoubleEncodedJson($transaction->counterparties);
            $updates['location'] = $this->fixDoubleEncodedJson($transaction->location);
            $updates['payment_meta'] = $this->fixDoubleEncodedJson($transaction->payment_meta);
            $updates['personal_finance_category'] = $this->fixDoubleEncodedJson($transaction->personal_finance_category);
            $updates['metadata'] = $this->fixDoubleEncodedJson($transaction->metadata);
            $updates['original_data'] = $this->fixDoubleEncodedJson($transaction->original_data);

            // Only update if we actually fixed something
            $hasChanges = false;
            foreach ($updates as $column => $value) {
                if ($value !== $transaction->$column) {
                    $hasChanges = true;
                    break;
                }
            }

            if ($hasChanges) {
                DB::table('plaid_transactions')
                    ->where('id', $transaction->id)
                    ->update($updates);
            }
        }
    }

    /**
     * Fix double-encoded JSON by detecting and decoding if necessary.
     */
    private function fixDoubleEncodedJson(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Try to decode once
        $decoded = json_decode($value, true);

        // If the decoded value is a string, it was double-encoded
        // We need to decode again to get the actual array, then re-encode once
        if (is_string($decoded)) {
            $innerDecoded = json_decode($decoded, true);
            if (is_array($innerDecoded)) {
                return json_encode($innerDecoded);
            }
        }

        // If the first decode gave us an array, it was correctly encoded
        // Return as-is
        return $value;
    }

    /**
     * Reverse the migrations.
     * Note: This is a data-fix migration, so we don't reverse it.
     */
    public function down(): void
    {
        // Cannot reverse - this is a one-way data fix
    }
};
