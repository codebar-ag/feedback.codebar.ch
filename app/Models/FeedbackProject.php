<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\IpMatcher;
use App\Support\OriginMatcher;
use App\Support\ProjectColor;
use Database\Factories\FeedbackProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $name
 * @property string $public_key
 * @property array{primary?: string, position?: string}|null $theme
 * @property list<string> $allowed_origins
 * @property list<string> $allowed_ips
 * @property bool $is_active
 * @property int|null $daily_limit
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FeedbackProject extends Model
{
    /** @use HasFactory<FeedbackProjectFactory> */
    use HasFactory;

    use HasUlids;
    use LogsActivity;

    /** Corners the widget launcher can sit in; the first one is the default. */
    public const array POSITIONS = ['bottom-right', 'bottom-left'];

    protected $table = 'feedback_projects';

    protected $fillable = [
        'name',
        'public_key',
        'theme',
        'allowed_origins',
        'allowed_ips',
        'is_active',
        'daily_limit',
    ];

    protected $attributes = [
        'allowed_ips' => '[]',
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'allowed_origins' => 'array',
            'allowed_ips' => 'array',
            'is_active' => 'boolean',
            'daily_limit' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('feedback')
            ->logOnly(['name', 'public_key', 'theme', 'allowed_origins', 'allowed_ips', 'is_active', 'daily_limit'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return HasMany<FeedbackEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(FeedbackEntry::class, 'project_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function originMatcher(): OriginMatcher
    {
        return new OriginMatcher($this->allowed_origins);
    }

    public function ipMatcher(): IpMatcher
    {
        return new IpMatcher($this->allowed_ips);
    }

    /** Project color, falling back to config("feedback.default_color"). */
    public function color(): ProjectColor
    {
        $hex = is_string($this->theme['primary'] ?? null) ? ProjectColor::parse($this->theme['primary']) : null;

        if ($hex === null) {
            $default = config('feedback.default_color');
            $hex = ProjectColor::parse(is_string($default) ? $default : 'black') ?? ProjectColor::BLACK;
        }

        return ProjectColor::derive($hex);
    }

    /** Corner of the widget launcher, falling back to bottom-right. */
    public function position(): string
    {
        $position = $this->theme['position'] ?? null;

        return in_array($position, self::POSITIONS, true) ? $position : self::POSITIONS[0];
    }
}
