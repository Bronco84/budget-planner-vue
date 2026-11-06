<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryController extends Controller
{
    public function __construct()
    {
        // Authorize budget ownership (BudgetPolicy view ability) for every action.
        $this->middleware('can:view,budget');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Budget $budget)
    {
        $categories = $budget->categories()->get();

        // Calculate total allocated
        $totalAllocated = $categories->sum('amount');

        // Spent per category, summed in SQL over the budget's expenses (all
        // time). Rows linked by category_id are grouped by id; unlinked legacy
        // rows fall back to their category string (see
        // Transaction::scopeInCategory). Pending rows the bank dropped do not
        // count, as in every other total.
        $expenses = fn () => $budget->transactions()
            ->countedInTotals()
            ->where('amount_in_cents', '<', 0)
            ->toBase();

        $spentById = $expenses()
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount_in_cents) as total')
            ->pluck('total', 'category_id');

        // Legacy names match trimmed and case-insensitively on every engine.
        $spentByLegacyName = Category::legacySpentByCategory($expenses(), $categories);

        $categoriesWithSpent = $categories->map(function ($category) use ($spentById, $spentByLegacyName) {
            $spent = abs((int) ($spentById[$category->id] ?? 0))
                + abs((int) ($spentByLegacyName[$category->id] ?? 0));

            return [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'amount' => $category->amount,
                'color' => $category->color,
                'order' => $category->order,
                'spent_cents' => $spent,
                'remaining_cents' => ($category->amount * 100) - $spent,
                'percent_used' => $category->amount > 0
                    ? ($spent / ($category->amount * 100)) * 100
                    : 0,
            ];
        });

        $totalSpent = $categoriesWithSpent->sum('spent_cents');

        return Inertia::render('Categories/Index', [
            'budget' => $budget,
            'categories' => $categoriesWithSpent,
            'summary' => [
                'total_allocated' => $totalAllocated,
                'total_spent_cents' => $totalSpent,
                'total_remaining_cents' => ($totalAllocated * 100) - $totalSpent,
                'category_count' => $categories->count(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Budget $budget)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'color' => 'nullable|string|max:7',
            'description' => 'nullable|string|max:1000',
        ]);

        // Get the highest order number and add 1
        $maxOrder = $budget->categories()->max('order') ?? 0;
        $validated['order'] = $maxOrder + 1;

        $budget->categories()->create($validated);

        return redirect()->back()->with('message', 'Category created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Budget $budget, Category $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'color' => 'nullable|string|max:7',
            'description' => 'nullable|string|max:1000',
        ]);

        $category->update($validated);

        return redirect()->back()->with('message', 'Category updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Budget $budget, Category $category)
    {
        if ($reason = $category->deletionBlockedReason()) {
            return redirect()->back()->with('error', $reason);
        }

        $category->delete();

        return redirect()->back()->with('message', 'Category deleted successfully.');
    }
}
