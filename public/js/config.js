/**
 * Dashboard Configuration
 * Drozast Container Management Platform
 */

const CONFIG = {
    // API Base URL - uses same origin as dashboard
    API_BASE: '/api/v1',

    // Stats polling interval (ms)
    POLL_INTERVAL: 5000,

    // Session storage key
    SESSION_KEY: 'drozast_session',

    // Token cookie name
    TOKEN_COOKIE: 'drozast_token',
};

// Freeze config to prevent modifications
Object.freeze(CONFIG);
