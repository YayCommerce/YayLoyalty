import ky, { HTTPError, Options, ResponsePromise } from 'ky';
import { toast } from 'sonner';

import { FORBIDDEN_ERROR_CODES } from '@/lib/access';

const _ky = ky.create({
  prefixUrl: window.yayLoyaltyMeta.wpMeta.restRoot,
  headers: {
    'X-WP-Nonce': window.yayLoyaltyMeta.wpMeta.restNonce,
  },
  hooks: {
    afterResponse: [
      async (request, options, response) => {
        if (response.status === 403) {
          // eslint-disable-next-line @typescript-eslint/no-explicit-any -- an error body of unknown shape, read defensively below
          const body: any = await response.json();

          if (body?.code === 'rest_cookie_invalid_nonce') {
            location.reload();
          }

          // One readable notice for a capability refusal; the error still propagates.
          if (FORBIDDEN_ERROR_CODES.includes(body?.code) && typeof body?.message === 'string') {
            toast.error(body.message);
          }
        }
      },
    ],
  },
  timeout: 20000,
});

interface AbstractApi {
  get(input?: string, options?: Options): ResponsePromise;

  post(input?: string, options?: Options): ResponsePromise;

  put(input?: string, options?: Options): ResponsePromise;

  patch(input?: string, options?: Options): ResponsePromise;

  delete(input?: string, options?: Options): ResponsePromise;
}

class PrettyApi implements AbstractApi {
  readonly base: string;

  constructor(base = window.yayLoyaltyMeta.wpMeta.restBase) {
    this.base = base;
  }

  get(input = '', options?: Options) {
    return _ky.get(`${this.base}/${input}`, options);
  }

  post(input = '', options?: Options) {
    return _ky.post(`${this.base}/${input}`, options);
  }

  put(input = '', options?: Options) {
    return _ky.put(`${this.base}/${input}`, options);
  }

  patch(input = '', options?: Options) {
    return _ky.patch(`${this.base}/${input}`, options);
  }

  delete(input = '', options?: Options) {
    return _ky.delete(`${this.base}/${input}`, options);
  }
}

class UglyApi implements AbstractApi {
  readonly restRoute: string;

  constructor(restRoute: string) {
    this.restRoute = restRoute;
  }

  get(input = '', options?: Options) {
    return _ky.get('', {
      ...options,
      searchParams: this._getSearchParams(input, options),
    });
  }

  post(input = '', options?: Options) {
    return _ky.post('', {
      ...options,
      searchParams: this._getSearchParams(input, options),
    });
  }

  put(input = '', options?: Options) {
    return _ky.put('', {
      ...options,
      searchParams: this._getSearchParams(input, options),
    });
  }

  patch(input = '', options?: Options) {
    return _ky.patch('', {
      ...options,
      searchParams: this._getSearchParams(input, options),
    });
  }

  delete(input = '', options?: Options) {
    return _ky.delete('', {
      ...options,
      searchParams: this._getSearchParams(input, options),
    });
  }

  _getSearchParams(input?: string, options?: Options) {
    let searchParams = new URLSearchParams();
    if (options?.searchParams) {
      searchParams = new URLSearchParams(options?.searchParams as Record<string, string>);
    }
    searchParams.set('rest_route', `${this.restRoute}/${input}`);
    return searchParams;
  }
}

export function createApi(base: string): AbstractApi {
  const baseUrl = new URL(`${window.yayLoyaltyMeta.wpMeta.restRoot}${base}`);
  const restRoute = baseUrl.searchParams.get('rest_route');

  if (!restRoute) {
    return new PrettyApi(base);
  } else {
    return new UglyApi(restRoute);
  }
}

export const yayLoyaltyApi = createApi(window.yayLoyaltyMeta.wpMeta.restBase);
export const wordpressApi = createApi('wp/v2');

export const api = yayLoyaltyApi;

/**
 * One toast-ready string from a WP_Error response or a client-side Error.
 * A network failure or a non-JSON body returns `fallback`, never "[object Object]".
 * afterResponse has already toasted a forbidden 403.
 */
export async function getApiErrorMessage(error: unknown, fallback: string): Promise<string> {
  if (error instanceof HTTPError) {
    try {
      const body: { message?: unknown } = await error.response.json();
      if (typeof body?.message === 'string' && body.message.length > 0) {
        return body.message;
      }
    } catch {}
  } else if (error instanceof Error && error.message.length > 0) {
    return error.message;
  }

  return fallback;
}

/**
 * Toasts a failed request, unless afterResponse already toasted it as a
 * capability refusal. Other 403s, such as a built-in campaign refusing to be
 * archived, carry their own message and still need one.
 */
export async function toastApiError(error: unknown, fallback: string): Promise<void> {
  if (error instanceof HTTPError && error.response.status === 403) {
    // A clone: getApiErrorMessage() reads the same body afterwards.
    const body: { code?: unknown } | null = await error.response
      .clone()
      .json()
      .catch(() => null);

    if (typeof body?.code === 'string' && (FORBIDDEN_ERROR_CODES as readonly string[]).includes(body.code)) {
      return;
    }
  }

  toast.error(await getApiErrorMessage(error, fallback));
}

/** Whether a request failed because the server has no such resource. */
export function isNotFoundError(error: unknown): boolean {
  return error instanceof HTTPError && error.response.status === 404;
}

/** The error fetch() throws when its AbortSignal fires. Check before getApiErrorMessage(): a cancellation is not a failure. */
export function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && 'AbortError' === error.name;
}
