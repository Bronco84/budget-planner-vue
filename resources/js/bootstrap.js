import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Function to get the current CSRF token from the page
function getCsrfToken() {
    const token = document.head.querySelector('meta[name="csrf-token"]');
    return token ? token.content : null;
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

// Gracefully handle CSRF token expiration (419 errors)
// This interceptor will automatically retry the request with a fresh token
// The user won't see any error - it happens transparently
window.axios.interceptors.response.use(
    response => response,
    async error => {
        const originalRequest = error.config;
        
        // Check if it's a 419 error and we haven't already retried this request
        if (error.response?.status === 419 && !originalRequest._retry) {
            originalRequest._retry = true; // Mark this request as retried to prevent infinite loops
            
            try {
                console.log('🔄 CSRF token expired, fetching fresh token...');
                
                // Get a fresh CSRF token from our custom endpoint
                // We use fetch here instead of axios to avoid triggering another interceptor
                const response = await fetch('/csrf-token', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                    }
                });
                
                if (!response.ok) {
                    throw new Error('Failed to fetch CSRF token');
                }
                
                const data = await response.json();
                const newToken = data.csrf_token;
                
                if (newToken) {
                    console.log('✅ Fresh CSRF token received, retrying request...');
                    
                    // Update the meta tag so other code can access the new token
                    const metaTag = document.head.querySelector('meta[name="csrf-token"]');
                    if (metaTag) {
                        metaTag.content = newToken;
                    }
                    
                    // Update axios defaults for future requests
                    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = newToken;
                    
                    // Update this specific request's header
                    originalRequest.headers['X-CSRF-TOKEN'] = newToken;
                    
                    // Retry the original request with the fresh token
                    return window.axios.request(originalRequest);
                } else {
                    throw new Error('No CSRF token in response');
                }
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
