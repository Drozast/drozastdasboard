/**
 * Logs Modal Component
 * Displays container logs in a modal window
 */

const LogsModal = {
    containerName: null,
    refreshInterval: null,

    /**
     * Show logs modal for a container
     */
    async show(containerName) {
        this.containerName = containerName;

        // Create modal if doesn't exist
        if (!document.getElementById('logsModal')) {
            this.createModal();
        }

        // Update title
        document.getElementById('logsModalTitle').textContent = `Logs: ${containerName}`;

        // Show modal
        const modal = document.getElementById('logsModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';

        // Load logs
        await this.loadLogs();

        // Start auto-refresh
        this.refreshInterval = setInterval(() => this.loadLogs(), 5000);
    },

    /**
     * Create modal HTML
     */
    createModal() {
        const modal = document.createElement('div');
        modal.id = 'logsModal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-backdrop" onclick="LogsModal.hide()"></div>
            <div class="modal-content logs-modal">
                <div class="modal-header">
                    <h3 id="logsModalTitle">Logs</h3>
                    <div class="modal-actions">
                        <select id="logsLines" onchange="LogsModal.loadLogs()">
                            <option value="50">50 líneas</option>
                            <option value="100" selected>100 líneas</option>
                            <option value="200">200 líneas</option>
                            <option value="500">500 líneas</option>
                        </select>
                        <button class="btn-icon" onclick="LogsModal.loadLogs()" title="Refrescar">🔄</button>
                        <button class="btn-icon" onclick="LogsModal.hide()" title="Cerrar">✕</button>
                    </div>
                </div>
                <div class="modal-body">
                    <div id="logsContent" class="logs-content">
                        <div class="logs-loading">Cargando logs...</div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    },

    /**
     * Load logs from API
     */
    async loadLogs() {
        if (!this.containerName) return;

        const logsContent = document.getElementById('logsContent');
        const lines = document.getElementById('logsLines')?.value || 100;

        try {
            const response = await API.getContainerLogs(this.containerName, lines);
            const logs = response.logs || [];

            if (logs.length === 0) {
                logsContent.innerHTML = '<div class="logs-empty">No hay logs disponibles</div>';
                return;
            }

            logsContent.innerHTML = logs.map(log => {
                const streamClass = log.stream === 'stderr' ? 'log-error' : 'log-stdout';
                return `<div class="log-line ${streamClass}">${this.escapeHtml(log.text)}</div>`;
            }).join('');

            // Scroll to bottom
            logsContent.scrollTop = logsContent.scrollHeight;
        } catch (e) {
            logsContent.innerHTML = `<div class="logs-error">Error cargando logs: ${e.message}</div>`;
        }
    },

    /**
     * Hide modal
     */
    hide() {
        const modal = document.getElementById('logsModal');
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }

        // Stop auto-refresh
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }

        this.containerName = null;
    },

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
};

// Close modal on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        LogsModal.hide();
    }
});
