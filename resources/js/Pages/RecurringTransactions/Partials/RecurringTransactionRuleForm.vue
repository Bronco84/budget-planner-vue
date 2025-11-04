<template>
    <div class="bg-white p-6 rounded-lg shadow">
        <form @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <!-- Field -->
                <div class="sm:col-span-3">
                    <label for="field" class="block text-sm font-medium text-gray-700">Field</label>
                    <select
                        id="field"
                        v-model="form.field"
                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md"
                    >
                        <option v-for="(label, key) in fieldOptions" :key="key" :value="key">
                            {{ label }}
                        </option>
                    </select>
                    <div v-if="form.errors.field" class="text-red-500 text-xs mt-1">
                        {{ form.errors.field }}
                    </div>
                </div>

                <!-- Operator -->
                <div class="sm:col-span-3">
                    <label for="operator" class="block text-sm font-medium text-gray-700">Operator</label>
                    <select
                        id="operator"
                        v-model="form.operator"
                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md"
                    >
                        <option v-for="(label, key) in operatorOptions" :key="key" :value="key">
                            {{ label }}
                        </option>
                    </select>
                    <div v-if="form.errors.operator" class="text-red-500 text-xs mt-1">
                        {{ form.errors.operator }}
                    </div>
                </div>

                <!-- Value -->
                <div class="sm:col-span-6">
                    <label for="value" class="block text-sm font-medium text-gray-700">Value</label>
                    <div class="mt-1">
                        <input
                            type="text"
                            id="value"
                            v-model="form.value"
                            class="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md"
                        />
                    </div>
                    <div v-if="form.errors.value" class="text-red-500 text-xs mt-1">
                        {{ form.errors.value }}
                    </div>
                </div>

                <!-- Case Sensitivity -->
                <div class="sm:col-span-3">
                    <div class="flex items-center">
                        <input
                            id="is_case_sensitive"
                            v-model="form.is_case_sensitive"
                            type="checkbox"
                            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                        />
                        <label for="is_case_sensitive" class="ml-2 block text-sm text-gray-700">
                            Case Sensitive
                        </label>
                    </div>
                    <div v-if="form.errors.is_case_sensitive" class="text-red-500 text-xs mt-1">
                        {{ form.errors.is_case_sensitive }}
                    </div>
                </div>

                <!-- Active Status -->
                <div class="sm:col-span-3">
                    <div class="flex items-center">
                        <input
                            id="is_active"
                            v-model="form.is_active"
                            type="checkbox"
                            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                        />
                        <label for="is_active" class="ml-2 block text-sm text-gray-700">
                            Active
                        </label>
                    </div>
                    <div v-if="form.errors.is_active" class="text-red-500 text-xs mt-1">
                        {{ form.errors.is_active }}
                    </div>
                </div>

                <!-- Priority -->
                <div class="sm:col-span-3">
                    <label for="priority" class="block text-sm font-medium text-gray-700">Priority</label>
                    <div class="mt-1">
                        <input
                            type="number"
                            id="priority"
                            v-model="form.priority"
                            min="1"
                            class="shadow-sm focus:ring-blue-500 focus:border-blue-500 block w-full sm:text-sm border-gray-300 rounded-md"
                        />
                    </div>
                    <div v-if="form.errors.priority" class="text-red-500 text-xs mt-1">
                        {{ form.errors.priority }}
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <button
                    type="submit"
                    class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    :disabled="form.processing"
                >
                    {{ submitButtonText }}
                </button>
            </div>
        </form>
    </div>
</template>

<script>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

export default {
    name: 'RecurringTransactionRuleForm',

    props: {
        fieldOptions: {
            type: Object,
            required: true
        },
        operatorOptions: {
            type: Object,
            required: true
        },
        rule: {
            type: Object,
            default: () => ({
                field: 'description',
                operator: 'contains',
                value: '',
                is_case_sensitive: false,
                is_active: true,
                priority: null
            })
        },
        submitUrl: {
            type: String,
            required: true
        },
        submitMethod: {
            type: String,
            default: 'post'
        },
        submitButtonText: {
            type: String,
            default: 'Save'
        },
        showTestButton: {
            type: Boolean,
            default: false
        }
    },

    emits: ['submitted', 'test'],

    setup(props, { emit }) {
        const form = useForm({
            field: props.rule.field,
            operator: props.rule.operator,
            value: props.rule.value,
            is_case_sensitive: props.rule.is_case_sensitive,
            is_active: props.rule.is_active,
            priority: props.rule.priority
        });

        // Watch for changes to the rule prop and update form fields
        watch(() => props.rule, (newRule) => {
            form.field = newRule.field;
            form.operator = newRule.operator;
            form.value = newRule.value;
            form.is_case_sensitive = newRule.is_case_sensitive;
            form.is_active = newRule.is_active;
            form.priority = newRule.priority;
            form.clearErrors();
        }, { deep: true });

        const submit = () => {
            form[props.submitMethod](props.submitUrl, {
                onSuccess: () => {
                    emit('submitted');
                }
            });
        };

        return {
            form,
            submit
        };
    }
};
</script>
