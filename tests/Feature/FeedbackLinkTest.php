<?php

declare(strict_types=1);

use App\Models\FeedbackEntry;
use App\Models\FeedbackProject;
use App\Models\FeedbackScreenshot;
use App\Support\FeedbackLink;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

const NAVIGATE = ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'];

beforeEach(function (): void {
    $this->project = FeedbackProject::factory()->create(['name' => 'Alpha', 'allowed_origins' => [TEST_ORIGIN]]);
});

/** @return array{id: string, url: string, screenshot_url: string|null, expires_at: string} */
function feedbackLink(string $id, array $options = []): array
{
    Artisan::call('feedback:link', ['id' => $id, '--json' => true, ...$options]);

    return json_decode(Artisan::output(), true);
}

function entryWithShot(FeedbackProject $project, array $attributes = []): FeedbackEntry
{
    postFeedback($project, ['screenshot' => fakeJpeg(200, 400), ...$attributes])->assertCreated();

    return FeedbackEntry::query()->latest('id')->firstOrFail();
}

it('creates a signed link with the default expiry', function (): void {
    $this->freezeSecond();
    $entry = entryWithShot($this->project);

    $data = feedbackLink($entry->id);

    expect($data['id'])->toBe($entry->id)
        ->and($data['url'])->toStartWith(url("/v1/feedback/{$entry->id}?expires="))->toContain('&signature=')
        ->and($data['screenshot_url'])->toContain("/v1/feedback/{$entry->id}/screenshot?download=1&expires=")
        ->and($data['expires_at'])->toBe(now()->addMinutes(FeedbackLink::DEFAULT_MINUTES)->toIso8601String());

    test()->artisan('feedback:link', ['id' => $entry->id, '--expires' => '60'])
        ->expectsOutputToContain('/v1/feedback/'.$entry->id)
        ->expectsOutputToContain(now()->addHour()->toDateTimeString())
        ->expectsOutputToContain('Anyone with this link')
        ->assertExitCode(0);
});

it('validates the link options', function (string $expires): void {
    $entry = FeedbackEntry::factory()->for($this->project, 'project')->create();

    test()->artisan('feedback:link', ['id' => $entry->id, '--expires' => $expires])
        ->expectsOutputToContain('--expires must be between 1 and 43200')
        ->assertExitCode(2);
})->with(['0', 'x', '-5', '43201', '1.5']);

it('fails for an unknown entry and links entries without screenshot', function (): void {
    test()->artisan('feedback:link', ['id' => '01JUNKNOWN'])->assertExitCode(1);

    $entry = FeedbackEntry::factory()->for($this->project, 'project')->create();
    expect(feedbackLink($entry->id)['screenshot_url'])->toBeNull();
    test()->artisan('feedback:link', ['id' => $entry->id])->expectsOutputToContain('none')->assertExitCode(0);
});

it('shows the feedback page in the browser with all form details', function (): void {
    $entry = entryWithShot($this->project, [
        'identity' => json_encode(['id' => 'u-1', 'name' => 'Anna Muster']),
        'submitted_from_url' => 'https://www.beispiel.ch/zwei',
        'console_log' => json_encode([['type' => 'error', 'message' => 'Boom', 'count' => 2, 'time' => 1]]),
        'network_log' => json_encode([['method' => 'POST', 'url' => 'https://api.beispiel.ch/save', 'status' => 500, 'duration' => 120, 'count' => 1, 'time' => 1]]),
        'custom_data' => json_encode(['plan' => 'pro']),
    ]);

    $response = test()->get(feedbackLink($entry->id)['url'], NAVIGATE);

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Alpha')
        ->assertSee('open')
        ->assertSee('Die Schaltfläche «Speichern» reagiert nicht.')
        ->assertSee('Anna Muster')
        ->assertSee('<a href="mailto:anna@beispiel.ch">anna@beispiel.ch</a>', false)
        ->assertSee('data-copy="anna@beispiel.ch"', false)
        ->assertSee('<a href="https://www.beispiel.ch/konto"', false)
        ->assertSee('Sent from')
        ->assertSee('https://www.beispiel.ch/zwei')
        ->assertSee('<dt>Viewport</dt>', false)
        ->assertSee('role="tab" id="tab-technical"', false)
        ->assertDontSee('<span class="count err">', false)
        ->assertSee('2 console errors')
        ->assertSee('Console <span class="count">2</span>', false)
        ->assertSee('Copy report')
        ->assertSee('data-copy="[error] Boom"', false)
        ->assertSee('data-copy="[error] Boom'."\n".'[error] Boom"', false)
        ->assertDontSee('×2')
        ->assertSee('POST https://api.beispiel.ch/save → 500 (120 ms)')
        ->assertSee('1541 × 947')
        ->assertSee('Europe/Zurich')
        ->assertSee('Boom')
        ->assertSee('https://api.beispiel.ch/save')
        ->assertSee('&quot;plan&quot;: &quot;pro&quot;', false)
        ->assertSee('200 × 400')
        ->assertSee('/screenshot?expires=', false)
        ->assertSee('/screenshot?download=1&amp;expires=', false);

    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $csp, $nonce);
    expect($csp)->toContain("default-src 'none'")->toContain("img-src 'self'")->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("script-src 'nonce-{$nonce[1]}'")
        ->and($response->getContent())->toContain('<style nonce="'.$nonce[1].'">')->toContain('<script nonce="'.$nonce[1].'">')
        ->and(substr_count((string) $response->getContent(), '<script'))->toBe(1);
    // "Copy report": plain text with the message, the environment and every log line.
    preg_match('#data-copy="(Feedback [^"]+)"#', (string) $response->getContent(), $report);
    expect(html_entity_decode($report[1]))
        ->toContain("Feedback {$entry->id} (Alpha)")
        ->toContain('Die Schaltfläche «Speichern» reagiert nicht.')
        ->toContain('Viewport: 1541 × 947')
        ->toContain("Console:\n[error] Boom\n[error] Boom\n")
        ->toContain("Network:\nPOST https://api.beispiel.ch/save → 500 (120 ms)")
        ->toContain('"plan": "pro"');
});

it('shows a completed entry without screenshot', function (): void {
    $entry = FeedbackEntry::factory()->for($this->project, 'project')->completed()->create(['screenshot_error' => 'render_failed']);

    test()->get(feedbackLink($entry->id)['url'], NAVIGATE)
        ->assertOk()
        ->assertSee('completed')
        ->assertSee('No screenshot (render_failed).')
        ->assertDontSee('Download');
});

it('loads the nonced codebar analytics tracker in production only', function (): void {
    config(['default.codebar_analytics.url' => 'https://analytics.codebar.ch/']);
    $entry = entryWithShot($this->project);
    $url = feedbackLink($entry->id)['url'];

    $local = test()->get($url, NAVIGATE)->assertOk();
    expect((string) $local->headers->get('Content-Security-Policy'))->not->toContain('connect-src')
        ->and((string) $local->getContent())->not->toContain('analytics.codebar.ch');

    app()['env'] = 'production';
    $response = test()->get($url, NAVIGATE)->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $csp, $nonce);

    expect($csp)->toContain('connect-src https://analytics.codebar.ch;')->toContain("default-src 'none'")
        ->and((string) $response->getContent())->toContain('<script src="https://analytics.codebar.ch/js/script.js" nonce="'.$nonce[1].'" crossorigin="anonymous" defer></script>')
        ->and(substr_count((string) $response->getContent(), '<script'))->toBe(2);
});

it('escapes everything the browser sent', function (): void {
    $entry = FeedbackEntry::factory()->for($this->project, 'project')->create([
        'message' => '<script>alert(1)</script>',
        'page_url' => 'javascript:alert(1)',
        'console_log' => [['type' => 'log', 'message' => '<img src=x onerror=alert(1)>'], 'not an object', ['type' => ['nested']]],
    ]);

    $response = test()->get(feedbackLink($entry->id)['url'], NAVIGATE)->assertOk();

    expect($response->getContent())
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x')
        ->not->toContain('href="javascript:')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('Console <span class="count">2</span>');
});

it('downloads the screenshot inline and as attachment', function (): void {
    $entry = entryWithShot($this->project);
    $shot = FeedbackScreenshot::query()->findOrFail($entry->id);
    $page = test()->get(feedbackLink($entry->id)['url'], NAVIGATE)->getContent();
    preg_match('#<img src="([^"]+)"#', (string) $page, $inline);

    test()->get(html_entity_decode($inline[1]), ['Sec-Fetch-Dest' => 'image'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Content-Disposition', "inline; filename=\"feedback-{$entry->id}.jpg\"")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertContent((string) $shot->data);

    test()->get(feedbackLink($entry->id)['screenshot_url'], NAVIGATE)
        ->assertOk()
        ->assertHeader('Content-Disposition', "attachment; filename=\"feedback-{$entry->id}.jpg\"")
        ->assertContent((string) $shot->data);
});

it('rejects tampered, foreign and expired links with an empty 403', function (): void {
    $entry = entryWithShot($this->project);
    $other = FeedbackEntry::factory()->for($this->project, 'project')->create();
    $data = feedbackLink($entry->id, ['--expires' => '60']);
    $inline = FeedbackLink::screenshot($entry, now()->addHour());

    $denied = fn (string $url, array $headers = NAVIGATE) => test()->get($url, $headers)->assertForbidden()->assertContent('');

    $denied(str_replace('signature=', 'signature=0', $data['url']));
    $denied(preg_replace('/signature=[^&]+/', '', $data['url']));
    $denied(str_replace($entry->id, $other->id, $data['url']));
    $denied(preg_replace('/expires=\d+/', 'expires='.now()->addYear()->timestamp, $data['url']));
    // The download flag is part of the signature.
    $denied($inline.'&download=1', []);
    $denied("/v1/feedback/{$entry->id}/screenshot", []);

    test()->get($data['url'], NAVIGATE)->assertOk();
    $this->travel(61)->minutes();
    $denied($data['url']);
    $denied($data['screenshot_url']);
});

it('reveals nothing for deleted entries or missing screenshots', function (): void {
    $entry = FeedbackEntry::factory()->for($this->project, 'project')->create();
    $url = FeedbackLink::screenshot($entry, now()->addHour());
    $page = FeedbackLink::page($entry, now()->addHour());

    test()->get($url)->assertForbidden()->assertContent('');

    $entry->delete();
    test()->get($page, NAVIGATE)->assertForbidden()->assertContent('');
});

it('returns a share link to the sender after submitting', function (): void {
    $this->freezeSecond();
    $key = (string) Str::uuid();

    $first = postFeedback($this->project, [], ['Idempotency-Key' => $key])->assertCreated();
    $again = postFeedback($this->project, [], ['Idempotency-Key' => $key])->assertOk();
    $entry = FeedbackEntry::query()->sole();

    expect($first->json())->toMatchArray([
        'id' => $entry->id,
        'status' => 'received',
        'url_expires_at' => now()->addMinutes(FeedbackLink::DEFAULT_MINUTES)->toIso8601String(),
    ])
        ->and($first->json('url'))->toContain("/v1/feedback/{$entry->id}?expires=")
        ->and($again->json('url'))->toContain("/v1/feedback/{$entry->id}?expires=");

    test()->get($first->json('url'), NAVIGATE)->assertOk()->assertSee('Die Schaltfläche');
});

it('caps the share link expiry and can switch it off', function (): void {
    $this->freezeSecond();

    config(['feedback.share_link_minutes' => 90]);
    expect(postFeedback($this->project)->assertCreated()->json('url_expires_at'))->toBe(now()->addMinutes(90)->toIso8601String());

    config(['feedback.share_link_minutes' => null]);
    expect(postFeedback($this->project)->assertCreated()->json())->toHaveKeys(['id', 'status'])->not->toHaveKey('url');
});

it('completes and reopens from a team link and logs who did it', function (): void {
    $entry = FeedbackEntry::factory()->for($this->project, 'project')->create();
    $data = feedbackLink($entry->id, ['--by' => 'Anna Muster']);
    expect($data['manage'])->toBeTrue()->and($data['url'])->toContain('manage=1')->toContain('by=Anna%20Muster');

    $page = test()->get($data['url'], NAVIGATE)->assertOk()->assertSee('Mark as completed')->assertDontSee('Reopen');
    preg_match('#<form class="state" method="post" action="([^"]+)"#', (string) $page->getContent(), $form);
    $action = html_entity_decode($form[1]);
    expect($action)->toContain("/v1/feedback/{$entry->id}/status?")->toContain('manage=1');

    test()->post($action, ['status' => 'completed'], NAVIGATE)->assertStatus(303)->assertRedirect($data['url']);
    expect($entry->refresh()->completed_at)->not->toBeNull();
    test()->get($data['url'], NAVIGATE)->assertSee('completed')->assertSee('Reopen')->assertDontSee('Mark as completed');

    // Repeating is harmless and logs nothing new.
    test()->post($action, ['status' => 'completed'])->assertStatus(303);
    test()->post($action, ['status' => 'open'])->assertStatus(303);
    expect($entry->refresh()->completed_at)->toBeNull();

    $log = Activity::query()->where('subject_id', $entry->id)->oldest('id')->get();
    expect($log->pluck('description')->all())->toBe(['completed', 'reopened'])
        ->and($log->pluck('properties.by')->unique()->all())->toBe(['Anna Muster']);
});

it('records "signed link" when the team link has no name', function (): void {
    $entry = FeedbackEntry::factory()->for($this->project, 'project')->create();
    test()->post(FeedbackLink::status($entry, now()->addHour()), ['status' => 'completed'])->assertStatus(303);

    expect(Activity::query()->where('subject_id', $entry->id)->sole()->properties['by'])->toBe('signed link');
});

it('keeps read-only and sender links without status changes', function (): void {
    $entry = FeedbackEntry::factory()->for($this->project, 'project')->create();
    $readOnly = feedbackLink($entry->id, ['--read-only' => true]);
    expect($readOnly['manage'])->toBeFalse()->and($readOnly['url'])->not->toContain('manage=');

    test()->get($readOnly['url'], NAVIGATE)->assertOk()->assertDontSee('<form', false)->assertDontSee('Mark as completed');
    test()->artisan('feedback:link', ['id' => $entry->id, '--read-only' => true])->expectsOutputToContain('read-only')->assertExitCode(0);

    $sender = postFeedback($this->project)->assertCreated()->json('url');
    test()->get($sender, NAVIGATE)->assertOk()->assertDontSee('<form', false);

    // A validly signed status URL without manage, or manage added afterwards, changes nothing.
    $withoutManage = URL::temporarySignedRoute('feedback.status', now()->addHour(), ['entry' => $entry->id]);
    test()->post($withoutManage, ['status' => 'completed'])->assertForbidden()->assertContent('');
    test()->post(str_replace('?', '?manage=1&', $withoutManage), ['status' => 'completed'])->assertForbidden()->assertContent('');
    test()->get($readOnly['url'].'&manage=1', NAVIGATE)->assertForbidden();
    test()->post("/v1/feedback/{$entry->id}/status", ['status' => 'completed'])->assertForbidden();

    expect($entry->refresh()->completed_at)->toBeNull();
});

it('validates the new status', function (): void {
    $entry = FeedbackEntry::factory()->for($this->project, 'project')->create();
    $action = FeedbackLink::status($entry, now()->addHour());

    test()->post($action, ['status' => 'deleted'])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    test()->post($action)->assertUnprocessable();
    test()->get($action)->assertStatus(405);
    $this->travel(2)->hours();
    test()->post($action, ['status' => 'completed'])->assertForbidden();
    expect($entry->refresh()->completed_at)->toBeNull();
});

it('opens links behind a TLS terminating proxy (production)', function (): void {
    // Laravel Cloud: the link is https, the app sees http plus X-Forwarded-Proto.
    TrustProxies::at('*');
    URL::forceScheme('https');
    $this->beforeApplicationDestroyed(function (): void {
        TrustProxies::flushState();
        URL::forceScheme(null);
    });
    $entry = FeedbackEntry::factory()->for($this->project, 'project')->create();
    $url = feedbackLink($entry->id)['url'];
    expect($url)->toStartWith('https://');
    $behindProxy = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', ...NAVIGATE];

    test()->get(str_replace('https://', 'http://', $url), $behindProxy)->assertOk()->assertSee($entry->message);
    test()->get(str_replace('https://', 'http://', str_replace('signature=', 'signature=0', $url)), $behindProxy)->assertForbidden()->assertContent('');
    test()->get(str_replace('https://', 'http://', url('/up')).'?signature=x', $behindProxy)->assertForbidden()->assertContent('');
});
