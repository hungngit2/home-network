<!-- Router Settings Tab -->
<div x-show="tab==='settings'" x-cloak>
  <div class="max-w-lg mx-auto space-y-4">
    
    <!-- Router Connection Status Panel -->
    <div class="bg-slate-800/80 border border-slate-700 rounded-xl p-5">
      <h2 class="text-base font-semibold text-slate-100 mb-3 flex items-center gap-2">
        <i class="fas fa-circle-info text-indigo-400"></i> Router Connection Status
      </h2>
      <div class="flex items-center gap-3 p-3.5 rounded-lg border text-sm"
           :class="connected ? 'bg-emerald-900/30 border-emerald-700/60 text-emerald-200' : 'bg-red-900/30 border-red-700/60 text-red-200'">
        <span class="w-3 h-3 rounded-full flex-shrink-0" :class="connected ? 'bg-emerald-400 animate-pulse-dot' : 'bg-red-400'"></span>
        <span class="font-medium" x-text="connected ? routerInfo : 'Disconnected'"></span>
        <button @click="checkStatus()" class="ml-auto text-xs px-2.5 py-1 bg-slate-700 hover:bg-slate-600 rounded text-slate-200 transition flex items-center gap-1.5">
          <i class="fas fa-sync-alt" :class="{'fa-spin': statusLoading}"></i>
          <span>Check</span>
        </button>
      </div>
    </div>

    <!-- Credentials & Communication Configuration -->
    <div class="bg-slate-800/80 border border-slate-700 rounded-xl p-6">
      <h2 class="text-base font-semibold text-slate-100 mb-5 flex items-center gap-2">
        <i class="fas fa-sliders text-indigo-400"></i> Configuration
      </h2>
      
      <div class="space-y-4">
        <div class="grid grid-cols-2 gap-3">
          <div class="col-span-2 sm:col-span-1">
            <label class="block text-xs font-medium text-slate-300 mb-1">Router Address (IP/Host)</label>
            <input x-model="settings.host" type="text" placeholder="192.168.88.1"
                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
          </div>
          <div class="col-span-2 sm:col-span-1">
            <label class="block text-xs font-medium text-slate-300 mb-1">LTE Interface</label>
            <input x-model="settings.port" type="text" placeholder="lte1"
                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-slate-300 mb-1">Username</label>
            <input x-model="settings.username" type="text" placeholder="admin"
                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-300 mb-1">Password</label>
            <div class="relative">
              <input :type="showPassword ? 'text' : 'password'" x-model="settings.password" placeholder="••••••••"
                     class="w-full px-3 py-2 pr-9 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
              <button @click="showPassword = !showPassword" type="button" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300" title="Toggle password">
                <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'" class="text-xs"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="flex flex-wrap gap-x-6 gap-y-2 pt-1">
          <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
            <input type="checkbox" x-model="settings.https" class="rounded border-slate-600 bg-slate-900 text-indigo-500 focus:ring-indigo-500">
            <span>Use HTTPS (REST www-ssl)</span>
          </label>
          <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
            <input type="checkbox" x-model="settings.ssl_verify" class="rounded border-slate-600 bg-slate-900 text-indigo-500 focus:ring-indigo-500">
            <span>Verify SSL Certificate</span>
          </label>
          <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
            <input type="checkbox" x-model="settings.auto_delete" class="rounded border-slate-600 bg-slate-900 text-indigo-500 focus:ring-indigo-500">
            <span>Auto-delete from router on sync</span>
          </label>
          <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
            <input type="checkbox" x-model="settings.sms_transliterate_accents" class="rounded border-slate-600 bg-slate-900 text-indigo-500 focus:ring-indigo-500">
            <span>Sanitize accents for GSM-7 outgoing SMS</span>
          </label>
        </div>

        <div class="grid grid-cols-2 gap-3 pt-1">
          <div>
            <label class="block text-xs font-medium text-slate-300 mb-1">Timeout (seconds)</label>
            <input x-model.number="settings.timeout" type="number" min="1" max="60"
                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-indigo-500 transition">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-300 mb-1">Sync Schedule (Cron expression)</label>
            <input x-model="settings.sync_schedule" type="text" placeholder="0 3 * * *"
                   class="w-full px-3 py-2 bg-slate-900 border border-slate-600 rounded-lg text-sm text-slate-200 font-mono placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
          </div>
        </div>

        <!-- Connection Test Result -->
        <div x-show="settings.testResult" x-cloak class="rounded-lg p-3 border text-xs"
             :class="settings.testResult?.ok ? 'bg-emerald-900/30 border-emerald-700 text-emerald-200' : 'bg-red-900/30 border-red-700 text-red-200'">
          <template x-if="settings.testResult?.ok">
            <div class="space-y-1">
              <div class="font-semibold flex items-center gap-1.5"><i class="fas fa-check-circle"></i> Connection Verified</div>
              <div class="text-emerald-300/80" x-text="'Board: ' + (settings.testResult.data?.board || '')"></div>
              <div class="text-emerald-300/80" x-text="'RouterOS: ' + (settings.testResult.data?.version || '')"></div>
            </div>
          </template>
          <template x-if="!settings.testResult?.ok">
            <div><i class="fas fa-times-circle mr-1"></i> <span x-text="settings.testResult?.error || 'Connection failed'"></span></div>
          </template>
        </div>

        <!-- Buttons -->
        <div class="flex gap-3 pt-2">
          <button @click="testConnection()" :disabled="settings.testing"
                  class="flex items-center gap-2 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-lg text-sm transition disabled:opacity-50">
            <i :class="settings.testing ? 'fas fa-spinner fa-spin' : 'fas fa-plug'" class="text-xs"></i>
            <span x-text="settings.testing ? 'Testing...' : 'Test Connection'"></span>
          </button>
          
          <button @click="saveSettings()" :disabled="settings.saving"
                  class="flex-1 flex items-center justify-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-medium transition disabled:opacity-50">
            <i :class="settings.saving ? 'fas fa-spinner fa-spin' : 'fas fa-save'" class="text-xs"></i>
            <span x-text="settings.saving ? 'Saving...' : 'Save Settings'"></span>
          </button>
        </div>
      </div>

      <div class="mt-5 pt-4 border-t border-slate-700/80">
        <p class="text-xs text-slate-500 leading-relaxed">
          <i class="fas fa-circle-info mr-1 text-slate-400"></i> Ensure the native REST API is enabled on your router in <code class="text-slate-300 bg-slate-900 px-1 py-0.5 rounded">IP > Services > www-ssl</code> (or <code class="text-slate-300 bg-slate-900 px-1 py-0.5 rounded">www</code> for HTTP).
        </p>
      </div>
    </div>

  </div>
</div>
