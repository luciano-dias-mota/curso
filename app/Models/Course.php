<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Course extends Model
{
    protected string $table = 'courses';

    protected array $fillable = [
        'title', 'slug', 'short_description', 'description', 'cover_image',
        'status', 'difficulty', 'required_score', 'xp_reward', 'position',
        'published_at', 'created_by'
    ];
}
