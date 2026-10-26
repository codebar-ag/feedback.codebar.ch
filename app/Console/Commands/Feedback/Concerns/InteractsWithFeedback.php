<?php

declare(strict_types=1);

namespace App\Console\Commands\Feedback\Concerns;

use App\Models\FeedbackEntry;
use App\Models\FeedbackProject;
use App\Support\IpMatcher;
use App\Support\OriginMatcher;
use App\Support\ProjectColor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/** @mixin Command */
trait InteractsWithFeedback
{
    /** Stores --by for the activity log entries written by this command. */
    protected function recordActor(): void
    {
        $by = $this->hasOption('by') ? $this->input->getOption('by') : null;

        if (is_string($by) && trim($by) !== '') {
            Context::add('activity_by', trim($by));
        } else {
            Context::forget('activity_by');
        }
    }

    protected function findProject(string $key): ?FeedbackProject
    {
        $project = FeedbackProject::query()->where('public_key', $key)->orWhere('id', $key)->first();

        if ($project === null) {
            $this->components->error("Project [{$key}] not found.");
        }

        return $project;
    }

    protected function findEntry(string $id): ?FeedbackEntry
    {
        $entry = FeedbackEntry::query()->find($id);

        if ($entry === null) {
            $this->components->error("Feedback [{$id}] not found.");
        }

        return $entry;
    }

    /**
     * @param  list<string>  $origins
     * @return list<string>|null
     */
    protected function validateOrigins(array $origins, bool $allowAny): ?array
    {
        $normalized = [];

        foreach ($origins as $origin) {
            $origin = OriginMatcher::normalize($origin);

            if (! OriginMatcher::isValidPattern($origin)) {
                $this->components->error("Invalid origin [{$origin}]. Examples: https://app.example.ch, https://*.example.ch, http://localhost:*");

                return null;
            }

            if ($origin === '*' && ! $allowAny && ! $this->confirm('Allow every origin (*)? Only for public websites.', false)) {
                $this->components->error('Origin [*] requires confirmation (--allow-any-origin).');

                return null;
            }

            $normalized[] = $origin;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $ips
     * @return list<string>|null
     */
    protected function validateIps(array $ips): ?array
    {
        foreach ($ips as $ip) {
            if (! IpMatcher::isValid($ip)) {
                $this->components->error("Invalid IP or CIDR [{$ip}]. Examples: 203.0.113.7, 10.0.0.0/8, 2001:db8::/32");

                return null;
            }
        }

        return array_values(array_unique($ips));
    }

    protected function parseColor(string $input): ?ProjectColor
    {
        $hex = ProjectColor::parse($input);

        if ($hex === null) {
            $this->components->error("Invalid color [{$input}]. Use black, white or a hex value like #0A7C66.");

            return null;
        }

        return ProjectColor::derive($hex);
    }

    /** @return list<string> */
    protected function listOption(string $name): array
    {
        $value = $this->option($name);

        return is_array($value) ? array_values(array_filter(array_map(strval(...), $value), fn (string $v): bool => trim($v) !== '')) : [];
    }

    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    protected function countAttribute(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        return is_int($value) || is_string($value) ? (string) $value : '0';
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function colorLine(ProjectColor $color): string
    {
        $line = "{$color->primary} · text {$color->onPrimary} · contrast {$color->formattedContrast()}";

        if ($color->strong !== $color->primary) {
            $line .= " · buttons {$color->strong} ({$color->onStrong}, ".number_format($color->strongContrast, 2, '.', '').':1)';
        }

        return $line;
    }

    protected function snippet(FeedbackProject $project): string
    {
        $endpoint = config('feedback.endpoint');

        return sprintf(
            "initFeedback({ projectKey: '%s', endpoint: '%s', locale: 'de_CH' })",
            $project->public_key,
            is_string($endpoint) ? rtrim($endpoint, '/') : '',
        );
    }

    /** @return array<string, mixed> */
    protected function projectData(FeedbackProject $project): array
    {
        $color = $project->color();

        return [
            'id' => $project->id,
            'name' => $project->name,
            'public_key' => $project->public_key,
            'is_active' => $project->is_active,
            'color' => $color->toArray(),
            'allowed_origins' => $project->allowed_origins,
            'allowed_ips' => $project->allowed_ips,
            'daily_limit' => $project->daily_limit,
            'open' => $project->entries()->open()->count(),
            'completed' => $project->entries()->completed()->count(),
            'snippet' => $this->snippet($project),
            'created_at' => $project->created_at->toIso8601String(),
            'updated_at' => $project->updated_at->toIso8601String(),
        ];
    }

    protected function printProject(FeedbackProject $project): void
    {
        $this->components->twoColumnDetail('Name', $project->name);
        $this->components->twoColumnDetail('Public Key', $project->public_key);
        $this->components->twoColumnDetail('Active', $project->is_active ? 'yes' : 'no');
        $this->components->twoColumnDetail('Origins', implode(', ', $project->allowed_origins));
        $this->components->twoColumnDetail('IPs', $project->allowed_ips === [] ? '(no restriction)' : implode(', ', $project->allowed_ips));
        $this->components->twoColumnDetail('Color', $this->colorLine($project->color()));
        $this->components->twoColumnDetail('Daily limit', $project->daily_limit === null ? '(no limit)' : (string) $project->daily_limit);
        $this->line('Init snippet:');
        $this->line('  '.$this->snippet($project));
    }

    /** @param array<mixed> $data */
    protected function printJson(array $data): int
    {
        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
