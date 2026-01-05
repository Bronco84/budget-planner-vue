import { useToast as useToastification } from 'vue-toastification';

export function useToast() {
    const toast = useToastification();

    return {
        success(message, options = {}) {
            toast.success(message, {
                timeout: 4000,
                ...options
            });
        },

        error(message, options = {}) {
            toast.error(message, {
                timeout: 6000,
                ...options
            });
        },

        warning(message, options = {}) {
            toast.warning(message, {
                timeout: 5000,
                ...options
            });
        },

        info(message, options = {}) {
            toast.info(message, {
                timeout: 4000,
                ...options
            });
        },

        /**
         * Show a confirmation dialog
         * @param {Object} options - Configuration options
         * @param {string} options.title - Dialog title
         * @param {string} options.message - Dialog message
         * @param {string} options.confirmText - Confirm button text (default: 'Confirm')
         * @param {string} options.cancelText - Cancel button text (default: 'Cancel')
         * @param {string} options.type - Dialog type: 'danger', 'warning', 'info' (default: 'warning')
         * @returns {Promise<boolean>} - Resolves to true if confirmed, false if cancelled
         */
        confirm(options = {}) {
            return new Promise((resolve) => {
                // We'll emit an event that the ConfirmDialog component will listen to
                const event = new CustomEvent('show-confirm-dialog', {
                    detail: {
                        ...options,
                        resolve
                    }
                });
                window.dispatchEvent(event);
            });
        }
    };
}


