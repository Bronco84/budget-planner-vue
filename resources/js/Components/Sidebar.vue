<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    HomeIcon,
    BanknotesIcon,
    ChartBarIcon,
    CalendarIcon,
    ArrowPathIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    PlusCircleIcon,
    ChatBubbleLeftIcon,
    TagIcon,
    PresentationChartLineIcon,
    CreditCardIcon,
    DocumentTextIcon,
    ChartPieIcon
} from '@heroicons/vue/24/outline';

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

const emit = defineEmits(['toggle', 'close', 'create-budget', 'create-transaction', 'create-recurring', 'connect-account', 'create-property', 'toggle-chat']);

const page = usePage();
const activeBudget = computed(() => page.props.activeBudget);
const hasActiveBudget = computed(() => !!activeBudget.value);

const showAddMenu = ref(false);
const addMenuRef = ref(null);
const isAddButtonAnimating = ref(false);

const navigationItems = computed(() => [
    { name: 'Home', href: 'budgets.show', icon: HomeIcon, route: 'budgets.*', disabled: !hasActiveBudget.value, params: hasActiveBudget.value ? { budget: activeBudget.value.id } : null, color: 'text-blue-500/70 dark:text-blue-400/70', hoverColor: 'group-hover:text-blue-600 dark:group-hover:text-blue-400' },
    { name: 'Transactions', href: 'transactions.index', icon: BanknotesIcon, route: 'budget.transaction.*', color: 'text-green-500/70 dark:text-green-400/70', hoverColor: 'group-hover:text-green-600 dark:group-hover:text-green-400' },
    { name: 'Recurring', href: 'recurring-transactions.redirect', icon: ArrowPathIcon, route: 'recurring-transactions.*', color: 'text-purple-500/70 dark:text-purple-400/70', hoverColor: 'group-hover:text-purple-600 dark:group-hover:text-purple-400' },
    { name: 'Debt Payoff', href: 'payoff-plans.index', icon: CreditCardIcon, route: 'payoff-plans.*', disabled: !hasActiveBudget.value, params: hasActiveBudget.value ? { budget: activeBudget.value.id } : null, color: 'text-red-500/70 dark:text-red-400/70', hoverColor: 'group-hover:text-red-600 dark:group-hover:text-red-400' },
    { name: 'Categories', href: 'budgets.categories.index', icon: TagIcon, route: 'budgets.categories.*', disabled: !hasActiveBudget.value, params: hasActiveBudget.value ? { budget: activeBudget.value.id } : null, color: 'text-amber-500/70 dark:text-amber-400/70', hoverColor: 'group-hover:text-amber-600 dark:group-hover:text-amber-400' },
    { name: 'Scenarios', href: 'budget.projections.multi-account', icon: PresentationChartLineIcon, route: 'budget.projections.multi-account', disabled: !hasActiveBudget.value, params: hasActiveBudget.value ? { budget: activeBudget.value.id } : null, color: 'text-indigo-500/70 dark:text-indigo-400/70', hoverColor: 'group-hover:text-indigo-600 dark:group-hover:text-indigo-400' },
    { name: 'Calendar', href: 'calendar.index', icon: CalendarIcon, route: 'calendar.*', color: 'text-pink-500/70 dark:text-pink-400/70', hoverColor: 'group-hover:text-pink-600 dark:group-hover:text-pink-400' },
    { name: 'Reports', href: 'reports.index', icon: ChartBarIcon, route: 'reports.*', disabled: !hasActiveBudget.value, params: hasActiveBudget.value ? { budget: activeBudget.value.id } : null, color: 'text-orange-500/70 dark:text-orange-400/70', hoverColor: 'group-hover:text-orange-600 dark:group-hover:text-orange-400' },
    { name: 'Files', href: 'budgets.files.index', icon: DocumentTextIcon, route: 'budgets.files.*', disabled: !hasActiveBudget.value, params: hasActiveBudget.value ? { budget: activeBudget.value.id } : null, color: 'text-cyan-500/70 dark:text-cyan-400/70', hoverColor: 'group-hover:text-cyan-600 dark:group-hover:text-cyan-400' },
]);

const toggleAddMenu = () => {
    showAddMenu.value = !showAddMenu.value;

    // Trigger animation
    isAddButtonAnimating.value = true;
    setTimeout(() => {
        isAddButtonAnimating.value = false;
    }, 300); // Match animation duration
};

const handleAction = (action) => {
    showAddMenu.value = false;
    emit(action);
};

// Close dropdown when clicking outside
const handleClickOutside = (event) => {
    if (addMenuRef.value && !addMenuRef.value.contains(event.target)) {
        showAddMenu.value = false;
    }
};

// Close dropdown when mobile sidebar closes
watch(() => props.isMobileOpen, (newValue) => {
    if (!newValue) {
        showAddMenu.value = false;
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
    <!-- Mobile backdrop -->
    <div
        v-if="isMobileOpen"
        class="fixed inset-0 z-40 bg-gray-600 bg-opacity-75 transition-opacity lg:hidden"
        @click="emit('close')"
    ></div>

    <!-- Sidebar -->
    <div
        :class="[
            'fixed inset-y-0 left-0 z-40 flex flex-col bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 transition-all duration-300 ease-in-out',
            {
                'w-64': !isCollapsed || isMobileOpen,
                'w-16': isCollapsed && !isMobileOpen,
                'translate-x-0': isMobileOpen,
                '-translate-x-full lg:translate-x-0': !isMobileOpen
            }
        ]"
    >
        <!-- Sidebar header -->
        <div class="flex items-center justify-between h-16 px-4 border-b border-gray-200 dark:border-gray-800">
            <div v-if="!isCollapsed || isMobileOpen" class="flex items-center">
                <span class="text-lg font-semibold text-gray-800 dark:text-gray-200">Menu</span>
            </div>

            <!-- Toggle button (desktop only) -->
            <button
                @click="emit('toggle')"
                class="hidden lg:flex items-center justify-center w-8 h-8 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500 dark:text-gray-400 transition-colors"
                :title="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            >
                <ChevronLeftIcon v-if="!isCollapsed" class="w-5 h-5" />
                <ChevronRightIcon v-else class="w-5 h-5" />
            </button>

            <!-- Close button (mobile only) -->
            <button
                @click="emit('close')"
                class="lg:hidden flex items-center justify-center w-8 h-8 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500 dark:text-gray-400 transition-colors"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Navigation items -->
        <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
            <!-- Regular Navigation Items -->
            <template v-for="item in navigationItems" :key="item.name">
                <!-- Action-based items (buttons) -->
                <button
                    v-if="item.action && !item.disabled"
                    @click="emit(item.action)"
                    :class="[
                        'group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-all duration-200 w-full text-left',
                        'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'
                    ]"
                    :title="isCollapsed && !isMobileOpen ? item.name : ''"
                >
                    <component
                        :is="item.icon"
                        :class="[
                            'flex-shrink-0 transition-all duration-200',
                            isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3',
                            item.color,
                            item.hoverColor,
                            'group-hover:scale-110'
                        ]"
                    />
                    <span v-if="!isCollapsed || isMobileOpen">{{ item.name }}</span>
                </button>

                <!-- Link-based items -->
                <Link
                    v-else-if="!item.action && !item.disabled"
                    :href="item.params ? route(item.href, item.params) : route(item.href)"
                    :class="[
                        'group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-all duration-200',
                        route().current(item.route)
                            ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white'
                            : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'
                    ]"
                    :title="isCollapsed && !isMobileOpen ? item.name : ''"
                >
                    <component
                        :is="item.icon"
                        :class="[
                            'flex-shrink-0 transition-all duration-200',
                            isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3',
                            item.color,
                            item.hoverColor,
                            'group-hover:scale-110'
                        ]"
                    />
                    <span v-if="!isCollapsed || isMobileOpen">{{ item.name }}</span>
                </Link>

                <!-- Disabled items - redirect to create budget or show as disabled -->
                <Link
                    v-else-if="item.disabled && item.href"
                    :href="route('budgets.create')"
                    :class="[
                        'group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-all duration-200 opacity-60',
                        'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'
                    ]"
                    :title="isCollapsed && !isMobileOpen ? item.name : ''"
                >
                    <component
                        :is="item.icon"
                        :class="[
                            'flex-shrink-0 transition-all duration-200',
                            isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3',
                            item.color,
                            item.hoverColor,
                            'group-hover:scale-110'
                        ]"
                    />
                    <span v-if="!isCollapsed || isMobileOpen">{{ item.name }}</span>
                </Link>

                <!-- Disabled action items -->
                <button
                    v-else-if="item.disabled && item.action"
                    disabled
                    :class="[
                        'group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-all duration-200 w-full text-left opacity-50 cursor-not-allowed',
                        'text-gray-600 dark:text-gray-400'
                    ]"
                    :title="isCollapsed && !isMobileOpen ? item.name : ''"
                >
                    <component
                        :is="item.icon"
                        :class="[
                            'flex-shrink-0',
                            item.color,
                            isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed || isMobileOpen">{{ item.name }}</span>
                </button>
            </template>
        </nav>

        <!-- Chat Button (outside scrollable nav) -->
        <div class="px-2 pb-2">
            <button
                @click="emit('toggle-chat')"
                :class="[
                    'w-full group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-all duration-200',
                    'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'
                ]"
                :title="isCollapsed && !isMobileOpen ? 'Chat Assistant' : ''"
            >
                <ChatBubbleLeftIcon
                    :class="[
                        'flex-shrink-0 transition-all duration-200 text-violet-500/70 dark:text-violet-400/70 group-hover:text-violet-600 dark:group-hover:text-violet-400',
                        isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3',
                        'group-hover:scale-110'
                    ]"
                />
                <span v-if="!isCollapsed || isMobileOpen">Chat Assistant</span>
            </button>
        </div>

        <!-- Add Menu Item (outside scrollable nav to prevent clipping) -->
        <div ref="addMenuRef" class="relative px-2 pb-4" @click.stop>
            <button
                @click.stop="toggleAddMenu"
                :class="[
                    'w-full group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-all duration-200',
                    'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white',
                    { 'animate-pulse-scale': isAddButtonAnimating }
                ]"
                :title="isCollapsed && !isMobileOpen ? 'Add' : ''"
            >
                <PlusCircleIcon
                    :class="[
                        'flex-shrink-0 transition-all duration-200 text-emerald-500/70 dark:text-emerald-400/70 group-hover:text-emerald-600 dark:group-hover:text-emerald-400',
                        isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3',
                        'group-hover:scale-110'
                    ]"
                />
                <span v-if="!isCollapsed || isMobileOpen">Add</span>
            </button>

            <!-- Add Menu Dropdown -->
            <div
                v-if="showAddMenu"
                :class="[
                    'absolute bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg z-50',
                    isCollapsed && !isMobileOpen ? 'left-full bottom-10 w-56' : 'left-2 right-2 bottom-full'
                ]"
                @click.stop
            >
                <div class="py-1">
                    <button
                        @click="handleAction('create-budget')"
                        class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center transition-colors"
                    >
                        <ChartPieIcon class="w-4 h-4 mr-2 text-blue-500/70 dark:text-blue-400/70" />
                        New Budget
                    </button>
                    
                    <!-- Conditional items based on active budget -->
                    <template v-if="hasActiveBudget">
                        <button
                            @click="handleAction('create-transaction')"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center transition-colors"
                        >
                            <BanknotesIcon class="w-4 h-4 mr-2 text-green-500/70 dark:text-green-400/70" />
                            New Transaction
                        </button>

                        <button
                            @click="handleAction('create-recurring')"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center transition-colors"
                        >
                            <ArrowPathIcon class="w-4 h-4 mr-2 text-purple-500/70 dark:text-purple-400/70" />
                            New Recurring
                        </button>

                        <button
                            @click="handleAction('connect-account')"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center transition-colors"
                        >
                            <CreditCardIcon class="w-4 h-4 mr-2 text-blue-500/70 dark:text-blue-400/70" />
                            Add Account
                        </button>

                        <button
                            @click="handleAction('create-property')"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center transition-colors"
                        >
                            <HomeIcon class="w-4 h-4 mr-2 text-amber-500/70 dark:text-amber-400/70" />
                            New Property
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes pulse-scale {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.animate-pulse-scale {
    animation: pulse-scale 0.3s ease-in-out;
}
</style>
