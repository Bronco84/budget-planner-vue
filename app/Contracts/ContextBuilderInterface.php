<?php

namespace App\Contracts;

use App\Models\Budget;
use App\Models\User;

interface ContextBuilderInterface
{
    /**
     * Build context data for this module.
     *
     * @param User $user The authenticated user
     * @param Budget $budget The active budget
     * @param array $options Additional options for building context
     * @return array The context data
     */
    public function build(User $user, Budget $budget, array $options = []): array;

    /**
     * Get the context type identifier this builder handles.
     *
     * @return string|array The context type(s) this builder handles
     */
    public function getContextType(): string|array;

    /**
     * Estimate token count for this context.
     *
     * @param Budget $budget The budget to estimate for
     * @return int Estimated token count
     */
    public function getTokenEstimate(Budget $budget): int;
}
