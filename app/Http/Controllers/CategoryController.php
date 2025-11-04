<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Budget $budget)
    {
        $categories = $budget->categories()->with('expenses')->get();

        // Calculate total allocated
        $totalAllocated = $categories->sum('amount');

        // Get all transactions for the budget to calculate spent amounts
        $transactions = $budget->transactions()
            ->where('amount_in_cents', '<', 0) // Only expenses
            ->get();

        // Calculate spent for each category based on transactions
        $categoriesWithSpent = $categories->map(function ($category) use ($transactions) {
            $spent = $transactions->where('category', $category->name)
                ->sum(function ($transaction) {
                    return abs($transaction->amount_in_cents);
                });

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
        // Ensure the category belongs to the budget
        if ($category->budget_id !== $budget->id) {
            abort(403, 'Unauthorized action.');
        }

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
        // Ensure the category belongs to the budget
        if ($category->budget_id !== $budget->id) {
            abort(403, 'Unauthorized action.');
        }

        // Check if category has transactions
        $transactionCount = $budget->transactions()
            ->where('category', $category->name)
            ->count();

        if ($transactionCount > 0) {
            return redirect()->back()->with('error',
                "Cannot delete category '{$category->name}' because it has {$transactionCount} transaction(s) assigned to it.");
        }

        $category->delete();

        return redirect()->back()->with('message', 'Category deleted successfully.');
    }

    /**
     * Reorder categories.
     */
    public function reorder(Request $request, Budget $budget)
    {
        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.order' => 'required|integer|min:0',
        ]);

        foreach ($validated['categories'] as $categoryData) {
            $category = Category::find($categoryData['id']);

            // Ensure category belongs to this budget
            if ($category && $category->budget_id === $budget->id) {
                $category->update(['order' => $categoryData['order']]);
            }
        }

        return redirect()->back()->with('message', 'Category order updated successfully.');
    }
}
