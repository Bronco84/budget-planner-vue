<template>
  <span class="inline-flex flex-wrap items-center gap-2" data-testid="removed-by-bank">
    <span
      class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 cursor-help"
      title="Your bank no longer reports this pending transaction (for example a voided hold or a cancelled order). It is left out of balances, totals and projections until you keep or delete it."
    >
      Removed by bank
    </span>
    <template v-if="canEdit">
      <button
        type="button"
        class="text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded"
        :disabled="busy"
        data-testid="removed-by-bank-keep"
        @click="keep"
      >
        Keep
      </button>
      <button
        type="button"
        class="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 focus:outline-none focus:ring-2 focus:ring-red-500 rounded"
        :disabled="busy"
        data-testid="removed-by-bank-delete"
        @click="remove"
      >
        Delete
      </button>
    </template>
  </span>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';
import { useBudgetAccess } from '@/composables/useBudgetAccess';

const props = defineProps({
  budgetId: { type: Number, required: true },
  transaction: { type: Object, required: true },
});

const toast = useToast();
const { canEdit } = useBudgetAccess();
const busy = ref(false);

const visitOptions = {
  preserveScroll: true,
  onStart: () => { busy.value = true; },
  onFinish: () => { busy.value = false; },
  onSuccess: (visited) => toast.flash(visited),
};

function keep() {
  router.patch(route('budget.transaction.keep', [props.budgetId, props.transaction.id]), {}, visitOptions);
}

async function remove() {
  const confirmed = await toast.confirm({
    title: 'Delete Transaction',
    message: 'Your bank no longer reports this transaction. Delete it?',
    confirmText: 'Delete',
    cancelText: 'Cancel',
    type: 'danger',
  });

  if (confirmed) {
    router.delete(route('budget.transaction.destroy', [props.budgetId, props.transaction.id]), visitOptions);
  }
}
</script>
