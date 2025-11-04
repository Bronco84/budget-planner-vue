<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '../Modal.vue';
import InputLabel from '../InputLabel.vue';
import TextInput from '../TextInput.vue';
import SelectInput from '../SelectInput.vue';
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
const page = usePage();
const activeBudget = computed(() => page.props.activeBudget);
const accounts = computed(() => activeBudget.value?.accounts || []);

const form = useForm({
    account_id: '',
    description: '',
    category: '',
    amount: '',
    date: new Date().toISOString().split('T')[0],
});

const closeModal = () => {
    form.reset();
    form.clearErrors();
    emit('close');
};

const submit = () => {
    if (!activeBudget.value) return;

    form.post(route('budget.transaction.store', activeBudget.value.id), {
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
                Quick Add Transaction
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Add a transaction to <span class="font-medium">{{ activeBudget?.name }}</span>
            </p>

            <form @submit.prevent="submit" class="mt-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Account Selection -->
                    <div class="md:col-span-2">
                        <InputLabel for="account_id" value="Account" />
                        <SelectInput
                            id="account_id"
                            v-model="form.account_id"
                            class="mt-1 block w-full"
                            required
                        >
                            <option value="" disabled>Select an account</option>
                            <option v-for="account in accounts" :key="account.id" :value="account.id">
                                {{ account.name }}
                            </option>
                        </SelectInput>
                        <InputError :message="form.errors.account_id" class="mt-2" />
                    </div>

                    <!-- Description -->
                    <div class="md:col-span-2">
                        <InputLabel for="description" value="Description" />
                        <TextInput
                            id="description"
                            v-model="form.description"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="e.g., Grocery shopping"
                            required
                            autofocus
                        />
                        <InputError :message="form.errors.description" class="mt-2" />
                    </div>

                    <!-- Category -->
                    <div>
                        <InputLabel for="category" value="Category" />
                        <TextInput
                            id="category"
                            v-model="form.category"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="e.g., Food & Dining"
                            required
                        />
                        <InputError :message="form.errors.category" class="mt-2" />
                    </div>

                    <!-- Amount -->
                    <div>
                        <InputLabel for="amount" value="Amount" />
                        <div class="mt-1 relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
                            </div>
                            <TextInput
                                id="amount"
                                v-model="form.amount"
                                type="number"
                                step="0.01"
                                class="pl-7 block w-full"
                                placeholder="0.00"
                                required
                            />
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Use positive for income, negative for expenses (e.g., -50.00)
                        </p>
                        <InputError :message="form.errors.amount" class="mt-2" />
                    </div>

                    <!-- Date -->
                    <div class="md:col-span-2">
                        <InputLabel for="date" value="Date" />
                        <TextInput
                            id="date"
                            v-model="form.date"
                            type="date"
                            class="mt-1 block w-full"
                            required
                        />
                        <InputError :message="form.errors.date" class="mt-2" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeModal" type="button">
                        Cancel
                    </SecondaryButton>

                    <PrimaryButton :disabled="form.processing">
                        Add Transaction
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>