<?php
declare(strict_types=1);
/**
 * PolymerOps Master Vault - Unified DevOps Hub & Credential Platform
 * 
 * Target: https://github.com/khaledtaha-tech/PolymerOps_Master_Vault
 */
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0d1117] text-[#e6edf3]">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PolymerOps Master Vault</title>
  <meta name="description" content="Unified DevOps Hub integrating GitHub Repository Reconnaissance and AES-256-GCM Credential Management.">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%236366f1%22 stroke-width=%222%22><path d=%22M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5%22/></svg>">

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            brand: {
              bg: '#0d1117',
              surface: '#161b22',
              subtle: '#21262d',
              border: '#30363d',
              indigo: '#6366f1',
              blue: '#58a6ff',
              green: '#238636',
              amber: '#d29922',
              danger: '#f85149'
            }
          }
        }
      }
    };
  </script>

  <!-- Unified Stylesheet -->
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="min-h-screen flex flex-col bg-[#0d1117] text-[#e6edf3]">

  <!-- =========================================================================
       1. Master Header & Navigation Toolbar
       ========================================================================= -->
  <header class="bg-[#161b22] border-b border-[#30363d] sticky top-0 z-30 shadow-md">
    <div class="max-w-[99%] mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-3">
      
      <!-- Brand & View Tabs -->
      <div class="flex items-center gap-6">
        <div class="flex items-center gap-2.5">
          <div class="bg-indigo-600 p-2 rounded-lg text-white shadow-sm">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          </div>
          <div>
            <h1 class="text-sm sm:text-base font-bold tracking-wide text-[#e6edf3]">PolymerOps Master Vault</h1>
            <p class="text-[10px] text-[#8b949e]">DevOps Hub & Credential Platform</p>
          </div>
        </div>

        <!-- Navigation Tabs -->
        <nav class="hidden md:flex items-center gap-1">
          <button data-view-tab="explorer" class="px-3 py-2 text-xs font-semibold text-[#58a6ff] border-b-2 border-[#58a6ff] transition">
            Repositories
          </button>
          <button data-view-tab="vault" class="px-3 py-2 text-xs font-semibold text-[#8b949e] hover:text-[#e6edf3] transition">
            Credential Vault
          </button>
          <button data-view-tab="tools" class="px-3 py-2 text-xs font-semibold text-[#8b949e] hover:text-[#e6edf3] transition">
            Generator & Tools
          </button>
        </nav>
      </div>

      <!-- Status Chips & User Profile -->
      <div class="flex items-center gap-2.5">
        <div id="statusPillDb" class="hidden sm:flex"></div>
        <div id="statusPillGithub" class="hidden sm:flex"></div>

        <button id="btnGlobalNotes" class="btn-secondary text-xs py-1.5 px-3" title="Work Notes & Progress">
          <svg class="w-4 h-4 text-[#58a6ff]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          <span class="hidden md:inline">Dev Notes</span>
        </button>

        <div id="userProfileContainer"></div>
      </div>
    </div>

    <!-- Mobile View Tabs -->
    <div class="md:hidden flex border-t border-[#30363d] px-4 py-2 gap-2 text-xs">
      <button data-view-tab="explorer" class="text-[#58a6ff] font-semibold">Repositories</button>
      <span class="text-[#30363d]">|</span>
      <button data-view-tab="vault" class="text-[#8b949e]">Vault</button>
      <span class="text-[#30363d]">|</span>
      <button data-view-tab="tools" class="text-[#8b949e]">Generator</button>
    </div>
  </header>

  <!-- =========================================================================
       2. Main Content Viewport
       ========================================================================= -->
  <main class="flex-1 w-full max-w-[99%] mx-auto px-3 sm:px-4 py-6 space-y-6">

    <!-- VIEW 1: REPOSITORIES EXPLORER -->
    <section id="viewExplorer" class="space-y-6">
      <!-- Metric Cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-[#161b22] border border-[#30363d] rounded-xl p-3 shadow-sm">
          <div class="text-[11px] text-[#8b949e]">Total Repositories</div>
          <div id="statTotalRepos" class="text-xl font-bold text-[#e6edf3] mt-1">0</div>
        </div>
        <div class="bg-[#161b22] border border-[#30363d] rounded-xl p-3 shadow-sm">
          <div class="text-[11px] text-[#8b949e]">Public Sources</div>
          <div id="statPublicRepos" class="text-xl font-bold text-[#3fb950] mt-1">0</div>
        </div>
        <div class="bg-[#161b22] border border-[#30363d] rounded-xl p-3 shadow-sm">
          <div class="text-[11px] text-[#8b949e]">Private Repos</div>
          <div id="statPrivateRepos" class="text-xl font-bold text-[#58a6ff] mt-1">0</div>
        </div>
        <div class="bg-[#161b22] border border-[#30363d] rounded-xl p-3 shadow-sm">
          <div class="text-[11px] text-[#8b949e]">Forks</div>
          <div id="statForks" class="text-xl font-bold text-[#d29922] mt-1">0</div>
        </div>
      </div>

      <!-- Explorer Toolbar -->
      <div class="bg-[#161b22] border border-[#30363d] rounded-xl p-3 flex flex-wrap items-center justify-between gap-3 shadow-sm">
        <div class="flex-1 min-w-[200px] relative">
          <input id="inputSearchRepos" type="text" placeholder="Search repositories by name, language, or description..." class="vault-input pl-9">
          <svg class="w-4 h-4 text-[#8b949e] absolute left-3 top-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>

        <div class="flex items-center gap-2">
          <select id="selectSortRepos" class="vault-input w-auto text-xs py-1.5">
            <option value="updated_desc">Recently Updated</option>
            <option value="updated_asc">Oldest Updated</option>
            <option value="name_asc">Name (A-Z)</option>
            <option value="name_desc">Name (Z-A)</option>
            <option value="stars_desc">Most Stars</option>
          </select>

          <div class="flex items-center bg-[#21262d] border border-[#30363d] rounded-lg p-0.5">
            <button id="btnViewList" class="p-1.5 rounded text-[#8b949e] hover:text-[#e6edf3]" title="List View">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            </button>
            <button id="btnViewGrid" class="p-1.5 rounded text-[#8b949e] hover:text-[#e6edf3]" title="Grid View">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            </button>
          </div>

          <button id="btnRefreshRepos" class="btn-secondary text-xs py-1.5 px-3" title="Sync from GitHub">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            <span>Sync</span>
          </button>
        </div>
      </div>

      <!-- Loading State -->
      <div id="explorerLoading" class="hidden space-y-3">
        <div class="h-14 bg-[#161b22] border border-[#30363d] rounded-xl skeleton"></div>
        <div class="h-14 bg-[#161b22] border border-[#30363d] rounded-xl skeleton"></div>
        <div class="h-14 bg-[#161b22] border border-[#30363d] rounded-xl skeleton"></div>
      </div>

      <!-- Container -->
      <div id="explorerContainer"></div>
    </section>

    <!-- VIEW 2: CREDENTIAL VAULT -->
    <section id="viewVault" class="hidden space-y-6">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h2 class="text-lg font-bold text-[#e6edf3]">Credential Vault</h2>
          <p class="text-xs text-[#8b949e]">Sector inheritance & encrypted account passwords</p>
        </div>
        <div class="flex items-center gap-2">
          <button id="btnRevealAllPasswords" class="btn-secondary text-xs py-1.5 px-3">
            <svg class="w-4 h-4 text-[#8b949e]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <span id="revealAllText">Reveal Passwords</span>
          </button>
          <button id="btnUnlockCritical" class="btn-secondary text-xs py-1.5 px-3 text-rose-400 border-rose-900/60 hover:border-rose-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span id="pinStatusText">Unlock PIN</span>
          </button>
        </div>
      </div>
      <div id="vaultContainer"></div>
    </section>

    <!-- VIEW 3: GLOBAL TOOLS & GENERATOR -->
    <section id="viewTools" class="hidden space-y-6">
      <div class="max-w-xl mx-auto bg-[#161b22] border border-[#30363d] rounded-xl p-6 shadow-sm space-y-4">
        <h3 class="text-base font-bold text-[#e6edf3]">Cryptographic Password Generator</h3>
        <p class="text-xs text-[#8b949e]">High-entropy client-side password derivation using Web Cryptography API.</p>

        <div class="space-y-2">
          <div class="flex justify-between text-xs text-[#8b949e]">
            <span>Password Length</span>
            <span id="genLengthValue" class="font-bold text-[#58a6ff]">20</span>
          </div>
          <input id="genLengthSlider" type="range" min="12" max="40" value="20" class="w-full accent-indigo-500">
        </div>

        <div>
          <label class="text-xs text-[#8b949e] block mb-1">Generated Output</label>
          <input id="genResultDisplay" type="text" readonly class="vault-input font-mono select-all text-sm">
        </div>

        <div class="flex gap-2">
          <button id="btnRegeneratePass" class="btn-secondary flex-1 text-xs py-2">Regenerate</button>
          <button id="btnCopyGenerated" class="btn-primary flex-1 text-xs py-2">Copy Password</button>
        </div>
      </div>
    </section>

  </main>

  <!-- =========================================================================
       3. Persistent Dev Notes Slide-Out Drawer
       ========================================================================= -->
  <div id="notesDrawer" class="fixed inset-0 z-50 pointer-events-none transition-visibility duration-300 invisible">
    <div id="notesDrawerBackdrop" class="fixed inset-0 bg-black/60 backdrop-blur-sm opacity-0 transition-opacity duration-300 pointer-events-none"></div>
    <div id="notesDrawerPanel" class="fixed inset-y-0 right-0 max-w-md w-full bg-[#161b22] border-l border-[#30363d] shadow-2xl flex flex-col transform translate-x-full transition-transform duration-300 ease-in-out pointer-events-auto">
      
      <div class="px-5 py-4 border-b border-[#30363d] flex items-center justify-between">
        <div>
          <h3 id="notesDrawerTitle" class="font-bold text-sm text-[#e6edf3]">Dev Notes & Progress</h3>
          <div id="notesAutoSaveStatus" class="text-[11px] text-[#8b949e] mt-0.5">Idle</div>
        </div>
        <button id="btnCloseNotes" class="p-1 rounded text-[#8b949e] hover:text-[#e6edf3]">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto p-5 space-y-4">
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="text-xs font-semibold text-[#8b949e]">Completed Work / Milestones</label>
            <span id="notesCompletedCount" class="text-[10px] text-[#8b949e]">0 chars</span>
          </div>
          <textarea id="notesCompletedText" rows="6" placeholder="Log completed fixes, commits, or releases..." class="vault-input text-xs"></textarea>
        </div>

        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="text-xs font-semibold text-[#8b949e]">Next Actionable Steps</label>
            <span id="notesNextStepsCount" class="text-[10px] text-[#8b949e]">0 chars</span>
          </div>
          <textarea id="notesNextStepsText" rows="6" placeholder="Next tasks, blockers, or credentials to configure..." class="vault-input text-xs"></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================================================================
       4. Authentication Modal
       ========================================================================= -->
  <div id="authModal" class="modal-overlay hidden">
    <div class="modal-card max-w-sm">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-base font-bold text-[#e6edf3]">Vault Sign In</h3>
        <button data-modal-close class="text-[#8b949e] hover:text-[#e6edf3]">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <form id="formLogin" class="space-y-4">
        <div>
          <label class="text-xs text-[#8b949e] block mb-1">Email</label>
          <input id="loginEmail" type="email" required autocomplete="username" class="vault-input">
        </div>
        <div>
          <label class="text-xs text-[#8b949e] block mb-1">Password</label>
          <input id="loginPassword" type="password" required autocomplete="current-password" class="vault-input">
        </div>
        <button type="submit" class="btn-primary w-full text-xs py-2 mt-2">Sign In to Vault</button>
      </form>
    </div>
  </div>

  <!-- =========================================================================
       5. Critical Vault PIN Modal
       ========================================================================= -->
  <div id="pinModal" class="modal-overlay hidden">
    <div class="modal-card max-w-xs">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-base font-bold text-rose-400">Unlock Critical Vault</h3>
        <button data-modal-close class="text-[#8b949e] hover:text-[#e6edf3]">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <form id="formPin" class="space-y-4">
        <div>
          <label class="text-xs text-[#8b949e] block mb-1">Enter Master PIN</label>
          <input id="inputPinCode" type="password" inputmode="numeric" required class="vault-input text-center font-mono text-lg tracking-widest" placeholder="••••">
        </div>
        <button type="submit" class="btn-primary w-full text-xs py-2 bg-rose-600 hover:bg-rose-700">Unlock Secrets</button>
      </form>
    </div>
  </div>

  <!-- Application Bootstrap Module -->
  <script type="module" src="assets/js/app.js"></script>
</body>
</html>
