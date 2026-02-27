/**
 * Container Cards Module
 * Renders service cards dynamically from API data
 */

const Containers = {
    data: [],
    statsData: {},

    /**
     * Initialize and render all containers
     */
    async init() {
        await this.loadContainers();
        this.render();
    },

    /**
     * Load containers from API
     */
    async loadContainers() {
        try {
            const response = await API.getContainers();
            this.data = response.containers || [];
        } catch (e) {
            console.error('Failed to load containers:', e);
            this.data = [];
        }
    },

    /**
     * Update stats for all containers
     */
    updateStats(containerStats) {
        this.statsData = containerStats || {};
        this.updateCardStats();
    },

    /**
     * Render all container cards grouped by category
     */
    render() {
        const servicesTab = document.getElementById('tab-services');
        if (!servicesTab) return;

        // Group by category
        const categories = this.groupByCategory(this.data);

        // Build HTML
        let html = '';
        for (const [categoryName, containers] of Object.entries(categories)) {
            const category = containers[0]?.category || {};
            html += this.renderCategory(categoryName, category.icon || '📁', containers);
        }

        // Add uncategorized if any
        const uncategorized = this.data.filter(c => !c.category?.name);
        if (uncategorized.length > 0) {
            html += this.renderCategory('Sin Categoría', '📦', uncategorized);
        }

        servicesTab.innerHTML = html;

        // Attach event listeners
        this.attachEventListeners();
    },

    /**
     * Group containers by category name
     */
    groupByCategory(containers) {
        const groups = {};
        for (const container of containers) {
            const catName = container.category?.name || 'Sin Categoría';
            if (!groups[catName]) {
                groups[catName] = [];
            }
            groups[catName].push(container);
        }
        return groups;
    },

    /**
     * Render a category section with its containers
     */
    renderCategory(name, icon, containers) {
        return `
            <div class="category">
                <h2 class="category-title">${icon} ${name}</h2>
                <div class="services-grid">
                    ${containers.map(c => this.renderCard(c)).join('')}
                </div>
            </div>
        `;
    },

    /**
     * Render a single container card
     */
    renderCard(container) {
        const stats = this.statsData[container.container_name];
        const isOnline = container.status === 'running';
        const statusClass = isOnline ? 'online' : 'offline';
        const statusText = isOnline ? 'Online' : 'Offline';

        const cpuPercent = stats?.cpu_percent?.toFixed(1) || '0.0';
        const ramMb = stats?.mem_usage_mb?.toFixed(0) || '0';
        const memPercent = stats?.mem_percent || 0;

        return `
            <div class="service-card" data-container="${container.container_name}" data-id="${container.id}">
                <div class="service-header">
                    <div class="service-icon">${container.icon || '📦'}</div>
                    <div class="service-info">
                        <h3>${container.display_name}</h3>
                        <p>${container.description || ''}</p>
                    </div>
                    <span class="service-status ${statusClass}">
                        <span class="status-dot"></span>${statusText}
                    </span>
                </div>

                <div class="service-stats">
                    <div class="service-stat">
                        <div class="service-stat-label">
                            <span>CPU</span>
                            <span class="cpu-val">${cpuPercent}%</span>
                        </div>
                        <div class="service-stat-bar">
                            <div class="service-stat-fill cpu" style="width: ${Math.min(parseFloat(cpuPercent), 100)}%"></div>
                        </div>
                    </div>
                    <div class="service-stat">
                        <div class="service-stat-label">
                            <span>RAM</span>
                            <span class="ram-val">${ramMb}MB</span>
                        </div>
                        <div class="service-stat-bar">
                            <div class="service-stat-fill ram" style="width: ${Math.min(memPercent, 100)}%"></div>
                        </div>
                    </div>
                </div>

                <div class="service-actions">
                    ${isOnline ? `
                        <button class="action-btn restart" onclick="Containers.restart('${container.container_name}')" title="Reiniciar">
                            🔄
                        </button>
                        <button class="action-btn stop" onclick="Containers.stop('${container.container_name}')" title="Detener">
                            ⏹️
                        </button>
                    ` : `
                        <button class="action-btn start" onclick="Containers.start('${container.container_name}')" title="Iniciar">
                            ▶️
                        </button>
                    `}
                    <button class="action-btn logs" onclick="Containers.showLogs('${container.container_name}')" title="Ver Logs">
                        📋
                    </button>
                    ${container.external_url ? `
                        <a href="${container.external_url}" target="_blank" class="service-link web">Abrir</a>
                    ` : ''}
                </div>
            </div>
        `;
    },

    /**
     * Update stats on existing cards (without re-rendering)
     */
    updateCardStats() {
        document.querySelectorAll('.service-card[data-container]').forEach(card => {
            const containerName = card.dataset.container;
            const stats = this.statsData[containerName];

            if (stats) {
                // Update CPU
                const cpuVal = card.querySelector('.cpu-val');
                const cpuBar = card.querySelector('.service-stat-fill.cpu');
                if (cpuVal) cpuVal.textContent = stats.cpu_percent.toFixed(1) + '%';
                if (cpuBar) cpuBar.style.width = Math.min(stats.cpu_percent, 100) + '%';

                // Update RAM
                const ramVal = card.querySelector('.ram-val');
                const ramBar = card.querySelector('.service-stat-fill.ram');
                if (ramVal) ramVal.textContent = stats.mem_usage_mb.toFixed(0) + 'MB';
                if (ramBar) ramBar.style.width = Math.min(stats.mem_percent, 100) + '%';

                // Update status
                const statusBadge = card.querySelector('.service-status');
                if (statusBadge && !statusBadge.classList.contains('online')) {
                    statusBadge.className = 'service-status online';
                    statusBadge.innerHTML = '<span class="status-dot"></span>Online';
                    this.updateCardActions(card, true);
                }
            } else {
                // Container not running
                const statusBadge = card.querySelector('.service-status');
                if (statusBadge && statusBadge.classList.contains('online')) {
                    statusBadge.className = 'service-status offline';
                    statusBadge.innerHTML = '<span class="status-dot"></span>Offline';
                    this.updateCardActions(card, false);
                }
            }
        });
    },

    /**
     * Update action buttons based on status
     */
    updateCardActions(card, isOnline) {
        const containerName = card.dataset.container;
        const actionsDiv = card.querySelector('.service-actions');
        if (!actionsDiv) return;

        const container = this.data.find(c => c.container_name === containerName);
        const externalUrl = container?.external_url;

        if (isOnline) {
            actionsDiv.innerHTML = `
                <button class="action-btn restart" onclick="Containers.restart('${containerName}')" title="Reiniciar">🔄</button>
                <button class="action-btn stop" onclick="Containers.stop('${containerName}')" title="Detener">⏹️</button>
                <button class="action-btn logs" onclick="Containers.showLogs('${containerName}')" title="Ver Logs">📋</button>
                ${externalUrl ? `<a href="${externalUrl}" target="_blank" class="service-link web">Abrir</a>` : ''}
            `;
        } else {
            actionsDiv.innerHTML = `
                <button class="action-btn start" onclick="Containers.start('${containerName}')" title="Iniciar">▶️</button>
                <button class="action-btn logs" onclick="Containers.showLogs('${containerName}')" title="Ver Logs">📋</button>
                ${externalUrl ? `<a href="${externalUrl}" target="_blank" class="service-link web">Abrir</a>` : ''}
            `;
        }
    },

    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Future: drag and drop, etc.
    },

    // ============ CONTAINER ACTIONS ============

    /**
     * Start a container
     */
    async start(name) {
        if (!confirm(`¿Iniciar ${name}?`)) return;

        this.showToast(`Iniciando ${name}...`, 'info');
        try {
            const result = await API.startContainer(name);
            this.showToast(result.message, result.success ? 'success' : 'error');
        } catch (e) {
            this.showToast(`Error: ${e.message}`, 'error');
        }
    },

    /**
     * Stop a container
     */
    async stop(name) {
        if (!confirm(`¿Detener ${name}?`)) return;

        this.showToast(`Deteniendo ${name}...`, 'info');
        try {
            const result = await API.stopContainer(name);
            this.showToast(result.message, result.success ? 'success' : 'error');
        } catch (e) {
            this.showToast(`Error: ${e.message}`, 'error');
        }
    },

    /**
     * Restart a container
     */
    async restart(name) {
        if (!confirm(`¿Reiniciar ${name}?`)) return;

        this.showToast(`Reiniciando ${name}...`, 'info');
        try {
            const result = await API.restartContainer(name);
            this.showToast(result.message, result.success ? 'success' : 'error');
        } catch (e) {
            this.showToast(`Error: ${e.message}`, 'error');
        }
    },

    /**
     * Show container logs in modal
     */
    async showLogs(name) {
        LogsModal.show(name);
    },

    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        // Remove existing toast
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);

        // Animate in
        setTimeout(() => toast.classList.add('show'), 10);

        // Remove after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },
};
