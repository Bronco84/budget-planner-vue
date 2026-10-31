import { fetchFreshCsrfToken } from '@/bootstrap';

function currentCsrfToken() {
    return document.head.querySelector('meta[name="csrf-token"]')?.content || '';
}

function send(url, options, token) {
    // Caller headers win, except the CSRF token, which always comes from the
    // live meta tag (or the freshly fetched token on retry).
    const headers = new Headers({ Accept: 'application/json' });
    new Headers(options.headers || {}).forEach((value, name) => headers.set(name, value));
    headers.set('X-CSRF-TOKEN', token);

    return fetch(url, { credentials: 'same-origin', ...options, headers });
}

/**
 * fetch() for same-origin write requests (POST/PUT/PATCH/DELETE).
 *
 * Sends the current CSRF token and Accept: application/json. If Laravel
 * answers 419 (the session token rotated under a page that never navigated),
 * it fetches a fresh token from GET /csrf-token — sharing the axios
 * interceptor's single in-flight refresh — and replays the request once.
 * Resolves with the Response exactly like fetch(); callers keep handling
 * response.ok / status themselves.
 */
export async function apiFetch(url, options = {}) {
    const response = await send(url, options, currentCsrfToken());

    if (response.status !== 419) {
        return response;
    }

    let freshToken;
    try {
        freshToken = await fetchFreshCsrfToken();
    } catch {
        return response;
    }

    return send(url, options, freshToken);
}
