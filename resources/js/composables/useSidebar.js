import { ref, onMounted } from 'vue';

const SIDEBAR_STORAGE_KEY = 'sidebar-collapsed';

// Shared state across all components
const isCollapsed = ref(false);
const isMobileOpen = ref(false);

export function useSidebar() {
    // Initialize from localStorage on mount
    onMounted(() => {
        const stored = localStorage.getItem(SIDEBAR_STORAGE_KEY);
        if (stored !== null) {
            isCollapsed.value = stored === 'true';
        }
    });

    const toggleCollapsed = () => {
        isCollapsed.value = !isCollapsed.value;
        localStorage.setItem(SIDEBAR_STORAGE_KEY, isCollapsed.value.toString());
    };

    const openMobile = () => {
        isMobileOpen.value = true;
        // Prevent body scroll when mobile sidebar is open
        document.body.style.overflow = 'hidden';
    };

    const closeMobile = () => {
        isMobileOpen.value = false;
        // Restore body scroll
        document.body.style.overflow = '';
    };

    return {
        isCollapsed,
        isMobileOpen,
        toggleCollapsed,
        openMobile,
        closeMobile
    };
}
