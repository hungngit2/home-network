<!-- USSD Terminal Tab -->
<div x-show="tab==='ussd'" x-cloak>
  <div class="grid md:grid-cols-2 gap-4">
    <!-- Dial Pad -->
    <div class="bg-slate-800/80 border border-slate-700 rounded-xl p-5">
      <h2 class="text-lg font-semibold text-slate-100 mb-4 flex items-center gap-2">
        <i class="fas fa-hashtag text-indigo-400"></i> USSD Terminal
      </h2>
      <div class="relative mb-4">
        <input x-model="ussd.code" type="text" placeholder="*101#"
               @keydown.enter="sendUssd()"
               class="w-full px-4 py-3 bg-slate-900 border border-slate-600 rounded-lg text-xl text-center font-mono text-slate-100 placeholder-slate-600 focus:outline-none focus:border-indigo-500 transition tracking-widest">
      </div>
      
      <!-- Presets -->
      <div class="flex flex-wrap gap-2 mb-4">
        <template x-for="p in ussd.presets" :key="p.code">
          <button @click="ussd.code=p.code"
                  class="px-3 py-1.5 text-xs bg-slate-700/80 hover:bg-indigo-600/30 border border-slate-600 hover:border-indigo-500 rounded-lg text-slate-300 hover:text-indigo-300 transition font-mono">
            <span x-text="p.code"></span>
            <span x-show="p.label" class="ml-1 text-slate-500 font-sans" x-text="'— ' + p.label"></span>
          </button>
        </template>
      </div>

      <!-- Keypad -->
      <div class="grid grid-cols-3 gap-2 mb-4">
        <template x-for="k in dialKeys" :key="k.v">
          <button @click="ussd.code += k.v" class="dialpad-btn py-3 bg-slate-700/60 hover:bg-slate-600 rounded-lg text-center transition">
            <span class="block text-lg font-semibold text-slate-100" x-text="k.v"></span>
            <span class="block text-[10px] text-slate-500 leading-none" x-text="k.sub || ''"></span>
          </button>
        </template>
      </div>

      <div class="grid grid-cols-3 gap-2">
        <button @click="ussd.code = ussd.code.slice(0, -1)" class="dialpad-btn py-2.5 bg-slate-700/40 hover:bg-slate-600 rounded-lg text-sm text-slate-400 transition" title="Backspace">
          <i class="fas fa-delete-left"></i>
        </button>
        <button @click="sendUssd()" :disabled="!ussd.code.trim() || ussd.loading"
                class="dialpad-btn py-2.5 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-sm text-white font-medium transition disabled:opacity-40 flex items-center justify-center gap-2">
          <i :class="ussd.loading ? 'fas fa-spinner fa-spin' : 'fas fa-paper-plane'" class="text-xs"></i> Send
        </button>
        <button @click="ussd.code = ''" class="dialpad-btn py-2.5 bg-slate-700/40 hover:bg-slate-600 rounded-lg text-sm text-slate-400 transition">
          Clear
        </button>
      </div>
    </div>

    <!-- Console -->
    <div class="bg-slate-900 border border-slate-700 rounded-xl flex flex-col min-h-[400px]">
      <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-800">
        <div class="flex items-center gap-2">
          <div class="flex gap-1.5">
            <span class="w-2.5 h-2.5 rounded-full bg-red-500/80"></span>
            <span class="w-2.5 h-2.5 rounded-full bg-amber-500/80"></span>
            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500/80"></span>
          </div>
          <span class="text-xs text-slate-500 font-mono ml-2">ussd-terminal</span>
        </div>
        <div class="flex items-center gap-1">
          <button @click="cancelUssd()" title="Cancel session" class="p-1.5 text-slate-500 hover:text-red-400 transition">
            <i class="fas fa-stop text-xs"></i>
          </button>
          <button @click="copyConsole()" title="Copy output" class="p-1.5 text-slate-500 hover:text-slate-200 transition">
            <i class="fas fa-copy text-xs"></i>
          </button>
          <button @click="ussd.console = []" title="Clear console" class="p-1.5 text-slate-500 hover:text-slate-200 transition">
            <i class="fas fa-eraser text-xs"></i>
          </button>
        </div>
      </div>
      
      <div class="flex-1 p-4 overflow-y-auto scrollbar-thin font-mono text-sm space-y-2" x-ref="ussdConsole">
        <div x-show="ussd.console.length === 0" class="text-slate-600 text-center py-8">
          <i class="fas fa-terminal text-2xl mb-2"></i>
          <p>Enter a USSD code to begin</p>
        </div>
        <template x-for="(line, i) in ussd.console" :key="i">
          <div>
            <div x-show="line.type === 'cmd'" class="text-indigo-400">
              <span class="text-slate-600 text-xs" x-text="line.time + ' '"></span>$ <span x-text="line.text"></span>
            </div>
            <div x-show="line.type === 'out'" class="text-emerald-300 pl-4 whitespace-pre-wrap select-text" x-text="line.text"></div>
            <div x-show="line.type === 'raw'" class="text-slate-500 pl-4 text-xs whitespace-pre-wrap select-text" x-text="'[raw] ' + line.text"></div>
            <div x-show="line.type === 'status'" class="text-amber-400/80 pl-4 text-xs" x-text="line.text"></div>
            <div x-show="line.type === 'error'" class="text-red-400 pl-4" x-text="line.text"></div>
            <div x-show="line.type === 'info'" class="text-slate-500 pl-4 text-xs" x-text="line.text"></div>
          </div>
        </template>
        <div x-show="ussd.loading" class="text-indigo-400 animate-pulse flex items-center gap-2 pl-4">
          <i class="fas fa-spinner fa-spin text-xs"></i> Waiting for response...
        </div>
      </div>
    </div>
  </div>
</div>
