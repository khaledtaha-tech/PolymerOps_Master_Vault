/**
 * PolymerOps Master Vault - Security & Crypto Module
 * 
 * Provides:
 * - Master PIN unlock lifecycle for Critical Vault elevation
 * - High-entropy cryptographic password generation
 * - Secure clipboard copying with automatic UI feedback
 */

import { api } from './api-client.js';

export class SecurityManager {
  constructor() {
    this.criticalUnlocked = false;
    this.unlockExpiresAt = null;
    this.onStateChange = null;
  }

  isCriticalUnlocked() {
    if (!this.criticalUnlocked || !this.unlockExpiresAt) return false;
    return new Date(this.unlockExpiresAt).getTime() > Date.now();
  }

  setUnlocked(unlocked, expiresAt = null) {
    this.criticalUnlocked = unlocked;
    this.unlockExpiresAt = expiresAt;
    if (this.onStateChange) this.onStateChange(this.isCriticalUnlocked());
  }

  async verifyPin(pin) {
    const res = await api.post('auth.php?action=verify_pin', { pin });
    if (res.ok && res.criticalUnlocked) {
      this.setUnlocked(true, res.expiresAt);
      return true;
    }
    return false;
  }

  async lockCritical() {
    await api.post('auth.php?action=lock_critical');
    this.setUnlocked(false, null);
  }

  /**
   * Cryptographically secure password generation
   */
  generatePassword(length = 20, includeSymbols = true) {
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower = 'abcdefghijkmnopqrstuvwxyz';
    const numbers = '23456789';
    const symbols = '!@#$%^&*_-+=?';

    let alphabet = upper + lower + numbers;
    if (includeSymbols) alphabet += symbols;

    const array = new Uint32Array(length);
    crypto.getRandomValues(array);

    const chars = [];
    chars.push(upper[array[0] % upper.length]);
    chars.push(lower[array[1] % lower.length]);
    chars.push(numbers[array[2] % numbers.length]);
    if (includeSymbols) chars.push(symbols[array[3] % symbols.length]);

    for (let i = chars.length; i < length; i++) {
      chars.push(alphabet[array[i] % alphabet.length]);
    }

    // Fisher-Yates Shuffle
    for (let i = chars.length - 1; i > 0; i--) {
      const j = array[i] % (i + 1);
      [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    return chars.join('');
  }

  async copyToClipboard(text) {
    if (!navigator.clipboard || !navigator.clipboard.writeText) {
      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
      return true;
    }
    await navigator.clipboard.writeText(text);
    return true;
  }
}

export const security = new SecurityManager();
