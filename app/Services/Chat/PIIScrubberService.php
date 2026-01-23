<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Log;

class PIIScrubberService
{
    /**
     * Keys that should be completely removed from context.
     */
    protected const KEYS_TO_REMOVE = [
        'email',
        'password',
        'password_hash',
        'remember_token',
        'api_key',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'plaid_access_token',
        'plaid_item_id',
        'plaid_account_id',
        'plaid_transaction_id',
        'plaid_entity_id',
        'bank_feed_transaction_id',
        'transfer_id',
        'external_id',
    ];

    /**
     * Keys that contain IDs which should be removed.
     */
    protected const ID_PATTERNS = [
        '/^.*_id$/',           // Any field ending in _id (except account_id, budget_id which are internal refs)
        '/^id$/',              // Direct id fields
        '/plaid_.*/',          // Any plaid-related fields
        '/^.*_token$/',        // Any token fields
        '/^.*_secret$/',       // Any secret fields
    ];

    /**
     * Keys that are safe to keep even if they match ID patterns.
     */
    protected const SAFE_KEYS = [
        'account_id',  // Internal reference, but we'll keep it for account linking context
        'budget_id',   // Internal reference
    ];

    /**
     * Scrub PII from context data.
     *
     * @param array $context The raw context data
     * @return array The scrubbed context data
     */
    public function scrub(array $context): array
    {
        return $this->scrubRecursive($context);
    }

    /**
     * Recursively scrub an array of PII.
     *
     * @param mixed $data The data to scrub
     * @param string $currentKey The current key being processed (for logging)
     * @return mixed The scrubbed data
     */
    protected function scrubRecursive(mixed $data, string $currentKey = ''): mixed
    {
        if (is_array($data)) {
            $scrubbed = [];
            foreach ($data as $key => $value) {
                // Skip keys that should be completely removed
                if ($this->shouldRemoveKey($key)) {
                    continue;
                }

                // Recursively scrub nested arrays
                $scrubbed[$key] = $this->scrubRecursive($value, (string) $key);
            }
            return $scrubbed;
        }

        // For scalar values, check if we need to scrub based on content
        if (is_string($data)) {
            return $this->scrubString($data, $currentKey);
        }

        return $data;
    }

    /**
     * Check if a key should be completely removed.
     *
     * @param string|int $key The key to check
     * @return bool True if the key should be removed
     */
    protected function shouldRemoveKey(string|int $key): bool
    {
        $keyStr = (string) $key;
        $keyLower = strtolower($keyStr);

        // Check explicit removal list
        if (in_array($keyLower, self::KEYS_TO_REMOVE, true)) {
            return true;
        }

        // Check if it's in the safe list
        if (in_array($keyLower, self::SAFE_KEYS, true)) {
            return false;
        }

        // Check against ID patterns
        foreach (self::ID_PATTERNS as $pattern) {
            if (preg_match($pattern, $keyLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scrub a string value for potential PII.
     *
     * @param string $value The string to scrub
     * @param string $key The key this value belongs to
     * @return string The scrubbed string
     */
    protected function scrubString(string $value, string $key): string
    {
        // Remove email addresses
        $value = preg_replace(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            '[EMAIL REDACTED]',
            $value
        );

        // Remove potential account numbers (sequences of 10+ digits)
        $value = preg_replace(
            '/\b\d{10,}\b/',
            '[ACCOUNT NUMBER REDACTED]',
            $value
        );

        // Remove credit card numbers (13-19 digits, possibly with spaces/dashes)
        $value = preg_replace(
            '/\b(?:\d[ -]*?){13,19}\b/',
            '[CARD NUMBER REDACTED]',
            $value
        );

        // Remove SSN patterns (XXX-XX-XXXX)
        $value = preg_replace(
            '/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/',
            '[SSN REDACTED]',
            $value
        );

        return $value;
    }

    /**
     * Extract only the first name from a full name.
     *
     * @param string|null $fullName The full name
     * @return string|null The first name only
     */
    public function extractFirstName(?string $fullName): ?string
    {
        if (!$fullName) {
            return null;
        }

        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? null;
    }

    /**
     * Prepare user context with PII removed.
     *
     * @param array $userContext Raw user context
     * @return array Scrubbed user context
     */
    public function prepareUserContext(array $userContext): array
    {
        return [
            'first_name' => $this->extractFirstName($userContext['name'] ?? null),
        ];
    }
}
