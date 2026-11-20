<?php

declare(strict_types=1);

use App\Models\FeedbackProject;

it('returns the project color and launcher position', function (): void {
    $project = FeedbackProject::factory()->create(['theme' => ['primary' => '#0A7C66', 'position' => 'bottom-left'], 'allowed_origins' => [TEST_ORIGIN]]);

    test()->getJson("/v1/projects/{$project->public_key}/config", ['Origin' => TEST_ORIGIN])
        ->assertOk()
        ->assertExactJson(['theme' => ['primary' => '#0A7C66'], 'position' => 'bottom-left'])
        ->assertHeader('Access-Control-Allow-Origin', TEST_ORIGIN)
        ->assertHeader('Cache-Control', 'max-age=600, public');
});

it('falls back to the default color', function (?string $default, string $expected): void {
    config(['feedback.default_color' => $default]);
    $project = FeedbackProject::factory()->create(['theme' => null, 'allowed_origins' => [TEST_ORIGIN]]);

    test()->getJson("/v1/projects/{$project->public_key}/config", ['Origin' => TEST_ORIGIN])
        ->assertOk()
        ->assertExactJson(['theme' => ['primary' => $expected], 'position' => 'bottom-right']);
})->with([
    ['black', '#000000'],
    ['white', '#FFFFFF'],
    ['#ED813A', '#ED813A'],
    ['invalid', '#000000'],
    [null, '#000000'],
]);

it('applies the same origin and IP checks as sending', function (): void {
    $project = FeedbackProject::factory()->create(['allowed_origins' => [TEST_ORIGIN], 'allowed_ips' => ['10.0.0.0/8']]);
    $url = "/v1/projects/{$project->public_key}/config";

    test()->getJson($url, ['Origin' => 'https://evil.example'])->assertForbidden();
    test()->getJson($url)->assertForbidden();
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->getJson($url, ['Origin' => TEST_ORIGIN])->assertForbidden();
    test()->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->getJson($url, ['Origin' => TEST_ORIGIN])->assertOk();
});

it('hides inactive projects', function (): void {
    $project = FeedbackProject::factory()->inactive()->create(['allowed_origins' => [TEST_ORIGIN]]);

    test()->getJson("/v1/projects/{$project->public_key}/config", ['Origin' => TEST_ORIGIN])
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'project_not_found']]);
});

it('lets a refused page read the 403 but not send', function (): void {
    $project = FeedbackProject::factory()->create(['allowed_origins' => [TEST_ORIGIN]]);

    test()->getJson("/v1/projects/{$project->public_key}/config", ['Origin' => 'https://evil.example'])
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'origin_not_allowed']])
        ->assertHeader('Access-Control-Allow-Origin', 'https://evil.example')
        ->assertHeader('Vary', 'Origin')
        ->assertHeaderMissing('Access-Control-Allow-Methods')
        ->assertHeaderMissing('Access-Control-Allow-Headers');

    test()->call('OPTIONS', "/v1/projects/{$project->public_key}/feedback", server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])
        ->assertForbidden()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('falls back to bottom-right for a missing or unknown position', function (?array $theme): void {
    $project = FeedbackProject::factory()->create(['theme' => $theme, 'allowed_origins' => [TEST_ORIGIN]]);

    test()->getJson("/v1/projects/{$project->public_key}/config", ['Origin' => TEST_ORIGIN])
        ->assertOk()
        ->assertJsonPath('position', 'bottom-right');
})->with([
    'no theme' => [null],
    'color only' => [['primary' => '#0A7C66']],
    'unknown corner' => [['primary' => '#0A7C66', 'position' => 'top-left']],
]);
