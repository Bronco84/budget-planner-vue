<template>
  <Head :title="'Budget Categories' + budget.name" />

  <AuthenticatedLayout>
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <!-- Summary Card -->
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <div class="text-sm text-gray-500 dark:text-gray-400">Total Allocated</div>
              <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ formatCurrency(summary.total_allocated * 100) }}
              </div>
            </div>
            <div>
              <div class="text-sm text-gray-500 dark:text-gray-400">Total Spent</div>
              <div class="text-2xl font-bold text-red-600 dark:text-red-400">
                {{ formatCurrency(summary.total_spent_cents) }}
              </div>
            </div>
            <div>
              <div class="text-sm text-gray-500 dark:text-gray-400">Remaining</div>
              <div class="text-2xl font-bold" :class="summary.total_remaining_cents >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                {{ formatCurrency(summary.total_remaining_cents) }}
              </div>
            </div>
            <div>
              <div class="text-sm text-gray-500 dark:text-gray-400">Categories</div>
              <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ summary.category_count }}
              </div>
            </div>
          </div>
        </div>

        <!-- Create Button -->
        <div class="flex justify-end">
          <button
            @click="showCreateModal = true"
            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Create Category
          </button>
        </div>

        <!-- Categories List -->
        <div v-if="categories.length > 0" class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="space-y-4">
              <div
                v-for="category in categories"
                :key="category.id"
                class="border dark:border-gray-700 rounded-lg p-4 hover:shadow-md transition-shadow"
              >
                <div class="flex items-start justify-between">
                  <div class="flex-1">
                    <div class="flex items-center space-x-3">
                      <!-- Color Indicator -->
                      <div
                        v-if="category.color"
                        class="w-4 h-4 rounded-full"
                        :style="{ backgroundColor: category.color }"
                      ></div>
                      <!-- Category Name -->
                      <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                        {{ category.name }}
                      </h3>
                    </div>

                    <!-- Description -->
                    <p v-if="category.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                      {{ category.description }}
                    </p>

                    <!-- Budget Details -->
                    <div class="mt-3 grid grid-cols-3 gap-4">
                      <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Allocated</div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                          {{ formatCurrency(category.amount * 100) }}
                        </div>
                      </div>
                      <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Spent</div>
                        <div class="text-sm font-medium text-red-600 dark:text-red-400">
                          {{ formatCurrency(category.spent_cents) }}
                        </div>
                      </div>
                      <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Remaining</div>
                        <div class="text-sm font-medium" :class="category.remaining_cents >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                          {{ formatCurrency(category.remaining_cents) }}
                        </div>
                      </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="mt-3">
                      <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mb-1">
                        <span>{{ Math.round(category.percent_used) }}% Used</span>
                      </div>
                      <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                        <div
                          class="h-2 rounded-full transition-all"
                          :class="category.percent_used > 100 ? 'bg-red-600' : category.percent_used > 80 ? 'bg-yellow-500' : 'bg-green-600'"
                          :style="{ width: Math.min(category.percent_used, 100) + '%' }"
                        ></div>
                      </div>
                    </div>
                  </div>

                  <!-- Action Buttons -->
                  <div class="flex space-x-2 ml-4">
                    <button
                      @click="editCategory(category)"
                      class="p-2 text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                      title="Edit"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                    </button>
                    <button
                      @click="confirmDelete(category)"
                      class="p-2 text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                      title="Delete"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div v-else class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-12 text-center">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No categories</h3>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Get started by creating your first budget category.
          </p>
          <div class="mt-6">
            <button
              @click="showCreateModal = true"
              class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500"
            >
              Create Category
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modals -->
    <CreateCategoryModal
      :show="showCreateModal"
      :budget="budget"
      @close="showCreateModal = false"
    />

    <EditCategoryModal
      :show="showEditModal"
      :budget="budget"
      :category="editingCategory"
      @close="showEditModal = false"
    />

    <DeleteConfirmationModal
      :show="showDeleteModal"
      :category="deletingCategory"
      :budget="budget"
      @close="showDeleteModal = false"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CreateCategoryModal from './Partials/CreateCategoryModal.vue';
import EditCategoryModal from './Partials/EditCategoryModal.vue';
import DeleteConfirmationModal from './Partials/DeleteConfirmationModal.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  categories: Array,
  summary: Object,
});

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const editingCategory = ref(null);
const deletingCategory = ref(null);

function editCategory(category) {
  editingCategory.value = category;
  showEditModal.value = true;
}

function confirmDelete(category) {
  deletingCategory.value = category;
  showDeleteModal.value = true;
}
</script>
