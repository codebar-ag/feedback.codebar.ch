import type { Messages } from './i18n/index.js';

export type Locale = 'de_CH' | 'fr_CH' | 'en_CH' | 'it_CH';

export interface Identity {
  id?: string | number;
  name?: string;
  email: string;
}

export type ConsoleLevel = 'error' | 'warn' | 'info' | 'log' | 'debug';

export type ScreenshotMode = 'optional' | 'required' | 'off';

export type Position = 'bottom-right' | 'bottom-left';

export interface FeedbackOptions {
  /** Public project key (fbk_live_…), public by design. */
  projectKey: string;
  /** API base URL, e.g. https://feedback.example.ch */
  endpoint: string;
  locale?: Locale | string;
  identity?: Identity | null;
  /** Overrides the project color loaded from the API. */
  colors?: { primary?: string };
  /** Launcher corner. Overrides the position set on the project. */
  position?: Position;
  launcher?: { style?: 'icon' | 'text'; label?: string | null };
  screenshot?: { mode?: ScreenshotMode; format?: 'jpeg'; quality?: number; maxPixels?: number };
  diagnostics?: {
    console?: boolean;
    network?: boolean;
    maxEntries?: number;
    levels?: ConsoleLevel[];
    redact?: RegExp[];
  };
  mask?: { selectors?: string[]; ignore?: string[] };
  /** Flat object, max. 8 KB. */
  customData?: Record<string, string | number | boolean | null>;
  messages?: Partial<Record<Locale, Partial<Messages>>>;
  draft?: { persist?: boolean; ttlHours?: number };
  /** CSP nonce for browsers without adoptedStyleSheets. */
  nonce?: string;
  zIndex?: number;
}

export interface RectMark {
  type: 'rect';
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface MarkerMark {
  type: 'marker';
  points: [number, number][];
}

export type Mark = RectMark | MarkerMark;

/** Metadata of a screenshot (stored in the draft, image itself in IndexedDB). */
export interface ShotMeta {
  /** Document size in CSS pixels. */
  w: number;
  h: number;
  /** Image pixels per CSS pixel. */
  scale: number;
  url: string;
  title: string;
  annotations: Mark[];
}

/** Payload passed to before_send. JSON fields are plain objects here. */
export interface FeedbackPayload {
  message: string;
  email: string;
  name?: string;
  locale: Locale;
  page_url: string;
  page_title?: string;
  referrer?: string;
  client: Record<string, unknown>;
  console_log?: unknown[];
  network_log?: unknown[];
  annotations?: Mark[];
  custom_data?: Record<string, unknown>;
  identity?: Identity;
  submitted_from_url?: string;
  screenshot_error?: string;
  widget_version: string;
  elapsed_ms: number;
  website: string;
}

export interface FeedbackError {
  code: string;
  status: number;
  retryAfter?: number;
}

export interface FeedbackEvents {
  ready: () => void;
  open: () => void;
  close: () => void;
  before_send: (payload: FeedbackPayload) => boolean | undefined;
  after_send: (result: { id: string; url?: string }) => void;
  error: (error: FeedbackError) => void;
}

export type FeedbackEventName = keyof FeedbackEvents;

export interface FeedbackInstance {
  open(): void;
  close(): void;
  showLauncher(): void;
  hideLauncher(): void;
  identify(identity: Identity | null): void;
  setLocale(locale: Locale | string): void;
  setData(data: Record<string, string | number | boolean | null>): void;
  isOpen(): boolean;
  destroy(options?: { clearDraft?: boolean }): void;
  on<E extends FeedbackEventName>(name: E, fn: FeedbackEvents[E]): () => void;
  off<E extends FeedbackEventName>(name: E, fn: FeedbackEvents[E]): void;
}
