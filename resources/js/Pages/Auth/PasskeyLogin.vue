<template>
  <Head title="Login" />

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
            Welcome Back
          </h2>
          <p class="text-center text-sm text-gray-600 mb-8">
            Sign in securely with your passkey
          </p>
        </div>

        <div class="space-y-6">
          <!-- Success Message -->
          <div v-if="status" class="rounded-xl bg-gradient-to-r from-green-50 to-emerald-50 p-4 border border-green-200">
            <div class="flex">
              <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-green-500" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
              </div>
              <div class="ml-3">
                <p class="text-sm font-medium text-green-800">{{ status }}</p>
              </div>
            </div>
          </div>

          <!-- Error Message -->
          <div v-if="error" class="rounded-xl bg-gradient-to-r from-red-50 to-pink-50 p-4 border border-red-200">
            <div class="flex">
              <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-500" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
              </div>
              <div class="ml-3">
                <p class="text-sm font-medium text-red-800">{{ error }}</p>
              </div>
            </div>
          </div>

          <!-- Email Field (Optional) -->
          <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
              Email (optional)
            </label>
            <input
              id="email"
              v-model="email"
              type="email"
              autocomplete="email webauthn"
              placeholder="your@email.com"
              class="block w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
            />
            <p class="mt-2 text-xs text-gray-500">
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
              <div class="w-full border-t border-gray-200"></div>
            </div>
            <div class="relative flex justify-center text-sm">
              <span class="px-4 bg-white/80 text-gray-500 font-medium">Or continue with</span>
            </div>
          </div>

          <!-- Magic Link Button -->
          <div>
            <Link
              :href="route('magic-link.request')"
              :class="[
                'group relative w-full flex justify-center py-3.5 px-6 border text-base font-medium rounded-xl transition-all duration-200',
                loading 
                  ? 'text-gray-400 bg-gray-50 border-gray-200 cursor-not-allowed pointer-events-none' 
                  : 'text-gray-700 bg-white border-gray-300 hover:bg-gray-50 hover:border-gray-400 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 shadow-sm hover:shadow'
              ]"
            >
              <span class="absolute left-0 inset-y-0 flex items-center pl-4">
                <svg :class="loading ? 'text-gray-300' : 'text-gray-400 group-hover:text-gray-600'" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
              </span>
              Send me a magic link
            </Link>
          </div>

          <!-- Register Link -->
          <div class="text-center pt-4 border-t border-gray-100">
            <p class="text-sm text-gray-600">
              Don't have an account?
              <Link 
                :href="route('register')" 
                :class="[
                  'font-semibold ml-1',
                  loading 
                    ? 'text-gray-400 cursor-not-allowed pointer-events-none' 
                    : 'text-indigo-600 hover:text-purple-600 transition-colors'
                ]"
              >
                Sign up
              </Link>
            </p>
          </div>
        </div>
      </div>

      <!-- Browser Support Notice -->
      <div v-if="!isWebAuthnSupported" class="mt-6 rounded-xl bg-gradient-to-r from-yellow-50 to-orange-50 p-4 border border-yellow-200 shadow-sm">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-yellow-500" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3">
            <p class="text-sm font-medium text-yellow-800">
              Your browser doesn't support passkeys. Please use the magic link option or update your browser.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

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
      },
    });

    const options = optionsResponse.data;
    console.log('WebAuthn options received:', options);

    // The Laragear package returns the data directly, not nested in publicKey
    // Convert base64url strings to ArrayBuffers
    const publicKey = {
      challenge: base64urlToBuffer(options.challenge),
      timeout: options.timeout,
      rpId: options.rpId,
      allowCredentials: options.allowCredentials?.map(cred => ({
        type: cred.type,
        id: base64urlToBuffer(cred.id),
        transports: cred.transports,
      })) || [],
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

    // Success! Do a full page reload to ensure session is properly established
    // This ensures CSRF tokens and Inertia state are fresh
    console.log('Login successful, redirecting to budgets...');
    window.location.href = '/budgets';
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
      if (err.response.status === 419) {
        error.value = 'Session expired. Please refresh the page and try again.';
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

