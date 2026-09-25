/**
 * PolymerOps Master Vault - Credential Vault Module
 */

import { api } from '../core/api-client.js';
import { toast } from '../ui/toast.js';
import { security } from '../core/security.js';
import { modals } from '../ui/modals.js';

export class VaultModule {
  constructor() {
    this.sectors = [];
    this.accounts = [];
    this.activeFilter = 'all';
    this.selectedSectorId = null;
    this.revealAll = false;
    this.dbAvailable = true;
  }

  async loadVault() {
    const container = document.getElementById('vaultContainer');
    if (!container) return;

    try {
      const data = await api.get('api.php?resource=vault');
      if (data.ok && data.vault) {
        this.sectors = data.vault.sectors || [];
        this.accounts = data.vault.accounts || [];
        security.setUnlocked(data.vault.criticalUnlocked);
        this.render();
      }
    } catch (err) {
      if (err.status === 401) {
        container.innerHTML = `
          <div class="text-center py-16 bg-[#161b22] border border-[#30363d] rounded-xl p-8">
            <svg class="w-12 h-12 text-[#d29922] mx-auto mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
            <h3 class="text-base font-bold text-[#e6edf3]">Authentication Required</h3>
            <p class="text-xs text-[#8b949e] mt-1 mb-4">Sign in to access your encrypted credential vault.</p>
            <button id="btnOpenAuthModal" class="btn-primary text-xs">Sign In / Register</button>
          </div>
        `;
        document.getElementById('btnOpenAuthModal')?.addEventListener('click', () => {
          modals.open('authModal');
        });
      } else {
        container.innerHTML = `
          <div class="text-center py-16 bg-[#161b22] border border-[#f85149] rounded-xl p-8">
            <h3 class="text-base font-bold text-[#f85149]">Database Offline</h3>
            <p class="text-xs text-[#8b949e] mt-1">Configure your MySQL connection in config.php to activate the Credential Vault.</p>
          </div>
        `;
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
      case 'CUSTOM':
      default:
        password = account.customPassword || '';
        break;
    }

    return { username, password, sectorName: sector?.name || 'Independent' };
  }

  render() {
    const container = document.getElementById('vaultContainer');
    if (!container) return;

    if (this.accounts.length === 0) {
      container.innerHTML = `
        <div class="text-center py-16 bg-[#161b22] border border-[#30363d] rounded-xl p-8">
          <svg class="w-12 h-12 text-[#8b949e] mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
          <h3 class="text-base font-bold text-[#e6edf3]">No accounts stored in vault</h3>
          <p class="text-xs text-[#8b949e] mt-1 mb-4">Store your first encrypted credential or sector.</p>
          <button id="btnEmptyNewAccount" class="btn-primary text-xs">Create New Account</button>
        </div>
      `;
      return;
    }

    let html = `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">`;

    this.accounts.forEach(acc => {
      const creds = this.resolveCredentials(acc);
      const isCritical = (acc.category === 'CRITICAL_DRIVE');
      const isMasked = !this.revealAll;

      html += `
        <div class="bg-[#161b22] border ${isCritical ? 'border-rose-900/60' : 'border-[#30363d]'} rounded-xl p-4 flex flex-col justify-between shadow-sm">
          <div>
            <div class="flex items-center justify-between gap-2">
              <h4 class="font-bold text-[#e6edf3] text-base truncate">${acc.title}</h4>
              <span class="px-2 py-0.5 rounded text-[10px] bg-[#21262d] text-[#8b949e] border border-[#30363d]">${creds.sectorName}</span>
            </div>
            ${acc.url ? `<a href="${acc.url}" target="_blank" class="text-xs text-[#58a6ff] hover:underline block mt-1 truncate">${acc.url}</a>` : ''}

            <div class="mt-4 space-y-2 bg-[#0d1117] p-3 rounded-lg border border-[#21262d] text-xs">
              <div class="flex items-center justify-between">
                <span class="text-[#8b949e]">User:</span>
                <span class="font-mono text-[#e6edf3] select-all">${creds.username || '(None)'}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-[#8b949e]">Password:</span>
                <span class="font-mono text-[#e6edf3] select-all">${isMasked ? '••••••••••••' : (creds.password || '(None)')}</span>
              </div>
            </div>
          </div>

          <div class="mt-4 pt-3 border-t border-[#30363d] flex items-center justify-between">
            <span class="text-[11px] ${isCritical ? 'text-rose-400 font-medium' : 'text-[#8b949e]'}">${acc.category}</span>
            <button data-action="copy-pass" data-pass="${creds.password}" class="btn-secondary text-[11px] py-1 px-2.5">
              Copy Pass
            </button>
          </div>
        </div>
      `;
    });

    html += `</div>`;
    container.innerHTML = html;

    container.querySelectorAll('[data-action="copy-pass"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await security.copyToClipboard(btn.dataset.pass);
        toast.success('Password copied to clipboard');
      });
    });
  }
}

export const vault = new VaultModule();
