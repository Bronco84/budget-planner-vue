<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringTransactionRule extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'recurring_transaction_template_id',
        'field',
        'operator',
        'value',
        'is_case_sensitive',
        'priority',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_case_sensitive' => 'boolean',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Available fields for rules.
     */
    const FIELD_DESCRIPTION = 'description';
    const FIELD_AMOUNT = 'amount';
    const FIELD_CATEGORY = 'category';

    /**
     * Available operators for rules.
     */
    const OPERATOR_CONTAINS = 'contains';
    const OPERATOR_EQUALS = 'equals';
    const OPERATOR_STARTS_WITH = 'starts_with';
    const OPERATOR_ENDS_WITH = 'ends_with';
    const OPERATOR_REGEX = 'regex';
    const OPERATOR_GREATER_THAN = 'greater_than';
    const OPERATOR_LESS_THAN = 'less_than';

    /**
     * Get all available field options.
     *
     * @return array<string, string>
     */
    public static function getFieldOptions(): array
    {
        return [
            self::FIELD_DESCRIPTION => 'Description',
            self::FIELD_AMOUNT => 'Amount',
            self::FIELD_CATEGORY => 'Category',
        ];
    }

    /**
     * Get all available operator options.
     *
     * @return array<string, string>
     */
    public static function getOperatorOptions(): array
    {
        return [
            self::OPERATOR_CONTAINS => 'Contains',
            self::OPERATOR_EQUALS => 'Equals',
            self::OPERATOR_STARTS_WITH => 'Starts With',
            self::OPERATOR_ENDS_WITH => 'Ends With',
            self::OPERATOR_REGEX => 'Regular Expression',
            self::OPERATOR_GREATER_THAN => 'Greater Than',
            self::OPERATOR_LESS_THAN => 'Less Than',
        ];
    }

    /**
     * Get the recurring transaction template that this rule belongs to.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringTransactionTemplate::class, 'recurring_transaction_template_id');
    }

    /**
     * Check if a transaction matches this rule.
     *
     * @param \App\Models\Transaction|\App\Models\PlaidTransaction $transaction
     * @return bool
     */
    public function matchesTransaction($transaction): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $fieldValue = $this->getFieldValueFromTransaction($transaction);
        $ruleValue = $this->value;
        
        // If case sensitivity is off and we're dealing with strings, convert to lowercase
        if (!$this->is_case_sensitive && is_string($fieldValue) && is_string($ruleValue)) {
            $fieldValue = strtolower($fieldValue);
            $ruleValue = strtolower($ruleValue);
        }
        
        switch ($this->operator) {
            case self::OPERATOR_CONTAINS:
                return is_string($fieldValue) && strpos($fieldValue, $ruleValue) !== false;
                
            case self::OPERATOR_EQUALS:
                return $fieldValue == $ruleValue;
                
            case self::OPERATOR_STARTS_WITH:
                return is_string($fieldValue) && strpos($fieldValue, $ruleValue) === 0;
                
            case self::OPERATOR_ENDS_WITH:
                return is_string($fieldValue) && 
                    substr($fieldValue, -strlen($ruleValue)) === $ruleValue;
                
            case self::OPERATOR_REGEX:
                return is_string($fieldValue) && preg_match($ruleValue, $fieldValue);
                
            case self::OPERATOR_GREATER_THAN:
                return is_numeric($fieldValue) && $fieldValue > (float) $ruleValue;
                
            case self::OPERATOR_LESS_THAN:
                return is_numeric($fieldValue) && $fieldValue < (float) $ruleValue;
                
            default:
                return false;
        }
    }

    /**
     * Extract the appropriate field value from a transaction.
     *
     * @param \App\Models\Transaction|\App\Models\PlaidTransaction $transaction
     * @return mixed
     */
    protected function getFieldValueFromTransaction($transaction)
    {
        switch ($this->field) {
            case self::FIELD_DESCRIPTION:
                return $transaction instanceof Transaction 
                    ? $transaction->description 
                    : $transaction->name;
                
            case self::FIELD_AMOUNT:
                $amount = $transaction instanceof Transaction 
                    ? $transaction->amount_in_cents / 100
                    : $transaction->amount;
                
                return abs((float)$amount);
                
            case self::FIELD_CATEGORY:
                return $transaction->category;
                
            default:
                return null;
        }
    }
} 