<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import ManagePasskeysForm from './Partials/ManagePasskeysForm.vue';
import ManageTrustedDevicesForm from './Partials/ManageTrustedDevicesForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    passkeys: {
        type: Array,
        default: () => [],
    },
    trustedDevices: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <ManagePasskeysForm :passkeys="passkeys" />
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <ManageTrustedDevicesForm :trusted-devices="trustedDevices" />
                </div>

                <div
                    class="bg-white dark:bg-gray-800 p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
