import { LiveRegion, trapTab } from './a11y/focus.js';
import { AnnotationLayer } from './annotate/layer.js';
import { History } from './annotate/history.js';
import type { Tool } from './annotate/tools.js';
import { sendFeedback } from './api/client.js';
import { composeShot } from './capture/compose.js';
import { loadCapture, preloadCapture } from './capture/load.js';
import { resolveOptions, loadProjectConfig, type ResolvedOptions } from './config.js';
import { RingBuffer } from './diagnostics/buffer.js';
import { captureConsole } from './diagnostics/console.js';
import { collectClient, prefetchHints } from './diagnostics/meta.js';
import { captureNetwork, nativeFetch, networkBuffer, type NetworkEntry } from './diagnostics/network.js';
import { DraftStore, uuid } from './draft/storage.js';
import { Emitter } from './events.js';
import { bcp47, resolveLocale, translator, type Translator } from './i18n/index.js';
import { Store, type Action, type State } from './store.js';
import { deriveTheme, parseColor, themeVars } from './theme/derive.js';
import type {
  FeedbackEventName,
  FeedbackEvents,
  FeedbackInstance,
  FeedbackOptions,
  FeedbackPayload,
  Identity,
  Locale,
  Position,
  ShotMeta,
} from './types.js';
import { raf, h } from './ui/dom.js';
import { createForm, type FormErrors, type FormView } from './ui/form.js';
import { createHeader } from './ui/header.js';
import { createHost, type Host } from './ui/host.js';
import { createLauncher, type Launcher } from './ui/launcher.js';
import { createPanel } from './ui/panel.js';
import { createStatusPage, type StatusPage } from './ui/status-page.js';
import { createToolbar, type Toolbar } from './ui/toolbar.js';
import { createToolsRow, type ToolsRow } from './ui/tools-row.js';
import { VERSION } from './version.js';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const SUCCESS_CLOSE_MS = 3000;

interface Shot {
  blob: Blob;
  url: string;
  meta: ShotMeta;
  persisted: boolean;
}

/** Validates the form. */
export function validate(
  values: { email: string; message: string; hasShot: boolean },
  rules: { needsEmail: boolean; shotRequired: boolean },
  t: Translator,
): FormErrors {
  const errors: FormErrors = {};
  const email = values.email.trim();
  const message = values.message.trim();
  if (rules.needsEmail && (!EMAIL.test(email) || email.length > 254)) errors.email = t('error_email');
  if (message.length < 3 || message.length > 5000) errors.message = t('error_message');
  if (rules.shotRequired && !values.hasShot) errors.shot = t('error_shot');
  return errors;
}

export class Widget implements FeedbackInstance {
  private readonly o: ResolvedOptions;
  private readonly store = new Store();
  private readonly events = new Emitter();
  private readonly draft: DraftStore;
  private readonly logs = new RingBuffer();
  private readonly network: RingBuffer<NetworkEntry>;
  private readonly live = new LiveRegion();
  private readonly offs: (() => void)[] = [];
  private readonly hostUi: Host;
  private readonly launcher: Launcher;
  private readonly panel: HTMLElement;
  private readonly header: ReturnType<typeof createHeader>;
  private readonly form: FormView;
  private readonly tools: ToolsRow;
  private readonly success: StatusPage;
  private readonly failure: StatusPage;
  private readonly toolbar: Toolbar;
  private readonly capturing: HTMLElement;

  private t: Translator;
  private locale: Locale;
  private identity: Identity | null;
  private customData: Record<string, unknown>;
  private primary: string | null;
  private position: Position | null;
  private shot: Shot | null = null;
  private shotError: string | null = null;
  private errors: FormErrors = {};
  private validated = false;
  private idempotencyKey = uuid();
  private openedAt = Date.now();
  private formHeight = 0;
  private launcherVisible = true;
  private checked = false;
  private history: History | null = null;
  private layer: AnnotationLayer | null = null;
  private tool: Tool = 'rect';
  private freshShot = false;
  private marksBefore: ShotMeta['annotations'] = [];
  private quiet = false;
  private saveTimer: ReturnType<typeof setTimeout> | undefined;
  private closeTimer: ReturnType<typeof setTimeout> | undefined;
  /** Signed link to the last sent feedback, shown on the success page. */
  private shareUrl: string | null = null;
  private destroyed = false;

  constructor(
    private readonly options: FeedbackOptions,
    private readonly onDestroy: () => void = () => {},
  ) {
    this.o = resolveOptions(options);
    this.locale = this.o.locale;
    this.t = translator(this.locale, options.messages);
    this.identity = options.identity ?? null;
    this.customData = { ...(options.customData ?? {}) };
    this.primary = parseColor(options.colors?.primary);
    this.position = this.o.position;
    this.draft = new DraftStore(this.o.projectKey, this.o.persist, this.o.ttlHours * 3_600_000);
    this.network = networkBuffer(this.o.maxEntries);

    if (this.o.console) this.offs.push(captureConsole(this.logs, { levels: this.o.levels, redact: this.o.redact }));
    if (this.o.network) this.offs.push(captureNetwork(this.network));

    this.hostUi = createHost(this.o.zIndex, options.nonce);
    this.launcher = createLauncher(
      () => (this.store.state === 'closed' ? this.open() : this.close()),
      () => {
        if (this.o.screenshotMode !== 'off') preloadCapture();
      },
    );
    this.header = createHeader(() => this.go('CLOSE'));
    this.tools = createToolsRow(
      () => void this.capture(),
      () => this.edit(),
      () => this.removeShot(true),
    );
    this.form = createForm(
      this.tools.el,
      () => void this.submit(),
      (field, blur) => this.onInput(field, blur),
    );
    this.success = createStatusPage('success', () => this.go('CLOSE'));
    this.failure = createStatusPage('error', () => this.go('BACK'));
    this.toolbar = createToolbar({
      tool: (tool) => {
        this.tool = tool;
        if (this.layer) this.layer.tool = tool;
        this.render();
      },
      undo: () => this.undo(),
      clear: () => {
        this.history?.clear();
        this.marksChanged();
      },
      done: () => this.finishAnnotating(),
      cancel: () => this.cancelAnnotating(),
    });
    this.capturing = h('div', { class: 'cap', hidden: true }, h('span', { class: 'sp', 'aria-hidden': 'true' }));
    this.panel = createPanel(this.header.el, h('div', { class: 'bd' }, this.form.el, this.success.el, this.failure.el));
    this.hostUi.root.append(this.capturing, this.panel, this.launcher.el, this.live.el);

    this.store.subscribe((next, prev) => this.onState(next, prev));
    this.listen();
    this.render();
  }

  /** Checks the page against the API, restores the draft, applies the project color and emits "ready". */
  async start(): Promise<this> {
    void prefetchHints();
    const configLoad = loadProjectConfig(this.o.endpoint, this.o.projectKey, nativeFetch);
    const loaded = await this.draft.load();
    const config = await configLoad;
    if (this.destroyed) return this;
    if (!config.allowed) {
      // The API would refuse every feedback from this page, so the widget never shows.
      console.warn('[codebar feedback] this page is not allowed for the project (origin or IP), the widget stays hidden');
      this.destroy();
      return this;
    }
    this.primary ??= parseColor(config.primary);
    this.position ??= config.position;
    this.checked = true;
    if (loaded) {
      const d = loaded.draft;
      this.form.email.value = d.email ?? '';
      this.form.message.value = d.message ?? '';
      if (d.idempotencyKey) this.idempotencyKey = d.idempotencyKey;
      if (d.shot && loaded.blob) this.setShot(loaded.blob, d.shot, true);
      if (d.open) {
        this.quiet = true;
        this.go('OPEN');
        this.quiet = false;
      }
    }
    this.render();
    this.events.emit('ready');
    return this;
  }

  // Public API ---------------------------------------------------------------

  open(): void {
    this.safe(() => this.go('OPEN'));
  }

  close(): void {
    this.safe(() => this.go('CLOSE'));
  }

  showLauncher(): void {
    this.safe(() => {
      this.launcherVisible = true;
      this.render();
    });
  }

  hideLauncher(): void {
    this.safe(() => {
      this.launcherVisible = false;
      this.render();
    });
  }

  identify(identity: Identity | null): void {
    this.safe(() => {
      this.identity = identity && typeof identity.email === 'string' ? identity : null;
      if (this.validated) this.revalidate();
      this.render();
    });
  }

  setLocale(locale: Locale | string): void {
    this.safe(() => {
      this.locale = resolveLocale(locale);
      this.t = translator(this.locale, this.options.messages);
      if (this.validated) this.revalidate();
      this.render();
    });
  }

  setData(data: Record<string, string | number | boolean | null>): void {
    this.safe(() => {
      this.customData = { ...this.customData, ...data };
    });
  }

  isOpen(): boolean {
    return this.store.state !== 'closed';
  }

  on<E extends FeedbackEventName>(name: E, fn: FeedbackEvents[E]): () => void {
    return this.events.on(name, fn);
  }

  off<E extends FeedbackEventName>(name: E, fn: FeedbackEvents[E]): void {
    this.events.off(name, fn);
  }

  destroy(options: { clearDraft?: boolean } = {}): void {
    if (this.destroyed) return;
    this.destroyed = true;
    clearTimeout(this.saveTimer);
    clearTimeout(this.closeTimer);
    try {
      if (options.clearDraft) void this.draft.clear();
      else if (this.store.state !== 'success') this.saveDraft();
      this.layer?.destroy();
      for (const off of this.offs.splice(0).reverse()) off();
      if (this.shot) URL.revokeObjectURL(this.shot.url);
      this.hostUi.host.remove();
      this.draft.close();
    } finally {
      this.events.clear();
      this.onDestroy();
    }
  }

  // State --------------------------------------------------------------------

  private go(action: Action): void {
    if (this.destroyed) return;
    this.measureForm();
    this.store.dispatch(action);
  }

  private onState(next: State, prev: State): void {
    const t = this.t;
    if (next === 'form' && prev === 'closed') {
      this.openedAt = Date.now();
      this.events.emit('open');
    }
    if (next === 'annotating') this.mountLayer();
    if (prev === 'annotating') this.unmountLayer();
    if (prev === 'success') clearTimeout(this.closeTimer);
    this.render();
    if (next !== 'success') this.saveDraft();

    if (next === 'closed') {
      this.events.emit('close');
      // Focus returns to the launcher unless the user is already working in the host page.
      const active = document.activeElement;
      if (!active || active === document.body || active === this.hostUi.host) this.launcher.el.focus({ preventScroll: true });
      return;
    }
    if (this.quiet) return;
    if (next === 'form') {
      if (prev === 'capturing') this.tools.camera.focus();
      else if (prev === 'annotating') (this.shot ? this.tools.edit : this.tools.camera).focus();
      else if (prev === 'sending') this.focusFirstError();
      else this.form.message.focus({ preventScroll: true });
    } else if (next === 'capturing') {
      this.live.announce(t('shot_busy'));
    } else if (next === 'annotating') {
      this.toolbar.done.focus({ preventScroll: true });
    } else if (next === 'sending') {
      this.live.announce(t('sending'));
    } else if (next === 'success') {
      this.success.button.focus({ preventScroll: true });
      // Stays open while a link is shown, so that it can be copied.
      if (this.shareUrl === null) this.closeTimer = setTimeout(() => this.go('CLOSE'), SUCCESS_CLOSE_MS);
    } else if (next === 'error') {
      this.failure.button.focus({ preventScroll: true });
    }
  }

  private measureForm(): void {
    if (!this.form.el.hidden && !this.panel.hidden && this.form.el.offsetHeight > 0) this.formHeight = this.form.el.offsetHeight;
  }

  private render(): void {
    if (this.destroyed) return;
    const state = this.store.state;
    const t = this.t;
    const root = this.hostUi.root;
    const open = state !== 'closed';
    const away = state === 'capturing' || state === 'annotating';

    // Hidden until the API has accepted this page.
    this.hostUi.host.style.display = this.checked ? '' : 'none';
    root.lang = bcp47(this.locale);
    root.classList.toggle('open', open);
    root.classList.toggle('away', away);
    root.classList.toggle('left', this.position === 'bottom-left');
    root.dataset.state = state;
    const theme = deriveTheme(this.primary ?? '#000000');
    for (const [k, v] of Object.entries(themeVars(theme))) root.style.setProperty(k, v);

    const label = this.o.launcherLabel ?? t('launcher');
    this.launcher.update(open, open ? t('close') : label, this.o.launcherStyle === 'text');
    this.launcher.el.hidden = !this.launcherVisible && !open;

    this.panel.hidden = !open;
    this.panel.setAttribute('aria-label', label);
    this.header.update(t('close'));

    const statusView = state === 'success' || state === 'error';
    this.form.el.hidden = statusView;
    this.form.update(t, this.identity, this.errors, state === 'sending');
    this.tools.el.hidden = this.o.screenshotMode === 'off';
    this.tools.update(t, {
      busy: state === 'capturing',
      thumb: this.shot?.url ?? null,
      failed: this.shotError !== null,
      error: this.errors.shot ?? (this.shotError ? t('shot_error') : ''),
    });
    this.success.el.hidden = state !== 'success';
    this.failure.el.hidden = state !== 'error';
    this.success.update(t, this.formHeight);
    this.failure.update(t, this.formHeight);

    this.capturing.hidden = state !== 'capturing';
    this.capturing.setAttribute('aria-label', t('shot_busy'));
    this.toolbar.update(t, this.tool, this.history?.canUndo ?? false, (this.history?.marks.length ?? 0) > 0);
  }

  // Input and validation -----------------------------------------------------

  private onInput(field: 'email' | 'message', blur: boolean): void {
    if (this.validated) {
      const errors = this.currentErrors();
      if (errors[field]) {
        if (blur) this.errors = { ...this.errors, [field]: errors[field] };
      } else {
        this.errors = { ...this.errors, [field]: undefined };
      }
      this.render();
    }
    if (blur) this.saveDraft();
    else this.scheduleSave();
  }

  private currentErrors(): FormErrors {
    return validate(
      { email: this.form.email.value, message: this.form.message.value, hasShot: this.shot !== null },
      { needsEmail: !this.identity, shotRequired: this.o.screenshotMode === 'required' },
      this.t,
    );
  }

  private revalidate(): void {
    this.errors = this.currentErrors();
  }

  private focusFirstError(): void {
    if (this.errors.email && !this.identity) this.form.email.focus();
    else if (this.errors.message) this.form.message.focus();
    else if (this.errors.shot) this.tools.camera.focus();
    else this.form.message.focus({ preventScroll: true });
  }

  // Draft --------------------------------------------------------------------

  private scheduleSave(): void {
    clearTimeout(this.saveTimer);
    this.saveTimer = setTimeout(() => this.saveDraft(), 300);
  }

  private saveDraft(): void {
    clearTimeout(this.saveTimer);
    if (this.destroyed && this.store.state === 'success') return;
    const state = this.store.state;
    const open = state !== 'closed' && state !== 'success';
    const email = this.form.email.value;
    const message = this.form.message.value;
    if (!open && !email && !message && !this.shot) {
      void this.draft.clear();
      return;
    }
    this.draft.save({
      open,
      email,
      message,
      shot: this.shot?.persisted ? this.shot.meta : null,
      idempotencyKey: this.idempotencyKey,
    });
  }

  // Screenshot and annotation ------------------------------------------------

  private setShot(blob: Blob, meta: ShotMeta, persisted: boolean): void {
    if (this.shot) URL.revokeObjectURL(this.shot.url);
    this.shot = { blob, meta, url: URL.createObjectURL(blob), persisted };
    this.shotError = null;
    delete this.errors.shot;
  }

  private removeShot(focus: boolean): void {
    if (this.shot) URL.revokeObjectURL(this.shot.url);
    this.shot = null;
    this.history = null;
    void this.draft.clearShot();
    if (this.validated) this.revalidate();
    this.saveDraft();
    this.render();
    if (focus) this.tools.camera.focus();
  }

  private async capture(): Promise<void> {
    if (this.store.state !== 'form') return;
    this.shotError = null;
    this.go('CAPTURE');
    // Two frames so that panel and launcher are hidden before rendering.
    await raf();
    await raf();
    try {
      const mod = await loadCapture();
      const result = await mod.capturePage({
        exclude: [this.hostUi.host],
        ignore: this.o.ignoreSelectors,
        mask: this.o.maskSelectors,
        maxPixels: this.o.maxPixels,
        quality: this.o.quality,
      });
      if (this.destroyed) return;
      const meta: ShotMeta = {
        w: result.w,
        h: result.h,
        scale: result.scale,
        url: location.href,
        title: document.title,
        annotations: [],
      };
      this.setShot(result.blob, meta, false);
      const shot = this.shot as Shot | null;
      void this.draft.saveShot(result.blob).then((ok) => {
        if (ok && shot && this.shot === shot) {
          shot.persisted = true;
          this.saveDraft();
        }
      });
      this.freshShot = true;
      this.marksBefore = [];
      this.history = new History();
      this.go('CAPTURED');
    } catch (error) {
      if (this.destroyed) return;
      const code = (error as { code?: unknown })?.code;
      this.shotError = typeof code === 'string' ? code : 'render_failed';
      this.go('CAPTURE_FAILED');
    }
  }

  private edit(): void {
    if (!this.shot || this.store.state !== 'form') return;
    this.freshShot = false;
    this.marksBefore = [...this.shot.meta.annotations];
    this.history = new History(this.shot.meta.annotations);
    this.go('EDIT');
  }

  private mountLayer(): void {
    if (!this.shot || !this.history) return;
    this.layer = new AnnotationLayer(this.shot.meta, this.shot.url, this.history, () => this.marksChanged(), this.t('tools_label'));
    this.layer.tool = this.tool;
    this.hostUi.root.prepend(this.layer.el);
    this.hostUi.root.append(this.toolbar.el);
  }

  private unmountLayer(): void {
    this.layer?.destroy();
    this.layer = null;
    this.toolbar.el.remove();
  }

  private marksChanged(): void {
    if (this.shot && this.history) this.shot.meta.annotations = this.history.marks;
    this.layer?.redraw();
    this.render();
    this.saveDraft();
  }

  private undo(): void {
    this.history?.undo();
    this.marksChanged();
  }

  private finishAnnotating(): void {
    if (this.store.state !== 'annotating') return;
    if (this.shot && this.history) this.shot.meta.annotations = this.history.marks;
    this.go('DONE');
  }

  /** Esc: keeps an existing screenshot without the new marks, drops a fresh one. */
  private cancelAnnotating(): void {
    if (this.store.state !== 'annotating') return;
    if (this.freshShot) {
      this.go('CANCEL');
      this.removeShot(false);
      this.tools.camera.focus();
      return;
    }
    if (this.shot) this.shot.meta.annotations = this.marksBefore;
    this.go('CANCEL');
  }

  // Sending ------------------------------------------------------------------

  private payload(): FeedbackPayload {
    const identity = this.identity;
    const shot = this.shot;
    const custom = Object.keys(this.customData).length ? this.customData : undefined;
    const logs = this.o.console ? this.logs.entries() : [];
    const network = this.o.network ? this.network.entries() : [];
    return {
      message: this.form.message.value.trim(),
      email: identity?.email ?? this.form.email.value.trim(),
      ...(identity?.name ? { name: identity.name } : {}),
      locale: this.locale,
      page_url: shot?.meta.url ?? location.href,
      page_title: (shot?.meta.title ?? document.title) || undefined,
      referrer: document.referrer || undefined,
      client: collectClient(),
      console_log: logs.length ? logs : undefined,
      network_log: network.length ? network : undefined,
      annotations: shot?.meta.annotations.length ? shot.meta.annotations : undefined,
      custom_data: custom,
      identity: identity
        ? {
            ...(identity.id !== undefined ? { id: String(identity.id) } : {}),
            ...(identity.name ? { name: identity.name } : {}),
            email: identity.email,
          }
        : undefined,
      submitted_from_url: location.href,
      screenshot_error: shot ? undefined : (this.shotError ?? undefined),
      widget_version: VERSION,
      elapsed_ms: Math.max(0, Date.now() - this.openedAt),
      website: this.form.honeypot.value,
    };
  }

  private async submit(): Promise<void> {
    if (this.store.state !== 'form') return;
    this.validated = true;
    this.revalidate();
    if (Object.keys(this.errors).length) {
      this.render();
      this.focusFirstError();
      return;
    }
    this.go('SUBMIT');

    const payload = this.payload();
    if (!this.events.emit('before_send', payload)) {
      this.go('INVALID');
      return;
    }

    const shot = this.shot;
    let blob: Blob | null = null;
    if (shot) blob = await composeShot(shot.blob, shot.meta.annotations, shot.meta.scale, this.o.quality).catch(() => shot.blob);

    const result = await sendFeedback(
      `${this.o.endpoint}/v1/projects/${encodeURIComponent(this.o.projectKey)}/feedback`,
      this.idempotencyKey,
      payload,
      blob,
      {
        fetch: nativeFetch,
        shrink: (b) => composeShot(b, [], 1, 0.6, 0.5),
      },
    );
    if (this.destroyed) return;

    if (result.ok) {
      void this.draft.clear();
      this.form.email.value = '';
      this.form.message.value = '';
      this.form.honeypot.value = '';
      if (this.shot) URL.revokeObjectURL(this.shot.url);
      this.shot = null;
      this.history = null;
      this.shotError = null;
      this.errors = {};
      this.validated = false;
      this.idempotencyKey = uuid();
      this.shareUrl = result.url ?? null;
      this.success.setLink(this.shareUrl);
      this.go('SENT');
      this.events.emit('after_send', { id: result.id, ...(result.url ? { url: result.url } : {}) });
      return;
    }

    const fields = result.fields ?? {};
    if (result.status === 422 && (fields.email || fields.message)) {
      if (fields.email) this.errors.email = this.t('error_email');
      if (fields.message) this.errors.message = this.t('error_message');
      this.go('INVALID');
      return;
    }

    this.go('FAILED');
    if (result.status === 403 || result.status === 404) console.warn(`[codebar feedback] ${result.code} (HTTP ${result.status})`);
    this.events.emit('error', {
      code: result.code,
      status: result.status,
      ...(result.retryAfter ? { retryAfter: result.retryAfter } : {}),
    });
  }

  // Listeners ----------------------------------------------------------------

  private listen(): void {
    const on = (target: EventTarget, type: string, fn: (e: never) => void, opts?: AddEventListenerOptions | boolean): void => {
      const handler = (e: Event): void => this.safe(() => fn(e as never));
      target.addEventListener(type, handler, opts);
      this.offs.push(() => target.removeEventListener(type, handler, opts));
    };

    on(document, 'keydown', (e: KeyboardEvent) => this.onKey(e));
    on(document, 'visibilitychange', () => {
      if (document.visibilityState === 'hidden' && this.store.state !== 'success') this.saveDraft();
    });
    on(window, 'pagehide', () => {
      if (this.store.state !== 'success') this.saveDraft();
    });
    const vv = window.visualViewport;
    if (vv) {
      const update = (): void => {
        const covered = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
        this.hostUi.root.style.setProperty('--fb-kb', `${Math.round(covered)}px`);
      };
      on(vv, 'resize', update);
      on(vv, 'scroll', update);
    }
  }

  private onKey(e: KeyboardEvent): void {
    const state = this.store.state;
    if (state === 'annotating') {
      if (e.key === 'Escape') {
        e.preventDefault();
        this.cancelAnnotating();
      } else if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        this.undo();
      } else if (e.key === 'Tab') {
        trapTab(e, this.toolbar.el, this.hostUi.shadow.activeElement);
      }
      return;
    }
    if (state === 'closed' || state === 'capturing') return;
    const inside = e.composedPath().includes(this.hostUi.host);
    if (e.key === 'Escape' && inside) {
      e.preventDefault();
      this.go(state === 'error' ? 'BACK' : 'CLOSE');
    } else if (e.key === 'Tab' && inside) {
      trapTab(e, this.panel, this.hostUi.shadow.activeElement);
    }
  }

  private safe(fn: () => void): void {
    try {
      fn();
    } catch (error) {
      console.warn('[codebar feedback] internal error', error);
    }
  }
}
