<script setup>
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    budget: {
        type: Object,
        required: true,
    },
    category: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const form = useForm({});

const closeModal = () => {
    form.clearErrors();
    emit('close');
};

const deleteCategory = () => {
    if (!props.category) return;

    form.delete(route('budgets.categories.destroy', [props.budget.id, props.category.id]), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        },
    });
};
</script>

<template>
    <Modal :show="show" @close="closeModal" max-width="lg">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Delete Category
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Are you sure you want to delete the category
                <span class="font-semibold">{{ category?.name }}</span>?
            </p>

            <div class="mt-3 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-md">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700 dark:text-yellow-200">
                            If this category has transactions assigned to it, deletion will be prevented.
                            You'll need to reassign or remove those transactions first.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="closeModal" type="button">
                    Cancel
                </SecondaryButton>

                <DangerButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                    @click="deleteCategory"
                >
                    Delete Category
                </DangerButton>
            </div>
        </div>
    </Modal>
</template>
