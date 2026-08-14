import http from 'k6/http';

/**
 * Shared helpers for the k6 load test scripts in this directory
 * (NFR-PERF-001/002, TDD-ADR-001's confirmed k6 load-testing tool).
 *
 * These scripts hit a real backend (Sanctum SPA cookie-based session auth,
 * TDD-ADR-002), not a mocked one, so every script must reproduce the exact
 * handshake the React SPA performs (src/api/client.ts): fetch a CSRF cookie,
 * then send it back as the X-XSRF-TOKEN header on every state-changing
 * request. The Origin header must also match one of SANCTUM_STATEFUL_DOMAINS
 * (config/sanctum.php / .env) or Laravel treats the request as stateless and
 * every request 401s — see CLAUDE.md Session 24's note on the same handshake
 * gap in the frontend's own first-login path.
 */

export const BASE_URL = __ENV.K6_BASE_URL || 'http://127.0.0.1:8000';
export const ORIGIN = __ENV.K6_ORIGIN || 'http://localhost:5173';
export const DEFAULT_PASSWORD = __ENV.TW_TEST_DEFAULT_PW || 'QaTest!2026Pw';

/**
 * Load-test fixture accounts seeded by database/seeders/LoadTestAccountSeeder.php
 * (50 Ministry Attache accounts, round-robin assigned across all 17 missions;
 * loadtest.user00..16 cover all 17 missions exactly once). Not the
 * QaTestAccountSeeder fixtures — those back the Playwright suites and should
 * not also absorb k6 concurrency.
 */
export function loadTestAccount(index) {
  return `loadtest.user${String(index % 50).padStart(2, '0')}@tradewatch.go.ke`;
}

function xsrfHeaderFrom(jar, url) {
  const cookies = jar.cookiesForURL(url);
  const raw = cookies['XSRF-TOKEN'] && cookies['XSRF-TOKEN'][0];

  return raw ? decodeURIComponent(raw) : '';
}

/** GET /sanctum/csrf-cookie, required before the first request in every session. */
export function fetchCsrfCookie() {
  return http.get(`${BASE_URL}/sanctum/csrf-cookie`, {
    headers: { Origin: ORIGIN },
    tags: { name: 'csrf-cookie' },
  });
}

/**
 * Logs in via POST /api/v1/login using the calling VU's own cookie jar
 * (k6's default per-VU jar auto-attaches the session cookie to every
 * subsequent request in this VU/iteration, same as a browser). Returns the
 * login response so callers can assert on it.
 */
export function login(email, password = DEFAULT_PASSWORD) {
  const jar = http.cookieJar();

  fetchCsrfCookie();

  const xsrf = xsrfHeaderFrom(jar, `${BASE_URL}/`);

  return http.post(
    `${BASE_URL}/api/v1/login`,
    JSON.stringify({ email, password }),
    {
      headers: {
        Origin: ORIGIN,
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': xsrf,
      },
      tags: { name: 'login' },
    },
  );
}

export function logout() {
  const jar = http.cookieJar();
  const xsrf = xsrfHeaderFrom(jar, `${BASE_URL}/`);

  return http.post(`${BASE_URL}/api/v1/logout`, null, {
    headers: {
      Origin: ORIGIN,
      Accept: 'application/json',
      'X-XSRF-TOKEN': xsrf,
    },
    tags: { name: 'logout' },
  });
}

/** Authenticated GET, relying on the VU's cookie jar from an earlier login(). */
export function authedGet(path, tagName) {
  return http.get(`${BASE_URL}${path}`, {
    headers: { Origin: ORIGIN, Accept: 'application/json' },
    tags: { name: tagName || path },
  });
}

/** Authenticated state-changing request (POST/PATCH/DELETE), CSRF-header included. */
export function authedWrite(method, path, body, tagName) {
  const jar = http.cookieJar();
  const xsrf = xsrfHeaderFrom(jar, `${BASE_URL}/`);

  return http.request(method, `${BASE_URL}${path}`, body === null ? null : JSON.stringify(body), {
    headers: {
      Origin: ORIGIN,
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': xsrf,
    },
    tags: { name: tagName || path },
  });
}
