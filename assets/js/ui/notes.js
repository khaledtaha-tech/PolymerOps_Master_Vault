/**
 * PolymerOps Master Vault - Persistent Dev Notes & Progress Drawer
 */

import { api } from '../core/api-client.js';

export class NotesManager {
  constructor() {
    this.drawerEl = null;
    this.panelEl = null;
    this.backdropEl = null;
    this.completedEl = null;
    this.nextStepsEl = null;
    this.statusEl = null;
    this.activeRepoId = 0;
    this.saveTimeout = null;

    this.STORAGE_KEY_COMPLETED = 'polymer_notes_completed';
    this.STORAGE_KEY_NEXT_STEPS = 'polymer_notes_next_steps';
  }

  init() {
    this.drawerEl = document.getElementById('notesDrawer');
    this.panelEl = document.getElementById('notesDrawerPanel');
    this.backdropEl = document.getElementById('notesDrawerBackdrop');
    this.completedEl = document.getElementById('notesCompletedText');
    this.nextStepsEl = document.getElementById('notesNextStepsText');
    this.statusEl = document.getElementById('notesAutoSaveStatus');

    if (this.completedEl) {
      this.completedEl.addEventListener('input', () => this.handleInput());
    }
    if (this.nextStepsEl) {
      this.nextStepsEl.addEventListener('input', () => this.handleInput());
    }
    if (this.backdropEl) {
      this.backdropEl.addEventListener('click', () => this.close());
    }
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.isOpen()) {
        this.close();
      }
    });
  }

  isOpen() {
    return this.panelEl && this.panelEl.classList.contains('translate-x-0');
  }

  async open(repoId = 0, repoName = '') {
    this.activeRepoId = repoId;
    const titleEl = document.getElementById('notesDrawerTitle');
    if (titleEl) {
      titleEl.textContent = repoName ? `Dev Notes: ${repoName}` : 'Global Work Notes & Progress';
    }

    if (this.drawerEl && this.panelEl && this.backdropEl) {
      this.drawerEl.classList.remove('invisible', 'pointer-events-none');
      this.drawerEl.classList.add('visible', 'pointer-events-auto');
      this.backdropEl.classList.remove('opacity-0', 'pointer-events-none');
      this.backdropEl.classList.add('opacity-100', 'pointer-events-auto');
      this.panelEl.classList.remove('translate-x-full');
      this.panelEl.classList.add('translate-x-0');
      document.body.classList.add('overflow-hidden');
    }

    await this.loadNotes();
  }

  close() {
    if (!this.drawerEl || !this.panelEl || !this.backdropEl) return;
    this.panelEl.classList.remove('translate-x-0');
    this.panelEl.classList.add('translate-x-full');
    this.backdropEl.classList.remove('opacity-100', 'pointer-events-auto');
    this.backdropEl.classList.add('opacity-0', 'pointer-events-none');

    setTimeout(() => {
      this.drawerEl.classList.remove('visible', 'pointer-events-auto');
      this.drawerEl.classList.add('invisible', 'pointer-events-none');
      document.body.classList.remove('overflow-hidden');
    }, 300);
  }

  async loadNotes() {
    try {
      if (this.activeRepoId > 0) {
        const res = await api.get(`api.php?resource=notes&repo_id=${this.activeRepoId}`);
        if (res.ok && res.source === 'database') {
          if (this.completedEl) this.completedEl.value = res.completed || '';
          if (this.nextStepsEl) this.nextStepsEl.value = res.nextSteps || '';
          this.updateCharCounts();
          return;
        }
      }

      // LocalStorage Fallback
      const suffix = this.activeRepoId > 0 ? `_${this.activeRepoId}` : '';
      const completed = localStorage.getItem(this.STORAGE_KEY_COMPLETED + suffix) || '';
      const nextSteps = localStorage.getItem(this.STORAGE_KEY_NEXT_STEPS + suffix) || '';
      if (this.completedEl) this.completedEl.value = completed;
      if (this.nextStepsEl) this.nextStepsEl.value = nextSteps;
      this.updateCharCounts();
    } catch (_) {
      // Fallback gracefully
    }
  }

  handleInput() {
    const completed = this.completedEl ? this.completedEl.value : '';
    const nextSteps = this.nextStepsEl ? this.nextStepsEl.value : '';

    const suffix = this.activeRepoId > 0 ? `_${this.activeRepoId}` : '';
    localStorage.setItem(this.STORAGE_KEY_COMPLETED + suffix, completed);
    localStorage.setItem(this.STORAGE_KEY_NEXT_STEPS + suffix, nextSteps);

    this.updateCharCounts();

    if (this.statusEl) {
      this.statusEl.innerHTML = '<span class="text-[#58a6ff]">Saving...</span>';
    }

    clearTimeout(this.saveTimeout);
    this.saveTimeout = setTimeout(async () => {
      try {
        if (this.activeRepoId > 0) {
          await api.patch(`api.php?resource=notes&repo_id=${this.activeRepoId}`, { completed, nextSteps });
        }
        if (this.statusEl) {
          this.statusEl.innerHTML = '<span class="text-[#3fb950]">Saved to storage</span>';
        }
      } catch (_) {
        if (this.statusEl) {
          this.statusEl.innerHTML = '<span class="text-[#3fb950]">Saved locally</span>';
        }
      }
    }, 500);
  }

  updateCharCounts() {
    const cVal = this.completedEl ? this.completedEl.value.length : 0;
    const nVal = this.nextStepsEl ? this.nextStepsEl.value.length : 0;
    const cCount = document.getElementById('notesCompletedCount');
    const nCount = document.getElementById('notesNextStepsCount');
    if (cCount) cCount.textContent = `${cVal.toLocaleString()} chars`;
    if (nCount) nCount.textContent = `${nVal.toLocaleString()} chars`;
  }
}

export const notes = new NotesManager();
