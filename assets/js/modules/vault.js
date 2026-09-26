/**
 * PolymerOps Master Vault - Credential Vault Module
 * 
 * Provides:
 * - Full multi-sector workspace with Access Groups sidebar
 * - Three view modes: Cards, List Table, and Graph Connections Topology
 * - Master Action Bar with Credential Pool, Generator, and Shared Password Rotation
 * - High-security PIN-isolated Critical Vault
 * - Smart Link Synergy to GitHub Repositories
 */

import { api } from '../core/api-client.js';
import { toast } from '../ui/toast.js';
import { security } from '../core/security.js';
import { modals } from '../ui/modals.js';
import { synergy } from './synergy.js';
import { explorer } from './explorer.js';

export class VaultModule {
  constructor() {
    this.sectors = [];
    this.accounts = [];
    this.credentialPool = [];
    this.links = [];
    this.filteredAccounts = [];

    this.activeSidebarView = 'all'; // 'all', 'shared', 'critical', or sectorId
    this.activeRepoFilter = null;
    this.activeRepoName = '';
    this.activeAuthFilter = 'ALL';
    this.googleOnly = false;
    this.searchQuery = '';
    this.viewMode = localStorage.getItem('polymer_vault_view_mode') || 'cards'; // 'cards', 'list', 'connections'
    this.revealAll = false;

    this.editingAccountId = null;
    this.editingSectorId = null;

    this.bindGlobalListeners();
  }

  bindGlobalListeners() {
    // Listen for synergy navigation events
    window.addEventListener('polymer:switch-view', (e) => {
      const { view, repoId, repoName } = e.detail || {};
      if (view === 'vault' && repoId) {
        this.filterByRepo(repoId, repoName);
      }
    });
  }

  toggleRevealAll() {
    this.revealAll = !this.revealAll;
    this.render();
    return this.revealAll;
  }

  filterByRepo(repoId, repoName = '') {
    this.activeRepoFilter = parseInt(repoId);
    this.activeRepoName = repoName || `Repo #${repoId}`;
    this.activeSidebarView = 'all';
    this.applyFilters();
    this.renderSidebar();
    this.render();
  }

  clearRepoFilter() {
    this.activeRepoFilter = null;
    this.activeRepoName = '';
    this.applyFilters();
    this.render();
  }

  async loadVault() {
    const container = document.getElementById('vaultContainer');
    if (!container) return;

    try {
      const data = await api.get('api.php?resource=vault&sub=all');
      if (data.ok && data.vault) {
        this.sectors = data.vault.sectors || [];
        this.accounts = data.vault.accounts || [];
        this.credentialPool = data.vault.credentialPool || [];
        this.links = data.vault.links || [];
        security.setUnlocked(data.vault.criticalUnlocked);

        this.applyFilters();
        this.renderSidebar();
        this.render();
      }
    } catch (err) {
      if (err.status === 401) {
        container.innerHTML = `
          <div class="text-center py-16 bg-[#161b22] border border-[#30363d] rounded-xl p-8">
            <svg class="w-12 h-12 text-[#d29922] mx-auto mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
            <h3 class="text-base font-bold text-[#e6edf3]">Authentication Required</h3>
            <p class="text-xs text-[#8b949e] mt-1 mb-4">Sign in to access your AES-256-GCM encrypted credential vault.</p>
            <button id="btnOpenAuthModal" class="btn-primary text-xs">Sign In / Register</button>
          </div>
        `;
        document.getElementById('btnOpenAuthModal')?.addEventListener('click', () => {
          modals.open('authModal');
        });
      } else {
        container.innerHTML = `
          <div class="text-center py-16 bg-[#161b22] border border-rose-900/60 rounded-xl p-8">
            <h3 class="text-base font-bold text-rose-400">Database Connection Offline</h3>
            <p class="text-xs text-[#8b949e] mt-1 mb-4">Relational database storage is unavailable. Please verify your MySQL credentials in config.php.</p>
            <button id="btnRetryVault" class="btn-secondary text-xs">Retry Connection</button>
          </div>
        `;
        document.getElementById('btnRetryVault')?.addEventListener('click', () => this.loadVault());
      }
    }
  }

  resolveCredentials(account) {
    const sector = this.sectors.find(s => s.id === account.sectorId);
    let username = '';
    if (account.logicRule === 'FULL_SECTOR' && sector) {
      username = sector.defaultUsername || '';
    } else {
      username = account.customUsername || account.mobileNumber || sector?.defaultUsername || '';
    }

    let password = '';
    switch (account.logicRule) {
      case 'FULL_SECTOR':
      case 'SECTOR_PASS_ONLY':
        password = sector?.defaultPassword || '';
        break;
      case 'SHARED_PASS':
        password = sector?.defaultPassword || account.customPassword || '';
        break;
      case 'CUSTOM':
      default:
        password = account.customPassword || '';
        break;
    }

    return { username, password, sectorName: sector?.name || 'Independent' };
  }

  applyFilters() {
    let list = [...this.accounts];

    // Filter by Smart Link Repo if active
    if (this.activeRepoFilter !== null) {
      const repoLinks = this.links.filter(l => parseInt(l.repository_id) === this.activeRepoFilter);
      const linkedAccountIds = repoLinks.map(l => String(l.account_id));
      list = list.filter(acc => linkedAccountIds.includes(String(acc.id)));
    }

    // Sidebar View
    if (this.activeSidebarView === 'shared') {
      const sharedSectorIds = this.sectors.filter(s => s.isSharedVault).map(s => s.id);
      list = list.filter(acc => acc.sharedWithTeam || sharedSectorIds.includes(acc.sectorId));
    } else if (this.activeSidebarView === 'critical') {
      list = list.filter(acc => acc.category === 'CRITICAL_DRIVE');
    } else if (this.activeSidebarView !== 'all') {
      list = list.filter(acc => acc.sectorId === this.activeSidebarView);
    }

    // Auth Type filter
    if (this.activeAuthFilter !== 'ALL') {
      list = list.filter(acc => acc.authType === this.activeAuthFilter);
    }

    // Google accounts only toggle
    if (this.googleOnly) {
      list = list.filter(acc => {
        const u = (acc.customUsername || '').toLowerCase();
        return acc.authType === 'GOOGLE_AUTH' || u.includes('@gmail.com') || u.includes('@google.com');
      });
    }

    // Search query
    if (this.searchQuery) {
      const q = this.searchQuery.toLowerCase();
      list = list.filter(acc => {
        const sector = this.sectors.find(s => s.id === acc.sectorId);
        const secName = sector ? sector.name.toLowerCase() : '';
        return (
          acc.title.toLowerCase().includes(q) ||
          (acc.url && acc.url.toLowerCase().includes(q)) ||
          (acc.customUsername && acc.customUsername.toLowerCase().includes(q)) ||
          (acc.notes && acc.notes.toLowerCase().includes(q)) ||
          secName.includes(q)
        );
      });
    }

    this.filteredAccounts = list;
  }

  renderSidebar() {
    const sidebarEl = document.getElementById('vaultSidebar');
    if (!sidebarEl) return;

    const totalCount = this.accounts.length;
    const sharedSectorIds = this.sectors.filter(s => s.isSharedVault).map(s => s.id);
    const sharedCount = this.accounts.filter(a => a.sharedWithTeam || sharedSectorIds.includes(a.sectorId)).length;
    const criticalCount = this.accounts.filter(a => a.category === 'CRITICAL_DRIVE').length;
    const isCriticalUnlocked = security.isCriticalUnlocked();

    let html = `
      <div class="space-y-4">
        <!-- Primary Views -->
        <div class="space-y-1">
          <div data-sidebar-view="all" class="sidebar-nav-item ${this.activeSidebarView === 'all' && !this.activeRepoFilter ? 'active' : ''}">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
              <span>All Accounts</span>
            </div>
            <span class="px-2 py-0.5 rounded-full text-[10px] bg-[#21262d] text-[#e6edf3] font-mono">${totalCount}</span>
          </div>

          <div data-sidebar-view="shared" class="sidebar-nav-item ${this.activeSidebarView === 'shared' ? 'active' : ''}">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 text-[#58a6ff]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <span>Shared Vault</span>
            </div>
            <span class="px-2 py-0.5 rounded-full text-[10px] bg-indigo-950 text-indigo-300 font-mono">${sharedCount}</span>
          </div>

          <div data-sidebar-view="critical" class="sidebar-nav-item ${this.activeSidebarView === 'critical' ? 'active' : ''}">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 ${isCriticalUnlocked ? 'text-emerald-400' : 'text-rose-400'}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span>Critical Vault</span>
            </div>
            <div class="flex items-center gap-1.5">
              <span class="px-1.5 py-0.2 rounded text-[10px] ${isCriticalUnlocked ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-rose-950 text-rose-400 border border-rose-800'}">
                ${isCriticalUnlocked ? 'Unlocked' : 'PIN Locked'}
              </span>
              <span class="px-1.5 py-0.5 rounded-full text-[10px] bg-[#21262d] text-[#e6edf3] font-mono">${criticalCount}</span>
            </div>
          </div>
        </div>

        <!-- Access Groups Panel -->
        <div class="pt-4 border-t border-[#30363d]">
          <div class="flex items-center justify-between px-2 mb-2">
            <span class="text-[11px] uppercase font-bold text-[#8b949e] tracking-wider">Access Groups</span>
            <button id="btnAddAccessGroup" class="p-1 rounded text-[#58a6ff] hover:bg-[#21262d]" title="Create New Access Group">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
          </div>

          <div class="space-y-0.5 max-h-[300px] overflow-y-auto">
            ${this.sectors.length === 0 ? `
              <div class="text-xs text-[#8b949e] px-2 py-3 text-center bg-[#0d1117] rounded-lg border border-[#21262d]">
                No access groups defined yet. Click + to add your first group (e.g. AI, DevOps).
              </div>
            ` : ''}

            ${this.sectors.map(sec => {
              const count = this.accounts.filter(a => a.sectorId === sec.id).length;
              const active = (this.activeSidebarView === sec.id);
              return `
                <div class="group flex items-center justify-between sidebar-nav-item ${active ? 'active' : ''}" data-sidebar-view="${sec.id}">
                  <div class="flex items-center gap-2 truncate">
                    <span class="w-2 h-2 rounded-full ${sec.isSharedVault ? 'bg-indigo-400' : 'bg-emerald-400'}"></span>
                    <span class="truncate">${sec.name}</span>
                  </div>
                  <div class="flex items-center gap-1">
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-[#21262d] text-[#8b949e] font-mono">${count}</span>
                    <button data-action="delete-sector" data-id="${sec.id}" data-name="${sec.name}" class="opacity-0 group-hover:opacity-100 p-1 text-[#8b949e] hover:text-rose-400 transition" title="Delete Access Group">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                </div>
              `;
            }).join('')}
          </div>
        </div>
      </div>
    `;

    sidebarEl.innerHTML = html;

    // Bind sidebar clicks
    sidebarEl.querySelectorAll('[data-sidebar-view]').forEach(item => {
      item.addEventListener('click', (e) => {
        if (e.target.closest('[data-action="delete-sector"]')) return;
        const view = item.dataset.sidebarView;
        this.activeSidebarView = view;
        this.activeRepoFilter = null; // Clear repo filter when switching views
        this.applyFilters();
        this.renderSidebar();
        this.render();
      });
    });

    // Add access group button
    document.getElementById('btnAddAccessGroup')?.addEventListener('click', () => {
      this.openSectorModal();
    });

    // Delete access group buttons
    sidebarEl.querySelectorAll('[data-action="delete-sector"]').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = btn.dataset.id;
        const name = btn.dataset.name;
        if (confirm(`Delete access group "${name}"? Accounts inside it will become independent.`)) {
          await this.deleteSector(id);
        }
      });
    });
  }

  render() {
    const container = document.getElementById('vaultContainer');
    if (!container) return;

    // Check empty state
    if (this.accounts.length === 0) {
      container.innerHTML = `
        <div class="text-center py-16 bg-[#161b22] border border-[#30363d] rounded-xl p-8">
          <svg class="w-12 h-12 text-[#8b949e] mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
          <h3 class="text-base font-bold text-[#e6edf3]">No accounts stored in vault</h3>
          <p class="text-xs text-[#8b949e] mt-1 mb-4">Store your first encrypted credential, server key, or access group.</p>
          <button id="btnEmptyCreateAccount" class="btn-primary text-xs">+ Create New Account</button>
        </div>
      `;
      document.getElementById('btnEmptyCreateAccount')?.addEventListener('click', () => this.openAccountModal());
      return;
    }

    let html = '';

    // Active Repo Filter Indicator
    if (this.activeRepoFilter !== null) {
      html += `
        <div class="mb-4 bg-indigo-950/40 border border-indigo-700/60 rounded-xl p-3 flex items-center justify-between text-xs">
          <div class="flex items-center gap-2 text-indigo-300">
            <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            <span>Showing credentials linked to repository: <strong class="text-white">${this.activeRepoName}</strong></span>
          </div>
          <button id="btnClearRepoFilter" class="btn-secondary text-[11px] py-1 px-2.5">Clear Filter</button>
        </div>
      `;
    }

    if (this.filteredAccounts.length === 0) {
      html += `
        <div class="text-center py-12 bg-[#161b22] border border-[#30363d] rounded-xl p-6">
          <p class="text-xs text-[#8b949e]">No accounts found matching the active filter criteria.</p>
        </div>
      `;
      container.innerHTML = html;
      document.getElementById('btnClearRepoFilter')?.addEventListener('click', () => this.clearRepoFilter());
      return;
    }

    if (this.viewMode === 'list') {
      html += this.buildListViewHtml();
    } else if (this.viewMode === 'connections') {
      html += this.buildConnectionsViewHtml();
    } else {
      html += this.buildCardsViewHtml();
    }

    container.innerHTML = html;
    this.bindContentEvents(container);
  }

  // =========================================================================
  // View 1: Cards View
  // =========================================================================
  buildCardsViewHtml() {
    let html = `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">`;

    this.filteredAccounts.forEach(acc => {
      const creds = this.resolveCredentials(acc);
      const isCritical = (acc.category === 'CRITICAL_DRIVE');
      const isMasked = !this.revealAll;
      const linkedRepos = this.links.filter(l => String(l.account_id) === String(acc.id));

      html += `
        <div class="bg-[#161b22] border ${isCritical ? 'border-rose-900/70 shadow-rose-950/20' : 'border-[#30363d]'} rounded-xl p-4 flex flex-col justify-between shadow-sm hover:border-[#58a6ff]/50 transition">
          <div>
            <!-- Header -->
            <div class="flex items-center justify-between gap-2">
              <h4 class="font-bold text-[#e6edf3] text-sm truncate" title="${acc.title}">${acc.title}</h4>
              <span class="px-2 py-0.5 rounded text-[10px] bg-[#21262d] text-[#8b949e] border border-[#30363d] truncate max-w-[100px]">${creds.sectorName}</span>
            </div>

            <!-- URL preview -->
            ${acc.url ? `
              <a href="${acc.url}" target="_blank" class="text-xs text-[#58a6ff] hover:underline flex items-center gap-1 mt-1 truncate" title="${acc.url}">
                <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                <span class="truncate">${acc.url.replace(/^https?:\/\//, '')}</span>
              </a>
            ` : ''}

            <!-- Credentials Box -->
            <div class="mt-3.5 space-y-1.5 bg-[#0d1117] p-2.5 rounded-lg border border-[#21262d] text-xs">
              <div class="flex items-center justify-between">
                <span class="text-[#8b949e] text-[11px]">User:</span>
                <span class="font-mono text-[#e6edf3] select-all truncate max-w-[170px]" title="${creds.username}">${creds.username || '(None)'}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-[#8b949e] text-[11px]">Password:</span>
                <span class="font-mono text-[#e6edf3] select-all truncate max-w-[170px]">${isMasked ? '••••••••••••' : (creds.password || '(None)')}</span>
              </div>
            </div>

            <!-- Badges: Auth & Linked Repos -->
            <div class="mt-3 flex items-center gap-1.5 flex-wrap">
              <span class="px-1.5 py-0.5 rounded text-[10px] bg-[#21262d] text-[#8b949e] border border-[#30363d]">${acc.authType}</span>
              ${acc.sharedWithTeam ? '<span class="px-1.5 py-0.5 rounded text-[10px] bg-indigo-950 text-indigo-300 border border-indigo-800">Team Shared</span>' : ''}
              ${linkedRepos.map(l => `
                <button data-action="open-repo" data-repo-id="${l.repository_id}" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] bg-slate-800 text-slate-300 hover:bg-slate-700 border border-slate-700 transition" title="Linked to GitHub repo">
                  <svg class="w-3 h-3 text-[#58a6ff]" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
                  <span>${l.repo_name || 'Repo #' + l.repository_id}</span>
                </button>
              `).join('')}
            </div>
          </div>

          <!-- Bottom Footer -->
          <div class="mt-4 pt-3 border-t border-[#30363d] flex items-center justify-between">
            <span class="text-[11px] ${isCritical ? 'text-rose-400 font-semibold' : 'text-[#8b949e]'}">${acc.category}</span>
            <div class="flex items-center gap-1.5">
              <button data-action="copy-pass" data-pass="${creds.password}" class="btn-secondary text-[11px] py-1 px-2.5">
                Copy Pass
              </button>
              <button data-action="edit-account" data-id="${acc.id}" class="p-1 rounded text-[#8b949e] hover:text-[#58a6ff]" title="Edit Account">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button data-action="delete-account" data-id="${acc.id}" data-title="${acc.title}" class="p-1 rounded text-[#8b949e] hover:text-rose-400" title="Delete Account">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              </button>
            </div>
          </div>
        </div>
      `;
    });

    html += `</div>`;
    return html;
  }

  // =========================================================================
  // View 2: Rich Operational Table List View
  // =========================================================================
  buildListViewHtml() {
    let html = `
      <div class="bg-[#161b22] border border-[#30363d] rounded-xl overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs whitespace-nowrap">
            <thead class="bg-[#21262d] text-[#8b949e] border-b border-[#30363d] uppercase font-semibold text-[11px] tracking-wider">
              <tr>
                <th class="py-3 px-3">Service / Title</th>
                <th class="py-3 px-3">Access Group</th>
                <th class="py-3 px-3">Username / Email</th>
                <th class="py-3 px-3">Password</th>
                <th class="py-3 px-3">Auth Type</th>
                <th class="py-3 px-3">Category</th>
                <th class="py-3 px-3">Linked Repo</th>
                <th class="py-3 px-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[#30363d]">
    `;

    this.filteredAccounts.forEach(acc => {
      const creds = this.resolveCredentials(acc);
      const isCritical = (acc.category === 'CRITICAL_DRIVE');
      const isMasked = !this.revealAll;
      const linkedRepos = this.links.filter(l => String(l.account_id) === String(acc.id));

      html += `
        <tr class="hover:bg-[#21262d]/50 transition">
          <!-- Col 1: Service / Title -->
          <td class="py-2.5 px-3">
            <div class="font-bold text-[#e6edf3] text-xs">${acc.title}</div>
            ${acc.url ? `<a href="${acc.url}" target="_blank" class="text-[11px] text-[#58a6ff] hover:underline block max-w-xs truncate">${acc.url.replace(/^https?:\/\//, '')}</a>` : ''}
          </td>

          <!-- Col 2: Access Group -->
          <td class="py-2.5 px-3">
            <span class="px-2 py-0.5 rounded text-[10px] bg-[#21262d] text-[#8b949e] border border-[#30363d]">${creds.sectorName}</span>
          </td>

          <!-- Col 3: Username -->
          <td class="py-2.5 px-3 font-mono text-[#e6edf3]">
            <div class="flex items-center gap-1.5">
              <span>${creds.username || '—'}</span>
              ${creds.username ? `
                <button data-action="copy-user" data-user="${creds.username}" class="p-0.5 text-[#8b949e] hover:text-[#e6edf3]" title="Copy Username">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                </button>
              ` : ''}
            </div>
          </td>

          <!-- Col 4: Password -->
          <td class="py-2.5 px-3 font-mono text-[#e6edf3]">
            <div class="flex items-center gap-1.5">
              <span>${isMasked ? '••••••••••••' : (creds.password || '—')}</span>
              ${creds.password ? `
                <button data-action="copy-pass" data-pass="${creds.password}" class="p-0.5 text-[#8b949e] hover:text-[#e6edf3]" title="Copy Password">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                </button>
              ` : ''}
            </div>
          </td>

          <!-- Col 5: Auth Type -->
          <td class="py-2.5 px-3">
            <span class="px-1.5 py-0.5 rounded text-[10px] bg-[#21262d] text-[#8b949e] border border-[#30363d]">${acc.authType}</span>
          </td>

          <!-- Col 6: Category -->
          <td class="py-2.5 px-3">
            <span class="px-1.5 py-0.5 rounded text-[10px] ${isCritical ? 'bg-rose-950 text-rose-300 border border-rose-800' : 'bg-[#21262d] text-[#8b949e] border border-[#30363d]'}">${acc.category}</span>
          </td>

          <!-- Col 7: Linked Repo -->
          <td class="py-2.5 px-3">
            ${linkedRepos.length > 0 ? `
              <button data-action="open-repo" data-repo-id="${linkedRepos[0].repository_id}" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] bg-slate-800 text-slate-300 hover:bg-slate-700 border border-slate-700 transition" title="Go to GitHub repository">
                <svg class="w-3 h-3 text-[#58a6ff]" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
                <span>${linkedRepos[0].repo_name || 'Repo #' + linkedRepos[0].repository_id}</span>
              </button>
            ` : '<span class="text-[#8b949e]">—</span>'}
          </td>

          <!-- Col 8: Actions -->
          <td class="py-2.5 px-3 text-right">
            <div class="flex items-center justify-end gap-1">
              <button data-action="edit-account" data-id="${acc.id}" class="p-1 rounded text-[#8b949e] hover:text-[#58a6ff]" title="Edit Account">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button data-action="delete-account" data-id="${acc.id}" data-title="${acc.title}" class="p-1 rounded text-[#8b949e] hover:text-rose-400" title="Delete Account">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              </button>
            </div>
          </td>
        </tr>
      `;
    });

    html += `</tbody></table></div></div>`;
    return html;
  }

  // =========================================================================
  // View 3: Connections / Topology Graph View
  // =========================================================================
  buildConnectionsViewHtml() {
    let html = `
      <div class="space-y-6">
        <div class="p-4 bg-[#161b22] border border-[#30363d] rounded-xl text-xs flex items-center justify-between">
          <div class="flex items-center gap-2 text-[#8b949e]">
            <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            <span><strong>Topology Graph View:</strong> Visualizing relational bindings between Access Groups, Credentials, and GitHub Repositories.</span>
          </div>
          <span class="text-[11px] text-[#58a6ff]">${this.sectors.length} Groups • ${this.accounts.length} Accounts • ${this.links.length} Links</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    `;

    // Group accounts by Sector
    const groups = {};
    this.sectors.forEach(s => {
      groups[s.id] = { sector: s, accounts: [] };
    });
    groups['independent'] = {
      sector: { id: null, name: 'Independent Accounts', isSharedVault: false },
      accounts: []
    };

    this.filteredAccounts.forEach(acc => {
      if (acc.sectorId && groups[acc.sectorId]) {
        groups[acc.sectorId].accounts.push(acc);
      } else {
        groups['independent'].accounts.push(acc);
      }
    });

    Object.values(groups).forEach(g => {
      if (g.accounts.length === 0 && g.sector.id === null) return;

      html += `
        <div class="topology-card flex flex-col justify-between space-y-4">
          <div>
            <!-- Group Hub -->
            <div class="flex items-center justify-between pb-3 border-b border-[#30363d]">
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full ${g.sector.isSharedVault ? 'bg-indigo-500' : 'bg-emerald-500'}"></span>
                <h4 class="font-bold text-[#e6edf3] text-sm">${g.sector.name}</h4>
              </div>
              <span class="px-2 py-0.5 rounded text-[10px] bg-[#21262d] text-[#8b949e] font-mono">${g.accounts.length} nodes</span>
            </div>

            <!-- Connected Accounts in this Cluster -->
            <div class="mt-3 space-y-2">
              ${g.accounts.map(acc => {
                const linkedRepos = this.links.filter(l => String(l.account_id) === String(acc.id));
                return `
                  <div class="bg-[#0d1117] border border-[#21262d] rounded-lg p-2.5 text-xs flex items-center justify-between hover:border-[#58a6ff]/40 transition">
                    <div>
                      <div class="font-semibold text-[#e6edf3] flex items-center gap-1.5">
                        <span>${acc.title}</span>
                        ${acc.category === 'CRITICAL_DRIVE' ? '<span class="w-1.5 h-1.5 rounded-full bg-rose-500" title="Critical Drive"></span>' : ''}
                      </div>
                      <div class="text-[10px] text-[#8b949e] font-mono">${acc.customUsername || '(Inherited User)'}</div>
                    </div>

                    <div class="flex items-center gap-1">
                      ${linkedRepos.map(l => `
                        <button data-action="open-repo" data-repo-id="${l.repository_id}" class="p-1 rounded bg-slate-800 text-slate-300 hover:text-white" title="Linked to GitHub: ${l.repo_name || 'Repo'}">
                          <svg class="w-3.5 h-3.5 text-[#58a6ff]" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
                        </button>
                      `).join('')}
                      <button data-action="edit-account" data-id="${acc.id}" class="p-1 text-[#8b949e] hover:text-[#e6edf3]" title="Edit">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      </button>
                    </div>
                  </div>
                `;
              }).join('')}
            </div>
          </div>
        </div>
      `;
    });

    html += `</div></div>`;
    return html;
  }

  bindContentEvents(container) {
    // Copy Password
    container.querySelectorAll('[data-action="copy-pass"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await security.copyToClipboard(btn.dataset.pass);
        toast.success('Password copied to clipboard');
      });
    });

    // Copy Username
    container.querySelectorAll('[data-action="copy-user"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await security.copyToClipboard(btn.dataset.user);
        toast.success(`Copied username: ${btn.dataset.user}`);
      });
    });

    // Edit Account
    container.querySelectorAll('[data-action="edit-account"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const acc = this.accounts.find(a => a.id === btn.dataset.id);
        if (acc) this.openAccountModal(acc);
      });
    });

    // Delete Account
    container.querySelectorAll('[data-action="delete-account"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.id;
        const title = btn.dataset.title;
        if (confirm(`Are you sure you want to delete "${title}" from the vault?`)) {
          await this.deleteAccount(id);
        }
      });
    });

    // Open Linked GitHub Repo in Explorer
    container.querySelectorAll('[data-action="open-repo"]').forEach(btn => {
      btn.addEventListener('click', () => {
        synergy.openRepoForAccount(btn.dataset.repoId);
      });
    });

    // Clear Repo Filter
    document.getElementById('btnClearRepoFilter')?.addEventListener('click', () => this.clearRepoFilter());
  }

  // =========================================================================
  // Modal: Full Account Modal (Add / Edit)
  // =========================================================================
  openAccountModal(account = null) {
    this.editingAccountId = account ? account.id : null;
    const modalTitle = document.getElementById('accountModalTitle');
    if (modalTitle) modalTitle.textContent = account ? 'Edit Vault Account' : 'Create New Account';

    // Populate sector dropdown
    const sectorSelect = document.getElementById('accSector');
    if (sectorSelect) {
      let optionsHtml = '<option value="">(None - Independent)</option>';
      this.sectors.forEach(s => {
        const sel = (account && account.sectorId === s.id) ? 'selected' : '';
        optionsHtml += `<option value="${s.id}" ${sel}>${s.name}</option>`;
      });
      sectorSelect.innerHTML = optionsHtml;
    }

    // Populate linked repo dropdown
    const repoSelect = document.getElementById('accLinkedRepo');
    if (repoSelect) {
      let repoOptions = '<option value="">(None)</option>';
      const currentLink = account ? this.links.find(l => String(l.account_id) === String(account.id)) : null;
      const currentRepoId = currentLink ? parseInt(currentLink.repository_id) : 0;

      explorer.repositories.forEach(r => {
        const sel = (currentRepoId === r.id) ? 'selected' : '';
        repoOptions += `<option value="${r.id}" ${sel}>${r.name}</option>`;
      });
      repoSelect.innerHTML = repoOptions;
    }

    // Set fields
    document.getElementById('accTitle').value = account?.title || '';
    document.getElementById('accUrl').value = account?.url || '';
    document.getElementById('accUsername').value = account?.customUsername || '';
    document.getElementById('accPassword').value = account?.customPassword || '';
    document.getElementById('accAuthType').value = account?.authType || 'CREDENTIALS';
    document.getElementById('accLogicRule').value = account?.logicRule || 'CUSTOM';
    document.getElementById('accCategory').value = account?.category || 'GENERAL';
    document.getElementById('accMobile').value = account?.mobileNumber || '';
    document.getElementById('accShared').checked = !!account?.sharedWithTeam;
    document.getElementById('accDbInfo').value = account?.dbInfo || '';
    document.getElementById('accApiKey').value = account?.apiKey || '';
    document.getElementById('accNotes').value = account?.notes || '';

    // Critical Toggle
    const isCritical = (account?.category === 'CRITICAL_DRIVE');
    const critToggle = document.getElementById('accCriticalToggle');
    if (critToggle) critToggle.checked = isCritical;

    modals.open('accountModal');
  }

  async saveAccount(formData) {
    try {
      const res = await api.post('api.php?resource=vault&action=account', formData);
      if (res.ok) {
        toast.success(this.editingAccountId ? 'Account updated successfully' : 'New account created successfully');
        modals.close('accountModal');
        await this.loadVault();
      }
    } catch (err) {
      toast.error(`Save failed: ${err.message}`);
    }
  }

  async deleteAccount(accountId) {
    try {
      const res = await api.delete(`api.php?resource=vault&action=account&id=${encodeURIComponent(accountId)}`);
      if (res.ok) {
        toast.info('Account deleted from vault');
        await this.loadVault();
      }
    } catch (err) {
      toast.error(`Delete failed: ${err.message}`);
    }
  }

  // =========================================================================
  // Modal: Access Group / Sector Modal
  // =========================================================================
  openSectorModal(sector = null) {
    this.editingSectorId = sector ? sector.id : null;
    const title = document.getElementById('sectorModalTitle');
    if (title) title.textContent = sector ? 'Edit Access Group' : 'Create Access Group';

    document.getElementById('sectorName').value = sector?.name || '';
    document.getElementById('sectorDefaultUser').value = sector?.defaultUsername || '';
    document.getElementById('sectorDefaultPass').value = sector?.defaultPassword || '';
    document.getElementById('sectorShared').checked = !!sector?.isSharedVault;

    modals.open('sectorModal');
  }

  async saveSector(formData) {
    try {
      const res = await api.post('api.php?resource=vault&action=sector', formData);
      if (res.ok) {
        toast.success(this.editingSectorId ? 'Access group updated' : 'Access group created');
        modals.close('sectorModal');
        await this.loadVault();
      }
    } catch (err) {
      toast.error(`Access group save failed: ${err.message}`);
    }
  }

  async deleteSector(sectorId) {
    try {
      const res = await api.delete(`api.php?resource=vault&action=sector&id=${encodeURIComponent(sectorId)}`);
      if (res.ok) {
        toast.info('Access group removed');
        if (this.activeSidebarView === sectorId) this.activeSidebarView = 'all';
        await this.loadVault();
      }
    } catch (err) {
      toast.error(`Delete failed: ${err.message}`);
    }
  }

  // =========================================================================
  // Modal: Credential Pool Modal
  // =========================================================================
  openPoolModal() {
    this.renderPoolList();
    modals.open('poolModal');
  }

  renderPoolList() {
    const listEl = document.getElementById('poolItemsList');
    if (!listEl) return;

    if (this.credentialPool.length === 0) {
      listEl.innerHTML = '<div class="text-xs text-[#8b949e] py-6 text-center">Credential pool is empty. Add reusable standard credentials below.</div>';
      return;
    }

    let html = '<div class="space-y-2 max-h-[320px] overflow-y-auto pr-1">';
    this.credentialPool.forEach(item => {
      html += `
        <div class="bg-[#0d1117] border border-[#21262d] rounded-lg p-2.5 flex items-center justify-between text-xs">
          <div>
            <div class="flex items-center gap-2">
              <span class="px-1.5 py-0.2 rounded text-[10px] bg-[#21262d] text-[#58a6ff] font-bold font-mono">${item.itemType}</span>
              <span class="font-mono text-[#e6edf3] select-all">${item.value}</span>
            </div>
            <div class="text-[10px] text-[#8b949e] mt-1">Used ${item.useCount} times</div>
          </div>
          <div class="flex items-center gap-1">
            <button data-action="copy-pool-item" data-val="${item.value}" class="btn-secondary text-[10px] py-1 px-2">Copy</button>
            <button data-action="delete-pool-item" data-id="${item.id}" class="p-1 text-[#8b949e] hover:text-rose-400">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
        </div>
      `;
    });
    html += '</div>';

    listEl.innerHTML = html;

    listEl.querySelectorAll('[data-action="copy-pool-item"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await security.copyToClipboard(btn.dataset.val);
        toast.success('Copied credential to clipboard');
      });
    });

    listEl.querySelectorAll('[data-action="delete-pool-item"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await this.deletePoolItem(btn.dataset.id);
      });
    });
  }

  async addPoolItem(itemType, value) {
    try {
      const res = await api.post('api.php?resource=vault&action=pool', { itemType, value });
      if (res.ok) {
        toast.success('Credential added to pool');
        await this.loadVault();
        this.renderPoolList();
      }
    } catch (err) {
      toast.error(`Could not add to pool: ${err.message}`);
    }
  }

  async deletePoolItem(id) {
    try {
      const res = await api.delete(`api.php?resource=vault&action=pool&id=${encodeURIComponent(id)}`);
      if (res.ok) {
        toast.info('Item removed from pool');
        await this.loadVault();
        this.renderPoolList();
      }
    } catch (err) {
      toast.error(`Delete failed: ${err.message}`);
    }
  }

  // =========================================================================
  // Modal: Rotate Shared Password Modal
  // =========================================================================
  openRotateSharedModal() {
    const secSelect = document.getElementById('rotateSectorSelect');
    if (secSelect) {
      let opts = '<option value="">All Shared Team Accounts</option>';
      this.sectors.filter(s => s.isSharedVault).forEach(s => {
        opts += `<option value="${s.id}">${s.name} (Shared Group)</option>`;
      });
      secSelect.innerHTML = opts;
    }

    const genPass = security.generatePassword(22);
    document.getElementById('rotateNewPassword').value = genPass;
    modals.open('rotateSharedModal');
  }

  async rotateShared(newPassword, sectorId = null) {
    try {
      const res = await api.post('api.php?resource=vault&action=rotate_shared', {
        newPassword,
        sectorId: sectorId || null,
      });
      if (res.ok) {
        toast.success('Shared team passwords rotated successfully');
        modals.close('rotateSharedModal');
        await this.loadVault();
      }
    } catch (err) {
      toast.error(`Rotation failed: ${err.message}`);
    }
  }
}

export const vault = new VaultModule();
