<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\MasterDataController;
use App\Http\Controllers\Api\Admin\MissionController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Alerts\AlertController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Directives\DirectiveController;
use App\Http\Controllers\Api\Governance\MfaAwarenessController;
use App\Http\Controllers\Api\Governance\MissionActivityController;
use App\Http\Controllers\Api\Inquiries\InquiryController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Referrals\ReferralController;
use App\Http\Controllers\Api\Reports\PeriodicReportController;
use App\Http\Controllers\Api\Reports\ReportTemplateController;
use App\Http\Controllers\Api\Sdt\ActingPsController;
use App\Http\Controllers\Api\Sdt\ConfigController;
use App\Http\Controllers\Api\Sdt\DirectivesController as SdtDirectivesController;
use App\Http\Controllers\Api\Sdt\HqWorkspaceController;
use App\Http\Controllers\Api\Sdt\PsDashboardController;
use App\Http\Controllers\Api\Sdt\ReportsController as SdtReportsController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/login', LoginController::class);
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot']);
    Route::post('/password/reset', [PasswordResetController::class, 'reset']);

    Route::middleware('auth:web')->group(function (): void {
        Route::post('/logout', LogoutController::class);
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me/preferences', [MeController::class, 'updatePreferences']);

        Route::prefix('users')->group(function (): void {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('/{user}', [UserController::class, 'show']);
            Route::patch('/{user}', [UserController::class, 'update']);
            Route::post('/{user}/deactivate', [UserController::class, 'deactivate']);
            Route::post('/{user}/reactivate', [UserController::class, 'reactivate']);
            Route::post('/{user}/resend-activation', [UserController::class, 'resendActivation']);
        });

        Route::prefix('missions')->group(function (): void {
            Route::get('/', [MissionController::class, 'index']);
            Route::post('/', [MissionController::class, 'store']);
            Route::patch('/{mission}', [MissionController::class, 'update']);
            Route::post('/{mission}/deactivate', [MissionController::class, 'deactivate']);
        });

        Route::prefix('audit-logs')->group(function (): void {
            Route::get('/', [AuditLogController::class, 'index']);
        });

        Route::prefix('notifications')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index']);
            Route::patch('/{notification}/read', [NotificationController::class, 'markRead']);
            Route::post('/mark-all-read', [NotificationController::class, 'markAllRead']);
        });

        // Layer 2 engine: ministry.scope binds current_ministry_id so the
        // Alert model's global scope (Session 5) filters every query
        // (CLAUDE.md Section 4, Rule 1; NFR-SEC-006).
        Route::prefix('alerts')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [AlertController::class, 'index']);
            Route::post('/', [AlertController::class, 'store']);
            Route::get('/{alert}', [AlertController::class, 'show']);
            Route::patch('/{alert}', [AlertController::class, 'update']);
            Route::post('/{alert}/attachments', [AlertController::class, 'storeAttachment']);
            Route::post('/{alert}/delegate', [AlertController::class, 'delegate']);
            Route::post('/{alert}/acknowledge', [AlertController::class, 'acknowledge']);
            Route::post('/{alert}/feedback', [AlertController::class, 'postFeedback']);
        });

        // Layer 2 engine: ministry.scope binds current_ministry_id so the
        // Inquiry model's global scope filters every query (CLAUDE.md
        // Section 4, Rule 1; NFR-SEC-006). FR-INQ-019 (link to a
        // cross-mission inquiry) is Stage 2, not exposed here.
        Route::prefix('inquiries')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [InquiryController::class, 'index']);
            Route::post('/', [InquiryController::class, 'store']);
            Route::get('/{inquiry}', [InquiryController::class, 'show']);
            Route::patch('/{inquiry}', [InquiryController::class, 'update']);
            Route::patch('/{inquiry}/status', [InquiryController::class, 'updateStatus']);
            Route::post('/{inquiry}/events', [InquiryController::class, 'storeEvent']);
            Route::post('/{inquiry}/notes', [InquiryController::class, 'storeNote']);
            Route::post('/{inquiry}/close', [InquiryController::class, 'close']);
            Route::post('/{inquiry}/referrals', [ReferralController::class, 'store']);
        });

        // Layer 2 engine: Directive and Tasking (FR-DIR-002 to 013).
        // ministry.scope binds current_ministry_id so the Directive
        // model's global scope filters every query (CLAUDE.md Section 4,
        // Rule 1; NFR-SEC-006). '/summary' must be registered before
        // '/{directive}' so it is never swallowed by that wildcard (same
        // precedent as periodic-reports' '/compliance').
        Route::prefix('directives')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [DirectiveController::class, 'index']);
            Route::get('/summary', [DirectiveController::class, 'summary']);
            Route::post('/', [DirectiveController::class, 'store']);
            Route::get('/{directive}', [DirectiveController::class, 'show']);
            Route::patch('/{directive}/status', [DirectiveController::class, 'updateStatus']);
            Route::post('/{directive}/notes', [DirectiveController::class, 'storeNote']);
        });

        // Layer 2 engine: Referral Register (FR-REF-001 to 006, BR-021).
        // ministry.scope binds current_ministry_id so
        // ReferralEntry/ReferralOrganisation's global scope filters every
        // query (CLAUDE.md Section 4, Rule 1; NFR-SEC-006). Recording a
        // referral never triggers external communication (BR-021,
        // FR-REF-003) — see App\Services\ReferralService.
        Route::prefix('referral-organisations')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [ReferralController::class, 'indexOrganisations']);
        });

        Route::prefix('referrals')->middleware('ministry.scope')->group(function (): void {
            Route::get('/summary', [ReferralController::class, 'summary']);
            Route::post('/{referralEntry}/attachments', [ReferralController::class, 'storeAttachment']);
        });

        // Session 25: FR-RPT-002, BR-006 (Periodic Report Engine template
        // versions). ReportTemplateSection carries ministry_id and uses the
        // default HasMinistryScope column, so ministry.scope is applied
        // here (unlike master-data, whose model never adopted the scope).
        // System Administrator only, enforced via ReportPolicy::manageTemplate().
        Route::prefix('report-templates')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [ReportTemplateController::class, 'index']);
            Route::post('/', [ReportTemplateController::class, 'store']);
        });

        // Session 25/26: FR-RPT-003 to 011, 014, 016, 018 (Periodic Report
        // Engine). ministry.scope binds current_ministry_id so the
        // PeriodicReport model's global scope filters every query
        // (CLAUDE.md Section 4, Rule 1; NFR-SEC-006). '/compliance' must be
        // registered before '/{periodicReport}' so it is never swallowed by
        // that wildcard.
        Route::prefix('periodic-reports')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [PeriodicReportController::class, 'index']);
            Route::get('/compliance', [PeriodicReportController::class, 'compliance']);
            Route::post('/', [PeriodicReportController::class, 'store']);
            Route::get('/{periodicReport}', [PeriodicReportController::class, 'show']);
            Route::patch('/{periodicReport}/sections/{section}', [PeriodicReportController::class, 'updateSection']);
            Route::post('/{periodicReport}/data-rows', [PeriodicReportController::class, 'storeDataRow']);
            Route::delete('/{periodicReport}/data-rows/{dataRow}', [PeriodicReportController::class, 'destroyDataRow']);
            Route::post('/{periodicReport}/carry-forward', [PeriodicReportController::class, 'carryForward']);
            Route::post('/{periodicReport}/submit', [PeriodicReportController::class, 'submit']);
        });

        // FR-HOM-001 to 003: read-only, no ministry.scope — the Head of
        // Mission/Deputy Head of Mission activity feed is deliberately
        // cross-ministry (every ministry with an attache at their own
        // mission), scoped instead via MissionPolicy::viewOwnActivity()
        // and App\Services\GovernanceService.
        Route::prefix('mission-activity')->group(function (): void {
            Route::get('/', [MissionActivityController::class, 'index']);
            Route::get('/summary', [MissionActivityController::class, 'summary']);
        });

        // FR-MFA-001 to 003: read-only, aggregate-only cross-mission,
        // cross-ministry counts for MFA HQ Officer / MFA Principal
        // Secretary. See App\Policies\MissionPolicy and
        // App\Services\GovernanceService.
        Route::prefix('mfa-awareness')->group(function (): void {
            Route::get('/', [MfaAwarenessController::class, 'index']);
            Route::get('/missions/{mission}', [MfaAwarenessController::class, 'missionSummary']);
            Route::get('/national-overview', [MfaAwarenessController::class, 'nationalOverview']);
        });

        // FR-MDATA-001, FR-MDATA-002: shared, platform-wide reference data.
        // GET is open to any authenticated user; write actions are System
        // Administrator only, enforced via MasterDataPolicy.
        Route::prefix('master-data')->group(function (): void {
            Route::get('/', [MasterDataController::class, 'index']);
            Route::post('/', [MasterDataController::class, 'store']);
            Route::patch('/{masterDataEntry}', [MasterDataController::class, 'update']);
        });

        // FR-SEARCH-001 to 004 (Knowledge Search Engine). ministry.scope
        // binds current_ministry_id so each queried model's global scope
        // (Alert, Inquiry, PeriodicReport) filters results (CLAUDE.md
        // Section 4, Rule 1; FR-SEARCH-001 AC2). No dedicated policy — see
        // App\Http\Controllers\Api\SearchController.
        Route::prefix('search')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [SearchController::class, 'search']);
            Route::get('/countries/{country}', [SearchController::class, 'countryProfile']);
        });

        // Session 14: FR-SDT-001 (PS dashboard), FR-SDT-004 to 006 (Acting
        // PS), FR-SDT-012/013 (HQ Officer workspace), FR-SDT-019/020/023
        // (SDT admin config screens). See App\Policies\MinistryPolicy,
        // App\Services\SdtService.
        Route::prefix('sdt')->group(function (): void {
            // Ministry-scoped: relies on the Alert/Inquiry/Directive
            // models' global scope, bound by ministry.scope (CLAUDE.md
            // Section 4, Rule 1).
            Route::middleware('ministry.scope')->group(function (): void {
                Route::get('/dashboard', [PsDashboardController::class, 'show']);
                Route::get('/hq-workspace', [HqWorkspaceController::class, 'show']);

                // Session 26: FR-SDT-007, FR-SDT-015. Ministry PS (and
                // Acting PS) only, via MinistryPolicy::viewPsDashboard() —
                // same boundary as /sdt/dashboard above.
                Route::prefix('reports')->group(function (): void {
                    Route::get('/compliance', [SdtReportsController::class, 'compliance']);
                    Route::get('/submission-summary', [SdtReportsController::class, 'submissionSummary']);
                });

                // Session 27: FR-SDT-008 (PS-level directive overview),
                // FR-SDT-009 (completion rate dashboard). Same boundary as
                // /sdt/reports above — Ministry PS (and Acting PS) only,
                // via MinistryPolicy::viewPsDashboard().
                Route::prefix('directives')->group(function (): void {
                    Route::get('/overview', [SdtDirectivesController::class, 'overview']);
                    Route::get('/compliance', [SdtDirectivesController::class, 'compliance']);
                });
            });

            // Not ministry-scoped: acts on explicit User/Ministry ids, not
            // a ministry-scoped model (mirrors Session 13's reasoning for
            // master-data/mfa-awareness).
            Route::post('/acting-ps/activate', [ActingPsController::class, 'activate']);
            Route::post('/acting-ps/deactivate', [ActingPsController::class, 'deactivate']);

            Route::prefix('config')->group(function (): void {
                Route::get('/alert-fields', [ConfigController::class, 'alertFields']);
                Route::post('/alert-fields', [ConfigController::class, 'storeAlertField']);
                Route::patch('/alert-fields/{masterDataEntry}', [ConfigController::class, 'updateAlertField']);

                Route::get('/inquiry-settings', [ConfigController::class, 'inquirySettings']);
                Route::post('/inquiry-settings', [ConfigController::class, 'storeInquirySetting']);
                Route::patch('/inquiry-settings/{masterDataEntry}', [ConfigController::class, 'updateInquirySetting']);

                // Session 27: FR-SDT-010. See ConfigController::storeDirectiveSetting().
                Route::post('/directive-settings', [ConfigController::class, 'storeDirectiveSetting']);

                Route::get('/referral-organisations', [ConfigController::class, 'referralOrganisations']);
                Route::post('/referral-organisations', [ConfigController::class, 'storeReferralOrganisation']);
                Route::patch('/referral-organisations/{referralOrganisation}', [ConfigController::class, 'updateReferralOrganisation']);

                // Session 26/31: FR-SDT-022. See
                // ConfigController::aieBudgetCodes()/storeAieBudgetCode()/updateAieBudgetCode().
                Route::get('/aie-budget-codes', [ConfigController::class, 'aieBudgetCodes']);
                Route::post('/aie-budget-codes', [ConfigController::class, 'storeAieBudgetCode']);
                Route::patch('/aie-budget-codes/{masterDataEntry}', [ConfigController::class, 'updateAieBudgetCode']);
            });
        });
    });
});
