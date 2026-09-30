<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Module extends Model
{
    protected string $table = 'modules';

    protected array $fillable = [
        'course_id', 'title', 'slug', 'description', 'icon',
        'position', 'required_score', 'xp_reward', 'status'
    ];
}
