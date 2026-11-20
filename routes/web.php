<?php

/** 既存Bladeの認証経路と接続段階の業務経路を定義する。 */
use App\Http\Controllers\BookController;
use App\Http\Controllers\PendingFeatureController;
use App\Http\Controllers\RegisteredUserController;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::model('book', Book::class);
Route::model('review', Review::class);
Route::model('genre', Genre::class);
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/', [BookController::class, 'index'])->name('books.index');
Route::get('/books/create', [BookController::class, 'create'])->middleware('auth')->name('books.create');
Route::post('/books', PendingFeatureController::class)->middleware(['auth'])->name('books.store');
Route::get('/books/{book}', PendingFeatureController::class)->name('books.show');
Route::get('/books/{book}/edit', PendingFeatureController::class)->middleware(['auth', 'can:update,book'])->name('books.edit');
Route::put('/books/{book}', PendingFeatureController::class)->middleware(['auth', 'can:update,book'])->name('books.update');
Route::delete('/books/{book}', PendingFeatureController::class)->middleware(['auth', 'can:delete,book'])->name('books.destroy');
Route::get('/genres', PendingFeatureController::class)->middleware(['auth'])->name('genres.index');
Route::get('/genres/create', PendingFeatureController::class)->middleware(['auth'])->name('genres.create');
Route::post('/genres', PendingFeatureController::class)->middleware(['auth'])->name('genres.store');
Route::get('/genres/{genre}', PendingFeatureController::class)->middleware(['auth'])->name('genres.show');
Route::get('/genres/{genre}/edit', PendingFeatureController::class)->middleware(['auth'])->name('genres.edit');
Route::put('/genres/{genre}', PendingFeatureController::class)->middleware(['auth'])->name('genres.update');
Route::delete('/genres/{genre}', PendingFeatureController::class)->middleware(['auth'])->name('genres.destroy');
Route::post('/books/{book}/reviews', PendingFeatureController::class)->middleware(['auth'])->name('reviews.store');
Route::get('/reviews/{review}/edit', PendingFeatureController::class)->middleware(['auth', 'can:update,review'])->name('reviews.edit');
Route::put('/reviews/{review}', PendingFeatureController::class)->middleware(['auth', 'can:update,review'])->name('reviews.update');
Route::delete('/reviews/{review}', PendingFeatureController::class)->middleware(['auth', 'can:delete,review'])->name('reviews.destroy');
Route::post('/reviews/{review}/like', PendingFeatureController::class)->middleware(['auth'])->name('reviews.like');
Route::post('/books/{book}/favorites', PendingFeatureController::class)->middleware(['auth'])->name('favorites.toggle');
Route::get('/favorites', PendingFeatureController::class)->middleware(['auth'])->name('favorites.index');
Route::get('/ranking', PendingFeatureController::class)->name('ranking.index');
