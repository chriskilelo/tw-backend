import { check, sleep } from 'k6';
import { login, authedGet, loadTestAccount, DEFAULT_PASSWORD } from './helpers.js';

/**
 * Dashboard load test: 50 concurrent authenticated users polling
 * GET /api/v1/alerts every 5s, the same interval AlertListPage's dashboard
 * polling uses. Asserts p95 < 3000ms (NFR-PERF-001).
 *
 * Each VU logs in once (module scope persists across a constant-vus
 * executor's iterations for that VU, so the session cookie jar survives
 * between polls) then polls on a 5s cadence for the test duration.
 */
export const options = {
  scenarios: {
    poll_alert_list: {
      executor: 'constant-vus',
      vus: 50,
      duration: '2m',
    },
  },
  // Sanctum SPA session auth (TDD-ADR-002) is cookie-based; k6 resets each
  // VU's cookie jar between iterations by default, which would silently
  // drop the session cookie after the first poll and turn every later poll
  // into a 401. Login happens once per VU (module-scoped `authenticated`
  // flag below), so the jar must survive across iterations too.
  noCookiesReset: true,
  thresholds: {
    'http_req_duration{name:alerts-list}': ['p(95)<3000'],
    'http_req_failed{name:alerts-list}': ['rate<0.01'],
  },
};

let authenticated = false;

export default function () {
  const email = loadTestAccount(__VU - 1);

  if (!authenticated) {
    const loginRes = login(email, DEFAULT_PASSWORD);
    check(loginRes, { 'login succeeded (200)': (r) => r.status === 200 });
    authenticated = true;
  }

  const res = authedGet('/api/v1/alerts', 'alerts-list');
  check(res, {
    'alerts list 200': (r) => r.status === 200,
    'alerts list under 3000ms': (r) => r.timings.duration < 3000,
  });

  sleep(5);
}
