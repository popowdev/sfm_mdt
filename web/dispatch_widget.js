
(function() {
    if (window.location.pathname.indexOf('/login/connexion') !== -1) return;

    var user = null;
    try { user = JSON.parse(localStorage.getItem('mdt_user')); } catch(e) {}
    if (!user || !user.discord_id) return;

    var DISPATCH_API = '/dispatch/dispatch_api.php';
    var STATE = { roster: null, inService: false, service: null, patrol: null, intervention: null, buttons: [], vehicleAssignments: [], panelOpen: false, formOpen: false };
    var pollTimer = null;

    var css = document.createElement('style');
    css.textContent = [
        '.dw-icon{position:fixed;width:44px;height:44px;border-radius:50%;background:#06b6d4;display:flex;align-items:center;justify-content:center;cursor:grab;z-index:9980;box-shadow:0 4px 15px rgba(6,182,212,0.4);transition:box-shadow .2s;font-size:1.1em;color:white;user-select:none;-webkit-user-select:none;touch-action:none;}',
        '.dw-icon:hover{box-shadow:0 4px 20px rgba(6,182,212,0.6);}',
        '.dw-icon.in-service{animation:dw-pulse 2s infinite;}',
        '@keyframes dw-pulse{0%,100%{box-shadow:0 4px 15px rgba(6,182,212,0.4)}50%{box-shadow:0 4px 25px rgba(6,182,212,0.8),0 0 40px rgba(6,182,212,0.3)}}',
        '.dw-icon.off-duty{background:#475569;box-shadow:0 4px 10px rgba(0,0,0,0.3);}',
        '.dw-panel{position:fixed;width:680px;min-width:320px;min-height:300px;max-height:90vh;background:#1e293b;border:1px solid #334155;border-radius:14px;z-index:9985;box-shadow:0 12px 40px rgba(0,0,0,0.5);display:none;flex-direction:column;overflow:hidden;font-family:"Inter",sans-serif;resize:both;}',
        '.dw-panel.show{display:flex;}',
        '.dw-panel-header{display:flex;align-items:center;padding:12px 16px;background:#0f172a;cursor:move;gap:8px;border-bottom:1px solid #334155;user-select:none;}',
        '.dw-panel-header .dw-title{flex:1;font-weight:700;font-size:.85em;color:#e2e8f0;}',
        '.dw-panel-header .dw-close{background:none;border:none;color:#64748b;cursor:pointer;font-size:1em;padding:2px 6px;}',
        '.dw-panel-header .dw-close:hover{color:#ef4444;}',
        '.dw-panel-body{flex:1;overflow-y:auto;padding:12px 16px;}',
        '.dw-panel-body::-webkit-scrollbar{width:4px;} .dw-panel-body::-webkit-scrollbar-thumb{background:#475569;border-radius:2px;}',
        '.dw-btn-tac{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px 14px;margin-bottom:10px;border:1px solid rgba(34,211,238,.3);background:rgba(34,211,238,.1);color:#67e8f9;border-radius:8px;font-family:inherit;font-size:.82em;font-weight:700;cursor:pointer;}',
        '.dw-btn-tac:hover{background:rgba(34,211,238,.2);}',
        '.dw-status{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;margin-bottom:10px;font-size:.82em;font-weight:600;}',
        '.dw-status.active{background:rgba(16,185,129,0.1);color:#34d399;border:1px solid rgba(16,185,129,0.2);}',
        '.dw-status.inactive{background:rgba(100,116,139,0.1);color:#94a3b8;border:1px solid rgba(100,116,139,0.2);}',
        '.dw-status .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}',
        '.dw-status.active .dot{background:#10b981;} .dw-status.inactive .dot{background:#64748b;}',
        '.dw-patrol-info{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:.82em;}',
        '.dw-patrol-info .label{color:#64748b;font-size:.75em;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;}',
        '.dw-patrol-info .value{color:white;font-weight:700;}',
        '.dw-btn-service{width:100%;padding:10px;border:none;border-radius:8px;font-weight:700;font-size:.85em;cursor:pointer;font-family:"Inter",sans-serif;transition:.2s;margin-bottom:12px;display:flex;align-items:center;justify-content:center;gap:8px;}',
        '.dw-btn-service.start{background:#10b981;color:white;} .dw-btn-service.start:hover{background:#059669;}',
        '.dw-btn-service.stop{background:#ef4444;color:white;} .dw-btn-service.stop:hover{background:#dc2626;}',
        '.dw-section-title{font-size:.72em;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;margin-top:4px;}',
        '.dw-actions-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:6px;margin-bottom:12px;}',
        '.dw-action-btn{padding:10px 6px;border:1px solid #334155;border-radius:8px;background:#0f172a;cursor:pointer;text-align:center;transition:transform .12s, border-color .15s, box-shadow .15s, background .15s;display:flex;flex-direction:column;align-items:center;gap:4px;position:relative;}',
        '.dw-action-btn:hover{border-color:var(--ac);box-shadow:0 0 12px var(--ac-glow);transform:translateY(-2px);background:#162032;}',
        '.dw-action-btn:active{transform:translateY(0) scale(.97);}',
        '.dw-action-btn .ac-icon{font-size:1.2em;} .dw-action-btn .ac-code{font-size:.72em;font-weight:800;color:#cbd5e1;} .dw-action-btn .ac-label{font-size:.66em;color:#64748b;line-height:1.15;}',
        '.dw-action-btn.reset{border-color:rgba(16,185,129,0.4);background:rgba(16,185,129,0.06);}',
        '.dw-action-btn.priority{border-color:var(--ac);box-shadow:0 0 8px var(--ac-glow);background:#162032;}',
        '.dw-action-btn.hidden-by-search{display:none;}',
        '.dw-search{width:100%;padding:8px 12px 8px 32px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;font-size:.82em;outline:none;font-family:"Inter",sans-serif;box-sizing:border-box;transition:border-color .15s;}',
        '.dw-search:focus{border-color:#06b6d4;}',
        '.dw-search-wrap{position:relative;margin-bottom:10px;}',
        '.dw-search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#64748b;font-size:.85em;pointer-events:none;}',
        '.dw-form-overlay{position:absolute;inset:0;background:rgba(15,23,42,0.95);z-index:5;display:flex;flex-direction:column;padding:16px;}',
        '.dw-form-title{font-weight:700;font-size:.9em;margin-bottom:12px;display:flex;align-items:center;gap:8px;}',
        '.dw-form-field{margin-bottom:10px;}',
        '.dw-form-field label{display:block;font-size:.75em;font-weight:600;color:#94a3b8;margin-bottom:4px;}',
        '.dw-form-field input,.dw-form-field select{width:100%;padding:8px 10px;background:#334155;border:1px solid #475569;border-radius:6px;color:white;font-size:.85em;outline:none;font-family:"Inter",sans-serif;box-sizing:border-box;}',
        '.dw-form-field input:focus,.dw-form-field select:focus{border-color:#06b6d4;}',
        '.dw-form-field .req{color:#ef4444;}',
        '.dw-form-actions{display:flex;gap:8px;margin-top:auto;}',
        '.dw-form-actions button{flex:1;padding:8px;border:none;border-radius:6px;font-weight:700;font-size:.82em;cursor:pointer;font-family:"Inter",sans-serif;}',
        '.dw-form-submit{background:#06b6d4;color:white;} .dw-form-cancel{background:#334155;color:#94a3b8;}',
        '.dw-int-mini{background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.2);border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:.8em;}',
        '.dw-int-mini .int-type{font-weight:700;color:#fbbf24;} .dw-int-mini .int-time{color:#64748b;font-size:.85em;}',
        '.dw-toast{position:fixed;top:60px;right:16px;z-index:9995;padding:10px 18px;border-radius:8px;font-size:.82em;font-weight:600;font-family:"Inter",sans-serif;animation:dwToastIn .25s ease-out;box-shadow:0 4px 12px rgba(0,0,0,0.3);}',
        '.dw-toast.success{background:#10b981;color:white;} .dw-toast.error{background:#ef4444;color:white;}',
        '@keyframes dwToastIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}'
    ].join('\n');
    document.head.appendChild(css);

    var noRosterFlag = sessionStorage.getItem('dw_no_roster');
    if (noRosterFlag) {
        var flagAge = Date.now() - parseInt(noRosterFlag, 10);
        if (flagAge < 300000) return;
    }

    fetch(DISPATCH_API + '?action=widget_state&discord_id=' + encodeURIComponent(user.discord_id))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.has_roster) {
                sessionStorage.setItem('dw_no_roster', String(Date.now()));
                return;
            }
            sessionStorage.removeItem('dw_no_roster');
            applyWidgetState(data);
            initWidget();
        })
        .catch(function() {});

    function applyWidgetState(data) {
        STATE.roster = data.roster;
        STATE.canTacmap = !!data.can_tacmap;
        STATE.inService = data.in_service;
        STATE.service = data.service;
        STATE.patrol = data.patrol;
        STATE.intervention = data.intervention;
        STATE.buttons = Array.isArray(data.buttons) ? data.buttons : [];
        STATE.vehicleAssignments = Array.isArray(data.vehicle_assignments) ? data.vehicle_assignments : [];
    }

    function initWidget() {
        createIcon();
        createPanel();
        renderPanel();
        startPolling();
    }

    var icon, panel;

    function createIcon() {
        icon = document.createElement('div');
        icon.className = 'dw-icon' + (STATE.inService ? ' in-service' : ' off-duty');
        icon.innerHTML = '<i class="fa-solid fa-tower-broadcast"></i>';

        var savedPos = localStorage.getItem('dw_icon_pos');
        var pos = savedPos ? JSON.parse(savedPos) : { right: 20, bottom: 96 };
        if (pos.right < 96 && pos.bottom < 96) pos.bottom = 96;
        icon.style.right = pos.right + 'px';
        icon.style.bottom = pos.bottom + 'px';

        icon.addEventListener('click', function(e) {
            if (icon._dragged) { icon._dragged = false; return; }
            togglePanel();
        });

        var startX, startY, startRight, startBottom, dragging = false;
        icon.addEventListener('mousedown', function(e) { startDrag(e.clientX, e.clientY); e.preventDefault(); });
        icon.addEventListener('touchstart', function(e) { var t = e.touches[0]; startDrag(t.clientX, t.clientY); }, { passive: true });

        function startDrag(cx, cy) {
            startX = cx; startY = cy;
            startRight = parseInt(icon.style.right); startBottom = parseInt(icon.style.bottom);
            dragging = false; icon._dragged = false;

            function onMove(mx, my) {
                var dx = mx - startX, dy = my - startY;
                if (!dragging && Math.abs(dx) + Math.abs(dy) < 5) return;
                dragging = true; icon._dragged = true;
                var newR = Math.max(10, Math.min(window.innerWidth - 54, startRight - dx));
                var newB = Math.max(10, Math.min(window.innerHeight - 54, startBottom - dy));
                if (newR < 96 && newB < 96) newB = 96;
                icon.style.right = newR + 'px'; icon.style.bottom = newB + 'px';
            }

            function onMouseMove(e) { onMove(e.clientX, e.clientY); }
            function onTouchMove(e) { var t = e.touches[0]; onMove(t.clientX, t.clientY); }

            function onEnd() {
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onEnd);
                document.removeEventListener('touchmove', onTouchMove);
                document.removeEventListener('touchend', onEnd);
                if (dragging) {
                    localStorage.setItem('dw_icon_pos', JSON.stringify({ right: parseInt(icon.style.right), bottom: parseInt(icon.style.bottom) }));
                }
            }

            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onEnd);
            document.addEventListener('touchmove', onTouchMove, { passive: true });
            document.addEventListener('touchend', onEnd);
        }

        document.body.appendChild(icon);
    }

    function dwAuthHeaders() {
        try {
            var t = localStorage.getItem('mdt_auth_token') || '';
            if (t) return { Authorization: 'Bearer ' + t };
        } catch (e) {}
        return {};
    }

    (function chargerAverto() {
        if (document.getElementById('mdt-averto-js')) return;
        var sc = document.createElement('script');
        sc.id = 'mdt-averto-js';
        sc.src = '/assets/averto.js';
        document.head.appendChild(sc);
    })();

    function openTacmap(board) {
        if (window.MDT_TACPANEL) { window.MDT_TACPANEL.toggle(true, board); return; }
        var sc = document.createElement('script');
        sc.src = '/assets/tacpanel.js';
        sc.onload = function() { if (window.MDT_TACPANEL) window.MDT_TACPANEL.toggle(true, board); };
        sc.onerror = function() { window.open('/carte/' + (board ? '?board=' + encodeURIComponent(board) : ''), '_blank'); };
        document.head.appendChild(sc);
    }

    function createPanel() {
        panel = document.createElement('div');
        panel.className = 'dw-panel';
        panel.innerHTML = '<div class="dw-panel-header"><i class="fa-solid fa-tower-broadcast" style="color:#06b6d4;"></i><span class="dw-title">Dispatch</span><button class="dw-close" onclick="this.closest(\'.dw-panel\').classList.remove(\'show\')">&times;</button></div><div class="dw-panel-body" id="dwPanelBody"></div>';

        var MIN_TOP = 60;
        var savedPanel = localStorage.getItem('dw_panel_pos');
        var ppos = savedPanel ? JSON.parse(savedPanel) : null;
        if (ppos && ppos.left !== undefined) {
            panel.style.top = Math.max(MIN_TOP, ppos.top) + 'px';
            panel.style.left = Math.max(0, Math.min(window.innerWidth - 100, ppos.left)) + 'px';
        } else {
            panel.style.top = '80px';
            panel.style.left = (window.innerWidth - 500) + 'px';
        }
        var savedSize = localStorage.getItem('dw_panel_size');
        if (savedSize) {
            try { var sz = JSON.parse(savedSize); panel.style.width = Math.min(sz.w, window.innerWidth - 40) + 'px'; } catch(e) {}
        }

        var header = panel.querySelector('.dw-panel-header');
        header.addEventListener('mousedown', function(e) {
            if (e.target.closest('.dw-close')) return;
            var sx = e.clientX, sy = e.clientY;
            var sTop = panel.offsetTop, sLeft = panel.offsetLeft;

            function onMove(ev) {
                var newTop = Math.max(MIN_TOP, Math.min(window.innerHeight - 50, sTop + (ev.clientY - sy)));
                var newLeft = Math.max(0, Math.min(window.innerWidth - 50, sLeft + (ev.clientX - sx)));
                panel.style.top = newTop + 'px';
                panel.style.left = newLeft + 'px';
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                localStorage.setItem('dw_panel_pos', JSON.stringify({ top: panel.offsetTop, left: panel.offsetLeft }));
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            e.preventDefault();
        });

        var _rszT = null;
        var resizeObserver = new ResizeObserver(function() {
            if (_rszT) clearTimeout(_rszT);
            _rszT = setTimeout(function() {
                try { localStorage.setItem('dw_panel_size', JSON.stringify({ w: panel.offsetWidth, h: panel.offsetHeight })); } catch(e) {}
            }, 200);
        });
        resizeObserver.observe(panel);

        document.body.appendChild(panel);
    }

    function togglePanel() {
        STATE.panelOpen = !panel.classList.contains('show');
        panel.classList.toggle('show');
        if (STATE.panelOpen) {
            renderPanel();
            refreshState();
        }
    }

    function renderPanel() {
        var body = document.getElementById('dwPanelBody');
        if (!body) return;
        body.innerHTML = '';

        var statusClass = STATE.inService ? 'active' : 'inactive';
        var statusText = STATE.inService ? 'En service' : 'Hors service';
        if (STATE.roster) statusText += ' - ' + STATE.roster.matricule + ' | ' + STATE.roster.nom_prenom;
        body.innerHTML += '<div class="dw-status ' + statusClass + '"><span class="dot"></span>' + esc(statusText) + '</div>';

        if (STATE.canTacmap) {
            body.innerHTML += '<button class="dw-btn-tac" id="dwTacBtn"><i class="fa-solid fa-map-location-dot"></i> Carte tactique</button>';
        }

        if (STATE.patrol) {
            body.innerHTML += '<div class="dw-patrol-info"><div class="label">Patrouille</div><div class="value">' + esc(STATE.patrol.indicatif) + '</div></div>';
        }

        if (STATE.intervention) {
            var elapsed = formatDur(STATE.intervention.created_at);
            body.innerHTML += '<div class="dw-int-mini"><span class="int-type"><i class="fa-solid fa-bolt"></i> ' + esc(STATE.intervention.type) + '</span> <span class="int-time">' + elapsed + '</span>' +
                (STATE.intervention.lieu ? '<br><small style="color:#94a3b8">' + esc(STATE.intervention.lieu) + '</small>' : '') + '</div>';
        }

        if (STATE.vehicleAssignments && STATE.vehicleAssignments.length) {
            STATE.vehicleAssignments.forEach(function(va) {
                var posColor = va.position === 'P1' ? '#ef4444' : '#3b82f6';
                body.innerHTML += '<div style="background:#0f172a;border:1px solid ' + posColor + '44;border-left:3px solid ' + posColor + ';border-radius:8px;padding:8px 12px;margin-bottom:8px;font-size:.8em;">' +
                    '<div style="font-weight:800;color:' + posColor + ';font-size:.85em;">' + esc(va.position) + ' — ' + esc(va.modele || 'Vehicule') + '</div>' +
                    '<div style="color:#94a3b8;font-size:.85em;">' + (va.couleur ? esc(va.couleur) + ' · ' : '') + (va.immat || '') + '</div>' +
                    '</div>';
            });
            if (typeof openPursuitWindow === 'function' && !document.getElementById('pursuitWindow').style.display.match(/block/)) {
                openPursuitWindow(STATE.vehicleAssignments[0]);
            }
        }

        if (STATE.inService) {
            body.innerHTML += '<button class="dw-btn-service stop" id="dwServiceBtn"><i class="fa-solid fa-right-from-bracket"></i> Fin de service</button>';
        } else {
            body.innerHTML += '<button class="dw-btn-service start" id="dwServiceBtn"><i class="fa-solid fa-right-to-bracket"></i> Prendre son service</button>';
        }

        if (STATE.inService && !STATE.patrol) {
            body.innerHTML += '<div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:.78em;color:#fbbf24;"><i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>Aucune patrouille active detectee — rejoins une patrouille sur le board pour les assignations</div>';
        }

        if (STATE.inService && STATE.buttons.length > 0) {
            var priorityCodes = ['10-99', '10-31', '10-91', '10-35'];
            var quickCodes = ['10-6', '10-23', '10-98'];
            var hiddenCodes = ['10-8', '10-10'];
            var priorityBtns = STATE.buttons.filter(function(b) { return priorityCodes.indexOf(b.code) !== -1; });
            var quickBtns = STATE.buttons.filter(function(b) { return quickCodes.indexOf(b.code) !== -1; });
            var otherBtns = STATE.buttons.filter(function(b) { return priorityCodes.indexOf(b.code) === -1 && quickCodes.indexOf(b.code) === -1 && hiddenCodes.indexOf(b.code) === -1; });

            var searchWrap = document.createElement('div');
            searchWrap.className = 'dw-search-wrap';
            searchWrap.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i><input type="search" class="dw-search" id="dwActionSearch" placeholder="Filtrer (10-56, refus, controle...)" autocomplete="off">';
            body.appendChild(searchWrap);

            if (priorityBtns.length) {
                var prioTitle = document.createElement('div');
                prioTitle.className = 'dw-section-title';
                prioTitle.style.color = '#ef4444';
                prioTitle.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="margin-right:4px;"></i>Priorité';
                body.appendChild(prioTitle);
                var prioGrid = document.createElement('div');
                prioGrid.className = 'dw-actions-grid';
                priorityBtns.forEach(function(btn) { prioGrid.appendChild(buildActionBtn(btn, true)); });
                body.appendChild(prioGrid);
            }

            if (quickBtns.length) {
                var quickTitle = document.createElement('div');
                quickTitle.className = 'dw-section-title';
                quickTitle.innerHTML = '<i class="fa-solid fa-bolt" style="margin-right:4px;color:#06b6d4;"></i>Rapide';
                body.appendChild(quickTitle);
                var quickGrid = document.createElement('div');
                quickGrid.className = 'dw-actions-grid';
                quickBtns.forEach(function(btn) { quickGrid.appendChild(buildActionBtn(btn, false)); });
                body.appendChild(quickGrid);
            }

            if (otherBtns.length) {
                var titleDiv = document.createElement('div');
                titleDiv.className = 'dw-section-title';
                titleDiv.innerHTML = '<i class="fa-solid fa-list" style="margin-right:4px;color:#94a3b8;"></i>Codes 10 (' + otherBtns.length + ')';
                body.appendChild(titleDiv);
                var grid = document.createElement('div');
                grid.className = 'dw-actions-grid';
                otherBtns.forEach(function(btn) { grid.appendChild(buildActionBtn(btn, false)); });
                body.appendChild(grid);
            }

            var searchInput = document.getElementById('dwActionSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var q = (searchInput.value || '').toLowerCase().trim();
                    body.querySelectorAll('.dw-action-btn').forEach(function(el) {
                        if (!q) { el.classList.remove('hidden-by-search'); return; }
                        var match = el.dataset.searchKey && el.dataset.searchKey.indexOf(q) !== -1;
                        el.classList.toggle('hidden-by-search', !match);
                    });
                });
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        var first = body.querySelector('.dw-action-btn:not(.hidden-by-search)');
                        if (first && first._btnRef) onActionClick(first._btnRef);
                    } else if (e.key === 'Escape') {
                        searchInput.value = '';
                        searchInput.dispatchEvent(new Event('input'));
                    }
                });
            }
        }

        function buildActionBtn(btn, isPriority) {
            var el = document.createElement('div');
            el.className = 'dw-action-btn' + (btn.is_reset ? ' reset' : '') + (isPriority ? ' priority' : '');
            el.style.setProperty('--ac', btn.color);
            el.style.setProperty('--ac-glow', btn.color + '55');
            el.title = btn.code + ' — ' + btn.label;
            el.dataset.searchKey = (btn.code + ' ' + btn.label).toLowerCase();
            el.innerHTML = '<span class="ac-icon" style="color:' + esc(btn.color) + '"><i class="fa-solid ' + esc(btn.icon) + '"></i></span><span class="ac-code">' + esc(btn.code) + '</span><span class="ac-label">' + esc(btn.label) + '</span>';
            el._btnRef = btn;
            el.addEventListener('click', function() { onActionClick(btn); });
            return el;
        }

        var tacBtn = document.getElementById('dwTacBtn');
        if (tacBtn) {
            tacBtn.addEventListener('click', function() {
                var board = (STATE.intervention && STATE.intervention.id) ? 'tb_i_' + STATE.intervention.id : '';
                openTacmap(board);
            });
        }

        var svcBtn = document.getElementById('dwServiceBtn');
        if (svcBtn) {
            svcBtn.addEventListener('click', function() {
                if (STATE.inService) endService();
                else startService();
            });
        }

    }

    function startService() {
        fetch(DISPATCH_API + '?action=start_service', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ discord_id: user.discord_id })
        }).then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                STATE.inService = true;
                icon.className = 'dw-icon in-service';
                try { if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission(); } catch(e) {}
                if (typeof lastForcedCheckAt !== 'undefined') lastForcedCheckAt = null;
                if (typeof lastActivity !== 'undefined') lastActivity = Date.now();
                dwToast('Service pris !', 'success');
                refreshState();
            } else { dwToast(data.error || 'Erreur', 'error'); }
        }).catch(function() { dwToast('Erreur reseau', 'error'); });
    }

    function endService() {
        fetch(DISPATCH_API + '?action=end_service', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ discord_id: user.discord_id })
        }).then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                STATE.inService = false;
                if (typeof lastForcedCheckAt !== 'undefined') lastForcedCheckAt = null;
                icon.className = 'dw-icon off-duty';
                var h = Math.floor(data.duration_minutes / 60);
                var m = data.duration_minutes % 60;
                dwToast('Fin de service (' + h + 'h' + (m < 10 ? '0' : '') + m + ')', 'success');
                refreshState();
            } else { dwToast(data.error || 'Erreur', 'error'); }
        }).catch(function() { dwToast('Erreur reseau', 'error'); });
    }

    function onActionClick(btn) {
        executeAction(btn.id, {});
    }

    function executeAction(buttonId, fieldValues) {
        fetch(DISPATCH_API + '?action=execute_action', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ button_id: buttonId, discord_id: user.discord_id, field_values: fieldValues })
        }).then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                dwToast(data.action === 'reset' ? 'Intervention terminee' : 'Intervention creee', 'success');
                refreshState();
            } else { dwToast(data.error || 'Erreur', 'error'); }
        }).catch(function() { dwToast('Erreur reseau', 'error'); });
    }

    function refreshState() {
        fetch(DISPATCH_API + '?action=widget_state&discord_id=' + encodeURIComponent(user.discord_id))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.has_roster) return;
                applyWidgetState(data);
                icon.className = 'dw-icon' + (STATE.inService ? ' in-service' : ' off-duty');
                if (STATE.panelOpen) renderPanel();
                dwHandleAfk(data);
            }).catch(function() {});
    }

    function startPolling() {
        try {
            if (typeof io !== 'undefined') {
                var socket = io(window.location.origin, {
                    path: '/dispatch-ws/',
                    transports: ['websocket', 'polling'],
                    reconnection: true,
                    reconnectionDelay: 3000
                });
                socket.on('connect', function() {
                    if (pollTimer) clearInterval(pollTimer);
                    pollTimer = setInterval(function() {
                        if (document.visibilityState === 'hidden') return;
                        refreshState();
                    }, 60000);
                });
                socket.on('dispatch_update', function() {
                    if (document.visibilityState !== 'hidden') refreshState();
                });
                socket.on('disconnect', function() {
                    if (pollTimer) clearInterval(pollTimer);
                    pollTimer = setInterval(function() {
                        if (document.visibilityState === 'hidden') return;
                        refreshState();
                    }, 30000);
                });
            }
        } catch(e) {}

        if (!pollTimer) {
            pollTimer = setInterval(function() {
                if (document.visibilityState === 'hidden') return;
                refreshState();
            }, 30000);
        }

        function dwBeat() {
            if (!STATE.inService) return;
            var act = dwActiveFlag ? '&active=1' : '';
            dwActiveFlag = false;
            try {
                fetch(DISPATCH_API + '?action=heartbeat&discord_id=' + encodeURIComponent(user.discord_id) + act, { keepalive: true, headers: dwAuthHeaders() })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { dwHandleAfk(d); })
                    .catch(function() {});
            } catch(e) {}
        }
        function dwSyncHeartbeatWorker() {
            if (!heartbeatWorker) return;
            try {
                heartbeatWorker.postMessage(STATE.inService
                    ? { url: location.origin + DISPATCH_API + '?action=heartbeat&discord_id=' + encodeURIComponent(user.discord_id), headers: dwAuthHeaders() }
                    : null);
            } catch(e) {}
        }

        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') { refreshState(); dwBeat(); dwSyncHeartbeatWorker(); }
        });
        window.addEventListener('focus', function() { dwBeat(); });

        if (heartbeatTimer) clearInterval(heartbeatTimer);
        heartbeatTimer = setInterval(function() { dwSyncHeartbeatWorker(); dwBeat(); }, 60000);

        try {
            if (!heartbeatWorker) {
                var hbCode = 'var u=null,h={};self.onmessage=function(e){if(e.data&&e.data.url){u=e.data.url;h=e.data.headers||{};}else{u=e.data;}};setInterval(function(){if(u){fetch(u,{keepalive:true,headers:h}).then(function(r){return r.json();}).then(function(d){self.postMessage(d);}).catch(function(){});}},30000);';
                var _hbUrl = URL.createObjectURL(new Blob([hbCode], { type: 'application/javascript' }));
                heartbeatWorker = new Worker(_hbUrl);
                URL.revokeObjectURL(_hbUrl);
                heartbeatWorker.onmessage = function(e) { dwHandleAfk(e.data); };
                dwSyncHeartbeatWorker();
            }
        } catch(e) { }
    }
    var heartbeatTimer = null;
    var heartbeatWorker = null;

    function formatDur(isoDate) {
        if (!isoDate) return '';
        var d = new Date(isoDate.replace(' ', 'T') + (isoDate.indexOf('Z') === -1 && isoDate.indexOf('+') === -1 ? 'Z' : ''));
        var diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 0) diff = 0;
        if (diff < 60) return diff + 's';
        if (diff < 3600) return Math.floor(diff / 60) + 'min';
        var h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60);
        return h + 'h' + (m < 10 ? '0' : '') + m;
    }

    function dwToast(msg, type) {
        var t = document.createElement('div');
        t.className = 'dw-toast ' + (type || 'success');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function() { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(function() { t.remove(); }, 300); }, 3000);
    }

    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML.replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

    var INACTIVITY_THRESHOLD  = 2 * 60 * 60 * 1000;
    var FORCED_CHECK_INTERVAL = 4 * 60 * 60 * 1000;
    var RESPONSE_TIMEOUT      = 5 * 60 * 1000;
    var CHECK_INTERVAL        = 60 * 1000;
    var RECENT_ACTIVITY_WINDOW = 5 * 60 * 1000;
    var ANTI_AFK_ENABLED       = false;
    var AFK_OFF_START_HOUR    = 21;
    var AFK_OFF_END_HOUR      = 0;
    var lastActivity = Date.now();
    var lastForcedCheckAt = null;
    var inactivityNotifShown = false;
    var inactivityNotifShownAt = 0;
    var inactivityNotifReason = null;
    var inactivityAutoEndTimer = null;
    var inactivityCheckTimer = null;
    var notifWatchdogTimer = null;

    function isAfkDisabledNow() {
        var h = new Date().getHours();
        return h >= AFK_OFF_START_HOUR;
    }

    function playAfkAlertSound() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.35, 0.7].forEach(function(delay) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0, ctx.currentTime + delay);
                gain.gain.linearRampToValueAtTime(0.18, ctx.currentTime + delay + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + delay + 0.22);
                osc.connect(gain); gain.connect(ctx.destination);
                osc.start(ctx.currentTime + delay);
                osc.stop(ctx.currentTime + delay + 0.25);
            });
            setTimeout(function() { try { ctx.close(); } catch(e) {} }, 1500);
        } catch (e) { }
    }

    function onUserActivity() {
        lastActivity = Date.now();
    }

    document.addEventListener('mousemove', onUserActivity, { passive: true });
    document.addEventListener('keydown', onUserActivity, { passive: true });
    document.addEventListener('click', onUserActivity, { passive: true });
    document.addEventListener('touchstart', onUserActivity, { passive: true });
    document.addEventListener('wheel', onUserActivity, { passive: true });

    var dwActiveFlag = false;
    function dwStrongActivity() { dwActiveFlag = true; }
    document.addEventListener('click', dwStrongActivity, { passive: true });
    document.addEventListener('keydown', dwStrongActivity, { passive: true });
    document.addEventListener('touchstart', dwStrongActivity, { passive: true });

    var dwAfkShownAt = 0, dwEndedShownFor = 0;
    function dwHandleAfk(d) {
        if (!d || typeof d !== 'object') return;
        if (d.afk && d.afk.pending) { dwShowAfkCheck(d.afk.remaining_s || 0); return; }
        dwHideAfkCheck();
        if (d.afk_ended && d.afk_ended.service_id && d.afk_ended.service_id !== dwEndedShownFor) {
            dwEndedShownFor = d.afk_ended.service_id;
            dwShowEnded(d.afk_ended);
        }
    }
    function dwAfkBeep() {
        try {
            var AC = window.AudioContext || window.webkitAudioContext; if (!AC) return;
            var ctx = new AC(); var t = ctx.currentTime;
            [0, 0.22].forEach(function(off) {
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.connect(g); g.connect(ctx.destination);
                o.frequency.value = 880;
                g.gain.setValueAtTime(0.12, t + off);
                g.gain.exponentialRampToValueAtTime(0.001, t + off + 0.35);
                o.start(t + off); o.stop(t + off + 0.4);
            });
        } catch(e) {}
    }
    function dwShowAfkCheck(remainingS) {
        var el = document.getElementById('dwAfkCheck');
        var mins = Math.max(1, Math.ceil(remainingS / 60));
        if (!el) {
            el = document.createElement('div');
            el.id = 'dwAfkCheck';
            el.style.cssText = 'position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(2,6,23,.72);backdrop-filter:blur(3px);font-family:Inter,sans-serif;';
            el.innerHTML = '<div style="background:linear-gradient(145deg,#1e293b,#0f172a);border:1px solid #f59e0b;border-radius:16px;padding:26px 28px;max-width:380px;width:92%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.6);">' +
                '<div style="font-size:2em;margin-bottom:8px;">📡</div>' +
                '<div style="font-weight:800;color:#fbbf24;font-size:1.05em;margin-bottom:6px;">Contrôle de présence</div>' +
                '<div style="color:#94a3b8;font-size:.85em;line-height:1.5;margin-bottom:6px;">Aucune activité détectée depuis un moment.<br>Confirme ta présence pour rester en service.</div>' +
                '<div id="dwAfkRemain" style="color:#64748b;font-size:.75em;margin-bottom:16px;"></div>' +
                '<button id="dwAfkYes" style="width:100%;padding:12px;border:none;border-radius:10px;background:#f59e0b;color:#111;font-weight:800;font-size:.95em;cursor:pointer;font-family:inherit;">👮 Je suis là</button></div>';
            document.body.appendChild(el);
            document.getElementById('dwAfkYes').addEventListener('click', function() {
                var btn = this; btn.disabled = true; btn.textContent = '…';
                fetch(DISPATCH_API + '?action=confirm_afk_check', { method:'POST', headers:{ 'Content-Type':'application/json' }, body: JSON.stringify({ reason: 'manual' }) })
                    .then(function(r){ return r.json(); })
                    .then(function() { dwHideAfkCheck(); dwActiveFlag = true; })
                    .catch(function() { btn.disabled = false; btn.textContent = '👮 Je suis là'; });
            });
            if (Date.now() - dwAfkShownAt > 120000) {
                dwAfkShownAt = Date.now();
                dwAfkBeep();
                try {
                    if ('Notification' in window && Notification.permission === 'granted') {
                        var n = new Notification('MDT — Contrôle de présence', { body: 'Confirme ta présence pour rester en service (' + mins + ' min restantes).', tag: 'mdt-afk', requireInteraction: true, icon: '/favicon.ico' });
                        n.onclick = function() { window.focus(); n.close(); };
                    }
                } catch(e) {}
            }
        }
        var rem = document.getElementById('dwAfkRemain');
        if (rem) rem.textContent = 'Sans réponse, le service sera clôturé dans ~' + mins + ' min (heures créditées jusqu\'à ta dernière activité).';
    }
    function dwHideAfkCheck() {
        var el = document.getElementById('dwAfkCheck');
        if (el) el.remove();
    }
    function dwShowEnded(info) {
        var labels = { idle_timeout: 'aucune réponse au contrôle de présence', no_heartbeat: 'navigateur fermé / connexion perdue', hard_cap: 'plafond de 12h atteint' };
        var h = Math.floor((info.credited_min || 0) / 60), m = (info.credited_min || 0) % 60;
        var dur = (h ? h + 'h' : '') + (m < 10 && h ? '0' : '') + m + (h ? '' : ' min');
        var el = document.createElement('div');
        el.id = 'dwAfkEnded';
        el.style.cssText = 'position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(2,6,23,.72);backdrop-filter:blur(3px);font-family:Inter,sans-serif;';
        el.innerHTML = '<div style="background:linear-gradient(145deg,#1e293b,#0f172a);border:1px solid #334155;border-radius:16px;padding:26px 28px;max-width:400px;width:92%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.6);">' +
            '<div style="font-size:2em;margin-bottom:8px;">🕐</div>' +
            '<div style="font-weight:800;color:#e2e8f0;font-size:1.05em;margin-bottom:6px;">Service clôturé automatiquement</div>' +
            '<div style="color:#94a3b8;font-size:.85em;line-height:1.6;margin-bottom:16px;">Raison : ' + (labels[info.reason] || info.reason) + '.<br>Heures créditées : <b style="color:#e2e8f0;">' + dur + '</b> (jusqu\'à ta dernière activité).<br><span style="font-size:.9em;color:#64748b;">Un souci ? La supervision peut régulariser tes heures.</span></div>' +
            (info.resumable ? '<button id="dwAfkResume" style="width:100%;padding:12px;border:none;border-radius:10px;background:#10b981;color:#fff;font-weight:800;font-size:.9em;cursor:pointer;font-family:inherit;margin-bottom:8px;">▶ Reprendre mon service (aucune heure perdue)</button>' : '') +
            '<button id="dwAfkOk" style="width:100%;padding:11px;border:1px solid #334155;border-radius:10px;background:transparent;color:#94a3b8;font-weight:700;font-size:.85em;cursor:pointer;font-family:inherit;">OK, compris</button></div>';
        document.body.appendChild(el);
        var ack = function() { fetch(DISPATCH_API + '?action=ack_auto_close', { method:'POST', headers:{ 'Content-Type':'application/json' }, body: '{}' }).catch(function(){}); el.remove(); };
        document.getElementById('dwAfkOk').addEventListener('click', ack);
        var rbtn = document.getElementById('dwAfkResume');
        if (rbtn) rbtn.addEventListener('click', function() {
            rbtn.disabled = true; rbtn.textContent = '…';
            fetch(DISPATCH_API + '?action=resume_service', { method:'POST', headers:{ 'Content-Type':'application/json' }, body: '{}' })
                .then(function(r){ return r.json(); })
                .then(function(d) {
                    if (d && d.success) { el.remove(); STATE.inService = true; refreshState(); }
                    else { ack(); }
                })
                .catch(function() { ack(); });
        });
    }

    function triggerAfkPopup(reason) {
        inactivityNotifShown = true;
        inactivityNotifShownAt = Date.now();
        inactivityNotifReason = reason;
        showInactivityNotif(reason);
        playAfkAlertSound();
        startNotifWatchdog(reason);

        inactivityAutoEndTimer = setTimeout(function() {
            if (!STATE.inService) return;
            var afkReason = inactivityNotifReason === 'forced' ? 'afk_forced_4h' : 'afk';
            fetch(DISPATCH_API + '?action=end_service', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ discord_id: user.discord_id, auto_afk: true, reason: afkReason })
            }).then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.success) {
                    STATE.inService = false;
                    if (icon) icon.className = 'dw-icon off-duty';
                    var msg = inactivityNotifReason === 'forced'
                        ? 'Service retire (verification non confirmee)'
                        : 'Service retire automatiquement (inactivite 2h)';
                    dwToast(msg, 'error');
                    if (typeof refreshState === 'function') refreshState();
                    try { window.dispatchEvent(new CustomEvent('mdt_perms_dirty')); } catch(e) {}
                }
            }).catch(function() {});
            var notif = document.getElementById('dw-inactivity-notif');
            if (notif) notif.remove();
            var bd = document.getElementById('dw-inactivity-backdrop');
            if (bd) bd.remove();
            if (notifWatchdogTimer) { clearInterval(notifWatchdogTimer); notifWatchdogTimer = null; }
            inactivityNotifShown = false;
            inactivityNotifReason = null;
            inactivityNotifShownAt = 0;
        }, RESPONSE_TIMEOUT);
    }

    function parseServiceStart(s) {
        if (!s) return null;
        try {
            var iso = s.indexOf('T') === -1 ? s.replace(' ', 'T') : s;
            var t = new Date(iso).getTime();
            return isNaN(t) ? null : t;
        } catch(e) { return null; }
    }

    function checkInactivity() {
        if (!ANTI_AFK_ENABLED) return;
        if (!STATE.inService) return;
        if (inactivityNotifShown) return;

        var now = Date.now();

        if (isAfkDisabledNow()) {
            lastActivity = now;
            lastForcedCheckAt = now;
            return;
        }

        if (!lastForcedCheckAt && STATE.service) {
            var ref = STATE.service.last_afk_check || STATE.service.start_at;
            var startTs = parseServiceStart(ref);
            if (startTs) lastForcedCheckAt = startTs;
        }

        if (lastForcedCheckAt && (now - lastForcedCheckAt) >= FORCED_CHECK_INTERVAL) {
            if ((now - lastActivity) < RECENT_ACTIVITY_WINDOW) {
                lastForcedCheckAt = now;
                try {
                    fetch(DISPATCH_API + '?action=confirm_afk_check', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ discord_id: user.discord_id, reason: 'auto_active' })
                    }).catch(function() {});
                } catch (e) {}
                return;
            }
            triggerAfkPopup('forced');
            return;
        }

        var elapsed = now - lastActivity;
        if (elapsed >= INACTIVITY_THRESHOLD) {
            triggerAfkPopup('inactivity');
            return;
        }
    }

    function startNotifWatchdog(reason) {
        if (notifWatchdogTimer) clearInterval(notifWatchdogTimer);
        notifWatchdogTimer = setInterval(function() {
            if (!inactivityNotifShown) {
                clearInterval(notifWatchdogTimer);
                notifWatchdogTimer = null;
                return;
            }
            var n = document.getElementById('dw-inactivity-notif');
            if (!n) {
                try { console.warn('[MDT-AFK] Notif disparue du DOM, recreation'); } catch(e) {}
                showInactivityNotif(reason);
            }
        }, 1000);
    }

    function showInactivityNotif(reason) {
        var isForced = reason === 'forced';
        var old = document.getElementById('dw-inactivity-notif');
        if (old) old.remove();
        var oldBd = document.getElementById('dw-inactivity-backdrop');
        if (oldBd) oldBd.remove();

        var backdrop = document.createElement('div');
        backdrop.id = 'dw-inactivity-backdrop';
        backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:99998;animation:dwToastIn .25s ease;';
        backdrop.addEventListener('click', function(e) { e.stopPropagation(); e.preventDefault(); });
        document.body.appendChild(backdrop);

        var borderColor = isForced ? '#06b6d4' : '#f59e0b';
        var iconBg = isForced ? 'rgba(6,182,212,0.15)' : 'rgba(245,158,11,0.12)';
        var iconColor = isForced ? '#06b6d4' : '#f59e0b';
        var iconClass = isForced ? 'fa-shield-halved' : 'fa-clock';
        var title = isForced ? 'Verification de service obligatoire' : 'Etes-vous toujours en service ?';
        var subtitle = isForced
            ? '<b>4 heures</b> de service consecutives. Confirme que tu es bien la (anti-AFK farm).'
            : 'Aucune activite detectee depuis <b>2 heures</b>';

        var notif = document.createElement('div');
        notif.id = 'dw-inactivity-notif';
        notif.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:99999;max-width:440px;width:90%;background:linear-gradient(145deg,#1e293b,#0f172a);border:2px solid ' + borderColor + ';border-radius:16px;padding:32px;text-align:center;box-shadow:0 25px 70px rgba(0,0,0,0.7), 0 0 0 1px ' + borderColor + '40; animation:dwToastIn .3s ease; pointer-events:auto;';
        notif.addEventListener('click', function(e) { e.stopPropagation(); });
        notif.innerHTML = '<div style="width:64px;height:64px;margin:0 auto 16px;border-radius:50%;background:' + iconBg + ';display:flex;align-items:center;justify-content:center;animation:dwPulse 1.5s infinite;"><i class="fa-solid ' + iconClass + '" style="font-size:1.8em;color:' + iconColor + ';"></i></div>' +
            '<div style="font-size:1.15em;font-weight:800;color:#f1f5f9;margin-bottom:6px;">' + title + '</div>' +
            '<div style="font-size:.82em;color:#94a3b8;margin-bottom:6px;">' + subtitle + '</div>' +
            '<div style="font-size:.78em;color:#ef4444;margin-bottom:8px;font-weight:600;" id="dw-inactivity-countdown">Fin de service automatique dans 5:00</div>' +
            '<div style="font-size:.72em;color:#64748b;margin-bottom:20px;font-style:italic;">Tu seras retire de ta patrouille et mis hors service si tu ne reponds pas.</div>' +
            '<button id="dw-inactivity-yes" style="width:100%;padding:16px;border:none;border-radius:10px;background:linear-gradient(135deg,#10b981,#059669);color:white;font-weight:800;font-size:1em;cursor:pointer;font-family:Inter,sans-serif;box-shadow:0 4px 15px rgba(16,185,129,0.3);"><i class="fa-solid fa-check" style="margin-right:8px;"></i>Oui, je suis toujours la</button>';

        if (!document.getElementById('dw-pulse-style')) {
            var st = document.createElement('style');
            st.id = 'dw-pulse-style';
            st.textContent = '@keyframes dwPulse { 0%,100% { box-shadow:0 0 0 0 rgba(245,158,11,0.5);} 50% { box-shadow:0 0 0 12px rgba(245,158,11,0); } }';
            document.head.appendChild(st);
        }

        document.body.appendChild(notif);

        var remaining = RESPONSE_TIMEOUT / 1000;
        var countdownEl = notif.querySelector('#dw-inactivity-countdown');
        var countdownTimer = setInterval(function() {
            remaining--;
            if (remaining <= 0) { clearInterval(countdownTimer); return; }
            var m = Math.floor(remaining / 60), s = remaining % 60;
            countdownEl.textContent = 'Fin de service automatique dans ' + m + ':' + (s < 10 ? '0' : '') + s;
        }, 1000);

        notif.querySelector('#dw-inactivity-yes').onclick = function(e) {
            if (e) { e.stopPropagation(); e.preventDefault(); }
            clearInterval(countdownTimer);
            lastActivity = Date.now();
            if (isForced) lastForcedCheckAt = Date.now();
            inactivityNotifShown = false;
            inactivityNotifReason = null;
            inactivityNotifShownAt = 0;
            if (inactivityAutoEndTimer) { clearTimeout(inactivityAutoEndTimer); inactivityAutoEndTimer = null; }
            if (notifWatchdogTimer) { clearInterval(notifWatchdogTimer); notifWatchdogTimer = null; }
            var bd = document.getElementById('dw-inactivity-backdrop');
            if (bd) bd.remove();
            notif.remove();
            dwToast(isForced ? 'Verification validee — bonne patrouille !' : 'Service confirme !', 'success');
            try {
                fetch(DISPATCH_API + '?action=confirm_afk_check', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ discord_id: user.discord_id, reason: isForced ? 'forced_4h' : 'inactivity_2h' })
                }).catch(function() {});
            } catch(e2) {}
        };

        try {
            if ('Notification' in window && Notification.permission === 'granted') {
                var n = new Notification('RP MDT Dispatch - ' + (isForced ? 'Verification 4h' : 'Toujours en service ?'), {
                    body: isForced
                        ? '4h de service. Reponds dans 5 min sinon ton service sera retire.'
                        : 'Aucune activite depuis 2h. Reponds dans 5 min sinon ton service sera retire.',
                    icon: '/favicon.ico',
                    tag: 'mdt-afk',
                    requireInteraction: true
                });
                n.onclick = function() { window.focus(); n.close(); };
            } else if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        } catch (e) {}
    }

    inactivityCheckTimer = setInterval(checkInactivity, CHECK_INTERVAL);

})();
