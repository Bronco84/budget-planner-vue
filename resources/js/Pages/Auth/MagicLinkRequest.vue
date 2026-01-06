<template>
  <Head title="Magic Link Login" />

  <GuestLayout>
    <div>
      <!-- Logo -->
      <div class="mx-auto h-20 w-auto flex justify-center mb-6">
        <img src="/images/logo.png" alt="Budget Planner Logo" class="h-20 w-auto drop-shadow-lg" />
      </div>

      <!-- Header with gradient -->
      <div class="flex items-center justify-center mb-4">
        <svg class="h-10 w-10 text-indigo-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        </svg>
        <h2 class="text-3xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
          Get a Magic Link
        </h2>
      </div>

      <p class="text-center text-sm text-gray-600 mb-6">
        We'll send you a secure login link via email
      </p>
    </div>

    <div class="space-y-6">
      <!-- Success Message -->
      <div v-if="status || linkSent" class="rounded-xl bg-gradient-to-r from-green-50 to-emerald-50 p-4 border border-green-200">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-green-500" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3">
            <h3 class="text-sm font-medium text-green-800">Check your email!</h3>
            <p class="mt-2 text-sm text-green-700">
              We've sent a magic link to <strong>{{ form.email }}</strong>. Click the link in the email to sign in. The link will expire in 15 minutes.
            </p>
          </div>
        </div>
      </div>

      <!-- Error Message -->
      <div v-if="form.errors.email" class="rounded-xl bg-gradient-to-r from-red-50 to-pink-50 p-4 border border-red-200">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-red-500" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3">
            <p class="text-sm font-medium text-red-800">{{ form.errors.email }}</p>
          </div>
        </div>
      </div>

      <!-- Email Form -->
      <form @submit.prevent="sendMagicLink" class="space-y-6">
        <div>
          <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
            Email address
          </label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            required
            class="block w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
            :class="{ 'border-red-300': form.errors.email }"
            placeholder="you@example.com"
          />
        </div>

        <div>
          <button
            type="submit"
            :disabled="form.processing"
            class="group relative w-full flex justify-center py-4 px-6 border border-transparent text-base font-semibold rounded-xl text-white bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-0.5"
          >
            <span class="absolute left-0 inset-y-0 flex items-center pl-4">
              <svg v-if="!form.processing" class="h-6 w-6 text-white/80 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
              <svg v-else class="animate-spin h-6 w-6 text-white" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </span>
            <span v-if="!form.processing" class="text-lg">Send Magic Link</span>
            <span v-else class="text-lg">Sending...</span>
          </button>
        </div>
      </form>

      <!-- Back to Login -->
      <div class="text-center">
        <Link
          :href="route('login')"
          class="inline-flex items-center text-sm font-medium text-indigo-600 hover:text-purple-600 transition-colors"
        >
          <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to passkey login
        </Link>
      </div>

      <!-- Info Box -->
      <div class="rounded-xl bg-gradient-to-r from-blue-50 to-indigo-50 p-4 border border-blue-200">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-blue-500" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3 flex-1">
            <h3 class="text-sm font-medium text-blue-800">How it works</h3>
            <div class="mt-2 text-sm text-blue-700">
              <ul class="list-disc pl-5 space-y-1">
                <li>Enter your email address</li>
                <li>We'll send you a secure login link</li>
                <li>Click the link to sign in instantly</li>
                <li>The link expires after 15 minutes</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  </GuestLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps({
  status: String,
});

const linkSent = ref(false);

const form = useForm({
  email: '',
});

const sendMagicLink = () => {
  form.post(route('magic-link.store'), {
    preserveScroll: true,
    onSuccess: () => {
      linkSent.value = true;
    },
  });
};
</script>

