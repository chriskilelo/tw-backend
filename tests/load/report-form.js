import { check, sleep } from 'k6';
import { login, authedGet, authedWrite, loadTestAccount, DEFAULT_PASSWORD } from './helpers.js';

/**
 * Report auto-save stress test: 17 attaches (one per mission, BR-007 caps
 * one draft report per mission per reporting period) simultaneously
 * auto-saving a narrative report section (FR-RPT-006). Asserts p95 < 3000ms
 * (NFR-PERF-001) and no 5xx responses.
 *
 * loadtest.user00..16 (LoadTestAccountSeeder) are round-robin assigned
 * across all 17 missions in mission-name order, so this VU set already
 * covers every mission exactly once.
 */
export const options = {
  scenarios: {
    report_autosave: {
      executor: 'constant-vus',
      vus: 17,
      duration: '2m',
    },
  },
  // Sanctum SPA session auth (TDD-ADR-002) is cookie-based; k6 resets each
  // VU's cookie jar between iterations by default, which would silently
  // drop both the session cookie and the CSRF cookie after the first
  // save and turn every later PATCH into a 419 CSRF mismatch. Login and
  // draft-report setup happen once per VU (module-scoped `target` below),
  // so the jar must survive across iterations too.
  noCookiesReset: true,
  thresholds: {
    'http_req_duration{name:report-section-autosave}': ['p(95)<3000'],
    // Scoped to the endpoint under test: the one-time setup calls
    // (report-create/report-list/report-show) can legitimately 422 on a
    // repeat run before the idempotent fallback resolves the existing
    // draft (BR-007), which isn't a server-error/perf signal worth failing
    // the gate over.
    'http_req_failed{name:report-section-autosave}': ['rate<0.01'],
  },
};

// Fixed reporting period so repeat runs of this script hit the same BR-007
// draft row instead of accumulating a new one per run; the idempotent lookup
// below handles the second-run 422. It must be a real quarter that has begun
// (FR-RPT-003 validation), and one long past, so it never lands on (and
// auto-saves into) a mission's genuine current-quarter draft.
const PERIOD_LABEL = 'Q1 2001';
const PERIOD_START = '2001-07-01';
const PERIOD_END = '2001-09-30';

let target = null; // { reportId, sectionId }, populated once per VU

function firstNarrativeSection(report) {
  return report.sections.find((s) => s.section_type === 'narrative') || null;
}

/**
 * Creates this VU's draft report on first use. On a repeat run the mission
 * already has one for this period (BR-007 unique index): the 422 names it in
 * `data.existing_report`, and the index endpoint (an attache sees only their
 * own mission's reports) is the fallback lookup.
 */
function ensureDraftReportTarget() {
  const createRes = authedWrite(
    'POST',
    '/api/v1/periodic-reports',
    {
      reporting_period_label: PERIOD_LABEL,
      period_start_date: PERIOD_START,
      period_end_date: PERIOD_END,
    },
    'report-create',
  );

  if (createRes.status === 201) {
    const report = createRes.json('data');
    const section = firstNarrativeSection(report);

    return { reportId: report.id, sectionId: section.id };
  }

  let existingId = createRes.status === 422 ? createRes.json('data.existing_report.id') : null;

  if (!existingId) {
    const listRes = authedGet(`/api/v1/periodic-reports?period=${encodeURIComponent(PERIOD_LABEL)}&per_page=100`, 'report-list');
    const existing = listRes
      .json('data')
      .find((r) => r.period_start_date.startsWith(PERIOD_START));
    existingId = existing ? existing.id : null;
  }

  if (!existingId) {
    throw new Error('Could not create or locate a draft report for this mission/period');
  }

  const showRes = authedGet(`/api/v1/periodic-reports/${existingId}`, 'report-show');
  const report = showRes.json('data');
  const section = firstNarrativeSection(report);

  return { reportId: report.id, sectionId: section.id };
}

export default function () {
  const email = loadTestAccount(__VU - 1);

  if (target === null) {
    const loginRes = login(email, DEFAULT_PASSWORD);
    check(loginRes, { 'login succeeded (200)': (r) => r.status === 200 });

    target = ensureDraftReportTarget();
  }

  const res = authedWrite(
    'PATCH',
    `/api/v1/periodic-reports/${target.reportId}/sections/${target.sectionId}`,
    { content: `Load test auto-save at ${new Date().toISOString()} (VU ${__VU}, iter ${__ITER})` },
    'report-section-autosave',
  );

  check(res, {
    'section save 200': (r) => r.status === 200,
    'section save under 3000ms': (r) => r.timings.duration < 3000,
    'section save no 5xx': (r) => r.status < 500,
  });

  sleep(2);
}
