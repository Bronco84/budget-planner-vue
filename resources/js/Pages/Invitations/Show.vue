<template>
  <Head title="Budget invitation" />

  <GuestLayout>
    <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-lg rounded-2xl shadow-2xl border border-white/20 dark:border-gray-700/50 p-8 space-y-6">
      <div class="mx-auto h-16 w-auto flex justify-center">
        <img src="/images/logo.png" alt="Budget Planner Logo" class="h-16 w-auto drop-shadow-lg" />
      </div>

      <template v-if="status === 'invalid' || !invitation">
        <h1 class="text-center text-2xl font-bold text-gray-900 dark:text-gray-100">Invitation unavailable</h1>
        <p class="text-center text-sm text-gray-600 dark:text-gray-400">
          This invitation link is invalid, has expired or has already been used. Ask the budget owner to send a new one.
        </p>
        <div class="text-center">
          <Link :href="route('login')" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">Go to sign in</Link>
        </div>
      </template>

      <template v-else>
        <div class="text-center space-y-2">
          <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">You're invited</h1>
          <p class="text-sm text-gray-600 dark:text-gray-400">
            <span v-if="invitation.invited_by" class="font-medium">{{ invitation.invited_by }}</span><span v-else>Someone</span>
            invited <span class="font-medium">{{ invitation.email }}</span> to
            <span class="font-medium">{{ invitation.budget_name }}</span> as {{ invitation.role === 'viewer' ? 'a' : 'an' }}
            <span class="font-medium">{{ invitation.role }}</span>.
          </p>
        </div>

        <InputError class="text-center" :message="errors.invitation" />

        <div v-if="status === 'ready'">
          <button
            type="button"
            :disabled="form.processing"
            class="w-full bg-blue-500 hover:bg-blue-600 disabled:opacity-50 text-white font-semibold py-3 px-4 rounded-xl"
            @click="accept"
          >
            Accept and open budget
          </button>
        </div>

        <div v-else-if="status === 'wrong_account'" class="rounded-xl bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 p-4 text-sm text-amber-800 dark:text-amber-200">
          You're signed in as <span class="font-medium">{{ signedInAs }}</span>, but this invitation is for
          <span class="font-medium">{{ invitation.email }}</span>. Sign out and sign in with that address to accept it.
        </div>

        <div v-else-if="status === 'sign_in'" class="space-y-3 text-center">
          <p class="text-sm text-gray-600 dark:text-gray-400">Sign in as {{ invitation.email }} to accept. You'll come back here afterwards.</p>
          <Link :href="route('login')" class="block w-full bg-blue-500 hover:bg-blue-600 text-white font-semibold py-3 px-4 rounded-xl">
            Sign in
          </Link>
        </div>

        <div v-else-if="status === 'register'" class="space-y-3 text-center">
          <p class="text-sm text-gray-600 dark:text-gray-400">Create a free account for {{ invitation.email }} with a passkey; no password needed.</p>
          <Link
            :href="route('register', { invitation: token })"
            class="block w-full bg-blue-500 hover:bg-blue-600 text-white font-semibold py-3 px-4 rounded-xl"
          >
            Create account
          </Link>
        </div>

        <p class="text-center text-xs text-gray-500 dark:text-gray-400">
          Expires {{ new Date(invitation.expires_at).toLocaleDateString() }}.
        </p>
      </template>
    </div>
  </GuestLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
  status: { type: String, required: true },
  token: { type: String, default: null },
  invitation: { type: Object, default: null },
  signedInAs: { type: String, default: null },
});

const page = usePage();
const toast = useToast();
const errors = computed(() => page.props.errors || {});
const form = useForm({});

const accept = () => {
  // Lands on the budget; toast the "You joined ..." flash there.
  form.post(route('invitations.accept', props.token), {
    onSuccess: (visited) => {
      if (visited.props.flash?.message) toast.success(visited.props.flash.message);
    },
  });
};
</script>
