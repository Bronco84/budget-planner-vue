<template>
  <Head title="Edit Property" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        Edit Property - {{ budget.name }}
      </h2>
    </template>

    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <form @submit.prevent="submit" class="p-6 space-y-6">
            <!-- Validation Errors -->
            <div v-if="Object.keys(form.errors).length > 0" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
                    There were errors with your submission
                  </h3>
                  <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                    <ul class="list-disc list-inside space-y-1">
                      <li v-for="(error, field) in form.errors" :key="field">
                        {{ error }}
                      </li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>

            <!-- Property Type -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Property Type
              </label>
              <div class="grid grid-cols-3 gap-4">
                <label
                  v-for="type in propertyTypes"
                  :key="type.value"
                  class="relative flex cursor-pointer rounded-lg border p-4 focus:outline-none"
                  :class="form.type === type.value ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-300 dark:border-gray-600'"
                >
                  <input
                    type="radio"
                    v-model="form.type"
                    :value="type.value"
                    class="sr-only"
                  />
                  <div class="flex flex-col items-center w-full">
                    <component :is="type.icon" class="w-8 h-8 mb-2" :class="form.type === type.value ? 'text-indigo-600' : 'text-gray-400'" />
                    <span class="text-sm font-medium" :class="form.type === type.value ? 'text-indigo-600' : 'text-gray-900 dark:text-gray-100'">
                      {{ type.label }}
                    </span>
                  </div>
                </label>
              </div>
              <div v-if="form.errors.type" class="mt-2 text-sm text-red-600 dark:text-red-400">
                {{ form.errors.type }}
              </div>
            </div>

            <!-- Name -->
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                Name
              </label>
              <input
                id="name"
                v-model="form.name"
                type="text"
                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                placeholder="e.g., Main Home, 2015 Honda Civic"
              />
              <div v-if="form.errors.name" class="mt-2 text-sm text-red-600 dark:text-red-400">
                {{ form.errors.name }}
              </div>
            </div>

            <!-- Current Value -->
            <div>
              <label for="current_value" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                Current Value
              </label>
              <div class="mt-1 relative rounded-md shadow-sm">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                  <span class="text-gray-500 sm:text-sm">$</span>
                </div>
                <input
                  id="current_value"
                  v-model="currentValueDollars"
                  type="number"
                  step="0.01"
                  class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 pl-7 focus:border-indigo-500 focus:ring-indigo-500"
                  placeholder="0.00"
                />
              </div>
              <div v-if="form.errors.current_value_cents" class="mt-2 text-sm text-red-600 dark:text-red-400">
                {{ form.errors.current_value_cents }}
              </div>
            </div>

            <!-- Property Fields -->
            <div v-if="form.type === 'property'" class="space-y-4 border-t border-gray-200 dark:border-gray-700 pt-4">
              <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Property Details</h3>
              
              <div>
                <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                  Address
                </label>
                <textarea
                  id="address"
                  v-model="form.address"
                  rows="2"
                  class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
              </div>

              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label for="bedrooms" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Bedrooms
                  </label>
                  <input
                    id="bedrooms"
                    v-model.number="form.bedrooms"
                    type="number"
                    min="0"
                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>

                <div>
                  <label for="bathrooms" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Bathrooms
                  </label>
                  <input
                    id="bathrooms"
                    v-model.number="form.bathrooms"
                    type="number"
                    min="0"
                    step="0.5"
                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>
              </div>
            </div>

            <!-- Vehicle Fields -->
            <div v-if="form.type === 'vehicle'" class="space-y-4 border-t border-gray-200 dark:border-gray-700 pt-4">
              <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Vehicle Details</h3>
              
              <div class="grid grid-cols-3 gap-4">
                <div>
                  <label for="vehicle_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Year
                  </label>
                  <input
                    id="vehicle_year"
                    v-model.number="form.vehicle_year"
                    type="number"
                    min="1900"
                    :max="new Date().getFullYear() + 1"
                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>

                <div>
                  <label for="vehicle_make" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Make
                  </label>
                  <input
                    id="vehicle_make"
                    v-model="form.vehicle_make"
                    type="text"
                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>

                <div>
                  <label for="vehicle_model" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Model
                  </label>
                  <input
                    id="vehicle_model"
                    v-model="form.vehicle_model"
                    type="text"
                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>
              </div>

              <div>
                <label for="mileage" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                  Mileage
                </label>
                <input
                  id="mileage"
                  v-model.number="form.mileage"
                  type="number"
                  min="0"
                  class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
              </div>
            </div>

            <!-- Link to Loan Accounts -->
            <div v-if="liabilityAccounts.length > 0" class="border-t border-gray-200 dark:border-gray-700 pt-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Linked Loan Accounts (Optional)
              </label>
              <div class="space-y-2">
                <label v-for="account in liabilityAccounts" :key="account.id" class="flex items-center">
                  <input
                    type="checkbox"
                    :value="account.id"
                    v-model="form.linked_account_ids"
                    class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                  />
                  <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                    {{ account.name }} ({{ formatCurrency(account.current_balance_cents) }})
                  </span>
                </label>
              </div>
              <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Link this property to mortgage or loan accounts to calculate equity
              </p>
            </div>

            <!-- Notes -->
            <div>
              <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                Notes (Optional)
              </label>
              <textarea
                id="notes"
                v-model="form.notes"
                rows="3"
                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>

            <!-- Actions -->
            <div class="flex justify-end gap-4">
              <Link
                :href="route('budgets.propertys.index', budget.id)"
                class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
              >
                Cancel
              </Link>
              <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                :class="{ 'opacity-25': form.processing }"
              >
                Update Property
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { HomeIcon, TruckIcon, CubeIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
  budget: Object,
  property: Object,
  liabilityAccounts: Array,
  propertyTypes: Object,
});

const propertyTypes = [
  { value: 'property', label: 'Property', icon: HomeIcon },
  { value: 'vehicle', label: 'Vehicle', icon: TruckIcon },
  { value: 'other', label: 'Other', icon: CubeIcon },
];

const form = useForm({
  name: props.property.name,
  type: props.property.type,
  current_value_cents: props.property.current_value_cents,
  address: props.property.address || '',
  property_type: props.property.property_type,
  bedrooms: props.property.bedrooms,
  bathrooms: props.property.bathrooms,
  square_feet: props.property.square_feet,
  year_built: props.property.year_built,
  vehicle_make: props.property.vehicle_make || '',
  vehicle_model: props.property.vehicle_model || '',
  vehicle_year: props.property.vehicle_year,
  vin: props.property.vin || '',
  mileage: props.property.mileage,
  notes: props.property.notes || '',
  linked_account_ids: props.property.linked_accounts?.map(a => a.id) || [],
});

const currentValueDollars = computed({
  get: () => form.current_value_cents / 100,
  set: (value) => {
    form.current_value_cents = Math.round(parseFloat(value || 0) * 100);
  },
});

const formatCurrency = (cents) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(cents / 100);
};

const submit = () => {
  form.put(route('budgets.propertys.update', [props.budget.id, props.property.id]));
};
</script>

