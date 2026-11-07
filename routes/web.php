<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\DarkVesselController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EncounterController;
use App\Http\Controllers\InvestigationController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\VesselController;
use Illuminate\Support\Facades\Route;

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Google SSO Routes
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// Deprecated Fishing Grounds Route (HTTP 410 Gone)
Route::any('/fishing-grounds', function () {
    abort(410, 'Modul Fishing Grounds telah dinonaktifkan secara permanen karena data FAO Fishing Areas tidak lagi digunakan (410 Gone).');
});

// Protected Portal Routes (Requires Authentication, Active Status, and Valid Role)
Route::middleware(['auth', 'role'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // 1. Global Vessel Map
    Route::get('/map', [MapController::class, 'index'])->name('map.index');

    // 2. Vessel Registry
    Route::get('/vessels', [VesselController::class, 'index'])->name('vessels.index');
    Route::get('/vessels/{id}', [VesselController::class, 'show'])->name('vessels.show');

    // 3. Live Vessel Tracking
    Route::get('/tracking', [TrackingController::class, 'index'])->name('tracking.index');

    // 4. Vessel Encounters
    Route::get('/encounters', [EncounterController::class, 'index'])->name('encounters.index');
    Route::get('/encounters/{id}', [EncounterController::class, 'show'])->name('encounters.show');
    Route::post('/encounters/{id}/status', [EncounterController::class, 'updateStatus'])
        ->middleware('role:super_admin,admin,analyst')
        ->name('encounters.update-status');

    // 6. Dark Vessel Detection & SAR Candidate Analyst Review
    Route::get('/dark-vessels', [DarkVesselController::class, 'index'])->name('dark-vessels.index');
    Route::get('/dark-vessels/export', [DarkVesselController::class, 'export'])->name('dark-vessels.export');
    Route::get('/dark-vessels/{id}', [DarkVesselController::class, 'show'])->name('dark-vessels.show');
    Route::post('/dark-vessels/{id}/review', [DarkVesselController::class, 'submitReview'])
        ->middleware('role:super_admin,admin,analyst')
        ->name('dark-vessels.review');

    // Aliases for SAR Analyst Review
    Route::get('/sar/candidates', [DarkVesselController::class, 'index'])->name('sar.candidates.index');
    Route::get('/sar/candidates/export', [DarkVesselController::class, 'export'])->name('sar.candidates.export');
    Route::get('/sar/candidates/{id}', [DarkVesselController::class, 'show'])->name('sar.candidates.show');
    Route::post('/sar/candidates/{id}/review', [DarkVesselController::class, 'submitReview'])
        ->middleware('role:super_admin,admin,analyst')
        ->name('sar.candidates.review');

    // SAR Evidence Serving
    Route::get('/sar/evidence/{candidate}/{type}', [\App\Http\Controllers\SarEvidenceController::class, 'serveEvidenceImage'])
        ->name('sar.evidence.image');

    // 7. Fishing Compliance
    Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');
    Route::get('/compliance/events/{id}', [ComplianceController::class, 'showEvent'])->name('compliance.show');
    Route::post('/compliance/events/{id}/status', [ComplianceController::class, 'updateEventStatus'])
        ->middleware('role:super_admin,admin,analyst')
        ->name('compliance.update-status');

    // 8. Investigations (Restricted — Private Research Team Dossiers)
    Route::get('/investigations', [InvestigationController::class, 'index'])->name('investigations.index');
    Route::get('/investigations/create', [InvestigationController::class, 'create'])
        ->middleware('role:super_admin,admin,analyst,research_lead,researcher,institutional_researcher')
        ->name('investigations.create');
    Route::post('/investigations', [InvestigationController::class, 'store'])
        ->middleware('role:super_admin,admin,analyst,research_lead,researcher,institutional_researcher')
        ->name('investigations.store');
    Route::get('/investigations/{id}', [InvestigationController::class, 'show'])->name('investigations.show');
    Route::patch('/investigations/{id}/findings', [InvestigationController::class, 'updateFindings'])
        ->name('investigations.findings.update');
    Route::get('/investigations/{id}/export', [InvestigationController::class, 'export'])
        ->name('investigations.export');

    // 9. Data Sources & Providers
    Route::get('/providers', [ProviderController::class, 'index'])->name('providers.index');
    Route::post('/providers/{slug}/health', [ProviderController::class, 'checkHealth'])
        ->middleware('role:super_admin,admin')
        ->name('providers.health');

    // 10. System Settings & Activity Logs
    Route::get('/settings', [SystemController::class, 'settings'])
        ->middleware('role:super_admin,admin')
        ->name('settings.index');
    Route::post('/settings/offsite-backup', [SystemController::class, 'updateOffsiteBackupGate'])
        ->middleware('role:super_admin')
        ->name('settings.offsite-backup');
    Route::get('/audit-logs', [SystemController::class, 'auditLogs'])
        ->middleware('role:super_admin,admin')
        ->name('audit-logs.index');

    // 11. User Profile & Account Settings
    Route::get('/settings/profile', [UserProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/settings/profile', [UserProfileController::class, 'update'])->name('profile.update');
    Route::post('/settings/profile/avatar', [UserProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
    Route::delete('/settings/profile/avatar', [UserProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
    Route::match(['patch', 'put'], '/settings/password', [UserProfileController::class, 'updatePassword'])
        ->middleware('throttle:15,1')
        ->name('profile.password.update');

    // 12. User Account & Access Control Management (Super Admin Exclusive)
    Route::middleware('role:super_admin')->prefix('admin/users')->name('admin.users.')->group(function () {
        Route::get('/', [\App\Http\Controllers\UserManagementController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\UserManagementController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\UserManagementController::class, 'store'])->name('store');
        Route::get('/{id}', [\App\Http\Controllers\UserManagementController::class, 'show'])->name('show');
        Route::get('/{id}/edit', [\App\Http\Controllers\UserManagementController::class, 'edit'])->name('edit');
        Route::patch('/{id}', [\App\Http\Controllers\UserManagementController::class, 'update'])->name('update');
        Route::post('/{id}/toggle-status', [\App\Http\Controllers\UserManagementController::class, 'toggleStatus'])->name('toggle-status');
        Route::post('/{id}/reset-password', [\App\Http\Controllers\UserManagementController::class, 'resetPassword'])->name('reset-password');
    });
});
