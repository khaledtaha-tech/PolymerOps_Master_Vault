/**
 * PolymerOps Master Vault - Smart Link Synergy & Correlation Engine
 * 
 * Bridges code repositories directly to encrypted vault credentials:
 * - Domain and normalized name cross-correlation (repo.homepage <-> account.url, repo.name <-> account.title)
 * - Automatic batch persistence of newly discovered correlations via api.php?resource=links&action=batch
 * - Decrypted credentials side-drawer with instant reveal, DB info, and API keys
 * - Reverse navigation and GitHub repo linking
 * - Dynamic badges for both Explorer table and Vault views
 */

import { api } from '../core/api-client.js';
import { toast } from '../ui/toast.js';
import { security } from '../core/security.js';
import { modals } from '../ui/modals.js';
import { vault } from './vault.js';
import { explorer } from './explorer.js';

export class SynergyModule {
  constructor() {
    this.links = [];
    this.activeRepoId = null;
    this.activeRepoName = '';
    this.activeDecryptedAccounts = [];
    this.bindDrawerEvents();
  }

  bindDrawerEvents() {
    // Close button
    document.getElementById('btnCloseCredentialsDrawer')?.addEventListener('click', () => {
      this.closeCredentialsDrawer();
    });

    // Backdrop click
    document.getElementById('credentialsDrawerBackdrop')?.addEventListener('click', () => {
      this.closeCredentialsDrawer();
    });

    // Attach button in drawer footer
    document.getElementById('btnDrawerAttachAccount')?.addEventListener('click', () => {
      if (this.activeRepoId) {
        const repo = explorer.repositories.find(r => r.id === this.activeRepoId);
        this.attachVaultModal(
          this.activeRepoId,
          this.activeRepoName,
          repo?.homepage || repo?.html_url || '',
          repo?.description || ''
        );
      }
    });

    // Open in vault button in drawer footer
    document.getElementById('btnDrawerOpenInVault')?.addEventListener('click', () => {
      if (this.activeRepoId) {
        this.openVaultForRepo(this.activeRepoId, this.activeRepoName);
      }
    });

    // Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        const drawer = document.getElementById('credentialsDrawer');
        if (drawer && !drawer.classList.contains('invisible')) {
          this.closeCredentialsDrawer();
        }
      }
    });
  }

  async loadLinks() {
    try {
      const data = await api.get('api.php?resource=links');
      if (data.ok) {
        this.links = data.links || [];
      }
    } catch (_) {
      // Gracefully silent if DB is offline
    }
  }

  getLinksForRepo(repoId) {
    const id = parseInt(repoId);
    return this.links.filter(l => parseInt(l.repository_id) === id);
  }

  getLinksForAccount(accountId) {
    const id = String(accountId);
    return this.links.filter(l => String(l.account_id) === id);
  }

  isRepoLinked(repoId) {
    return this.getLinksForRepo(repoId).length > 0;
  }

  getSecuredReposCount(repositories = []) {
    if (!Array.isArray(repositories) || repositories.length === 0) return 0;
    const linkedRepoIds = new Set(this.links.map(l => parseInt(l.repository_id)));
    return repositories.filter(r => linkedRepoIds.has(parseInt(r.id))).length;
  }

  normalizeDomain(url) {
    if (!url) return '';
    try {
      let u = String(url).trim();
      if (!u) return '';
      if (!/^https?:\/\//i.test(u)) {
        u = 'https://' + u;
      }
      const parsed = new URL(u);
      let host = parsed.hostname.toLowerCase();
      if (host.startsWith('www.')) host = host.substring(4);
      return host;
    } catch (_) {
      return String(url).toLowerCase().replace(/^(https?:\/\/)?(www\.)?/, '').split('/')[0].split(':')[0].trim();
    }
  }

  normalizeName(name) {
    if (!name) return '';
    return String(name).toLowerCase().replace(/[^a-z0-9]/g, '');
  }

  async correlate(repositories = [], accounts = []) {
    if (!Array.isArray(repositories) || !Array.isArray(accounts) || repositories.length === 0 || accounts.length === 0) {
      return 0;
    }

    const existingPairSet = new Set(
      this.links.map(l => `${parseInt(l.repository_id)}_${String(l.account_id)}`)
    );

    const genericDomains = new Set([
      'localhost', '127.0.0.1', 'github.com', 'api.github.com',
      'vercel.app', 'netlify.app', 'github.io', 'onrender.com', 'railway.app'
    ]);

    const newDiscovered = [];

    for (const repo of repositories) {
      const repoDomain = this.normalizeDomain(repo.homepage);
      const repoNormName = this.normalizeName(repo.name);

      for (const acc of accounts) {
        const pairKey = `${parseInt(repo.id)}_${String(acc.id)}`;
        if (existingPairSet.has(pairKey)) continue;

        let isMatch = false;
        let matchNature = 'PRIMARY_HOSTING';

        // 1. Domain match check
        const accDomain = this.normalizeDomain(acc.url);
        if (repoDomain && accDomain && repoDomain.length > 3 && accDomain.length > 3) {
          if (!genericDomains.has(repoDomain) && !genericDomains.has(accDomain)) {
            if (repoDomain === accDomain || repoDomain.endsWith('.' + accDomain) || accDomain.endsWith('.' + repoDomain)) {
              isMatch = true;
              matchNature = 'PRIMARY_HOSTING';
            }
          }
        }

        // 2. Normalized Name match check
        if (!isMatch && repoNormName.length >= 4) {
          const accNormTitle = this.normalizeName(acc.title);
          if (accNormTitle.length >= 4) {
            if (repoNormName === accNormTitle) {
              isMatch = true;
            } else if (accNormTitle.includes(repoNormName) || (accNormTitle.length > 5 && repoNormName.includes(accNormTitle))) {
              isMatch = true;
            }
          }
        }

        if (isMatch) {
          existingPairSet.add(pairKey);
          newDiscovered.push({
            repositoryId: parseInt(repo.id),
            accountId: String(acc.id),
            linkNature: matchNature,
            repoName: repo.name,
            repoUrl: repo.html_url || ('https://github.com/' + repo.name),
          });
        }
      }
    }

    if (newDiscovered.length > 0) {
      try {
        const res = await api.post('api.php?resource=links&action=batch', { links: newDiscovered });
        if (res.ok) {
          toast.success(`Auto-correlated ${newDiscovered.length} repository credentials`);
          await this.loadLinks();
          explorer.render();
          return newDiscovered.length;
        }
      } catch (_) {
        // Gracefully silent if database is not active
      }
    }

    return 0;
  }

  async openCredentialsDrawer(repoId, repoName = '') {
    const id = parseInt(repoId);
    this.activeRepoId = id;
    this.activeRepoName = repoName || `Repo #${id}`;

    const drawer = document.getElementById('credentialsDrawer');
    const backdrop = document.getElementById('credentialsDrawerBackdrop');
    const panel = document.getElementById('credentialsDrawerPanel');
    const titleEl = document.getElementById('credentialsDrawerTitle');
    const subtitleEl = document.getElementById('credentialsDrawerSubtitle');
    const bodyEl = document.getElementById('credentialsDrawerBody');

    if (!drawer || !panel || !bodyEl) return;

    // Update titles
    if (titleEl) titleEl.textContent = this.activeRepoName;
    if (subtitleEl) subtitleEl.textContent = 'Decrypted system logins, DB info, and API tokens';

    // Open drawer UI
    drawer.classList.remove('invisible');
    drawer.classList.add('visible');
    setTimeout(() => {
      backdrop?.classList.remove('opacity-0');
      backdrop?.classList.add('opacity-100');
      panel.classList.remove('translate-x-full');
      panel.classList.add('translate-x-0');
    }, 10);

    // Loading state
    bodyEl.innerHTML = `
      <div class="space-y-3">
        <div class="h-24 bg-[#0d1117] rounded-xl border border-[#21262d] skeleton"></div>
        <div class="h-24 bg-[#0d1117] rounded-xl border border-[#21262d] skeleton"></div>
      </div>
    `;

    try {
      const res = await api.get(`api.php?resource=links&action=details&repo_id=${id}`);
      if (res.ok) {
        this.activeDecryptedAccounts = res.accounts || [];
        this.renderDrawerAccounts();
      } else {
        bodyEl.innerHTML = `
          <div class="text-center py-10 bg-[#0d1117] border border-rose-900/60 rounded-xl p-6">
            <p class="text-xs text-rose-400">${res.error || 'Failed to fetch credentials'}</p>
          </div>
        `;
      }
    } catch (err) {
      if (err.status === 401) {
        bodyEl.innerHTML = `
          <div class="text-center py-10 bg-[#0d1117] border border-[#30363d] rounded-xl p-6">
            <svg class="w-10 h-10 text-amber-400 mx-auto mb-2" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
            <h4 class="text-xs font-bold text-[#e6edf3]">Authentication Required</h4>
            <p class="text-[11px] text-[#8b949e] mt-1 mb-3">Sign in to decrypt credentials for ${this.activeRepoName}.</p>
            <button id="btnDrawerSignIn" class="btn-primary text-xs py-1.5 px-3">Sign In</button>
          </div>
        `;
        document.getElementById('btnDrawerSignIn')?.addEventListener('click', () => {
          this.closeCredentialsDrawer();
          modals.open('authModal');
        });
      } else {
        bodyEl.innerHTML = `
          <div class="text-center py-10 bg-[#0d1117] border border-[#30363d] rounded-xl p-6">
            <p class="text-xs text-[#8b949e]">Unable to fetch credentials: ${err.message}</p>
          </div>
        `;
      }
    }
  }

  renderDrawerAccounts() {
    const bodyEl = document.getElementById('credentialsDrawerBody');
    if (!bodyEl) return;

    if (this.activeDecryptedAccounts.length === 0) {
      bodyEl.innerHTML = `
        <div class="text-center py-12 bg-[#0d1117] border border-[#30363d] rounded-xl p-6">
          <svg class="w-12 h-12 text-[#8b949e] mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
          <h4 class="text-sm font-semibold text-[#e6edf3]">No credentials linked</h4>
          <p class="text-xs text-[#8b949e] mt-1 mb-4">Attach an existing account or create a new one for this repository.</p>
          <button id="btnDrawerEmptyAttach" class="btn-primary text-xs py-1.5 px-3">+ Attach Vault Account</button>
        </div>
      `;

      document.getElementById('btnDrawerEmptyAttach')?.addEventListener('click', () => {
        const repo = explorer.repositories.find(r => r.id === this.activeRepoId);
        this.attachVaultModal(
          this.activeRepoId,
          this.activeRepoName,
          repo?.homepage || repo?.html_url || '',
          repo?.description || ''
        );
      });
      return;
    }

    let html = '';

    this.activeDecryptedAccounts.forEach((acc, idx) => {
      const isCritical = (acc.category === 'CRITICAL_DRIVE');

      html += `
        <div class="bg-[#0d1117] border ${isCritical ? 'border-rose-900/70 shadow-rose-950/20' : 'border-[#30363d]'} rounded-xl p-4 space-y-3 shadow-sm">
          
          <!-- Card Header -->
          <div class="flex items-start justify-between gap-2">
            <div>
              <div class="flex items-center gap-2 flex-wrap">
                <h4 class="font-bold text-sm text-[#e6edf3]">${acc.title}</h4>
                <span class="px-1.5 py-0.2 rounded text-[10px] bg-[#21262d] text-[#8b949e] border border-[#30363d]">${acc.sectorName || 'Independent'}</span>
                <span class="px-1.5 py-0.2 rounded text-[10px] bg-indigo-950/80 text-indigo-300 border border-indigo-800">${acc.linkNature}</span>
              </div>
              ${acc.url ? `
                <a href="${acc.url}" target="_blank" class="inline-flex items-center gap-1 text-xs text-[#58a6ff] hover:underline mt-1 truncate max-w-xs" title="${acc.url}">
                  <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                  <span class="truncate">${acc.url.replace(/^https?:\/\//, '')}</span>
                </a>
              ` : ''}
            </div>
            <button data-drawer-action="unlink" data-link-id="${acc.linkId}" class="p-1 text-[#8b949e] hover:text-rose-400 transition" title="Unlink account from this repo">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <!-- Credentials Field Box -->
          <div class="space-y-2 bg-[#161b22] p-3 rounded-lg border border-[#21262d] text-xs">
            
            <!-- Username -->
            <div class="flex items-center justify-between gap-2">
              <span class="text-[#8b949e] text-[11px] font-medium">Username / Login:</span>
              <div class="flex items-center gap-1.5">
                <span class="font-mono text-[#e6edf3] select-all truncate max-w-[200px]">${acc.username || '(None)'}</span>
                ${acc.username ? `
                  <button data-drawer-copy="${acc.username}" data-label="Username" class="p-1 rounded text-[#8b949e] hover:text-[#e6edf3] hover:bg-[#30363d]" title="Copy Username">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  </button>
                ` : ''}
              </div>
            </div>

            <!-- Password -->
            <div class="flex items-center justify-between gap-2">
              <span class="text-[#8b949e] text-[11px] font-medium">Password:</span>
              <div class="flex items-center gap-1.5">
                <span id="drawerPass_${idx}" class="font-mono text-[#e6edf3] select-all truncate max-w-[180px]">••••••••••••</span>
                ${acc.password ? `
                  <button data-drawer-toggle-pass="drawerPass_${idx}" data-pass="${acc.password}" class="p-1 rounded text-[#8b949e] hover:text-[#e6edf3] hover:bg-[#30363d]" title="Toggle Reveal Password">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                  <button data-drawer-copy="${acc.password}" data-label="Password" class="p-1 rounded text-[#8b949e] hover:text-[#e6edf3] hover:bg-[#30363d]" title="Copy Password">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  </button>
                ` : ''}
              </div>
            </div>

          </div>

          <!-- Decrypted Database Info (if present) -->
          ${acc.dbInfo ? `
            <div class="space-y-1 bg-[#161b22] p-2.5 rounded-lg border border-[#21262d]">
              <div class="flex items-center justify-between text-[11px]">
                <span class="font-semibold text-emerald-400 flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                  <span>Database Credentials (Decrypted)</span>
                </span>
                <button data-drawer-copy="${acc.dbInfo}" data-label="Database Info" class="text-[10px] text-[#58a6ff] hover:underline">Copy DB Info</button>
              </div>
              <pre class="font-mono text-[11px] text-[#e6edf3] bg-[#0d1117] p-2 rounded border border-[#30363d] overflow-x-auto whitespace-pre-wrap select-all">${acc.dbInfo}</pre>
            </div>
          ` : ''}

          <!-- Decrypted API Key / Tokens (if present) -->
          ${acc.apiKey ? `
            <div class="space-y-1 bg-[#161b22] p-2.5 rounded-lg border border-[#21262d]">
              <div class="flex items-center justify-between text-[11px]">
                <span class="font-semibold text-amber-400 flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 2l-2 2m-2-2l2 2m0 0l-7 7m7-7l-7 7m-3 3l-4 4-2-2-2 2-2-2-2 2 4 4 10-10"/></svg>
                  <span>API Keys & Secrets (Decrypted)</span>
                </span>
                <button data-drawer-copy="${acc.apiKey}" data-label="API Key" class="text-[10px] text-[#58a6ff] hover:underline">Copy Key</button>
              </div>
              <pre class="font-mono text-[11px] text-[#e6edf3] bg-[#0d1117] p-2 rounded border border-[#30363d] overflow-x-auto whitespace-pre-wrap select-all">${acc.apiKey}</pre>
            </div>
          ` : ''}

          <!-- Account Notes (if present) -->
          ${acc.notes ? `
            <div class="text-[11px] text-[#8b949e] bg-[#161b22] p-2 rounded border border-[#21262d]">
              <span class="font-semibold text-[#e6edf3]">Notes:</span> ${acc.notes}
            </div>
          ` : ''}

          <!-- Card Actions Footer -->
          <div class="flex items-center justify-between pt-1 text-xs">
            <span class="text-[10px] text-[#8b949e]">Auth: ${acc.authType}</span>
            <button data-drawer-open-vault="${acc.id}" class="inline-flex items-center gap-1 text-[11px] text-[#58a6ff] hover:underline">
              <span>View full account in Vault &rarr;</span>
            </button>
          </div>

        </div>
      `;
    });

    bodyEl.innerHTML = html;

    // Bind drawer copy actions
    bodyEl.querySelectorAll('[data-drawer-copy]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const val = btn.dataset.drawerCopy;
        const label = btn.dataset.label || 'Value';
        await security.copyToClipboard(val);
        toast.success(`Copied ${label} to clipboard`);
      });
    });

    // Bind password reveal/hide toggles
    bodyEl.querySelectorAll('[data-drawer-toggle-pass]').forEach(btn => {
      btn.addEventListener('click', () => {
        const targetId = btn.dataset.drawerTogglePass;
        const pass = btn.dataset.pass;
        const targetEl = document.getElementById(targetId);
        if (targetEl) {
          const isMasked = targetEl.textContent.startsWith('••••');
          targetEl.textContent = isMasked ? pass : '••••••••••••';
        }
      });
    });

    // Bind unlink action
    bodyEl.querySelectorAll('[data-drawer-action="unlink"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const linkId = btn.dataset.linkId;
        if (confirm('Unlink this credential from repository?')) {
          await this.removeLink(linkId);
          await this.openCredentialsDrawer(this.activeRepoId, this.activeRepoName);
          explorer.render();
        }
      });
    });

    // Bind open in vault
    bodyEl.querySelectorAll('[data-drawer-open-vault]').forEach(btn => {
      btn.addEventListener('click', () => {
        this.openVaultForRepo(this.activeRepoId, this.activeRepoName);
      });
    });
  }

  closeCredentialsDrawer() {
    const drawer = document.getElementById('credentialsDrawer');
    const backdrop = document.getElementById('credentialsDrawerBackdrop');
    const panel = document.getElementById('credentialsDrawerPanel');

    if (!drawer || !panel) return;

    backdrop?.classList.remove('opacity-100');
    backdrop?.classList.add('opacity-0');
    panel.classList.remove('translate-x-0');
    panel.classList.add('translate-x-full');

    setTimeout(() => {
      drawer.classList.remove('visible');
      drawer.classList.add('invisible');
      this.activeRepoId = null;
      this.activeRepoName = '';
      this.activeDecryptedAccounts = [];
    }, 300);
  }

  attachVaultModal(repoId, repoName, repoUrl = '', repoDesc = '') {
    this.closeCredentialsDrawer();
    vault.openAccountModal(null, {
      title: repoName,
      url: repoUrl,
      notes: repoDesc ? `Repository: ${repoName}\n${repoDesc}` : `Repository: ${repoName}`,
      category: 'WORK',
      linkedRepoId: parseInt(repoId),
    });
  }

  openVaultForRepo(repoId, repoName = '') {
    this.closeCredentialsDrawer();
    window.dispatchEvent(new CustomEvent('polymer:switch-view', {
      detail: { view: 'vault', repoId: parseInt(repoId), repoName }
    }));
    toast.info(`Switched to Vault filtered for: ${repoName || 'Repo #' + repoId}`);
  }

  openRepoForAccount(repoId, repoName = '') {
    window.dispatchEvent(new CustomEvent('polymer:switch-view', {
      detail: { view: 'explorer', repoId: parseInt(repoId), repoName }
    }));
    toast.info(`Switched to Explorer for: ${repoName || 'Repo #' + repoId}`);
  }

  async createLink(repositoryId, accountId, linkNature = 'PRIMARY_HOSTING', repoName = '', repoUrl = '') {
    try {
      const res = await api.post('api.php?resource=links', {
        repositoryId: parseInt(repositoryId),
        accountId: String(accountId),
        linkNature,
        repoName,
        repoUrl,
      });

      if (res.ok) {
        toast.success('Repository linked to vault credential successfully');
        await this.loadLinks();
        explorer.render();
        return true;
      }
    } catch (err) {
      toast.error(`Link failed: ${err.message}`);
    }
    return false;
  }

  async removeLink(linkId) {
    try {
      const res = await api.delete(`api.php?resource=links&id=${encodeURIComponent(linkId)}`);
      if (res.ok) {
        toast.info('Vault link removed');
        await this.loadLinks();
        explorer.render();
        return true;
      }
    } catch (err) {
      toast.error(`Could not delete link: ${err.message}`);
    }
    return false;
  }
}

export const synergy = new SynergyModule();
