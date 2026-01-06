<template>
  <Head title="Register" />

  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-50 via-white to-purple-50 py-12 px-4 sm:px-6 lg:px-8">
    <!-- Background decoration -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div class="absolute -top-40 -right-40 w-80 h-80 bg-purple-200 rounded-full mix-blend-multiply filter blur-xl opacity-70 animate-blob"></div>
      <div class="absolute -bottom-40 -left-40 w-80 h-80 bg-indigo-200 rounded-full mix-blend-multiply filter blur-xl opacity-70 animate-blob animation-delay-2000"></div>
      <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-80 h-80 bg-pink-200 rounded-full mix-blend-multiply filter blur-xl opacity-70 animate-blob animation-delay-4000"></div>
    </div>

    <div class="max-w-md w-full space-y-8 relative">
      <!-- Card with glass morphism effect -->
      <div class="bg-white/80 backdrop-blur-lg rounded-2xl shadow-2xl border border-white/20 p-8">
        <div>
          <!-- Logo -->
          <div class="mx-auto h-20 w-auto flex justify-center mb-6">
            <img src="/images/logo.png" alt="Budget Planner Logo" class="h-20 w-auto drop-shadow-lg" />
          </div>
          
          <!-- Welcome text with gradient -->
          <h2 class="text-center text-4xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent mb-2">
            Create Your Account
          </h2>
          <p class="text-center text-sm text-gray-600 mb-8">
            Start your journey with secure, passwordless authentication
          </p>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
          <!-- Name Field -->
          <div>
            <InputLabel for="name" value="Name" class="text-sm font-medium text-gray-700" />
            <TextInput
              id="name"
              type="text"
              v-model="form.name"
              required
              autofocus
              autocomplete="name"
              class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
              placeholder="John Doe"
            />
            <InputError class="mt-2" :message="form.errors.name" />
          </div>

          <!-- Email Field -->
          <div>
            <InputLabel for="email" value="Email" class="text-sm font-medium text-gray-700" />
            <TextInput
              id="email"
              type="email"
              v-model="form.email"
              required
              autocomplete="username"
              class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
              placeholder="your@email.com"
            />
            <InputError class="mt-2" :message="form.errors.email" />
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
                <h3 class="text-sm font-medium text-blue-800">What's a passkey?</h3>
                <div class="mt-2 text-sm text-blue-700">
                  <p>After creating your account, you'll set up a passkey using your device's biometric authentication (Face ID, Touch ID, Windows Hello). No passwords needed!</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Submit Button -->
          <div>
            <button
              type="submit"
              :disabled="form.processing"
              class="group relative w-full flex justify-center py-4 px-6 border border-transparent text-base font-semibold rounded-xl text-white bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-0.5"
            >
              <span class="absolute left-0 inset-y-0 flex items-center pl-4">
                <svg v-if="!form.processing" class="h-6 w-6 text-white/80 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                </svg>
                <svg v-else class="animate-spin h-6 w-6 text-white" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
              </span>
              <span v-if="!form.processing" class="text-lg">Continue to Passkey Setup</span>
              <span v-else class="text-lg">Creating Account...</span>
            </button>
          </div>

          <!-- Login Link -->
          <div class="text-center pt-4 border-t border-gray-100">
            <p class="text-sm text-gray-600">
              Already have an account?
              <Link 
                :href="route('login')" 
                :class="[
                  'font-semibold ml-1',
                  form.processing 
                    ? 'text-gray-400 cursor-not-allowed pointer-events-none' 
                    : 'text-indigo-600 hover:text-purple-600 transition-colors'
                ]"
              >
                Sign in
              </Link>
            </p>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            // Form will redirect to passkey registration after success
        },
    });
};
</script>

<style scoped>
@keyframes blob {
  0% {
    transform: translate(0px, 0px) scale(1);
  }
  33% {
    transform: translate(30px, -50px) scale(1.1);
  }
  66% {
    transform: translate(-20px, 20px) scale(0.9);
  }
  100% {
    transform: translate(0px, 0px) scale(1);
  }
}

.animate-blob {
  animation: blob 7s infinite;
}

.animation-delay-2000 {
  animation-delay: 2s;
}

.animation-delay-4000 {
  animation-delay: 4s;
}
</style>
