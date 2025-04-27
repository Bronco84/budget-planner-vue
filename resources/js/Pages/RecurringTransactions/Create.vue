<template>
  <Head :title="budget.name + ' - Add Recurring Transaction'" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ budget.name }} - Add Recurring Transaction</h2>
        <Link 
          :href="route('recurring-transactions.index', budget.id)" 
          class="px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300"
        >
          Cancel
        </Link>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <form @submit.prevent="submit">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Description -->
                <div class="col-span-2">
                  <InputLabel for="description" value="Description" />
                  <TextInput
                    id="description"
                    type="text"
                    v-model="form.description"
                    class="mt-1 block w-full"
                    required
                    autofocus
                  />
                  <InputError :message="form.errors.description" class="mt-2" />
                </div>

                <!-- Amount -->
                <div>
                  <InputLabel for="amount" value="Amount" />
                  <div class="mt-1 relative rounded-md shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <span class="text-gray-500 sm:text-sm">$</span>
                    </div>
                    <TextInput
                      id="amount"
                      type="number"
                      step="0.01"
                      v-model="form.amount"
                      class="pl-7 block w-full"
                      placeholder="0.00"
                      required
                    />
                  </div>
                  <InputError :message="form.errors.amount" class="mt-2" />
                </div>

                <!-- Account -->
                <div>
                  <InputLabel for="account_id" value="Account" />
                  <select
                    id="account_id"
                    v-model="form.account_id"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                    required
                  >
                    <option value="">Select an account</option>
                    <option v-for="account in accounts" :key="account.id" :value="account.id">
                      {{ account.name }} (${{ (account.current_balance_cents / 100).toFixed(2) }})
                    </option>
                  </select>
                  <InputError :message="form.errors.account_id" class="mt-2" />
                </div>

                <!-- Category -->
                <div>
                  <InputLabel for="category" value="Category" />
                  <TextInput
                    id="category"
                    type="text"
                    v-model="form.category"
                    class="mt-1 block w-full"
                    required
                  />
                  <InputError :message="form.errors.category" class="mt-2" />
                </div>

                <!-- Frequency -->
                <div>
                  <InputLabel for="frequency" value="Frequency" />
                  <select
                    id="frequency"
                    v-model="form.frequency"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                    required
                  >
                    <option value="">Select frequency</option>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="biweekly">Bi-weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="yearly">Yearly</option>
                  </select>
                  <InputError :message="form.errors.frequency" class="mt-2" />
                </div>

                <!-- Day of Week (for weekly/biweekly) -->
                <div v-if="form.frequency === 'weekly' || form.frequency === 'biweekly'">
                  <InputLabel for="day_of_week" value="Day of Week" />
                  <select
                    id="day_of_week"
                    v-model="form.day_of_week"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                    required
                  >
                    <option value="">Select day</option>
                    <option value="0">Sunday</option>
                    <option value="1">Monday</option>
                    <option value="2">Tuesday</option>
                    <option value="3">Wednesday</option>
                    <option value="4">Thursday</option>
                    <option value="5">Friday</option>
                    <option value="6">Saturday</option>
                  </select>
                  <InputError :message="form.errors.day_of_week" class="mt-2" />
                </div>

                <!-- Day of Month (for monthly/quarterly) -->
                <div v-if="form.frequency === 'monthly' || form.frequency === 'quarterly'">
                  <InputLabel for="day_of_month" value="Day of Month" />
                  <select
                    id="day_of_month"
                    v-model="form.day_of_month"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                    required
                  >
                    <option value="">Select day</option>
                    <option v-for="day in 31" :key="day" :value="day">{{ day }}</option>
                  </select>
                  <InputError :message="form.errors.day_of_month" class="mt-2" />
                </div>

                <!-- Start Date -->
                <div>
                  <InputLabel for="start_date" value="Start Date" />
                  <TextInput
                    id="start_date"
                    type="date"
                    v-model="form.start_date"
                    class="mt-1 block w-full"
                    required
                  />
                  <InputError :message="form.errors.start_date" class="mt-2" />
                </div>

                <!-- End Date -->
                <div>
                  <InputLabel for="end_date" value="End Date (Optional)" />
                  <TextInput
                    id="end_date"
                    type="date"
                    v-model="form.end_date"
                    class="mt-1 block w-full"
                  />
                  <InputError :message="form.errors.end_date" class="mt-2" />
                </div>
              </div>

              <div class="flex items-center justify-end mt-6">
                <PrimaryButton class="ml-4" :disabled="form.processing">
                  Create Recurring Transaction
                </PrimaryButton>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
  budget: Object,
  accounts: Array,
});

const form = useForm({
  description: '',
  amount: '',
  account_id: '',
  category: '',
  frequency: '',
  start_date: '',
  end_date: '',
  day_of_week: '',
  day_of_month: '',
});

const submit = () => {
  form.post(route('recurring-transactions.store', props.budget.id));
};
</script> 