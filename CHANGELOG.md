# Changelog

All notable changes to the API and the widget are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Feedback page redesign: screenshot first with the sender's message as a sticky, hideable card; technical details on a second tab with copy buttons for every value and log entry and "Copy report" as plain text; console errors highlighted. Repeated console entries and failed requests are listed one by one (at most 500 per log), each with its own copy button and in "Copy all", instead of a single row with ×N; no count badge on the tab.
- Feedback pages behind signed, expiring links with all details, console and network log and the screenshot (inline and download).
- `feedback:link {id}` creates team links that can complete and reopen the entry (`--by` is logged, `--read-only`, `--expires`).
- The API returns a read-only share link after sending (`FEEDBACK_SHARE_LINK_MINUTES`, `0` turns it off); the widget shows it on the thank you screen with a copy button, `after_send` receives it as `url`.
- Launcher position per project: `feedback:project:create|update --position=bottom-left` (or `bottom-right`), returned by `/v1/projects/{key}/config` as `position` and applied by the widget without a change on the website. `position` in `init()` still wins.
- Close button in marking mode (top right, bottom right on phones), same as Esc.

### Changed

- The widget is no longer published to npm: the API serves it at `/widget/v1/feedback.js` (one script tag, no npm, no CDN, updated with every deploy). `npm run build` compiles it into `resources/widget/v1`. The npm package `@codebar-ag/feedback-widget` 1.0.0 stays available but gets no updates. Removed: release workflow, type definitions, SRI file, publint and attw.
- The widget only appears on pages the API accepts: it checks the project config first and stays hidden on a `403` (origin or IP not allowed). Refused `GET` requests now carry `Access-Control-Allow-Origin` so the widget can read the `403`; the preflight for sending stays refused.
- Dev dependencies: vite 8.3.5, typescript-eslint 8.72.0.
- Pest browser tests (`tests/Browser`, Playwright Chromium) for the feedback page: tabs, message card, copy, completing and reopening, refused links. New dev dependencies: pestphp/pest-plugin-browser 5.1, playwright 1.64.1. `composer test` leaves them out, `composer test:browser` runs them.

### Fixed

- Feedback links answered 403 in browsers behind a TLS terminating proxy (Laravel Cloud): the navigation guard checked the signature against the http:// URL before the trusted proxies were applied. The route's signature check stays authoritative.
- `<noscript>` content (e.g. of an Alpine toast region) showed up as literal markup in screenshots; `<noscript>` and `<template>` are now left out.
- Screenshots with the script tag from the API: the lazy screenshot chunk was served without `Access-Control-Allow-Origin`, so other websites could not load it.
- Screenshots of pages using Alpine, Vue or other framework attributes (`:class`, `x-on:click`) were blank.

## [1.0.0] - 2026-10-16

Initial release.

- Laravel 13 API: projects with origin and IP allowlists, rate limits, honeypot, idempotency, screenshots stored in the database, Artisan commands, Oh Dear health checks.
- Widget `@codebar-ag/feedback-widget`: full-page screenshots with rectangle and highlighter, persistent drafts, diagnostics with redaction, identity, four languages, project colors, WCAG 2.2 AA.

[Unreleased]: https://github.com/codebar-ag/feedback.codebar.ch/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/codebar-ag/feedback.codebar.ch/releases/tag/v1.0.0
