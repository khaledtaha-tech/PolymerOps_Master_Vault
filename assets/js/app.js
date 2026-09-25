/**
 * PolymerOps Master Vault - Master Application Orchestrator
 */

import { api } from './core/api-client.js';
import { security } from './core/security.js';
import { toast } from './ui/toast.js';
import { modals } from './ui/modals.js';
import { notes } from './ui/notes.js';
import { explorer } from './modules/explorer.js';
import { vault } from './modules/vault.js';
import { synergy } from './modules/synergy.js';

class PolymerOpsApp {
  constructor() {
    this.currentView = 'explorer';
    this.session = null;
    this.systemStatus = null;
  }

  async init() {
    notes.init();
    this.bindEvents();

    try {
      // 1. Check System Status
      const statusRes = await api.get('api.php?resource=status');
      if (statusRes.ok) {
        this.systemStatus = statusRes;
        api.setCsrfToken(statusRes.csrfToken);
        this.renderStatusPills(statusRes);
      }

      // 2. Discover Session
      const sessionRes = await api.get('auth.php?action=session');
      if (sessionRes.ok) {
        this.session = sessionRes;
        if (sessionRes.csrfToken) api.setCsrfToken(sessionRes.csrfToken);
        this.renderUserPill(sessionRes);
      }

      // 3. Load Explorer and Synergy
      await explorer.loadRepositories();
      await synergy.loadLinks();

      // If user is authenticated, preload vault
      if (this.session && this.session.authenticated) {
        await vault.loadVault();
      }
    } catch (err) {
      console.error('PolymerOps Bootstrap Exception:', err);
    }
  }

  switchView(viewName) {
    this.currentView = viewName;

    const explorerView = document.getElementById('viewExplorer');
    const vaultView = document.getElementById('viewVault');
    const toolsView = document.getElementById('viewTools');

    if (explorerView) explorerView.classList.toggle('hidden', viewName !== 'explorer');
    if (vaultView) vaultView.classList.toggle('hidden', viewName !== 'vault');
    if (toolsView) toolsView.classList.toggle('hidden', viewName !== 'tools');

    document.querySelectorAll('[data-view-tab]').forEach(tab => {
      const active = (tab.dataset.viewTab === viewName);
      tab.classList.toggle('text-[#58a6ff]', active);
      tab.classList.toggle('border-b-2', active);
      tab.classList.toggle('border-[#58a6ff]', active);
      tab.classList.toggle('text-[#8b949e]', !active);
    });

    if (viewName === 'vault') {
      vault.loadVault();
    }
  }

  renderStatusPills(status) {
    const dbPill = document.getElementById('statusPillDb');
    const ghPill = document.getElementById('statusPillGithub');

    if (dbPill) {
      if (status.dbAvailable) {
        dbPill.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-500"></span><span>MySQL Ready</span>';
        dbPill.className = 'flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs bg-emerald-950/60 border border-emerald-800 text-emerald-400';
      } else {
        dbPill.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-500"></span><span>DB Offline (Standalone)</span>';
        dbPill.className = 'flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs bg-amber-950/60 border border-amber-800 text-amber-400';
      }
    }

    if (ghPill) {
      if (status.githubConfigured) {
        ghPill.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-500"></span><span>GitHub Connected</span>';
        ghPill.className = 'flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs bg-emerald-950/60 border border-emerald-800 text-emerald-400';
      } else {
        ghPill.innerHTML = '<span class="w-2 h-2 rounded-full bg-rose-500"></span><span>PAT Missing</span>';
        ghPill.className = 'flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs bg-rose-950/60 border border-rose-800 text-rose-400';
      }
    }
  }

  renderUserPill(session) {
    const userContainer = document.getElementById('userProfileContainer');
    if (!userContainer) return;

    if (session.authenticated && session.user) {
      userContainer.innerHTML = `
        <div class="flex items-center gap-2">
          <div class="text-right hidden sm:block">
            <div class="text-xs text-[#e6edf3] font-medium max-w-[150px] truncate">${session.user.email}</div>
            <div class="text-[10px] text-emerald-400">Authenticated</div>
          </div>
          <button id="btnLogout" class="btn-secondary text-xs px-2.5 py-1">Logout</button>
        </div>
      `;
      document.getElementById('btnLogout')?.addEventListener('click', async () => {
        await api.post('auth.php?action=logout');
        window.location.reload();
      });
    } else {
      userContainer.innerHTML = `
        <button id="btnOpenAuth" class="btn-primary text-xs py-1.5 px-3">Sign In</button>
      `;
      document.getElementById('btnOpenAuth')?.addEventListener('click', () => {
        modals.open('authModal');
      });
    }
  }

  bindEvents() {
    // View Switcher Tabs
    document.querySelectorAll('[data-view-tab]').forEach(tab => {
      tab.addEventListener('click', () => this.switchView(tab.dataset.viewTab));
    });

    // Global Notes Drawer
    document.getElementById('btnGlobalNotes')?.addEventListener('click', () => {
      notes.open(0, 'Global DevOps Notes');
    });

    document.getElementById('btnCloseNotes')?.addEventListener('click', () => {
      notes.close();
    });

    // Refresh Repositories
    document.getElementById('btnRefreshRepos')?.addEventListener('click', () => {
      explorer.loadRepositories(true);
    });

    // Explorer Search and Filters
    const searchInput = document.getElementById('inputSearchRepos');
    if (searchInput) {
      searchInput.addEventListener('input', (e) => {
        explorer.searchQuery = e.target.value.trim();
        explorer.applyFilters();
        explorer.render();
      });
    }

    const sortSelect = document.getElementById('selectSortRepos');
    if (sortSelect) {
      sortSelect.addEventListener('change', (e) => {
        explorer.sortBy = e.target.value;
        explorer.applyFilters();
        explorer.render();
      });
    }

    // Toggle Grid vs List
    const updateViewButtons = (mode) => {
      const listBtn = document.getElementById('btnViewList');
      const gridBtn = document.getElementById('btnViewGrid');
      if (listBtn) {
        listBtn.classList.toggle('bg-[#30363d]', mode === 'list');
        listBtn.classList.toggle('text-[#e6edf3]', mode === 'list');
        listBtn.classList.toggle('text-[#8b949e]', mode !== 'list');
      }
      if (gridBtn) {
        gridBtn.classList.toggle('bg-[#30363d]', mode === 'grid');
        gridBtn.classList.toggle('text-[#e6edf3]', mode === 'grid');
        gridBtn.classList.toggle('text-[#8b949e]', mode !== 'grid');
      }
    };
    updateViewButtons(explorer.viewMode);

    document.getElementById('btnViewList')?.addEventListener('click', () => {
      explorer.viewMode = 'list';
      localStorage.setItem('polymer_view_mode', 'list');
      updateViewButtons('list');
      explorer.render();
    });

    document.getElementById('btnViewGrid')?.addEventListener('click', () => {
      explorer.viewMode = 'grid';
      localStorage.setItem('polymer_view_mode', 'grid');
      updateViewButtons('grid');
      explorer.render();
    });

    // Password Generator in Tools
    const genLength = document.getElementById('genLengthSlider');
    const genOutput = document.getElementById('genResultDisplay');
    const reGenBtn = document.getElementById('btnRegeneratePass');

    if (genLength && genOutput && reGenBtn) {
      const updateGen = () => {
        const len = parseInt(genLength.value);
        document.getElementById('genLengthValue').textContent = len;
        genOutput.value = security.generatePassword(len);
      };

      genLength.addEventListener('input', updateGen);
      reGenBtn.addEventListener('click', updateGen);
      updateGen();

      document.getElementById('btnCopyGenerated')?.addEventListener('click', async () => {
        await security.copyToClipboard(genOutput.value);
        toast.success('Generated password copied to clipboard');
      });
    }

    // Auth Form
    const loginForm = document.getElementById('formLogin');
    if (loginForm) {
      loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('loginEmail').value;
        const password = document.getElementById('loginPassword').value;
        try {
          const res = await api.post('auth.php?action=login', { email, password });
          if (res.ok) {
            toast.success('Signed in successfully');
            modals.close('authModal');
            window.location.reload();
          }
        } catch (err) {
          toast.error(`Login failed: ${err.message}`);
        }
      });
    }

    // Vault Reveal Passwords Toggle
    document.getElementById('btnRevealAllPasswords')?.addEventListener('click', () => {
      const isRevealed = vault.toggleRevealAll();
      const txt = document.getElementById('revealAllText');
      if (txt) txt.textContent = isRevealed ? 'Hide Passwords' : 'Reveal Passwords';
      toast.info(isRevealed ? 'Passwords revealed' : 'Passwords hidden');
    });

    // Vault Critical PIN Unlock Modal
    document.getElementById('btnUnlockCritical')?.addEventListener('click', () => {
      if (security.isCriticalUnlocked()) {
        security.lockCritical();
        vault.loadVault();
        const txt = document.getElementById('pinStatusText');
        if (txt) txt.textContent = 'Unlock PIN';
        toast.info('Critical vault locked');
      } else {
        modals.open('pinModal');
        document.getElementById('inputPinCode')?.focus();
      }
    });

    // PIN Submission Form
    const pinForm = document.getElementById('formPin');
    if (pinForm) {
      pinForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const pinInput = document.getElementById('inputPinCode');
        const pin = pinInput ? pinInput.value.trim() : '';
        try {
          const success = await security.verifyPin(pin);
          if (success) {
            toast.success('Critical Vault unlocked (15 min)');
            modals.close('pinModal');
            if (pinInput) pinInput.value = '';
            const txt = document.getElementById('pinStatusText');
            if (txt) txt.textContent = 'Lock Critical';
            await vault.loadVault();
          } else {
            toast.error('Incorrect PIN code');
          }
        } catch (err) {
          toast.error(`PIN verification error: ${err.message}`);
        }
      });
    }
  }
}

// Global Startup
window.addEventListener('DOMContentLoaded', () => {
  const app = new PolymerOpsApp();
  app.init();
});
