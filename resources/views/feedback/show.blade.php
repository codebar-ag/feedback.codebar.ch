@php
    $sender = $entry->name !== null && $entry->name !== '' ? $entry->name : $entry->email;
    $initials = collect(preg_split('/[\s@._-]+/', (string) $sender) ?: [])->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $marks = count($entry->annotations ?? []);
    $short = trim(($entry->browser_name ?? '').' '.($entry->browser_version ?? '')).($entry->os_name !== null ? ' · '.trim($entry->os_name.' '.($entry->os_version ?? '')) : '');
    $pageLabel = preg_replace('#^https?://#i', '', $entry->page_url);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Feedback · {{ $project->name }}</title>
    <style nonce="{{ $nonce }}">
        :root { color-scheme: light dark; --bg: #f4f6f9; --surface: #fff; --sunken: #f1f4f8; --stage: #e7ebf1; --ink: #0f1b2d; --mute: #5f6b7c; --line: #e1e6ed; --accent: #1d4ed8; --ok: #15803d; --ok-bg: #dcfce7; --open: #b45309; --open-bg: #fef3c7; --err: #b91c1c; --err-bg: #fee2e2; --warn: #a16207; --warn-bg: #fef9c3; --shadow: 0 1px 2px rgba(15,27,45,.06), 0 8px 24px rgba(15,27,45,.08); --radius: 14px; --mono: ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0d1117; --surface: #161b22; --sunken: #1c232c; --stage: #0a0d12; --ink: #e6edf3; --mute: #8d99a8; --line: #2a3340; --accent: #6ea8ff; --ok: #4ade80; --ok-bg: #12301f; --open: #fbbf24; --open-bg: #3a2a06; --err: #f87171; --err-bg: #3b1515; --warn: #facc15; --warn-bg: #352d07; --shadow: 0 1px 2px rgba(0,0,0,.4), 0 8px 24px rgba(0,0,0,.4); } }
        * { box-sizing: border-box; }
        html { scroll-padding-top: 130px; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        button { font: inherit; color: inherit; }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        svg { width: 16px; height: 16px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        .top { position: sticky; top: 0; z-index: 20; background: color-mix(in srgb, var(--surface) 92%, transparent); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
        .bar { max-width: 1400px; margin: 0 auto; padding: 12px 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; }
        .title { display: flex; align-items: center; gap: 12px; min-width: 0; margin-right: auto; }
        h1 { font-size: 17px; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .id { display: inline-flex; align-items: center; gap: 4px; color: var(--mute); font: 12px var(--mono); }
        .status { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
        .status::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .status.open { color: var(--open); background: var(--open-bg); }
        .status.completed { color: var(--ok); background: var(--ok-bg); }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .actions form { margin: 0; }
        .btn { display: inline-flex; align-items: center; gap: 7px; height: 36px; padding: 0 14px; border-radius: 10px; border: 1px solid var(--line); background: var(--surface); color: var(--ink); font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap; }
        .btn:hover { background: var(--sunken); text-decoration: none; }
        .btn.primary { background: var(--ink); border-color: var(--ink); color: var(--surface); }
        .btn.primary:hover { opacity: .9; background: var(--ink); }
        .btn.done { background: var(--ok); border-color: var(--ok); color: #fff; }

        .tabs { max-width: 1400px; margin: 0 auto; padding: 0 20px; display: flex; gap: 4px; }
        [role=tab] { position: relative; display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; border: 0; background: none; color: var(--mute); font-weight: 600; cursor: pointer; }
        [role=tab][aria-selected=true] { color: var(--ink); }
        [role=tab][aria-selected=true]::after { content: ""; position: absolute; left: 10px; right: 10px; bottom: -1px; height: 2px; border-radius: 2px; background: var(--ink); }
        .count { font-size: 12px; padding: 1px 7px; border-radius: 999px; background: var(--sunken); color: var(--mute); }

        main { max-width: 1400px; margin: 0 auto; padding: 20px; }
        [role=tabpanel][hidden] { display: none; }

        .stage { display: grid; background: var(--stage); border-radius: var(--radius); padding: 20px; min-height: 60vh; }
        .stage > * { grid-area: 1 / 1; }
        .shot { justify-self: center; align-self: start; display: block; max-width: 100%; line-height: 0; border-radius: 10px; overflow: hidden; box-shadow: var(--shadow); background: var(--surface); }
        .shot img { display: block; max-width: 100%; height: auto; }
        .empty { justify-self: center; align-self: center; text-align: center; color: var(--mute); padding: 80px 20px; }
        .empty svg { width: 40px; height: 40px; stroke-width: 1.5; margin-bottom: 8px; }

        .card { justify-self: end; align-self: start; position: sticky; top: 132px; z-index: 5; width: min(380px, 100%); margin: 4px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: 0 18px 50px rgba(15,27,45,.22); overflow: hidden; }
        .card-head { display: flex; align-items: center; gap: 12px; padding: 16px 16px 12px; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 15px; color: #fff; background: linear-gradient(135deg, #2563eb, #7c3aed); flex: none; }
        .who { min-width: 0; flex: 1; }
        .who strong { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .who .line { display: flex; align-items: center; gap: 4px; min-width: 0; font-size: 13px; color: var(--mute); }
        .who .line a, .who .line span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .icon-btn { display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; border: 0; background: none; color: var(--mute); cursor: pointer; flex: none; }
        .icon-btn:hover { background: var(--sunken); color: var(--ink); }
        .icon-btn.done { color: var(--ok); }
        .message { margin: 0; padding: 4px 16px 16px; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 16px; max-height: 42vh; overflow: auto; }
        .facts { border-top: 1px solid var(--line); padding: 10px 16px 14px; display: grid; gap: 6px; font-size: 13px; color: var(--mute); }
        .fact { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .fact > span, .fact > a { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; padding-top: 4px; }
        .chip { font-size: 12px; padding: 2px 8px; border-radius: 999px; background: var(--sunken); color: var(--mute); }
        .card.collapsed .message, .card.collapsed .facts { display: none; }
        .card.collapsed { width: auto; }
        .card.collapsed .who { display: none; }

        .tech { display: grid; align-items: start; gap: 16px; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); }
        .stack { display: grid; gap: 16px; min-width: 0; }
        .panel { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); min-width: 0; }
        .panel.wide { grid-column: 1 / -1; }
        .panel-head { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--line); }
        .panel-head h2 { font-size: 14px; margin: 0 auto 0 0; display: flex; align-items: center; gap: 8px; }
        .panel-head .btn { height: 30px; font-size: 13px; padding: 0 10px; }
        .intro { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 16px; }
        .intro p { margin: 0 auto 0 0; color: var(--mute); }
        .kv { margin: 0; }
        .kv div { display: grid; grid-template-columns: 150px 1fr auto; gap: 12px; align-items: center; padding: 7px 16px; border-bottom: 1px solid var(--line); }
        .kv div:last-child { border-bottom: 0; }
        .kv dt { color: var(--mute); font-size: 13px; }
        .kv dd { margin: 0; overflow-wrap: anywhere; font-size: 14px; }
        .kv .icon-btn, .log .icon-btn { opacity: .55; }
        .kv div:hover .icon-btn, .log li:hover .icon-btn, .icon-btn:focus-visible, .icon-btn.done { opacity: 1; }
        .log { list-style: none; margin: 0; padding: 0; }
        .log li { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: start; padding: 10px 16px; border-bottom: 1px solid var(--line); }
        .log li:last-child { border-bottom: 0; }
        .log li.error { background: color-mix(in srgb, var(--err-bg) 45%, transparent); }
        .log li.warn { background: color-mix(in srgb, var(--warn-bg) 45%, transparent); }
        .level { font: 600 11px var(--mono); text-transform: uppercase; padding: 2px 7px; border-radius: 6px; background: var(--sunken); color: var(--mute); margin-top: 2px; }
        .level.error, .level.s5, .level.s4 { background: var(--err-bg); color: var(--err); }
        .level.warn { background: var(--warn-bg); color: var(--warn); }
        .level.s2 { background: var(--ok-bg); color: var(--ok); }
        .entry-text { font: 13px/1.5 var(--mono); white-space: pre-wrap; overflow-wrap: anywhere; min-width: 0; }
        .entry { min-width: 0; }
        .entry-text { display: block; }
        .entry-meta { display: block; margin-top: 2px; color: var(--mute); font-size: 12px; }
        details { margin-top: 6px; }
        summary { cursor: pointer; color: var(--mute); font: 12px system-ui, sans-serif; }
        details pre { margin: 6px 0 0; padding: 8px 10px; border-radius: 8px; background: var(--sunken); font: 12px/1.5 var(--mono); white-space: pre-wrap; overflow-wrap: anywhere; }
        .code { margin: 0; padding: 14px 16px; font: 13px/1.5 var(--mono); white-space: pre-wrap; overflow-wrap: anywhere; }
        .none { padding: 14px 16px; color: var(--mute); margin: 0; }
        footer { max-width: 1400px; margin: 0 auto; padding: 4px 20px 32px; color: var(--mute); font-size: 13px; }
        .sprite { position: absolute; width: 0; height: 0; }
        .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

        @media (max-width: 900px) {
            .stage { display: flex; flex-direction: column; gap: 16px; padding: 12px; }
            .card { position: static; width: 100%; margin: 0; order: -1; box-shadow: var(--shadow); }
            .shot { align-self: center; }
            .tech { grid-template-columns: 1fr; }
            .kv div { grid-template-columns: 1fr auto; }
            .kv dt { grid-column: 1 / -1; }
            html { scroll-padding-top: 180px; }
        }
        @media print { .top .actions, .tabs, .icon-btn { display: none !important; } [role=tabpanel][hidden] { display: block; } }
    </style>
</head>
<body>
<svg class="sprite" aria-hidden="true">
    <symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></symbol>
    <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14"/></symbol>
    <symbol id="i-open" viewBox="0 0 24 24"><path d="M14 4h6v6m0-6L10 14M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></symbol>
    <symbol id="i-hide" viewBox="0 0 24 24"><path d="M6 15l6-6 6 6"/></symbol>
    <symbol id="i-show" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
    <symbol id="i-link" viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></symbol>
    <symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/></symbol>
    <symbol id="i-undo" viewBox="0 0 24 24"><path d="M9 14L4 9l5-5M4 9h10a6 6 0 0 1 0 12h-4"/></symbol>
</svg>

<header class="top">
    <div class="bar">
        <div class="title">
            <div>
                <h1>{{ $project->name }}</h1>
                <span class="id">{{ $entry->id }}
                    <button class="icon-btn" type="button" data-copy="{{ $entry->id }}" aria-label="Copy feedback ID" title="Copy feedback ID"><svg><use href="#i-copy"/></svg></button>
                </span>
            </div>
            @if ($entry->completed_at === null)
                <span class="status open">open</span>
            @else
                <span class="status completed" title="{{ $entry->completed_at->toDateTimeString() }} UTC">completed</span>
            @endif
        </div>
        <div class="actions">
            @if ($shot !== null)
                <a class="btn" href="{{ $downloadUrl }}" download title="{{ $shot->width }} × {{ $shot->height }}, {{ number_format($shot->bytes / 1024, 0, '.', "'") }} KB"><svg><use href="#i-download"/></svg>Download</a>
                <a class="btn" href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer"><svg><use href="#i-open"/></svg>Full size</a>
            @endif
            @if ($statusUrl !== null)
                <form class="state" method="post" action="{{ $statusUrl }}">
                    @if ($entry->completed_at === null)
                        <button class="btn primary" type="submit" name="status" value="completed"><svg><use href="#i-check-circle"/></svg>Mark as completed</button>
                    @else
                        <button class="btn" type="submit" name="status" value="open"><svg><use href="#i-undo"/></svg>Reopen</button>
                    @endif
                </form>
            @endif
        </div>
    </div>
    <div class="tabs" role="tablist" aria-label="Feedback views">
        <button role="tab" id="tab-feedback" aria-controls="panel-feedback" aria-selected="true" type="button">Feedback</button>
        <button role="tab" id="tab-technical" aria-controls="panel-technical" aria-selected="false" tabindex="-1" type="button">
            Technical
        </button>
    </div>
</header>

<main>
    <section id="panel-feedback" role="tabpanel" aria-labelledby="tab-feedback">
        <div class="stage">
            @if ($shot !== null)
                <a class="shot" href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer" title="Open full size">
                    <img src="{{ $imageUrl }}" width="{{ $shot->width }}" height="{{ $shot->height }}" alt="Screenshot of {{ $entry->page_url }}">
                </a>
            @else
                <div class="empty">
                    <svg><use href="#i-image"/></svg>
                    <p>{{ $entry->screenshot_error === null ? 'No screenshot.' : 'No screenshot ('.$entry->screenshot_error.').' }}</p>
                </div>
            @endif

            <aside class="card" id="card" aria-label="Message from the sender">
                <div class="card-head">
                    <span class="avatar" aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
                    <div class="who">
                        <strong>{{ $sender }}</strong>
                        <span class="line">
                            <a href="mailto:{{ $entry->email }}">{{ $entry->email }}</a>
                            <button class="icon-btn" type="button" data-copy="{{ $entry->email }}" aria-label="Copy email" title="Copy email"><svg><use href="#i-copy"/></svg></button>
                        </span>
                    </div>
                    <button class="icon-btn" type="button" id="card-toggle" aria-expanded="true" aria-controls="card" aria-label="Hide message" title="Hide message"><svg><use href="#i-hide"/></svg></button>
                </div>
                <p class="message">{{ $entry->message }}</p>
                <div class="facts">
                    <div class="fact"><svg><use href="#i-clock"/></svg><span title="{{ $entry->created_at->toDateTimeString() }} UTC">{{ $entry->created_at->diffForHumans() }} · {{ $entry->created_at->toDateTimeString() }} UTC</span></div>
                    <div class="fact">
                        <svg><use href="#i-link"/></svg>
                        @if ($pageHref !== null)
                            <a href="{{ $pageHref }}" rel="noopener noreferrer" target="_blank" title="{{ $entry->page_url }}">{{ $pageLabel }}</a>
                        @else
                            <span>{{ $entry->page_url }}</span>
                        @endif
                        <button class="icon-btn" type="button" data-copy="{{ $entry->page_url }}" aria-label="Copy page URL" title="Copy page URL"><svg><use href="#i-copy"/></svg></button>
                    </div>
                    @if ($entry->submitted_from_url !== null && $entry->submitted_from_url !== $entry->page_url)
                        <div class="fact"><span>Sent from</span>
                            @if ($sentFromHref !== null)
                                <a href="{{ $sentFromHref }}" rel="noopener noreferrer" target="_blank">{{ $entry->submitted_from_url }}</a>
                            @else
                                <span>{{ $entry->submitted_from_url }}</span>
                            @endif
                        </div>
                    @endif
                    <div class="chips">
                        @if ($short !== '')<span class="chip">{{ $short }}</span>@endif
                        @if ($entry->viewport_w !== null)<span class="chip">{{ $entry->viewport_w }} × {{ $entry->viewport_h }}</span>@endif
                        @if ($marks > 0)<span class="chip">{{ $marks }} {{ $marks === 1 ? 'mark' : 'marks' }}</span>@endif
                        @if ($consoleErrors > 0)<span class="chip">{{ $consoleErrors }} console {{ $consoleErrors === 1 ? 'error' : 'errors' }}</span>@endif
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <section id="panel-technical" role="tabpanel" aria-labelledby="tab-technical">
        <div class="intro">
            <p>Everything the browser sent along. Copy single values or the whole report for a ticket.</p>
            <button class="btn primary" type="button" data-copy="{{ $report }}"><svg><use href="#i-copy"/></svg>Copy report</button>
        </div>
        <div class="tech">
            <div class="stack">
            <section class="panel" aria-labelledby="h-console">
                <div class="panel-head">
                    <h2 id="h-console">Console <span class="count">{{ count($console) }}</span></h2>
                    @if ($console !== [])
                        <button class="btn" type="button" data-copy="{{ collect($console)->map(fn ($row) => \App\Http\Controllers\FeedbackPageController::consoleLine($row))->implode("\n") }}"><svg><use href="#i-copy"/></svg>Copy all</button>
                    @endif
                </div>
                @if ($console === [])
                    <p class="none">No console entries.</p>
                @else
                    <ul class="log">
                        @foreach ($console as $row)
                            <li class="{{ in_array($row['type'], ['error', 'warn'], true) ? $row['type'] : '' }}">
                                <span class="level {{ $row['type'] }}">{{ $row['type'] !== '' ? $row['type'] : 'log' }}</span>
                                <div class="entry"><span class="entry-text">{{ $row['message'] }}</span>
                                    @if ($row['source'] !== '')
                                        <span class="entry-meta">{{ $row['source'] }}{{ $row['line'] !== '' ? ':'.$row['line'] : '' }}{{ $row['column'] !== '' ? ':'.$row['column'] : '' }}</span>
                                    @endif
                                    @if ($row['stack'] !== '')
                                        <details><summary>Stack trace</summary><pre>{{ $row['stack'] }}</pre></details>
                                    @endif
                                </div>
                                <button class="icon-btn" type="button" data-copy="{{ \App\Http\Controllers\FeedbackPageController::consoleLine($row) }}" aria-label="Copy console entry" title="Copy"><svg><use href="#i-copy"/></svg></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="panel" aria-labelledby="h-network">
                <div class="panel-head">
                    <h2 id="h-network">Failed requests <span class="count">{{ count($network) }}</span></h2>
                    @if ($network !== [])
                        <button class="btn" type="button" data-copy="{{ collect($network)->map(fn ($row) => \App\Http\Controllers\FeedbackPageController::networkLine($row))->implode("\n") }}"><svg><use href="#i-copy"/></svg>Copy all</button>
                    @endif
                </div>
                @if ($network === [])
                    <p class="none">No failed requests.</p>
                @else
                    <ul class="log">
                        @foreach ($network as $row)
                            <li>
                                <span class="level s{{ substr($row['status'], 0, 1) }}">{{ $row['status'] !== '' && $row['status'] !== '0' ? $row['status'] : 'ERR' }}</span>
                                <div class="entry"><span class="entry-text">{{ $row['method'] }} {{ $row['url'] }}</span>
                                    @if ($row['duration'] !== '')
                                        <span class="entry-meta">{{ $row['duration'] }} ms</span>
                                    @endif
                                </div>
                                <button class="icon-btn" type="button" data-copy="{{ \App\Http\Controllers\FeedbackPageController::networkLine($row) }}" aria-label="Copy request" title="Copy"><svg><use href="#i-copy"/></svg></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($customData !== null)
                <section class="panel" aria-labelledby="h-custom">
                    <div class="panel-head">
                        <h2 id="h-custom">Custom data</h2>
                        <button class="btn" type="button" data-copy="{{ $customData }}"><svg><use href="#i-copy"/></svg>Copy</button>
                    </div>
                    <pre class="code">{{ $customData }}</pre>
                </section>
            @endif
            </div>
            <section class="panel" aria-labelledby="h-env">
                <div class="panel-head"><h2 id="h-env">Environment</h2></div>
                <dl class="kv">
                    @foreach ($environment as $label => $value)
                        <div>
                            <dt>{{ $label }}</dt>
                            <dd @class(['entry-text' => in_array($label, ['User agent', 'IP address'], true)])>{{ $value }}</dd>
                            <button class="icon-btn" type="button" data-copy="{{ $value }}" aria-label="Copy {{ $label }}" title="Copy"><svg><use href="#i-copy"/></svg></button>
                        </div>
                    @endforeach
                </dl>
            </section>

        </div>
    </section>
</main>

<footer>This link expires {{ $expires->toDateTimeString() }} UTC. Anyone with the link can open this page until then.</footer>
<div class="sr" role="status" aria-live="polite" id="live"></div>

<script nonce="{{ $nonce }}">
    (() => {
        const tabs = [...document.querySelectorAll('[role=tab]')];
        const select = (tab, focus) => {
            for (const t of tabs) {
                const on = t === tab;
                t.setAttribute('aria-selected', String(on));
                t.tabIndex = on ? 0 : -1;
                document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
            }
            if (focus) tab.focus();
            history.replaceState(null, '', tab.id === 'tab-technical' ? '#technical' : location.pathname + location.search);
        };
        tabs.forEach((tab, i) => {
            tab.addEventListener('click', () => select(tab, false));
            tab.addEventListener('keydown', (e) => {
                const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
                if (step) { e.preventDefault(); select(tabs[(i + step + tabs.length) % tabs.length], true); }
            });
        });
        select(location.hash === '#technical' ? tabs[1] : tabs[0], false);

        const card = document.getElementById('card');
        const toggle = document.getElementById('card-toggle');
        toggle.addEventListener('click', () => {
            const collapsed = card.classList.toggle('collapsed');
            toggle.setAttribute('aria-expanded', String(!collapsed));
            toggle.setAttribute('aria-label', collapsed ? 'Show message' : 'Hide message');
            toggle.title = toggle.getAttribute('aria-label');
            toggle.querySelector('use').setAttribute('href', collapsed ? '#i-show' : '#i-hide');
        });

        const live = document.getElementById('live');
        const write = async (text) => {
            try { await navigator.clipboard.writeText(text); return true; } catch {
                const area = Object.assign(document.createElement('textarea'), { value: text });
                area.style.position = 'fixed'; area.style.opacity = '0';
                document.body.append(area); area.select();
                const ok = document.execCommand('copy'); area.remove(); return ok;
            }
        };
        document.addEventListener('click', async (e) => {
            const button = e.target.closest('[data-copy]');
            if (!button || !(await write(button.dataset.copy))) return;
            const use = button.querySelector('use');
            button.classList.add('done');
            use?.setAttribute('href', '#i-check');
            live.textContent = 'Copied to clipboard';
            clearTimeout(button.copyTimer);
            button.copyTimer = setTimeout(() => { button.classList.remove('done'); use?.setAttribute('href', '#i-copy'); live.textContent = ''; }, 1600);
        });
    })();
</script>
@if ($analyticsScript)
<script src="{{ $analyticsScript }}" nonce="{{ $nonce }}" crossorigin="anonymous" defer></script>
@endif
</body>
</html>
