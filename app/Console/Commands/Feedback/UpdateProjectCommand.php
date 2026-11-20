<?php

declare(strict_types=1);

namespace App\Console\Commands\Feedback;

use App\Console\Commands\Feedback\Concerns\InteractsWithFeedback;
use App\Support\OriginMatcher;
use Illuminate\Console\Command;

final class UpdateProjectCommand extends Command
{
    use InteractsWithFeedback;

    protected $signature = 'feedback:project:update
        {key : Public key or ID}
        {--name= : New name}
        {--add-origin=* : Add an allowed origin}
        {--remove-origin=* : Remove an allowed origin}
        {--add-ip=* : Add an allowed IP/CIDR}
        {--remove-ip=* : Remove an allowed IP/CIDR}
        {--color= : black, white or hex}
        {--position= : Launcher corner: bottom-right or bottom-left}
        {--limit= : Daily limit, "none" removes it}
        {--activate : Activate the project}
        {--deactivate : Deactivate the project}
        {--allow-any-origin : Confirms the origin "*"}
        {--by= : Name recorded in the activity log}
        {--json : Output as JSON}';

    protected $description = 'Change origins, IPs, color, position, limit and status of a feedback project';

    public function handle(): int
    {
        $this->recordActor();

        $project = $this->findProject($this->stringArgument('key'));

        if ($project === null) {
            return self::FAILURE;
        }

        if ($this->option('activate') && $this->option('deactivate')) {
            $this->components->error('Use either --activate or --deactivate.');

            return self::INVALID;
        }

        $name = $this->stringOption('name');

        if ($name !== null) {
            if (mb_strlen(trim($name)) > 120) {
                $this->components->error('The name must be 1 to 120 characters long.');

                return self::INVALID;
            }

            $project->name = trim($name);
        }

        $add = $this->validateOrigins($this->listOption('add-origin'), (bool) $this->option('allow-any-origin'));
        $addIps = $this->validateIps($this->listOption('add-ip'));

        if ($add === null || $addIps === null) {
            return self::INVALID;
        }

        $remove = array_map(OriginMatcher::normalize(...), $this->listOption('remove-origin'));
        $origins = array_values(array_unique(array_diff([...$project->allowed_origins, ...$add], $remove)));

        if ($origins === []) {
            $this->components->error('A project needs at least one allowed origin.');

            return self::INVALID;
        }

        $project->allowed_origins = $origins;
        $project->allowed_ips = array_values(array_unique(array_diff([...$project->allowed_ips, ...$addIps], $this->listOption('remove-ip'))));

        $colorInput = $this->stringOption('color');

        if ($colorInput !== null) {
            $color = $this->parseColor($colorInput);

            if ($color === null) {
                return self::INVALID;
            }

            $project->theme = [...($project->theme ?? []), 'primary' => $color->primary];
        }

        $positionInput = $this->stringOption('position');

        if ($positionInput !== null) {
            $position = $this->parsePosition($positionInput);

            if ($position === null) {
                return self::INVALID;
            }

            $project->theme = [...($project->theme ?? []), 'position' => $position];
        }

        $limit = $this->stringOption('limit');

        if ($limit !== null) {
            if ($limit === 'none') {
                $project->daily_limit = null;
            } elseif (ctype_digit($limit) && (int) $limit > 0) {
                $project->daily_limit = (int) $limit;
            } else {
                $this->components->error('--limit must be a positive integer or "none".');

                return self::INVALID;
            }
        }

        if ($this->option('activate')) {
            $project->is_active = true;
        }

        if ($this->option('deactivate')) {
            $project->is_active = false;
        }

        $dirty = $project->isDirty();
        $project->save();

        if ($this->option('json')) {
            return $this->printJson($this->projectData($project));
        }

        $this->components->info($dirty ? 'Project updated.' : 'No changes.');
        $this->printProject($project);

        return self::SUCCESS;
    }
}
