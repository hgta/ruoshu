<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

class BookPolicy
{
    public function view(User $user, Book $book): bool
    {
        return $book->user_id === $user->id || $user->isAdmin();
    }

    public function update(User $user, Book $book): bool
    {
        return $book->user_id === $user->id || $user->isAdmin();
    }

    public function delete(User $user, Book $book): bool
    {
        return $book->user_id === $user->id || $user->isAdmin();
    }
}
