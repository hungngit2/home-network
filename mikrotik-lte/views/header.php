<!-- Header -->
<header class="sticky top-0 z-30 bg-slate-900/95 backdrop-blur border-b border-slate-800">
  <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-lg bg-indigo-600 flex items-center justify-center flex-shrink-0 shadow-lg shadow-indigo-600/20">
        <i class="fas fa-tower-cell text-white text-sm"></i>
      </div>
      <div>
        <h1 class="text-base font-semibold text-white leading-tight">MikroTik LTE</h1>
        <p class="text-xs text-slate-400" x-text="connected ? (lteStatus?.['current-operator'] || 'Loading...') : 'Disconnected'"></p>
      </div>
    </div>

    <!-- Status Metrics -->
    <div class="flex items-center gap-4 text-xs font-mono text-slate-400">
      <div x-show="lteStatus" class="flex items-center gap-1.5 cursor-pointer group relative">
        <div class="flex items-end gap-0.5 h-3.5">
          <template x-for="i in 5" :key="i">
            <div class="w-1 rounded-sm transition-colors"
                 :class="i <= (lteStatus?.signalStrength?.bars || 0) ? (lteStatus?.signalStrength?.color || 'bg-emerald-500') : 'bg-slate-700'"
                 :style="'height:' + (i * 20) + '%'"></div>
          </template>
        </div>
        <span x-text="(lteStatus?.rssi || 'N/A')"></span>

        <!-- Details Popover -->
        <div class="absolute right-0 top-full mt-2 w-72 bg-slate-850 bg-slate-800 border border-slate-700 rounded-xl p-3.5 shadow-2xl hidden group-hover:block z-50">
          <div class="space-y-1.5 text-xs text-slate-300 font-sans">
            <div class="flex justify-between border-b border-slate-700/60 pb-1 text-slate-400 font-semibold uppercase text-[10px]">Modem Details</div>
            <p><b class="text-slate-400 font-medium">Model:</b> <span class="text-slate-200" x-text="lteStatus?.model || 'N/A'"></span></p>
            <p><b class="text-slate-400 font-medium">IMEI:</b> <span class="font-mono text-slate-200" x-text="lteStatus?.imei || 'N/A'"></span></p>
            <p x-show="lteStatus?.['subscriber-number']"><b class="text-slate-400 font-medium">Phone:</b> <span class="text-emerald-300 font-mono" x-text="lteStatus?.['subscriber-number']"></span></p>
            <p><b class="text-slate-400 font-medium">Uptime:</b> <span class="text-slate-200" x-text="lteStatus?.['session-uptime'] || 'N/A'"></span></p>
            <p x-show="lteStatus?.imsi"><b class="text-slate-400 font-medium">IMSI:</b> <span class="font-mono text-slate-400 text-[11px]" x-text="lteStatus?.imsi"></span></p>
          </div>
        </div>
      </div>

      <span x-show="lteStatus" class="px-2 py-0.5 rounded-full text-[10px] uppercase font-bold tracking-wider"
            :class="lteStatus?.['data-class'] === 'LTE' ? 'bg-emerald-900/60 text-emerald-300 border border-emerald-700/50' : 'bg-blue-900/60 text-blue-300 border border-blue-700/50'"
            x-text="lteStatus?.['data-class']"></span>

      <button @click="fetchLteStatus()" class="hover:text-white transition p-1" title="Refresh LTE Signal">
        <i class="fas fa-sync-alt" :class="{'fa-spin': statusLoading}"></i>
      </button>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <div class="max-w-5xl mx-auto px-4">
    <nav class="flex gap-1 -mb-px overflow-x-auto scrollbar-thin">
      <template x-for="t in tabs" :key="t.id">
        <button @click="switchTab(t.id)"
                class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition whitespace-nowrap"
                :class="tab === t.id ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-slate-200 hover:border-slate-600'">
          <i :class="t.icon" class="text-xs"></i>
          <span x-text="t.label"></span>
        </button>
      </template>
    </nav>
  </div>
</header>
