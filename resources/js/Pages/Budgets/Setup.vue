<template>
  <Head :title="`Set up ${budget.name}`" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        Set up {{ budget.name }}
      </h2>
    </template>

    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <!-- Success message -->
            <div class="mb-8 text-center">
              <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 dark:bg-green-900/20 rounded-full mb-4">
                <svg class="w-8 h-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">
                Budget created successfully! 🎉
              </h1>
              <p class="text-gray-600 dark:text-gray-400">
                Now let's add your accounts to start tracking your finances.
              </p>
            </div>

            <!-- Setup options -->
            <div class="max-w-2xl mx-auto">
              <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-6 text-center">
                How would you like to set up your accounts?
              </h2>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <!-- Bank Connection Option -->
                <div
                  @click="connectBank"
                  class="relative group cursor-pointer border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 hover:border-indigo-500 dark:hover:border-indigo-400 transition-all duration-200 hover:shadow-lg"
                >
                  <div class="text-center">
                    <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-indigo-100 dark:bg-indigo-900/20 mb-4 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-900/30 transition-colors">
                      <svg class="h-8 w-8 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-4m-5 0H9m0 0H5m0 0h4M9 7h6m-6 4h6m-6 4h6" />
                      </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                      Connect Your Bank
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                      Securely import all your accounts with real balances and automatic transaction syncing.
                    </p>
                    <div class="text-xs text-gray-500 dark:text-gray-500 space-y-1">
                      <div class="flex items-center justify-center">
                        <svg class="w-3 h-3 text-green-500 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                        Real-time balances
                      </div>
                      <div class="flex items-center justify-center">
                        <svg class="w-3 h-3 text-green-500 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                        Automatic transaction sync
                      </div>
                      <div class="flex items-center justify-center">
                        <svg class="w-3 h-3 text-green-500 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                        Multiple accounts at once
                      </div>
                    </div>
                  </div>
                  <div class="absolute top-4 right-4">
                    <div class="bg-indigo-100 dark:bg-indigo-900/30 text-indigo-800 dark:text-indigo-300 text-xs font-medium px-2 py-1 rounded-full">
                      Recommended
                    </div>
                  </div>
                </div>

                <!-- Manual Account Option -->
                <div
                  @click="createManualAccount"
                  class="cursor-pointer border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 hover:border-gray-400 dark:hover:border-gray-500 transition-all duration-200 hover:shadow-md"
                >
                  <div class="text-center">
                    <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-gray-100 dark:bg-gray-700 mb-4">
                      <svg class="h-8 w-8 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6" />
                      </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                      Add Manually
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                      Create accounts yourself and enter transactions manually for full control.
                    </p>
                    <div class="text-xs text-gray-500 dark:text-gray-500 space-y-1">
                      <div class="flex items-center justify-center">
                        <svg class="w-3 h-3 text-blue-500 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                        Full control
                      </div>
                      <div class="flex items-center justify-center">
                        <svg class="w-3 h-3 text-blue-500 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                        Any account type
                      </div>
                      <div class="flex items-center justify-center">
                        <svg class="w-3 h-3 text-blue-500 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                        Privacy focused
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Skip option -->
              <div class="text-center pt-6 border-t border-gray-200 dark:border-gray-700">
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                  Want to explore the budget first?
                </p>
                <Link
                  :href="route('budgets.show', budget.id)"
                  class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-500 dark:hover:text-indigo-300 font-medium"
                >
                  Skip for now and explore your budget →
                </Link>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

// Props
const props = defineProps({
  budget: Object,
  linkToken: String,
});

// Methods
const connectBank = () => {
  router.visit(route('budgets.setup.connect', props.budget.id));
};

const createManualAccount = () => {
  router.visit(route('budgets.setup.manual', props.budget.id));
};
</script>