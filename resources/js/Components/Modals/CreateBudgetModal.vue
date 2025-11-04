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

const form = useForm({
    name: '',
    description: '',
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