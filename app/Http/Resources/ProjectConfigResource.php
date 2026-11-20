<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FeedbackProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public widget configuration. Contains nothing sensitive.
 *
 * @mixin FeedbackProject
 */
final class ProjectConfigResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{theme: array{primary: string}, position: string} */
    public function toArray(Request $request): array
    {
        return ['theme' => ['primary' => $this->color()->primary], 'position' => $this->position()];
    }
}
