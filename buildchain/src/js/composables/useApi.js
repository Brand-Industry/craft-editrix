import { ref } from 'vue';

export function useApi() {
  const loading = ref(false);
  const error = ref(null);

  const getCsrfToken = () => {
    return window.Craft?.csrfTokenValue || '';
  };

  const getCsrfTokenName = () => {
    return window.Craft?.csrfTokenName || 'CRAFT_CSRF_TOKEN';
  };

  const request = async (url, options = {}) => {
    loading.value = true;
    error.value = null;

    try {
      const headers = {
        'Accept': 'application/json',
        ...options.headers,
      };

      // Add CSRF token for POST requests
      if (options.method === 'POST') {
        if (options.body instanceof FormData) {
          options.body.append(getCsrfTokenName(), getCsrfToken());
        } else {
          headers['Content-Type'] = 'application/json';
          headers['X-CSRF-Token'] = getCsrfToken();
        }
      }

      const response = await fetch(url, {
        ...options,
        headers,
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || `Request failed with status ${response.status}`);
      }

      return data;
    } catch (err) {
      error.value = err.message;
      throw err;
    } finally {
      loading.value = false;
    }
  };

  const post = async (url, data = {}) => {
    const formData = new FormData();

    const appendData = (key, value) => {
      if (value === null || value === undefined) {
        return;
      }
      if (typeof value === 'boolean') {
        formData.append(key, value ? '1' : '0');
      } else if (Array.isArray(value)) {
        value.forEach((item, index) => {
          if (typeof item === 'object') {
            formData.append(key, JSON.stringify(item));
          } else {
            formData.append(`${key}[]`, item);
          }
        });
      } else if (typeof value === 'object') {
        formData.append(key, JSON.stringify(value));
      } else {
        formData.append(key, String(value));
      }
    };

    Object.entries(data).forEach(([key, value]) => {
      appendData(key, value);
    });

    return request(url, {
      method: 'POST',
      body: formData,
    });
  };

  const get = async (url, params = {}) => {
    const searchParams = new URLSearchParams();
    
    Object.entries(params).forEach(([key, value]) => {
      if (value !== null && value !== undefined) {
        if (Array.isArray(value)) {
          value.forEach(v => searchParams.append(`${key}[]`, v));
        } else {
          searchParams.append(key, String(value));
        }
      }
    });

    const queryString = searchParams.toString();
    const fullUrl = queryString ? `${url}?${queryString}` : url;

    return request(fullUrl, {
      method: 'GET',
    });
  };

  return {
    loading,
    error,
    request,
    post,
    get,
  };
}
