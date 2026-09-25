/**
 * PolymerOps Master Vault - Modal Controller
 */

export class ModalManager {
  constructor() {
    this.activeModals = [];
    this.bindEvents();
  }

  bindEvents() {
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.activeModals.length > 0) {
        this.close(this.activeModals[this.activeModals.length - 1]);
      }
    });

    document.addEventListener('click', (e) => {
      const closeBtn = e.target.closest('[data-modal-close]');
      if (closeBtn) {
        const modal = closeBtn.closest('.modal-overlay');
        if (modal) this.close(modal.id);
        return;
      }

      if (e.target.classList.contains('modal-overlay')) {
        this.close(e.target.id);
      }
    });
  }

  open(modalId) {
    const el = document.getElementById(modalId);
    if (!el) return;
    el.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
    if (!this.activeModals.includes(modalId)) {
      this.activeModals.push(modalId);
    }
  }

  close(modalId) {
    const el = document.getElementById(modalId);
    if (!el) return;
    el.classList.add('hidden');
    this.activeModals = this.activeModals.filter(id => id !== modalId);
    if (this.activeModals.length === 0) {
      document.body.classList.remove('overflow-hidden');
    }
  }

  closeAll() {
    [...this.activeModals].forEach(id => this.close(id));
  }
}

export const modals = new ModalManager();
