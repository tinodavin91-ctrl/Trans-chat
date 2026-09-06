<?php

namespace Database\Seeders;

use App\Services\FirestoreService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $firestore = app(FirestoreService::class);

        if (! $firestore->findUserByEmail('test@example.com')) {
            $firestore->create('users', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => Hash::make('password'),
                'hide_online_status' => false,
                'remember_token' => null,
                'email_verified_at' => null,
            ]);
        }
    }
}
