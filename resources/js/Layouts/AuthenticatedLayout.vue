<script setup>
import { ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import Sidebar from '@/Components/Sidebar.vue';
import { Link } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';
import { useSidebar } from '@/composables/useSidebar';

const showingNavigationDropdown = ref(false);
const { initializeTheme } = useTheme();
const { isCollapsed, isMobileOpen, toggleCollapsed, openMobile, closeMobile } = useSidebar();

// Initialize theme on component mount
initializeTheme();
</script>

<template>
    <div>
        <!-- Sidebar -->
        <Sidebar
            :is-collapsed="isCollapsed"
            :is-mobile-open="isMobileOpen"
            @toggle="toggleCollapsed"
            @close="closeMobile"
        />

        <!-- Main Content Area -->
        <div
            :class="[
                'min-h-screen bg-gray-100 dark:bg-gray-900 transition-all duration-300 ease-in-out',
                {
                    'lg:ml-64': !isCollapsed,
                    'lg:ml-16': isCollapsed
                }
            ]"
        >
            <nav
                class="border-b border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-900 transition-colors duration-200"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-full px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex items-center">
                            <!-- Hamburger for mobile -->
                            <button
                                @click="openMobile"
                                class="lg:hidden inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none mr-3"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                </svg>
                            </button>

                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200 transition-colors duration-200"
                                    />
                                </Link>
                            </div>
                        </div>

                        <div class="flex items-center space-x-4">
                            <!-- Theme Toggle -->
                            <ThemeToggle />

                            <!-- Settings Dropdown -->
                            <div class="relative">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white dark:bg-gray-900 px-3 py-2 text-sm font-medium leading-4 text-gray-500 dark:text-gray-400 transition duration-150 ease-in-out hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Profile
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Breadcrumbs -->
            <div class="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 transition-colors duration-200" v-if="$page.props.breadcrumbs">
                <div class="mx-auto max-w-full px-4 py-3 sm:px-6 lg:px-8">
                    <Breadcrumbs :breadcrumbs="$page.props.breadcrumbs" />
                </div>
            </div>

            <!-- Page Heading -->
            <header
                class="bg-white dark:bg-gray-900 shadow dark:shadow-gray-800 transition-colors duration-200"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-full px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
