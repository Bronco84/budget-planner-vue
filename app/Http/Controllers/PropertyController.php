<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PropertyController extends Controller
{
    /**
     * Display a listing of properties for a budget.
     */
    public function index(Budget $budget): Response
    {
        $this->authorize('view', $budget);

        $properties = $budget->properties()
            ->with('linkedAccounts')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Properties/Index', [
            'budget' => $budget,
            'properties' => $properties,
        ]);
    }

    /**
     * Show the form for creating a new property.
     */
    public function create(Budget $budget): Response
    {
        $this->authorize('view', $budget);

        // Get liability accounts that could be linked to properties
        $liabilityAccounts = $budget->accounts()
            ->whereIn('type', ['mortgage', 'loan', 'line of credit'])
            ->whereNull('property_id')
            ->get();

        return Inertia::render('Properties/Create', [
            'budget' => $budget,
            'liabilityAccounts' => $liabilityAccounts,
            'propertyTypes' => Property::PROPERTY_TYPES,
        ]);
    }

    /**
     * Store a newly created asset in storage.
     */
    public function store(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('view', $budget);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:property,vehicle,other',
            'current_value_cents' => 'required|integer|min:0',
            'address' => 'nullable|string',
            'property_type' => 'nullable|string',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|numeric|min:0',
            'square_feet' => 'nullable|integer|min:0',
            'year_built' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'vehicle_make' => 'nullable|string|max:255',
            'vehicle_model' => 'nullable|string|max:255',
            'vehicle_year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'vin' => 'nullable|string|max:17',
            'mileage' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'linked_account_id' => 'nullable|exists:accounts,id',
        ]);

        $validated['value_updated_at'] = now();
        $validated['api_source'] = 'manual';

        $property = $budget->properties()->create($validated);

        // Link account to asset if specified
        if ($request->has('linked_account_id') && $request->linked_account_id) {
            $account = $budget->accounts()->find($request->linked_account_id);
            if ($account) {
                $account->update(['property_id' => $property->id]);
            }
        }

        return redirect()->route('budgets.assets.index', $budget)
            ->with('message', 'Asset created successfully');
    }

    /**
     * Display the specified asset.
     */
    public function show(Budget $budget, Property $property): Response
    {
        $this->authorize('view', $budget);

        if ($property->budget_id !== $budget->id) {
            abort(404);
        }

        $property->load('linkedAccounts');

        return Inertia::render('Properties/Show', [
            'budget' => $budget,
            'property' => $property,
        ]);
    }

    /**
     * Show the form for editing the specified asset.
     */
    public function edit(Budget $budget, Property $property): Response
    {
        $this->authorize('view', $budget);

        if ($property->budget_id !== $budget->id) {
            abort(404);
        }

        $property->load('linkedAccounts');

        // Get liability accounts that could be linked to this asset
        $liabilityAccounts = $budget->accounts()
            ->whereIn('type', ['mortgage', 'loan', 'line of credit'])
            ->where(function($query) use ($property) {
                $query->whereNull('property_id')
                    ->orWhere('property_id', $property->id);
            })
            ->get();

        return Inertia::render('Properties/Edit', [
            'budget' => $budget,
            'property' => $property,
            'liabilityAccounts' => $liabilityAccounts,
            'propertyTypes' => Property::PROPERTY_TYPES,
        ]);
    }

    /**
     * Update the specified asset in storage.
     */
    public function update(Request $request, Budget $budget, Property $property): RedirectResponse
    {
        $this->authorize('view', $budget);

        if ($property->budget_id !== $budget->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:property,vehicle,other',
            'current_value_cents' => 'required|integer|min:0',
            'address' => 'nullable|string',
            'property_type' => 'nullable|string',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|numeric|min:0',
            'square_feet' => 'nullable|integer|min:0',
            'year_built' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'vehicle_make' => 'nullable|string|max:255',
            'vehicle_model' => 'nullable|string|max:255',
            'vehicle_year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'vin' => 'nullable|string|max:17',
            'mileage' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'linked_account_ids' => 'nullable|array',
            'linked_account_ids.*' => 'exists:accounts,id',
        ]);

        // Update value timestamp if value changed
        if ($validated['current_value_cents'] !== $property->current_value_cents) {
            $validated['value_updated_at'] = now();
        }

        $property->update($validated);

        // Update linked accounts
        if ($request->has('linked_account_ids')) {
            // Clear existing links
            $budget->accounts()->where('property_id', $property->id)->update(['property_id' => null]);
            
            // Set new links
            if (!empty($request->linked_account_ids)) {
                $budget->accounts()
                    ->whereIn('id', $request->linked_account_ids)
                    ->update(['property_id' => $property->id]);
            }
        }

        return redirect()->route('budgets.assets.index', $budget)
            ->with('message', 'Asset updated successfully');
    }

    /**
     * Remove the specified asset from storage.
     */
    public function destroy(Budget $budget, Property $property): RedirectResponse
    {
        $this->authorize('view', $budget);

        if ($property->budget_id !== $budget->id) {
            abort(404);
        }

        // Unlink any accounts before deleting
        $budget->accounts()->where('property_id', $property->id)->update(['property_id' => null]);

        $property->delete();

        return redirect()->route('budgets.assets.index', $budget)
            ->with('message', 'Asset deleted successfully');
    }
}
