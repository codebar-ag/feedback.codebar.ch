<?php

declare(strict_types=1);

use App\Models\FeedbackEntry;
use App\Models\FeedbackProject;
use App\Models\FeedbackScreenshot;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

function artisanJson(string $command, array $parameters = []): array
{
    Artisan::call($command, [...$parameters, '--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('creates a project and prints key, color and snippet', function (): void {
    test()->artisan('feedback:project:create', [
        'name' => 'Beispiel Website',
        '--origin' => ['https://www.beispiel.ch', 'https://*.beispiel.ch/'],
        '--ip' => ['10.0.0.0/8'],
        '--color' => '#0A7C66',
        '--limit' => '500',
    ])
        ->expectsOutputToContain('Project created.')
        ->expectsOutputToContain('#0A7C66 · text #FFFFFF · contrast 5.14:1')
        ->expectsOutputToContain("endpoint: 'https://feedback.example.test', locale: 'de_CH' })")
        ->assertExitCode(0);

    expect(FeedbackProject::query()->sole())
        ->name->toBe('Beispiel Website')
        ->public_key->toMatch('/^fbk_live_[0-9A-Za-z]{22}$/')
        ->theme->toBe(['primary' => '#0A7C66'])
        ->allowed_origins->toBe(['https://www.beispiel.ch', 'https://*.beispiel.ch'])
        ->allowed_ips->toBe(['10.0.0.0/8'])
        ->is_active->toBeTrue()
        ->daily_limit->toBe(500);
});

it('creates a project as JSON', function (): void {
    $data = artisanJson('feedback:project:create', ['name' => 'Intranet', '--origin' => ['https://intranet.firma.ch'], '--color' => 'white']);

    expect($data)
        ->toHaveKeys(['id', 'public_key', 'snippet', 'color'])
        ->and($data['color'])->toMatchArray(['primary' => '#FFFFFF', 'on_primary' => '#101F3C', 'contrast' => 16.36, 'divider' => true, 'strong' => '#101F3C'])
        ->and($data['allowed_ips'])->toBe([]);
});

it('shows the adjusted button color for mid tones', function (): void {
    test()->artisan('feedback:project:create', ['name' => 'Grau', '--origin' => ['https://a.ch'], '--color' => '#808080'])
        ->expectsOutputToContain('buttons #')
        ->assertExitCode(0);
});

it('validates create input', function (array $parameters, string $message): void {
    test()->artisan('feedback:project:create', ['name' => 'X', ...$parameters])
        ->expectsOutputToContain($message)
        ->assertExitCode(2);

    expect(FeedbackProject::query()->count())->toBe(0);
})->with([
    'no origin' => [[], 'At least one --origin'],
    'bad origin' => [['--origin' => ['www.beispiel.ch']], 'Invalid origin'],
    'bad ip' => [['--origin' => ['https://a.ch'], '--ip' => ['10.0.0.0/40']], 'Invalid IP'],
    'bad color' => [['--origin' => ['https://a.ch'], '--color' => 'blau'], 'Invalid color'],
    'bad hex' => [['--origin' => ['https://a.ch'], '--color' => '#12345'], 'Invalid color'],
    'bad position' => [['--origin' => ['https://a.ch'], '--position' => 'middle'], 'Invalid position'],
    'bad limit' => [['--origin' => ['https://a.ch'], '--limit' => '0'], '--limit must be'],
    'empty name' => [['name' => ' ', '--origin' => ['https://a.ch']], 'The name must be'],
]);

it('requires confirmation for the any-origin pattern', function (): void {
    test()->artisan('feedback:project:create', ['name' => 'Public', '--origin' => ['*']])
        ->expectsConfirmation('Allow every origin (*)? Only for public websites.', 'no')
        ->assertExitCode(2);

    test()->artisan('feedback:project:create', ['name' => 'Public', '--origin' => ['*']])
        ->expectsConfirmation('Allow every origin (*)? Only for public websites.', 'yes')
        ->assertExitCode(0);

    test()->artisan('feedback:project:create', ['name' => 'Public 2', '--origin' => ['*'], '--allow-any-origin' => true])->assertExitCode(0);

    expect(FeedbackProject::query()->count())->toBe(2);
});

it('lists projects with open and completed counts', function (): void {
    $project = FeedbackProject::factory()->create(['name' => 'Alpha', 'allowed_ips' => ['10.0.0.1']]);
    FeedbackEntry::factory()->for($project, 'project')->count(2)->create();
    FeedbackEntry::factory()->for($project, 'project')->completed()->create();

    test()->artisan('feedback:project:list')
        ->expectsTable(
            ['Name', 'Key', 'Active', 'Color', 'Origins', 'IPs', 'Open', 'Completed'],
            [['Alpha', $project->public_key, 'yes', '#0A7C66', 'https://www.beispiel.ch', '10.0.0.1', '2', '1']],
        )
        ->assertExitCode(0);

    expect(artisanJson('feedback:project:list'))->toHaveCount(1)
        ->and(artisanJson('feedback:project:list')[0])->toMatchArray(['name' => 'Alpha', 'open' => 2, 'completed' => 1]);
});

it('lists no projects', function (): void {
    test()->artisan('feedback:project:list')->expectsOutputToContain('No projects yet')->assertExitCode(0);
});

it('shows a project by key or id', function (): void {
    $project = FeedbackProject::factory()->create(['name' => 'Alpha']);

    test()->artisan('feedback:project:show', ['key' => $project->public_key])
        ->expectsOutputToContain('Alpha')
        ->expectsOutputToContain('(no restriction)')
        ->expectsOutputToContain('Init snippet')
        ->assertExitCode(0);

    expect(artisanJson('feedback:project:show', ['key' => $project->id])['public_key'])->toBe($project->public_key);

    test()->artisan('feedback:project:show', ['key' => 'fbk_live_nope'])->expectsOutputToContain('not found')->assertExitCode(1);
});

it('updates origins, IPs, color, limit and status', function (): void {
    $project = FeedbackProject::factory()->create(['allowed_origins' => ['https://www.beispiel.ch'], 'allowed_ips' => ['10.0.0.1']]);

    test()->artisan('feedback:project:update', [
        'key' => $project->public_key,
        '--name' => 'Neu',
        '--add-origin' => ['http://localhost:*'],
        '--remove-origin' => ['https://WWW.beispiel.ch'],
        '--add-ip' => ['2001:db8::/32'],
        '--remove-ip' => ['10.0.0.1'],
        '--color' => 'black',
        '--limit' => '100',
        '--deactivate' => true,
    ])->expectsOutputToContain('Project updated.')->assertExitCode(0);

    expect($project->refresh())
        ->name->toBe('Neu')
        ->allowed_origins->toBe(['http://localhost:*'])
        ->allowed_ips->toBe(['2001:db8::/32'])
        ->theme->toBe(['primary' => '#000000'])
        ->daily_limit->toBe(100)
        ->is_active->toBeFalse();

    test()->artisan('feedback:project:update', ['key' => $project->id, '--activate' => true, '--limit' => 'none'])->assertExitCode(0);
    expect($project->refresh())->is_active->toBeTrue()->daily_limit->toBeNull();

    test()->artisan('feedback:project:update', ['key' => $project->id])->expectsOutputToContain('No changes.')->assertExitCode(0);
    expect(artisanJson('feedback:project:update', ['key' => $project->id, '--color' => '#ED813A'])['color']['on_primary'])->toBe('#101F3C');
});

it('sets the launcher position without touching the color', function (): void {
    $project = FeedbackProject::factory()->create(['theme' => ['primary' => '#0A7C66']]);

    test()->artisan('feedback:project:update', ['key' => $project->public_key, '--position' => 'bottom-left'])
        ->expectsOutputToContain('Project updated.')
        ->expectsOutputToContain('bottom-left')
        ->assertExitCode(0);
    expect($project->refresh())->theme->toBe(['primary' => '#0A7C66', 'position' => 'bottom-left']);

    expect(artisanJson('feedback:project:update', ['key' => $project->id, '--color' => 'black']))
        ->position->toBe('bottom-left')
        ->and($project->refresh()->theme)->toBe(['primary' => '#000000', 'position' => 'bottom-left']);

    $created = artisanJson('feedback:project:create', ['name' => 'Links', '--origin' => ['https://a.ch'], '--position' => 'bottom-left']);
    expect($created['position'])->toBe('bottom-left')
        ->and(FeedbackProject::query()->findOrFail($created['id'])->theme)->toBe(['position' => 'bottom-left']);

    expect(artisanJson('feedback:project:create', ['name' => 'Standard', '--origin' => ['https://b.ch']])['position'])->toBe('bottom-right');
});

it('validates update input', function (array $parameters, string $message, int $code = 2): void {
    $project = FeedbackProject::factory()->create(['allowed_origins' => ['https://a.ch']]);

    test()->artisan('feedback:project:update', ['key' => $project->public_key, ...$parameters])
        ->expectsOutputToContain($message)
        ->assertExitCode($code);
})->with([
    'remove last origin' => [['--remove-origin' => ['https://a.ch']], 'at least one allowed origin'],
    'bad origin' => [['--add-origin' => ['nope']], 'Invalid origin'],
    'bad ip' => [['--add-ip' => ['x']], 'Invalid IP'],
    'bad color' => [['--color' => 'x'], 'Invalid color'],
    'bad position' => [['--position' => 'top-left'], 'Invalid position'],
    'bad limit' => [['--limit' => '-1'], '--limit must be'],
    'both flags' => [['--activate' => true, '--deactivate' => true], 'either --activate or --deactivate'],
    'long name' => [['--name' => str_repeat('a', 121)], 'The name must be'],
    'unknown project' => [['key' => 'unknown'], 'not found', 1],
]);

it('rotates the public key', function (): void {
    $project = FeedbackProject::factory()->create();
    $old = $project->public_key;

    test()->artisan('feedback:project:rotate-key', ['key' => $old])
        ->expectsOutputToContain('Key rotated')
        ->assertExitCode(0);

    expect($project->refresh()->public_key)->not->toBe($old)->toStartWith('fbk_live_');

    $data = artisanJson('feedback:project:rotate-key', ['key' => $project->id]);
    expect($data['old_public_key'])->toBe($project->public_key)
        ->and($data['public_key'])->toBe($project->refresh()->public_key);

    test()->artisan('feedback:project:rotate-key', ['key' => $old])->assertExitCode(1);
    test()->post("/v1/projects/{$old}/feedback", feedbackPayload(), ['Origin' => TEST_ORIGIN])->assertNotFound();
});

it('deletes a project with its feedback and screenshots', function (): void {
    $project = FeedbackProject::factory()->create(['name' => 'Alt']);
    $other = FeedbackEntry::factory()->create();
    $entry = FeedbackEntry::factory()->for($project, 'project')->create();
    FeedbackScreenshot::query()->create(['entry_id' => $entry->id, 'mime' => 'image/png', 'width' => 1, 'height' => 1, 'bytes' => 1, 'sha256' => str_repeat('0', 64), 'data' => 'x']);

    test()->artisan('feedback:project:delete', ['key' => $project->public_key])
        ->expectsConfirmation('Delete [Alt] and its 1 feedback entries? This cannot be undone.', 'no')
        ->assertExitCode(1);
    expect($project->exists())->toBeTrue();

    test()->artisan('feedback:project:delete', ['key' => $project->public_key, '--force' => true, '--by' => 'Anna'])
        ->expectsOutputToContain('Project [Alt] deleted with 1 feedback entries.')
        ->assertExitCode(0);

    expect(FeedbackProject::query()->find($project->id))->toBeNull()
        ->and(FeedbackEntry::query()->pluck('id')->all())->toBe([$other->id])
        ->and(FeedbackScreenshot::query()->count())->toBe(0)
        ->and(Activity::query()->where('subject_id', $project->id)->latest('id')->first())
        ->event->toBe('deleted')
        ->properties->toArray()->toMatchArray(['by' => 'Anna']);

    test()->post("/v1/projects/{$project->public_key}/feedback", feedbackPayload(), ['Origin' => TEST_ORIGIN])->assertNotFound();
    test()->artisan('feedback:project:delete', ['key' => $project->id, '--force' => true])->assertExitCode(1);
});

it('deletes a project as JSON', function (): void {
    $project = FeedbackProject::factory()->create();

    expect(artisanJson('feedback:project:delete', ['key' => $project->id, '--force' => true]))
        ->toMatchArray(['id' => $project->id, 'public_key' => $project->public_key, 'deleted_entries' => 0]);
});
