/**
 * BridgeBox USB Import — admin & teacher dashboards.
 *
 * Polls /usb/drives every 1.5s while idle, and /usb/progress every 1s while a
 * copy is in progress. Renders the live progress bar, the currently-copying
 * file, and the destination category folder.
 *
 * Virus scanning was removed at the request of the hardware engineer; the
 * extension blocklist on the server still prevents dangerous file types
 * (.exe, .bat, .dll, .ps1, etc.) from being copied.
 *
 * Vanilla JS only — no build step, drops straight into public/assets/js.
 */
(() => {
    const root = document.querySelector('[data-usb-panel]');
    if (!root) return;

    const urls = {
        drives: root.dataset.urlDrives || null,
        start: root.dataset.urlStart || null,
        progress: root.dataset.urlProgress || null,
        list: root.dataset.urlList || null,
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        || root.dataset.csrf
        || '';

    // Student variant has only the library URL — no drives / start / progress.
    const isReadOnly = !urls.drives;

    const $ = (sel) => root.querySelector(sel);

    const els = {
        drivesList: $('[data-usb-drives]'),
        empty: $('[data-usb-empty]'),
        clamWarn: $('[data-usb-clam-warning]'),
        progressBox: $('[data-usb-progress]'),
        progressBar: $('[data-usb-progress-bar]'),
        progressPct: $('[data-usb-progress-pct]'),
        progressMsg: $('[data-usb-progress-msg]'),
        currentFile: $('[data-usb-current-file]'),
        currentCat: $('[data-usb-current-cat]'),
        scanStatus: $('[data-usb-scan-status]'),
        library: $('[data-usb-library]'),
        libraryEmpty: $('[data-usb-library-empty]'),
        refreshBtn: $('[data-usb-refresh]'),
    };

    // The ClamAV warning is no longer relevant — virus scanning was removed.
    if (els.clamWarn) els.clamWarn.remove();
    // The scan-status pill is also no longer used.
    if (els.scanStatus) els.scanStatus.remove();

    const CATEGORY_META = {
        video:    { icon: 'fa-film',         label: 'Video',    folder: 'video folder' },
        audio:    { icon: 'fa-music',        label: 'Audio',    folder: 'audio folder' },
        document: { icon: 'fa-file-lines',   label: 'Document', folder: 'document folder' },
        image:    { icon: 'fa-image',        label: 'Image',    folder: 'image folder' },
        archive:  { icon: 'fa-file-zipper',  label: 'Archive',  folder: 'archive folder' },
        other:    { icon: 'fa-file',         label: 'Other',    folder: 'other folder' },
    };

    const escapeHtml = (s) => {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    };

    const fmtBytes = (n) => {
        if (typeof n !== 'number' || !isFinite(n)) return '—';
        const u = ['B','KB','MB','GB','TB'];
        let i = 0, v = n;
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return v.toFixed(1) + ' ' + u[i];
    };

    let pollTimer = null;
    let listTimer = null;
    let busy = false;

    const fetchJson = async (url, opts = {}) => {
        if (!url) throw new Error('No URL configured');
        const r = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(opts.headers || {}),
            },
            ...opts,
        });
        if (!r.ok && r.status !== 422) throw new Error('HTTP ' + r.status);
        return r.json();
    };

    const renderDrives = (drives) => {
        if (!els.drivesList) return;
        if (!drives || drives.length === 0) {
            els.drivesList.innerHTML = '';
            if (els.empty) els.empty.hidden = false;
            return;
        }
        if (els.empty) els.empty.hidden = true;

        els.drivesList.innerHTML = drives.map((d) => {
            const ins = d.inspect || { files: 0, size_human: '—' };
            const counts = ins.by_category || {};
            const tags = Object.keys(counts).map((c) => {
                const meta = CATEGORY_META[c] || CATEGORY_META.other;
                return `<span class="usb-tag"><i class="fa-solid ${meta.icon}"></i> ${counts[c]} ${escapeHtml(meta.label)}</span>`;
            }).join('');
            return `
              <div class="usb-drive">
                <div class="usb-drive-info">
                  <div class="usb-drive-icon"><i class="fa-solid fa-usb-drive" aria-hidden="true"></i><i class="fa-brands fa-usb" aria-hidden="true"></i></div>
                  <div>
                    <p class="usb-drive-name">${escapeHtml(d.label)}</p>
                    <span class="usb-drive-meta">${escapeHtml(d.path)} · ${escapeHtml(d.size_human)} · ${ins.files || 0} file(s)</span>
                    <div class="usb-tags">${tags}</div>
                  </div>
                </div>
                <button class="btn primary" type="button" data-usb-start="${escapeHtml(d.path)}" ${busy ? 'disabled' : ''}>
                  <i class="fa-solid fa-down-long"></i> Copy to BridgeBox
                </button>
              </div>`;
        }).join('');

        els.drivesList.querySelectorAll('[data-usb-start]').forEach((btn) => {
            btn.addEventListener('click', () => startImport(btn.dataset.usbStart));
        });
    };

    const renderProgress = (job) => {
        if (!job || !els.progressBox) {
            if (els.progressBox) els.progressBox.hidden = true;
            busy = false;
            return;
        }

        const active = ['starting','scanning','copying'].includes(job.status);
        busy = active;
        els.progressBox.hidden = false;

        const totalB = job.total_bytes || 0;
        const copiedB = job.copied_bytes || 0;
        const pct = totalB > 0 ? Math.min(100, Math.round((copiedB / totalB) * 100)) : (active ? 5 : 100);

        if (els.progressBar) els.progressBar.style.width = pct + '%';
        if (els.progressPct) els.progressPct.textContent = pct + '%';
        if (els.progressMsg) {
            const sub = totalB ? ` (${fmtBytes(copiedB)} / ${fmtBytes(totalB)})` : '';
            els.progressMsg.textContent = (job.message || '') + sub;
        }
        if (els.currentFile) {
            els.currentFile.textContent = job.current_file || (active ? '…' : 'Idle');
        }
        if (els.currentCat) {
            const meta = CATEGORY_META[job.current_category] || null;
            const folderLabel = job.current_folder || (meta ? meta.folder : null);
            if (folderLabel && meta) {
                // Show "→ image folder" so the user can see where each file is going.
                els.currentCat.innerHTML =
                    `<i class="fa-solid fa-folder-tree" aria-hidden="true"></i> ` +
                    `→ <i class="fa-solid ${meta.icon}" aria-hidden="true"></i> ${escapeHtml(folderLabel)}`;
            } else if (folderLabel) {
                els.currentCat.innerHTML =
                    `<i class="fa-solid fa-folder-tree" aria-hidden="true"></i> → ${escapeHtml(folderLabel)}`;
            } else {
                els.currentCat.textContent = '—';
            }
        }

        if (els.drivesList) {
            els.drivesList.querySelectorAll('[data-usb-start]').forEach((b) => { b.disabled = active; });
        }

        // When the import finishes, refresh the library
        if (!active) loadLibrary();
    };

    const renderLibrary = (items) => {
        if (!els.library) return;
        if (!items || items.length === 0) {
            els.library.innerHTML = '';
            if (els.libraryEmpty) els.libraryEmpty.hidden = false;
            return;
        }
        if (els.libraryEmpty) els.libraryEmpty.hidden = true;

        // Group items by category so the user sees clear folder buckets.
        const groups = {};
        items.forEach((it) => {
            const cat = it.category || 'other';
            if (!groups[cat]) groups[cat] = [];
            groups[cat].push(it);
        });

        // Order: video, audio, document, image, archive, other
        const order = ['video','audio','document','image','archive','other'];
        const html = order
            .filter((cat) => groups[cat] && groups[cat].length > 0)
            .map((cat) => {
                const meta = CATEGORY_META[cat] || CATEGORY_META.other;
                const cards = groups[cat].map((it) => `
                    <div class="usb-item">
                      <div class="usb-item-icon usb-cat-${escapeHtml(cat)}">
                        <i class="fa-solid ${meta.icon}" aria-hidden="true"></i>
                      </div>
                      <div class="usb-item-body">
                        <p class="usb-item-name" title="${escapeHtml(it.name)}">${escapeHtml(it.name)}</p>
                        <span class="usb-item-meta">${escapeHtml(it.size)}</span>
                      </div>
                      <a class="btn ghost btn-small" href="${escapeHtml(it.url)}" target="_blank" rel="noopener" title="Preview / play">
                        <i class="fa-solid fa-eye"></i>
                      </a>
                      <a class="btn ghost btn-small" href="${escapeHtml(it.url)}" download title="Download">
                        <i class="fa-solid fa-download"></i>
                      </a>
                    </div>`).join('');
                return `
                  <div class="usb-folder-group">
                    <h5 class="usb-folder-title">
                      <i class="fa-solid ${meta.icon}" aria-hidden="true"></i>
                      ${escapeHtml(meta.folder)}
                      <span class="usb-folder-count">${groups[cat].length}</span>
                    </h5>
                    <div class="usb-library-grid">${cards}</div>
                  </div>`;
            }).join('');

        els.library.innerHTML = html;
    };

    const loadDrives = async () => {
        if (!urls.drives) return;
        try {
            const data = await fetchJson(urls.drives);
            renderDrives(data.drives || []);
            renderProgress(data.job || null);
        } catch (e) { /* ignore */ }
    };

    const loadProgress = async () => {
        if (!urls.progress) return;
        try {
            const data = await fetchJson(urls.progress);
            renderProgress(data.job || null);
        } catch (e) { /* ignore */ }
    };

    const loadLibrary = async () => {
        if (!urls.list || !els.library) return;
        try {
            const data = await fetchJson(urls.list);
            renderLibrary(data.items || []);
        } catch (e) { /* ignore */ }
    };

    const startImport = async (drive) => {
        if (busy) return;
        if (!urls.start) return;
        try {
            const fd = new FormData();
            fd.append('drive', drive);
            fd.append('_token', csrf);
            const data = await fetchJson(urls.start, {
                method: 'POST',
                body: fd,
                headers: { 'X-CSRF-TOKEN': csrf },
            });
            if (!data.success) {
                alert(data.message || 'Could not start import.');
                return;
            }
            busy = true;
            await loadProgress();
        } catch (e) {
            alert('Failed to start import.');
        }
    };

    const startPolling = () => {
        stopPolling();
        if (!isReadOnly) {
            pollTimer = setInterval(() => {
                if (busy) loadProgress();
                else loadDrives();
            }, 1500);
        }
        if (urls.list) {
            listTimer = setInterval(() => { if (!busy) loadLibrary(); }, 15000);
        }
    };
    const stopPolling = () => {
        if (pollTimer) clearInterval(pollTimer);
        if (listTimer) clearInterval(listTimer);
    };

    if (els.refreshBtn) els.refreshBtn.addEventListener('click', loadDrives);

    if (!isReadOnly) loadDrives();
    loadLibrary();
    startPolling();
})();
