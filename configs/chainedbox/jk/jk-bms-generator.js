/**
 * JK BMS Password Generator
 * A tool for generating force update codes and settings passwords for JK BMS Monitor
 */

class JKBMSGenerator {
  constructor() {
    // Configuration constants
    this.CONFIG = {
      UPDATE_INTERVAL: 1000, // 1 second
      CODE_VALIDITY_MINUTES: 30,
      MIN_SERIAL_LENGTH: 10,
      MAX_SERIAL_LENGTH: 32,
      NOTIFICATION_DURATION: 3000 // 3 seconds
    };

    // Lookup table for code generation algorithm
    this.BYTE_ARRAY = [
      0x01, 0x04, 0x03, 0x02, 0x08, 0x06, 0x07, 0x05,
      0x04, 0x01, 0x03, 0x02, 0x05, 0x08, 0x07, 0x06,
      0x08, 0x02, 0x01, 0x03, 0x05, 0x04, 0x07, 0x06,
      0x06, 0x02, 0x03, 0x04, 0x01, 0x07, 0x05, 0x08,
      0x07, 0x01, 0x02, 0x03, 0x05, 0x06, 0x04, 0x08,
      0x05, 0x02, 0x03, 0x04, 0x01, 0x08, 0x07, 0x06,
      0x02, 0x03, 0x04, 0x01, 0x05, 0x08, 0x06, 0x07,
      0x03, 0x04, 0x01, 0x02, 0x07, 0x08, 0x05, 0x06,
      0x05, 0x06, 0x01, 0x02, 0x07, 0x08, 0x03, 0x04,
      0x06, 0x07, 0x01, 0x02, 0x05, 0x08, 0x03, 0x04
    ];

    // CRC lookup table for password generation
    this.CRC_TABLE = [
      0x00, 0x91, 0xe3, 0x72, 0x07, 0x96, 0xe4, 0x75,
      0x0e, 0x9f, 0xed, 0x7c, 0x09, 0x98, 0xea, 0x7b,
      0x1c, 0x8d, 0xff, 0x6e, 0x1b, 0x8a, 0xf8, 0x69,
      0x12, 0x83, 0xf1, 0x60, 0x15, 0x84, 0xf6, 0x67,
      0x38, 0xa9, 0xdb, 0x4a, 0x3f, 0xae, 0xdc, 0x4d,
      0x36, 0xa7, 0xd5, 0x44, 0x31, 0xa0, 0xd2, 0x43,
      0x24, 0xb5, 0xc7, 0x56, 0x23, 0xb2, 0xc0, 0x51,
      0x2a, 0xbb, 0xc9, 0x58, 0x2d, 0xbc, 0xce, 0x5f,
      0x70, 0xe1, 0x93, 0x02, 0x77, 0xe6, 0x94, 0x05,
      0x7e, 0xef, 0x9d, 0x0c, 0x79, 0xe8, 0x9a, 0x0b,
      0x6c, 0xfd, 0x8f, 0x1e, 0x6b, 0xfa, 0x88, 0x19,
      0x62, 0xf3, 0x81, 0x10, 0x65, 0xf4, 0x86, 0x17,
      0x48, 0xd9, 0xab, 0x3a, 0x4f, 0xde, 0xac, 0x3d,
      0x46, 0xd7, 0xa5, 0x34, 0x41, 0xd0, 0xa2, 0x33,
      0x54, 0xc5, 0xb7, 0x26, 0x53, 0xc2, 0xb0, 0x21,
      0x5a, 0xcb, 0xb9, 0x28, 0x5d, 0xcc, 0xbe, 0x2f,
      0xe0, 0x71, 0x03, 0x92, 0xe7, 0x76, 0x04, 0x95,
      0xee, 0x7f, 0x0d, 0x9c, 0xe9, 0x78, 0x0a, 0x9b,
      0xfc, 0x6d, 0x1f, 0x8e, 0xfb, 0x6a, 0x18, 0x89,
      0xf2, 0x63, 0x11, 0x80, 0xf5, 0x64, 0x16, 0x87,
      0xd8, 0x49, 0x3b, 0xaa, 0xdf, 0x4e, 0x3c, 0xad,
      0xd6, 0x47, 0x35, 0xa4, 0xd1, 0x40, 0x32, 0xa3,
      0xc4, 0x55, 0x27, 0xb6, 0xc3, 0x52, 0x20, 0xb1,
      0xca, 0x5b, 0x29, 0xb8, 0xcd, 0x5c, 0x2e, 0xbf,
      0x90, 0x01, 0x73, 0xe2, 0x97, 0x06, 0x74, 0xe5,
      0x9e, 0x0f, 0x7d, 0xec, 0x99, 0x08, 0x7a, 0xeb,
      0x8c, 0x1d, 0x6f, 0xfe, 0x8b, 0x1a, 0x68, 0xf9,
      0x82, 0x13, 0x61, 0xf0, 0x85, 0x14, 0x66, 0xf7,
      0xa8, 0x39, 0x4b, 0xda, 0xaf, 0x3e, 0x4c, 0xdd,
      0xa6, 0x37, 0x45, 0xd4, 0xa1, 0x30, 0x42, 0xd3,
      0xb4, 0x25, 0x57, 0xc6, 0xb3, 0x22, 0x50, 0xc1,
      0xba, 0x2b, 0x59, 0xc8, 0xbd, 0x2c, 0x5e, 0xcf
    ];

    // DOM elements
    this.elements = {
      countdown: null,
      updateCode: null,
      serialInput: null,
      generatedPassword: null,
      copyForceCode: null,
      copyPassword: null,
      notification: null
    };

    // State
    this.state = {
      countdownInterval: null,
      nextUpdateTime: null
    };

    this.init();
  }

  /**
   * Initialize the application
   */
  init() {
    this.bindElements();
    this.setupEventListeners();
    this.calculateNextUpdateTime();
    this.startCountdown();
    this.updateForceCode();
    this.updatePassword();
  }

  /**
   * Bind DOM elements to properties
   */
  bindElements() {
    this.elements.countdown = document.getElementById('countdown');
    this.elements.updateCode = document.getElementById('update-code');
    this.elements.serialInput = document.getElementById('serialInput');
    this.elements.generatedPassword = document.getElementById('generatedPassword');
    this.elements.copyForceCode = document.getElementById('copyForceCode');
    this.elements.copyPassword = document.getElementById('copyPassword');
    this.elements.notification = document.getElementById('notification');

    // Validate that all elements exist
    Object.entries(this.elements).forEach(([key, element]) => {
      if (!element) {
        console.error(`Element not found: ${key}`);
      }
    });
  }

  /**
   * Setup event listeners
   */
  setupEventListeners() {
    // Serial input validation and password generation
    if (this.elements.serialInput) {
      this.elements.serialInput.addEventListener('input', (e) => {
        this.handleSerialInput(e);
      });
    }

    // Copy button handlers
    this.setupClipboard();
  }

  /**
   * Handle serial number input with validation
   */
  handleSerialInput(event) {
    const input = event.target;
    let value = input.value.toUpperCase();

    // Remove invalid characters (only allow hex)
    value = value.replace(/[^0-9A-F]/g, '');

    // Limit length
    if (value.length > this.CONFIG.MAX_SERIAL_LENGTH) {
      value = value.substring(0, this.CONFIG.MAX_SERIAL_LENGTH);
    }

    // Update input value
    input.value = value;

    // Validate and update password
    this.validateSerialInput(value);
    this.updatePassword();
  }

  /**
   * Validate serial input and show visual feedback
   */
  validateSerialInput(value) {
    const isValid = value.length >= this.CONFIG.MIN_SERIAL_LENGTH;
    const input = this.elements.serialInput;

    if (isValid) {
      input.classList.remove('error');
    } else {
      input.classList.add('error');
    }

    return isValid;
  }

  /**
   * Generate the force update code based on current time
   */
  generateForceCode() {
    try {
      const now = new Date();
      const dateString = now.toISOString().slice(2, 10).replace(/-/g, '');
      const hourString = now.getUTCHours().toString().padStart(2, '0');
      const timeString = dateString + hourString;

      // Calculate checksum
      const checksum = timeString.split('').reduce((acc, digit) => acc + parseInt(digit), 0);
      const modulus = checksum % 10;

      // Generate code using lookup table
      let code = '';
      for (let i = 0; i < 8; i++) {
        const index = i + modulus * 8;
        const byteValue = this.BYTE_ARRAY[index];
        code += timeString[byteValue - 1];
      }

      return code;
    } catch (error) {
      console.error('Error generating force code:', error);
      return 'ERROR';
    }
  }

  /**
   * Calculate CRC8 ROHC checksum
   */
  calculateCRC8(data) {
    try {
      let crc = 0xff;
      for (let i = 0; i < data.length; i++) {
        crc = this.CRC_TABLE[(crc ^ data.charCodeAt(i)) & 0xff];
      }
      return crc.toString(16).padStart(2, '0').toUpperCase();
    } catch (error) {
      console.error('Error calculating CRC8:', error);
      return '00';
    }
  }

  /**
   * Generate settings password from serial number
   */
  generatePassword(serialNumber) {
    if (!serialNumber || serialNumber.length < this.CONFIG.MIN_SERIAL_LENGTH) {
      return 'N/A';
    }

    try {
      const forceCode = this.generateForceCode();
      const crc = this.calculateCRC8(serialNumber.toUpperCase());
      return forceCode + crc;
    } catch (error) {
      console.error('Error generating password:', error);
      return 'ERROR';
    }
  }

  /**
   * Calculate the next code update time (next 30-minute mark)
   */
  calculateNextUpdateTime() {
    const now = new Date();
    const nextUpdate = new Date(now);

    // Round up to next 30-minute mark
    const currentMinutes = now.getMinutes();
    const nextMinutes = currentMinutes < 30 ? 30 : 60;

    nextUpdate.setMinutes(nextMinutes, 0, 0);
    if (nextMinutes === 60) {
      nextUpdate.setHours(nextUpdate.getHours() + 1, 0, 0, 0);
    }

    this.state.nextUpdateTime = nextUpdate;
  }

  /**
   * Start the countdown timer
   */
  startCountdown() {
    // Clear existing interval
    if (this.state.countdownInterval) {
      clearInterval(this.state.countdownInterval);
    }

    this.state.countdownInterval = setInterval(() => {
      this.updateCountdown();
    }, this.CONFIG.UPDATE_INTERVAL);

    // Initial update
    this.updateCountdown();
  }

  /**
   * Update countdown display
   */
  updateCountdown() {
    if (!this.elements.countdown || !this.state.nextUpdateTime) return;

    const now = new Date().getTime();
    const distance = this.state.nextUpdateTime.getTime() - now;

    if (distance <= 0) {
      // Time expired, generate new code and reset timer
      this.calculateNextUpdateTime();
      this.updateForceCode();
      this.updatePassword();
      return;
    }

    // Calculate time components
    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((distance % (1000 * 60)) / 1000);

    // Update display
    this.elements.countdown.textContent = `${minutes}m ${seconds}s`;
  }

  /**
   * Update the force code display
   */
  updateForceCode() {
    if (!this.elements.updateCode) return;

    const code = this.generateForceCode();
    this.elements.updateCode.textContent = code;
  }

  /**
   * Update the password display
   */
  updatePassword() {
    if (!this.elements.generatedPassword || !this.elements.serialInput) return;

    const serialNumber = this.elements.serialInput.value;
    const password = this.generatePassword(serialNumber);
    this.elements.generatedPassword.textContent = password;
  }

  /**
   * Setup clipboard functionality
   */
  setupClipboard() {
    // Force code copy
    if (this.elements.copyForceCode) {
      const clipboard1 = new ClipboardJS(this.elements.copyForceCode, {
        text: () => this.elements.updateCode.textContent
      });

      clipboard1.on('success', () => {
        this.showNotification('Force code copied to clipboard!', 'success');
      });

      clipboard1.on('error', () => {
        this.showNotification('Failed to copy force code', 'error');
      });
    }

    // Password copy
    if (this.elements.copyPassword) {
      const clipboard2 = new ClipboardJS(this.elements.copyPassword, {
        text: () => {
          const password = this.elements.generatedPassword.textContent;
          return password !== 'N/A' && password !== 'ERROR' ? password : '';
        }
      });

      clipboard2.on('success', () => {
        this.showNotification('Password copied to clipboard!', 'success');
      });

      clipboard2.on('error', () => {
        this.showNotification('Failed to copy password', 'error');
      });
    }
  }

  /**
   * Show notification to user
   */
  showNotification(message, type = 'success') {
    if (!this.elements.notification) return;

    const notification = this.elements.notification;
    const textElement = notification.querySelector('.notification-text');

    if (textElement) {
      textElement.textContent = message;
    } else {
      notification.textContent = message;
    }

    // Update style based on type
    notification.className = `notification ${type}`;
    notification.classList.remove('hidden');
    notification.classList.add('show');

    // Auto-hide after delay
    setTimeout(() => {
      notification.classList.remove('show');
      setTimeout(() => {
        notification.classList.add('hidden');
      }, 300); // Wait for transition
    }, this.CONFIG.NOTIFICATION_DURATION);
  }

  /**
   * Cleanup resources
   */
  destroy() {
    if (this.state.countdownInterval) {
      clearInterval(this.state.countdownInterval);
    }
  }
}

// Error handling
window.addEventListener('error', (event) => {
  console.error('Application error:', event.error);
});

// Initialize application when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  try {
    const generator = new JKBMSGenerator();

    // Make generator available globally for debugging
    window.jkBMSGenerator = generator;

    // Cleanup on page unload
    window.addEventListener('beforeunload', () => {
      generator.destroy();
    });
  } catch (error) {
    console.error('Failed to initialize JK BMS Generator:', error);

    // Show fallback error message
    const container = document.querySelector('.container');
    if (container) {
      const errorDiv = document.createElement('div');
      errorDiv.style.cssText = `
        background: #ff5252;
        color: white;
        padding: 20px;
        border-radius: 8px;
        margin: 20px 0;
        text-align: center;
      `;
      errorDiv.textContent = 'Failed to initialize application. Please refresh the page.';
      container.insertBefore(errorDiv, container.firstChild);
    }
  }
});