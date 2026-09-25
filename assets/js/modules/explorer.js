/**
 * PolymerOps Master Vault - GitHub Explorer & Reconnaissance Module
 */

import { api } from '../core/api-client.js';
import { toast } from '../ui/toast.js';
import { notes } from '../ui/notes.js';
import { modals } from '../ui/modals.js';

export class ExplorerModule {
  constructor() {
    this.repositories = [];
    this.filteredRepositories = [];
    this.user = null;
    this.stats = null;
    this.rateLimit = null;
    this.isLoading = false;

    this.searchQuery = '';
    this.visibilityFilter = 'all';
    this.languageFilter = 'all';
    this.sortBy = 'updated_desc';
    this.viewMode = localStorage.getItem('polymer_view_mode') || 'list';

    this.inspections = {};
    this.initLocalStorage();
  }

  initLocalStorage() {
    try {
      const raw = localStorage.getItem('polymer_inspections_cache');
      this.inspections = raw ? JSON.parse(raw) : {};
    } catch (_) {
      this.inspections = {};
    }
  }

  saveInspections() {
    try {
      localStorage.setItem('polymer_inspections_cache', JSON.stringify(this.inspections));
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

    if (this.searchQuery) {
      const q = this.searchQuery.toLowerCase();
      list = list.filter(r => 
        r.name.toLowerCase().includes(q) ||
        (r.description && r.description.toLowerCase().includes(q)) ||
        (r.language && r.language.toLowerCase().includes(q))
      );
    }

    if (this.visibilityFilter === 'public') {
      list = list.filter(r => !r.private);
    } else if (this.visibilityFilter === 'private') {
      list = list.filter(r => r.private);
    } else if (this.visibilityFilter === 'forks') {
      list = list.filter(r => r.fork);
    }

    if (this.languageFilter !== 'all') {
      list = list.filter(r => (r.language || 'Unspecified') === this.languageFilter);
    }

    // Sort
    list.sort((a, b) => {
      if (this.sortBy === 'updated_desc') return new Date(b.updated_at) - new Date(a.updated_at);
      if (this.sortBy === 'updated_asc') return new Date(a.updated_at) - new Date(b.updated_at);
      if (this.sortBy === 'name_asc') return a.name.localeCompare(b.name);
      if (this.sortBy === 'name_desc') return b.name.localeCompare(a.name);
      if (this.sortBy === 'stars_desc') return (b.stargazers_count || 0) - (a.stargazers_count || 0);
      return 0;
    });

    this.filteredRepositories = list;
  }

  async inspectRepo(repoName, branch = 'main', pushedAt = '') {
    toast.info(`Scanning repository AST: ${repoName}...`);
    try {
      const res = await api.get(`api.php?resource=repos&action=inspect&repo=${encodeURIComponent(repoName)}&branch=${encodeURIComponent(branch)}&pushed_at=${encodeURIComponent(pushedAt)}`);
      if (res.ok && res.inspection) {
        this.inspections[repoName] = res.inspection;
        this.saveInspections();
        toast.success(`Inspected ${repoName}: DB [${res.inspection.database.type}], Auth [${res.inspection.auth.detected ? 'Yes' : 'No'}]`);
        this.render();
      }
    } catch (err) {
      toast.error(`Inspection failed: ${err.message}`);
    }
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

  render() {
    const container = document.getElementById('explorerContainer');
    if (!container) return;

    if (this.filteredRepositories.length === 0) {
      container.innerHTML = `
        <div class="text-center py-16 bg-[#161b22] border border-[#30363d] rounded-xl p-8">
          <svg class="w-12 h-12 text-[#8b949e] mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16m-7 6h7"/></svg>
          <h3 class="text-base font-semibold text-[#e6edf3]">No repositories match your filter criteria</h3>
          <p class="text-xs text-[#8b949e] mt-1">Try adjusting search terms or clear visibility filters.</p>
        </div>
      `;
      return;
    }

    if (this.viewMode === 'grid') {
      this.renderGridView(container);
    } else {
      this.renderListView(container);
    }
  }

  renderListView(container) {
    let html = `
      <div class="bg-[#161b22] border border-[#30363d] rounded-xl overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs sm:text-sm">
            <thead class="bg-[#21262d] text-[#8b949e] border-b border-[#30363d] uppercase font-semibold text-[11px] tracking-wider">
              <tr>
                <th class="py-3 px-4">Repository</th>
                <th class="py-3 px-4">Detected Stack</th>
                <th class="py-3 px-4">Language</th>
                <th class="py-3 px-4">Links & Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[#30363d]">
    `;

    this.filteredRepositories.forEach(r => {
      const inspect = this.inspections[r.name];
      const dbTag = inspect?.database?.detected ? `<span class="px-2 py-0.5 rounded text-[11px] bg-emerald-950 text-emerald-400 border border-emerald-800">${inspect.database.type}</span>` : '<span class="text-[#8b949e] text-[11px]">No DB</span>';
      const authTag = inspect?.auth?.detected ? `<span class="px-2 py-0.5 rounded text-[11px] bg-purple-950 text-purple-300 border border-purple-800">Auth</span>` : '';

      html += `
        <tr class="hover:bg-[#21262d]/50 transition">
          <td class="py-3 px-4">
            <div class="flex items-center gap-2">
              <a href="${r.html_url}" target="_blank" class="font-semibold text-[#58a6ff] hover:underline">${r.name}</a>
              ${r.private ? '<span class="px-1.5 py-0.2 rounded text-[10px] bg-[#30363d] text-[#e6edf3]">Private</span>' : '<span class="px-1.5 py-0.2 rounded text-[10px] bg-[#238636]/30 text-[#3fb950]">Public</span>'}
            </div>
            ${r.description ? `<p class="text-xs text-[#8b949e] mt-0.5 line-clamp-1">${r.description}</p>` : ''}
          </td>
          <td class="py-3 px-4">
            <div class="flex items-center gap-1.5 flex-wrap">
              ${dbTag} ${authTag}
              <button data-action="inspect-repo" data-name="${r.name}" data-branch="${r.default_branch}" data-pushed="${r.pushed_at}" class="text-[11px] text-[#58a6ff] hover:underline">Scan AST</button>
            </div>
          </td>
          <td class="py-3 px-4 text-[#8b949e]">
            ${r.language || 'Unspecified'}
          </td>
          <td class="py-3 px-4">
            <div class="flex items-center gap-2">
              <button data-action="open-notes" data-id="${r.id}" data-name="${r.name}" class="p-1 text-[#8b949e] hover:text-[#e6edf3]" title="Work Notes">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button data-action="copy-clone" data-url="${r.clone_url}" class="p-1 text-[#8b949e] hover:text-[#e6edf3]" title="Copy Clone URL">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              </button>
            </div>
          </td>
        </tr>
      `;
    });

    html += `</tbody></table></div></div>`;
    container.innerHTML = html;
    this.bindRowEvents(container);
  }

  renderGridView(container) {
    let html = `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">`;

    this.filteredRepositories.forEach(r => {
      const inspect = this.inspections[r.name];
      const dbTag = inspect?.database?.detected ? `<span class="px-2 py-0.5 rounded text-[11px] bg-emerald-950 text-emerald-400 border border-emerald-800">${inspect.database.type}</span>` : '';

      html += `
        <div class="bg-[#161b22] border border-[#30363d] rounded-xl p-4 flex flex-col justify-between hover:border-[#58a6ff]/50 transition shadow-sm">
          <div>
            <div class="flex items-center justify-between gap-2">
              <a href="${r.html_url}" target="_blank" class="font-bold text-[#58a6ff] text-base hover:underline truncate">${r.name}</a>
              ${r.private ? '<span class="px-2 py-0.5 rounded text-[10px] bg-[#30363d] text-[#e6edf3]">Private</span>' : '<span class="px-2 py-0.5 rounded text-[10px] bg-[#238636]/30 text-[#3fb950]">Public</span>'}
            </div>
            <p class="text-xs text-[#8b949e] mt-2 line-clamp-2">${r.description || 'No description provided.'}</p>
          </div>
          <div class="mt-4 pt-3 border-t border-[#30363d] flex items-center justify-between">
            <span class="text-xs text-[#8b949e] font-medium">${r.language || 'Unspecified'}</span>
            <div class="flex items-center gap-1">
              ${dbTag}
              <button data-action="open-notes" data-id="${r.id}" data-name="${r.name}" class="p-1 text-[#8b949e] hover:text-[#e6edf3]">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
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
    container.querySelectorAll('[data-action="inspect-repo"]').forEach(btn => {
      btn.addEventListener('click', () => {
        this.inspectRepo(btn.dataset.name, btn.dataset.branch, btn.dataset.pushed);
      });
    });

    container.querySelectorAll('[data-action="open-notes"]').forEach(btn => {
      btn.addEventListener('click', () => {
        notes.open(parseInt(btn.dataset.id), btn.dataset.name);
      });
    });

    container.querySelectorAll('[data-action="copy-clone"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await navigator.clipboard.writeText(btn.dataset.url);
        toast.success('Clone URL copied to clipboard');
      });
    });
  }

  renderLoading(loading) {
    const el = document.getElementById('explorerLoading');
    if (el) el.classList.toggle('hidden', !loading);
  }

  renderError(msg) {
    const el = document.getElementById('explorerContainer');
    if (el) {
      el.innerHTML = `
        <div class="bg-[#3d1a1f] border border-[#f85149] rounded-xl p-6 text-center">
          <h3 class="text-base font-bold text-[#f85149]">Failed to load repositories</h3>
          <p class="text-xs text-[#e6edf3] mt-1">${msg}</p>
        </div>
      `;
    }
  }
}

export const explorer = new ExplorerModule();
