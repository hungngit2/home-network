// Loader. Always fetches the current implementation (re-checked every minute), so edits show up in browsers
// without bumping the Lovelace resource version: HA caches /local files for 31 days.
import(`/local/inverter-wizard-card.impl.js?t=${Math.floor(Date.now() / 60000)}`);
