<template>
  <Head title="Register Passkey" />

  <AuthenticatedLayout>
    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="flex items-center mb-6">
              <svg class="h-8 w-8 text-indigo-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
              </svg>
              <h2 class="text-2xl font-bold text-gray-900">Register a Passkey</h2>
            </div>

            <p class="text-gray-600 mb-6">
              Passkeys let you sign in using Face ID, Touch ID, Windows Hello, or a security key. They're more secure than passwords and much easier to use.
            </p>

            <!-- Success Message -->
            <div v-if="success" class="mb-6 rounded-md bg-green-50 p-4">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <p class="text-sm font-medium text-green-800">{{ success }}</p>
                </div>
              </div>
            </div>

            <!-- Error Message -->
            <div v-if="error" class="mb-6 rounded-md bg-red-50 p-4">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <p class="text-sm font-medium text-red-800">{{ error }}</p>
                </div>
              </div>
            </div>

            <!-- Registration Form -->
            <div class="space-y-6">
              <div>
                <label for="passkey-name" class="block text-sm font-medium text-gray-700 mb-2">
                  Passkey Name (Optional)
                </label>
                <input
                  id="passkey-name"
                  v-model="passkeyName"
                  type="text"
                  placeholder="e.g., My iPhone, Work Laptop"
                  class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                />
                <p class="mt-2 text-sm text-gray-500">
                  Give this passkey a name to help you identify it later.
                </p>
              </div>

              <div>
                <button
                  @click="registerPasskey"
                  :disabled="loading"
                  class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
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
              <div class="rounded-md bg-blue-50 p-4">
                <div class="flex">
                  <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
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
              <div v-if="hasExistingPasskeys" class="mt-8">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Your Passkeys</h3>
                <p class="text-sm text-gray-600 mb-4">
                  You can register multiple passkeys for different devices.
                </p>
                <Link
                  :href="route('settings.devices')"
                  class="text-sm text-indigo-600 hover:text-indigo-500"
                >
                  Manage all devices →
                </Link>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
  hasExistingPasskeys: Boolean,
});

const loading = ref(false);
const error = ref('');
const success = ref('');
const passkeyName = ref('');

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
      throw new Error('Failed to get registration options');
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

    if (registerResponse.ok) {
      success.value = 'Passkey registered successfully! You can now use it to sign in.';
      passkeyName.value = '';
    } else {
      const errorData = await registerResponse.json();
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

