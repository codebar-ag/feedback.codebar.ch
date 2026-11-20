import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initWith } from '../../src/init';
import type { CaptureModule } from '../../src/capture/load';
import type { FeedbackInstance, FeedbackOptions } from '../../src/types';

const KEY = 'fbk_live_unitAAAAAAAAAAAAAAAAAA';
let instance: FeedbackInstance | null = null;
let fetchMock: ReturnType<typeof vi.fn>;

const capture = (impl?: () => Promise<unknown>): (() => Promise<CaptureModule>) => {
  return async () =>
    ({
      capturePage: vi.fn(impl ?? (async () => ({ blob: new Blob(['jpeg'], { type: 'image/jpeg' }), w: 1200, h: 3000, scale: 1 }))),
      CaptureError: Error,
      toJpeg: vi.fn(),
    }) as unknown as CaptureModule;
};

async function start(options: Partial<FeedbackOptions> = {}, loader = capture()): Promise<FeedbackInstance> {
  instance = await initWith({ projectKey: KEY, endpoint: 'https://api.example', locale: 'de_CH', ...options }, loader);
  return instance;
}

const shadow = (): ShadowRoot => document.querySelector('codebar-feedback')!.shadowRoot!;
const $ = <T extends Element = HTMLElement>(sel: string): T => shadow().querySelector<T>(sel)!;
const visible = (sel: string): boolean => {
  const el = shadow().querySelector<HTMLElement>(sel);
  return !!el && !el.closest('[hidden]');
};
const flush = async (): Promise<void> => {
  for (let i = 0; i < 5; i++) await new Promise((r) => setTimeout(r, 0));
};
const type = (el: HTMLInputElement | HTMLTextAreaElement, value: string): void => {
  el.value = value;
  el.dispatchEvent(new Event('input'));
};
const submit = (): void => {
  $<HTMLFormElement>('form').dispatchEvent(new Event('submit', { cancelable: true }));
};
const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

beforeEach(() => {
  vi.stubGlobal('requestAnimationFrame', (fn: FrameRequestCallback) => setTimeout(() => fn(0), 0));
  fetchMock = vi.fn(async (url: string) =>
    String(url).endsWith('/config') ? json(200, { theme: { primary: '#0A7C66' } }) : json(201, { id: '01JX', status: 'received' }),
  );
  window.fetch = fetchMock as unknown as typeof fetch;
});

afterEach(() => {
  instance?.destroy({ clearDraft: true });
  instance = null;
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('widget', () => {
  it('renders only the launcher in an open shadow root', async () => {
    await start();
    expect(shadow().mode).toBe('open');
    expect(visible('.l')).toBe(true);
    expect(visible('.p')).toBe(false);
    const l = $('.l');
    expect(l.getAttribute('aria-label')).toBe('Feedback geben');
    expect(l.getAttribute('aria-haspopup')).toBe('dialog');
    expect(l.getAttribute('aria-expanded')).toBe('false');
  });

  it('applies the project color from the API', async () => {
    await start();
    await flush();
    expect($('.r').style.getPropertyValue('--fb-primary')).toBe('#0A7C66');
    expect($('.r').style.getPropertyValue('--fb-on-primary')).toBe('#FFFFFF');
    expect(fetchMock).toHaveBeenCalledWith(
      `https://api.example/v1/projects/${KEY}/config`,
      expect.objectContaining({ credentials: 'omit' }),
    );
  });

  it('lets colors.primary override the project color', async () => {
    await start({ colors: { primary: 'white' } });
    expect($('.r').style.getPropertyValue('--fb-primary')).toBe('#FFFFFF');
    expect($('.r').style.getPropertyValue('--fb-strong')).toBe('#101F3C');
    // The config is still requested: it also checks whether the page is allowed.
    expect(fetchMock).toHaveBeenCalledOnce();
  });

  it('sits bottom-right unless the project or init() says otherwise', async () => {
    await start();
    await flush();
    expect($('.r').classList.contains('left')).toBe(false);
  });

  it('applies the launcher position from the API', async () => {
    sessionStorage.clear();
    fetchMock.mockImplementation(async () => json(200, { theme: { primary: '#0A7C66' }, position: 'bottom-left' }));
    await start();
    await flush();
    expect($('.r').classList.contains('left')).toBe(true);
  });

  it('lets position override the project position', async () => {
    sessionStorage.clear();
    fetchMock.mockImplementation(async () => json(200, { theme: { primary: '#0A7C66' }, position: 'bottom-left' }));
    await start({ position: 'bottom-right' });
    await flush();
    expect($('.r').classList.contains('left')).toBe(false);
  });

  it('opens and closes, header has only icon and X', async () => {
    const w = await start();
    const events: string[] = [];
    w.on('open', () => events.push('open'));
    w.on('close', () => events.push('close'));
    $('.l').click();
    expect(w.isOpen()).toBe(true);
    expect(visible('.p')).toBe(true);
    expect($('.p').getAttribute('role')).toBe('dialog');
    expect($('.p').getAttribute('aria-label')).toBe('Feedback geben');
    expect($('.l').getAttribute('aria-expanded')).toBe('true');
    const header = $('.hd');
    expect(header.querySelectorAll('button')).toHaveLength(1);
    expect(header.textContent).toBe('');
    $('.x').click();
    expect(w.isOpen()).toBe(false);
    expect(events).toEqual(['open', 'close']);
  });

  it('closes with Escape inside the widget', async () => {
    const w = await start();
    w.open();
    $('textarea').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, composed: true }));
    expect(w.isOpen()).toBe(false);
  });

  it('shows only the email field without identity, no name field', async () => {
    const w = await start();
    w.open();
    expect(visible('input[type=email]')).toBe(true);
    expect(shadow().querySelector('input[name=name]')).toBeNull();
    expect(visible('.id')).toBe(false);
    expect($('label[for$="-e"]').className).toContain('rq');
    expect($('label[for$="-m"]').className).toContain('rq');
  });

  it('shows "Senden als" with the identity badge instead of the email field', async () => {
    const w = await start({ identity: { id: 4711, name: 'Anna Muster', email: 'anna.muster@beispiel.ch' } });
    w.open();
    expect(visible('input[type=email]')).toBe(false);
    expect(visible('.id')).toBe(true);
    expect($('.id b').textContent).toBe('Anna Muster');
    expect($('.id span').textContent).toBe('anna.muster@beispiel.ch');
    expect(shadow().textContent).toContain('Senden als');
    w.identify(null);
    expect(visible('input[type=email]')).toBe(true);
  });

  it('validates on submit, focuses the first error and revalidates on input', async () => {
    const w = await start();
    w.open();
    submit();
    const emailErr = $('input[type=email]').getAttribute('aria-describedby')!;
    expect(shadow().getElementById(emailErr)!.textContent).toBe('Bitte gib eine gültige E-Mail ein.');
    expect($('input[type=email]').getAttribute('aria-invalid')).toBe('true');
    expect(shadow().activeElement).toBe($('input[type=email]'));
    expect(fetchMock.mock.calls.filter((c) => String(c[0]).endsWith('/feedback'))).toHaveLength(0);
    type($('input[type=email]'), 'anna@beispiel.ch');
    expect(shadow().getElementById(emailErr)!.textContent).toBe('');
    expect($('textarea').getAttribute('aria-invalid')).toBe('true');
  });

  it('sends the payload, shows the success page and closes after 3 s', async () => {
    const w = await start({ customData: { tenant: 'acme' } });
    await flush();
    const after = vi.fn();
    w.on('after_send', after);
    w.open();
    type($('input[type=email]'), 'anna@beispiel.ch');
    type($('textarea'), 'Der Knopf geht nicht.');
    console.error('Boom with token=abc');
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    submit();
    expect($('button[type=submit]').getAttribute('aria-busy')).toBe('true');
    expect($('button[type=submit]').textContent).toBe('Wird gesendet …');
    await vi.advanceTimersByTimeAsync(10);

    const call = fetchMock.mock.calls.find((c) => String(c[0]).endsWith('/feedback'))!;
    expect(call[0]).toBe(`https://api.example/v1/projects/${KEY}/feedback`);
    const init = call[1] as RequestInit;
    const body = init.body as FormData;
    expect((init.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
    expect(body.get('message')).toBe('Der Knopf geht nicht.');
    expect(body.get('email')).toBe('anna@beispiel.ch');
    expect(body.get('locale')).toBe('de_CH');
    expect(body.get('page_url')).toBe(location.href);
    expect(body.get('submitted_from_url')).toBe(location.href);
    expect(body.get('widget_version')).toBe('1.0.0');
    expect(body.get('website')).toBe('');
    expect(JSON.parse(String(body.get('custom_data')))).toEqual({ tenant: 'acme' });
    expect(JSON.parse(String(body.get('console_log')))[0].message).toBe('Boom with token=[redacted]');
    expect(JSON.parse(String(body.get('client'))).viewport).toBeDefined();
    expect(body.has('screenshot')).toBe(false);

    expect(visible('.st.ok')).toBe(true);
    expect(visible('form')).toBe(false);
    expect(after).toHaveBeenCalledWith({ id: '01JX' });
    expect(sessionStorage.getItem(`cbf:${KEY}:draft`)).toBeNull();

    await vi.advanceTimersByTimeAsync(3000);
    expect(w.isOpen()).toBe(false);
    w.open();
    expect($<HTMLTextAreaElement>('textarea').value).toBe('');
  });

  it('shows the error page and keeps the text', async () => {
    fetchMock.mockImplementation(async (url: string) =>
      String(url).endsWith('/config') ? json(200, {}) : json(403, { error: { code: 'origin_not_allowed' } }),
    );
    const w = await start();
    const onError = vi.fn();
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    w.on('error', onError);
    w.open();
    type($('input[type=email]'), 'anna@beispiel.ch');
    type($('textarea'), 'Text bleibt erhalten');
    submit();
    await flush();
    expect(visible('.st.err')).toBe(true);
    expect($('.st.err').textContent).toContain('Senden fehlgeschlagen');
    expect($('.st.err').textContent).toContain('Dein Feedback wurde nicht gesendet.');
    expect($('.st.err').querySelectorAll('button')).toHaveLength(1);
    expect(onError).toHaveBeenCalledWith({ code: 'origin_not_allowed', status: 403 });
    expect(warn).toHaveBeenCalled();
    $('.st.err button').click();
    expect(visible('form')).toBe(true);
    expect($<HTMLTextAreaElement>('textarea').value).toBe('Text bleibt erhalten');
  });

  it('maps 422 errors to the fields', async () => {
    fetchMock.mockImplementation(async (url: string) =>
      String(url).endsWith('/config') ? json(200, {}) : json(422, { error: { code: 'validation_failed', fields: { message: ['x'] } } }),
    );
    const w = await start({ identity: { name: 'A', email: 'a@b.ch' } });
    w.open();
    type($('textarea'), 'abc');
    submit();
    await flush();
    expect(visible('form')).toBe(true);
    expect($('textarea').getAttribute('aria-invalid')).toBe('true');
  });

  it('lets before_send change the payload or cancel', async () => {
    const w = await start({ identity: { id: 1, name: 'Anna', email: 'anna@b.ch' } });
    w.on('before_send', (p) => {
      p.custom_data = { changed: true };
    });
    const cancel = w.on('before_send', () => false);
    w.open();
    type($('textarea'), 'Hallo');
    submit();
    await flush();
    expect(fetchMock.mock.calls.filter((c) => String(c[0]).endsWith('/feedback'))).toHaveLength(0);
    expect(visible('form')).toBe(true);
    cancel();
    submit();
    await flush();
    const body = fetchMock.mock.calls.find((c) => String(c[0]).endsWith('/feedback'))![1].body as FormData;
    expect(JSON.parse(String(body.get('custom_data')))).toEqual({ changed: true });
    expect(JSON.parse(String(body.get('identity')))).toEqual({ id: '1', name: 'Anna', email: 'anna@b.ch' });
    expect(body.get('name')).toBe('Anna');
  });

  it('never captures a screenshot automatically', async () => {
    const loader = vi.fn(capture());
    const w = await start({}, loader);
    w.open();
    await flush();
    expect(loader).not.toHaveBeenCalled();
    expect(visible('.th')).toBe(false);
    expect(visible('.tr button')).toBe(true);
    expect($('.tr').querySelectorAll('button:not([hidden])')).toHaveLength(1);
  });

  it('captures, annotates and shows preview, pen and trash without texts', async () => {
    const w = await start();
    w.open();
    $<HTMLButtonElement>('.tr button').click();
    expect($('.r').dataset.state).toBe('capturing');
    expect($('.r').classList.contains('away')).toBe(true);
    await flush();
    expect($('.r').dataset.state).toBe('annotating');
    expect(visible('.tb')).toBe(true);
    expect($('.tb [data-tool=rect]').getAttribute('aria-pressed')).toBe('true');
    $<HTMLButtonElement>('.tb .fin').click();
    expect($('.r').dataset.state).toBe('form');
    expect(visible('.th')).toBe(true);
    const buttons = Array.from($('.tr').querySelectorAll<HTMLButtonElement>('button')).filter((b) => !b.hidden);
    expect(buttons.map((b) => b.getAttribute('aria-label'))).toEqual(['Bearbeiten', 'Screenshot entfernen']);
    expect($('.tr').textContent).toBe('');
    expect(w.isOpen()).toBe(true);
  });

  it('removes a fresh screenshot on Escape and keeps an existing one', async () => {
    const w = await start();
    w.open();
    $<HTMLButtonElement>('.tr button').click();
    await flush();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect($('.r').dataset.state).toBe('form');
    expect(visible('.th')).toBe(false);

    $<HTMLButtonElement>('.tr button').click();
    await flush();
    $<HTMLButtonElement>('.tb .fin').click();
    $<HTMLButtonElement>('.tr button[aria-label=Bearbeiten]').click();
    expect($('.r').dataset.state).toBe('annotating');
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(visible('.th')).toBe(true);
    expect(w.isOpen()).toBe(true);
  });

  it('marks a failed screenshot and keeps the form usable', async () => {
    const w = await start(
      {},
      capture(async () => Promise.reject(Object.assign(new Error('x'), { code: 'timeout' }))),
    );
    w.open();
    $<HTMLButtonElement>('.tr button').click();
    await flush();
    expect($('.r').dataset.state).toBe('form');
    expect($('.tr button').classList.contains('bad')).toBe(true);
    expect($('.tr').parentElement!.querySelector('.e')!.textContent).toBe('Screenshot nicht möglich.');
    type($('input[type=email]'), 'a@b.ch');
    type($('textarea'), 'Ohne Bild');
    submit();
    await flush();
    const body = fetchMock.mock.calls.find((c) => String(c[0]).endsWith('/feedback'))![1].body as FormData;
    expect(body.get('screenshot_error')).toBe('timeout');
  });

  it('hides the tools row with screenshot mode off', async () => {
    const w = await start({ screenshot: { mode: 'off' } });
    w.open();
    expect(visible('.tr')).toBe(false);
  });

  it('switches the locale immediately', async () => {
    const w = await start({ messages: { fr_CH: { submit: 'Expédier' } } });
    w.open();
    w.setLocale('fr_CH');
    expect($('.r').lang).toBe('fr-CH');
    expect($('button[type=submit]').textContent).toBe('Expédier');
    expect($('label[for$="-m"]').textContent).toBe('Ton avis');
    w.setLocale('it');
    expect($('label[for$="-m"]').textContent).toBe('Il tuo feedback');
  });

  it('restores the draft and reopens the panel without focus', async () => {
    let w = await start();
    w.open();
    type($('input[type=email]'), 'anna@beispiel.ch');
    type($('textarea'), 'Entwurf');
    w.destroy();
    instance = null;
    w = await start();
    expect(w.isOpen()).toBe(true);
    expect($<HTMLInputElement>('input[type=email]').value).toBe('anna@beispiel.ch');
    expect($<HTMLTextAreaElement>('textarea').value).toBe('Entwurf');
    expect(shadow().activeElement).toBeNull();
  });

  it('is a singleton and cleans up on destroy', async () => {
    const before = console.error;
    const w = await start();
    expect(await initWith({ projectKey: 'other', endpoint: 'https://x' }, capture())).toBe(w);
    expect(console.error).not.toBe(before);
    w.destroy({ clearDraft: true });
    instance = null;
    expect(document.querySelector('codebar-feedback')).toBeNull();
    expect(console.error).toBe(before);
    expect(sessionStorage.getItem(`cbf:${KEY}:draft`)).toBeNull();
  });

  it('stays hidden and removes itself when the API refuses the page', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    fetchMock.mockImplementation(async () => json(403, { error: { code: 'origin_not_allowed' } }));
    await start();
    instance = null;
    expect(document.querySelector('codebar-feedback')).toBeNull();
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('not allowed'));
  });

  it('keeps the host hidden until the API has answered', async () => {
    let answer!: (r: Response) => void;
    fetchMock.mockImplementation(() => new Promise<Response>((r) => (answer = r)));
    const ready = start();
    await flush();
    expect(document.querySelector<HTMLElement>('codebar-feedback')!.style.display).toBe('none');
    answer(json(200, { theme: { primary: '#0A7C66' } }));
    await ready;
    expect(document.querySelector<HTMLElement>('codebar-feedback')!.style.display).toBe('');
    expect(visible('.l')).toBe(true);
  });

  it('still shows when the API is not reachable', async () => {
    fetchMock.mockImplementation(async () => Promise.reject(new TypeError('offline')));
    await start();
    expect(document.querySelector<HTMLElement>('codebar-feedback')!.style.display).toBe('');
    expect(visible('.l')).toBe(true);
  });

  it('shows and hides the launcher', async () => {
    const w = await start();
    w.hideLauncher();
    expect(visible('.l')).toBe(false);
    w.showLauncher();
    expect(visible('.l')).toBe(true);
  });

  it('rejects invalid options without touching the page', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    await expect(initWith({ projectKey: '', endpoint: '' }, capture())).rejects.toThrow();
    expect(document.querySelector('codebar-feedback')).toBeNull();
    expect(error).toHaveBeenCalled();
  });

  it('keeps the host page safe when a handler throws', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const w = await start();
    w.on('open', () => {
      throw new Error('host bug');
    });
    expect(() => w.open()).not.toThrow();
    expect(w.isOpen()).toBe(true);
    expect(warn).toHaveBeenCalled();
  });
});
