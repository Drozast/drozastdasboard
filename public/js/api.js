/**
 * API Client Module
 * Handles all API communication
 */

const API = {
    /**
     * Make API request
     */
    async request(method, endpoint, data = null) {
        const options = {
            method,
            headers: Auth.getHeaders(),
        };

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        const url = `${CONFIG.API_BASE}${endpoint}`;

        try {
            const response = await fetch(url, options);

            // Handle 401 Unauthorized
            if (response.status === 401) {
                Auth.clear();
                window.location.reload();
                return null;
            }

            const json = await response.json();

            if (!response.ok) {
                throw new Error(json.error || json.message || 'Request failed');
            }

            return json;
        } catch (e) {
            console.error(`API ${method} ${endpoint} error:`, e);
            throw e;
        }
    },

    // Convenience methods
    get(endpoint) {
        return this.request('GET', endpoint);
    },

    post(endpoint, data) {
        return this.request('POST', endpoint, data);
    },

    put(endpoint, data) {
        return this.request('PUT', endpoint, data);
    },

    delete(endpoint) {
        return this.request('DELETE', endpoint);
    },

    // ============ STATS ============

    async getStats() {
        return this.get('/stats');
    },

    async getStatsHistory(type = 'cpu', hours = 24, container = null) {
        let url = `/stats/history?type=${type}&hours=${hours}`;
        if (container) url += `&container=${encodeURIComponent(container)}`;
        return this.get(url);
    },

    async getEnergy() {
        return this.get('/energy');
    },

    // ============ CONTAINERS ============

    async getContainers() {
        return this.get('/containers');
    },

    async getContainer(name) {
        return this.get(`/containers/${encodeURIComponent(name)}`);
    },

    async getContainerLogs(name, lines = 100, since = '1h') {
        return this.get(`/containers/${encodeURIComponent(name)}/logs?lines=${lines}&since=${since}`);
    },

    async startContainer(name) {
        return this.post(`/containers/${encodeURIComponent(name)}/start`);
    },

    async stopContainer(name) {
        return this.post(`/containers/${encodeURIComponent(name)}/stop`);
    },

    async restartContainer(name) {
        return this.post(`/containers/${encodeURIComponent(name)}/restart`);
    },

    async discoverContainers() {
        return this.post('/containers/discover');
    },

    // ============ ADMIN CONTAINERS ============

    async createContainer(data) {
        return this.post('/admin/containers', data);
    },

    async updateContainer(id, data) {
        return this.put(`/admin/containers/${id}`, data);
    },

    async deleteContainer(id) {
        return this.delete(`/admin/containers/${id}`);
    },

    // ============ CATEGORIES ============

    async getCategories() {
        return this.get('/categories');
    },

    async createCategory(data) {
        return this.post('/categories', data);
    },

    async updateCategory(id, data) {
        return this.put(`/categories/${id}`, data);
    },

    async deleteCategory(id) {
        return this.delete(`/categories/${id}`);
    },

    // ============ API KEYS ============

    async getApiKeys() {
        return this.get('/api-keys');
    },

    async createApiKey(name) {
        return this.post('/api-keys', { name });
    },

    async deleteApiKey(id) {
        return this.delete(`/api-keys/${id}`);
    },

    async revokeApiKey(id) {
        return this.post(`/api-keys/${id}/revoke`);
    },

    // ============ AUDIT LOG ============

    async getAuditLog(limit = 50, offset = 0) {
        return this.get(`/audit-log?limit=${limit}&offset=${offset}`);
    },

    // ============ CONTEXT ============

    async getContext() {
        return this.get('/context');
    },

    async regenerateContext() {
        return this.post('/context/regenerate');
    },

    // ============ ALERTS ============

    async getAlerts() {
        return this.get('/alerts');
    },

    async updateAlert(id, data) {
        return this.put(`/alerts/${id}`, data);
    },

    async testAlert(topic, message) {
        return this.post('/alerts/test', { topic, message });
    },
};
