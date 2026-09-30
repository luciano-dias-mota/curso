<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class User extends Model
{
    protected string $table = 'users';

    protected array $fillable = [
        'role_id', 'name', 'email', 'password_hash', 'avatar',
        'status', 'theme', 'xp_total', 'current_level',
        'current_streak', 'best_streak', 'last_study_date'
    ];
}
