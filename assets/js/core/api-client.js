/**
 * PolymerOps Master Vault - Core API Client
 * 
 * Secure HTTP client handling:
 * - Automatic CSRF header injection ('X-CSRF-Token')
 * - Timeout interception & AbortController integration
 * - Consistent JSON parsing & error mapping
 */

export class ApiClient {
  constructor(timeoutMs = 20000) {
    this.timeoutMs = timeoutMs;
    this.csrfToken = '';
  }

  setCsrfToken(token) {
    this.csrfToken = token || '';
  }

  async request(url, options = {}) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.timeoutMs);

    const method = (options.method || 'GET').toUpperCase();
    const headers = {
      'Accept': 'application/json',
      ...(options.headers || {}),
    };

    if (options.body !== undefined && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }

    if (method !== 'GET' && this.csrfToken) {
      headers['X-CSRF-Token'] = this.csrfToken;
    }

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        method,
        headers,
        signal: controller.signal,
      });

      let data;
      try {
        data = await response.json();
      } catch (_) {
        data = { ok: false, error: 'Invalid JSON response from server.' };
      }

      if (!response.ok || data.ok === false) {
        const error = new Error(data.error || `HTTP error ${response.status}`);
        error.status = response.status;
        error.code = data.code || 'REQUEST_FAILED';
        error.data = data;
        throw error;
      }

      return data;
    } catch (err) {
      if (err.name === 'AbortError') {
        throw new Error('Server request timed out. Please verify your connection.');
      }
      throw err;
    } finally {
      clearTimeout(timeoutId);
    }
  }

  get(url, options = {}) {
    return this.request(url, { ...options, method: 'GET' });
  }

  post(url, body = {}, options = {}) {
    return this.request(url, { ...options, method: 'POST', body: JSON.stringify(body) });
  }

  patch(url, body = {}, options = {}) {
    return this.request(url, { ...options, method: 'PATCH', body: JSON.stringify(body) });
  }

  delete(url, options = {}) {
    return this.request(url, { ...options, method: 'DELETE' });
  }
}

export const api = new ApiClient();
