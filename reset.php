<?php
declare(strict_types=1);

/**
 * PolymerOps Master Vault - Password Reset Gateway
 * 
 * Secure standalone token validation and master password mutation screen.
 */
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0d1117] text-[#e6edf3]">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Master Password — PolymerOps Master Vault</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="assets/css/app.css">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center p-4 bg-[#0d1117] text-[#e6edf3] font-sans antialiased">
  
  <div class="w-full max-w-md">
    <!-- Brand Header -->
    <div class="text-center mb-6">
      <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-indigo-950/80 border border-indigo-700/60 text-indigo-400 mb-3 shadow-lg shadow-indigo-950/50">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <h1 class="text-xl font-bold tracking-tight text-[#e6edf3]">PolymerOps Master Vault</h1>
      <p class="text-xs text-[#8b949e] mt-1">Enterprise Credential Recovery Gateway</p>
    </div>

    <!-- Main Card -->
    <div class="bg-[#161b22] border border-[#30363d] rounded-xl shadow-2xl overflow-hidden p-6">
      
      <!-- State 1: Validating Token (Spinner) -->
      <div id="stateLoading" class="py-12 text-center space-y-3">
        <div class="inline-block w-8 h-8 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
        <p class="text-xs text-[#8b949e]">Verifying recovery token authenticity...</p>
      </div>

      <!-- State 2: Invalid / Expired Token -->
      <div id="stateInvalid" class="hidden text-center py-6 space-y-4">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-rose-950/60 border border-rose-800 text-rose-400 mx-auto">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div>
          <h2 class="text-sm font-bold text-rose-400">Recovery Link Invalid or Expired</h2>
          <p id="invalidMessage" class="text-xs text-[#8b949e] mt-1.5 leading-relaxed">This password recovery link has either expired after 1 hour or has already been used.</p>
        </div>
        <div class="pt-2">
          <a href="index.php" class="btn-primary w-full text-xs py-2.5">Return to Sign In</a>
        </div>
      </div>

      <!-- State 3: Active Reset Form -->
      <div id="stateForm" class="hidden space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-[#30363d]">
          <div>
            <h2 class="text-sm font-bold text-[#e6edf3]">Set New Password</h2>
            <p class="text-[11px] text-[#8b949e]">Enter your new vault master password below.</p>
          </div>
          <div id="maskedEmailBadge" class="px-2.5 py-1 rounded bg-[#21262d] border border-[#30363d] text-[11px] font-mono text-emerald-400"></div>
        </div>

        <form id="formResetPassword" class="space-y-4">
          <!-- New Password -->
          <div>
            <label class="text-xs text-[#8b949e] block mb-1">New Password (min 10 characters)</label>
            <div class="relative">
              <input id="newPassword" type="password" required autocomplete="new-password" minlength="10" placeholder="Enter new strong password" class="vault-input pr-10 font-mono text-xs">
              <button type="button" class="password-toggle-btn absolute right-2.5 top-1/2 -translate-y-1/2 text-[#8b949e] hover:text-[#e6edf3] p-1 transition-colors" data-target="newPassword" title="Toggle password visibility">
                <svg class="eye-open w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-closed w-4 h-4 hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>

          <!-- Password Strength Meter -->
          <div>
            <div class="flex items-center justify-between text-[11px] mb-1">
              <span class="text-[#8b949e]">Password Strength</span>
              <span id="strengthText" class="text-rose-400 font-medium">Too weak</span>
            </div>
            <div class="w-full bg-[#0d1117] h-1.5 rounded-full overflow-hidden border border-[#30363d]">
              <div id="strengthBar" class="h-full bg-rose-500 transition-all duration-300 w-1/4"></div>
            </div>
          </div>

          <!-- Confirm Password -->
          <div>
            <label class="text-xs text-[#8b949e] block mb-1">Confirm New Password</label>
            <div class="relative">
              <input id="confirmPassword" type="password" required autocomplete="new-password" minlength="10" placeholder="Re-enter new password" class="vault-input pr-10 font-mono text-xs">
              <button type="button" class="password-toggle-btn absolute right-2.5 top-1/2 -translate-y-1/2 text-[#8b949e] hover:text-[#e6edf3] p-1 transition-colors" data-target="confirmPassword" title="Toggle password visibility">
                <svg class="eye-open w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-closed w-4 h-4 hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <p id="matchError" class="text-[11px] text-rose-400 mt-1 hidden">Passwords do not match.</p>
          </div>

          <button type="submit" id="btnSubmitReset" class="btn-primary w-full text-xs py-2.5 font-semibold mt-2">Update Vault Password</button>
        </form>
      </div>

      <!-- State 4: Success Message -->
      <div id="stateSuccess" class="hidden text-center py-6 space-y-4">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-950/60 border border-emerald-800 text-emerald-400 mx-auto">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
          <h2 class="text-sm font-bold text-emerald-400">Password Updated Successfully</h2>
          <p class="text-xs text-[#8b949e] mt-1.5 leading-relaxed">Your vault master password has been securely regenerated. You may now sign in using your new credentials.</p>
        </div>
        <div class="pt-2">
          <a href="index.php" class="btn-primary w-full text-xs py-2.5 bg-emerald-600 hover:bg-emerald-700">Proceed to Sign In</a>
        </div>
      </div>

    </div>

    <!-- Footer Security Notice -->
    <div class="mt-6 text-center text-[11px] text-[#8b949e] flex items-center justify-center gap-1.5">
      <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      <span>Secured with bcrypt and AES-256-GCM authenticated encryption</span>
    </div>
  </div>

  <script>
    (function() {
      // Reusable Password Toggle
      document.addEventListener('click', function(e) {
        var toggleBtn = e.target.closest('.password-toggle-btn');
        if (!toggleBtn) return;
        e.preventDefault();
        var targetId = toggleBtn.getAttribute('data-target');
        var input = targetId ? document.getElementById(targetId) : toggleBtn.parentElement.querySelector('input');
        if (!input) return;

        var isPassword = (input.type === 'password');
        input.type = isPassword ? 'text' : 'password';

        var eyeOpen = toggleBtn.querySelector('.eye-open');
        var eyeClosed = toggleBtn.querySelector('.eye-closed');
        if (eyeOpen && eyeClosed) {
          eyeOpen.classList.toggle('hidden', isPassword);
          eyeClosed.classList.toggle('hidden', !isPassword);
        }
      });

      // Extract Token
      var urlParams = new URLSearchParams(window.location.search);
      var token = urlParams.get('token') || '';

      var stateLoading = document.getElementById('stateLoading');
      var stateInvalid = document.getElementById('stateInvalid');
      var stateForm = document.getElementById('stateForm');
      var stateSuccess = document.getElementById('stateSuccess');
      var invalidMessage = document.getElementById('invalidMessage');
      var maskedEmailBadge = document.getElementById('maskedEmailBadge');

      if (!token || token.length !== 64) {
        stateLoading.classList.add('hidden');
        stateInvalid.classList.remove('hidden');
        invalidMessage.textContent = 'Invalid recovery token in URL query string.';
        return;
      }

      // Verify Token with Backend
      fetch('auth.php?action=verify_reset_token&token=' + encodeURIComponent(token))
        .then(function(res) { return res.json(); })
        .then(function(data) {
          stateLoading.classList.add('hidden');
          if (data.ok && data.valid) {
            stateForm.classList.remove('hidden');
            if (data.maskedEmail) {
              maskedEmailBadge.textContent = data.maskedEmail;
            }
          } else {
            stateInvalid.classList.remove('hidden');
            if (data.error) invalidMessage.textContent = data.error;
          }
        })
        .catch(function(err) {
          stateLoading.classList.add('hidden');
          stateInvalid.classList.remove('hidden');
          invalidMessage.textContent = 'Network communication error while validating token.';
        });

      // Password Strength & Match Validation
      var newPassInput = document.getElementById('newPassword');
      var confirmPassInput = document.getElementById('confirmPassword');
      var strengthText = document.getElementById('strengthText');
      var strengthBar = document.getElementById('strengthBar');
      var matchError = document.getElementById('matchError');
      var form = document.getElementById('formResetPassword');
      var btnSubmit = document.getElementById('btnSubmitReset');

      function calculateStrength(pwd) {
        if (!pwd || pwd.length < 8) return { score: 1, label: 'Too weak', color: 'bg-rose-500', width: '25%' };
        var score = 0;
        if (pwd.length >= 10) score++;
        if (pwd.length >= 14) score++;
        if (/[A-Z]/.test(pwd)) score++;
        if (/[0-9]/.test(pwd)) score++;
        if (/[^A-Za-z0-9]/.test(pwd)) score++;

        if (score >= 4) return { score: 4, label: 'Very Strong', color: 'bg-emerald-500', width: '100%' };
        if (score === 3) return { score: 3, label: 'Strong', color: 'bg-emerald-400', width: '75%' };
        if (score === 2) return { score: 2, label: 'Fair', color: 'bg-amber-400', width: '50%' };
        return { score: 1, label: 'Weak', color: 'bg-rose-400', width: '30%' };
      }

      newPassInput.addEventListener('input', function() {
        var str = calculateStrength(newPassInput.value);
        strengthText.textContent = str.label;
        strengthBar.className = 'h-full transition-all duration-300 ' + str.color;
        strengthBar.style.width = str.width;
      });

      confirmPassInput.addEventListener('input', function() {
        if (confirmPassInput.value && newPassInput.value !== confirmPassInput.value) {
          matchError.classList.remove('hidden');
        } else {
          matchError.classList.add('hidden');
        }
      });

      form.addEventListener('submit', function(e) {
        e.preventDefault();
        var pwd = newPassInput.value;
        var confirm = confirmPassInput.value;

        if (pwd.length < 10) {
          alert('Password must be at least 10 characters.');
          return;
        }
        if (pwd !== confirm) {
          matchError.classList.remove('hidden');
          return;
        }
        matchError.classList.add('hidden');

        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<span>Updating Password...</span>';

        fetch('auth.php?action=reset_password', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token: token, password: pwd })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
          if (data.ok) {
            stateForm.classList.add('hidden');
            stateSuccess.classList.remove('hidden');
          } else {
            alert(data.error || 'Password update failed.');
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<span>Update Vault Password</span>';
          }
        })
        .catch(function(err) {
          alert('An unexpected network error occurred.');
          btnSubmit.disabled = false;
          btnSubmit.innerHTML = '<span>Update Vault Password</span>';
        });
      });
    })();
  </script>
</body>
</html>
