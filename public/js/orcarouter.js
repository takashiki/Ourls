/*
 * OrcaRouter provider panel.
 *
 * The browser is a thin client here: it never holds an API key and never calls
 * OrcaRouter directly. It starts a login, submits the code the consent screen
 * showed, and asks the server for a capability-filtered model list.
 *
 * Concurrency rules enforced below:
 *   - `generation` is incremented on every state-changing start and on
 *     `pagehide`. Every async callback checks it before touching the UI, so a
 *     late response from an abandoned attempt cannot overwrite a newer login.
 *   - `pagehide` clears the busy flag and the authorization hint synchronously
 *     and sends the server cancel with keepalive. Relying on the guarded
 *     `finally` would leave a back-forward-cache restore permanently busy.
 */
(function ($) {
    'use strict';

    var CAP_CHAT = 'chat';

    var state = {
        generation: 0,
        attempt: null,
        loggingIn: false,
        model: null,
        capabilities: null,
        lastCatalog: null,
        active: 'orcarouter'
    };

    function el(id) {
        return document.getElementById(id);
    }

    function post(path, payload) {
        return $.ajax({
            url: path,
            type: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload || {})
        });
    }

    function note(id, message, className) {
        var node = el(id);
        if (!node) {
            return;
        }
        node.textContent = message;
        node.className = 'orca-hint' + (className ? ' ' + className : '');
    }

    function setState(label, className) {
        var node = el('orca-state');
        if (!node) {
            return;
        }
        node.textContent = label;
        node.className = 'orca-state' + (className ? ' ' + className : '');
    }

    /* ------------------------------------------------------------------ *
     * Model selector
     * ------------------------------------------------------------------ */

    function closeList() {
        var list = el('orca-model-list');
        var trigger = el('orca-model-button');
        if (list) {
            list.hidden = true;
        }
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
    }

    function openList() {
        var list = el('orca-model-list');
        var trigger = el('orca-model-button');
        if (!list || !list.children.length) {
            return;
        }
        list.hidden = false;
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'true');
        }
    }

    function renderList(models, source, notice) {
        var list = el('orca-model-list');
        var trigger = el('orca-model-button');
        if (!list) {
            return;
        }
        list.innerHTML = '';

        if (!models.length) {
            var empty = document.createElement('li');
            empty.className = 'orca-option-empty';
            empty.textContent = 'No model matches the current capability.';
            list.appendChild(empty);
        }

        models.forEach(function (model) {
            var item = document.createElement('li');
            item.className = 'orca-option';
            item.setAttribute('role', 'option');
            item.setAttribute('data-model-id', model.id);
            item.setAttribute('aria-selected', state.model === model.id ? 'true' : 'false');

            var id = document.createElement('span');
            id.className = 'orca-option-id';
            id.textContent = model.id;
            item.appendChild(id);

            var meta = [];
            if (model.input_modalities && model.input_modalities.length) {
                meta.push(model.input_modalities.join(', '));
            }
            if (model.context_length) {
                meta.push(Math.round(model.context_length / 1000) + 'k ctx');
            }
            if (model.reasoning_efforts && model.reasoning_efforts.length) {
                meta.push('reasoning: ' + model.reasoning_efforts.join('/'));
            }
            if (meta.length) {
                var metaNode = document.createElement('span');
                metaNode.className = 'orca-option-meta';
                metaNode.textContent = meta.join(' · ');
                item.appendChild(metaNode);
            }

            item.addEventListener('click', function () {
                chooseModel(model.id);
            });
            list.appendChild(item);
        });

        if (trigger && state.model) {
            trigger.textContent = state.model;
        }

        var label = models.length + ' model' + (models.length === 1 ? '' : 's');
        if (source !== 'live') {
            label += ' · degraded: verified fallback list';
        } else if (notice) {
            label += ' · ' + notice;
        }
        note('orca-model-status', label);
    }

    function loadModels(capability, modalities, keepSelection) {
        var query = 'capability=' + encodeURIComponent(capability);
        if (modalities && modalities.length) {
            query += '&modalities=' + encodeURIComponent(modalities.join(','));
        }

        return $.getJSON('orcarouter/models?' + query).then(function (data) {
            state.capabilities = modalityKey(modalities);
            state.lastCatalog = data;

            var ids = data.models.map(function (model) {
                return model.id;
            });

            // A model that is no longer compatible is cleared, never silently
            // kept. The server does the same for the persisted value.
            if (state.model && (!keepSelection || ids.indexOf(state.model) === -1)) {
                if (ids.indexOf(state.model) === -1) {
                    state.model = null;
                    var trigger = el('orca-model-button');
                    if (trigger) {
                        trigger.textContent = 'Select a model';
                    }
                }
            }

            renderList(data.models, data.source, data.notice);

            if (!state.model && data.models.length) {
                chooseModel(data.models[0].id);
            } else if (state.model) {
                persistModel(state.model);
            }

            return data;
        });
    }

    function modalityKey(modalities) {
        return (modalities || []).slice().sort().join(',');
    }

    function chooseModel(modelId) {
        state.model = modelId;
        var trigger = el('orca-model-button');
        if (trigger) {
            trigger.textContent = modelId;
        }
        Array.prototype.forEach.call(
            el('orca-model-list').querySelectorAll('[data-model-id]'),
            function (item) {
                item.setAttribute('aria-selected', item.getAttribute('data-model-id') === modelId ? 'true' : 'false');
            }
        );
        closeList();
        persistModel(modelId);
    }

    function persistModel(modelId) {
        post('orcarouter/model', {
            model: modelId,
            capability: CAP_CHAT,
            modalities: currentModalities()
        }).then(function (data) {
            if (data && data.cleared) {
                state.model = null;
                note('orca-model-status', 'The previous model is no longer available. Pick another one.', 'orca-state-degraded');
            }
        });
    }

    /* ------------------------------------------------------------------ *
     * Attachment / capability changes
     * ------------------------------------------------------------------ */

    function currentModalities() {
        var modalities = [];
        if (el('orca-attach-image') && el('orca-attach-image').checked) {
            modalities.push('image');
        }

        return modalities;
    }

    function modalitiesChanged() {
        var modalities = currentModalities();
        var incompatible = state.model && state.lastCatalog && state.lastCatalog.models.every(function (model) {
            return model.id !== state.model;
        });

        loadModels(CAP_CHAT, modalities, true).then(function () {
            if (incompatible) {
                note('orca-model-status', 'The selected model does not accept the new attachment. Pick another one.', 'orca-state-degraded');
            }
        });
    }

    /* ------------------------------------------------------------------ *
     * Login lifecycle
     * ------------------------------------------------------------------ */

    function beginLogin() {
        // A new login supersedes everything before it.
        state.generation += 1;
        var generation = state.generation;
        state.loggingIn = true;

        // Clear any URL from a previous attempt first: leaving it on screen
        // would show an authorize URL that no longer belongs to this sign-in.
        var staleWrap = el('orca-pkce-url-wrap');
        var staleUrl = el('orca-pkce-url');
        if (staleUrl) {
            staleUrl.value = '';
        }
        if (staleWrap) {
            staleWrap.classList.add('am-hide');
        }

        setBusy(true);
        note('orca-pkce-status', 'Opening the OrcaRouter authorization page…');

        return post('orcarouter/pkce/start', {}).then(function (data) {
            if (generation !== state.generation) {
                return;
            }
            state.attempt = data.attempt;

            var wrap = el('orca-pkce-url-wrap');
            var url = el('orca-pkce-url');
            if (wrap && url) {
                url.value = data.authorize_url;
                wrap.classList.remove('am-hide');
            }
            note('orca-pkce-status', 'Waiting for the code from the OrcaRouter consent screen.');

            if (window.open) {
                window.open(data.authorize_url, '_blank', 'noopener');
            }

            return data;
        }).fail(function (xhr) {
            if (generation === state.generation) {
                failLogin(xhr);
            }
        });
    }

    function failLogin(xhr) {
        var message = 'OrcaRouter sign-in could not start.';
        if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
            message = xhr.responseJSON.error;
        } else if (xhr && xhr.status === 0) {
            message = 'The server is unreachable.';
        }
        state.loggingIn = false;
        state.attempt = null;
        setBusy(false);
        note('orca-pkce-status', message);
    }

    function finishLogin() {
        var codeNode = el('orca-pkce-code');
        var code = codeNode ? codeNode.value.trim() : '';
        if (!code) {
            note('orca-pkce-status', 'Paste the code shown on the OrcaRouter consent screen.');
            return;
        }

        state.generation += 1;
        var generation = state.generation;

        post('orcarouter/pkce/exchange', { code: code, state: null }).then(function (data) {
            if (generation !== state.generation) {
                return;
            }
            state.loggingIn = false;
            state.attempt = null;
            if (codeNode) {
                codeNode.value = '';
            }
            setBusy(false);
            closeList();

            if (data.credential && data.credential.status === 'active') {
                note('orca-pkce-status', 'Signed in. Key ' + data.credential.masked + ' is stored on the server.');
                setState('Connected', 'orca-state-connected');
                if (data.credential.scope && data.credential.scope !== 'api') {
                    note('orca-pkce-status', 'Signed in with the "' + data.credential.scope + '" scope; the requested "api" scope was not granted.', 'orca-state-degraded');
                }
            }
            refreshStatus();
            loadModels(CAP_CHAT, currentModalities(), false);
        }).fail(function (xhr) {
            if (generation !== state.generation) {
                return;
            }
            state.loggingIn = false;
            state.attempt = null;
            setBusy(false);
            var message = 'OrcaRouter sign-in failed.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                message = xhr.responseJSON.error;
            }
            note('orca-pkce-status', message);
        });
    }

    /*
     * Release the server lock. Used by the Cancel button and by pagehide.
     * `keepalive` lets the request outlive the page; it is not required for
     * correctness because the server lock also expires on its own.
     */
    function cancelLogin(attempt) {
        var body = JSON.stringify({ attempt: attempt === undefined ? state.attempt : attempt });
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon('orcarouter/pkce/cancel', new Blob([body], { type: 'application/json' }));
            } else {
                $.ajax({ url: 'orcarouter/pkce/cancel', type: 'POST', contentType: 'application/json', data: body, async: true });
            }
        } catch (error) {
            /* the lock also has a TTL, so a failed cancel is recoverable */
        }
    }

    function setBusy(busy) {
        state.loggingIn = busy;
        var start = el('orca-pkce-start');
        var cancel = el('orca-pkce-cancel');
        var code = el('orca-pkce-code');
        var exchange = el('orca-pkce-exchange');
        if (start) {
            start.disabled = busy;
        }
        if (cancel) {
            cancel.disabled = !busy;
        }
        if (code) {
            code.disabled = !busy;
        }
        if (exchange) {
            exchange.disabled = !busy;
        }
    }

    /* ------------------------------------------------------------------ *
     * Status
     * ------------------------------------------------------------------ */

    function applyStatus(status) {
        if (!status) {
            return;
        }
        var api = status.providers.orcarouter;
        var pkce = status.providers['orcarouter-oauth'];

        if (api && api.credential) {
            note('orca-api-key-status', 'Saved key ' + api.credential.masked + (api.credential.usable ? '' : ' — needs to be replaced'), api.credential.usable ? 'orca-masked' : 'orca-masked orca-state-degraded');
        } else {
            note('orca-api-key-status', 'No key saved.', 'orca-masked');
        }

        if (pkce && pkce.credential) {
            note('orca-pkce-status', 'Signed in' + (pkce.credential.masked ? ' (' + pkce.credential.masked + ')' : '') + (pkce.credential.usable ? '' : ' — needs to be reconnected'));
        }

        var connected = (api && api.credential && api.credential.usable) || (pkce && pkce.credential && pkce.credential.usable);
        var reauth = (api && api.credential && !api.credential.usable) || (pkce && pkce.credential && !pkce.credential.usable);

        if (connected) {
            setState('Connected', 'orca-state-connected');
        } else if (reauth) {
            setState('Needs reconnect', 'orca-state-degraded');
        } else {
            setState('Not connected', '');
        }

        if (status.login && status.login.in_progress && !state.loggingIn) {
            // Another page (or a previous load) left a lock behind. Offer it
            // back rather than showing a permanently busy panel.
            state.attempt = status.login.attempt;
            setBusy(true);
            note('orca-pkce-status', 'An OrcaRouter sign-in is already in progress.');
        }
        if (!status.login || !status.login.in_progress) {
            if (!state.loggingIn) {
                setBusy(false);
            }
        }
    }

    function refreshStatus() {
        return $.getJSON('orcarouter/status').then(function (data) {
            applyStatus(data.status);
            return data.status;
        });
    }

    /* ------------------------------------------------------------------ *
     * Wiring
     * ------------------------------------------------------------------ */

    function wire() {
        var trigger = el('orca-model-button');
        if (trigger) {
            trigger.addEventListener('click', function () {
                var list = el('orca-model-list');
                if (list && list.hidden) {
                    openList();
                } else {
                    closeList();
                }
            });
        }

        var save = el('orca-api-key-save');
        if (save) {
            save.addEventListener('click', function () {
                var field = el('orca-api-key');
                var key = field ? field.value : '';
                if (!key) {
                    note('orca-api-key-status', 'Enter an OrcaRouter API key first.', 'orca-masked');
                    return;
                }
                post('orcarouter/api-key', { api_key: key }).then(function (data) {
                    if (field) {
                        field.value = '';
                    }
                    note('orca-api-key-status', 'Saved key ' + data.credential.masked + '.', 'orca-masked');
                    setState('Connected', 'orca-state-connected');
                    refreshStatus();
                    loadModels(CAP_CHAT, currentModalities(), false);
                }).fail(function (xhr) {
                    var message = 'The key could not be saved.';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                        message = xhr.responseJSON.error;
                    }
                    note('orca-api-key-status', message, 'orca-masked');
                });
            });
        }

        var clear = el('orca-api-key-clear');
        if (clear) {
            clear.addEventListener('click', function () {
                post('orcarouter/api-key/clear', {}).then(function () {
                    note('orca-api-key-status', 'No key saved.', 'orca-masked');
                    refreshStatus();
                });
            });
        }

        var start = el('orca-pkce-start');
        if (start) {
            start.addEventListener('click', function () {
                beginLogin();
            });
        }

        var cancel = el('orca-pkce-cancel');
        if (cancel) {
            cancel.addEventListener('click', function () {
                var attempt = state.attempt;
                state.generation += 1;
                state.loggingIn = false;
                state.attempt = null;
                setBusy(false);
                note('orca-pkce-status', 'Sign-in cancelled.');
                cancelLogin(attempt);
            });
        }

        var exchange = el('orca-pkce-exchange');
        if (exchange) {
            exchange.addEventListener('click', finishLogin);
        }

        var forget = el('orca-pkce-forget');
        if (forget) {
            forget.addEventListener('click', function () {
                post('orcarouter/pkce/forget', {}).then(function () {
                    note('orca-pkce-status', 'Signed out.');
                    refreshStatus();
                });
            });
        }

        var attach = el('orca-attach-image');
        if (attach) {
            attach.addEventListener('change', modalitiesChanged);
        }

        Array.prototype.forEach.call(document.querySelectorAll('input[name="orca-auth"]'), function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) {
                    return;
                }
                // Switching authentication method abandons any running login.
                if (state.loggingIn) {
                    var attempt = state.attempt;
                    state.generation += 1;
                    state.loggingIn = false;
                    state.attempt = null;
                    setBusy(false);
                    cancelLogin(attempt);
                }
                state.active = radio.value;
                post('orcarouter/provider', { provider: radio.value }).then(refreshStatus);
            });
        });

        var send = el('orca-send');
        if (send) {
            send.addEventListener('click', function () {
                var prompt = el('orca-prompt').value;
                var answer = el('orca-answer');
                if (!state.model) {
                    note('orca-model-status', 'Select a model first.', 'orca-state-degraded');
                    return;
                }
                post('orcarouter/chat', {
                    model: state.model,
                    prompt: prompt,
                    attachment_modalities: currentModalities()
                }).then(function (data) {
                    if (answer) {
                        answer.classList.remove('am-hide');
                        answer.textContent = data.content;
                    }
                }).fail(function (xhr) {
                    var message = 'The request failed.';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                        message = xhr.responseJSON.error;
                    }
                    if (answer) {
                        answer.classList.remove('am-hide');
                        answer.textContent = message;
                    }
                    if (xhr && xhr.status === 401) {
                        refreshStatus();
                    }
                });
            });
        }
    }

    /*
     * pagehide fires when the page enters the back-forward cache. Clear the UI
     * state synchronously here and cancel the server work with keepalive: the
     * generation guard would otherwise suppress the late `finally` and a
     * restored page would stay busy forever.
     */
    function onPageHide() {
        state.generation += 1;
        var wasLoggingIn = state.loggingIn;
        var attempt = state.attempt;
        state.loggingIn = false;
        state.attempt = null;
        setBusy(false);
        if (wasLoggingIn) {
            note('orca-pkce-status', 'Sign-in was interrupted. You can start again.');
            cancelLogin(attempt);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        wire();
        setBusy(false);
        closeList();
        refreshStatus().then(function () {
            loadModels(CAP_CHAT, [], true);
        });
    });

    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('beforeunload', onPageHide);
}(window.jQuery));
