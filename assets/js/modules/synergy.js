/**
 * PolymerOps Master Vault - Smart Link Synergy Module
 * 
 * Bridges code repositories directly to encrypted vault credentials:
 * - Domain matching (repo.homepage <-> account.url)
 * - Credential injection into repository cards & inspection modal
 * - Database connection string extraction
 */

import { api } from '../core/api-client.js';
import { toast } from '../ui/toast.js';
import { modals } from '../ui/modals.js';

export class SynergyModule {
  constructor() {
    this.links = [];
    this.activeRepoId = 0;
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
    return this.links.filter(l => parseInt(l.repository_id) === parseInt(repoId));
  }

  async createLink(repositoryId, accountId, linkNature = 'PRIMARY_HOSTING') {
    try {
      const res = await api.post('api.php?resource=links', {
        repositoryId,
        accountId,
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
      }
    } catch (err) {
      toast.error(`Could not delete link: ${err.message}`);
    }
  }

  renderBadgeIndicators() {
    // Dynamically enrich repository rows that have active vault links
    document.querySelectorAll('[data-repo-id]').forEach(el => {
      const repoId = parseInt(el.dataset.repoId);
      const matched = this.getLinksForRepo(repoId);
      const badgeContainer = el.querySelector('.vault-link-badge-slot');
      if (badgeContainer) {
        if (matched.length > 0) {
          badgeContainer.innerHTML = `
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] bg-indigo-950 text-indigo-400 border border-indigo-800" title="${matched.length} Linked Vault Credentials">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
              <span>${matched.length} Vault</span>
            </span>
          `;
        } else {
          badgeContainer.innerHTML = '';
        }
      }
    });
  }
}

export const synergy = new SynergyModule();
