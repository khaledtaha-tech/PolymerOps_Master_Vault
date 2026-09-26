<?php
declare(strict_types=1);
/**
 * PolymerOps Master Vault - Unified DevOps Hub & Credential Platform
 * 
 * GitHub: https://github.com/khaledtaha-tech/PolymerOps_Master_Vault
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
    <div class="max-w-[99%] mx-auto px-4 py-2.5 flex flex-wrap items-center justify-between gap-3">
      
      <!-- Brand & View Tabs -->
      <div class="flex items-center gap-6">
        <div class="flex items-center gap-2.5">
          <div class="bg-indigo-600 p-2 rounded-lg text-white shadow-sm flex-shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          </div>
          <div>
            <h1 class="text-sm sm:text-base font-bold tracking-wide text-[#e6edf3]">PolymerOps Master Vault</h1>
            <p class="text-[10px] text-[#8b949e]">Unified DevOps Hub & Credential Platform</p>
          </div>
        </div>

        <!-- Navigation Tabs -->
        <nav class="hidden md:flex items-center gap-1">
          <button data-view-tab="explorer" class="px-3 py-1.5 text-xs font-semibold text-[#58a6ff] border-b-2 border-[#58a6ff] transition">
            Repositories
          </button>
          <button data-view-tab="vault" class="px-3 py-1.5 text-xs font-semibold text-[#8b949e] hover:text-[#e6edf3] transition">
            Credential Vault
          </button>
          <button data-view-tab="tools" class="px-3 py-1.5 text-xs font-semibold text-[#8b949e] hover:text-[#e6edf3] transition">
            Generator & Tools
          </button>
        </nav>
      </div>

      <!-- Status Chips & User Profile -->
      <div class="flex items-center gap-2.5 flex-wrap">
        <div id="statusPillDb" class="hidden sm:flex"></div>
        <div id="statusPillGithub" class="hidden sm:flex"></div>

        <button id="btnGlobalNotes" class="btn-secondary text-xs py-1.5 px-3" title="Work Notes & Progress">
          <svg class="w-3.5 h-3.5 text-[#58a6ff]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
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
  <main class="flex-1 w-full max-w-[99%] mx-auto px-3 sm:px-4 py-5 space-y-5">

    <!-- =======================================================================
         VIEW 1: REPOSITORIES EXPLORER
         ======================================================================= -->
    <section id="viewExplorer" class="space-y-4">
      
      <!-- Top Metrics -->
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

      <!-- Explorer Header Toolbar & Controls -->
      <div class="bg-[#161b22] border border-[#30363d] rounded-xl p-3 space-y-3 shadow-sm">
        
        <!-- Row 1: Telemetry, Sync, Batch Actions -->
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs border-b border-[#30363d]/80 pb-3">
          
          <!-- Telemetry: Live Rate Limits & Last Sync -->
          <div class="flex items-center gap-4 flex-wrap">
            <div id="rateLimitCounter" class="flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-[#0d1117] border border-[#30363d] text-[#8b949e]">
              <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
              <span>Rate limit loading...</span>
            </div>
            <div id="lastSyncTimestamp" class="text-[#8b949e]">
              Last sync: —
            </div>
          </div>

          <!-- Actions: Sync & Batch Copy -->
          <div class="flex items-center gap-2 flex-wrap">
            <button id="btnRefreshRepos" class="btn-secondary text-xs py-1.5 px-3" title="Sync Fresh Data from GitHub API">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              <span>Sync</span>
            </button>

            <!-- Batch Action: Copy All Visible Names -->
            <button id="btnBatchCopyNames" class="btn-secondary text-xs py-1.5 px-3" title="Copy visible repo names as newline-delimited list">
              <svg class="w-3.5 h-3.5 text-[#58a6ff]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              <span>Copy All Visible Names</span>
            </button>

            <!-- More Batch Copies Dropdown -->
            <div class="relative">
              <button id="btnMoreBatchDropdown" class="btn-secondary text-xs py-1.5 px-2.5 flex items-center gap-1">
                <span>More Batch</span>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
              </button>
              <div id="menuMoreBatch" class="hidden absolute right-0 mt-1 w-48 bg-[#161b22] border border-[#30363d] rounded-lg shadow-xl z-20 py-1 text-xs">
                <button id="btnBatchCopyClone" class="w-full text-left px-3 py-1.5 hover:bg-[#21262d] text-[#e6edf3]">Copy Clone URLs</button>
                <button id="btnBatchCopySsh" class="w-full text-left px-3 py-1.5 hover:bg-[#21262d] text-[#e6edf3]">Copy SSH URLs</button>
                <button id="btnBatchCopyMarkdown" class="w-full text-left px-3 py-1.5 hover:bg-[#21262d] text-[#e6edf3]">Copy Markdown Table</button>
                <div class="border-t border-[#30363d] my-1"></div>
                <button id="btnBatchExportJson" class="w-full text-left px-3 py-1.5 hover:bg-[#21262d] text-[#58a6ff]">Export Filtered to JSON</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Row 2: Search, Quick Filters, Language & Sort -->
        <div class="flex flex-wrap items-center justify-between gap-3">
          
          <!-- Search Input -->
          <div class="flex-1 min-w-[240px] relative">
            <input id="inputSearchRepos" type="text" placeholder="Search repos by name, stack, db, auth, or description..." class="vault-input pl-9 text-xs">
            <svg class="w-4 h-4 text-[#8b949e] absolute left-3 top-2.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          </div>

          <!-- Quick Filter Buttons -->
          <div class="flex items-center bg-[#0d1117] border border-[#30363d] rounded-lg p-0.5 text-xs">
            <button data-quick-filter="all" class="px-2.5 py-1 rounded bg-[#30363d] text-[#e6edf3] font-medium transition">All</button>
            <button data-quick-filter="public" class="px-2.5 py-1 rounded text-[#8b949e] hover:text-[#e6edf3] transition">Public</button>
            <button data-quick-filter="private" class="px-2.5 py-1 rounded text-[#8b949e] hover:text-[#e6edf3] transition">Private</button>
            <button data-quick-filter="sources" class="px-2.5 py-1 rounded text-[#8b949e] hover:text-[#e6edf3] transition">Sources Only</button>
            <button data-quick-filter="forks" class="px-2.5 py-1 rounded text-[#8b949e] hover:text-[#e6edf3] transition">Forks</button>
          </div>

          <!-- Language Selector -->
          <select id="selectFilterLanguage" class="vault-input w-auto text-xs py-1.5 min-w-[130px]">
            <option value="all">All Languages</option>
          </select>

          <!-- Sort Selector -->
          <select id="selectSortRepos" class="vault-input w-auto text-xs py-1.5 min-w-[130px]">
            <option value="updated_desc">Recently Updated</option>
            <option value="updated_asc">Oldest Updated</option>
            <option value="name_asc">Name (A-Z)</option>
            <option value="name_desc">Name (Z-A)</option>
            <option value="stars_desc">Most Stars</option>
          </select>

          <!-- View Mode Switcher: Table List vs Grid -->
          <div class="flex items-center bg-[#21262d] border border-[#30363d] rounded-lg p-0.5">
            <button id="btnViewList" class="p-1.5 rounded bg-[#30363d] text-[#e6edf3]" title="Table List View">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            </button>
            <button id="btnViewGrid" class="p-1.5 rounded text-[#8b949e] hover:text-[#e6edf3]" title="Cards Grid View">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            </button>
          </div>

        </div>
      </div>

      <!-- Loading Skeleton -->
      <div id="explorerLoading" class="hidden space-y-3">
        <div class="h-12 bg-[#161b22] border border-[#30363d] rounded-xl skeleton"></div>
        <div class="h-12 bg-[#161b22] border border-[#30363d] rounded-xl skeleton"></div>
        <div class="h-12 bg-[#161b22] border border-[#30363d] rounded-xl skeleton"></div>
      </div>

      <!-- Explorer Content Container -->
      <div id="explorerContainer"></div>
    </section>

    <!-- =======================================================================
         VIEW 2: CREDENTIAL VAULT WORKSPACE
         ======================================================================= -->
    <section id="viewVault" class="hidden">
      <div class="flex flex-col lg:flex-row gap-5 items-start">
        
        <!-- Left Sidebar: Views & Access Groups -->
        <aside class="w-full lg:w-64 bg-[#161b22] border border-[#30363d] rounded-xl p-4 shadow-sm flex-shrink-0">
          <div id="vaultSidebar"></div>
        </aside>

        <!-- Main Vault Workspace -->
        <div class="flex-1 min-w-0 w-full space-y-4">
          
          <!-- Master Action Bar -->
          <div class="bg-[#161b22] border border-[#30363d] rounded-xl p-3 space-y-3 shadow-sm">
            
            <!-- Top Actions & View Switchers -->
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#30363d]/80 pb-3">
              
              <!-- Left: Action Triggers -->
              <div class="flex items-center gap-2 flex-wrap">
                <button id="btnNewAccountModal" class="btn-primary text-xs py-1.5 px-3">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <span>+ New Account</span>
                </button>

                <button id="btnOpenPoolModal" class="btn-secondary text-xs py-1.5 px-3" title="Reusable Credential Pool">
                  <svg class="w-3.5 h-3.5 text-[#58a6ff]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                  <span>Credential Pool</span>
                </button>

                <button id="btnOpenGenModal" class="btn-secondary text-xs py-1.5 px-3" title="Cryptographic Password Generator">
                  <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 2l-2 2m-6 6l-3-3L3 14l3 3 7-7zm0 0l3 3 7-7-3-3-7 7z"/></svg>
                  <span>Generator</span>
                </button>

                <button id="btnOpenRotateModal" class="btn-secondary text-xs py-1.5 px-3" title="Rotate Shared Password">
                  <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                  <span>Rotate Shared</span>
                </button>
              </div>

              <!-- Right: View Switchers (Cards, List, Connections) -->
              <div class="flex items-center bg-[#0d1117] border border-[#30363d] rounded-lg p-0.5 text-xs">
                <button id="btnVaultCardsView" class="px-2.5 py-1 rounded bg-[#30363d] text-[#e6edf3] font-medium flex items-center gap-1.5 transition">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                  <span>Cards</span>
                </button>
                <button id="btnVaultListView" class="px-2.5 py-1 rounded text-[#8b949e] hover:text-[#e6edf3] flex items-center gap-1.5 transition">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                  <span>List</span>
                </button>
                <button id="btnVaultConnectionsView" class="px-2.5 py-1 rounded text-[#8b949e] hover:text-[#e6edf3] flex items-center gap-1.5 transition">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                  <span>Connections</span>
                </button>
              </div>
            </div>

            <!-- Filter Bar: Search, Auth Types, Google Toggle, Show Passwords -->
            <div class="flex flex-wrap items-center justify-between gap-3 text-xs">
              
              <!-- Search Input -->
              <div class="flex-1 min-w-[200px] relative">
                <input id="inputSearchVault" type="text" placeholder="Search accounts by service, username, url, sector..." class="vault-input pl-8 text-xs">
                <svg class="w-3.5 h-3.5 text-[#8b949e] absolute left-2.5 top-2.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              </div>

              <!-- Auth Type Filter -->
              <select id="selectFilterAuth" class="vault-input w-auto text-xs py-1.5 min-w-[130px]">
                <option value="ALL">All Auth Types</option>
                <option value="CREDENTIALS">Standard Credentials</option>
                <option value="GOOGLE_AUTH">Google Identity / SSO</option>
                <option value="NAFAZ">Nafaz National SSO</option>
                <option value="OTP_SMS">SMS OTP</option>
                <option value="GITHUB_AUTH">GitHub OAuth</option>
                <option value="OTHER">Other Authentication</option>
              </select>

              <!-- Quick Filter: @ Google Accounts -->
              <button id="btnToggleGoogleOnly" class="btn-secondary text-xs py-1.5 px-3 flex items-center gap-1.5" title="Filter accounts using Google Auth or Gmail">
                <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                <span>@ Google Accounts</span>
              </button>

              <!-- Reveal / Hide Passwords Toggle -->
              <button id="btnRevealAllPasswords" class="btn-secondary text-xs py-1.5 px-3 flex items-center gap-1.5">
                <svg class="w-4 h-4 text-[#8b949e]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span id="revealAllText">Reveal Passwords</span>
              </button>

              <!-- Critical PIN Unlock Trigger -->
              <button id="btnUnlockCritical" class="btn-secondary text-xs py-1.5 px-3 text-rose-400 border-rose-900/60 hover:border-rose-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span id="pinStatusText">Unlock PIN</span>
              </button>
            </div>
          </div>

          <!-- Vault Container (Cards, List, Connections) -->
          <div id="vaultContainer"></div>
        </div>

      </div>
    </section>

    <!-- =======================================================================
         VIEW 3: GENERATOR & TOOLS
         ======================================================================= -->
    <section id="viewTools" class="hidden space-y-6">
      <div class="max-w-xl mx-auto bg-[#161b22] border border-[#30363d] rounded-xl p-6 shadow-sm space-y-5">
        <div>
          <h3 class="text-base font-bold text-[#e6edf3]">Cryptographic Password Generator</h3>
          <p class="text-xs text-[#8b949e] mt-1">High-entropy client-side password derivation using Web Cryptography API (crypto.getRandomValues).</p>
        </div>

        <div class="space-y-2">
          <div class="flex justify-between text-xs text-[#8b949e]">
            <span>Password Length</span>
            <span id="genLengthValue" class="font-bold text-[#58a6ff]">20</span>
          </div>
          <input id="genLengthSlider" type="range" min="12" max="40" value="20" class="w-full accent-indigo-500">
        </div>

        <div>
          <label class="text-xs text-[#8b949e] block mb-1">Generated Output</label>
          <input id="genResultDisplay" type="text" readonly class="vault-input font-mono select-all text-sm tracking-wider">
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
          <textarea id="notesCompletedText" rows="6" placeholder="Log completed features, commits, or releases..." class="vault-input text-xs"></textarea>
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
       4. Full Account Creation / Edit Modal
       ========================================================================= -->
  <div id="accountModal" class="modal-overlay hidden">
    <div class="modal-card max-w-lg max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4 border-b border-[#30363d] pb-3">
        <h3 id="accountModalTitle" class="text-base font-bold text-[#e6edf3]">Create Vault Account</h3>
        <button data-modal-close class="text-[#8b949e] hover:text-[#e6edf3]">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <form id="formAccount" class="space-y-3.5 text-xs">
        
        <!-- Sector / Access Group & Service Title -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="text-[#8b949e] block mb-1">Access Group / Sector</label>
            <select id="accSector" class="vault-input text-xs"></select>
          </div>
          <div>
            <label class="text-[#8b949e] block mb-1">Service Name / Title *</label>
            <input id="accTitle" type="text" required placeholder="e.g. Hostinger VPS / OpenAI API" class="vault-input text-xs">
          </div>
        </div>

        <!-- Service URL -->
        <div>
          <label class="text-[#8b949e] block mb-1">Website / Platform URL</label>
          <input id="accUrl" type="url" placeholder="https://..." class="vault-input text-xs">
        </div>

        <!-- Username / Email & Password -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="text-[#8b949e] block mb-1">Username / Email</label>
            <input id="accUsername" type="text" placeholder="user@example.com" class="vault-input text-xs">
          </div>
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="text-[#8b949e]">Password</label>
              <button type="button" id="btnGenAccountPass" class="text-[#58a6ff] hover:underline text-[10px]">Generate Pass</button>
            </div>
            <input id="accPassword" type="text" placeholder="Encrypted password" class="vault-input font-mono text-xs">
          </div>
        </div>

        <!-- Auth Type, Logic Rule, Category -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
          <div>
            <label class="text-[#8b949e] block mb-1">Auth Type</label>
            <select id="accAuthType" class="vault-input text-xs">
              <option value="CREDENTIALS">Standard Password</option>
              <option value="GOOGLE_AUTH">Google SSO</option>
              <option value="NAFAZ">Nafaz National SSO</option>
              <option value="OTP_SMS">SMS OTP</option>
              <option value="GITHUB_AUTH">GitHub OAuth</option>
              <option value="OTHER">Other</option>
            </select>
          </div>
          <div>
            <label class="text-[#8b949e] block mb-1">Logic Rule</label>
            <select id="accLogicRule" class="vault-input text-xs">
              <option value="CUSTOM">Custom Credential</option>
              <option value="FULL_SECTOR">Full Sector Inherit</option>
              <option value="SECTOR_PASS_ONLY">Sector Pass Only</option>
              <option value="SHARED_PASS">Team Shared Pass</option>
              <option value="MEMORIZED_PASS">Memorized Pass</option>
            </select>
          </div>
          <div>
            <label class="text-[#8b949e] block mb-1">Category</label>
            <select id="accCategory" class="vault-input text-xs">
              <option value="GENERAL">General</option>
              <option value="WORK">Work / Project</option>
              <option value="CRITICAL_DRIVE">Critical Drive</option>
              <option value="OFFICIAL_GOV">Official / Gov</option>
            </select>
          </div>
        </div>

        <!-- Critical Drive Isolation Toggle -->
        <div class="p-3 bg-[#0d1117] rounded-lg border border-[#30363d] space-y-1.5">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" id="accCriticalToggle" class="accent-rose-500 rounded">
            <span class="font-semibold text-rose-400">Critical Drive Isolation (Master PIN Protected)</span>
          </label>
          <p class="text-[11px] text-[#8b949e]">When enabled, this account is isolated from standard views and requires Master PIN authorization to view or decrypt.</p>
        </div>

        <!-- Smart Link Synergy: Bind to GitHub Repo -->
        <div>
          <label class="text-[#8b949e] block mb-1">Link to GitHub Repository (Synergy)</label>
          <select id="accLinkedRepo" class="vault-input text-xs"></select>
        </div>

        <!-- Database Info & API Keys (Encrypted) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="text-[#8b949e] block mb-1">Database Info (AES-256 Encrypted)</label>
            <textarea id="accDbInfo" rows="2" placeholder="Host, Port, DB Name, User, Pass..." class="vault-input font-mono text-[11px]"></textarea>
          </div>
          <div>
            <label class="text-[#8b949e] block mb-1">API Keys & Secrets (AES-256 Encrypted)</label>
            <textarea id="accApiKey" rows="2" placeholder="API Tokens, Bearer keys..." class="vault-input font-mono text-[11px]"></textarea>
          </div>
        </div>

        <!-- Mobile & Shared with Team -->
        <div class="flex items-center justify-between pt-1">
          <div class="flex-1 mr-3">
            <label class="text-[#8b949e] block mb-1">Recovery / Mobile Number</label>
            <input id="accMobile" type="tel" placeholder="+1..." class="vault-input text-xs">
          </div>
          <div class="pt-4">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" id="accShared" class="accent-indigo-500 rounded">
              <span class="text-xs text-[#e6edf3]">Shared with Team</span>
            </label>
          </div>
        </div>

        <!-- Notes -->
        <div>
          <label class="text-[#8b949e] block mb-1">Account Notes</label>
          <textarea id="accNotes" rows="2" placeholder="Additional access details or instructions..." class="vault-input text-xs"></textarea>
        </div>

        <!-- Form Actions -->
        <div class="flex gap-2 pt-2 border-t border-[#30363d]">
          <button type="button" data-modal-close class="btn-secondary flex-1 text-xs py-2">Cancel</button>
          <button type="submit" class="btn-primary flex-1 text-xs py-2">Save Account</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================================
       5. Access Group / Sector Modal
       ========================================================================= -->
  <div id="sectorModal" class="modal-overlay hidden">
    <div class="modal-card max-w-sm">
      <div class="flex items-center justify-between mb-4 border-b border-[#30363d] pb-2">
        <h3 id="sectorModalTitle" class="text-base font-bold text-[#e6edf3]">Add Access Group</h3>
        <button data-modal-close class="text-[#8b949e] hover:text-[#e6edf3]">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <form id="formSector" class="space-y-3.5 text-xs">
        <div>
          <label class="text-[#8b949e] block mb-1">Group Name *</label>
          <input id="sectorName" type="text" required placeholder="e.g. AI Tools, DevOps, Jobs" class="vault-input text-xs">
        </div>
        <div>
          <label class="text-[#8b949e] block mb-1">Default Username</label>
          <input id="sectorDefaultUser" type="text" placeholder="default-user@example.com" class="vault-input text-xs">
        </div>
        <div>
          <label class="text-[#8b949e] block mb-1">Default Password</label>
          <input id="sectorDefaultPass" type="password" placeholder="Default group password" class="vault-input text-xs">
        </div>
        <div>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" id="sectorShared" class="accent-indigo-500 rounded">
            <span class="text-xs text-[#e6edf3]">Mark as Shared Team Vault</span>
          </label>
        </div>

        <div class="flex gap-2 pt-2 border-t border-[#30363d]">
          <button type="button" data-modal-close class="btn-secondary flex-1 text-xs py-2">Cancel</button>
          <button type="submit" class="btn-primary flex-1 text-xs py-2">Save Group</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================================
       6. Credential Pool Modal
       ========================================================================= -->
  <div id="poolModal" class="modal-overlay hidden">
    <div class="modal-card max-w-md">
      <div class="flex items-center justify-between mb-4 border-b border-[#30363d] pb-2">
        <div>
          <h3 class="text-base font-bold text-[#e6edf3]">Credential Pool</h3>
          <p class="text-[11px] text-[#8b949e]">Reusable usernames, memorized secrets, and tokens</p>
        </div>
        <button data-modal-close class="text-[#8b949e] hover:text-[#e6edf3]">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="space-y-4">
        <!-- Pool items list -->
        <div id="poolItemsList"></div>

        <!-- Add new item to pool -->
        <form id="formAddPoolItem" class="pt-3 border-t border-[#30363d] space-y-2 text-xs">
          <div class="font-semibold text-[#8b949e]">Add New Pool Credential</div>
          <div class="flex gap-2">
            <select id="poolItemType" class="vault-input w-auto text-xs py-1.5">
              <option value="PASSWORD">PASSWORD</option>
              <option value="USERNAME">USERNAME</option>
              <option value="API_KEY">API_KEY</option>
              <option value="TOKEN">TOKEN</option>
            </select>
            <input id="poolItemValue" type="text" required placeholder="Enter credential value..." class="vault-input flex-1 text-xs">
            <button type="submit" class="btn-primary text-xs py-1.5 px-3">Add</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- =========================================================================
       7. Rotate Shared Password Modal
       ========================================================================= -->
  <div id="rotateSharedModal" class="modal-overlay hidden">
    <div class="modal-card max-w-sm">
      <div class="flex items-center justify-between mb-4 border-b border-[#30363d] pb-2">
        <h3 class="text-base font-bold text-emerald-400">Rotate Shared Password</h3>
        <button data-modal-close class="text-[#8b949e] hover:text-[#e6edf3]">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <form id="formRotateShared" class="space-y-3.5 text-xs">
        <div>
          <label class="text-[#8b949e] block mb-1">Target Shared Scope</label>
          <select id="rotateSectorSelect" class="vault-input text-xs"></select>
        </div>

        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="text-[#8b949e]">New High-Entropy Password</label>
            <button type="button" id="btnGenRotatePass" class="text-[#58a6ff] hover:underline text-[10px]">Re-Generate</button>
          </div>
          <input id="rotateNewPassword" type="text" required class="vault-input font-mono text-xs">
        </div>

        <p class="text-[11px] text-[#8b949e]">Rotating will update the password for all team accounts flagged with Shared Pass.</p>

        <div class="flex gap-2 pt-2 border-t border-[#30363d]">
          <button type="button" data-modal-close class="btn-secondary flex-1 text-xs py-2">Cancel</button>
          <button type="submit" class="btn-primary flex-1 text-xs py-2 bg-emerald-600 hover:bg-emerald-700">Apply Rotation</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================================
       8. Authentication Modal
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
       9. Critical Vault PIN Modal
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
          <label class="text-xs text-[#8b949e] block mb-1">Enter Master PIN (4-8 digits)</label>
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
