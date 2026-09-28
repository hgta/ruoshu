<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gift extends Model
{
    protected $fillable = ['name', 'icon', 'price', 'sort', 'enabled'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'sort' => 'integer', 'enabled' => 'boolean'];
    }
}
