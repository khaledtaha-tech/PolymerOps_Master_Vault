/**
 * PolymerOps Master Vault - Smart Link Synergy Module
 * 
 * Bridges code repositories directly to encrypted vault credentials:
 * - Domain matching (repo.homepage <-> account.url)
 * - Bidirectional navigation between repositories and vault credentials
 * - Relational binding (repo_vault_links) lifecycle
 */

import { api } from '../core/api-client.js';
import { toast } from '../ui/toast.js';

export class SynergyModule {
  constructor() {
    this.links = [];
  }

  async loadLinks() {
    try {
      const data = await api.get('api.php?resource=links');
      if (data.ok) {
        this.links = data.links || [];
        this.renderBadgeIndicators();
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

  openVaultForRepo(repoId, repoName = '') {
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

  async createLink(repositoryId, accountId, linkNature = 'PRIMARY_HOSTING') {
    try {
      const res = await api.post('api.php?resource=links', {
        repositoryId: parseInt(repositoryId),
        accountId: String(accountId),
        linkNature,
      });

      if (res.ok) {
        toast.success('Repository linked to vault credential successfully');
        await this.loadLinks();
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
        return true;
      }
    } catch (err) {
      toast.error(`Could not delete link: ${err.message}`);
    }
    return false;
  }

  renderBadgeIndicators() {
    document.querySelectorAll('[data-repo-id]').forEach(el => {
      const repoId = parseInt(el.dataset.repoId);
      const matched = this.getLinksForRepo(repoId);
      const badgeContainer = el.querySelector('.vault-link-badge-slot');
      if (badgeContainer) {
        if (matched.length > 0) {
          badgeContainer.innerHTML = `
            <button data-action="synergy-open-vault" data-repo-id="${repoId}" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] bg-indigo-950/80 hover:bg-indigo-900 text-indigo-300 border border-indigo-700/60 transition cursor-pointer" title="${matched.length} Linked Vault Credentials - Click to open in Vault">
              <svg class="w-3 h-3 text-indigo-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
              <span>${matched.length} Vault</span>
            </button>
          `;
        } else {
          badgeContainer.innerHTML = '';
        }
      }
    });
  }
}

export const synergy = new SynergyModule();
