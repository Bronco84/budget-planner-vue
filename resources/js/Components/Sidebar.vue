<script setup>
import { Link } from '@inertiajs/vue3';
import {
    HomeIcon,
    BanknotesIcon,
    CurrencyDollarIcon,
    ChartBarIcon,
    CalendarIcon,
    ChevronLeftIcon,
    ChevronRightIcon
} from '@heroicons/vue/24/outline';

defineProps({
    isCollapsed: {
        type: Boolean,
        default: false
    },
    isMobileOpen: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['toggle', 'close']);

const navigationItems = [
    { name: 'Dashboard', href: 'dashboard', icon: HomeIcon, route: 'dashboard' },
    { name: 'Budgets', href: 'budgets.index', icon: BanknotesIcon, route: 'budgets.*' },
    { name: 'Transactions', href: '#', icon: CurrencyDollarIcon, route: 'transactions.*', disabled: true },
    { name: 'Reports', href: '#', icon: ChartBarIcon, route: 'reports.*', disabled: true },
    { name: 'Calendar', href: '#', icon: CalendarIcon, route: 'calendar.*', disabled: true },
];
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
            <template v-for="item in navigationItems" :key="item.name">
                <Link
                    v-if="!item.disabled"
                    :href="route(item.href)"
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
    </div>
</template>
