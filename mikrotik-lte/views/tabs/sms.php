<!-- SMS Messenger-Style Threads View -->
<div x-show="tab==='inbox'" x-cloak class="flex h-[calc(100vh-140px)] border border-slate-700/80 rounded-xl overflow-hidden bg-slate-900 shadow-xl">

  <!-- Left Sidebar: Conversations List -->
  <div class="w-full md:w-1/3 border-r border-slate-700/80 flex flex-col bg-slate-900"
       :class="activePhone ? 'hidden md:flex' : 'flex'">
    
    <!-- Sidebar Header & Search -->
    <div class="p-3.5 border-b border-slate-700/80 bg-slate-850 bg-slate-800/60 flex items-center justify-between gap-2">
      <div class="relative flex-1">
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
        <input x-model="inboxSearch" type="text" placeholder="Search conversations..."
               class="w-full pl-8 pr-3 py-1.5 text-xs bg-slate-900 border border-slate-700 rounded-lg text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
      </div>
      <button @click="activePhone='NEW'; compose.phone=''; compose.message='';"
              class="w-8 h-8 rounded-lg bg-indigo-600/30 hover:bg-indigo-600 border border-indigo-500/50 text-indigo-300 hover:text-white transition flex items-center justify-center flex-shrink-0"
              title="New message">
        <i class="fas fa-plus text-xs"></i>
      </button>
      <button @click="fetchInbox()" class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-400 hover:text-slate-200 transition flex items-center justify-center flex-shrink-0"
              title="Sync SMS from Router">
        <i class="fas fa-sync-alt text-xs" :class="{'fa-spin': inboxLoading}"></i>
      </button>
    </div>

    <!-- Conversations List -->
    <div class="flex-1 overflow-y-auto scrollbar-thin divide-y divide-slate-800/80">
      <template x-for="(threadMsgs, phone) in groupedMessages()" :key="phone">
        <div @click="activePhone=phone; compose.message='';"
             class="p-3.5 cursor-pointer hover:bg-slate-800/60 transition group flex items-start gap-3"
             :class="activePhone === phone ? 'bg-slate-800/90 border-l-2 border-indigo-500' : ''">
          <div class="w-9 h-9 rounded-full bg-indigo-900/40 border border-indigo-700/40 flex items-center justify-center flex-shrink-0 mt-0.5">
            <i class="fas fa-user text-indigo-300 text-xs"></i>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between mb-1">
              <p class="font-semibold text-sm text-slate-100 truncate" x-text="phone"></p>
              <span class="text-[10px] text-slate-500 flex-shrink-0 ml-2" x-text="(threadMsgs[threadMsgs.length - 1] || {}).timestamp || ''"></span>
            </div>
            <p class="text-xs text-slate-400 truncate line-clamp-1" x-text="(threadMsgs[threadMsgs.length - 1] || {}).message || ''"></p>
          </div>
        </div>
      </template>

      <!-- Empty state when no threads -->
      <div x-show="Object.keys(groupedMessages()).length === 0" class="p-8 text-center text-slate-500">
        <i class="fas fa-inbox text-3xl mb-2 opacity-50"></i>
        <p class="text-xs" x-text="inboxSearch ? 'No matching conversations' : 'No messages in inbox'"></p>
      </div>
    </div>
  </div>

  <!-- Right Main: Active Conversation Thread -->
  <div class="flex-1 flex flex-col bg-slate-950"
       :class="activePhone ? 'flex' : 'hidden md:flex'">
    
    <!-- Thread Header -->
    <div class="px-4 py-3 border-b border-slate-700/80 flex justify-between items-center bg-slate-900/90">
      <div class="flex items-center gap-2.5">
        <button @click="activePhone=null" class="md:hidden text-slate-400 hover:text-slate-200 p-1 mr-1" title="Back to conversations">
          <i class="fas fa-arrow-left"></i>
        </button>
        <div>
          <h3 class="font-semibold text-sm text-white leading-tight"
              x-text="activePhone === 'NEW' ? 'New Message' : (activePhone || 'Select a thread')"></h3>
          <p x-show="activePhone && activePhone !== 'NEW'" class="text-[11px] text-slate-400"
             x-text="((messages[activePhone] || []).length) + ' messages'"></p>
        </div>
      </div>
      
      <div class="flex items-center gap-2">
        <button x-show="activePhone && activePhone !== 'NEW'"
                @click="bulkDeleteThread(activePhone)"
                class="px-2.5 py-1 text-xs rounded-lg text-red-400 hover:text-red-300 hover:bg-red-950/40 border border-red-800/40 transition flex items-center gap-1.5"
                title="Delete entire conversation">
          <i class="fas fa-trash text-xs"></i>
          <span class="hidden sm:inline">Delete Thread</span>
        </button>
      </div>
    </div>

    <!-- Messages Container -->
    <div class="flex-1 overflow-y-auto p-4 space-y-3 scrollbar-thin" x-ref="threadContainer">
      
      <!-- Placeholder when no thread is selected -->
      <div x-show="!activePhone" class="h-full flex flex-col items-center justify-center text-slate-600">
        <div class="w-14 h-14 rounded-full bg-slate-900 border border-slate-800 flex items-center justify-center mb-3">
          <i class="fas fa-comments text-2xl text-slate-600"></i>
        </div>
        <p class="text-sm font-medium text-slate-400">Select a conversation or start a new message</p>
        <p class="text-xs text-slate-600 mt-1">Incoming SMS automatically reassemble and update in real-time</p>
      </div>

      <!-- Messages loop for active conversation -->
      <template x-if="activePhone && activePhone !== 'NEW'">
        <template x-for="m in (messages[activePhone] || [])" :key="m.id">
          <!-- Sent on Left, Received on Right -->
          <div class="flex items-end gap-1.5 group" :class="m.type === 'sent' ? 'justify-start' : 'justify-end'">
            
            <!-- Action button for Sent message (right side of bubble) -->
            <button x-show="m.type === 'sent'" @click="deleteSingle(m.id)"
                    class="opacity-0 group-hover:opacity-100 text-slate-600 hover:text-red-400 p-1 mb-1 transition-opacity order-2"
                    title="Delete message">
              <i class="fas fa-trash text-[10px]"></i>
            </button>

            <!-- Action button for Received message (left side of bubble) -->
            <button x-show="m.type !== 'sent'" @click="deleteSingle(m.id)"
                    class="opacity-0 group-hover:opacity-100 text-slate-600 hover:text-red-400 p-1 mb-1 transition-opacity order-1"
                    title="Delete message">
              <i class="fas fa-trash text-[10px]"></i>
            </button>

            <!-- Bubble -->
            <div class="max-w-[78%] px-4 py-2.5 rounded-2xl text-sm shadow-md transition border"
                 :class="m.type === 'sent'
                   ? 'bg-indigo-700/80 border-indigo-600/70 text-white rounded-bl-sm order-1'
                   : 'bg-slate-800 border-slate-700 text-slate-100 rounded-br-sm order-2'">
              <p class="whitespace-pre-wrap break-words select-text text-sm leading-relaxed" x-text="m.message"></p>
              <div class="flex items-center gap-1 mt-1 text-[10px] text-slate-300/70"
                   :class="m.type === 'sent' ? 'justify-start' : 'justify-end'">
                <span x-text="m.timestamp"></span>
              </div>
            </div>

          </div>
        </template>
      </template>
    </div>

    <!-- Compose & Reply Input Area -->
    <div class="p-3.5 bg-slate-900 border-t border-slate-700/80">
      <div class="flex gap-2">
        <input x-show="!activePhone || activePhone === 'NEW'"
               x-model="compose.phone" type="text" placeholder="Phone Number (e.g. +84...)"
               class="w-1/3 sm:w-1/4 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition">
        
        <input x-model="compose.message" type="text"
               :placeholder="activePhone && activePhone !== 'NEW' ? 'Reply to ' + activePhone + '...' : 'Type a message...'"
               class="flex-1 px-3.5 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 transition"
               @keydown.enter="sendSms()">
        
        <button @click="sendSms()" :disabled="compose.sending || (!compose.message.trim()) || (!activePhone && !compose.phone.trim())"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-medium transition disabled:opacity-40 flex items-center gap-1.5 flex-shrink-0">
          <i :class="compose.sending ? 'fas fa-spinner fa-spin' : 'fas fa-paper-plane'" class="text-xs"></i>
          <span x-text="compose.sending ? 'Sending...' : 'Send'"></span>
        </button>
      </div>
    </div>

  </div>
</div>
