<?php

declare(strict_types=1);

namespace App\Console\Commands\Feedback;

use App\Console\Commands\Feedback\Concerns\InteractsWithFeedback;
use App\Models\FeedbackProject;
use App\Support\PublicKeyGenerator;
use Illuminate\Console\Command;

final class CreateProjectCommand extends Command
{
    use InteractsWithFeedback;

    protected $signature = 'feedback:project:create
        {name : Project name}
        {--origin=* : Allowed origin pattern (at least one)}
        {--ip=* : Allowed IP or CIDR range (empty = no restriction)}
        {--color= : black, white or hex (#0A7C66)}
        {--position= : Launcher corner: bottom-right (default) or bottom-left}
        {--limit= : Daily limit for this project}
        {--allow-any-origin : Confirms the origin "*"}
        {--by= : Name recorded in the activity log}
        {--json : Output as JSON}';

    protected $description = 'Create a feedback project and print its public key and init snippet';

    public function handle(): int
    {
        $this->recordActor();

        $name = trim($this->stringArgument('name'));

        if ($name === '' || mb_strlen($name) > 120) {
            $this->components->error('The name must be 1 to 120 characters long.');

            return self::INVALID;
        }

        $originInput = $this->listOption('origin');

        if ($originInput === []) {
            $this->components->error('At least one --origin is required.');

            return self::INVALID;
        }

        $origins = $this->validateOrigins($originInput, (bool) $this->option('allow-any-origin'));
        $ips = $this->validateIps($this->listOption('ip'));

        if ($origins === null || $ips === null) {
            return self::INVALID;
        }

        $color = null;
        $colorInput = $this->stringOption('color');

        if ($colorInput !== null && ($color = $this->parseColor($colorInput)) === null) {
            return self::INVALID;
        }

        $position = null;
        $positionInput = $this->stringOption('position');

        if ($positionInput !== null && ($position = $this->parsePosition($positionInput)) === null) {
            return self::INVALID;
        }

        $theme = array_filter(['primary' => $color?->primary, 'position' => $position]);

        $limit = $this->stringOption('limit');

        if ($limit !== null && (! ctype_digit($limit) || (int) $limit < 1)) {
            $this->components->error('--limit must be a positive integer.');

            return self::INVALID;
        }

        do {
            $key = PublicKeyGenerator::generate();
        } while (FeedbackProject::query()->where('public_key', $key)->exists());

        $project = FeedbackProject::query()->create([
            'name' => $name,
            'public_key' => $key,
            'theme' => $theme !== [] ? $theme : null,
            'allowed_origins' => $origins,
            'allowed_ips' => $ips,
            'is_active' => true,
            'daily_limit' => $limit !== null ? (int) $limit : null,
        ]);

        if ($this->option('json')) {
            return $this->printJson($this->projectData($project->refresh()));
        }

        $this->components->info('Project created.');
        $this->printProject($project->refresh());

        return self::SUCCESS;
    }
}
