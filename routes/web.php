<?php

use App\Http\Controllers\Admin\CertificateController;
use App\Http\Controllers\Admin\CertificateDocumentController;
use App\Http\Controllers\Admin\CertificateRenewalController;
use App\Http\Controllers\Admin\CertificateTypeController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\VesselController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Client\DocumentController;
use App\Http\Controllers\Client\PortalController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\SuperAdmin\AdministratorController;
use App\Http\Controllers\SuperAdmin\RegistrationReviewController;
use App\Http\Controllers\SuperAdmin\UserDeletionController;
use App\Http\Middleware\EnsureUserHasRole;
use App\Livewire\Auth\Login;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/login', Login::class)->middleware('guest')->name('login');
Route::get('/register', [RegistrationController::class, 'create'])->middleware('guest')->name('register');
Route::post('/register', [RegistrationController::class, 'store'])->middleware(['guest', 'throttle:registration'])->name('register.store');
Route::get('/registration/notice', [RegistrationController::class, 'notice'])->name('registration.notice');
Route::get('/email/verify/{id}/{hash}', [RegistrationController::class, 'verify'])->middleware(['signed', 'throttle:10,1'])->whereNumber('id')->name('verification.verify');
Route::post('/email/verification-notification', [RegistrationController::class, 'resend'])->middleware('throttle:verification-resend')->name('verification.send');
Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

// Keep existing URLs/names compatible; management is now available to administrators.
Route::middleware(['auth', EnsureUserHasRole::class.':admin'])->prefix('super-admin')->name('super-admin.')->group(function () {
    Route::get('users/{user}/delete', [UserDeletionController::class, 'confirm'])->name('users.delete');
    Route::delete('users/{user}', [UserDeletionController::class, 'destroy'])->middleware('throttle:5,1')->name('users.destroy');
    Route::get('registrations', [RegistrationReviewController::class, 'index'])->name('registrations.index');
    Route::get('registrations/{applicant}', [RegistrationReviewController::class, 'show'])->name('registrations.show');
    Route::post('registrations/{applicant}/decision', [RegistrationReviewController::class, 'decide'])->middleware('throttle:5,1')->name('registrations.decide');
    Route::get('administrators', [AdministratorController::class, 'index'])->name('administrators.index');
    Route::get('administrators/{administrator}/change', [AdministratorController::class, 'edit'])->name('administrators.edit');
    Route::post('administrators/{administrator}/change', [AdministratorController::class, 'update'])->middleware('throttle:5,1')->name('administrators.update');
});

Route::get('/admin/dashboard', DashboardController::class)
    ->middleware(['auth', EnsureUserHasRole::class.':admin'])->name('admin.dashboard');
Route::middleware(['auth', EnsureUserHasRole::class.':admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [UserManagementController::class, 'index'])->name('users.index');
    Route::get('users/{user}/department', [UserManagementController::class, 'edit'])->name('users.department.edit');
    Route::patch('users/{user}/department', [UserManagementController::class, 'update'])->name('users.department.update');
    Route::resource('vessels', VesselController::class)->except('destroy');
    Route::resource('clients', ClientController::class)->except('destroy');
    Route::resource('certificate-types', CertificateTypeController::class)
        ->parameters(['certificate-types' => 'certificateType'])->except('destroy');
    Route::resource('certificates', CertificateController::class)->only(['index', 'create', 'store', 'show']);
    Route::get('certificates/{certificate}/renew', [CertificateRenewalController::class, 'create'])->name('certificates.renew');
    Route::post('certificates/{certificate}/renew', [CertificateRenewalController::class, 'store'])->name('certificates.renew.store');
    Route::get('certificates/{certificate}/versions/{versionNumber}/preview', [CertificateDocumentController::class, 'previewVersion'])
        ->where('versionNumber', '[1-9][0-9]{0,9}')->name('certificates.versions.preview');
    Route::get('certificates/{certificate}/versions/{versionNumber}/download', [CertificateDocumentController::class, 'downloadVersion'])
        ->where('versionNumber', '[1-9][0-9]{0,9}')->name('certificates.versions.download');
    Route::get('certificates/{certificate}/preview', [CertificateDocumentController::class, 'preview'])->name('certificates.preview');
    Route::get('certificates/{certificate}/download', [CertificateDocumentController::class, 'download'])->name('certificates.download');
});
Route::middleware(['auth', EnsureUserHasRole::class.':client'])->prefix('client')->name('client.')->group(function () {
    Route::get('dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('vessels', [PortalController::class, 'vessels'])->name('vessels.index');
    Route::get('vessels/{vessel}', [PortalController::class, 'vessel'])->whereNumber('vessel')->name('vessels.show');
    Route::get('certificates', [PortalController::class, 'certificates'])->name('certificates.index');
    Route::get('certificates/{certificate}', [PortalController::class, 'certificate'])->whereNumber('certificate')->name('certificates.show');
    Route::get('certificates/{certificate}/preview', [DocumentController::class, 'preview'])->name('certificates.preview');
    Route::get('certificates/{certificate}/download', [DocumentController::class, 'download'])->name('certificates.download');
    Route::get('certificates/{certificate}/versions/{versionNumber}/preview', [DocumentController::class, 'previewVersion'])
        ->where('versionNumber', '[1-9][0-9]{0,9}')->name('certificates.versions.preview');
    Route::get('certificates/{certificate}/versions/{versionNumber}/download', [DocumentController::class, 'downloadVersion'])
        ->where('versionNumber', '[1-9][0-9]{0,9}')->name('certificates.versions.download');
});
