<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Lesson extends Model
{
    protected string $table = 'lessons';

    protected array $fillable = [
        'phase_id', 'title', 'slug', 'summary', 'position',
        'estimated_minutes', 'xp_reward', 'status'
    ];
}
