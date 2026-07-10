<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\MasterData\ExpenseCategoryController;
use App\Http\Controllers\MasterData\ExpenseItemController;
use App\Http\Controllers\MasterData\ExpenseSubcategoryController;
use App\Http\Controllers\MasterData\PayrollCostComponentOptionsController;
use App\Http\Controllers\MasterData\PlantationUnitController;
use App\Http\Controllers\PDO\PdoApprovalController;
use App\Http\Controllers\PDO\PdoCloseController;
use App\Http\Controllers\PDO\PdoDetailAttachmentController;
use App\Http\Controllers\PDO\PdoDetailController;
use App\Http\Controllers\PDO\PdoHeaderController;
use App\Http\Controllers\PdoSupplementary\PdoSupplementaryController;
use App\Http\Controllers\PdoSupplementary\PdoSupplementaryMergeController;
use App\Http\Controllers\Realization\RealizationAttachmentController;
use App\Http\Controllers\Realization\RealizationEntryController;
use App\Http\Controllers\Reports\RecapController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Settings\SystemSettingController;
use App\Http\Controllers\Transfer\TransferEntryController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────────────
// PUBLIC ROUTES (tanpa auth)
// ─────────────────────────────────────────────────────
Route::prefix('v1')->group(function () {

    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/refresh-token', [AuthController::class, 'refreshToken']);

});

// ─────────────────────────────────────────────────────
// PROTECTED ROUTES (butuh auth + unit access middleware)
// ─────────────────────────────────────────────────────
Route::prefix('v1')->middleware(['auth:sanctum', 'ensure.unit.access'])->group(function () {

    // ── Auth ──────────────────────────────────────────
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // ── Users & Roles (ADMIN only) ────────────────────
    Route::apiResource('users', UserController::class);
    Route::get('roles', [UserController::class, 'roles']);
    Route::get('plantation-units', [PlantationUnitController::class, 'index']);
    Route::put('plantation-units/{plantation_unit}', [PlantationUnitController::class, 'updatePayrollEstateMapping']);

    // ── Master Data — Kategori ────────────────────────
    Route::apiResource('expense-categories', ExpenseCategoryController::class);

    // ── Master Data — Sub-Kategori ────────────────────
    Route::apiResource('expense-subcategories', ExpenseSubcategoryController::class);

    // ── Master Data — Item Biaya ──────────────────────
    Route::get('expense-items/routine', [ExpenseItemController::class, 'routine']); // SEBELUM resource route
    Route::apiResource('expense-items', ExpenseItemController::class);
    Route::get('payroll-cost-component-options', [PayrollCostComponentOptionsController::class, 'index']);

    // ── PDO Bulanan ───────────────────────────────────
    Route::get('pdo/{pdo}/export', [PdoHeaderController::class, 'export']);
    Route::get('pdo/{pdo}/pengajuan-breakdown', [PdoHeaderController::class, 'pengajuanBreakdown']);
    Route::apiResource('pdo', PdoHeaderController::class);

    // PDO Details (baris item)
    Route::prefix('pdo/{pdo}')->group(function () {
        Route::get('details', [PdoDetailController::class, 'index']);
        Route::post('details', [PdoDetailController::class, 'store'])->middleware('check.pdo.status');
        Route::put('details/{detail}', [PdoDetailController::class, 'update'])->middleware('check.pdo.status');
        Route::delete('details/{detail}', [PdoDetailController::class, 'destroy'])->middleware('check.pdo.status');
        Route::post('details/{detail}/pull-external-cost', [PdoDetailController::class, 'pullExternalCost'])->middleware('check.pdo.status');
        Route::post('pull-external-costs', [PdoDetailController::class, 'bulkPullExternalCost'])->middleware('check.pdo.status');

        // Approval workflow
        Route::post('submit', [PdoApprovalController::class, 'submit'])->middleware('check.pdo.status');
        Route::post('approve', [PdoApprovalController::class, 'approve']);
        Route::post('reject', [PdoApprovalController::class, 'reject']);
        Route::get('approval-history', [PdoApprovalController::class, 'history']);

        // Transfer summary per PDO
        Route::get('transfers', [TransferEntryController::class, 'summaryByPdo']);
        Route::post('transfers/bulk', [TransferEntryController::class, 'storeBulk']);
        // Draft transfer: edit/hapus draft + commit permanen
        Route::put('transfers/drafts/{entry}', [TransferEntryController::class, 'updateDraft']);
        Route::delete('transfers/drafts/{entry}', [TransferEntryController::class, 'deleteDraft']);
        Route::post('transfers/commit', [TransferEntryController::class, 'commitDrafts']);

        // Penutupan PDO — tidak perlu check.pdo.status karena endpoint ini yang menutup
        Route::post('close', [PdoCloseController::class, 'close']);

        // Realisasi per PDO
        Route::get('realizations', [RealizationEntryController::class, 'summaryByPdo']);
        Route::get('realizations/items', [RealizationEntryController::class, 'itemsByPdo']);
        // BR-REAL-005: item yang boleh direalisasi actor + saldo kantong
        Route::get('realizations/available', [RealizationEntryController::class, 'availableByPdo']);
        // BR-CLOSE-003: write realisasi diblokir untuk PDO closed
        Route::post('realizations', [RealizationEntryController::class, 'store'])->middleware('check.pdo.status');
    });

    // ── Transfer Entries (per pdo_detail) ─────────────
    Route::prefix('pdo-details/{detail}')->group(function () {
        Route::get('transfers', [TransferEntryController::class, 'index']);
        Route::post('transfers', [TransferEntryController::class, 'store'])->middleware('check.pdo.status');
        Route::get('attachments', [PdoDetailAttachmentController::class, 'index']);
        Route::post('attachments', [PdoDetailAttachmentController::class, 'store']);
    });

    // ── Lampiran item biaya ───────────────────────────
    Route::get('pdo-detail-attachments/{attachment}/download', [PdoDetailAttachmentController::class, 'download'])
        ->name('pdo-detail-attachments.download');
    Route::delete('pdo-detail-attachments/{attachment}', [PdoDetailAttachmentController::class, 'destroy']);
    Route::get('transfer-entries', [TransferEntryController::class, 'all']);
    Route::get('transfer-entries/pdo-summary', [TransferEntryController::class, 'pdoSummaryList']);
    Route::put('transfer-entries/{entry}', [TransferEntryController::class, 'update'])->middleware('check.pdo.status');

    // ── Realisasi Dana ────────────────────────────────
    Route::get('realization-entries', [RealizationEntryController::class, 'index']);
    Route::post('realization-entries', [RealizationEntryController::class, 'store'])->middleware('check.pdo.status');
    Route::put('realization-entries/{entry}', [RealizationEntryController::class, 'update'])->middleware('check.pdo.status');
    Route::delete('realization-entries/{entry}', [RealizationEntryController::class, 'destroy'])->middleware('check.pdo.status');

    // Attachments (bukti transaksi)
    Route::post('realization-entries/{entry}/attachments', [RealizationAttachmentController::class, 'store']);
    Route::delete('realization-entries/{entry}/attachments/{attachment}', [RealizationAttachmentController::class, 'destroy']);

    // ── PDO Tambahan ──────────────────────────────────
    Route::apiResource('pdo-supplementary', PdoSupplementaryController::class)->except(['destroy']);

    Route::prefix('pdo-supplementary/{supplementary}')->group(function () {
        Route::post('details', [PdoSupplementaryController::class, 'storeDetail']);
        Route::put('details/{detail}', [PdoSupplementaryController::class, 'updateDetail']);
        Route::delete('details/{detail}', [PdoSupplementaryController::class, 'destroyDetail']);

        Route::get('approval-logs', [PdoApprovalController::class, 'historySupplementary']);
        Route::post('submit', [PdoApprovalController::class, 'submitSupplementary']);
        Route::post('approve', [PdoApprovalController::class, 'approveSupplementary']);
        Route::post('reject', [PdoApprovalController::class, 'rejectSupplementary']);
        Route::post('merge', [PdoSupplementaryMergeController::class, 'merge']);
    });

    // ── Dashboard ─────────────────────────────────────
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('dashboard/category-summary', [DashboardController::class, 'categorySummary']);

    // ── Laporan ───────────────────────────────────────
    Route::get('reports/realization', [ReportController::class, 'realization']);
    Route::get('reports/over-budget', [ReportController::class, 'overBudget']);
    Route::get('reports/missing-proof', [ReportController::class, 'missingProof']);
    Route::get('reports/recap', [RecapController::class, 'index']);
    Route::get('reports/recap/export', [RecapController::class, 'exportExcel']);
    Route::post('reports/export', [ReportController::class, 'export']);
    Route::get('reports/export/{job}', [ReportController::class, 'exportStatus']);

    // ── Pengaturan (ADMIN only) ───────────────────────
    Route::get('settings', [SystemSettingController::class, 'index']);
    Route::put('settings', [SystemSettingController::class, 'update']);
    Route::post('settings/wa-test', [SystemSettingController::class, 'testWhatsApp']);

    Route::get('notification-templates', [SystemSettingController::class, 'templates']);
    Route::put('notification-templates/{template}', [SystemSettingController::class, 'updateTemplate']);

});
