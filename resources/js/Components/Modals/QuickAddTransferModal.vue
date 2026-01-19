<script setup>
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '../Modal.vue';
import InputLabel from '../InputLabel.vue';
import TextInput from '../TextInput.vue';
import SelectInput from '../SelectInput.vue';
import InputError from '../InputError.vue';
import PrimaryButton from '../PrimaryButton.vue';
import SecondaryButton from '../SecondaryButton.vue';
import { ArrowsRightLeftIcon } from '@heroicons/vue/24/outline';

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

// Get tomorrow's date as default (transfers should be future-dated)
const getTomorrowDate = () => {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    return tomorrow.toISOString().split('T')[0];
};

const form = useForm({
    from_account_id: '',
    to_account_id: '',
    amount: '',
    date: getTomorrowDate(),
    description: '',
    notes: '',
});

// Reset form when modal opens
watch(() => props.show, (newValue) => {
    if (newValue) {
        form.reset();
        form.date = getTomorrowDate();
        form.clearErrors();
    }
});

const closeModal = () => {
    form.reset();
    form.clearErrors();
    emit('close');
};

const submit = () => {
    if (!activeBudget.value) return;

    form.post(route('budget.transfers.store', activeBudget.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        },
    });
};

// Computed property for filtered "to" accounts (exclude selected "from" account)
const toAccountOptions = computed(() => {
    if (!form.from_account_id) return accounts.value;
    return accounts.value.filter(account => account.id !== parseInt(form.from_account_id));
});

// Computed property for filtered "from" accounts (exclude selected "to" account)
const fromAccountOptions = computed(() => {
    if (!form.to_account_id) return accounts.value;
    return accounts.value.filter(account => account.id !== parseInt(form.to_account_id));
});
</script>

<template>
    <Modal :show="show" @close="closeModal" max-width="lg">
        <div class="p-6">
            <div class="flex items-center gap-3 mb-1">
                <div class="p-2 bg-cyan-100 dark:bg-cyan-900 rounded-lg">
                    <ArrowsRightLeftIcon class="w-5 h-5 text-cyan-600 dark:text-cyan-400" />
                </div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    New Transfer
                </h2>
            </div>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Transfer funds between accounts in <span class="font-medium">{{ activeBudget?.name }}</span>
            </p>

            <form @submit.prevent="submit" class="mt-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- From Account -->
                    <div>
                        <InputLabel for="from_account_id" value="From Account" />
                        <SelectInput
                            id="from_account_id"
                            v-model="form.from_account_id"
                            class="mt-1 block w-full"
                            required
                        >
                            <option value="" disabled>Select source account</option>
                            <option v-for="account in fromAccountOptions" :key="account.id" :value="account.id">
                                {{ account.name }}
                            </option>
                        </SelectInput>
                        <InputError :message="form.errors.from_account_id" class="mt-2" />
                    </div>

                    <!-- To Account -->
                    <div>
                        <InputLabel for="to_account_id" value="To Account" />
                        <SelectInput
                            id="to_account_id"
                            v-model="form.to_account_id"
                            class="mt-1 block w-full"
                            required
                        >
                            <option value="" disabled>Select destination account</option>
                            <option v-for="account in toAccountOptions" :key="account.id" :value="account.id">
                                {{ account.name }}
                            </option>
                        </SelectInput>
                        <InputError :message="form.errors.to_account_id" class="mt-2" />
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
                                min="0.01"
                                class="pl-7 block w-full"
                                placeholder="0.00"
                                required
                            />
                        </div>
                        <InputError :message="form.errors.amount" class="mt-2" />
                    </div>

                    <!-- Date -->
                    <div>
                        <InputLabel for="date" value="Date" />
                        <TextInput
                            id="date"
                            v-model="form.date"
                            type="date"
                            class="mt-1 block w-full"
                            required
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Transfers appear in projected transactions
                        </p>
                        <InputError :message="form.errors.date" class="mt-2" />
                    </div>

                    <!-- Description -->
                    <div class="md:col-span-2">
                        <InputLabel for="description" value="Description (optional)" />
                        <TextInput
                            id="description"
                            v-model="form.description"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="e.g., Monthly savings transfer"
                        />
                        <InputError :message="form.errors.description" class="mt-2" />
                    </div>

                    <!-- Notes -->
                    <div class="md:col-span-2">
                        <InputLabel for="notes" value="Notes (optional)" />
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                            rows="2"
                            placeholder="Additional notes..."
                        ></textarea>
                        <InputError :message="form.errors.notes" class="mt-2" />
                    </div>
                </div>

                <!-- Transfer Preview -->
                <div v-if="form.from_account_id && form.to_account_id && form.amount" class="mt-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="flex items-center justify-center gap-3 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">
                            {{ accounts.find(a => a.id === parseInt(form.from_account_id))?.name }}
                        </span>
                        <div class="flex items-center gap-1 text-cyan-600 dark:text-cyan-400">
                            <span class="text-lg font-bold">${{ parseFloat(form.amount || 0).toFixed(2) }}</span>
                            <ArrowsRightLeftIcon class="w-5 h-5" />
                        </div>
                        <span class="font-medium text-gray-700 dark:text-gray-300">
                            {{ accounts.find(a => a.id === parseInt(form.to_account_id))?.name }}
                        </span>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeModal" type="button">
                        Cancel
                    </SecondaryButton>

                    <PrimaryButton :disabled="form.processing">
                        Create Transfer
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
