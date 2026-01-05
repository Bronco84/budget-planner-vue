<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDownIcon, CheckIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';
import { useToast } from 'vue-toastification';

const props = defineProps({
    isCollapsed: {
        type: Boolean,
        default: false
    },
    isMobileOpen: {
        type: Boolean,
        default: false
    }
});

const page = usePage();
const toast = useToast();
const activeBudget = computed(() => page.props.activeBudget);
const userBudgets = computed(() => page.props.userBudgets || []);
const showDropdown = ref(false);
const dropdownRef = ref(null);
const isLoading = ref(false);

// Generate initials from budget name
const getInitials = (name) => {
    if (!name) return '?';
    
    const words = name.trim().split(/\s+/);
    if (words.length === 1) {
        return words[0].substring(0, 2).toUpperCase();
    }
    return (words[0][0] + words[1][0]).toUpperCase();
};

// Get budget color (use stored color or fallback to default)
const getBudgetColor = (budget) => {
    return budget?.color || '#6366f1'; // Default to indigo if no color set
};

const toggleDropdown = () => {
    showDropdown.value = !showDropdown.value;
};

const switchBudget = async (budgetId) => {
    if (isLoading.value || budgetId === activeBudget.value?.id) {
        showDropdown.value = false;
        return;
    }

    isLoading.value = true;

    try {
        await axios.post(route('preferences.active-budget.set'), {
            budget_id: budgetId
        });

        // Close dropdown
        showDropdown.value = false;

        // Get current route info
        const currentRoute = route().current();
        const currentParams = route().params;

        // Check if we're on a budget-specific route
        if (currentRoute && currentParams.budget) {
            // Replace the budget ID in the URL and navigate
            const newParams = { ...currentParams, budget: budgetId };
            
            try {
                // Try to navigate to the same route with the new budget ID
                router.visit(route(currentRoute, newParams), {
                    preserveState: false,
                    preserveScroll: false,
                    onSuccess: () => {
                        toast.success('Budget switched successfully');
                    },
                    onError: (errors) => {
                        console.error('Navigation error:', errors);
                        // If navigation fails, try going to budget home
                        router.visit(route('budgets.show', budgetId), {
                            onSuccess: () => {
                                toast.success('Budget switched successfully');
                            }
                        });
                    }
                });
            } catch (e) {
                console.error('Route generation error:', e);
                // Fallback to budget home page
                router.visit(route('budgets.show', budgetId), {
                    onSuccess: () => {
                        toast.success('Budget switched successfully');
                    }
                });
            }
        } else {
            // Not on a budget-specific page, go to the new budget's home
            router.visit(route('budgets.show', budgetId), {
                onSuccess: () => {
                    toast.success('Budget switched successfully');
                }
            });
        }
    } catch (error) {
        console.error('Failed to switch budget:', error);
        toast.error('Failed to switch budget');
        isLoading.value = false;
    }
};

// Close dropdown when clicking outside
const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        showDropdown.value = false;
    }
};

// Close dropdown when mobile sidebar closes
watch(() => props.isMobileOpen, (newValue) => {
    if (!newValue) {
        showDropdown.value = false;
    }
});

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div ref="dropdownRef" class="relative" @click.stop>
        <!-- Budget Switcher Button -->
        <button
            v-if="activeBudget"
            @click.stop="toggleDropdown"
            :class="[
                'w-full group flex items-center justify-between px-3 py-2 text-sm font-medium rounded-md transition-all duration-200',
                'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800',
                showDropdown ? 'bg-gray-50 dark:bg-gray-800' : ''
            ]"
            :title="isCollapsed && !isMobileOpen ? activeBudget.name : ''"
        >
            <div class="flex items-center min-w-0 flex-1">
                <!-- Budget Avatar with Initials -->
                <div 
                    :class="[
                        'flex-shrink-0 rounded-full flex items-center justify-center text-white font-bold select-none',
                        isCollapsed && !isMobileOpen ? 'w-8 h-8 text-[10px]' : 'w-9 h-9 text-xs mr-3'
                    ]"
                    :style="{ 
                        backgroundColor: getBudgetColor(activeBudget),
                        lineHeight: 1,
                        letterSpacing: '-0.02em'
                    }"
                >
                    {{ getInitials(activeBudget.name) }}
                </div>
                <div v-if="!isCollapsed || isMobileOpen" class="min-w-0 flex-1">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Active Budget</div>
                    <div class="font-semibold truncate">{{ activeBudget.name }}</div>
                </div>
            </div>
            <ChevronDownIcon 
                v-if="!isCollapsed || isMobileOpen"
                :class="[
                    'w-4 h-4 ml-2 flex-shrink-0 transition-transform duration-200',
                    showDropdown ? 'rotate-180' : ''
                ]"
            />
        </button>

        <!-- No Budget State -->
        <div
            v-else
            class="w-full px-3 py-2 text-sm text-gray-500 dark:text-gray-400 text-center"
        >
            <span v-if="!isCollapsed || isMobileOpen">No active budget</span>
        </div>

        <!-- Dropdown Menu -->
        <div
            v-if="showDropdown && userBudgets.length > 0"
            :class="[
                'absolute bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg z-50 max-h-64 overflow-y-auto',
                isCollapsed && !isMobileOpen ? 'left-full top-0 w-64 ml-2' : 'left-0 right-0 top-full mt-1'
            ]"
            @click.stop
        >
            <div class="py-1">
                <button
                    v-for="budget in userBudgets"
                    :key="budget.id"
                    @click="switchBudget(budget.id)"
                    :disabled="isLoading"
                    :class="[
                        'w-full text-left px-4 py-2 text-sm transition-colors flex items-center gap-3',
                        budget.id === activeBudget?.id
                            ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                            : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700',
                        isLoading ? 'opacity-50 cursor-not-allowed' : ''
                    ]"
                >
                    <!-- Budget Avatar in Dropdown -->
                    <div 
                        :class="[
                            'flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-white font-bold text-[10px] select-none'
                        ]"
                        :style="{ 
                            backgroundColor: getBudgetColor(budget),
                            lineHeight: 1,
                            letterSpacing: '-0.02em'
                        }"
                    >
                        {{ getInitials(budget.name) }}
                    </div>
                    
                    <div class="min-w-0 flex-1">
                        <div class="font-medium truncate">{{ budget.name }}</div>
                        <div 
                            v-if="budget.description" 
                            class="text-xs text-gray-500 dark:text-gray-400 truncate"
                        >
                            {{ budget.description }}
                        </div>
                    </div>
                    
                    <CheckIcon 
                        v-if="budget.id === activeBudget?.id"
                        class="w-4 h-4 flex-shrink-0"
                    />
                </button>
            </div>
        </div>
    </div>
</template>

