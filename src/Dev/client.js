(() => {
  const script = document.currentScript;
  if (!script || window.__hanjiPreview) return;
  window.__hanjiPreview = true;
  let session = script.dataset.session;
  let revision = Number(script.dataset.revision || 0);
  let basePath = script.dataset.basePath || '/';
  let overlay;
  let errorKey;
  let dismissedError;
  let paused = false;
  let socket;
  let retryTimer;
  let retryDelay = 250;
  let updates = Promise.resolve();

  function hideError() {
    overlay?.remove();
    overlay = undefined;
  }

  function showError(error) {
    errorKey = JSON.stringify(error);
    if (errorKey === dismissedError) return;
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = '__hanji_error';
      const root = overlay.attachShadow({ mode: 'open' });
      const style = document.createElement('style');
      style.textContent = `
        :host { position:fixed; inset:0; z-index:2147483647; font-family:system-ui,sans-serif; color:#e9edf5; }
        .backdrop { height:100%; display:grid; place-items:start center; padding:7vh 24px; box-sizing:border-box; background:#080b12b8; overflow:auto; }
        .panel { width:min(780px,100%); background:#171d29; border:1px solid #f0717880; border-radius:16px; box-shadow:0 24px 80px #0008; overflow:hidden; }
        header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid #ffffff18; }
        strong { color:#d3b0ff; letter-spacing:.08em; font-size:14px; } span { color:#ff929b; margin-left:18px; }
        button { background:none; border:0; color:#a6b1c5; font-size:24px; cursor:pointer; }
        pre { margin:0; padding:24px; white-space:pre-wrap; overflow-wrap:anywhere; font:14px/1.7 ui-monospace,monospace; }
        footer { padding:0 24px 24px; color:#8997ae; font-size:13px; }
      `;
      const backdrop = document.createElement('div');
      backdrop.className = 'backdrop';
      const panel = document.createElement('section');
      panel.className = 'panel';
      panel.setAttribute('role', 'alertdialog');
      panel.setAttribute('aria-label', 'Hanji build failed');
      const header = document.createElement('header');
      const title = document.createElement('div');
      const brand = document.createElement('strong');
      brand.textContent = 'HANJI';
      const label = document.createElement('span');
      label.textContent = 'Build failed';
      title.append(brand, label);
      const close = document.createElement('button');
      close.textContent = '×';
      close.setAttribute('aria-label', 'Dismiss build error');
      close.onclick = () => { dismissedError = errorKey; hideError(); };
      header.append(title, close);
      const message = document.createElement('pre');
      const footer = document.createElement('footer');
      footer.textContent = 'The last successful build is still available. Save your changes to retry.';
      panel.append(header, message, footer);
      backdrop.append(panel);
      root.append(style, backdrop);
      document.documentElement.append(overlay);
    }
    const parts = [error.message];
    if (error.source) parts.push(`Source: ${error.source}`);
    if (error.url) parts.push(`URL: ${error.url}`);
    if (Array.isArray(error.hints)) parts.push(...error.hints);
    overlay.shadowRoot.querySelector('pre').textContent = parts.join('\n\n');
  }

  function reload(nextBasePath) {
    paused = true;
    clearTimeout(retryTimer);
    socket?.close();
    if (nextBasePath && nextBasePath !== basePath && location.pathname.startsWith(basePath)) {
      location.replace(nextBasePath + location.pathname.slice(basePath.length) + location.search + location.hash);
    } else {
      location.reload();
    }
  }

  async function styles(version) {
    const links = [...document.querySelectorAll('link[rel~="stylesheet" i]')].filter(link => new URL(link.href, location.href).origin === location.origin);
    if (links.length === 0) return false;
    await Promise.all(links.map(link => new Promise((resolve, reject) => {
      const next = link.cloneNode(true);
      const url = new URL(link.href, location.href);
      url.searchParams.set('__hanji', String(version));
      next.href = url.href;
      next.disabled = link.disabled;
      next.nonce = link.nonce;
      const timer = setTimeout(() => { next.remove(); reject(new Error('Stylesheet update timed out')); }, 5000);
      next.onload = () => { clearTimeout(timer); link.remove(); resolve(); };
      next.onerror = () => { clearTimeout(timer); next.remove(); reject(new Error('Stylesheet update failed')); };
      link.after(next);
    })));
    return true;
  }

  async function apply(state) {
    if (paused) return;
    if (state.status === 'error' && state.error) {
      showError(state.error);
    } else if (state.status === 'building') {
      dismissedError = undefined;
    } else if (state.status === 'ready') {
      dismissedError = undefined;
      hideError();
      if (state.session !== session) return reload(state.basePath);
      if (state.revision <= revision) return;
      const changes = state.changes.filter(change => change.revision > revision);
      if (changes.length === 0 || changes[0].revision !== revision + 1 || changes.some(change => change.type === 'reload')) return reload(state.basePath);
      if (changes.some(change => change.type === 'css')) {
        try {
          if (!await styles(state.revision)) return reload(state.basePath);
        } catch {
          return reload(state.basePath);
        }
      }
      revision = state.revision;
      basePath = state.basePath;
    }
  }

  function connect() {
    if (paused) return;
    const url = new URL('/__hanji/ws', location.href);
    url.protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const current = new WebSocket(url);
    socket = current;
    current.onopen = () => { retryDelay = 250; };
    current.onmessage = event => {
      if (current !== socket || paused) return;
      try {
        const state = JSON.parse(event.data);
        updates = updates.then(() => apply(state)).catch(() => reload());
      } catch {
        current.close();
      }
    };
    current.onerror = () => current.close();
    current.onclose = () => {
      if (current !== socket || paused) return;
      clearTimeout(retryTimer);
      retryTimer = setTimeout(connect, retryDelay);
      retryDelay = Math.min(retryDelay * 2, 5000);
    };
  }
  addEventListener('pagehide', () => { paused = true; clearTimeout(retryTimer); socket?.close(); });
  addEventListener('pageshow', () => { if (paused) { paused = false; connect(); } });
  connect();
})();
