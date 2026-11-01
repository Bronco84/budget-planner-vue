<?php

namespace App\Rules;

use App\Models\RecurringTransactionRule;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects regex rule values that preg_match cannot compile.
 *
 * Applied to a recurring transaction rule's `value`, either top level
 * (`value`) or inside an array of rules (`rules.0.value`). The sibling
 * `operator` and `is_case_sensitive` inputs are read from the same level, and
 * values for any operator other than regex pass untouched. The pattern is
 * compiled exactly as RecurringTransactionRule::matchesTransaction() uses it.
 */
class ValidRegexRulePattern implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $prefix = str_contains($attribute, '.') ? substr($attribute, 0, strrpos($attribute, '.') + 1) : '';

        if (data_get($this->data, $prefix.'operator') !== RecurringTransactionRule::OPERATOR_REGEX || ! is_string($value)) {
            return;
        }

        $caseSensitive = filter_var(data_get($this->data, $prefix.'is_case_sensitive', false), FILTER_VALIDATE_BOOLEAN);
        $pattern = $caseSensitive ? $value : strtolower($value);

        if (@preg_match($pattern, '') === false) {
            $fail('The :attribute must be a valid regular expression including delimiters, e.g. /^netflix/.');
        }
    }
}
