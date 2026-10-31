<template>
  <Head :title="`Sharing · ${budget.name}`" />

  <AuthenticatedLayout>
    <div class="py-8">
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        <!-- Header -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Sharing</h1>
          <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            People with access to <span class="font-medium">{{ budget.name }}</span>.
            You are {{ article(role) }} <span class="font-medium">{{ role }}</span><span v-if="isPrimaryOwner"> (primary owner)</span>.
          </p>
          <dl class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs text-gray-600 dark:text-gray-400">
            <div class="rounded-md bg-gray-50 dark:bg-gray-700/50 p-3">
              <dt class="font-semibold text-gray-800 dark:text-gray-200">Viewer</dt>
              <dd>Can see everything, change nothing.</dd>
            </div>
            <div class="rounded-md bg-gray-50 dark:bg-gray-700/50 p-3">
              <dt class="font-semibold text-gray-800 dark:text-gray-200">Editor</dt>
              <dd>Can change transactions, accounts, categories and plans, and sync banks.</dd>
            </div>
            <div class="rounded-md bg-gray-50 dark:bg-gray-700/50 p-3">
              <dt class="font-semibold text-gray-800 dark:text-gray-200">Owner</dt>
              <dd>Full control: settings, sharing, bank connections and deletion.</dd>
            </div>
          </dl>
        </div>

        <!-- Invite -->
        <div v-if="can.manage" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Invite people</h2>
          <form class="mt-4 flex flex-col sm:flex-row gap-3" @submit.prevent="invite">
            <div class="flex-1">
              <label for="invite-email" class="sr-only">Email address</label>
              <input
                id="invite-email"
                v-model="inviteForm.email"
                type="email"
                required
                placeholder="name@example.com"
                class="block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              />
              <InputError class="mt-2" :message="inviteForm.errors.email" />
            </div>
            <div>
              <label for="invite-role" class="sr-only">Role</label>
              <select
                id="invite-role"
                v-model="inviteForm.role"
                class="block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              >
                <option v-for="option in roles" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
              <InputError class="mt-2" :message="inviteForm.errors.role" />
            </div>
            <button
              type="submit"
              :disabled="inviteForm.processing"
              class="bg-blue-500 hover:bg-blue-600 disabled:opacity-50 text-white font-semibold py-2 px-4 rounded"
            >
              Send invite
            </button>
          </form>
          <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Invitations are emailed, can be used once and expire after 7 days. People without an account can create one from the link.
          </p>
        </div>

        <!-- Members -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
          <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Members ({{ members.length }})</h2>
            <InputError class="mt-2" :message="errors.member || errors.user_id || errors.membership" />
          </div>
          <ul class="divide-y divide-gray-200 dark:divide-gray-700">
            <li
              v-for="member in members"
              :key="member.id"
              class="px-6 py-4 flex flex-col sm:flex-row sm:items-center gap-3"
              :data-member="member.email"
            >
              <div class="flex items-center gap-3 flex-1 min-w-0">
                <div class="flex-shrink-0 w-9 h-9 rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 flex items-center justify-center text-xs font-bold">
                  {{ initials(member.name) }}
                </div>
                <div class="min-w-0">
                  <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                    {{ member.name }}
                    <span v-if="member.is_you" class="ml-1 text-xs text-gray-500">(you)</span>
                  </div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ member.email }}</div>
                </div>
              </div>

              <div class="flex items-center gap-2 flex-wrap">
                <template v-if="member.is_primary_owner">
                  <span class="rounded-full bg-indigo-100 dark:bg-indigo-900/40 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:text-indigo-300">
                    Owner · primary
                  </span>
                </template>
                <template v-else-if="can.manage">
                  <label :for="`role-${member.id}`" class="sr-only">Role for {{ member.name }}</label>
                  <select
                    :id="`role-${member.id}`"
                    :value="member.role"
                    class="text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                    @change="changeRole(member, $event.target.value)"
                  >
                    <option v-for="option in roles" :key="option.value" :value="option.value">{{ option.label }}</option>
                  </select>
                  <button
                    v-if="can.transfer"
                    type="button"
                    class="text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400"
                    @click="transferOwnership(member)"
                  >
                    Make primary owner
                  </button>
                  <button
                    v-if="!member.is_you"
                    type="button"
                    class="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400"
                    @click="removeMember(member)"
                  >
                    Remove
                  </button>
                </template>
                <span
                  v-else
                  class="rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-700 dark:text-gray-300 capitalize"
                >
                  {{ member.role }}
                </span>
              </div>
            </li>
          </ul>
        </div>

        <!-- Pending invitations -->
        <div v-if="can.manage" class="bg-white dark:bg-gray-800 rounded-lg shadow">
          <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Pending invitations ({{ invitations.length }})</h2>
          </div>
          <p v-if="invitations.length === 0" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
            No pending invitations.
          </p>
          <ul v-else class="divide-y divide-gray-200 dark:divide-gray-700">
            <li
              v-for="invitation in invitations"
              :key="invitation.id"
              class="px-6 py-4 flex flex-col sm:flex-row sm:items-center gap-2"
            >
              <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ invitation.email }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                  <span class="capitalize">{{ invitation.role }}</span>
                  <span v-if="invitation.invited_by"> · invited by {{ invitation.invited_by }}</span>
                  · expires {{ formatDate(invitation.expires_at) }}
                </div>
              </div>
              <button
                type="button"
                class="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400"
                @click="revokeInvitation(invitation)"
              >
                Revoke
              </button>
            </li>
          </ul>
        </div>

        <!-- Leave -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Leave this budget</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
              <template v-if="isPrimaryOwner">
                Primary ownership passes to another owner. If you are the only owner, make someone else an owner first.
              </template>
              <template v-else>
                You will lose access until someone invites you again.
              </template>
            </p>
          </div>
          <button
            type="button"
            class="bg-white border border-red-300 text-red-700 hover:bg-red-50 dark:bg-gray-800 dark:border-red-700 dark:text-red-400 font-semibold py-2 px-4 rounded"
            @click="leave"
          >
            Leave budget
          </button>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
  budget: { type: Object, required: true },
  role: { type: String, default: null },
  isPrimaryOwner: { type: Boolean, default: false },
  members: { type: Array, required: true },
  invitations: { type: Array, default: () => [] },
  roles: { type: Array, required: true },
  can: { type: Object, required: true },
});

const page = usePage();
const toast = useToast();
const errors = computed(() => page.props.errors || {});

const inviteForm = useForm({
  email: '',
  role: 'viewer',
});

// Show the outcome each action flashes (flash.message / flash.error). After
// leaving, this runs once the page the user lands on has rendered.
const showFlash = (visited) => {
  const { message, error } = visited.props.flash || {};
  if (message) toast.success(message);
  if (error) toast.error(error);
};

const article = (role) => (role === 'editor' || role === 'owner' ? 'an' : 'a');

const initials = (name) => {
  if (!name) return '?';
  const words = name.trim().split(/\s+/);
  return (words.length === 1 ? words[0].substring(0, 2) : words[0][0] + words[1][0]).toUpperCase();
};

const formatDate = (iso) => new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });

const invite = () => {
  inviteForm.post(route('budgets.invitations.store', props.budget.id), {
    preserveScroll: true,
    onSuccess: (visited) => {
      inviteForm.reset('email');
      showFlash(visited);
    },
  });
};

const changeRole = (member, role) => {
  router.patch(route('budgets.members.update', [props.budget.id, member.id]), { role }, { preserveScroll: true, onSuccess: showFlash });
};

const removeMember = (member) => {
  if (!confirm(`Remove ${member.name} from this budget?`)) return;
  router.delete(route('budgets.members.destroy', [props.budget.id, member.id]), { preserveScroll: true, onSuccess: showFlash });
};

const transferOwnership = (member) => {
  if (!confirm(`Make ${member.name} the primary owner? You will stay on as an owner.`)) return;
  router.post(route('budgets.ownership.transfer', props.budget.id), { user_id: member.id }, { preserveScroll: true, onSuccess: showFlash });
};

const revokeInvitation = (invitation) => {
  if (!confirm(`Revoke the invitation for ${invitation.email}?`)) return;
  router.delete(route('budgets.invitations.destroy', [props.budget.id, invitation.id]), { preserveScroll: true, onSuccess: showFlash });
};

const leave = () => {
  if (!confirm(`Leave "${props.budget.name}"?`)) return;
  router.delete(route('budgets.membership.leave', props.budget.id), { onSuccess: showFlash });
};
</script>
