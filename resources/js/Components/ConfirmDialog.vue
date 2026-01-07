<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { ExclamationTriangleIcon, InformationCircleIcon } from '@heroicons/vue/24/outline';

const isOpen = ref(false);
const dialogOptions = ref({
    title: 'Confirm Action',
    message: 'Are you sure you want to proceed?',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    type: 'warning' // 'danger', 'warning', 'info'
});
const resolveCallback = ref(null);

const handleConfirmDialog = (event) => {
    dialogOptions.value = {
        title: event.detail.title || 'Confirm Action',
        message: event.detail.message || 'Are you sure you want to proceed?',
        confirmText: event.detail.confirmText || 'Confirm',
        cancelText: event.detail.cancelText || 'Cancel',
        type: event.detail.type || 'warning'
    };
    resolveCallback.value = event.detail.resolve;
    isOpen.value = true;
};

const confirm = () => {
    if (resolveCallback.value) {
        resolveCallback.value(true);
    }
    isOpen.value = false;
    resolveCallback.value = null;
};

const cancel = () => {
    if (resolveCallback.value) {
        resolveCallback.value(false);
    }
    isOpen.value = false;
    resolveCallback.value = null;
};

onMounted(() => {
    window.addEventListener('show-confirm-dialog', handleConfirmDialog);
});

onUnmounted(() => {
    window.removeEventListener('show-confirm-dialog', handleConfirmDialog);
});

const getIconComponent = () => {
    if (dialogOptions.value.type === 'danger' || dialogOptions.value.type === 'warning') {
        return ExclamationTriangleIcon;
    }
    return InformationCircleIcon;
};

const getIconColorClass = () => {
    switch (dialogOptions.value.type) {
        case 'danger':
            return 'text-red-600 dark:text-red-400';
        case 'warning':
            return 'text-yellow-600 dark:text-yellow-400';
        case 'info':
            return 'text-blue-600 dark:text-blue-400';
        default:
            return 'text-yellow-600 dark:text-yellow-400';
    }
};

const getConfirmButtonClass = () => {
    switch (dialogOptions.value.type) {
        case 'danger':
            return 'bg-red-600 hover:bg-red-700 focus:ring-red-500 dark:bg-red-700 dark:hover:bg-red-800';
        case 'warning':
            return 'bg-yellow-600 hover:bg-yellow-700 focus:ring-yellow-500 dark:bg-yellow-700 dark:hover:bg-yellow-800';
        case 'info':
            return 'bg-blue-600 hover:bg-blue-700 focus:ring-blue-500 dark:bg-blue-700 dark:hover:bg-blue-800';
        default:
            return 'bg-yellow-600 hover:bg-yellow-700 focus:ring-yellow-500 dark:bg-yellow-700 dark:hover:bg-yellow-800';
    }
};
</script>

<template>
    <TransitionRoot :show="isOpen" as="template">
        <Dialog as="div" class="relative z-50" @close="cancel">
            <TransitionChild
                as="template"
                enter="ease-out duration-300"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-200"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-gray-500 dark:bg-gray-900 bg-opacity-75 dark:bg-opacity-75 transition-opacity" />
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
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div class="sm:flex sm:items-start">
                                <div :class="[
                                    'mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full sm:mx-0 sm:h-10 sm:w-10',
                                    dialogOptions.type === 'danger' ? 'bg-red-100 dark:bg-red-900/30' :
                                    dialogOptions.type === 'warning' ? 'bg-yellow-100 dark:bg-yellow-900/30' :
                                    'bg-blue-100 dark:bg-blue-900/30'
                                ]">
                                    <component :is="getIconComponent()" :class="['h-6 w-6', getIconColorClass()]" aria-hidden="true" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <DialogTitle as="h3" class="text-base font-semibold leading-6 text-gray-900 dark:text-gray-100">
                                        {{ dialogOptions.title }}
                                    </DialogTitle>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ dialogOptions.message }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                                <button
                                    type="button"
                                    :class="[
                                        'inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm sm:ml-3 sm:w-auto focus:outline-none focus:ring-2 focus:ring-offset-2',
                                        getConfirmButtonClass()
                                    ]"
                                    @click="confirm"
                                >
                                    {{ dialogOptions.confirmText }}
                                </button>
                                <button
                                    type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-gray-700 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 sm:mt-0 sm:w-auto"
                                    @click="cancel"
                                >
                                    {{ dialogOptions.cancelText }}
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>


