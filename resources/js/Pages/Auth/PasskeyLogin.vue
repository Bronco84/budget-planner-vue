<template>
  <Head title="Login" />

  <GuestLayout>
    <!-- Card with glass morphism effect -->
    <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-lg rounded-2xl shadow-2xl border border-white/20 dark:border-gray-700/50 p-8">
        <div>
          <!-- Logo -->
          <div class="mx-auto h-20 w-auto flex justify-center mb-6">
            <img src="/images/logo.png" alt="Budget Planner Logo" class="h-20 w-auto drop-shadow-lg" />
          </div>

          <!-- Welcome text with gradient -->
          <h2 class="text-center text-4xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 dark:from-indigo-400 dark:to-purple-400 bg-clip-text text-transparent mb-2">
            Welcome Back
          </h2>
          <p class="text-center text-sm text-gray-600 dark:text-gray-400 mb-8">
            Sign in securely with your passkey
          </p>
        </div>

        <div class="space-y-6">
          <!-- Success Message -->
          <div v-if="status" class="rounded-xl bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/30 dark:to-emerald-900/30 p-4 border border-green-200 dark:border-green-800/50">
            <div class="flex">
              <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-green-500 dark:text-green-400" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
              </div>
              <div class="ml-3">
                <p class="text-sm font-medium text-green-800 dark:text-green-300">{{ status }}</p>
              </div>
            </div>
          </div>

          <!-- Error Message -->
          <div v-if="error" class="rounded-xl bg-gradient-to-r from-red-50 to-pink-50 dark:from-red-900/30 dark:to-pink-900/30 p-4 border border-red-200 dark:border-red-800/50">
            <div class="flex">
              <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-500 dark:text-red-400" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
              </div>
              <div class="ml-3">
                <p class="text-sm font-medium text-red-800 dark:text-red-300">{{ error }}</p>
              </div>
            </div>
          </div>

          <!-- Email Field (Optional) -->
          <div>
            <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Email (optional)
            </label>
            <input
              id="email"
              v-model="email"
              type="email"
              autocomplete="email webauthn"
              placeholder="your@email.com"
              class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-xl shadow-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:focus:ring-indigo-400 focus:border-transparent transition-all"
            />
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
              Providing your email helps us find your passkey and offer better alternatives if needed.
            </p>
          </div>

          <!-- Passkey Login Button -->
          <div>
            <button
              @click="loginWithPasskey"
              :disabled="loading"
              class="group relative w-full flex justify-center py-4 px-6 border border-transparent text-base font-semibold rounded-xl text-white bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-0.5"
            >
              <span class="absolute left-0 inset-y-0 flex items-center pl-4">
                <svg v-if="!loading" class="h-6 w-6 text-white/80 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
                <svg v-else class="animate-spin h-6 w-6 text-white" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
              </span>
              <span v-if="!loading" class="text-lg">Sign in with Passkey</span>
              <span v-else class="text-lg">Authenticating...</span>
            </button>
          </div>

          <!-- Divider -->
          <div class="relative">
            <div class="absolute inset-0 flex items-center">
              <div class="w-full border-t border-gray-200 dark:border-gray-700"></div>
            </div>
            <div class="relative flex justify-center text-sm">
              <span class="px-4 bg-white/80 dark:bg-gray-800/80 text-gray-500 dark:text-gray-400 font-medium">Or continue with</span>
            </div>
          </div>

          <!-- Magic Link Button -->
          <div>
            <Link
              :href="route('magic-link.request')"
              :class="[
                'group relative w-full flex justify-center py-3.5 px-6 border text-base font-medium rounded-xl transition-all duration-200',
                loading
                  ? 'text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 cursor-not-allowed pointer-events-none'
                  : 'text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 hover:border-gray-400 dark:hover:border-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-800 focus:ring-indigo-500 shadow-sm hover:shadow'
              ]"
            >
              <span class="absolute left-0 inset-y-0 flex items-center pl-4">
                <svg :class="loading ? 'text-gray-300 dark:text-gray-500' : 'text-gray-400 dark:text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300'" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
              </span>
              Send me a magic link
            </Link>
          </div>

          <!-- Register Link -->
          <div class="text-center pt-4 border-t border-gray-100 dark:border-gray-700">
            <p class="text-sm text-gray-600 dark:text-gray-400">
              Don't have an account?
              <Link
                :href="route('register')"
                :class="[
                  'font-semibold ml-1',
                  loading
                    ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed pointer-events-none'
                    : 'text-indigo-600 dark:text-indigo-400 hover:text-purple-600 dark:hover:text-purple-400 transition-colors'
                ]"
              >
                Sign up
              </Link>
            </p>
          </div>
        </div>
      </div>

      <!-- Browser Support Notice -->
      <div v-if="!isWebAuthnSupported" class="mt-6 rounded-xl bg-gradient-to-r from-yellow-50 to-orange-50 dark:from-yellow-900/30 dark:to-orange-900/30 p-4 border border-yellow-200 dark:border-yellow-800/50 shadow-sm">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-yellow-500 dark:text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3">
            <p class="text-sm font-medium text-yellow-800 dark:text-yellow-300">
              Your browser doesn't support passkeys. Please use the magic link option or update your browser.
            </p>
          </div>
        </div>
      </div>
  </GuestLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
  status: String,
});

const loading = ref(false);
const error = ref('');
const email = ref('');
const isWebAuthnSupported = ref(true);

onMounted(() => {
  // Check if WebAuthn is supported
  isWebAuthnSupported.value = window.PublicKeyCredential !== undefined;
});

const loginWithPasskey = async () => {
  if (!isWebAuthnSupported.value) {
    error.value = 'Your browser doesn\'t support passkeys. Please use the magic link option.';
    return;
  }

  loading.value = true;
  error.value = '';

  try {
    console.log('Starting passkey login...');

    // Prepare request body with optional email
    const requestBody = email.value ? { email: email.value } : {};

    // Use Axios instead of fetch for better CSRF handling
    const optionsResponse = await window.axios.post('/webauthn/login/options', requestBody, {
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    });

    const options = optionsResponse.data;
    console.log('WebAuthn options received:', options);

    // Check if we received HTML instead of JSON (indicates a server-side issue)
    if (typeof options === 'string' && options.trim().startsWith('<!DOCTYPE')) {
      console.error('Received HTML instead of JSON from server');
      throw new Error('Server configuration error. Please contact support.');
    }

    // Validate response structure
    if (!options || !options.challenge) {
      console.error('Invalid WebAuthn options response:', options);
      throw new Error('Invalid response from server. Please try again or use the magic link option.');
    }

    // Validate and filter allowCredentials
    let validCredentials = [];
    if (options.allowCredentials && Array.isArray(options.allowCredentials)) {
      // Filter out any credentials with missing or invalid IDs
      validCredentials = options.allowCredentials.filter(cred => {
        if (!cred || !cred.id || typeof cred.id !== 'string' || cred.id.trim() === '') {
          console.warn('Skipping invalid credential:', cred);
          return false;
        }
        return true;
      });

      // If all credentials were invalid, show a helpful error
      if (options.allowCredentials.length > 0 && validCredentials.length === 0) {
        console.error('All credentials were invalid. Original data:', options.allowCredentials);
        throw new Error('Your passkey data appears to be corrupted. Please use the magic link option to sign in, then remove and re-add your passkey in Settings.');
      }
    }

    // The Laragear package returns the data directly, not nested in publicKey
    // Convert base64url strings to ArrayBuffers
    const publicKey = {
      challenge: base64urlToBuffer(options.challenge),
      timeout: options.timeout,
      rpId: options.rpId,
      allowCredentials: validCredentials.map(cred => ({
        type: cred.type,
        id: base64urlToBuffer(cred.id),
        transports: cred.transports,
      })),
      userVerification: options.userVerification || 'preferred',
    };

    console.log('Calling navigator.credentials.get with:', publicKey);

    // Prompt user for passkey
    const credential = await navigator.credentials.get({ publicKey });

    if (!credential) {
      throw new Error('No credential returned');
    }

    console.log('Credential received:', credential);

    // Prepare credential for server (Laragear expects this format)
    const credentialData = {
      id: credential.id,
      rawId: bufferToBase64url(credential.rawId),
      type: credential.type,
      response: {
        authenticatorData: bufferToBase64url(credential.response.authenticatorData),
        clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
        signature: bufferToBase64url(credential.response.signature),
        userHandle: credential.response.userHandle ? bufferToBase64url(credential.response.userHandle) : null,
      },
    };

    console.log('Sending credential to server:', credentialData);

    // Send credential to server using Axios
    const loginResponse = await window.axios.post('/webauthn/login', credentialData, {
      headers: {
        'Accept': 'application/json',
      },
    });

    console.log('Login response:', loginResponse);

    // Success! Use Inertia router for proper SPA navigation
    console.log('Login successful, redirecting...');

    // Use Inertia's router.visit with replace to redirect after login
    // This is the Inertia best practice - it maintains SPA behavior
    // and properly handles the redirect with fresh data
    router.visit(route('budgets.index'), {
      method: 'get',
      replace: true, // Replace history so back button doesn't go to login
      preserveState: false, // Get fresh state from server
      preserveScroll: false, // Reset scroll position
    });
  } catch (err) {
    console.error('Passkey login error:', err);

    // Only reset loading state on error
    loading.value = false;

    // Handle WebAuthn errors
    if (err.name === 'NotAllowedError') {
      error.value = 'Authentication was cancelled or timed out.';
    } else if (err.name === 'InvalidStateError') {
      error.value = 'No passkey found for this device. Please use the magic link option below to sign in, then you can add a passkey for this device.';
    } else if (err.name === 'NotSupportedError') {
      error.value = 'Passkeys are not supported on this device. Please use the magic link option below.';
    }
    // Handle Axios errors
    else if (err.response) {
      // Note: 419 errors are now handled automatically by axios interceptor in bootstrap.js
      // If we see a 419 here, it means the auto-retry also failed (session completely dead)
      if (err.response.status === 419) {
        error.value = 'Your session has completely expired. Please refresh the page and try again.';
      } else if (err.response.status === 422) {
        error.value = 'Authentication failed. Your passkey may not be registered. Try using the magic link option below.';
      } else {
        error.value = err.response.data?.message || 'Could not authenticate with passkey. Try using the magic link option below.';
      }
    } else {
      error.value = err.message || 'Could not authenticate with passkey. Try using the magic link option below.';
    }
  }
};

// Helper functions for base64url encoding/decoding
function base64urlToBuffer(base64url) {
  if (!base64url) {
    console.error('base64urlToBuffer received undefined or null value');
    throw new Error('Invalid base64url value');
  }
  const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}

function bufferToBase64url(buffer) {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}
</script>

