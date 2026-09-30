function smsApp() {
  return {
    tab: "inbox",
    tabs: [
      {id: "inbox", label: "SMS", icon: "fas fa-comments"},
      {id: "ussd", label: "USSD", icon: "fas fa-hashtag"},
      {id: "settings", label: "Settings", icon: "fas fa-gear"},
    ],
    connected: false,
    routerInfo: "",
    statusLoading: false,
    messages: {},
    inboxLoading: false,
    inboxSearch: "",
    compose: {phone: "", message: "", sending: false},
    ussd: {
      code: "",
      loading: false,
      console: [],
      presets: [
        {code: "*101#", label: "Balance"},
        {code: "*102#", label: "Number"},
        {code: "*111#", label: "Menu"},
        {code: "*098#", label: "Info"},
      ],
    },
    dialKeys: [
      {v: "1", sub: ""},    {v: "2", sub: "ABC"},  {v: "3", sub: "DEF"},
      {v: "4", sub: "GHI"},  {v: "5", sub: "JKL"},  {v: "6", sub: "MNO"},
      {v: "7", sub: "PQRS"}, {v: "8", sub: "TUV"},  {v: "9", sub: "WXYZ"},
      {v: "*", sub: ""},    {v: "0", sub: "+"},    {v: "#", sub: ""},
    ],
    settings: {
      host: "",
      username: "",
      password: "",
      port: "",
      https: true,
      ssl_verify: false,
      timeout: 10,
      auto_delete: true,
      sync_schedule: "0 3 * * *",
      sms_transliterate_accents: true,
      testing: false,
      saving: false,
      testResult: null
    },
    showPassword: false,
    toasts: [],
    _toastId: 0,
    activePhone: null,
    lteStatus: null,

    init() {
      this.fetchLteStatus();
      setInterval(() => this.fetchLteStatus(), 10000);
      this.checkStatus();
      this.fetchInbox().then(() => {
        const phones = Object.keys(this.messages);
        if (phones.length > 0) this.activePhone = phones[0];
      });
      this.loadSettings();

      // Background SMS auto-sync every 15 seconds
      setInterval(() => {
        if (this.tab === "inbox" && !this.compose.sending) {
          this.fetchInbox(true);
        }
      }, 15000);

      // Auto-scroll conversation to bottom on thread change
      this.$watch('activePhone', () => {
        this.$nextTick(() => {
          if (this.$refs.threadContainer) {
            this.$refs.threadContainer.scrollTop = this.$refs.threadContainer.scrollHeight;
          }
        });
      });
    },

    async fetchLteStatus() {
      try {
        const r = await this.api("lte_monitor");
        if (r.success) {
          this.lteStatus = r.data;
          this.lteStatus.signalStrength = this.calculateSignalBars(this.lteStatus.rssi);
        }
      } catch (e) {
        console.error("LTE monitor failed", e);
      }
    },

    calculateSignalBars(rssi) {
      if (!rssi) return { bars: 0, label: "No Signal", color: "bg-slate-600" };
      const val = parseInt(rssi);
      if (val >= -70) return { bars: 5, label: "Excellent", color: "bg-emerald-500" };
      if (val >= -85) return { bars: 4, label: "Good", color: "bg-emerald-400" };
      if (val >= -100) return { bars: 3, label: "Fair", color: "bg-amber-500" };
      return { bars: 1, label: "Poor", color: "bg-red-500" };
    },

    async deleteSingle(id) {
      if (!confirm("Delete this message?")) return;
      try {
        const r = await this.api("delete", {id: id});
        if (r.success) {
          await this.fetchInbox();
          if (this.activePhone && (!this.messages[this.activePhone] || !this.messages[this.activePhone].length)) {
            const phones = Object.keys(this.messages);
            this.activePhone = phones.length > 0 ? phones[0] : null;
          }
          this.showToast("success", "Deleted");
        } else {
          this.showToast("error", r.error || "Delete failed");
        }
      } catch (e) {
        this.showToast("error", "Delete failed");
      }
    },

    async bulkDeleteThread(phone) {
      if (!confirm("Delete all messages in this thread?")) return;
      const ids = (this.messages[phone] || []).map(m => m.id);
      if (!ids.length) return;
      try {
        const r = await this.api("bulk_delete", {ids: ids});
        if (r.success) {
          await this.fetchInbox();
          const phones = Object.keys(this.messages);
          this.activePhone = phones.length > 0 ? phones[0] : null;
          this.showToast("success", "Thread deleted");
        } else {
          this.showToast("error", r.error || "Delete failed");
        }
      } catch (e) {
        this.showToast("error", "Delete failed");
      }
    },

    switchTab(id) {
      this.tab = id;
      if (id === "inbox") this.fetchInbox();
    },

    showToast(type, message) {
      const id = ++this._toastId;
      this.toasts.push({id, type, message});
      setTimeout(() => this.removeToast(id), 4000);
    },

    removeToast(id) {
      this.toasts = this.toasts.filter(t => t.id !== id);
    },

    async api(action, data = {}) {
      const body = {action, ...data};
      const res = await fetch(window.location.pathname, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(body),
      });
      return res.json();
    },

    async checkStatus() {
      this.statusLoading = true;
      try {
        const r = await this.api("status");
        if (r.success) {
          this.connected = true;
          const d = r.data;
          this.routerInfo = (d.board || "Router") + " · v" + (d.version || "?");
        } else {
          this.connected = false;
          this.routerInfo = "";
        }
      } catch (e) {
        this.connected = false;
        this.routerInfo = "";
      }
      this.statusLoading = false;
    },

    async fetchInbox(silent = false) {
      if (!silent) this.inboxLoading = true;
      try {
        const r = await this.api("inbox");
        if (r.success) {
          this.messages = r.data || {};
          if (!this.activePhone) {
            const phones = Object.keys(this.messages);
            if (phones.length > 0) this.activePhone = phones[0];
          }
        } else if (!silent) {
          this.showToast("error", r.error || "Failed to fetch inbox");
        }
      } catch (e) {
        if (!silent) this.showToast("error", "Network error fetching inbox");
      }
      if (!silent) this.inboxLoading = false;
    },

    groupedMessages() {
      if (!this.inboxSearch.trim()) return this.messages;
      const q = this.inboxSearch.toLowerCase();
      const filtered = {};
      for (const phone in this.messages) {
        if (phone.toLowerCase().includes(q)) {
          filtered[phone] = this.messages[phone];
        } else {
          const sub = this.messages[phone].filter(m => (m.message || "").toLowerCase().includes(q));
          if (sub.length) filtered[phone] = sub;
        }
      }
      return filtered;
    },

    replyTo(m) {
      this.compose.phone = m.phone || "";
      this.compose.message = "";
      this.showToast("info", "Replying to " + m.phone);
    },

    async sendSms() {
      if (this.compose.sending) return;
      this.compose.sending = true;
      try {
        const phone = (this.activePhone && this.activePhone !== "NEW") ? this.activePhone : this.compose.phone.trim();
        const message = this.compose.message.trim();
        if (!phone || !message) {
          this.showToast("error", "Phone number and message are required.");
          this.compose.sending = false;
          return;
        }
        const r = await this.api("send", {phone, message});
        if (r.success) {
          this.showToast("success", "SMS sent");
          this.compose.message = "";
          await this.fetchInbox();
          this.activePhone = phone;
          this.$nextTick(() => {
            if (this.$refs.threadContainer) {
              this.$refs.threadContainer.scrollTop = this.$refs.threadContainer.scrollHeight;
            }
          });
        } else {
          this.showToast("error", r.error || "Failed to send SMS");
        }
      } catch (e) {
        this.showToast("error", "Network error sending SMS");
      }
      this.compose.sending = false;
    },

    timeNow() {
      return new Date().toLocaleTimeString("en-GB", {hour: "2-digit", minute: "2-digit", second: "2-digit"});
    },

    async sendUssd() {
      if (!this.ussd.code.trim() || this.ussd.loading) return;
      const code = this.ussd.code.trim();
      this.ussd.console.push({type: "cmd", text: code, time: this.timeNow()});
      this.ussd.loading = true;
      this.$nextTick(() => {
        if (this.$refs.ussdConsole) this.$refs.ussdConsole.scrollTop = this.$refs.ussdConsole.scrollHeight;
      });
      try {
        const r = await this.api("ussd", {code});
        if (r.success && r.data) {
          const d = r.data;
          if (d.decoded) this.ussd.console.push({type: "out", text: d.decoded, time: this.timeNow()});
          if (d.raw && d.raw !== d.decoded) this.ussd.console.push({type: "raw", text: d.raw, time: this.timeNow()});
          if (d.status_text) this.ussd.console.push({type: "status", text: "Status: " + d.status_text + (d.dcs !== null ? " (DCS=" + d.dcs + ")" : ""), time: this.timeNow()});
        } else {
          this.ussd.console.push({type: "error", text: r.error || "Failed", time: this.timeNow()});
        }
      } catch (e) {
        this.ussd.console.push({type: "error", text: "Network error", time: this.timeNow()});
      }
      this.ussd.loading = false;
      this.$nextTick(() => {
        if (this.$refs.ussdConsole) this.$refs.ussdConsole.scrollTop = this.$refs.ussdConsole.scrollHeight;
      });
    },

    async cancelUssd() {
      try {
        await this.api("ussd_cancel");
        this.ussd.console.push({type: "info", text: "USSD session cancelled", time: this.timeNow()});
      } catch (e) {}
    },

    copyConsole() {
      const text = this.ussd.console.map(l => (l.time || "") + " " + l.text).join("\n");
      navigator.clipboard.writeText(text).then(() => this.showToast("info", "Copied to console output"));
    },

    async loadSettings() {
      try {
        const r = await this.api("get_config");
        if (r.success && r.data) {
          Object.assign(this.settings, {
            host: r.data.host || "",
            username: r.data.username || "",
            password: r.data.password || "",
            port: r.data.port || "",
            https: !!r.data.https,
            ssl_verify: !!r.data.ssl_verify,
            timeout: r.data.timeout || 10,
            auto_delete: !!r.data.auto_delete,
            sync_schedule: r.data.sync_schedule || "0 3 * * *",
            sms_transliterate_accents: r.data.sms_transliterate_accents ?? true,
          });
        }
      } catch (e) {}
    },

    async testConnection() {
      this.settings.testing = true;
      this.settings.testResult = null;
      try {
        const r = await this.api("test_connection", {
          host: this.settings.host,
          username: this.settings.username,
          password: this.settings.password,
          port: this.settings.port,
          https: this.settings.https,
          ssl_verify: this.settings.ssl_verify,
          timeout: this.settings.timeout,
        });
        this.settings.testResult = r.success ? {ok: true, data: r.data} : {ok: false, error: r.error};
      } catch (e) {
        this.settings.testResult = {ok: false, error: "Network error"};
      }
      this.settings.testing = false;
    },

    async saveSettings() {
      this.settings.saving = true;
      try {
        const r = await this.api("save_config", {
          host: this.settings.host,
          username: this.settings.username,
          password: this.settings.password,
          port: this.settings.port,
          https: this.settings.https,
          ssl_verify: this.settings.ssl_verify,
          timeout: this.settings.timeout,
          auto_delete: this.settings.auto_delete,
          sync_schedule: this.settings.sync_schedule,
          sms_transliterate_accents: this.settings.sms_transliterate_accents,
        });
        if (r.success) {
          this.showToast("success", "Settings saved");
          this.checkStatus();
        } else {
          if (r.data?.needs_force) {
            if (confirm("Connection test failed: " + (r.error || "") + "\n\nSave anyway?")) {
              const r2 = await this.api("save_config", {
                host: this.settings.host,
                username: this.settings.username,
                password: this.settings.password,
                port: this.settings.port,
                https: this.settings.https,
                ssl_verify: this.settings.ssl_verify,
                timeout: this.settings.timeout,
                auto_delete: this.settings.auto_delete,
                sync_schedule: this.settings.sync_schedule,
                sms_transliterate_accents: this.settings.sms_transliterate_accents,
                force: true,
              });
              if (r2.success) {
                this.showToast("warning", "Settings saved");
                this.checkStatus();
              } else {
                this.showToast("error", r2.error || "Failed");
              }
            }
          } else {
            this.showToast("error", r.error || "Failed");
          }
        }
      } catch (e) {
        this.showToast("error", "Network error saving");
      }
      this.settings.saving = false;
    },
  };
}
