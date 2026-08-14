import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { login, logout, loadTestAccount, DEFAULT_PASSWORD } from './helpers.js';

/**
 * NFR-PERF-002: 50 concurrent users logging in and out over 2 minutes.
 * NFR-PERF-001: p95 response time < 3000ms.
 *
 * Each VU maps to one of the 50 LoadTestAccountSeeder fixtures and repeats
 * a login -> short pause -> logout cycle for the whole test window. Multiple
 * VUs sharing the same underlying 50-account pool when VUs > 50 is
 * deliberate (round-robins via loadTestAccount()); login concurrency on the
 * same account is itself a realistic thing to load, since LoginController
 * writes to that user's own row (failed_login_attempts/last_login_at) on
 * every attempt (CLAUDE.md Section 6 `users` table).
 */
export const options = {
  scenarios: {
    login_logout: {
      executor: 'constant-vus',
      vus: 50,
      duration: '2m',
    },
  },
  thresholds: {
    'http_req_duration{name:login}': ['p(95)<3000'],
    'http_req_duration{name:logout}': ['p(95)<3000'],
    'http_req_failed{name:login}': ['rate<0.01'],
    'http_req_failed{name:logout}': ['rate<0.01'],
  },
};

const loginTrend = new Trend('login_duration', true);
const logoutTrend = new Trend('logout_duration', true);

export default function () {
  const email = loadTestAccount(__VU - 1);

  const loginRes = login(email, DEFAULT_PASSWORD);
  loginTrend.add(loginRes.timings.duration);
  check(loginRes, {
    'login succeeded (200)': (r) => r.status === 200,
    'login under 3000ms': (r) => r.timings.duration < 3000,
  });

  sleep(1);

  const logoutRes = logout();
  logoutTrend.add(logoutRes.timings.duration);
  check(logoutRes, {
    'logout succeeded (2xx)': (r) => r.status >= 200 && r.status < 300,
    'logout under 3000ms': (r) => r.timings.duration < 3000,
  });

  sleep(1);
}
