/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * A deliberately small admin client. It does not import the social app or its
 * card layout; every view is a dense table or form that fits a desktop screen.
 */
(() => {
    'use strict';

    const csrf = document.documentElement.dataset.csrf || '';
    const view = document.getElementById('admin-view');
    const title = document.getElementById('admin-title');
    const errorBox = document.getElementById('admin-error');
    const refresh = document.getElementById('admin-refresh');
    const nav = [...document.querySelectorAll('[data-section]')];
    const labels = {
        overview: 'Overview',
        users: 'Accounts',
        reports: 'Reports',
        queue: 'Queue',
        errors: 'Errors',
        moderation: 'Moderation',
        settings: 'Settings',
        audit: 'Audit log',
    };
    let section = location.hash.replace(/^#\/?/, '') || 'overview';
    if (!labels[section]) section = 'overview';

    const asArray = (value) => Array.isArray(value) ? value : [];

    const esc = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');

    async function api(method, path, body) {
        const headers = { Accept: 'application/json' };
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (method !== 'GET' && csrf !== '') headers['X-CSRF-Token'] = csrf;
        const response = await fetch('/api/v1/host/admin' + path, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const text = await response.text();
        let payload = null;
        try { payload = text === '' ? null : JSON.parse(text); } catch { payload = { error: { message: text } }; }
        if (!response.ok) {
            throw new Error(payload?.error?.message || 'The admin request failed.');
        }
        return payload;
    }

    function setSection(next) {
        section = labels[next] ? next : 'overview';
        title.textContent = labels[section];
        nav.forEach((button) => button.classList.toggle('is-active', button.dataset.section === section));
        if (location.hash !== '#/' + section) history.replaceState(null, '', '#/' + section);
        void load();
    }

    function showError(error) {
        errorBox.textContent = error instanceof Error ? error.message : String(error);
        errorBox.hidden = false;
    }

    function clearError() {
        errorBox.hidden = true;
        errorBox.textContent = '';
    }

    function table(headers, rows) {
        // Callers build a row string with map(...).join(''); accept both a
        // string and an array so a single view cannot crash the console.
        const body = Array.isArray(rows) ? rows.join('') : String(rows ?? '');
        return '<div class="admin-table-wrap"><table class="admin-table"><thead><tr>'
            + headers.map((header) => '<th>' + esc(header) + '</th>').join('')
            + '</tr></thead><tbody>' + body + '</tbody></table></div>';
    }

    function stat(label, value) {
        return '<div class="admin-stat"><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong></div>';
    }

    async function overview() {
        const data = await api('GET', '/overview');
        if (!data || !data.stats) throw new Error('The overview response is incomplete.');
        const s = data.stats;
        return '<section class="admin-section"><h2>Instance at a glance</h2>'
            + '<div class="admin-grid">'
            + stat('Accounts', s.accounts.total) + stat('Active', s.accounts.active)
            + stat('Posts', s.posts.total) + stat('Public', s.posts.public)
            + stat('Remote posts', s.federation.remote_posts) + stat('Queue', s.queue.pending)
            + stat('Open reports', s.reports.open) + stat('Errors 24h', s.errors.recent)
            + '</div>'
            + '<div class="admin-split"><div><h2>Health</h2><ul class="admin-list">'
            + asArray(data.health).map((check) => '<li><span>' + esc(check.label) + '</span><strong class="'
                + (check.status === 'ok' ? 'admin-ok' : 'admin-bad') + '">' + esc(check.status) + '</strong></li>').join('')
            + '</ul></div><div><h2>Recent audit</h2><ul class="admin-list">'
            + asArray(data.audit).slice(0, 8).map((entry) => '<li><span>' + esc(entry.action) + '</span><small class="admin-muted">'
                + esc(entry.created_at) + '</small></li>').join('')
            + '</ul></div></div></section>';
    }

    async function users() {
        const data = await api('GET', '/users?per_page=50');
        return '<section class="admin-section"><h2>Accounts</h2>'
            + '<p class="admin-lead">' + data.total + ' accounts. Disabled accounts cannot sign in.</p>'
            + table(['Handle', 'Email', 'Role', 'Posts', 'Followers', 'Following', 'State', ''], data.users.map((user) =>
                '<tr><td><strong>@' + esc(user.handle) + '</strong><br><small class="admin-muted">' + esc(user.display_name || '') + '</small></td>'
                + '<td class="admin-mono">' + esc(user.email) + '</td><td>' + esc(user.role) + '</td>'
                + '<td>' + esc(user.posts) + '</td><td>' + esc(user.followers) + '</td><td>' + esc(user.following) + '</td>'
                + '<td>' + (user.disabled_at ? '<span class="admin-bad">disabled</span>' : '<span class="admin-ok">active</span>') + '</td>'
                + '<td class="actions"><button type="button" class="admin-button admin-button--small" data-action="toggle-user" data-id="'
                + esc(user.id) + '" data-disabled="' + (user.disabled_at ? '1' : '0') + '">'
                + (user.disabled_at ? 'Enable' : 'Disable') + '</button></td></tr>').join(''))
            + '<h2 style="margin-top:26px">Create account</h2><form id="create-user" class="admin-form admin-split">'
            + '<fieldset><legend>Identity</legend><label>Handle<input name="handle" required pattern="[a-z0-9][a-z0-9_-]{1,29}"></label>'
            + '<label>Email<input name="email" type="email" required></label>'
            + '<label>Display name<input name="display_name" maxlength="80"></label></fieldset>'
            + '<fieldset><legend>Access</legend><label>Password<input name="password" type="password" minlength="8" required></label>'
            + '<label>Role<select name="role"><option value="user">User</option><option value="admin">Administrator</option></select></label>'
            + '<button class="admin-button" type="submit">Create account</button></fieldset></form></section>';
    }

    async function reports() {
        const data = await api('GET', '/reports?state=open');
        return '<section class="admin-section"><h2>Open reports</h2><p class="admin-lead">'
            + data.counts.open + ' open, ' + data.counts.resolved + ' resolved, ' + data.counts.dismissed + ' dismissed.</p>'
            + table(['Target', 'Category', 'Reporter', 'Comment', 'Created', ''], data.reports.map((report) =>
                '<tr><td><strong>' + esc(report.target_type) + '</strong><br><span class="admin-mono">' + esc(report.target_id) + '</span>'
                + (report.target?.content ? '<br><small class="admin-muted">' + esc(String(report.target.content).slice(0, 140)) + '</small>' : '') + '</td>'
                + '<td>' + esc(report.category) + '</td><td>@' + esc(report.reporter_handle) + '</td>'
                + '<td>' + esc(report.comment || '—') + '</td><td class="admin-mono">' + esc(report.created_at) + '</td>'
                + '<td class="actions"><button type="button" class="admin-button admin-button--small" data-action="report" data-id="'
                + esc(report.id) + '" data-state="resolved">Resolve</button>'
                + '<button type="button" class="admin-button admin-button--small admin-button--quiet" data-action="report" data-id="'
                + esc(report.id) + '" data-state="dismissed">Dismiss</button></td></tr>').join(''))
            + '</section>';
    }

    async function queue() {
        const data = await api('GET', '/jobs?limit=100');
        return '<section class="admin-section"><h2>Queue</h2><p class="admin-lead">Pending '
            + data.counts.pending + ', running ' + data.counts.running + ', dead ' + data.counts.dead + '.</p>'
            + '<div class="admin-actions" style="margin-bottom:14px">'
            + '<button class="admin-button" data-action="queue-run">Run one batch</button>'
            + '<button class="admin-button admin-button--quiet" data-action="queue-retry">Retry dead</button>'
            + '<button class="admin-button admin-button--danger" data-action="queue-purge">Purge dead</button></div>'
            + table(['Type', 'State', 'Attempts', 'Available', 'Last error', 'Payload'], data.jobs.map((job) =>
                '<tr><td class="admin-mono">' + esc(job.type) + '</td><td>' + esc(job.state) + '</td><td>' + esc(job.attempts)
                + '</td><td class="admin-mono">' + esc(job.available_at) + '</td><td class="admin-pre">'
                + esc(job.last_error || '') + '</td><td class="admin-pre">'
                + esc(JSON.stringify(job.payload).slice(0, 300)) + '</td></tr>').join(''))
            + '</section>';
    }

    async function errors() {
        const data = await api('GET', '/communications?limit=100');
        return '<section class="admin-section"><h2>Errors and delivery failures</h2><p class="admin-lead">'
            + data.counts.last_24h + ' entries in 24 hours.</p>'
            + table(['Level', 'Source', 'Message', 'Time'], data.errors.map((entry) =>
                '<tr><td class="' + (entry.level === 'error' || entry.level === 'critical' ? 'admin-bad' : '') + '">' + esc(entry.level)
                + '</td><td>' + esc(entry.source || '—') + '</td><td>' + esc(entry.message) + '</td><td class="admin-mono">'
                + esc(entry.created_at) + '</td></tr>').join('')) + '</section>';
    }

    async function moderation() {
        const data = await api('GET', '/moderation');
        const rows = asArray(data.actions).map((action) =>
            '<tr><td><strong>' + esc(action.kind) + '</strong><br><small class="admin-muted">' + esc(action.note || '') + '</small></td>'
            + '<td class="admin-mono">' + esc(action.target) + '</td><td>@' + esc(action.moderator_id) + '</td>'
            + '<td class="admin-mono">' + esc(action.created_at) + '</td>'
            + '<td>' + (action.reversed_at
                ? '<span class="admin-muted">reversed ' + esc(action.reversed_at) + '</span>'
                : '<button type="button" class="admin-button admin-button--small admin-button--quiet" data-action="moderation-reverse" data-id="'
                    + esc(action.id) + '">Reverse</button>') + '</td></tr>').join('');

        return '<section class="admin-section"><h2>Actor and domain policy</h2>'
            + '<p class="admin-lead">' + esc(data.active) + ' active actions. A blocked domain also stops outgoing federation to it.</p>'
            + table(['Action', 'Target', 'Moderator', 'Created', ''], rows)
            + '<h2 style="margin-top:26px">Apply moderation</h2><form id="moderation-form" class="admin-form">'
            + '<fieldset><legend>New action</legend>'
            + '<label>Action<select name="kind"><option value="block_domain">Block domain</option>'
            + '<option value="suspend_actor">Suspend actor</option><option value="silence_actor">Silence actor</option></select></label>'
            + '<label>Domain or actor URL<input name="target" required placeholder="example.com or https://example.com/@actor"></label>'
            + '<label>Note<input name="note" maxlength="2000"></label>'
            + '<button class="admin-button" type="submit">Apply action</button></fieldset></form></section>';
    }

    async function settings() {
        const data = await api('GET', '/settings');
        return '<section class="admin-section"><h2>Instance settings</h2>'
            + '<p class="admin-lead">Written to <code>' + esc(data.core.env_file) + '</code>. Most changes apply on the next request; a container restart is needed for the core runtime.</p>'
            + '<form id="settings-form" class="admin-form admin-split">'
            + '<div><fieldset><legend>Registration</legend>'
            + '<label class="admin-check"><input type="checkbox" name="registrations"' + (data.registrations.enabled ? ' checked' : '') + '> Allow public registration</label>'
            + '<label class="admin-check"><input type="checkbox" name="registration_approval"' + (data.registrations.approval ? ' checked' : '') + '> Require administrator approval</label>'
            + '</fieldset><fieldset><legend>Core dependency</legend>'
            + '<label>Channel<select name="core_channel"><option value="main"' + (data.core.channel === 'main' ? ' selected' : '') + '>main branch</option><option value="release"' + (data.core.channel === 'release' ? ' selected' : '') + '>tagged release</option></select></label>'
            + '<label>Release version<input name="core_version" value="' + esc(data.core.version) + '" placeholder="0.7.0"></label>'
            + '<small>Installed: ' + esc(data.core.installed) + '. After saving, run <code>' + esc(data.core.apply_command) + '</code> on the host (or rebuild the Docker image).</small>'
            + '</fieldset></div><div><fieldset><legend>Native AT Protocol PDS</legend>'
            + '<label class="admin-check"><input type="checkbox" name="atproto_pds"' + (data.atproto.pds_enabled ? ' checked' : '') + '> Enable native PDS</label>'
            + '<label>Public PDS URL<input name="atproto_pds_url" value="' + esc(data.atproto.pds_url) + '"></label>'
            + '<label>Handle domain<input name="atproto_pds_handle_domain" value="' + esc(data.atproto.handle_domain) + '"></label>'
            + '<label>PLC directory<input name="atproto_plc_url" value="' + esc(data.atproto.plc_url) + '"></label>'
            + '<small>Members can then create their own did:plc identity and handle, not only link Bluesky.</small>'
            + '</fieldset><button class="admin-button" type="submit">Save settings</button></div></form></section>';
    }

    async function audit() {
        const data = await api('GET', '/audit?limit=200');
        return '<section class="admin-section"><h2>Audit log</h2>'
            + table(['Time', 'Actor', 'Action', 'Target', 'Details'], data.entries.map((entry) =>
                '<tr><td class="admin-mono">' + esc(entry.created_at) + '</td><td>@' + esc(entry.actor_id) + '</td><td>'
                + esc(entry.action) + '</td><td>' + esc(entry.target_type || '—') + ' ' + esc(entry.target_id || '') + '</td>'
                + '<td class="admin-pre">' + esc(JSON.stringify(entry.details)) + '</td></tr>').join('')) + '</section>';
    }

    const loaders = { overview, users, reports, queue, errors, moderation, settings, audit };

    async function load() {
        clearError();
        view.innerHTML = '<p class="admin-loading">Loading…</p>';
        try {
            view.innerHTML = await loaders[section]();
        } catch (error) {
            view.innerHTML = '';
            showError(error);
        }
    }

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        const action = button.dataset.action;
        button.disabled = true;
        clearError();
        try {
            if (action === 'toggle-user') {
                await api('POST', '/users/' + encodeURIComponent(button.dataset.id)
                    + (button.dataset.disabled === '1' ? '/enable' : '/disable'));
            } else if (action === 'report') {
                await api('POST', '/reports/' + encodeURIComponent(button.dataset.id) + '/resolve', {
                    state: button.dataset.state,
                });
            } else if (action === 'queue-run') {
                await api('POST', '/queue/run');
            } else if (action === 'queue-retry') {
                await api('POST', '/jobs/retry-dead');
            } else if (action === 'queue-purge') {
                await api('POST', '/jobs/purge');
            } else if (action === 'moderation-reverse') {
                await api('POST', '/moderation/' + encodeURIComponent(button.dataset.id) + '/reverse', {});
            }
            await load();
        } catch (error) {
            showError(error);
            button.disabled = false;
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (form.id !== 'create-user' && form.id !== 'settings-form' && form.id !== 'moderation-form') return;
        event.preventDefault();
        clearError();
        const data = new FormData(form);
        try {
            if (form.id === 'create-user') {
                await api('POST', '/users', {
                    handle: data.get('handle'),
                    email: data.get('email'),
                    display_name: data.get('display_name') || undefined,
                    password: data.get('password'),
                    role: data.get('role'),
                });
            } else if (form.id === 'moderation-form') {
                await api('POST', '/moderation', {
                    kind: data.get('kind'),
                    target: data.get('target'),
                    note: data.get('note') || undefined,
                });
            } else {
                await api('POST', '/settings', {
                    registrations: data.has('registrations'),
                    registration_approval: data.has('registration_approval'),
                    core_channel: data.get('core_channel'),
                    core_version: data.get('core_version'),
                    atproto_pds: data.has('atproto_pds'),
                    atproto_pds_url: data.get('atproto_pds_url'),
                    atproto_pds_handle_domain: data.get('atproto_pds_handle_domain'),
                    atproto_plc_url: data.get('atproto_plc_url'),
                });
            }
            await load();
        } catch (error) {
            showError(error);
        }
    });

    nav.forEach((button) => button.addEventListener('click', () => setSection(button.dataset.section)));
    refresh.addEventListener('click', () => void load());
    window.addEventListener('hashchange', () => setSection(location.hash.replace(/^#\/?/, '')));

    setSection(section);
})();
