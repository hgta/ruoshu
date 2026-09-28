<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    private static int $seq = 0;

    public function definition(): array
    {
        self::$seq++;

        return [
            'name' => '书友'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'email' => 'reader'.self::$seq.'@test.local',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'roles' => User::ROLE_READER,
            'status' => 1,
        ];
    }

    /** 作者角色 */
    public function author(): static
    {
        return $this->state(fn () => ['roles' => User::ROLE_READER | User::ROLE_AUTHOR]);
    }
}
