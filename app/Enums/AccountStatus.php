<?php

namespace App\Enums;

enum AccountStatus: string
{
    case ACTIVE = 'active';
    case EXCLUDED = 'excluded';
    
    /**
     * Get the label for display
     */
    public function label(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::EXCLUDED => 'Excluded',
        };
    }
    
    /**
     * Get the CSS classes for display
     */
    public function classes(): string
    {
        return match($this) {
            self::ACTIVE => 'bg-green-100 text-green-800',
            self::EXCLUDED => 'bg-gray-100 text-gray-800',
        };
    }
    
    /**
     * Convert from include_in_budget boolean to AccountStatus
     */
    public static function fromIncludeInBudget(bool $includeInBudget): self
    {
        return $includeInBudget ? self::ACTIVE : self::EXCLUDED;
    }
    
    /**
     * Convert to include_in_budget boolean
     */
    public function toIncludeInBudget(): bool
    {
        return $this === self::ACTIVE;
    }
} 