<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\StickerController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\BugReportController;
use App\Http\Middleware\AuthenticateFirestoreToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(AuthenticateFirestoreToken::class);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/stickers/{sticker}/image', [StickerController::class, 'image']);

Route::middleware(AuthenticateFirestoreToken::class)->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/users', [UserController::class, 'index']);
    Route::patch('/users/me/privacy', [UserController::class, 'updatePrivacy']);

    Route::post('/conversations/{conversation}/typing', [ConversationController::class, 'typing']);
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead']);
    Route::get('/conversations/{conversation}/media', [ConversationController::class, 'media']);
    Route::get('/conversations/{conversation}/starred', [ConversationController::class, 'starred']);
    Route::delete('/conversations/{conversation}/clear', [ConversationController::class, 'clear']);

    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::post('/messages/upload', [MessageController::class, 'uploadAttachment']);
    Route::post('/messages/{message}/star', [MessageController::class, 'toggleStar']);
    Route::post('/messages/{message}/react', [MessageController::class, 'toggleReaction']);

    Route::post('/bug-reports', [BugReportController::class, 'store']);
    Route::get('/bug-reports', [BugReportController::class, 'index']);
    Route::patch('/bug-reports/{bugReport}', [BugReportController::class, 'update']);

    Route::post('/broadcasting/auth', function (Request $request) {
        return Broadcast::auth($request);
    });

    Route::post('/users/{id}/block', [BlockController::class, 'store']);
    Route::delete('/users/{id}/block', [BlockController::class, 'destroy']);
    Route::get('/users/{id}/block', [BlockController::class, 'show']);

    Route::get('/stickers', [StickerController::class, 'index']);
    Route::get('/stickers/mine', [StickerController::class, 'mine']);
    Route::post('/stickers', [StickerController::class, 'store']);
    Route::delete('/stickers/{sticker}', [StickerController::class, 'destroy']);
});
