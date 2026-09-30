<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Phase extends Model
{
    protected string $table = 'phases';

    protected array $fillable = [
        'module_id', 'title', 'slug', 'description', 'phase_type',
        'position', 'required_score', 'required_lessons_pct',
        'max_attempts', 'xp_reward', 'status'
    ];
}
