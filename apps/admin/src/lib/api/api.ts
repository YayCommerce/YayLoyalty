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
          // eslint-disable-next-line @typescript-eslint/no-explicit-any
          const body: any = await response.json();

          if (body?.code === 'rest_cookie_invalid_nonce') {
            // if session expired
            location.reload();
          }

          // A capability or ceiling refusal from the backbone: one readable
          // notice, never a raw error or a silent no-op. The error still
          // propagates so callers can reset their own state.
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
 * The WP REST `WP_Error` shape (`{code, message, data:{status}}`) turned into
 * one string a toast can show. `error instanceof HTTPError` is how `ky`
 * signals a non-2xx response. A plain `Error` (e.g. a client-side guard like
 * `fetchAllMatchingCustomers`'s page-count ceiling) uses its own `.message`
 * instead — still a message this codebase wrote on purpose, unlike a raw
 * network failure or a non-JSON body, which fall back to `fallback` rather
 * than surfacing "[object Object]" or a browser-internal string.
 * A 403 with a `FORBIDDEN_ERROR_CODES` code has already been toasted by the
 * `afterResponse` hook above — callers that only want to handle the REST
 * cases that hook doesn't cover should check `error.response.status` first.
 */
export async function getApiErrorMessage(error: unknown, fallback: string): Promise<string> {
  if (error instanceof HTTPError) {
    try {
      const body: { message?: unknown } = await error.response.json();
      if (typeof body?.message === 'string' && body.message.length > 0) {
        return body.message;
      }
    } catch {
      // Response body wasn't JSON — fall through to the generic fallback.
    }
  } else if (error instanceof Error && error.message.length > 0) {
    return error.message;
  }

  return fallback;
}
