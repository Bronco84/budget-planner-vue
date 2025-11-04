<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Modal from '../Modal.vue';
import PrimaryButton from '../PrimaryButton.vue';
import SecondaryButton from '../SecondaryButton.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);
const page = usePage();
const activeBudget = computed(() => page.props.activeBudget);

const closeModal = () => {
    emit('close');
};

const goToFullForm = () => {
    if (activeBudget.value) {
        router.visit(route('recurring-transactions.create', activeBudget.value.id));
    }
    closeModal();
};
</script>

<template>
    <Modal :show="show" @close="closeModal" max-width="md">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Create Recurring Transaction
            </h2>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                Recurring transactions have many configuration options including frequency patterns, date ranges, and advanced rules.
            </p>

            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                Would you like to go to the full creation form?
            </p>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="closeModal" type="button">
                    Cancel
                </SecondaryButton>

                <PrimaryButton @click="goToFullForm">
                    Go to Form
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>