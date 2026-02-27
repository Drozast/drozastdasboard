/**
 * Authentication Module
 * Handles login, logout, and session management
 */

const Auth = {
    token: null,
    user: null,

    /**
     * Initialize auth state from storage
     */
    async init() {
        // Try to restore session
        const stored = localStorage.getItem(CONFIG.SESSION_KEY);
        if (stored) {
            try {
                const session = JSON.parse(stored);
                if (session.token && session.expires_at) {
                    if (new Date(session.expires_at) > new Date()) {
                        this.token = session.token;
                        this.user = session.user;
                        // Verify token is still valid
                        const valid = await this.verify();
                        if (valid) {
                            return true;
                        }
                    }
                }
            } catch (e) {
                console.error('Failed to restore session:', e);
            }
        }
        this.clear();
        return false;
    },

    /**
     * Login with email and password
     */
    async login(email, password) {
        try {
            const response = await fetch(`${CONFIG.API_BASE}/auth/login`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password }),
            });

            const data = await response.json();

            if (response.ok && data.token) {
                this.token = data.token;
                this.user = data.user;

                // Store session
                localStorage.setItem(CONFIG.SESSION_KEY, JSON.stringify({
                    token: data.token,
                    expires_at: data.expires_at,
                    user: data.user,
                }));

                return { success: true, user: data.user };
            }

            return { success: false, error: data.error || 'Login failed' };
        } catch (e) {
            console.error('Login error:', e);
            return { success: false, error: 'Connection error' };
        }
    },

    /**
     * Logout
     */
    async logout() {
        try {
            await fetch(`${CONFIG.API_BASE}/auth/logout`, {
                method: 'POST',
                headers: this.getHeaders(),
            });
        } catch (e) {
            console.error('Logout error:', e);
        }
        this.clear();
    },

    /**
     * Verify current token
     */
    async verify() {
        if (!this.token) return false;

        try {
            const response = await fetch(`${CONFIG.API_BASE}/auth/check`, {
                headers: this.getHeaders(),
            });

            if (response.ok) {
                const data = await response.json();
                return data.authenticated;
            }
            return false;
        } catch (e) {
            console.error('Verify error:', e);
            return false;
        }
    },

    /**
     * Clear auth state
     */
    clear() {
        this.token = null;
        this.user = null;
        localStorage.removeItem(CONFIG.SESSION_KEY);
    },

    /**
     * Check if authenticated
     */
    isAuthenticated() {
        return !!this.token;
    },

    /**
     * Get auth headers for API requests
     */
    getHeaders() {
        const headers = { 'Content-Type': 'application/json' };
        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }
        return headers;
    },

    /**
     * Get current user
     */
    getUser() {
        return this.user;
    },
};
