import { resolveLocale } from './i18n/index.js';
import type { FeedbackOptions, Locale, Position, ScreenshotMode } from './types.js';

export interface ResolvedOptions {
  projectKey: string;
  endpoint: string;
  locale: Locale;
  /** Null when init() gave none: the project setting decides, then bottom-right. */
  position: Position | null;
  launcherStyle: 'icon' | 'text';
  launcherLabel: string | null;
  screenshotMode: ScreenshotMode;
  quality: number;
  maxPixels: number;
  console: boolean;
  network: boolean;
  maxEntries: number;
  levels: NonNullable<NonNullable<FeedbackOptions['diagnostics']>['levels']>;
  redact: RegExp[];
  maskSelectors: string[];
  ignoreSelectors: string[];
  persist: boolean;
  ttlHours: number;
  zIndex: number;
}

const isSafariOrMobile = (): boolean =>
  /iPhone|iPad|Android|Mobile/i.test(navigator.userAgent) ||
  (/Safari\//.test(navigator.userAgent) && !/Chrome|Chromium|Edg|Firefox/.test(navigator.userAgent));

/** Validates the init options and applies the defaults. */
export function resolveOptions(o: FeedbackOptions): ResolvedOptions {
  if (!o || typeof o.projectKey !== 'string' || !o.projectKey.trim()) throw new Error('projectKey is required');
  if (typeof o.endpoint !== 'string' || !/^https?:\/\//.test(o.endpoint)) throw new Error('endpoint must be an http(s) URL');
  const shot = o.screenshot ?? {};
  const diag = o.diagnostics ?? {};
  return {
    projectKey: o.projectKey.trim(),
    endpoint: o.endpoint.replace(/\/+$/, ''),
    locale: resolveLocale(o.locale ?? document.documentElement.lang ?? 'de_CH'),
    position: parsePosition(o.position),
    launcherStyle: o.launcher?.style === 'text' ? 'text' : 'icon',
    launcherLabel: o.launcher?.label ?? null,
    screenshotMode: shot.mode === 'required' || shot.mode === 'off' ? shot.mode : 'optional',
    quality: typeof shot.quality === 'number' ? Math.min(1, Math.max(0.1, shot.quality)) : 0.82,
    maxPixels: shot.maxPixels ?? (isSafariOrMobile() ? 16_000_000 : 25_000_000),
    console: diag.console !== false,
    network: diag.network !== false,
    maxEntries: Math.max(1, Math.min(50, diag.maxEntries ?? 50)),
    levels: diag.levels ?? ['error', 'warn'],
    redact: diag.redact ?? [],
    maskSelectors: ['[data-feedback-mask]', ...(o.mask?.selectors ?? [])],
    ignoreSelectors: ['[data-feedback-ignore]', ...(o.mask?.ignore ?? [])],
    persist: o.draft?.persist !== false,
    ttlHours: o.draft?.ttlHours ?? 24,
    zIndex: o.zIndex ?? 2147483000,
  };
}

/** A known launcher corner, or null for anything else. */
export function parsePosition(value: unknown): Position | null {
  return value === 'bottom-left' || value === 'bottom-right' ? value : null;
}

const CONFIG_TTL = 10 * 60 * 1000;

export interface ProjectConfig {
  /** False when the API refuses this page (403: origin or IP not allowed): the widget stays hidden. */
  allowed: boolean;
  primary: string | null;
  position: Position | null;
}

/**
 * GET /v1/projects/{key}/config with 5 s timeout, cached for 10 minutes in
 * sessionStorage. Returns the project color and launcher position, or nulls
 * (widget stays black, bottom-right) when the API is not reachable. A 403
 * marks the page as not allowed.
 */
export async function loadProjectConfig(endpoint: string, projectKey: string, fetchFn: typeof fetch): Promise<ProjectConfig> {
  const cacheKey = `cbf:${projectKey}:config`;
  try {
    const cached = JSON.parse(sessionStorage.getItem(cacheKey) ?? 'null') as {
      t: number;
      primary: string | null;
      position?: unknown;
    } | null;
    if (cached && Date.now() - cached.t < CONFIG_TTL)
      return { allowed: true, primary: cached.primary, position: parsePosition(cached.position) };
  } catch {
    // no cache
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 5_000);
  try {
    const res = await fetchFn(`${endpoint}/v1/projects/${encodeURIComponent(projectKey)}/config`, {
      credentials: 'omit',
      mode: 'cors',
      signal: controller.signal,
    });
    if (res.status === 403) return { allowed: false, primary: null, position: null };
    if (!res.ok) return { allowed: true, primary: null, position: null };
    const data = (await res.json()) as { theme?: { primary?: string }; position?: unknown };
    const primary = typeof data.theme?.primary === 'string' ? data.theme.primary : null;
    const position = parsePosition(data.position);
    try {
      sessionStorage.setItem(cacheKey, JSON.stringify({ t: Date.now(), primary, position }));
    } catch {
      // ignore
    }
    return { allowed: true, primary, position };
  } catch {
    return { allowed: true, primary: null, position: null };
  } finally {
    clearTimeout(timer);
  }
}
