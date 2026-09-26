/**
 * PolymerOps Master Vault - GitHub Explorer & Reconnaissance Module
 * 
 * Provides:
 * - Rich operational repository table with persistent metadata controls
 * - Real-time AST tree inspection cache
 * - Rate limit telemetry & last sync monitoring
 * - Multi-criteria batch copy actions
 * - Smart Link synergy integration
 */

import { api } from '../core/api-client.js';
import { toast } from '../ui/toast.js';
import { notes } from '../ui/notes.js';
import { synergy } from './synergy.js';
import { security } from '../core/security.js';

export class ExplorerModule {
  constructor() {
    this.repositories = [];
    this.filteredRepositories = [];
    this.user = null;
    this.stats = null;
    this.rateLimit = null;
    this.lastSyncTimestamp = null;
    this.isLoading = false;

    this.searchQuery = '';
    this.visibilityFilter = 'all'; // 'all', 'public', 'private', 'sources', 'forks'
    this.languageFilter = 'all';
    this.sortBy = 'updated_desc';
    this.viewMode = localStorage.getItem('polymer_view_mode') || 'list';

    this.inspections = {};
    this.metadata = {};
    this.initLocalStorage();
  }

  initLocalStorage() {
    try {
      const rawIns = localStorage.getItem('polymer_inspections_cache');
      this.inspections = rawIns ? JSON.parse(rawIns) : {};
    } catch (_) {
      this.inspections = {};
    }

    try {
      const rawMeta = localStorage.getItem('polymer_repos_metadata');
      this.metadata = rawMeta ? JSON.parse(rawMeta) : {};
    } catch (_) {
      this.metadata = {};
    }
  }

  saveInspections() {
    try {
      localStorage.setItem('polymer_inspections_cache', JSON.stringify(this.inspections));
    } catch (_) {}
  }

  saveLocalMetadata() {
    try {
      localStorage.setItem('polymer_repos_metadata', JSON.stringify(this.metadata));
    } catch (_) {}
  }

  async loadRepositories(forceRefresh = false) {
    this.isLoading = true;
    this.renderLoading(true);

    try {
      const url = `api.php?resource=repos${forceRefresh ? '&refresh=1' : ''}`;
      const data = await api.get(url);

      this.repositories = data.repositories || [];
      this.user = data.user || null;
      this.stats = data.stats || null;
      this.rateLimit = data.rate_limit || null;
      this.lastSyncTimestamp = data.last_sync_timestamp || data.cached_at || new Date().toISOString();

      // Merge backend metadata with local metadata cache
      this.repositories.forEach(r => {
        if (r.metadata) {
          this.metadata[r.id] = {
            ...(this.metadata[r.id] || {}),
            ...r.metadata,
          };
        }
      });
      this.saveLocalMetadata();

      this.renderRateLimitAndSync();
      this.renderLanguagesDropdown();
      this.applyFilters();
      this.renderStats();
      this.render();

      if (data.cached) {
        toast.info(`Loaded ${this.repositories.length} cached repositories`);
      } else {
        toast.success(`Synced ${this.repositories.length} repositories from GitHub`);
      }
    } catch (err) {
      toast.error(`GitHub API: ${err.message}`);
      this.renderError(err.message);
    } finally {
      this.isLoading = false;
      this.renderLoading(false);
    }
  }

  applyFilters() {
    let list = [...this.repositories];

    // Search query
    if (this.searchQuery) {
      const q = this.searchQuery.toLowerCase();
      list = list.filter(r => {
        const meta = this.metadata[r.id] || {};
        const dbName = meta.detected_db || meta.detectedDb || '';
        const authName = meta.detected_auth || meta.detectedAuth || '';
        return (
          r.name.toLowerCase().includes(q) ||
          (r.description && r.description.toLowerCase().includes(q)) ||
          (r.language && r.language.toLowerCase().includes(q)) ||
          dbName.toLowerCase().includes(q) ||
          authName.toLowerCase().includes(q)
        );
      });
    }

    // Visibility filter buttons: [All], [Public], [Private], [Sources Only], [Forks]
    if (this.visibilityFilter === 'public') {
      list = list.filter(r => !r.private && !r.fork);
    } else if (this.visibilityFilter === 'private') {
      list = list.filter(r => r.private);
    } else if (this.visibilityFilter === 'sources') {
      list = list.filter(r => !r.fork);
    } else if (this.visibilityFilter === 'forks') {
      list = list.filter(r => r.fork);
    }

    // Language filter dropdown
    if (this.languageFilter !== 'all') {
      list = list.filter(r => (r.language || 'Unspecified') === this.languageFilter);
    }

    // Sort order
    list.sort((a, b) => {
      if (this.sortBy === 'updated_desc') return new Date(b.updated_at) - new Date(a.updated_at);
      if (this.sortBy === 'updated_asc') return new Date(a.updated_at) - new Date(b.updated_at);
      if (this.sortBy === 'name_asc') return a.name.localeCompare(b.name);
      if (this.sortBy === 'name_desc') return b.name.localeCompare(a.name);
      if (this.sortBy === 'stars_desc') return (b.stargazers_count || 0) - (a.stargazers_count || 0);
      return 0;
    });

    this.filteredRepositories = list;
    this.updateFilterButtonStyles();
  }

  updateFilterButtonStyles() {
    document.querySelectorAll('[data-quick-filter]').forEach(btn => {
      const active = (btn.dataset.quickFilter === this.visibilityFilter);
      btn.classList.toggle('bg-[#30363d]', active);
      btn.classList.toggle('text-[#e6edf3]', active);
      btn.classList.toggle('text-[#8b949e]', !active);
    });
  }

  async saveMetadata(repoId, patch, repoName = '') {
    const id = parseInt(repoId);
    this.metadata[id] = {
      ...(this.metadata[id] || {}),
      ...patch,
    };
    this.saveLocalMetadata();

    try {
      const payload = {
        repositoryId: id,
        name: repoName,
        detectedDb: patch.detectedDb ?? this.metadata[id].detectedDb ?? this.metadata[id].detected_db ?? null,
        detectedAuth: patch.detectedAuth ?? this.metadata[id].detectedAuth ?? this.metadata[id].detected_auth ?? null,
        assignedAiTool: patch.assignedAiTool ?? this.metadata[id].assignedAiTool ?? this.metadata[id].assigned_ai_tool ?? null,
        assignedPromptTool: patch.assignedPromptTool ?? this.metadata[id].assignedPromptTool ?? this.metadata[id].assigned_prompt_tool ?? null,
      };

      const res = await api.post('api.php?resource=repos&action=metadata', payload);
      if (res.ok) {
        const fieldName = Object.keys(patch)[0];
        const val = patch[fieldName];
        toast.success(`Saved ${fieldName} [${val || 'None'}] for ${repoName || 'Repo #' + id}`);
      }
    } catch (err) {
      // Local fallback is already saved
      toast.info(`Saved locally: ${err.message}`);
    }
  }

  async inspectRepo(repoName, branch = 'main', pushedAt = '') {
    toast.info(`Scanning repository AST: ${repoName}...`);
    try {
      const res = await api.get(`api.php?resource=repos&action=inspect&repo=${encodeURIComponent(repoName)}&branch=${encodeURIComponent(branch)}&pushed_at=${encodeURIComponent(pushedAt)}`);
      if (res.ok && res.inspection) {
        this.inspections[repoName] = res.inspection;
        this.saveInspections();

        // Auto-assign detected db/auth if present and not already set
        const repo = this.repositories.find(r => r.name === repoName);
        if (repo && res.inspection.database?.detected) {
          const detectedDb = res.inspection.database.type;
          const currentMeta = this.metadata[repo.id] || {};
          if (!currentMeta.detected_db && !currentMeta.detectedDb) {
            await this.saveMetadata(repo.id, { detectedDb }, repoName);
          }
        }

        toast.success(`AST scan complete for ${repoName}: DB [${res.inspection.database.type}], Stack [${res.inspection.tech_stack?.name || 'Generic'}]`);
        this.render();
      }
    } catch (err) {
      toast.error(`Inspection failed: ${err.message}`);
    }
  }

  getLanguageColor(lang) {
    if (!lang) return '#8b949e';
    const map = {
      'JavaScript': '#f1e05a',
      'TypeScript': '#3178c6',
      'PHP': '#4F5D95',
      'Python': '#3572A5',
      'HTML': '#e34c26',
      'CSS': '#563d7c',
      'Vue': '#41b883',
      'Go': '#00ADD8',
      'Rust': '#dea584',
      'Shell': '#89e051',
      'C++': '#f34b7d',
      'C': '#555555',
      'C#': '#178600',
      'Ruby': '#701516',
      'Java': '#b07219',
      'Kotlin': '#A97BFF',
      'Swift': '#F05138',
      'Dart': '#00B4AB',
      'Dockerfile': '#384d54',
      'Markdown': '#083fa1',
      'Makefile': '#427819',
    };
    return map[lang] || '#8b949e';
  }

  formatRelativeTime(dateStr) {
    if (!dateStr) return '—';
    try {
      const date = new Date(dateStr);
      const diffMs = Date.now() - date.getTime();
      const diffSecs = Math.floor(diffMs / 1000);
      const diffMins = Math.floor(diffSecs / 60);
      const diffHours = Math.floor(diffMins / 60);
      const diffDays = Math.floor(diffHours / 24);
      const diffMonths = Math.floor(diffDays / 30);
      const diffYears = Math.floor(diffDays / 365);

      if (diffSecs < 60) return 'just now';
      if (diffMins < 60) return `${diffMins}m ago`;
      if (diffHours < 24) return `${diffHours}h ago`;
      if (diffDays < 30) return `${diffDays}d ago`;
      if (diffMonths < 12) return `${diffMonths}mo ago`;
      return `${diffYears}y ago`;
    } catch (_) {
      return dateStr;
    }
  }

  renderRateLimitAndSync() {
    const rateLimitEl = document.getElementById('rateLimitCounter');
    const syncTimeEl = document.getElementById('lastSyncTimestamp');

    if (rateLimitEl && this.rateLimit) {
      const remaining = this.rateLimit.remaining ?? 5000;
      const limit = this.rateLimit.limit ?? 5000;
      const colorClass = remaining < 500 ? 'text-rose-400' : (remaining < 2000 ? 'text-amber-400' : 'text-emerald-400');

      rateLimitEl.innerHTML = `
        <span class="w-2 h-2 rounded-full ${remaining < 500 ? 'bg-rose-500' : (remaining < 2000 ? 'bg-amber-500' : 'bg-emerald-500')}"></span>
        <span class="${colorClass} font-mono font-medium">${remaining.toLocaleString()}</span>
        <span class="text-[#8b949e]">/ ${limit.toLocaleString()} API calls left</span>
      `;
      rateLimitEl.title = this.rateLimit.reset_formatted ? `Resets at: ${this.rateLimit.reset_formatted}` : 'GitHub API Rate Limit';
    }

    if (syncTimeEl && this.lastSyncTimestamp) {
      syncTimeEl.textContent = `Last sync: ${this.formatRelativeTime(this.lastSyncTimestamp)}`;
      syncTimeEl.title = new Date(this.lastSyncTimestamp).toLocaleString();
    }
  }

  renderLanguagesDropdown() {
    const select = document.getElementById('selectFilterLanguage');
    if (!select) return;

    const languages = new Set();
    this.repositories.forEach(r => {
      if (r.language) languages.add(r.language);
    });

    const sortedLangs = Array.from(languages).sort();
    let optionsHtml = '<option value="all">All Languages</option>';
    sortedLangs.forEach(lang => {
      const selected = (this.languageFilter === lang) ? 'selected' : '';
      optionsHtml += `<option value="${lang}" ${selected}>${lang}</option>`;
    });

    select.innerHTML = optionsHtml;
  }

  renderStats() {
    const totalEl = document.getElementById('statTotalRepos');
    const pubEl = document.getElementById('statPublicRepos');
    const privEl = document.getElementById('statPrivateRepos');
    const forksEl = document.getElementById('statForks');

    if (totalEl && this.stats) totalEl.textContent = this.stats.total || 0;
    if (pubEl && this.stats) pubEl.textContent = this.stats.public || 0;
    if (privEl && this.stats) privEl.textContent = this.stats.private || 0;
    if (forksEl && this.stats) forksEl.textContent = this.stats.forks || 0;
  }

  renderLoading(loading) {
    const loadingEl = document.getElementById('explorerLoading');
    const container = document.getElementById('explorerContainer');
    if (loadingEl) loadingEl.classList.toggle('hidden', !loading);
    if (container && loading) container.innerHTML = '';
  }

  renderError(message) {
    const container = document.getElementById('explorerContainer');
    if (!container) return;
    container.innerHTML = `
      <div class="text-center py-16 bg-[#161b22] border border-rose-900/60 rounded-xl p-8">
        <svg class="w-12 h-12 text-rose-500 mx-auto mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <h3 class="text-base font-bold text-[#e6edf3]">GitHub Sync Failed</h3>
        <p class="text-xs text-rose-400 mt-1 mb-4">${message}</p>
        <button id="btnRetrySync" class="btn-primary text-xs">Retry GitHub Connection</button>
      </div>
    `;
    document.getElementById('btnRetrySync')?.addEventListener('click', () => this.loadRepositories(true));
  }

  render() {
    const container = document.getElementById('explorerContainer');
    if (!container) return;

    if (this.filteredRepositories.length === 0) {
      container.innerHTML = `
        <div class="text-center py-16 bg-[#161b22] border border-[#30363d] rounded-xl p-8">
          <svg class="w-12 h-12 text-[#8b949e] mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16m-7 6h7"/></svg>
          <h3 class="text-base font-semibold text-[#e6edf3]">No repositories match your filter criteria</h3>
          <p class="text-xs text-[#8b949e] mt-1 mb-4">Try clearing search terms or selecting [All] filters.</p>
          <button id="btnResetFilters" class="btn-secondary text-xs">Reset All Filters</button>
        </div>
      `;
      document.getElementById('btnResetFilters')?.addEventListener('click', () => {
        this.searchQuery = '';
        this.visibilityFilter = 'all';
        this.languageFilter = 'all';
        const searchInput = document.getElementById('inputSearchRepos');
        if (searchInput) searchInput.value = '';
        const langSelect = document.getElementById('selectFilterLanguage');
        if (langSelect) langSelect.value = 'all';
        this.applyFilters();
        this.render();
      });
      return;
    }

    if (this.viewMode === 'grid') {
      this.renderGridView(container);
    } else {
      this.renderListView(container);
    }
  }

  // =========================================================================
  // Rich Operational Table View (All 9 Requested Columns)
  // =========================================================================
  renderListView(container) {
    let html = `
      <div class="bg-[#161b22] border border-[#30363d] rounded-xl overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs whitespace-nowrap">
            <thead class="bg-[#21262d] text-[#8b949e] border-b border-[#30363d] uppercase font-semibold text-[11px] tracking-wider">
              <tr>
                <th class="py-3 px-3 min-w-[220px]">Repository</th>
                <th class="py-3 px-3 min-w-[110px]">Language</th>
                <th class="py-3 px-3 min-w-[110px]">Live App</th>
                <th class="py-3 px-3 min-w-[130px]">Tech Stack</th>
                <th class="py-3 px-3 min-w-[125px]">Database</th>
                <th class="py-3 px-3 min-w-[125px]">Auth / Login</th>
                <th class="py-3 px-3 min-w-[120px]">AI Dev / Tool</th>
                <th class="py-3 px-3 min-w-[120px]">Prompt Tool</th>
                <th class="py-3 px-3 min-w-[100px] text-right">Updated</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[#30363d]">
    `;

    this.filteredRepositories.forEach(r => {
      const inspect = this.inspections[r.name] || {};
      const meta = this.metadata[r.id] || {};

      // Column 1: Repository Details & Quick Actions
      const linkedVaults = synergy.getLinksForRepo(r.id);
      const hasVaultLink = linkedVaults.length > 0;

      // Column 2: Language & Dot Indicator
      const langColor = this.getLanguageColor(r.language);

      // Column 3: Live App Link
      let liveAppHtml = '<span class="text-[#8b949e]">—</span>';
      if (r.homepage) {
        let domain = r.homepage.replace(/^https?:\/\//, '').replace(/\/.*$/, '');
        liveAppHtml = `
          <a href="${r.homepage}" target="_blank" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] bg-[#21262d] text-[#58a6ff] hover:text-white border border-[#30363d] truncate max-w-[130px]" title="${r.homepage}">
            <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            <span class="truncate">${domain}</span>
          </a>
        `;
      }

      // Column 4: Tech Stack Tag
      const stackName = inspect.tech_stack?.name || (r.language ? `${r.language} Project` : 'Generic');

      // Column 5: Database Dropdown
      const selectedDb = meta.detected_db || meta.detectedDb || (inspect.database?.detected ? inspect.database.type : 'None');
      const dbOptions = ['None', 'MySQL', 'SQLite', 'PostgreSQL', 'MongoDB', 'Supabase', 'Firebase', 'Prisma'];
      if (!dbOptions.includes(selectedDb) && selectedDb !== 'None') dbOptions.push(selectedDb);

      // Column 6: Auth Dropdown
      const selectedAuth = meta.detected_auth || meta.detectedAuth || (inspect.auth?.detected ? 'JWT' : 'None');
      const authOptions = ['None', 'Google SSO', 'JWT', 'Session', 'OAuth', 'Clerk', 'NextAuth', 'Auth0'];
      if (!authOptions.includes(selectedAuth) && selectedAuth !== 'None') authOptions.push(selectedAuth);

      // Column 7: AI Dev Tool Dropdown
      const selectedAiTool = meta.assigned_ai_tool || meta.assignedAiTool || 'None';
      const aiToolOptions = ['None', 'AntiGravity', 'Cursor', 'ChatGPT', 'Claude'];

      // Column 8: Prompt / Explainer Tool Dropdown
      const selectedPromptTool = meta.assigned_prompt_tool || meta.assignedPromptTool || 'None';
      const promptOptions = ['None', 'Gemini', 'ChatGPT', 'Claude'];

      // Column 9: Relative Time
      const relativeTime = this.formatRelativeTime(r.updated_at);

      html += `
        <tr class="hover:bg-[#21262d]/50 transition group">
          <!-- Col 1: Repository -->
          <td class="py-2.5 px-3">
            <div class="flex items-center gap-1.5 flex-wrap">
              <a href="${r.html_url}" target="_blank" class="font-bold text-[#58a6ff] hover:underline text-xs flex items-center gap-1">
                ${r.name}
                <svg class="w-3 h-3 opacity-0 group-hover:opacity-100 transition-opacity text-[#8b949e]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
              </a>
              ${r.private ? '<span class="px-1.5 py-0.2 rounded text-[10px] bg-[#30363d] text-[#e6edf3]">Private</span>' : '<span class="px-1.5 py-0.2 rounded text-[10px] bg-[#238636]/30 text-[#3fb950]">Public</span>'}
              ${r.fork ? '<span class="px-1.5 py-0.2 rounded text-[10px] bg-amber-950/80 text-amber-400 border border-amber-800">Fork</span>' : ''}
              
              <!-- Quick Copy Button -->
              <button data-action="copy-name" data-name="${r.name}" class="p-0.5 text-[#8b949e] hover:text-[#e6edf3]" title="Copy repository name">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              </button>

              <!-- Dev Notes Trigger -->
              <button data-action="open-notes" data-id="${r.id}" data-name="${r.name}" class="p-0.5 text-[#8b949e] hover:text-[#58a6ff]" title="Work Notes & Progress">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>

              <!-- Smart Link Synergy Badge -->
              ${hasVaultLink ? `
                <button data-action="open-vault-for-repo" data-repo-id="${r.id}" data-name="${r.name}" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] bg-indigo-950 text-indigo-300 border border-indigo-700 hover:bg-indigo-900 cursor-pointer" title="Linked to ${linkedVaults.length} Vault account(s) - Click to view in Vault">
                  <svg class="w-3 h-3 text-indigo-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
                  <span>${linkedVaults.length} Vault</span>
                </button>
              ` : ''}
            </div>
            ${r.description ? `<p class="text-[11px] text-[#8b949e] mt-0.5 max-w-xs truncate" title="${r.description}">${r.description}</p>` : ''}
          </td>

          <!-- Col 2: Language -->
          <td class="py-2.5 px-3">
            <div class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full inline-block flex-shrink-0" style="background-color: ${langColor}"></span>
              <span class="text-xs text-[#e6edf3] font-medium">${r.language || 'None'}</span>
            </div>
          </td>

          <!-- Col 3: Live App -->
          <td class="py-2.5 px-3">
            ${liveAppHtml}
          </td>

          <!-- Col 4: Tech Stack -->
          <td class="py-2.5 px-3">
            <div class="flex items-center gap-1">
              <span class="px-2 py-0.5 rounded text-[11px] bg-[#21262d] text-[#e6edf3] border border-[#30363d] truncate max-w-[120px]" title="${stackName}">
                ${stackName}
              </span>
              <button data-action="inspect-repo" data-name="${r.name}" data-branch="${r.default_branch}" data-pushed="${r.pushed_at}" class="p-1 text-[#8b949e] hover:text-[#58a6ff]" title="Scan repository AST for dependencies and architecture">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              </button>
            </div>
          </td>

          <!-- Col 5: Database Dropdown -->
          <td class="py-2.5 px-3">
            <select data-action="change-metadata" data-repo-id="${r.id}" data-repo-name="${r.name}" data-field="detectedDb" class="vault-table-select">
              ${dbOptions.map(opt => `<option value="${opt}" ${opt === selectedDb ? 'selected' : ''}>${opt}</option>`).join('')}
            </select>
          </td>

          <!-- Col 6: Auth / Login Dropdown -->
          <td class="py-2.5 px-3">
            <select data-action="change-metadata" data-repo-id="${r.id}" data-repo-name="${r.name}" data-field="detectedAuth" class="vault-table-select">
              ${authOptions.map(opt => `<option value="${opt}" ${opt === selectedAuth ? 'selected' : ''}>${opt}</option>`).join('')}
            </select>
          </td>

          <!-- Col 7: AI Dev Tool Dropdown -->
          <td class="py-2.5 px-3">
            <select data-action="change-metadata" data-repo-id="${r.id}" data-repo-name="${r.name}" data-field="assignedAiTool" class="vault-table-select">
              ${aiToolOptions.map(opt => `<option value="${opt}" ${opt === selectedAiTool ? 'selected' : ''}>${opt}</option>`).join('')}
            </select>
          </td>

          <!-- Col 8: Prompt / Explainer Tool Dropdown -->
          <td class="py-2.5 px-3">
            <select data-action="change-metadata" data-repo-id="${r.id}" data-repo-name="${r.name}" data-field="assignedPromptTool" class="vault-table-select">
              ${promptOptions.map(opt => `<option value="${opt}" ${opt === selectedPromptTool ? 'selected' : ''}>${opt}</option>`).join('')}
            </select>
          </td>

          <!-- Col 9: Updated -->
          <td class="py-2.5 px-3 text-right">
            <span class="text-xs text-[#8b949e] font-mono" title="${new Date(r.updated_at).toLocaleString()}">${relativeTime}</span>
          </td>
        </tr>
      `;
    });

    html += `</tbody></table></div></div>`;
    container.innerHTML = html;
    this.bindRowEvents(container);
  }

  // =========================================================================
  // Responsive Grid View
  // =========================================================================
  renderGridView(container) {
    let html = `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">`;

    this.filteredRepositories.forEach(r => {
      const inspect = this.inspections[r.name] || {};
      const meta = this.metadata[r.id] || {};
      const langColor = this.getLanguageColor(r.language);
      const relativeTime = this.formatRelativeTime(r.updated_at);
      const linkedVaults = synergy.getLinksForRepo(r.id);
      const selectedDb = meta.detected_db || meta.detectedDb || (inspect.database?.detected ? inspect.database.type : 'None');
      const selectedAuth = meta.detected_auth || meta.detectedAuth || (inspect.auth?.detected ? 'JWT' : 'None');

      html += `
        <div class="bg-[#161b22] border border-[#30363d] rounded-xl p-4 flex flex-col justify-between hover:border-[#58a6ff]/50 transition shadow-sm space-y-3">
          <div>
            <div class="flex items-center justify-between gap-2">
              <a href="${r.html_url}" target="_blank" class="font-bold text-[#58a6ff] text-base hover:underline truncate">${r.name}</a>
              <div class="flex items-center gap-1">
                ${r.private ? '<span class="px-1.5 py-0.2 rounded text-[10px] bg-[#30363d] text-[#e6edf3]">Private</span>' : '<span class="px-1.5 py-0.2 rounded text-[10px] bg-[#238636]/30 text-[#3fb950]">Public</span>'}
                ${r.fork ? '<span class="px-1.5 py-0.2 rounded text-[10px] bg-amber-950/80 text-amber-400 border border-amber-800">Fork</span>' : ''}
              </div>
            </div>
            <p class="text-xs text-[#8b949e] mt-1.5 line-clamp-2">${r.description || 'No description provided.'}</p>

            <div class="mt-3 flex items-center gap-2 flex-wrap text-xs">
              <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full inline-block" style="background-color: ${langColor}"></span>
                <span class="text-[#e6edf3] font-medium text-[11px]">${r.language || 'None'}</span>
              </div>
              ${selectedDb !== 'None' ? `<span class="px-1.5 py-0.5 rounded text-[10px] bg-emerald-950 text-emerald-300 border border-emerald-800">${selectedDb}</span>` : ''}
              ${selectedAuth !== 'None' ? `<span class="px-1.5 py-0.5 rounded text-[10px] bg-purple-950 text-purple-300 border border-purple-800">${selectedAuth}</span>` : ''}
              ${linkedVaults.length > 0 ? `<button data-action="open-vault-for-repo" data-repo-id="${r.id}" data-name="${r.name}" class="px-1.5 py-0.5 rounded text-[10px] bg-indigo-950 text-indigo-300 border border-indigo-800 cursor-pointer">🔑 ${linkedVaults.length} Vault</button>` : ''}
            </div>
          </div>

          <div class="pt-3 border-t border-[#30363d] flex items-center justify-between text-xs text-[#8b949e]">
            <span class="font-mono text-[11px]">${relativeTime}</span>
            <div class="flex items-center gap-1">
              <button data-action="open-notes" data-id="${r.id}" data-name="${r.name}" class="p-1 hover:text-[#58a6ff]" title="Work Notes">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button data-action="copy-clone" data-url="${r.clone_url}" class="p-1 hover:text-[#e6edf3]" title="Copy Clone URL">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              </button>
            </div>
          </div>
        </div>
      `;
    });

    html += `</div>`;
    container.innerHTML = html;
    this.bindRowEvents(container);
  }

  bindRowEvents(container) {
    // Dropdown updates
    container.querySelectorAll('[data-action="change-metadata"]').forEach(select => {
      select.addEventListener('change', (e) => {
        const repoId = e.target.dataset.repoId;
        const repoName = e.target.dataset.repoName;
        const field = e.target.dataset.field;
        const val = e.target.value;
        this.saveMetadata(repoId, { [field]: val }, repoName);
      });
    });

    // Copy repo name
    container.querySelectorAll('[data-action="copy-name"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await security.copyToClipboard(btn.dataset.name);
        toast.success(`Copied: ${btn.dataset.name}`);
      });
    });

    // Copy clone URL
    container.querySelectorAll('[data-action="copy-clone"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await security.copyToClipboard(btn.dataset.url);
        toast.success('Clone URL copied to clipboard');
      });
    });

    // Dev Notes Drawer
    container.querySelectorAll('[data-action="open-notes"]').forEach(btn => {
      btn.addEventListener('click', () => {
        notes.open(parseInt(btn.dataset.id), btn.dataset.name);
      });
    });

    // Scan AST
    container.querySelectorAll('[data-action="inspect-repo"]').forEach(btn => {
      btn.addEventListener('click', () => {
        this.inspectRepo(btn.dataset.name, btn.dataset.branch, btn.dataset.pushed);
      });
    });

    // Open Vault for Repo (Synergy)
    container.querySelectorAll('[data-action="open-vault-for-repo"]').forEach(btn => {
      btn.addEventListener('click', () => {
        synergy.openVaultForRepo(btn.dataset.repoId, btn.dataset.name);
      });
    });
  }

  // =========================================================================
  // Batch Actions
  // =========================================================================
  async copyAllVisibleNames() {
    if (this.filteredRepositories.length === 0) {
      toast.error('No visible repositories to copy');
      return;
    }
    const names = this.filteredRepositories.map(r => r.name).join('\n');
    await security.copyToClipboard(names);
    toast.success(`Copied ${this.filteredRepositories.length} repository names to clipboard`);
  }

  async copyCloneUrls() {
    if (this.filteredRepositories.length === 0) {
      toast.error('No visible repositories');
      return;
    }
    const urls = this.filteredRepositories.map(r => `git clone ${r.clone_url}`).join('\n');
    await security.copyToClipboard(urls);
    toast.success(`Copied ${this.filteredRepositories.length} clone commands to clipboard`);
  }

  async copySshUrls() {
    if (this.filteredRepositories.length === 0) {
      toast.error('No visible repositories');
      return;
    }
    const urls = this.filteredRepositories.map(r => r.ssh_url).join('\n');
    await security.copyToClipboard(urls);
    toast.success(`Copied ${this.filteredRepositories.length} SSH URLs to clipboard`);
  }

  async copyMarkdownTable() {
    if (this.filteredRepositories.length === 0) {
      toast.error('No visible repositories');
      return;
    }
    let md = '| Repository | Language | Description | Database | Auth |\n| --- | --- | --- | --- | --- |\n';
    this.filteredRepositories.forEach(r => {
      const meta = this.metadata[r.id] || {};
      const db = meta.detected_db || meta.detectedDb || 'None';
      const auth = meta.detected_auth || meta.detectedAuth || 'None';
      md += `| [${r.name}](${r.html_url}) | ${r.language || 'None'} | ${r.description || ''} | ${db} | ${auth} |\n`;
    });
    await security.copyToClipboard(md);
    toast.success(`Copied Markdown table for ${this.filteredRepositories.length} repositories`);
  }

  exportFilteredJson() {
    if (this.filteredRepositories.length === 0) {
      toast.error('No repositories to export');
      return;
    }
    const exportData = this.filteredRepositories.map(r => ({
      ...r,
      metadata: this.metadata[r.id] || {},
    }));
    const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `polymerops-repos-export-${new Date().toISOString().slice(0, 10)}.json`;
    a.click();
    URL.revokeObjectURL(a);
    toast.success(`Exported ${this.filteredRepositories.length} repositories to JSON`);
  }
}

export const explorer = new ExplorerModule();
