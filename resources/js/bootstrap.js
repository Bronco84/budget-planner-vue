import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Function to get the current CSRF token from the page
function getCsrfToken() {
    const token = document.head.querySelector('meta[name="csrf-token"]');
    return token ? token.content : null;
}

/**
 * Write a token everywhere the app reads it from.
 *
 * The meta tag is the single source of truth for the raw `fetch()` callers
 * scattered through the components, so it has to be updated too — not just the
 * axios defaults. Laravel rotates the session token on login/logout, and since
 * navigation after that point is client-side (Inertia never re-renders <head>),
 * nothing would otherwise refresh it and the next write request 419s.
 */
export function setCsrfToken(token) {
    if (!token) {
        return;
    }

    const metaTag = document.head.querySelector('meta[name="csrf-token"]');
    if (metaTag) {
        metaTag.content = token;
    }

    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

// Configure Axios to automatically send CSRF tokens
// Laravel expects either X-CSRF-TOKEN (from meta tag) or X-XSRF-TOKEN (from cookie)
let token = getCsrfToken();

if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
} else {
    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
}

// Refresh CSRF token before each request to ensure it's always current
window.axios.interceptors.request.use(function (config) {
    const currentToken = getCsrfToken();
    if (currentToken) {
        config.headers['X-CSRF-TOKEN'] = currentToken;
    }
    return config;
}, function (error) {
    return Promise.reject(error);
});

// A single in-flight refresh shared by every request that 419s at once (the
// dashboard fires several on load), so they don't stampede /csrf-token and
// clobber each other's token.
let pendingTokenRefresh = null;

function fetchFreshCsrfToken() {
    if (!pendingTokenRefresh) {
        pendingTokenRefresh = fetch('/csrf-token', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
            },
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Failed to fetch CSRF token');
                }
                return response.json();
            })
            .then(data => {
                if (!data.csrf_token) {
                    throw new Error('No CSRF token in response');
                }
                setCsrfToken(data.csrf_token);
                return data.csrf_token;
            })
            .finally(() => {
                pendingTokenRefresh = null;
            });
    }

    return pendingTokenRefresh;
}

// Safety net for CSRF token expiration (419 errors): fetch a fresh token and
// replay the request once. This should be rare — the token is kept in sync on
// every Inertia navigation (see app.js) — so a hit here means the session
// rotated out from under a page that never navigated.
window.axios.interceptors.response.use(
    response => response,
    async error => {
        const originalRequest = error.config;

        // Check if it's a 419 error and we haven't already retried this request
        if (error.response?.status === 419 && !originalRequest._retry) {
            originalRequest._retry = true; // Mark this request as retried to prevent infinite loops

            try {
                console.log('🔄 CSRF token expired, fetching fresh token...');

                const newToken = await fetchFreshCsrfToken();

                console.log('✅ Fresh CSRF token received, retrying request...');

                // Update this specific request's header
                originalRequest.headers['X-CSRF-TOKEN'] = newToken;

                // Retry the original request with the fresh token
                return window.axios.request(originalRequest);
            } catch (refreshError) {
                // If we can't refresh the token, let the error through
                // This might happen if the session is completely dead
                console.error('❌ Failed to refresh CSRF token:', refreshError);
                return Promise.reject(error);
            }
        }

        // For all other errors, just pass them through
        return Promise.reject(error);
    }
);

// Also configure Axios to send cookies with requests
window.axios.defaults.withCredentials = true;
