/*
 * InfraLogging viewer: a small single-page app on top of the REST API.
 *
 * Views (hash routes):
 *   #/login            sign in (POST /auth/login)
 *   #/runs             run history with filters and paging (GET /runs)
 *   #/runs/new         start a run, write scope (POST /runs)
 *   #/runs/{runId}     run details, logs, exceptions, metrics; finish run and
 *                      add log entries with write scope (GET/PATCH /runs/{id}, logs)
 *   #/scripts          script catalog (GET /scripts)
 *   #/scripts/{id}     one script with run counts (GET /scripts/{id})
 *   #/reports          execution summary and failing scripts (GET /reports/...)
 *
 * All text from the API is inserted as text nodes (never as HTML), because
 * log messages and script fields come from many scripts and are untrusted.
 */
'use strict';

const API_BASE = '/api/v1';
const SESSION_KEY = 'infralogging.session';
const RUN_STATUSES = ['Running', 'Success', 'Failed', 'Cancelled'];
const FINAL_STATUSES = ['Success', 'Failed', 'Cancelled'];
const LOG_LEVELS = ['Trace', 'Debug', 'Information', 'Warning', 'Error', 'Critical'];
const PAGE_SIZE = 25;

const view = document.getElementById('view');
let session = loadSession();
let flashMessage = null;
let renderCount = 0;

/* ======================================================================
 * Session (token kept in sessionStorage, cleared when the tab closes)
 * ==================================================================== */

function loadSession() {
  try {
    const saved = JSON.parse(sessionStorage.getItem(SESSION_KEY));
    if (saved && saved.expiresAt > Date.now()) return saved;
  } catch { /* ignore unreadable data */ }
  sessionStorage.removeItem(SESSION_KEY);
  return null;
}

function setSession(value) {
  session = value;
  if (value) sessionStorage.setItem(SESSION_KEY, JSON.stringify(value));
  else sessionStorage.removeItem(SESSION_KEY);
  updateHeader();
}

function canWrite() {
  return !!session && session.scope.split(' ').includes('write');
}

function signOut(message) {
  setSession(null);
  flashMessage = message || null;
  location.hash = '#/login';
}

/* ======================================================================
 * API client
 * ==================================================================== */

class ApiError extends Error {
  constructor(status, code, message) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

async function api(method, path, body) {
  const headers = { Accept: 'application/json' };
  if (session) headers.Authorization = `Bearer ${session.token}`;
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  let response;
  try {
    response = await fetch(API_BASE + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, 'NETWORK', 'Could not reach the API server. Check that it is running.');
  }

  let payload = null;
  try { payload = await response.json(); } catch { /* non-JSON body */ }

  if (!response.ok) {
    const error = new ApiError(
      response.status,
      payload?.error?.code ?? `HTTP_${response.status}`,
      payload?.error?.message ?? `The request failed with status ${response.status}.`
    );
    // An expired or rejected token ends the session (but a failed login does not).
    if (response.status === 401 && path !== '/auth/login') {
      signOut('Your session has ended. Sign in again to continue.');
    }
    throw error;
  }
  return payload;
}

/** Builds a query string from an object, skipping empty values. */
function queryString(values) {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(values)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, value);
  }
  const text = params.toString();
  return text ? `?${text}` : '';
}

/* ======================================================================
 * DOM helpers
 * ==================================================================== */

/** h('a', {href: '#/runs'}, 'Runs') - children are added as text, never HTML. */
function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs || {})) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') el.className = value;
    else if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
    else if (value === true) el.setAttribute(key, '');
    else el.setAttribute(key, value);
  }
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    el.append(child instanceof Node ? child : String(child));
  }
  return el;
}

function field(label, control, extraClass) {
  return h('label', { class: extraClass ? `field ${extraClass}` : 'field' }, label, control);
}

function select(name, options, selected, emptyLabel) {
  const el = h('select', { name },
    emptyLabel !== undefined ? h('option', { value: '' }, emptyLabel) : null,
    options.map((opt) => {
      const [value, label] = Array.isArray(opt) ? opt : [opt, opt];
      return h('option', { value, selected: String(value) === String(selected ?? '') }, label);
    })
  );
  return el;
}

function notice(message, kind) {
  return h('p', { class: kind === 'info' ? 'notice info' : 'notice', role: kind === 'info' ? 'status' : 'alert' }, message);
}

function emptyState(message) {
  return h('p', { class: 'empty' }, message);
}

function table(headers, rows) {
  return h('div', { class: 'table-wrap' },
    h('table', {},
      h('thead', {}, h('tr', {}, headers.map(([label, cls]) => h('th', { class: cls, scope: 'col' }, label)))),
      h('tbody', {}, rows)
    )
  );
}

/* ======================================================================
 * Formatting
 * ==================================================================== */

const dateTimeFormat = new Intl.DateTimeFormat(undefined, {
  year: 'numeric', month: 'short', day: 'numeric',
  hour: '2-digit', minute: '2-digit', second: '2-digit',
});
const timeFormat = new Intl.DateTimeFormat(undefined, {
  hour: '2-digit', minute: '2-digit', second: '2-digit',
});

/** Local time for reading, with the exact UTC value on hover. */
function time(iso, format = dateTimeFormat) {
  if (!iso) return h('span', { class: 'muted' }, '—');
  return h('time', { datetime: iso, title: `${iso} (UTC)` }, format.format(new Date(iso)));
}

function duration(ms) {
  if (ms === null || ms === undefined) return '—';
  if (ms < 1000) return `${ms} ms`;
  let seconds = Math.round(ms / 1000);
  const hours = Math.floor(seconds / 3600);
  seconds -= hours * 3600;
  const minutes = Math.floor(seconds / 60);
  seconds -= minutes * 60;
  if (hours) return `${hours}h ${minutes}m`;
  if (minutes) return `${minutes}m ${seconds}s`;
  return `${seconds}s`;
}

function statusBadge(status) {
  return h('span', { class: `status status-${String(status).toLowerCase()}` }, status);
}

function percent(rate) {
  return rate === null || rate === undefined ? '—' : `${Math.round(rate * 1000) / 10}%`;
}

function text(value) {
  return value === null || value === undefined || value === '' ? '—' : value;
}

/** Only http(s) links are rendered as links, so a stored value cannot run script. */
function safeLink(url) {
  if (typeof url === 'string' && /^https?:\/\//i.test(url)) {
    return h('a', { href: url, target: '_blank', rel: 'noopener noreferrer' }, url);
  }
  return text(url);
}

function prettyJson(value) {
  return h('pre', { class: 'json' }, JSON.stringify(value, null, 2));
}

/* ======================================================================
 * Header and router
 * ==================================================================== */

function updateHeader() {
  const nav = document.getElementById('nav');
  const account = document.getElementById('account');
  nav.hidden = !session;
  account.hidden = !session;
  if (session) {
    document.getElementById('account-name').textContent =
      `${session.username} (${canWrite() ? 'read and write' : 'read only'})`;
    for (const link of nav.querySelectorAll('[data-write-only]')) link.hidden = !canWrite();
  }
}

function markCurrentSection(section) {
  for (const link of document.querySelectorAll('#nav a')) {
    if (link.dataset.section === section) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  }
}

const routes = [
  { pattern: /^#\/login$/,                 view: loginView,        section: null,      auth: false },
  { pattern: /^#\/runs\/new$/,             view: newRunView,       section: 'new',     auth: true },
  { pattern: /^#\/runs\/([0-9a-f-]{36})$/i, view: runDetailView,   section: 'runs',    auth: true },
  { pattern: /^#\/runs$/,                  view: runsView,         section: 'runs',    auth: true },
  { pattern: /^#\/scripts\/(\d+)$/,        view: scriptDetailView, section: 'scripts', auth: true },
  { pattern: /^#\/scripts$/,               view: scriptsView,      section: 'scripts', auth: true },
  { pattern: /^#\/reports$/,               view: reportsView,      section: 'reports', auth: true },
];

async function render() {
  const [path, query = ''] = (location.hash || '#/runs').split('?');
  const route = routes.find((r) => r.pattern.test(path));
  if (!route) { location.hash = '#/runs'; return; }
  if (route.auth && !session) { location.hash = '#/login'; return; }
  if (!route.auth && session) { location.hash = '#/runs'; return; }

  markCurrentSection(route.section);
  const thisRender = ++renderCount;
  view.replaceChildren(h('p', { class: 'loading' }, 'Loading…'));

  try {
    const args = path.match(route.pattern).slice(1);
    const content = await route.view(new URLSearchParams(query), ...args);
    if (thisRender !== renderCount) return; // the user navigated away meanwhile
    view.replaceChildren(content);
    const heading = view.querySelector('h1');
    if (heading) document.title = `${heading.textContent} | InfraLogging`;
    view.focus({ preventScroll: true });
  } catch (error) {
    if (thisRender !== renderCount || error.status === 401) return;
    view.replaceChildren(
      h('h1', {}, error.status === 404 ? 'Not found' : 'Something went wrong'),
      notice(error.message)
    );
  }
}

/* ======================================================================
 * Sign in
 * ==================================================================== */

async function loginView() {
  const message = h('p', { class: 'form-message error', role: 'alert' });
  const username = h('input', { name: 'username', autocomplete: 'username', required: true });
  const password = h('input', { name: 'password', type: 'password', autocomplete: 'current-password', required: true });
  const button = h('button', { type: 'submit' }, 'Sign in');

  const form = h('form', {
    class: 'form-stack',
    onsubmit: async (event) => {
      event.preventDefault();
      button.disabled = true;
      message.textContent = '';
      try {
        const { data } = await api('POST', '/auth/login', {
          username: username.value.trim(),
          password: password.value,
        });
        setSession({
          token: data.token,
          scope: data.scope,
          username: username.value.trim(),
          expiresAt: Date.now() + data.expiresIn * 1000,
        });
        location.hash = '#/runs';
      } catch (error) {
        message.textContent = error.status === 401
          ? 'The username or password is incorrect.'
          : error.message;
        button.disabled = false;
        password.select();
      }
    },
  },
    field('Username', username),
    field('Password', password),
    message,
    h('div', {}, button)
  );

  const flash = flashMessage;
  flashMessage = null;

  return h('section', { class: 'signin' },
    h('h1', {}, 'Sign in'),
    h('p', { class: 'subtitle' }, 'View script runs, logs, and reports for IT Operations automation.'),
    flash ? notice(flash, 'info') : null,
    form
  );
}

/* ======================================================================
 * Runs
 * ==================================================================== */

async function runsView(params) {
  const filters = {
    scriptId: params.get('scriptId') || '',
    status: params.get('status') || '',
    from: params.get('from') || '',
    to: params.get('to') || '',
  };
  const page = Math.max(1, Number(params.get('page')) || 1);

  const { data: scripts } = await api('GET', '/scripts');

  const form = h('form', {
    class: 'filters',
    onsubmit: (event) => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.target));
      location.hash = `#/runs${queryString(values)}`;
    },
  },
    field('Script', select('scriptId', scripts.map((s) => [s.scriptId, s.scriptName]), filters.scriptId, 'All scripts')),
    field('Status', select('status', RUN_STATUSES, filters.status, 'Any status')),
    field('Started on or after', h('input', { type: 'date', name: 'from', value: filters.from })),
    field('Started on or before', h('input', { type: 'date', name: 'to', value: filters.to })),
    h('button', { type: 'submit' }, 'Apply filters'),
    Object.values(filters).some(Boolean)
      ? h('a', { class: 'button button-quiet', href: '#/runs' }, 'Clear')
      : null
  );

  let results;
  try {
    const result = await api('GET', `/runs${queryString({ ...filters, page, limit: PAGE_SIZE })}`);
    results = runsTable(result, filters, page);
  } catch (error) {
    if (error.status === 401) throw error;
    results = notice(error.message); // e.g. a date range that ends before it starts
  }

  return h('section', {},
    h('div', { class: 'page-head' },
      h('div', {},
        h('h1', {}, 'Runs'),
        h('p', { class: 'subtitle' }, 'Every execution of a registered script, newest first.')
      ),
      canWrite() ? h('a', { class: 'button', href: '#/runs/new' }, 'Start a run') : null
    ),
    form,
    results
  );
}

function runsTable(result, filters, page) {
  const { data: runs, pagination } = result;
  if (runs.length === 0) {
    return emptyState(Object.values(filters).some(Boolean)
      ? 'No runs match these filters. Clear the filters to see all runs.'
      : 'No runs have been recorded yet.');
  }

  const rows = runs.map((run) => h('tr', {},
    h('td', {}, statusBadge(run.status)),
    h('td', {}, h('a', { href: `#/runs/${run.runId}` }, run.scriptName)),
    h('td', { class: 'nowrap' }, time(run.startTimeUtc)),
    h('td', { class: 'num' }, duration(run.durationMs)),
    h('td', {}, text(run.computerName)),
    h('td', {}, text(run.userName))
  ));

  const first = (pagination.page - 1) * pagination.limit + 1;
  const last = first + runs.length - 1;
  const pageLink = (target) => `#/runs${queryString({ ...filters, page: target })}`;

  return h('div', {},
    table([['Status'], ['Script'], ['Started'], ['Duration', 'num'], ['Computer'], ['User']], rows),
    h('div', { class: 'pager' },
      h('span', {}, `Showing ${first}–${last} of ${pagination.total}`),
      h('div', {},
        page > 1 ? h('a', { class: 'button button-quiet', href: pageLink(page - 1) }, 'Previous') : null,
        last < pagination.total ? h('a', { class: 'button button-quiet', href: pageLink(page + 1) }, 'Next') : null
      )
    )
  );
}

/* ======================================================================
 * Run details: summary, logs, exceptions, metrics, and write actions
 * ==================================================================== */

async function runDetailView(params, runId) {
  const [{ data: run }, { data: exceptions }, { data: metrics }] = await Promise.all([
    api('GET', `/runs/${runId}`),
    api('GET', `/runs/${runId}/exceptions`),
    api('GET', `/runs/${runId}/metrics`),
  ]);

  const facts = [
    ['Run ID', h('span', { class: 'id' }, run.runId)],
    ['Started', time(run.startTimeUtc)],
    ['Ended', time(run.endTimeUtc)],
    ['Duration', duration(run.durationMs)],
    ['Script version', text(run.scriptVersion)],
    ['Computer', text(run.computerName)],
    ['User', text(run.userName)],
    ['PowerShell', run.powerShellVersion ? `${run.powerShellVersion} ${run.psEdition ?? ''}`.trim() : '—'],
    ['Process ID', text(run.processId)],
    ['Correlation ID', h('span', { class: 'id' }, text(run.correlationId))],
    ['Parent run', run.parentRunId ? h('a', { class: 'id', href: `#/runs/${run.parentRunId}` }, run.parentRunId) : '—'],
    ['Git commit', run.gitCommit ? h('span', { class: 'id' }, run.gitCommit) : '—'],
    ['Script path', text(run.scriptPath)],
    ['Message', text(run.message)],
  ];

  // Tab panels: logs load on their own so the level filter can refetch them.
  const panel = h('div', { id: 'run-panel', role: 'tabpanel' });
  const tabButtons = [];
  const tabs = [
    { id: 'logs', label: 'Logs', show: () => showLogs(panel, runId) },
    { id: 'exceptions', label: `Exceptions (${exceptions.length})`, show: () => panel.replaceChildren(exceptionList(exceptions)) },
    { id: 'metrics', label: `Metrics (${metrics.length})`, show: () => panel.replaceChildren(metricTable(metrics)) },
  ];
  const selectTab = (tab) => {
    for (const button of tabButtons) button.setAttribute('aria-selected', String(button.dataset.tab === tab.id));
    tab.show();
  };
  for (const tab of tabs) {
    tabButtons.push(h('button', {
      type: 'button', role: 'tab', 'data-tab': tab.id, 'aria-controls': 'run-panel',
      onclick: () => selectTab(tab),
    }, tab.label));
  }
  // Failed runs open on the exceptions tab when there are any.
  selectTab(run.status === 'Failed' && exceptions.length ? tabs[1] : tabs[0]);

  return h('section', {},
    h('a', { class: 'back', href: '#/runs' }, 'Back to runs'),
    h('div', { class: 'page-head' },
      h('div', {},
        h('h1', {}, run.scriptName),
        h('p', { class: 'subtitle' }, statusBadge(run.status), ' ',
          h('a', { href: `#/scripts/${run.scriptId}` }, 'View this script'))
      )
    ),
    h('dl', { class: 'facts' }, facts.map(([label, value]) => h('div', {}, h('dt', {}, label), h('dd', {}, value)))),
    run.parameters !== null && run.parameters !== undefined
      ? h('div', {}, h('h2', {}, 'Parameters'), prettyJson(run.parameters))
      : null,
    h('div', { class: 'tabs', role: 'tablist', 'aria-label': 'Run data' }, tabButtons),
    panel,
    canWrite() ? runActions(run, () => selectTab(tabs[0])) : null
  );
}

async function showLogs(panel, runId, level = '', focusFilter = false) {
  const levelSelect = select('level', LOG_LEVELS, level, 'All levels');
  levelSelect.addEventListener('change', () => showLogs(panel, runId, levelSelect.value, true));
  const tools = h('div', { class: 'tab-tools' }, field('Level', levelSelect));

  panel.replaceChildren(tools, h('p', { class: 'loading' }, 'Loading logs…'));
  try {
    const { data: logs } = await api('GET', `/runs/${runId}/logs${queryString({ level })}`);
    panel.replaceChildren(tools, logs.length
      ? h('div', { class: 'console', role: 'log', 'aria-label': 'Log entries' }, logs.map(logLine))
      : emptyState(level ? `No ${level} entries for this run.` : 'This run has no log entries.'));
    // Keep keyboard focus on the filter only after the user changed it.
    if (focusFilter) levelSelect.focus({ preventScroll: true });
  } catch (error) {
    if (error.status !== 401) panel.replaceChildren(tools, notice(error.message));
  }
}

function logLine(entry) {
  const extra = entry.jsonData !== null && entry.jsonData !== undefined
    ? h('details', {}, h('summary', {}, 'Data'), h('pre', {}, JSON.stringify(entry.jsonData, null, 2)))
    : null;
  const where = [entry.functionName, entry.lineNumber ? `line ${entry.lineNumber}` : null].filter(Boolean).join(', ');
  return h('div', { class: `log-line lvl-${entry.level.toLowerCase()}` },
    h('span', { class: 'log-time' }, time(entry.timestampUtc, timeFormat)),
    h('span', { class: 'log-level' }, entry.level),
    h('div', { class: 'log-text' },
      entry.eventName ? h('span', { class: 'log-event' }, entry.eventName) : null,
      text(entry.message),
      where ? h('span', { class: 'log-time' }, ` (${where})`) : null,
      extra
    )
  );
}

function exceptionList(exceptions) {
  if (!exceptions.length) return emptyState('No exceptions were recorded for this run.');
  return h('div', {}, exceptions.map((ex) => h('article', { class: 'exception' },
    h('h3', {}, text(ex.exceptionType)),
    h('p', {}, text(ex.message)),
    h('p', { class: 'meta' }, time(ex.timestampUtc),
      ex.fullyQualifiedErrorId ? `, error ID ${ex.fullyQualifiedErrorId}` : '',
      ex.category ? `, category ${ex.category}` : ''),
    ex.scriptStackTrace ? h('details', {}, h('summary', {}, 'Script stack trace'), h('pre', { class: 'json' }, ex.scriptStackTrace)) : null,
    ex.stackTrace ? h('details', {}, h('summary', {}, '.NET stack trace'), h('pre', { class: 'json' }, ex.stackTrace)) : null,
    ex.invocationInfo ? h('details', {}, h('summary', {}, 'Invocation info'), prettyJson(ex.invocationInfo)) : null
  )));
}

function metricTable(metrics) {
  if (!metrics.length) return emptyState('No metrics were recorded for this run.');
  const number = new Intl.NumberFormat();
  return table([['Metric'], ['Value', 'num'], ['Unit'], ['Category'], ['Recorded']],
    metrics.map((m) => h('tr', {},
      h('td', {}, m.metricName),
      h('td', { class: 'num' }, number.format(m.metricValue)),
      h('td', {}, text(m.unit)),
      h('td', {}, text(m.category)),
      h('td', { class: 'nowrap' }, time(m.timestampUtc))
    )));
}

/** Write-scope forms: finish a running run, add a log entry. */
function runActions(run, refreshLogs) {
  const panels = [];

  if (run.status === 'Running') {
    const message = h('p', { class: 'form-message', role: 'status' });
    const status = select('status', FINAL_STATUSES, 'Success');
    const note = h('input', { name: 'message', placeholder: 'Optional' });
    const button = h('button', { type: 'submit' }, 'Finish run');
    panels.push(h('form', {
      class: 'panel form-stack',
      onsubmit: async (event) => {
        event.preventDefault();
        button.disabled = true;
        try {
          // endTimeUtc is omitted, so the server records the current time.
          await api('PATCH', `/runs/${run.runId}`, { status: status.value, message: note.value || null });
          render();
        } catch (error) {
          message.className = 'form-message error';
          message.textContent = error.message;
          button.disabled = false;
        }
      },
    },
      h('h2', {}, 'Finish this run'),
      field('Final status', status),
      field('Message', note),
      message,
      h('div', {}, button)
    ));
  }

  const message = h('p', { class: 'form-message', role: 'status' });
  const level = select('level', LOG_LEVELS, 'Information');
  const eventName = h('input', { name: 'eventName', maxlength: 200, placeholder: 'For example, VerifyBackup' });
  const logText = h('textarea', { name: 'message' });
  const button = h('button', { type: 'submit' }, 'Add log entry');
  panels.push(h('form', {
    class: 'panel form-stack',
    onsubmit: async (event) => {
      event.preventDefault();
      button.disabled = true;
      try {
        await api('POST', `/runs/${run.runId}/logs`, {
          level: level.value,
          eventName: eventName.value.trim() || null,
          message: logText.value || null,
        });
        message.className = 'form-message success';
        message.textContent = 'Log entry added.';
        eventName.value = '';
        logText.value = '';
        refreshLogs();
      } catch (error) {
        message.className = 'form-message error';
        message.textContent = error.message;
      }
      button.disabled = false;
    },
  },
    h('h2', {}, 'Add a log entry'),
    h('div', { class: 'form-row' }, field('Level', level), field('Event name', eventName)),
    field('Message', logText),
    message,
    h('div', {}, button)
  ));

  return h('div', { class: 'actions' }, panels);
}

/* ======================================================================
 * Start a run (write scope)
 * ==================================================================== */

async function newRunView() {
  if (!canWrite()) {
    return h('section', {}, h('h1', {}, 'Start a run'),
      notice('Your account has read-only access. Sign in with a read-write account to start runs.'));
  }
  const { data: scripts } = await api('GET', '/scripts');

  const message = h('p', { class: 'form-message error', role: 'alert' });
  const scriptName = h('input', { name: 'scriptName', list: 'script-names', required: true, maxlength: 200 });
  const inputs = {
    scriptVersion: h('input', { name: 'scriptVersion', maxlength: 50 }),
    computerName: h('input', { name: 'computerName', maxlength: 255 }),
    userName: h('input', { name: 'userName', maxlength: 255 }),
    team: h('input', { name: 'team', maxlength: 100 }),
    owner: h('input', { name: 'owner', maxlength: 200 }),
  };
  const button = h('button', { type: 'submit' }, 'Start run');

  const form = h('form', {
    class: 'form-stack',
    onsubmit: async (event) => {
      event.preventDefault();
      button.disabled = true;
      message.textContent = '';
      const body = { scriptName: scriptName.value.trim(), startTimeUtc: new Date().toISOString() };
      for (const [key, input] of Object.entries(inputs)) {
        if (input.value.trim()) body[key] = input.value.trim();
      }
      try {
        const { data } = await api('POST', '/runs', body);
        location.hash = `#/runs/${data.runId}`;
      } catch (error) {
        message.textContent = error.message;
        button.disabled = false;
      }
    },
  },
    field('Script name', scriptName),
    h('datalist', { id: 'script-names' }, scripts.map((s) => h('option', { value: s.scriptName }))),
    h('div', { class: 'form-row' }, field('Script version', inputs.scriptVersion), field('Computer', inputs.computerName)),
    h('div', { class: 'form-row' }, field('User', inputs.userName), field('Team', inputs.team)),
    field('Owner', inputs.owner),
    message,
    h('div', {}, button)
  );

  return h('section', {},
    h('a', { class: 'back', href: '#/runs' }, 'Back to runs'),
    h('h1', {}, 'Start a run'),
    h('p', { class: 'subtitle' },
      'Records a new run with the current time as its start. A script name that is not in the catalog is added automatically.'),
    form
  );
}

/* ======================================================================
 * Scripts
 * ==================================================================== */

async function scriptsView() {
  const { data: scripts } = await api('GET', '/scripts');
  return h('section', {},
    h('h1', {}, 'Scripts'),
    h('p', { class: 'subtitle' }, 'Every script that has reported a run. Scripts are added the first time they run.'),
    scripts.length
      ? table([['Script'], ['Team'], ['Owner'], ['Repository'], ['Registered']],
          scripts.map((s) => h('tr', {},
            h('td', {}, h('a', { href: `#/scripts/${s.scriptId}` }, s.scriptName)),
            h('td', {}, text(s.team)),
            h('td', {}, text(s.owner)),
            h('td', {}, safeLink(s.repository)),
            h('td', { class: 'nowrap' }, time(s.createdDateUtc))
          )))
      : emptyState('No scripts have reported a run yet.')
  );
}

async function scriptDetailView(params, scriptId) {
  const { data: s } = await api('GET', `/scripts/${scriptId}`);
  const counts = [
    [s.totalExecutions, 'Total runs'],
    [s.successfulExecutions, 'Succeeded'],
    [s.failedExecutions, 'Failed'],
    [s.cancelledExecutions, 'Cancelled'],
    [s.runningExecutions, 'Running now'],
  ];
  return h('section', {},
    h('a', { class: 'back', href: '#/scripts' }, 'Back to scripts'),
    h('h1', {}, s.scriptName),
    h('p', { class: 'subtitle' }, text(s.description)),
    h('ul', { class: 'counts' }, counts.map(([n, label]) => h('li', {}, h('strong', {}, n), h('span', {}, label)))),
    h('p', {}, h('a', { href: `#/runs?scriptId=${s.scriptId}` }, 'View all runs of this script')),
    h('dl', { class: 'facts' },
      [
        ['Team', text(s.team)],
        ['Owner', text(s.owner)],
        ['Repository', safeLink(s.repository)],
        ['Registered', time(s.createdDateUtc)],
        ['Last updated', time(s.modifiedDateUtc)],
        ['Latest run', s.latestRun
          ? h('span', {}, statusBadge(s.latestRun.status), ' ',
              h('a', { href: `#/runs/${s.latestRun.runId}` }, time(s.latestRun.startTimeUtc)))
          : 'No runs yet'],
      ].map(([label, value]) => h('div', {}, h('dt', {}, label), h('dd', {}, value)))
    )
  );
}

/* ======================================================================
 * Reports
 * ==================================================================== */

async function reportsView(params) {
  const filters = {
    from: params.get('from') || '',
    to: params.get('to') || '',
    team: params.get('team') || '',
  };
  const { data: scripts } = await api('GET', '/scripts');
  const teams = [...new Set(scripts.map((s) => s.team).filter(Boolean))].sort();

  const form = h('form', {
    class: 'filters',
    onsubmit: (event) => {
      event.preventDefault();
      location.hash = `#/reports${queryString(Object.fromEntries(new FormData(event.target)))}`;
    },
  },
    field('From', h('input', { type: 'date', name: 'from', value: filters.from })),
    field('To', h('input', { type: 'date', name: 'to', value: filters.to })),
    field('Team', select('team', teams, filters.team, 'All teams')),
    h('button', { type: 'submit' }, 'Update reports'),
    Object.values(filters).some(Boolean) ? h('a', { class: 'button button-quiet', href: '#/reports' }, 'Clear') : null
  );

  let body;
  try {
    const [{ data: summary }, { data: failing }] = await Promise.all([
      api('GET', `/reports/execution-summary${queryString(filters)}`),
      api('GET', `/reports/failing-scripts${queryString({ ...filters, limit: 10 })}`),
    ]);
    body = [summarySection(summary), failingSection(failing)];
  } catch (error) {
    if (error.status === 401) throw error;
    body = [notice(error.message)];
  }

  return h('section', {},
    h('h1', {}, 'Reports'),
    h('p', { class: 'subtitle' }, 'How runs are going overall, and which scripts fail most often.'),
    form,
    body
  );
}

function summarySection(s) {
  if (s.totalExecutions === 0) {
    return h('div', {}, h('h2', {}, 'Execution summary'), emptyState('No runs started in this period.'));
  }
  const parts = [
    ['success', s.successfulExecutions, 'Succeeded'],
    ['failed', s.failedExecutions, 'Failed'],
    ['cancelled', s.cancelledExecutions, 'Cancelled'],
    ['running', s.runningExecutions, 'Running'],
  ];
  return h('div', {},
    h('h2', {}, 'Execution summary'),
    h('div', { class: 'headline-stats' },
      h('p', {}, h('strong', {}, s.totalExecutions), 'runs'),
      h('p', {}, h('strong', {}, percent(s.successRate)), 'success rate of finished runs'),
      h('p', {}, h('strong', {}, duration(s.averageDurationMs)), 'average duration')
    ),
    h('div', { class: 'summary-bar', role: 'img',
      'aria-label': parts.map(([, n, label]) => `${label}: ${n}`).join(', ') },
      parts.filter(([, n]) => n > 0).map(([key, n, label]) =>
        h('span', { class: `bar-${key}`, style: `width: ${(n / s.totalExecutions) * 100}%`, title: `${label}: ${n}` }))
    ),
    h('ul', { class: 'legend' }, parts.map(([key, n, label]) =>
      h('li', {}, h('i', { class: `bar-${key}` }), `${label}: ${n}`)))
  );
}

function failingSection(failing) {
  return h('div', {},
    h('h2', {}, 'Scripts with the most failures'),
    failing.length
      ? table([['Script'], ['Team'], ['Failures', 'num'], ['Runs', 'num'], ['Failure rate', 'num'], ['Last failure']],
          failing.map((f) => h('tr', {},
            h('td', {}, h('a', { href: `#/runs?scriptId=${f.scriptId}&status=Failed` }, f.scriptName)),
            h('td', {}, text(f.team)),
            h('td', { class: 'num' }, f.failureCount),
            h('td', { class: 'num' }, f.totalExecutions),
            h('td', { class: 'num' }, percent(f.failureRate)),
            h('td', { class: 'nowrap' }, time(f.lastFailureUtc))
          )))
      : emptyState('No script failed in this period.')
  );
}

/* ======================================================================
 * Start
 * ==================================================================== */

document.getElementById('sign-out').addEventListener('click', () => signOut('You have signed out.'));
window.addEventListener('hashchange', render);
updateHeader();
render();
