class ScanApp {
    constructor() {
        this.init();
    }

    async init() {
        console.log('Initializing app...');
        this.setupEventListeners();
        await this.loadInitialData();
    }

    setupEventListeners() {
        // Tab switching
        document.querySelector('.tabs')?.addEventListener('click', e => {
            const tab = e.target.closest('[data-tab]');
            if (tab) {
                this.switchTab(tab.dataset.tab);
            }
        });

        // View buttons in scans table
        document.querySelector('#scansTable')?.addEventListener('click', e => {
            const viewBtn = e.target.closest('.view-button');
            if (viewBtn) {
                const scanId = viewBtn.dataset.scanId;
                const coords = viewBtn.dataset.coords;
                if (scanId) this.loadScanData(scanId, coords);
            }
        });

        // File upload
        document.getElementById('xmlFile')?.addEventListener('change', e => {
            if (e.target.files.length > 0) {
                this.handleFileUpload(e.target.files[0]);
            }
        });
    }

    async loadInitialData() {
        try {
            const response = await fetch('?fetchScans=true');
            const data = await response.json();
            this.updateScansTable(data);
        } catch (error) {
            this.showNotification('Error loading scans: ' + error.message, 'error');
        }
    }

    async loadScanData(scanId, coords) {
        console.log('Loading scan data:', { scanId, coords });
        try {
            const response = await fetch(`?fetchScannedObjects=true&scanID=${scanId}`);
            const data = await response.json();
            
            if (!Array.isArray(data)) {
                throw new Error('Invalid data received from server');
            }

            // Update location header first
            const header = document.getElementById('scanLocationHeader');
            if (header) header.textContent = `Scan for ${coords}`;

            // Switch to scanned objects tab
            this.switchTab('ScannedObjects');

            // Render the data
            this.renderScannedObjects(data);

        } catch (error) {
            this.showNotification('Error loading scan data: ' + error.message, 'error');
        }
    }

    async handleFileUpload(file) {
        const formData = new FormData();
        formData.append('xmlFile', file);

        try {
            const response = await fetch('', { 
                method: 'POST', 
                body: formData 
            });
            const result = await response.json();
            this.showNotification(result.message, result.type);
            if (result.type === 'success') {
                await this.loadInitialData();
            }
        } catch (error) {
            this.showNotification('Upload failed: ' + error.message, 'error');
        }
    }

    switchTab(tabName) {
        // Update button states
        document.querySelectorAll('.tablinks').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tabName);
        });
        // Update tab visibility
        document.querySelectorAll('.tabcontent').forEach(content => {
            content.style.display = content.id === tabName ? 'block' : 'none';
        });
    }

    updateScansTable(scans) {
        const tbody = document.querySelector('#scansTable tbody');
        if (!tbody) return;

        tbody.innerHTML = scans.map(scan => `
            <tr>
                <td>${scan.id || ''}</td>
                <td>${(scan.galX || '0') + ', ' + (scan.galY || '0')}</td>
                <td>${scan.characterName || ''}</td>
                <td>
                    Year ${scan.years || '0'}, 
                    Day ${scan.days || '0'}, 
                    ${String(scan.hours || '0').padStart(2, '0')}:
                    ${String(scan.minutes || '0').padStart(2, '0')}:
                    ${String(scan.seconds || '0').padStart(2, '0')}
                </td>
                <td>
                    <button class="view-button" 
                            data-scan-id="${scan.id || ''}"
                            data-coords="${(scan.galX || '0') + ', ' + (scan.galY || '0')}">
                        View
                    </button>
                </td>
            </tr>
        `).join('');
    }

    renderScannedObjects(objects) {
        console.log('Rendering scanned objects:', objects);
        const container = document.getElementById('ScannedObjects');
        if (!container) return;

        // Count objects by type
        const counts = objects.reduce((acc, obj) => {
            if (this.isWreckOrDebris(obj)) {
                acc.wreck++;
            } else if (obj.iffStatus === 'Enemy') {
                acc.enemy++;
            } else if (obj.iffStatus === 'Friend') {
                acc.friend++;
            } else if (obj.iffStatus === 'Neutral') {
                acc.neutral++;
            }
            return acc;
        }, { enemy: 0, friend: 0, neutral: 0, wreck: 0 });

        // Build HTML string
        let html = `
            <div class="ship-count-summary">
                <span class="count-filter" data-status="Enemy">
                    Enemy Ships: <span class="enemy-count">${counts.enemy}</span>
                </span>
                <span class="count-filter" data-status="Friend">
                    Friendly Ships: <span class="friend-count">${counts.friend}</span>
                </span>
                <span class="count-filter" data-status="Neutral">
                    Neutral Ships: <span class="neutral-count">${counts.neutral}</span>
                </span>
                <span class="count-filter" data-status="Wreck">
                    Wrecks: <span class="wreck-count">${counts.wreck}</span>
                </span>
            </div>
            <div class="search-container">
                <div class="search-controls">
                    <button class="fold-button" id="foldAllBtn">
                        <img src="/assets/images/minus.png" alt="Fold All">
                    </button>
                    <button class="fold-button" id="unfoldAllBtn">
                        <img src="/assets/images/plus.png" alt="Unfold All">
                    </button>
                    <input type="text" id="shipSearch" class="ship-search" 
                           placeholder="Search ships by name, type, owner or ID...">
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th></th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Owner</th>
                        <th>X</th>
                        <th>Y</th>
                        <th>Travel Direction</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>`;

        // Group objects by squad
        const groups = {};
        objects.forEach(obj => {
            const key = obj.partyLeaderUID || obj.entityUID;
            if (!groups[key]) groups[key] = [];
            groups[key].push(obj);
        });

        // Add grouped objects to table
        Object.entries(groups).forEach(([key, groupObjects]) => {
            const squadId = `squad_${key.replace(/[^a-zA-Z0-9]/g, '_')}`;
            
            // Add squad header
            html += `
                <tr class="party-group ${groupObjects[0]?.iffStatus?.toLowerCase()}-leader">
                    <td colspan="9">
                        ${groupObjects.length > 1 ? `
                            <span class="squad-toggle" data-target="${squadId}" data-state="collapsed">
                                <img src="/assets/images/plus.png" alt="Expand" class="toggle-icon">
                            </span>
                        ` : ''}
                        <strong>Squad: ${groupObjects.length} Ship${groupObjects.length !== 1 ? 's' : ''}</strong>
                    </td>
                </tr>`;

            // Add squad members
            groupObjects.forEach((obj, index) => {
                const isLeader = index === 0;
                const className = `${obj.iffStatus?.toLowerCase()}${isLeader ? '-leader' : ''} ${!isLeader ? squadId + ' hidden' : ''}`;
                
                html += `
                    <tr class="${className}">
                        <td>${obj.image ? `<img src="${obj.image}" alt="${obj.name || 'Ship'}">` : ''}</td>
                        <td>${obj.entityUID || ''}</td>
                        <td>${obj.name || ''}</td>
                        <td>${obj.typeName || ''}</td>
                        <td>${obj.ownerName || ''}</td>
                        <td>${obj.x || '0'}</td>
                        <td>${obj.y || '0'}</td>
                        <td>${obj.travelDirection || ''}</td>
                        <td>
                            <button class="copy-button" data-entity-id="${obj.entityUID ? (obj.entityUID.split(':')[1] || obj.entityUID) : ''}">
                                <img src="/assets/images/copy.png" alt="Copy ID" width="16" height="16">
                            </button>
                        </td>
                    </tr>`;
            });
        });

        html += '</tbody></table>';

        // Update container and setup handlers
        container.innerHTML = html;
        this.setupObjectTableHandlers(container);
    }

    setupObjectTableHandlers(container) {
        // Fold/Unfold buttons
        container.querySelector('#foldAllBtn')?.addEventListener('click', () => {
            container.querySelectorAll('.squad-toggle').forEach(toggle => {
                const targetId = toggle.dataset.target;
                if (!targetId) return;

                toggle.dataset.state = 'collapsed';
                toggle.querySelector('.toggle-icon').src = '/assets/images/plus.png';
                container.querySelectorAll(`.${targetId}`).forEach(row => {
                    row.classList.add('hidden');
                });
            });
        });

        container.querySelector('#unfoldAllBtn')?.addEventListener('click', () => {
            container.querySelectorAll('.squad-toggle').forEach(toggle => {
                const targetId = toggle.dataset.target;
                if (!targetId) return;

                toggle.dataset.state = 'expanded';
                toggle.querySelector('.toggle-icon').src = '/assets/images/minus.png';
                container.querySelectorAll(`.${targetId}`).forEach(row => {
                    if (row.dataset.filteredOut !== 'true') {
                        row.classList.remove('hidden');
                    }
                });
            });
        });

        // Squad toggles
        container.querySelectorAll('.squad-toggle').forEach(toggle => {
            toggle.addEventListener('click', () => {
                const targetId = toggle.dataset.target;
                if (!targetId) return;

                const currentState = toggle.dataset.state;
                const newState = currentState === 'collapsed' ? 'expanded' : 'collapsed';
                const icon = toggle.querySelector('.toggle-icon');
                
                if (icon) {
                    icon.src = `/assets/images/${newState === 'expanded' ? 'minus' : 'plus'}.png`;
                }
                
                toggle.dataset.state = newState;
                
                container.querySelectorAll(`.${targetId}`).forEach(row => {
                    if (row.dataset.filteredOut !== 'true') {
                        row.classList.toggle('hidden', newState === 'collapsed');
                    }
                });
            });
        });

        // Search
        container.querySelector('#shipSearch')?.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase();

            // Get tbody for iteration
            const tbody = container.querySelector('table tbody');
            if (!tbody) return;

            // Process each row in order
            const rows = Array.from(tbody.children);
            let currentHeader = null;
            let hasVisibleMembers = false;

            rows.forEach((row, index) => {
                if (row.classList.contains('party-group')) {
                    // If we have a previous header, set its visibility based on members
                    if (currentHeader) {
                        currentHeader.classList.toggle('hidden', !hasVisibleMembers);
                    }
                    // Reset for new header
                    currentHeader = row;
                    hasVisibleMembers = false;
                } else {
                    // Process member row
                    const text = [
                        row.cells[2]?.textContent, // name
                        row.cells[3]?.textContent, // type
                        row.cells[4]?.textContent, // owner
                        row.cells[1]?.textContent  // ID
                    ].filter(Boolean).join(' ').toLowerCase();

                    const visible = query === '' || text.includes(query);
                    row.classList.toggle('hidden', !visible);
                    row.dataset.filteredOut = !visible;

                    // Update visibility tracker for current header
                    if (visible) {
                        hasVisibleMembers = true;
                    }

                    // Handle last header if this is the last row
                    if (index === rows.length - 1 && currentHeader) {
                        currentHeader.classList.toggle('hidden', !hasVisibleMembers);
                    }
                }
            });
        });

        // Status filters
        container.querySelectorAll('.count-filter').forEach(filter => {
            filter.addEventListener('click', () => {
                const status = filter.dataset.status;
                if (!status) return;

                // Clear search
                const searchInput = container.querySelector('#shipSearch');
                if (searchInput) searchInput.value = '';

                // Get tbody for iteration
                const tbody = container.querySelector('table tbody');
                if (!tbody) return;

                // Process each row in order
                const rows = Array.from(tbody.children);
                let currentHeader = null;
                let hasVisibleMembers = false;

                rows.forEach((row, index) => {
                    if (row.classList.contains('party-group')) {
                        // If we have a previous header, set its visibility based on members
                        if (currentHeader) {
                            currentHeader.classList.toggle('hidden', !hasVisibleMembers);
                        }
                        // Reset for new header
                        currentHeader = row;
                        hasVisibleMembers = false;
                    } else {
                        // Process member row
                        const typeName = row.cells[3]?.textContent.toLowerCase() || '';
                        const isWreck = status === 'Wreck' ? 
                            (typeName.includes('wreck') || typeName.includes('debris')) :
                            !typeName.includes('wreck') && !typeName.includes('debris');
                        
                        const rowStatus = row.className.split(' ')[0]?.replace('-leader', '');
                        const visible = status === 'Wreck' ? 
                            isWreck : 
                            (rowStatus === status.toLowerCase() && isWreck);
                        
                        row.classList.toggle('hidden', !visible);
                        row.dataset.filteredOut = !visible;

                        // Update visibility tracker for current header
                        if (visible) {
                            hasVisibleMembers = true;
                        }

                        // Handle last header if this is the last row
                        if (index === rows.length - 1 && currentHeader) {
                            currentHeader.classList.toggle('hidden', !hasVisibleMembers);
                        }
                    }
                });

                // Fold all squads after filtering
                container.querySelector('#foldAllBtn')?.click();
            });
        });

        // Copy buttons
        container.querySelectorAll('.copy-button').forEach(button => {
            button.addEventListener('click', () => {
                const id = button.dataset.entityId;
                if (!id) return;

                navigator.clipboard.writeText(id)
                    .then(() => this.showNotification('ID copied to clipboard', 'success'))
                    .catch(err => this.showNotification('Failed to copy ID', 'error'));
            });
        });
    }

    isWreckOrDebris(obj) {
        const type = (obj?.typeName || '').toLowerCase();
        const name = (obj?.name || '').toLowerCase();
        return type.includes('wreck') || 
               type.includes('debris') || 
               name.includes('wreck') || 
               name.includes('debris');
    }

    showNotification(message, type = 'info') {
        const container = document.getElementById('notificationContainer');
        if (!container) return;

        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = `
            <span>${message}</span>
            <button onclick="this.parentElement.remove()">&times;</button>
        `;
        
        container.prepend(notification);

        // Auto-remove after 5 seconds
        setTimeout(() => notification.remove(), 5000);
    }
}

// Initialize the application
document.addEventListener('DOMContentLoaded', () => new ScanApp());