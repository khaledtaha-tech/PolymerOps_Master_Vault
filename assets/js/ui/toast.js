/**
 * PolymerOps Master Vault - Unified Toast Notification Engine
 */

export class ToastManager {
  constructor() {
    this.container = null;
    this.init();
  }

  init() {
    let el = document.getElementById('polymerToastContainer');
    if (!el) {
      el = document.createElement('div');
      el.id = 'polymerToastContainer';
      el.className = 'fixed bottom-5 right-5 z-50 flex flex-col gap-2 pointer-events-none max-w-sm';
      document.body.appendChild(el);
    }
    this.container = el;
  }

  show(message, type = 'info', duration = 3000) {
    if (!this.container) this.init();

    const toast = document.createElement('div');
    toast.className = 'pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl text-xs sm:text-sm border transition-all duration-300 transform translate-y-2 opacity-0';

    let bgBorderText = 'bg-[#161b22] border-[#30363d] text-[#e6edf3]';
    let iconSvg = '<svg class="w-4 h-4 text-[#58a6ff] shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';

    if (type === 'success') {
      bgBorderText = 'bg-[#161b22] border-[#238636] text-[#3fb950]';
      iconSvg = '<svg class="w-4 h-4 text-[#3fb950] shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>';
    } else if (type === 'error') {
      bgBorderText = 'bg-[#161b22] border-[#f85149] text-[#f85149]';
      iconSvg = '<svg class="w-4 h-4 text-[#f85149] shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
    } else if (type === 'warning') {
      bgBorderText = 'bg-[#161b22] border-[#d29922] text-[#d29922]';
      iconSvg = '<svg class="w-4 h-4 text-[#d29922] shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    }

    toast.className += ' ' + bgBorderText;
    toast.innerHTML = `${iconSvg}<span>${message}</span>`;

    this.container.appendChild(toast);

    requestAnimationFrame(() => {
      toast.classList.remove('translate-y-2', 'opacity-0');
      toast.classList.add('translate-y-0', 'opacity-100');
    });

    setTimeout(() => {
      toast.classList.remove('translate-y-0', 'opacity-100');
      toast.classList.add('translate-y-2', 'opacity-0');
      setTimeout(() => toast.remove(), 300);
    }, duration);
  }

  success(msg, duration) { this.show(msg, 'success', duration); }
  error(msg, duration) { this.show(msg, 'error', duration); }
  warning(msg, duration) { this.show(msg, 'warning', duration); }
  info(msg, duration) { this.show(msg, 'info', duration); }
}

export const toast = new ToastManager();
