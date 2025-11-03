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
    ChatBubbleLeftIcon
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

const emit = defineEmits(['toggle', 'close', 'create-transaction', 'create-recurring', 'connect-account', 'toggle-chat']);

const page = usePage();
const activeBudget = computed(() => page.props.activeBudget);
const hasActiveBudget = computed(() => !!activeBudget.value);

const showAddMenu = ref(false);
const addMenuRef = ref(null);
const isAddButtonAnimating = ref(false);

const navigationItems = computed(() => [
    { name: 'Home', href: 'budgets.show', icon: HomeIcon, route: 'budgets.*', disabled: !hasActiveBudget.value, params: hasActiveBudget.value ? { budget: activeBudget.value.id } : null },
    { name: 'Transactions', href: 'transactions.index', icon: BanknotesIcon, route: 'budget.transaction.*' },
    { name: 'Recurring', href: 'recurring-transactions.redirect', icon: ArrowPathIcon, route: 'recurring-transactions.*' },
    { name: 'Calendar', href: 'calendar.index', icon: CalendarIcon, route: 'calendar.*' },
    { name: 'Reports', href: 'reports.index', icon: ChartBarIcon, route: 'reports.*', disabled: !hasActiveBudget.value, params: hasActiveBudget.value ? { budget: activeBudget.value.id } : null },
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
            'fixed inset-y-0 left-0 z-50 flex flex-col bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 transition-all duration-300 ease-in-out',
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
                <Link
                    v-if="!item.disabled"
                    :href="item.params ? route(item.href, item.params) : route(item.href)"
                    :class="[
                        'group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors',
                        route().current(item.route)
                            ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white'
                            : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'
                    ]"
                    :title="isCollapsed && !isMobileOpen ? item.name : ''"
                >
                    <component
                        :is="item.icon"
                        :class="[
                            'flex-shrink-0',
                            isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed || isMobileOpen">{{ item.name }}</span>
                </Link>

                <!-- Disabled items -->
                <div
                    v-else
                    :class="[
                        'group flex items-center px-3 py-2 text-sm font-medium rounded-md cursor-not-allowed opacity-50',
                        'text-gray-400 dark:text-gray-600'
                    ]"
                    :title="isCollapsed && !isMobileOpen ? `${item.name} (Coming Soon)` : 'Coming Soon'"
                >
                    <component
                        :is="item.icon"
                        :class="[
                            'flex-shrink-0',
                            isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed || isMobileOpen">{{ item.name }}</span>
                    <span v-if="(!isCollapsed || isMobileOpen)" class="ml-auto text-xs">(Soon)</span>
                </div>
            </template>
        </nav>

        <!-- Chat Button (outside scrollable nav) -->
        <div class="px-2 pb-2">
            <button
                @click="emit('toggle-chat')"
                :class="[
                    'w-full group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors',
                    'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'
                ]"
                :title="isCollapsed && !isMobileOpen ? 'Chat Assistant' : ''"
            >
                <ChatBubbleLeftIcon
                    :class="[
                        'flex-shrink-0',
                        isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3'
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
                    'w-full group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors',
                    'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white',
                    { 'animate-pulse-scale': isAddButtonAnimating }
                ]"
                :title="isCollapsed && !isMobileOpen ? 'Add' : ''"
            >
                <PlusCircleIcon
                    :class="[
                        'flex-shrink-0',
                        isCollapsed && !isMobileOpen ? 'w-6 h-6' : 'w-5 h-5 mr-3'
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
                    <!-- Conditional items based on active budget -->
                    <template v-if="hasActiveBudget">
                        <button
                            @click="handleAction('create-transaction')"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center"
                        >
                            <BanknotesIcon class="w-4 h-4 mr-2" />
                            New Transaction
                        </button>

                        <button
                            @click="handleAction('create-recurring')"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center"
                        >
                            <ArrowPathIcon class="w-4 h-4 mr-2" />
                            New Recurring
                        </button>

                        <button
                            @click="handleAction('connect-account')"
                            class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center"
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                            Connect Bank
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
