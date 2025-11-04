<template>
    <Head :title="`Rules for ${recurringTransaction.description}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Rules for {{ recurringTransaction.description }}
                </h2>
                <Link
                    :href="route('recurring-transactions.edit', { budget: budget.id, recurring_transaction: recurringTransaction.id })"
                    class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 active:bg-gray-400 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
                >
                    <ArrowLeftIcon class="w-4 h-4 mr-2" />
                    Back to Recurring Transaction
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-medium text-gray-900">Transaction Rules</h3>
                        <div class="flex space-x-3">
                            <Link
                                :href="route('recurring-transactions.rules.linked', { budget: budget.id, recurring_transaction: recurringTransaction.id })"
                                class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 active:bg-gray-800 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
                            >
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                                </svg>
                                View Linked
                            </Link>
                            <Link
                                v-if="rules.length > 0"
                                :href="route('recurring-transactions.rules.preview', { budget: budget.id, recurring_transaction: recurringTransaction.id })"
                                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 active:bg-blue-800 focus:outline-none focus:border-blue-900 focus:ring focus:ring-blue-300 disabled:opacity-25 transition"
                            >
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                                Preview Matches
                            </Link>
                            <button
                                v-if="rules.length > 0"
                                @click="showApplyRulesModal = true"
                                class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700 active:bg-green-800 focus:outline-none focus:border-green-900 focus:ring focus:ring-green-300 disabled:opacity-25 transition"
                            >
                                <CheckIcon class="w-4 h-4 mr-2" />
                                Apply Rules
                            </button>
                        </div>
                    </div>

                    <p class="mb-4 text-sm text-gray-600">
                        Rules help you automatically identify and link similar transactions to this recurring transaction.
                        Transactions are evaluated against rules in priority order (lowest number first).
                    </p>

                    <!-- Rules List -->
                    <div v-if="rules.length > 0" class="mb-8">
                        <ul class="divide-y divide-gray-200">
                            <li v-for="rule in rules" :key="rule.id" class="py-4">
                                <div class="flex justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center">
                                            <span
                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mr-2"
                                                :class="rule.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
                                            >
                                                {{ rule.is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                            <span class="text-sm font-medium text-gray-900">
                                                Priority: {{ rule.priority }}
                                            </span>
                                        </div>
                                        <p class="mt-2 text-sm text-gray-600">
                                            <span class="font-medium">{{ fieldOptions[rule.field] }}</span>
                                            <span class="mx-1">{{ operatorOptions[rule.operator] }}</span>
                                            <span class="font-medium">"{{ rule.value }}"</span>
                                            <span v-if="rule.is_case_sensitive" class="ml-2 text-xs text-gray-500">(Case Sensitive)</span>
                                        </p>
                                    </div>
                                    <div class="flex">
                                        <button
                                            @click="editRule(rule)"
                                            class="text-blue-600 hover:text-blue-900 mr-4"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            @click="confirmDeleteRule(rule)"
                                            class="text-red-600 hover:text-red-900"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div v-else class="bg-yellow-50 p-4 rounded-md mb-8">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <ExclamationIcon class="h-5 w-5 text-yellow-400" />
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">No rules defined</h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p>
                                        You haven't defined any rules for this recurring transaction yet.
                                        Create a rule below to automatically identify similar transactions.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Add New Rule Form -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-4">
                            {{ isEditing ? 'Edit Rule' : 'Add New Rule' }}
                        </h3>
                        <RecurringTransactionRuleForm
                            :field-options="fieldOptions"
                            :operator-options="operatorOptions"
                            :rule="formRule"
                            :submit-url="formSubmitUrl"
                            :submit-method="formSubmitMethod"
                            :submit-button-text="isEditing ? 'Update Rule' : 'Add Rule'"
                            :show-test-button="!isEditing"
                            @submitted="ruleSubmitted"
                            @test="testRule"
                        />
                        <button
                            v-if="isEditing"
                            @click="cancelEdit"
                            class="mt-4 inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 active:bg-gray-400 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Rule Confirmation Modal -->
        <TransitionRoot appear :show="showDeleteModal" as="template">
            <Dialog as="div" @close="showDeleteModal = false" class="relative z-10">
                <TransitionChild
                    as="template"
                    enter="duration-300 ease-out"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="duration-200 ease-in"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-black bg-opacity-25" />
                </TransitionChild>

                <div class="fixed inset-0 overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center p-4 text-center">
                        <TransitionChild
                            as="template"
                            enter="duration-300 ease-out"
                            enter-from="opacity-0 scale-95"
                            enter-to="opacity-100 scale-100"
                            leave="duration-200 ease-in"
                            leave-from="opacity-100 scale-100"
                            leave-to="opacity-0 scale-95"
                        >
                            <DialogPanel class="w-full max-w-md transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
                                <DialogTitle as="h3" class="text-lg font-medium leading-6 text-gray-900">
                                    Delete Rule
                                </DialogTitle>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        Are you sure you want to delete this rule? This action cannot be undone.
                                    </p>
                                </div>

                                <div class="mt-4 flex justify-end space-x-3">
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md border border-transparent bg-gray-100 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 focus-visible:ring-offset-2"
                                        @click="showDeleteModal = false"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2"
                                        @click="deleteRule"
                                        :disabled="isDeleting"
                                    >
                                        {{ isDeleting ? 'Deleting...' : 'Delete' }}
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>

        <!-- Apply Rules Confirmation Modal -->
        <TransitionRoot appear :show="showApplyRulesModal" as="template">
            <Dialog as="div" @close="showApplyRulesModal = false" class="relative z-10">
                <TransitionChild
                    as="template"
                    enter="duration-300 ease-out"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="duration-200 ease-in"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-black bg-opacity-25" />
                </TransitionChild>

                <div class="fixed inset-0 overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center p-4 text-center">
                        <TransitionChild
                            as="template"
                            enter="duration-300 ease-out"
                            enter-from="opacity-0 scale-95"
                            enter-to="opacity-100 scale-100"
                            leave="duration-200 ease-in"
                            leave-from="opacity-100 scale-100"
                            leave-to="opacity-0 scale-95"
                        >
                            <DialogPanel class="w-full max-w-md transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
                                <DialogTitle as="h3" class="text-lg font-medium leading-6 text-gray-900">
                                    Apply Rules to Transactions
                                </DialogTitle>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        This will scan your recent transactions and link matching ones to this recurring transaction.
                                        Only active rules will be applied.
                                    </p>
                                </div>

                                <div class="mt-4 flex justify-end space-x-3">
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md border border-transparent bg-gray-100 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 focus-visible:ring-offset-2"
                                        @click="showApplyRulesModal = false"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 focus-visible:ring-offset-2"
                                        @click="applyRules"
                                        :disabled="isApplying"
                                    >
                                        {{ isApplying ? 'Applying...' : 'Apply Rules' }}
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>
    </AuthenticatedLayout>
</template>

<script>
import { ref, computed } from 'vue';
import { useForm, Link, Head } from '@inertiajs/vue3';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { ArrowLeftIcon, CheckIcon, ExclamationTriangleIcon as ExclamationIcon } from '@heroicons/vue/24/solid';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RecurringTransactionRuleForm from '../Partials/RecurringTransactionRuleForm.vue';

export default {
    components: {
        Head,
        AuthenticatedLayout,
        RecurringTransactionRuleForm,
        Link,
        Dialog,
        DialogPanel,
        DialogTitle,
        TransitionRoot,
        TransitionChild,
        ArrowLeftIcon,
        CheckIcon,
        ExclamationIcon
    },
    
    props: {
        budget: Object,
        recurringTransaction: Object,
        rules: Array,
        fieldOptions: Object,
        operatorOptions: Object
    },
    
    setup(props) {
        // Edit mode
        const isEditing = ref(false);
        const selectedRule = ref(null);
        
        // Delete modal
        const showDeleteModal = ref(false);
        const ruleToDelete = ref(null);
        const isDeleting = ref(false);
        
        // Apply rules modal
        const showApplyRulesModal = ref(false);
        const isApplying = ref(false);

        // Default form values
        const defaultRule = {
            field: 'description',
            operator: 'contains',
            value: '',
            is_case_sensitive: false,
            is_active: true,
            priority: null
        };
        
        // Computed form values
        const formRule = computed(() => {
            return selectedRule.value || defaultRule;
        });
        
        const formSubmitUrl = computed(() => {
            if (isEditing.value) {
                return route('recurring-transactions.rules.update', {
                    budget: props.budget.id,
                    recurring_transaction: props.recurringTransaction.id,
                    rule: selectedRule.value.id
                });
            } else {
                return route('recurring-transactions.rules.store', {
                    budget: props.budget.id,
                    recurring_transaction: props.recurringTransaction.id
                });
            }
        });
        
        const formSubmitMethod = computed(() => {
            return isEditing.value ? 'patch' : 'post';
        });
        
        // Methods
        const editRule = (rule) => {
            selectedRule.value = { ...rule };
            isEditing.value = true;
        };
        
        const cancelEdit = () => {
            selectedRule.value = null;
            isEditing.value = false;
        };
        
        const ruleSubmitted = () => {
            if (isEditing.value) {
                cancelEdit();
            }
        };
        
        const confirmDeleteRule = (rule) => {
            ruleToDelete.value = rule;
            showDeleteModal.value = true;
        };
        
        const deleteRule = () => {
            isDeleting.value = true;
            
            useForm().delete(route('recurring-transactions.rules.destroy', {
                budget: props.budget.id,
                recurring_transaction: props.recurringTransaction.id,
                rule: ruleToDelete.value.id
            }), {
                onFinish: () => {
                    isDeleting.value = false;
                    showDeleteModal.value = false;
                    ruleToDelete.value = null;
                }
            });
        };
        
        const applyRules = () => {
            isApplying.value = true;
            
            useForm().post(route('recurring-transactions.rules.apply', {
                budget: props.budget.id,
                recurring_transaction: props.recurringTransaction.id
            }), {}, {
                onFinish: () => {
                    isApplying.value = false;
                    showApplyRulesModal.value = false;
                }
            });
        };
        
        const testRule = () => {
            // Create a form with the current rule values
            const testForm = useForm({
                field: formRule.value.field,
                operator: formRule.value.operator,
                value: formRule.value.value,
                is_case_sensitive: formRule.value.is_case_sensitive
            });
            
            // Post to the test endpoint
            testForm.post(route('recurring-transactions.rules.test', {
                budget: props.budget.id,
                recurring_transaction: props.recurringTransaction.id
            }));
        };
        
        return {
            isEditing,
            selectedRule,
            showDeleteModal,
            ruleToDelete,
            isDeleting,
            showApplyRulesModal,
            isApplying,
            formRule,
            formSubmitUrl,
            formSubmitMethod,
            editRule,
            cancelEdit,
            ruleSubmitted,
            confirmDeleteRule,
            deleteRule,
            applyRules,
            testRule
        };
    }
}
</script> 