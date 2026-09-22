/**
 * Thin fetch wrapper around the platform API.
 *
 * The console is served from the same origin as the API, so the session cookie
 * rides along with `credentials: 'same-origin'`; mutating calls additionally
 * need the CSRF token handed out by /auth/me (or /auth/login).
 */

let csrfToken = null;

// The console is always served from "<app root>/superadmin/", so the API root
// is "<app root>/api/v1" — derived from Vite's BASE_URL, which keeps one build
// shape working from the web root or any sub-directory (e.g. /restaurants/).
const apiRoot = import.meta.env.BASE_URL.replace(/superadmin\/?$/, '');
const API_BASE = import.meta.env.VITE_API_BASE || `${apiRoot}api/v1`;

export const setCsrfToken = (token) => {
  csrfToken = token || null;
};

export const getCsrfToken = () => csrfToken;

export class ApiError extends Error {
  constructor(message, status, code, details) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details || {};
  }
}

async function request(method, path, body) {
  const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;
  const headers = { accept: 'application/json' };
  if (body !== undefined && !isFormData) headers['content-type'] = 'application/json';
  if (csrfToken && method !== 'GET') headers['x-csrf-token'] = csrfToken;

  let response;
  try {
    response = await fetch(`${API_BASE}${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : isFormData ? body : JSON.stringify(body),
    });
  } catch (networkError) {
    throw new ApiError('Cannot reach the platform API.', 0, 'network_error');
  }

  const text = await response.text();
  let payload = null;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
    }
  }

  if (!response.ok) {
    const error = payload?.error || {};
    if (response.status === 401 && !path.startsWith('/auth/login') && !path.startsWith('/auth/logout')) {
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('platform:unauthorized'));
      }
    }
    throw new ApiError(
      error.message || `Request failed with status ${response.status}`,
      response.status,
      error.code,
      error.details,
    );
  }

  return payload ?? {};
}

const query = (params = {}) => {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '' || value === 'all') return;
    search.set(key, value);
  });
  const string = search.toString();
  return string ? `?${string}` : '';
};

export const api = {
  get: (path, params) => request('GET', `${path}${query(params)}`),
  post: (path, body) => request('POST', path, body ?? {}),
  patch: (path, body) => request('PATCH', path, body ?? {}),
  delete: (path, body) => request('DELETE', path, body ?? {}),
  upload: (path, formData) => request('POST', path, formData),
  url: (path) => `${API_BASE}${path}`,
};

export { API_BASE };


