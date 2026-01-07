<template>
  <Head title="Register Passkey" />

  <component :is="layoutComponent">
    <!-- Guest layout: Card with glass morphism effect -->
    <div v-if="isNewUser" class="bg-white/80 backdrop-blur-lg rounded-2xl shadow-2xl border border-white/20 p-8">
          <div>
            <!-- Logo -->
            <div class="mx-auto h-20 w-auto flex justify-center mb-6">
              <img src="/images/logo.png" alt="Budget Planner Logo" class="h-20 w-auto drop-shadow-lg" />
            </div>
            
            <!-- Header with gradient -->
            <div class="flex items-center justify-center mb-4">
              <svg class="h-10 w-10 text-indigo-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
              </svg>
              <h2 class="text-3xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                Register a Passkey
              </h2>
            </div>

            <p class="text-center text-sm text-gray-600 mb-6">
              <span class="font-semibold text-gray-900">Welcome! To complete your registration, you must set up a passkey. </span>
              Passkeys let you sign in using Face ID, Touch ID, Windows Hello, or a security key. They're more secure than passwords and much easier to use.
            </p>
          </div>

          <div class="space-y-6">
            <!-- Warning for new users -->
            <div class="rounded-xl bg-gradient-to-r from-yellow-50 to-amber-50 border border-yellow-200 p-4">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-yellow-500" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <p class="text-sm font-medium text-yellow-800">
                    Passkey Required: This app uses passkeys for secure, passwordless authentication. You cannot proceed without registering a passkey.
                  </p>
                </div>
              </div>
            </div>

            <!-- Success Message -->
            <div v-if="success" class="rounded-xl bg-gradient-to-r from-green-50 to-emerald-50 p-4 border border-green-200">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-green-500" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <p class="text-sm font-medium text-green-800">{{ success }}</p>
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

            <!-- Registration Form -->
            <div>
              <label for="passkey-name" class="block text-sm font-medium text-gray-700 mb-2">
                Passkey Name (Optional)
              </label>
              <input
                id="passkey-name"
                v-model="passkeyName"
                type="text"
                placeholder="e.g., My iPhone, Work Laptop"
                class="block w-full px-4 py-3 border border-gray-300 rounded-xl shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
              />
              <p class="mt-2 text-sm text-gray-500">
                Give this passkey a name to help you identify it later.
              </p>
            </div>

            <div>
              <button
                @click="registerPasskey"
                :disabled="loading"
                class="group relative w-full flex justify-center py-4 px-6 border border-transparent text-base font-semibold rounded-xl text-white bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-0.5"
              >
                <span class="absolute left-0 inset-y-0 flex items-center pl-4">
                  <svg v-if="!loading" class="h-6 w-6 text-white/80 group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                  <svg v-else class="animate-spin h-6 w-6 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                </span>
                <span v-if="!loading" class="text-lg">Complete Registration</span>
                <span v-else class="text-lg">Registering...</span>
              </button>
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
                  <h3 class="text-sm font-medium text-blue-800">What happens next?</h3>
                  <div class="mt-2 text-sm text-blue-700">
                    <ul class="list-disc pl-5 space-y-1">
                      <li>You'll be prompted to use your device's biometric authentication</li>
                      <li>Your passkey will be securely stored on this device</li>
                      <li>You can register multiple passkeys on different devices</li>
                      <li>This device will be remembered for 90 days</li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

    <!-- Authenticated layout: Standard in-app container -->
    <div v-else class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 bg-white border-b border-gray-200">
            <!-- Header -->
            <div class="flex items-center mb-6">
              <svg class="h-8 w-8 text-indigo-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
              </svg>
              <h2 class="text-2xl font-semibold text-gray-900">
                Register a New Passkey
              </h2>
            </div>

            <p class="text-sm text-gray-600 mb-6">
              Register additional passkeys for other devices. Passkeys let you sign in using Face ID, Touch ID, Windows Hello, or a security key.
            </p>
          </div>

          <div class="p-6 space-y-6">
            <!-- Success Message -->
            <div v-if="success" class="rounded-lg bg-green-50 p-4 border border-green-200">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-green-500" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <p class="text-sm font-medium text-green-800">{{ success }}</p>
                </div>
              </div>
            </div>

            <!-- Error Message -->
            <div v-if="error" class="rounded-lg bg-red-50 p-4 border border-red-200">
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

            <!-- Registration Form -->
            <div>
              <label for="passkey-name" class="block text-sm font-medium text-gray-700 mb-2">
                Passkey Name (Optional)
              </label>
              <input
                id="passkey-name"
                v-model="passkeyName"
                type="text"
                placeholder="e.g., My iPhone, Work Laptop"
                class="block w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
              />
              <p class="mt-2 text-sm text-gray-500">
                Give this passkey a name to help you identify it later.
              </p>
            </div>

            <div>
              <button
                @click="registerPasskey"
                :disabled="loading"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-lg font-semibold text-sm text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <svg v-if="!loading" class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <svg v-else class="animate-spin h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span v-if="!loading">Register Passkey</span>
                <span v-else>Registering...</span>
              </button>
            </div>

            <!-- Info Box -->
            <div class="rounded-lg bg-blue-50 p-4 border border-blue-200">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-blue-500" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3 flex-1">
                  <h3 class="text-sm font-medium text-blue-800">What happens next?</h3>
                  <div class="mt-2 text-sm text-blue-700">
                    <ul class="list-disc pl-5 space-y-1">
                      <li>You'll be prompted to use your device's biometric authentication</li>
                      <li>Your passkey will be securely stored on this device</li>
                      <li>You can register multiple passkeys on different devices</li>
                      <li>This device will be remembered for 90 days</li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>

            <!-- Existing Passkeys -->
            <div v-if="hasExistingPasskeys" class="pt-6 border-t border-gray-200 dark:border-gray-700">
              <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Your Passkeys</h3>
              <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                You can register multiple passkeys for different devices.
              </p>
              <Link
                :href="route('profile.edit')"
                class="inline-flex items-center text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
              >
                Manage your passkeys and devices
                <svg class="ml-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  </component>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps({
  hasExistingPasskeys: Boolean,
  isNewUser: Boolean,
});

const loading = ref(false);
const error = ref('');
const success = ref('');
const passkeyName = ref('');

// Use computed to determine which layout to use
const layoutComponent = computed(() => {
  return props.isNewUser ? GuestLayout : AuthenticatedLayout;
});

const registerPasskey = async () => {
  loading.value = true;
  error.value = '';
  success.value = '';

  try {
    // Get registration options from server
    const optionsResponse = await fetch('/webauthn/register/options', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        name: passkeyName.value || 'My Passkey',
      }),
    });

    if (!optionsResponse.ok) {
      const errorText = await optionsResponse.text();
      console.error('Registration options error:', errorText);
      console.error('Response status:', optionsResponse.status);
      throw new Error(`Failed to get registration options: ${optionsResponse.status}`);
    }

    const options = await optionsResponse.json();
    console.log('Registration options received:', options);

    // The Laragear package returns data directly, not nested in publicKey
    const publicKey = {
      challenge: base64urlToBuffer(options.challenge),
      rp: {
        name: options.rp.name,
        id: options.rp.id,
      },
      user: {
        id: base64urlToBuffer(options.user.id),
        name: options.user.name,
        displayName: options.user.displayName,
      },
      pubKeyCredParams: options.pubKeyCredParams,
      timeout: options.timeout,
      excludeCredentials: options.excludeCredentials?.map(cred => ({
        type: cred.type,
        id: base64urlToBuffer(cred.id),
        transports: cred.transports,
      })) || [],
      authenticatorSelection: options.authenticatorSelection || {},
      attestation: options.attestation || 'none',
    };

    // Create credential
    const credential = await navigator.credentials.create({ publicKey });

    if (!credential) {
      throw new Error('No credential created');
    }

    // Prepare credential for server
    const credentialData = {
      id: credential.id,
      rawId: bufferToBase64url(credential.rawId),
      type: credential.type,
      response: {
        attestationObject: bufferToBase64url(credential.response.attestationObject),
        clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
      },
    };

    // Send credential to server
    const registerResponse = await fetch('/webauthn/register', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      },
      body: JSON.stringify(credentialData),
    });

    console.log('Register response status:', registerResponse.status);
    console.log('Register response ok:', registerResponse.ok);

    if (registerResponse.ok) {
      success.value = 'Passkey registered successfully! Redirecting...';
      passkeyName.value = '';
      
      console.log('Passkey registration successful, redirecting to /budgets in 2 seconds');
      
      // Force redirect after successful registration
      setTimeout(() => {
        console.log('Executing redirect now to:', window.location.origin + '/budgets');
        // Try multiple redirect methods for better compatibility
        try {
          window.location.href = '/budgets';
        } catch (e) {
          console.error('window.location.href failed:', e);
          try {
            window.location.replace('/budgets');
          } catch (e2) {
            console.error('window.location.replace failed:', e2);
            // Last resort - use router
            router.visit('/budgets', { method: 'get', replace: true });
          }
        }
      }, 2000);
    } else {
      console.error('Registration response not OK. Status:', registerResponse.status);
      const errorData = await registerResponse.json().catch(() => ({ message: 'Unknown error' }));
      console.error('Registration failed with data:', errorData);
      throw new Error(errorData.message || 'Registration failed');
    }
  } catch (err) {
    console.error('Passkey registration error:', err);
    
    if (err.name === 'NotAllowedError') {
      error.value = 'Registration was cancelled or timed out.';
    } else if (err.name === 'InvalidStateError') {
      error.value = 'This passkey is already registered.';
    } else {
      error.value = err.message || 'Could not register passkey. Please try again.';
    }
  } finally {
    loading.value = false;
  }
};

// Helper functions
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
