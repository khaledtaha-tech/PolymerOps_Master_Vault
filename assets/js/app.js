/**
 * PolymerOps Master Vault - Master Application Orchestrator
 * 
 * Coordinates:
 * - Application lifecycle & status discovery
 * - Navigation between Repositories, Vault, and Generator Tools
 * - Modal controllers, form submissions, and user sessions
 * - Batch operations and view mode synchronizations
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
      await synergy.loadLinks();
      await explorer.loadRepositories();

      // If user is authenticated, load vault
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
    } else if (viewName === 'explorer') {
      explorer.render();
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

    // Synergy Event Listener
    window.addEventListener('polymer:switch-view', (e) => {
      const { view } = e.detail || {};
      if (view) this.switchView(view);
    });

    // Global Notes Drawer
    document.getElementById('btnGlobalNotes')?.addEventListener('click', () => {
      notes.open(0, 'Global DevOps Notes');
    });

    document.getElementById('btnCloseNotes')?.addEventListener('click', () => {
      notes.close();
    });

    // =========================================================================
    // Explorer Controls
    // =========================================================================
    
    // Refresh / Sync Repositories
    document.getElementById('btnRefreshRepos')?.addEventListener('click', () => {
      explorer.loadRepositories(true);
    });

    // Search Repositories
    document.getElementById('inputSearchRepos')?.addEventListener('input', (e) => {
      explorer.searchQuery = e.target.value.trim();
      explorer.applyFilters();
      explorer.render();
    });

    // Quick Filter Buttons [All], [Public], [Private], [Sources Only], [Forks]
    document.querySelectorAll('[data-quick-filter]').forEach(btn => {
      btn.addEventListener('click', () => {
        explorer.visibilityFilter = btn.dataset.quickFilter;
        explorer.applyFilters();
        explorer.render();
      });
    });

    // Language Filter Dropdown
    document.getElementById('selectFilterLanguage')?.addEventListener('change', (e) => {
      explorer.languageFilter = e.target.value;
      explorer.applyFilters();
      explorer.render();
    });

    // Sort Dropdown
    document.getElementById('selectSortRepos')?.addEventListener('change', (e) => {
      explorer.sortBy = e.target.value;
      explorer.applyFilters();
      explorer.render();
    });

    // Batch Actions: Copy All Visible Names
    document.getElementById('btnBatchCopyNames')?.addEventListener('click', () => {
      explorer.copyAllVisibleNames();
    });

    // More Batch Actions Dropdown Toggle
    const moreBatchBtn = document.getElementById('btnMoreBatchDropdown');
    const moreBatchMenu = document.getElementById('menuMoreBatch');
    if (moreBatchBtn && moreBatchMenu) {
      moreBatchBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        moreBatchMenu.classList.toggle('hidden');
      });
      document.addEventListener('click', () => {
        moreBatchMenu.classList.add('hidden');
      });
    }

    document.getElementById('btnBatchCopyClone')?.addEventListener('click', () => explorer.copyCloneUrls());
    document.getElementById('btnBatchCopySsh')?.addEventListener('click', () => explorer.copySshUrls());
    document.getElementById('btnBatchCopyMarkdown')?.addEventListener('click', () => explorer.copyMarkdownTable());
    document.getElementById('btnBatchExportJson')?.addEventListener('click', () => explorer.exportFilteredJson());

    // Explorer List vs Grid View Toggle
    const updateExplorerViewButtons = (mode) => {
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
    updateExplorerViewButtons(explorer.viewMode);

    document.getElementById('btnViewList')?.addEventListener('click', () => {
      explorer.viewMode = 'list';
      localStorage.setItem('polymer_view_mode', 'list');
      updateExplorerViewButtons('list');
      explorer.render();
    });

    document.getElementById('btnViewGrid')?.addEventListener('click', () => {
      explorer.viewMode = 'grid';
      localStorage.setItem('polymer_view_mode', 'grid');
      updateExplorerViewButtons('grid');
      explorer.render();
    });

    // =========================================================================
    // Vault Workspace Controls
    // =========================================================================

    // Top-Level Action Triggers
    document.getElementById('btnNewAccountModal')?.addEventListener('click', () => {
      vault.openAccountModal();
    });

    document.getElementById('btnOpenPoolModal')?.addEventListener('click', () => {
      vault.openPoolModal();
    });

    document.getElementById('btnOpenGenModal')?.addEventListener('click', () => {
      this.switchView('tools');
    });

    document.getElementById('btnOpenRotateModal')?.addEventListener('click', () => {
      vault.openRotateSharedModal();
    });

    // View Switchers: Cards, List, Connections
    const updateVaultViewButtons = (mode) => {
      const cardsBtn = document.getElementById('btnVaultCardsView');
      const listBtn = document.getElementById('btnVaultListView');
      const connBtn = document.getElementById('btnVaultConnectionsView');

      [
        { el: cardsBtn, name: 'cards' },
        { el: listBtn, name: 'list' },
        { el: connBtn, name: 'connections' }
      ].forEach(({ el, name }) => {
        if (!el) return;
        const active = (mode === name);
        el.classList.toggle('bg-[#30363d]', active);
        el.classList.toggle('text-[#e6edf3]', active);
        el.classList.toggle('text-[#8b949e]', !active);
      });
    };
    updateVaultViewButtons(vault.viewMode);

    document.getElementById('btnVaultCardsView')?.addEventListener('click', () => {
      vault.viewMode = 'cards';
      localStorage.setItem('polymer_vault_view_mode', 'cards');
      updateVaultViewButtons('cards');
      vault.render();
    });

    document.getElementById('btnVaultListView')?.addEventListener('click', () => {
      vault.viewMode = 'list';
      localStorage.setItem('polymer_vault_view_mode', 'list');
      updateVaultViewButtons('list');
      vault.render();
    });

    document.getElementById('btnVaultConnectionsView')?.addEventListener('click', () => {
      vault.viewMode = 'connections';
      localStorage.setItem('polymer_vault_view_mode', 'connections');
      updateVaultViewButtons('connections');
      vault.render();
    });

    // Search Vault
    document.getElementById('inputSearchVault')?.addEventListener('input', (e) => {
      vault.searchQuery = e.target.value.trim();
      vault.applyFilters();
      vault.render();
    });

    // Auth Type Filter
    document.getElementById('selectFilterAuth')?.addEventListener('change', (e) => {
      vault.activeAuthFilter = e.target.value;
      vault.applyFilters();
      vault.render();
    });

    // @ Google Accounts Filter Toggle
    const googleBtn = document.getElementById('btnToggleGoogleOnly');
    if (googleBtn) {
      googleBtn.addEventListener('click', () => {
        vault.googleOnly = !vault.googleOnly;
        googleBtn.classList.toggle('bg-blue-950', vault.googleOnly);
        googleBtn.classList.toggle('border-blue-700', vault.googleOnly);
        googleBtn.classList.toggle('text-blue-300', vault.googleOnly);
        vault.applyFilters();
        vault.render();
      });
    }

    // Vault Reveal Passwords Toggle
    document.getElementById('btnRevealAllPasswords')?.addEventListener('click', () => {
      const isRevealed = vault.toggleRevealAll();
      const txt = document.getElementById('revealAllText');
      if (txt) txt.textContent = isRevealed ? 'Hide Passwords' : 'Reveal Passwords';
      toast.info(isRevealed ? 'Passwords revealed' : 'Passwords hidden');
    });

    // Critical PIN Unlock Trigger
    document.getElementById('btnUnlockCritical')?.addEventListener('click', async () => {
      if (security.isCriticalUnlocked()) {
        await security.lockCritical();
        await vault.loadVault();
        const txt = document.getElementById('pinStatusText');
        if (txt) txt.textContent = 'Unlock PIN';
        toast.info('Critical vault locked');
      } else {
        modals.open('pinModal');
        document.getElementById('inputPinCode')?.focus();
      }
    });

    // =========================================================================
    // Forms & Modals Handlers
    // =========================================================================

    // Form: Account Creation / Mutation
    const formAccount = document.getElementById('formAccount');
    if (formAccount) {
      formAccount.addEventListener('submit', async (e) => {
        e.preventDefault();
        const isCritical = document.getElementById('accCriticalToggle').checked;
        const categoryVal = isCritical ? 'CRITICAL_DRIVE' : document.getElementById('accCategory').value;

        const payload = {
          id: vault.editingAccountId || undefined,
          sectorId: document.getElementById('accSector').value || null,
          title: document.getElementById('accTitle').value.trim(),
          url: document.getElementById('accUrl').value.trim() || null,
          customUsername: document.getElementById('accUsername').value.trim() || null,
          customPassword: document.getElementById('accPassword').value || null,
          authType: document.getElementById('accAuthType').value,
          logicRule: document.getElementById('accLogicRule').value,
          category: categoryVal,
          mobileNumber: document.getElementById('accMobile').value.trim() || null,
          sharedWithTeam: document.getElementById('accShared').checked,
          dbInfo: document.getElementById('accDbInfo').value.trim() || null,
          apiKey: document.getElementById('accApiKey').value.trim() || null,
          notes: document.getElementById('accNotes').value.trim() || null,
          linkedRepoId: parseInt(document.getElementById('accLinkedRepo').value) || 0,
        };

        await vault.saveAccount(payload);
      });

      document.getElementById('btnGenAccountPass')?.addEventListener('click', () => {
        document.getElementById('accPassword').value = security.generatePassword(22);
        toast.info('Generated password inserted');
      });
    }

    // Form: Sector / Access Group Mutation
    const formSector = document.getElementById('formSector');
    if (formSector) {
      formSector.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
          id: vault.editingSectorId || undefined,
          name: document.getElementById('sectorName').value.trim(),
          defaultUsername: document.getElementById('sectorDefaultUser').value.trim() || null,
          defaultPassword: document.getElementById('sectorDefaultPass').value || null,
          isSharedVault: document.getElementById('sectorShared').checked,
        };
        await vault.saveSector(payload);
      });
    }

    // Form: Add Credential Pool Item
    const formPool = document.getElementById('formAddPoolItem');
    if (formPool) {
      formPool.addEventListener('submit', async (e) => {
        e.preventDefault();
        const itemType = document.getElementById('poolItemType').value;
        const valueInput = document.getElementById('poolItemValue');
        const val = valueInput.value.trim();
        if (val) {
          await vault.addPoolItem(itemType, val);
          valueInput.value = '';
        }
      });
    }

    // Form: Rotate Shared Password
    const formRotate = document.getElementById('formRotateShared');
    if (formRotate) {
      formRotate.addEventListener('submit', async (e) => {
        e.preventDefault();
        const pass = document.getElementById('rotateNewPassword').value.trim();
        const sectorId = document.getElementById('rotateSectorSelect').value || null;
        if (pass) {
          await vault.rotateShared(pass, sectorId);
        }
      });

      document.getElementById('btnGenRotatePass')?.addEventListener('click', () => {
        document.getElementById('rotateNewPassword').value = security.generatePassword(22);
      });
    }

    // Tool: Password Generator Page
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

    // Form: Authentication Login
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

    // Form: PIN Verification
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
