import { toast } from 'vue-sonner';

export function useToast() {
    return {
        success(message, options = {}) {
            toast.success(message, {
                duration: 4000,
                ...options
            });
        },

        error(message, options = {}) {
            toast.error(message, {
                duration: 6000,
                ...options
            });
        },

        warning(message, options = {}) {
            toast.warning(message, {
                duration: 5000,
                ...options
            });
        },

        info(message, options = {}) {
            toast.info(message, {
                duration: 4000,
                ...options
            });
        },

        /**
         * Toast the outcome a redirect flashed (flash.message / flash.error).
         * Pass it the page an Inertia visit's onSuccess receives; Inertia
         * treats a redirect back as success, so a flashed refusal is only
         * visible if the caller shows it.
         * @param {Object} visited - The page object from onSuccess
         */
        flash(visited) {
            const { message, error } = visited?.props?.flash || {};
            if (message) this.success(message);
            if (error) this.error(error);
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


