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

// Also configure Axios to send cookies with requests
window.axios.defaults.withCredentials = true;
