<?php

use App\Http\Controllers\Admin\DocumentTypeController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\DevLoginController;
use App\Http\Controllers\Auth\EntraController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentFieldController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SignatureController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::view('/login', 'auth.login')->middleware('guest')->name('login');
Route::get('/auth/entra', [EntraController::class, 'redirect'])->middleware(['guest', 'throttle:20,1'])->name('auth.entra');
Route::get('/auth/entra/callback', [EntraController::class, 'callback'])->middleware(['guest', 'throttle:10,1'])->name('auth.entra.callback');
Route::post('/dev/login', DevLoginController::class)->middleware(['guest', 'throttle:20,1'])->name('auth.dev');
Route::get('/branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');
Route::get('/verifier/{code}', [VerificationController::class, 'show'])->middleware('throttle:60,1')->name('verify.show');

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', [EntraController::class, 'logout'])->name('logout');

    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/export.csv', [DocumentController::class, 'export'])->name('documents.export');
    Route::get('/documents/create', [DocumentController::class, 'create'])->middleware('can.send')->name('documents.create');
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('can.send')->name('documents.store');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('/documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
    Route::put('/documents/{document}/placements', [DocumentController::class, 'update'])->name('documents.placements.update');
    Route::post('/documents/{document}/fields', [DocumentFieldController::class, 'store'])->name('documents.fields.store');
    Route::put('/documents/{document}/fields/{field}', [DocumentFieldController::class, 'update'])->name('documents.fields.update');
    Route::delete('/documents/{document}/fields/{field}', [DocumentFieldController::class, 'destroy'])->name('documents.fields.destroy');
    Route::get('/documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.cancel');
    Route::post('/documents/{document}/revoke', [DocumentController::class, 'revoke'])->name('documents.revoke');

    Route::get('/signatures/{signatureRequest}', [SignatureController::class, 'show'])->name('signatures.show');
    Route::post('/signatures/{signatureRequest}/sign', [SignatureController::class, 'sign'])->name('signatures.sign');
    Route::put('/signatures/{signatureRequest}/position', [SignatureController::class, 'position'])->name('signatures.position');
    Route::post('/signatures/{signatureRequest}/reject', [SignatureController::class, 'reject'])->name('signatures.reject');
    Route::post('/signatures/{signatureRequest}/eid/prepare', [SignatureController::class, 'eidPrepare'])->middleware('throttle:20,1')->name('signatures.eid.prepare');
    Route::post('/signatures/{signatureRequest}/eid/failure', [SignatureController::class, 'eidFailure'])->middleware('throttle:30,1')->name('signatures.eid.failure');
    Route::post('/signatures/{signatureRequest}/eid/finalize', [SignatureController::class, 'eidFinalize'])->middleware('throttle:20,1')->name('signatures.eid.finalize');
    Route::post('/signatures/{signatureRequest}/fields', [SignatureController::class, 'storeField'])->name('signatures.fields.store');
    Route::put('/signatures/{signatureRequest}/fields/{field}', [SignatureController::class, 'updateField'])->name('signatures.fields.update');
    Route::delete('/signatures/{signatureRequest}/fields/{field}', [SignatureController::class, 'destroyField'])->name('signatures.fields.destroy');
    Route::post('/signatures/{signatureRequest}/delegate', [SignatureController::class, 'delegate'])->name('signatures.delegate');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/eid-failure', [ProfileController::class, 'eidFailure'])->middleware('throttle:30,1')->name('profile.eid.failure');
    Route::post('/profile/eid-check', [ProfileController::class, 'eidCheck'])->middleware('throttle:20,1')->name('profile.eid.check');
    Route::get('/profile/signature', [ProfileController::class, 'signatureImage'])->name('profile.signature.show');
    Route::post('/profile/signature', [ProfileController::class, 'storeSignature'])->name('profile.signature.store');
    Route::delete('/profile/signature', [ProfileController::class, 'deleteSignature'])->name('profile.signature.delete');

    Route::prefix('admin')->middleware('admin')->name('admin.')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::get('/document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
        Route::post('/document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
        Route::put('/document-types/{documentType}', [DocumentTypeController::class, 'update'])->name('document-types.update');
        Route::delete('/document-types/{documentType}', [DocumentTypeController::class, 'destroy'])->name('document-types.destroy');
    });
});
