<?php

namespace App\Models;

use App\Support\FirestoreDocument;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class User extends FirestoreDocument implements AuthenticatableContract, Arrayable, JsonSerializable
{
    use Authenticatable;

    public ?string $currentAccessTokenHash = null;

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return (string) ($this->attributes['password'] ?? '');
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        unset($data['password'], $data['remember_token']);

        return $data;
    }
}
