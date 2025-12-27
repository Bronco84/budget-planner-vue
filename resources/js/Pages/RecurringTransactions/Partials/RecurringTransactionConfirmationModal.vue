<template>
    <TransitionRoot as="template" :show="show">
        <Dialog as="div" class="relative z-50" @close="$emit('close')">
            <TransitionChild
                as="template"
                enter="ease-out duration-300"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-200"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" />
            </TransitionChild>

            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    >
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-4xl sm:p-6">
                            <div>
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
                                    <CheckIcon class="h-6 w-6 text-green-600" aria-hidden="true" />
                                </div>
                                <div class="mt-3 text-center sm:mt-5">
                                    <DialogTitle as="h3" class="text-base font-semibold leading-6 text-gray-900">
                                        Confirm Recurring Transaction Templates
                                    </DialogTitle>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500">
                                            Review and modify the detected patterns before creating recurring transaction templates.
                                            You can edit any field to ensure the templates accurately reflect your recurring transactions.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 max-h-96 overflow-y-auto">
                                <div v-for="(pattern, index) in editablePatterns" :key="index" class="mb-6 border rounded-lg p-4 bg-gray-50">
                                    <div class="flex justify-between items-start mb-4">
                                        <div class="flex items-center gap-2">
                                            <h4 class="text-lg font-medium text-gray-900">Pattern {{ index + 1 }}</h4>
                                            <!-- Entity Matching Badge -->
                                            <span v-if="pattern.original.plaid_entity_id" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800" title="Linked to Plaid entity for reliable matching">
                                                <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M16.403 12.652a3 3 0 000-5.304 3 3 0 00-3.75-3.751 3 3 0 00-5.305 0 3 3 0 00-3.751 3.75 3 3 0 000 5.305 3 3 0 003.75 3.751 3 3 0 005.305 0 3 3 0 003.751-3.75zm-2.546-4.46a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                                                </svg>
                                                {{ pattern.original.plaid_entity_name || 'Entity Match' }}
                                            </span>
                                        </div>
                                        <div class="flex items-center">
                                            <input
                                                :id="`pattern-${index}`"
                                                v-model="pattern.selected"
                                                type="checkbox"
                                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                            />
                                            <label :for="`pattern-${index}`" class="ml-2 text-sm text-gray-700">
                                                Create template
                                            </label>
                                        </div>
                                    </div>

                                    <div v-if="pattern.selected" class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                        <!-- Description -->
                                        <div class="sm:col-span-2">
                                            <label :for="`description-${index}`" class="block text-sm font-medium text-gray-700">
                                                Description
                                            </label>
                                            <input
                                                :id="`description-${index}`"
                                                v-model="pattern.form.description"
                                                type="text"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required
                                            />
                                        </div>

                                        <!-- Category -->
                                        <div>
                                            <label :for="`category-${index}`" class="block text-sm font-medium text-gray-700">
                                                Category
                                            </label>
                                            <input
                                                :id="`category-${index}`"
                                                v-model="pattern.form.category"
                                                type="text"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                placeholder="Optional"
                                            />
                                        </div>

                                        <!-- Amount Type -->
                                        <div>
                                            <label :for="`amount-type-${index}`" class="block text-sm font-medium text-gray-700">
                                                Amount Type
                                            </label>
                                            <select
                                                :id="`amount-type-${index}`"
                                                v-model="pattern.form.amount_type"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required
                                            >
                                                <option value="static">Fixed Amount</option>
                                                <option value="dynamic">Variable Amount</option>
                                            </select>
                                        </div>

                                        <!-- Static Amount (shown when amount_type is static) -->
                                        <div v-if="pattern.form.amount_type === 'static'">
                                            <label :for="`amount-${index}`" class="block text-sm font-medium text-gray-700">
                                                Amount ($)
                                            </label>
                                            <input
                                                :id="`amount-${index}`"
                                                v-model.number="pattern.form.amount"
                                                type="number"
                                                step="0.01"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required
                                            />
                                        </div>

                                        <!-- Dynamic Amount Fields (shown when amount_type is dynamic) -->
                                        <template v-if="pattern.form.amount_type === 'dynamic'">
                                            <div>
                                                <label :for="`min-amount-${index}`" class="block text-sm font-medium text-gray-700">
                                                    Min Amount ($)
                                                </label>
                                                <input
                                                    :id="`min-amount-${index}`"
                                                    v-model.number="pattern.form.min_amount"
                                                    type="number"
                                                    step="0.01"
                                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label :for="`max-amount-${index}`" class="block text-sm font-medium text-gray-700">
                                                    Max Amount ($)
                                                </label>
                                                <input
                                                    :id="`max-amount-${index}`"
                                                    v-model.number="pattern.form.max_amount"
                                                    type="number"
                                                    step="0.01"
                                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                />
                                            </div>
                                        </template>

                                        <!-- Frequency -->
                                        <div>
                                            <label :for="`frequency-${index}`" class="block text-sm font-medium text-gray-700">
                                                Frequency
                                            </label>
                                            <select
                                                :id="`frequency-${index}`"
                                                v-model="pattern.form.frequency"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required
                                            >
                                                <option value="" disabled>Select frequency</option>
                                                <option value="daily">Daily</option>
                                                <option value="weekly">Weekly</option>
                                                <option value="biweekly">Every Two Weeks</option>
                                                <option value="monthly">Monthly</option>
                                                <option value="bimonthly">Twice a Month</option>
                                                <option value="quarterly">Quarterly</option>
                                                <option value="yearly">Yearly</option>
                                            </select>
                                        </div>

                                        <!-- Day of Week (for weekly/biweekly) -->
                                        <div v-if="pattern.form.frequency === 'weekly' || pattern.form.frequency === 'biweekly'">
                                            <label :for="`day-of-week-${index}`" class="block text-sm font-medium text-gray-700">
                                                Day of Week
                                            </label>
                                            <select
                                                :id="`day-of-week-${index}`"
                                                v-model="pattern.form.day_of_week"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required
                                            >
                                                <option value="" disabled>Select day</option>
                                                <option value="1">Monday</option>
                                                <option value="2">Tuesday</option>
                                                <option value="3">Wednesday</option>
                                                <option value="4">Thursday</option>
                                                <option value="5">Friday</option>
                                                <option value="6">Saturday</option>
                                                <option value="7">Sunday</option>
                                            </select>
                                        </div>

                                        <!-- Day of Month (for monthly/quarterly/yearly) -->
                                        <div v-if="['monthly', 'quarterly', 'yearly'].includes(pattern.form.frequency)">
                                            <label :for="`day-of-month-${index}`" class="block text-sm font-medium text-gray-700">
                                                Day of Month
                                            </label>
                                            <input
                                                :id="`day-of-month-${index}`"
                                                v-model.number="pattern.form.day_of_month"
                                                type="number"
                                                min="1"
                                                max="31"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required
                                            />
                                        </div>

                                        <!-- Bimonthly Settings -->
                                        <template v-if="pattern.form.frequency === 'bimonthly'">
                                            <div>
                                                <label :for="`first-occurrence-${index}`" class="block text-sm font-medium text-gray-700">
                                                    First Occurrence (Day of Month)
                                                </label>
                                                <input
                                                    :id="`first-occurrence-${index}`"
                                                    v-model.number="pattern.form.bimonthly_first_day"
                                                    type="number"
                                                    min="1"
                                                    max="31"
                                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <label :for="`second-occurrence-${index}`" class="block text-sm font-medium text-gray-700">
                                                    Second Occurrence (Day of Month)
                                                </label>
                                                <input
                                                    :id="`second-occurrence-${index}`"
                                                    v-model.number="pattern.form.bimonthly_second_day"
                                                    type="number"
                                                    min="1"
                                                    max="31"
                                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                    required
                                                />
                                            </div>
                                        </template>

                                        <!-- Start Date -->
                                        <div>
                                            <label :for="`start-date-${index}`" class="block text-sm font-medium text-gray-700">
                                                Start Date
                                            </label>
                                            <input
                                                :id="`start-date-${index}`"
                                                v-model="pattern.form.start_date"
                                                type="date"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required
                                            />
                                        </div>

                                        <!-- End Date -->
                                        <div>
                                            <label :for="`end-date-${index}`" class="block text-sm font-medium text-gray-700">
                                                End Date (Optional)
                                            </label>
                                            <input
                                                :id="`end-date-${index}`"
                                                v-model="pattern.form.end_date"
                                                type="date"
                                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                            />
                                        </div>

                                        <!-- Pattern Details (read-only info) -->
                                        <div class="sm:col-span-2 bg-blue-50 p-3 rounded-md">
                                            <h5 class="text-sm font-medium text-blue-900 mb-2">Detected Pattern Details:</h5>
                                            <div class="grid grid-cols-2 gap-2 text-xs text-blue-800">
                                                <div><strong>Occurrences:</strong> {{ pattern.original.occurrence_count }}</div>
                                                <div><strong>Confidence:</strong> {{ Math.round(pattern.original.confidence * 100) }}%</div>
                                                <div><strong>Avg Amount:</strong> ${{ pattern.original.average_amount?.toFixed(2) }}</div>
                                                <div><strong>Last Seen:</strong> {{ pattern.original.last_transaction_date }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <button
                                    type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:col-start-2"
                                    @click="createTemplates"
                                    :disabled="processing || selectedPatterns.length === 0"
                                >
                                    <span v-if="processing">Creating...</span>
                                    <span v-else>Create {{ selectedPatterns.length }} Template{{ selectedPatterns.length !== 1 ? 's' : '' }}</span>
                                </button>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:col-start-1 sm:mt-0"
                                    @click="$emit('close')"
                                    :disabled="processing"
                                >
                                    Cancel
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { CheckIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    patterns: {
        type: Array,
        default: () => []
    },
    account: {
        type: Object,
        default: null
    }
})

const emit = defineEmits(['close', 'create'])

const processing = ref(false)

const editablePatterns = ref(
    props.patterns.map(pattern => ({
        selected: true,
        original: pattern,
        form: {
            description: pattern.original_description || pattern.description || 'Recurring Transaction',
            category: '',
            amount_type: pattern.standard_deviation > 10 ? 'dynamic' : 'static',
            amount: pattern.last_transaction_amount || Math.round((pattern.average_amount || 0) * 100) / 100,
            min_amount: pattern.min_amount ? Math.abs(Math.round(pattern.min_amount * 100) / 100) : null,
            max_amount: pattern.max_amount ? Math.abs(Math.round(pattern.max_amount * 100) / 100) : null,
            frequency: inferFrequency(pattern),
            day_of_week: null,
            day_of_month: inferDayOfMonth(pattern),
            bimonthly_first_day: null,
            bimonthly_second_day: null,
            start_date: pattern.last_transaction_date || new Date().toISOString().split('T')[0],
            end_date: null
        }
    }))
)

// Watch for changes in patterns prop and update editablePatterns
watch(() => props.patterns, (newPatterns) => {
    editablePatterns.value = newPatterns.map(pattern => ({
        selected: true,
        original: pattern,
        form: {
            description: pattern.original_description || pattern.description || 'Recurring Transaction',
            category: '',
            amount_type: pattern.standard_deviation > 10 ? 'dynamic' : 'static',
            amount: pattern.last_transaction_amount || Math.round((pattern.average_amount || 0) * 100) / 100,
            min_amount: pattern.min_amount ? Math.abs(Math.round(pattern.min_amount * 100) / 100) : null,
            max_amount: pattern.max_amount ? Math.abs(Math.round(pattern.max_amount * 100) / 100) : null,
            frequency: inferFrequency(pattern),
            day_of_week: null,
            day_of_month: inferDayOfMonth(pattern),
            bimonthly_first_day: null,
            bimonthly_second_day: null,
            start_date: pattern.last_transaction_date || new Date().toISOString().split('T')[0],
            end_date: null
        }
    }))
}, { immediate: true })

const selectedPatterns = computed(() => {
    return editablePatterns.value.filter(pattern => pattern.selected)
})

function inferFrequency(pattern) {
    const avgDaysBetween = pattern.average_days_between || 30

    if (avgDaysBetween <= 2) return 'daily'
    if (avgDaysBetween <= 9) return 'weekly'
    if (avgDaysBetween <= 18) return 'biweekly'
    if (avgDaysBetween <= 35) return 'monthly'
    if (avgDaysBetween <= 95) return 'quarterly'
    return 'yearly'
}

function inferDayOfMonth(pattern) {
    if (pattern.last_transaction_date) {
        const date = new Date(pattern.last_transaction_date)
        return date.getDate()
    }
    return 1
}

async function createTemplates() {
    const selected = selectedPatterns.value
    if (selected.length === 0) return

    processing.value = true

    try {
        const templatesData = selected.map(pattern => ({
            ...pattern.form,
            pattern_id: pattern.original.id || Math.random().toString(36).substr(2, 9),
            // Include Plaid entity information for reliable matching
            plaid_entity_id: pattern.original.plaid_entity_id || null,
            plaid_entity_name: pattern.original.plaid_entity_name || null,
        }))

        await emit('create', templatesData)
    } catch (error) {
        // Error creating templates
    } finally {
        processing.value = false
    }
}
</script>