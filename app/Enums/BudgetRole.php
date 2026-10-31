<?php

namespace App\Enums;

/**
 * A member's role on a shared budget, modelled on Google Drive / Notion sharing:
 *
 * - viewer: read-only access to the budget and its data
 * - editor: read/write access to budget data (transactions, accounts, categories, ...)
 * - owner:  full control — budget settings, deletion, sharing and bank connections
 *
 * The creator (budgets.user_id) is always the primary owner; other members are
 * rows in the budget_user pivot whose access_level holds one of these values.
 */
enum BudgetRole: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Owner = 'owner';

    /**
     * Resolve a stored pivot value. The legacy 'admin' level maps to owner;
     * anything unrecognised degrades to the least-privileged role.
     */
    public static function fromStored(?string $value): self
    {
        return match ($value) {
            'owner', 'admin' => self::Owner,
            'editor' => self::Editor,
            default => self::Viewer,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }

    public function canEdit(): bool
    {
        return $this !== self::Viewer;
    }

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
