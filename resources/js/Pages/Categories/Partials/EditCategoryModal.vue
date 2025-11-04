<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
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

const form = useForm({
    name: '',
    amount: '',
    color: '#3b82f6',
    description: '',
});

// Populate form when category changes
watch(() => props.category, (category) => {
    if (category) {
        form.name = category.name || '';
        form.amount = category.amount || '';
        form.color = category.color || '#3b82f6';
        form.description = category.description || '';
    }
}, { immediate: true });

const closeModal = () => {
    form.clearErrors();
    emit('close');
};

const submit = () => {
    if (!props.category) return;

    form.patch(route('budgets.categories.update', [props.budget.id, props.category.id]), {
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
                Edit Budget Category
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Update the category details for <span class="font-medium">{{ category?.name }}</span>
            </p>

            <form @submit.prevent="submit" class="mt-6">
                <div class="space-y-4">
                    <!-- Name -->
                    <div>
                        <InputLabel for="edit-name" value="Category Name" />
                        <TextInput
                            id="edit-name"
                            v-model="form.name"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="e.g., Food & Dining"
                            required
                            autofocus
                        />
                        <InputError :message="form.errors.name" class="mt-2" />
                    </div>

                    <!-- Amount -->
                    <div>
                        <InputLabel for="edit-amount" value="Allocated Amount" />
                        <div class="mt-1 relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
                            </div>
                            <TextInput
                                id="edit-amount"
                                v-model="form.amount"
                                type="number"
                                step="0.01"
                                min="0"
                                class="pl-7 block w-full"
                                placeholder="0.00"
                                required
                            />
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            The amount you plan to allocate to this category
                        </p>
                        <InputError :message="form.errors.amount" class="mt-2" />
                    </div>

                    <!-- Color -->
                    <div>
                        <InputLabel for="edit-color" value="Color (Optional)" />
                        <div class="mt-1 flex items-center gap-3">
                            <input
                                id="edit-color"
                                v-model="form.color"
                                type="color"
                                class="h-10 w-20 cursor-pointer rounded border border-gray-300 dark:border-gray-600"
                            />
                            <TextInput
                                v-model="form.color"
                                type="text"
                                class="flex-1"
                                placeholder="#3b82f6"
                                pattern="^#[0-9A-Fa-f]{6}$"
                            />
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Choose a color to help identify this category
                        </p>
                        <InputError :message="form.errors.color" class="mt-2" />
                    </div>

                    <!-- Description -->
                    <div>
                        <InputLabel for="edit-description" value="Description (Optional)" />
                        <textarea
                            id="edit-description"
                            v-model="form.description"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                            rows="3"
                            placeholder="Add any notes about this category..."
                        ></textarea>
                        <InputError :message="form.errors.description" class="mt-2" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeModal" type="button">
                        Cancel
                    </SecondaryButton>

                    <PrimaryButton :disabled="form.processing">
                        Update Category
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
