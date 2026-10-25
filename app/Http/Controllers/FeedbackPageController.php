<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FeedbackEntry;
use App\Support\FeedbackLink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Page of one feedback entry behind a signed link (feedback:link, share link):
 * screenshot with the sender's message, technical details in a second tab.
 * The only HTML the API serves: one nonce'd inline script (tabs, copy
 * buttons) and, in production, the nonce'd codebar analytics tracker.
 */
final class FeedbackPageController
{
    /** Upper bound of rows per log once repeated entries are spread out. */
    private const MAX_ROWS = 500;

    public function __invoke(Request $request, FeedbackEntry $entry): Response
    {
        $expires = Carbon::createFromTimestamp($request->integer('expires'));
        $by = $request->query('by');
        // Metadata only, the BLOB is served by the screenshot link.
        $shot = $entry->screenshot()->select(['entry_id', 'mime', 'width', 'height', 'bytes'])->first();
        $nonce = Str::random(32);
        $environment = self::environment($entry);
        $console = self::rows($entry->console_log, ['type', 'message', 'source', 'line', 'column', 'stack']);
        $network = self::rows($entry->network_log, ['method', 'url', 'status', 'duration']);
        $analyticsUrl = self::analyticsUrl();
        $customData = $entry->custom_data === null || $entry->custom_data === [] ? null : (string) json_encode($entry->custom_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response()->view('feedback.show', [
            'entry' => $entry,
            'project' => $entry->project,
            'shot' => $shot,
            'imageUrl' => $shot === null ? null : FeedbackLink::screenshot($entry, $expires),
            'downloadUrl' => $shot === null ? null : FeedbackLink::screenshot($entry, $expires, download: true),
            'pageHref' => FeedbackLink::safeHref($entry->page_url),
            'sentFromHref' => FeedbackLink::safeHref($entry->submitted_from_url),
            'environment' => $environment,
            'console' => $console,
            'consoleErrors' => count(array_filter($console, fn (array $row): bool => $row['type'] === 'error')),
            'network' => $network,
            'customData' => $customData,
            'report' => self::report($entry, $environment, $console, $network, $customData),
            'statusUrl' => $request->boolean('manage') ? FeedbackLink::status($entry, $expires, is_string($by) ? $by : null) : null,
            'expires' => $expires,
            'nonce' => $nonce,
            'analyticsScript' => $analyticsUrl === null ? null : $analyticsUrl.'/js/script.js',
        ])->withHeaders([
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; ".($analyticsUrl === null ? '' : "connect-src {$analyticsUrl}; ")."base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Origin of codebar analytics when the tracker runs in this environment. */
    private static function analyticsUrl(): ?string
    {
        $url = config('default.codebar_analytics.url');
        $environments = (array) config('default.codebar_analytics.environments', []);

        return is_string($url) && $url !== '' && app()->environment($environments) ? rtrim($url, '/') : null;
    }

    /** @return array<string, string> label => value, only what is known */
    private static function environment(FeedbackEntry $entry): array
    {
        $size = fn (?int $w, ?int $h): ?string => $w === null || $h === null ? null : "{$w} × {$h}";
        $screen = $size($entry->screen_w, $entry->screen_h);

        return array_filter([
            'Browser' => trim(($entry->browser_name ?? '').' '.($entry->browser_version ?? '')) ?: null,
            'Operating system' => trim(($entry->os_name ?? '').' '.($entry->os_version ?? '')) ?: null,
            'Device' => $entry->device_type,
            'Viewport' => $size($entry->viewport_w, $entry->viewport_h),
            'Screen' => $screen === null ? null : $screen.($entry->dpr !== null ? " @ {$entry->dpr}x" : ''),
            'Page size' => $size($entry->page_w, $entry->page_h),
            'Scroll position' => $size($entry->scroll_x, $entry->scroll_y),
            'Color depth' => $entry->color_depth === null ? null : "{$entry->color_depth} bit",
            'Timezone' => $entry->timezone,
            'Browser language' => $entry->browser_language,
            'Widget language' => $entry->locale,
            'Page' => $entry->page_url,
            'Page title' => $entry->page_title,
            'Sent from' => $entry->submitted_from_url !== $entry->page_url ? $entry->submitted_from_url : null,
            'Referrer' => $entry->referrer,
            'Origin' => $entry->origin,
            'IP address' => $entry->ip,
            'User ID' => $entry->identity_user_id,
            'Time to send' => $entry->elapsed_ms === null ? null : number_format($entry->elapsed_ms / 1000, 1).' s',
            'Widget version' => $entry->widget_version,
            'User agent' => $entry->user_agent,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Plain text of the technical tab, for "Copy report" (tickets, chats).
     *
     * @param  array<string, string>  $environment
     * @param  list<array<string, string>>  $console
     * @param  list<array<string, string>>  $network
     */
    private static function report(FeedbackEntry $entry, array $environment, array $console, array $network, ?string $customData): string
    {
        $lines = ["Feedback {$entry->id} ({$entry->project->name}), {$entry->created_at->toDateTimeString()} UTC", '', $entry->message, ''];

        foreach ($environment as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        if ($console !== []) {
            $lines[] = '';
            $lines[] = 'Console:';

            foreach ($console as $row) {
                $lines[] = self::consoleLine($row);
            }
        }

        if ($network !== []) {
            $lines[] = '';
            $lines[] = 'Network:';

            foreach ($network as $row) {
                $lines[] = self::networkLine($row);
            }
        }

        if ($customData !== null) {
            $lines[] = '';
            $lines[] = 'Custom data:';
            $lines[] = $customData;
        }

        return implode("\n", $lines);
    }

    /** @param array<string, string> $row */
    public static function consoleLine(array $row): string
    {
        $where = $row['source'] === '' ? '' : ' ('.$row['source'].($row['line'] === '' ? '' : ':'.$row['line']).($row['column'] === '' ? '' : ':'.$row['column']).')';

        return "[{$row['type']}] {$row['message']}{$where}".($row['stack'] === '' ? '' : "\n{$row['stack']}");
    }

    /** @param array<string, string> $row */
    public static function networkLine(array $row): string
    {
        return trim("{$row['method']} {$row['url']} → {$row['status']}".($row['duration'] === '' ? '' : " ({$row['duration']} ms)"));
    }

    /**
     * Logs come from the browser: every cell becomes a plain string. The
     * widget folds identical consecutive entries into one with a count, the
     * page lists every occurrence (at most MAX_ROWS per log).
     *
     * @param  array<mixed>|null  $log
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private static function rows(?array $log, array $keys): array
    {
        $rows = [];

        foreach ($log ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = [];

            foreach ($keys as $key) {
                $value = $item[$key] ?? null;
                $row[$key] = is_scalar($value) ? (string) $value : '';
            }

            $times = max(1, is_numeric($item['count'] ?? null) ? (int) $item['count'] : 1);

            for ($i = 0; $i < $times && count($rows) < self::MAX_ROWS; $i++) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
