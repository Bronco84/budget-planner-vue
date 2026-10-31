import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * The signed-in user's role on the budget in the current route, from the
 * `budgetAccess` shared prop (HandleInertiaRequests). Presentation only: the
 * server enforces every permission. Off budget routes everything is allowed so
 * pages without budget context are unaffected.
 */
export function useBudgetAccess() {
    const page = usePage();
    const access = computed(() => page.props.budgetAccess || null);

    const allowed = (key) => computed(() => (access.value ? Boolean(access.value.can?.[key]) : true));

    return {
        role: computed(() => access.value?.role ?? null),
        canEdit: allowed('edit'),
        canManage: allowed('manage'),
        canManageConnections: allowed('manage_connections'),
    };
}
