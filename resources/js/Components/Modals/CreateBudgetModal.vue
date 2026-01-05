<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../Modal.vue';
import InputLabel from '../InputLabel.vue';
import TextInput from '../TextInput.vue';
import TextArea from '../TextArea.vue';
import InputError from '../InputError.vue';
import PrimaryButton from '../PrimaryButton.vue';
import SecondaryButton from '../SecondaryButton.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const presetColors = [
    '#3b82f6', '#8b5cf6', '#ec4899', '#6366f1', '#06b6d4', '#14b8a6',
    '#10b981', '#22c55e', '#f59e0b', '#f97316', '#ef4444', '#f43f5e',
];

const getInitials = (name) => {
    if (!name) return '?';
    const words = name.trim().split(/\s+/);
    if (words.length === 1) {
        return words[0].substring(0, 2).toUpperCase();
    }
    return (words[0][0] + words[1][0]).toUpperCase();
};

const form = useForm({
    name: '',
    description: '',
    color: '#6366f1',
});

const closeModal = () => {
    form.reset();
    emit('close');
};

const submit = () => {
    form.post(route('budgets.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        },
    });
};
</script>

<template>
    <Modal :show="show" @close="closeModal" max-width="md">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Create New Budget
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Give your budget a name and optional description.
            </p>

            <form @submit.prevent="submit" class="mt-6">
                <div>
                    <InputLabel for="budget_name" value="Budget Name" />
                    <TextInput
                        id="budget_name"
                        v-model="form.name"
                        type="text"
                        class="mt-1 block w-full"
                        placeholder="e.g., Personal Budget 2025"
                        required
                        autofocus
                    />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>

                <div class="mt-4">
                    <InputLabel for="budget_description" value="Description (Optional)" />
                    <TextArea
                        id="budget_description"
                        v-model="form.description"
                        class="mt-1 block w-full"
                        rows="3"
                        placeholder="What is this budget for?"
                    />
                    <InputError :message="form.errors.description" class="mt-2" />
                </div>

                <div class="mt-4">
                    <InputLabel for="budget_color" value="Budget Color" />
                    <div class="mt-2 flex items-center gap-3">
                        <input
                            id="budget_color"
                            type="color"
                            v-model="form.color"
                            class="h-10 w-16 rounded border border-gray-300 dark:border-gray-600 cursor-pointer"
                        />
                        
                        <div 
                            class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold text-xs select-none"
                            :style="{ backgroundColor: form.color, lineHeight: 1 }"
                        >
                            {{ getInitials(form.name) }}
                        </div>

                        <div class="flex gap-1.5 flex-wrap flex-1">
                            <button
                                v-for="presetColor in presetColors"
                                :key="presetColor"
                                type="button"
                                @click="form.color = presetColor"
                                :class="[
                                    'w-6 h-6 rounded-full border-2 transition-all',
                                    form.color === presetColor ? 'border-gray-900 dark:border-white scale-110' : 'border-gray-300 dark:border-gray-600'
                                ]"
                                :style="{ backgroundColor: presetColor }"
                            ></button>
                        </div>
                    </div>
                    <InputError :message="form.errors.color" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeModal" type="button">
                        Cancel
                    </SecondaryButton>

                    <PrimaryButton :disabled="form.processing">
                        Create Budget
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>