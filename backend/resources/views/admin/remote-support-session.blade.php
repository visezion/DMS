<x-admin-layout title="Remote Support Session" heading="Remote Support Session">
    @php
        $isOnline = $effectiveStatus === 'online';
        $statusClass = $isOnline
            ? 'border-emerald-200 bg-emerald-100 text-emerald-700'
            : 'border-slate-200 bg-slate-100 text-slate-700';
        $lastFrameLabel = $lastFrameAt ? $lastFrameAt->diffForHumans() : 'no frame yet';
    @endphp

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Remote Support Session</p>
                <h2 class="mt-1 text-2xl font-semibold text-slate-900">{{ $device->hostname }}</h2>
                <p class="mt-1 font-mono text-xs text-slate-500">{{ $device->id }}</p>
            </div>
            <div class="text-right">
                <a href="{{ route('admin.remote-support') }}" class="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">Back to Remote Support</a>
                <p class="mt-3">
                    <span class="rounded-full border px-2 py-0.5 text-xs capitalize {{ $statusClass }}">{{ $effectiveStatus }}</span>
                </p>
            </div>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs text-slate-500">Session ID</p>
                <p class="mt-1 break-all font-mono text-sm text-slate-900">{{ $session->id }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs text-slate-500">Mesh / Remote ID</p>
                <p class="mt-1 break-all font-mono text-sm text-slate-900">{{ $meshId }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs text-slate-500">Last Check-in</p>
                <p class="mt-1 text-sm text-slate-900">{{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'never' }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs text-slate-500">Method</p>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ $remoteMethod }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs text-slate-500">WebRTC Capability</p>
                <p class="mt-1 text-sm font-medium {{ $webRtcCapability ? 'text-emerald-700' : 'text-slate-700' }}">{{ $webRtcCapability ? 'enabled' : 'disabled' }}</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs text-slate-500">Last Frame</p>
                <p class="mt-1 text-sm text-slate-900" id="remote-last-frame">{{ $lastFrameLabel }}</p>
            </article>
        </div>
    </section>

    <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">Inbuilt Remote View</h3>
                <p class="mt-1 text-sm text-slate-500">Remote-desktop style viewer with video and direct input relay.</p>
                <p class="mt-2 text-xs text-slate-500">Primary: WebRTC. Fallback: live frames.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    id="remote-reconnect-btn"
                    class="rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                    {{ $isOnline ? '' : 'disabled' }}
                >
                    Reconnect
                </button>
                <button
                    type="button"
                    id="remote-toggle-btn"
                    class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:bg-slate-100"
                    {{ $isOnline ? '' : 'disabled' }}
                >
                    Pause Stream
                </button>
            </div>
        </div>

        <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-950 p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-300">
                <div class="flex flex-wrap items-center gap-3">
                    <span>Remote Frame</span>
                    <span id="remote-mode-label">{{ $remoteMethod }}</span>
                </div>
                <div class="flex flex-col items-end gap-1 text-right">
                    <div id="remote-meta">Initializing remote session...</div>
                    <div id="remote-token-meta" class="text-[11px] text-slate-400"></div>
                </div>
            </div>

            <div id="remote-stage" class="relative overflow-hidden rounded-xl border border-slate-800 bg-black">
                <video
                    id="remote-video"
                    autoplay
                    playsinline
                    muted
                    class="hidden h-auto w-full bg-black"
                ></video>
                <img
                    id="remote-frame"
                    alt="Remote frame fallback"
                    class="hidden h-auto w-full"
                />
                <div id="remote-placeholder" class="flex min-h-[420px] items-center justify-center px-6 py-12 text-center text-sm text-slate-400">
                    {{ $isOnline ? 'Waiting for live stream...' : 'Device is offline. Remote session unavailable.' }}
                </div>
            </div>
            <p class="mt-3 text-xs text-slate-400">Click inside the viewer before sending keyboard input.</p>
        </div>
    </section>

    <form method="POST" action="{{ route('admin.remote-support.close', $session->id) }}" class="mt-5">
        @csrf
        <button class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">
            Close Session
        </button>
    </form>

    <script>
        (() => {
            const appBasePath = @json(request()->getBaseUrl());
            const toAppUrl = (path) => {
                const normalizedBase = (appBasePath || '').replace(/\/+$/, '');
                const normalizedPath = String(path || '').startsWith('/') ? String(path || '') : `/${String(path || '')}`;
                return `${normalizedBase}${normalizedPath}`;
            };
            const bootstrapUrl = toAppUrl(@json(route('admin.remote-support.realtime.bootstrap', $session->id, false)));
            const signalPushUrl = toAppUrl(@json(route('admin.remote-support.realtime.signal.push', $session->id, false)));
            const signalPullUrl = toAppUrl(@json(route('admin.remote-support.realtime.signal.pull', $session->id, false)));
            const inputPushUrl = toAppUrl(@json(route('admin.remote-support.realtime.input.push', $session->id, false)));
            const captureUrl = toAppUrl(@json(route('admin.remote-support.capture', $device->id, false)));
            const frameUrl = toAppUrl(@json(route('admin.remote-support.frame', $device->id, false)));
            const csrfRefreshUrl = toAppUrl(@json(route('admin.session.csrf', [], false)));
            const fallbackCsrf = @json(csrf_token());
            const sessionId = @json($session->id);
            const isOnline = @json($isOnline);
            const defaultPollMs = @json($webrtcSignalPollIntervalMs);
            const inputFlushMs = @json($webrtcInputFlushIntervalMs);
            const inputBatchMax = @json($webrtcInputBatchMax);

            const reconnectBtn = document.getElementById('remote-reconnect-btn');
            const toggleBtn = document.getElementById('remote-toggle-btn');
            const video = document.getElementById('remote-video');
            const frameImg = document.getElementById('remote-frame');
            const placeholder = document.getElementById('remote-placeholder');
            const meta = document.getElementById('remote-meta');
            const tokenMeta = document.getElementById('remote-token-meta');
            const modeLabel = document.getElementById('remote-mode-label');
            const lastFrame = document.getElementById('remote-last-frame');
            const stage = document.getElementById('remote-stage');

            let peer = null;
            let mode = 'idle';
            let paused = false;
            let signalPollTimer = null;
            let fallbackPollTimer = null;
            let inputFlushTimer = null;
            let signalSince = 0;
            let inputQueue = [];
            let bootstrapState = null;
            let reconnectTimer = null;
            let reconnectAttempts = 0;
            const maxReconnectAttempts = 5;
            let bootstrapInFlight = false;
            let authBroken = false;
            let csrfToken = fallbackCsrf;
            let adminToken = '';
            let webrtcConnected = false;
            let webrtcTimeoutTimer = null;

            function readCookie(name) {
                const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const match = document.cookie.match(new RegExp(`(?:^|; )${escaped}=([^;]*)`));
                return match ? decodeURIComponent(match[1]) : '';
            }

            function currentCsrfToken() {
                const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const cookieToken = readCookie('XSRF-TOKEN');
                return csrfToken || metaToken || fallbackCsrf || cookieToken;
            }

            function storeCsrfToken(token) {
                const normalized = String(token || '').trim();
                if (normalized === '') {
                    return;
                }

                csrfToken = normalized;
                const metaNode = document.querySelector('meta[name="csrf-token"]');
                if (metaNode) {
                    metaNode.setAttribute('content', normalized);
                }
            }

            function ajaxHeaders(includeCsrf = false) {
                const headers = {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                };
                if (adminToken) {
                    headers['X-Remote-Support-Token'] = adminToken;
                }
                if (includeCsrf) {
                    headers['Content-Type'] = 'application/json';
                    headers['X-CSRF-TOKEN'] = currentCsrfToken();
                }

                return headers;
            }

            function setMeta(text) {
                if (meta) meta.textContent = text;
            }

            function setTokenMeta(text) {
                if (tokenMeta) tokenMeta.textContent = text || '';
            }

            function setModeLabel(text) {
                if (modeLabel) modeLabel.textContent = text;
            }

            function setLastFrame(text) {
                if (lastFrame) lastFrame.textContent = text;
            }

            function showPlaceholder(text) {
                placeholder.textContent = text;
                placeholder.classList.remove('hidden');
            }

            function showVideo() {
                video.classList.remove('hidden');
                frameImg.classList.add('hidden');
                placeholder.classList.add('hidden');
            }

            function showFrame(src) {
                frameImg.src = src;
                frameImg.classList.remove('hidden');
                video.classList.add('hidden');
                placeholder.classList.add('hidden');
            }

            function stopTimers() {
                if (signalPollTimer) {
                    clearInterval(signalPollTimer);
                    signalPollTimer = null;
                }
                if (fallbackPollTimer) {
                    clearInterval(fallbackPollTimer);
                    fallbackPollTimer = null;
                }
                if (inputFlushTimer) {
                    clearInterval(inputFlushTimer);
                    inputFlushTimer = null;
                }
                if (reconnectTimer) {
                    clearTimeout(reconnectTimer);
                    reconnectTimer = null;
                }
                if (webrtcTimeoutTimer) {
                    clearTimeout(webrtcTimeoutTimer);
                    webrtcTimeoutTimer = null;
                }
            }

            function stopWithAuthError(message) {
                authBroken = true;
                paused = true;
                stopTimers();
                destroyPeer();
                adminToken = '';
                setTokenMeta('');
                setReconnectDisabled(false);
                if (toggleBtn) {
                    toggleBtn.textContent = 'Resume Stream';
                    toggleBtn.disabled = true;
                }
                setMeta(message || 'admin session expired');
                showPlaceholder('Admin session expired. Reload the page and sign in again.');
            }

            async function ensureJsonSessionResponse(response) {
                const contentType = String(response.headers.get('content-type') || '').toLowerCase();
                const redirectedToLogin = response.redirected && String(response.url || '').includes('/admin/login');
                const htmlLoginResponse = contentType.includes('text/html') && String(response.url || '').includes('/admin/login');
                if (!redirectedToLogin && !htmlLoginResponse) {
                    return;
                }

                stopWithAuthError('admin session expired');
                throw new Error('auth-redirect');
            }

            async function ensureAuthorized(response, payload) {
                if (response.status !== 401 && response.status !== 419) {
                    return payload;
                }

                stopWithAuthError(payload?.message || (response.status === 419 ? 'csrf token mismatch' : 'admin session expired'));
                throw new Error(`auth-${response.status}`);
            }

            async function refreshCsrfToken() {
                const response = await fetch(csrfRefreshUrl, {
                    method: 'GET',
                    headers: ajaxHeaders(false),
                    credentials: 'same-origin',
                });
                await ensureJsonSessionResponse(response);
                const payload = await response.json().catch(() => ({}));
                await ensureAuthorized(response, payload);
                if (!response.ok || payload?.ok !== true || typeof payload?.token !== 'string' || payload.token.trim() === '') {
                    return false;
                }

                storeCsrfToken(payload.token);
                return true;
            }

            function destroyPeer() {
                if (!peer) return;
                try {
                    peer.ontrack = null;
                    peer.onicecandidate = null;
                    peer.close();
                } catch (error) {
                }
                peer = null;
            }

            function setReconnectDisabled(disabled) {
                if (reconnectBtn) {
                    reconnectBtn.disabled = disabled || !isOnline;
                }
            }

            function scheduleReconnect(reason) {
                if (paused || reconnectAttempts >= maxReconnectAttempts) {
                    if (reconnectAttempts >= maxReconnectAttempts) {
                        setMeta('reconnect limit reached');
                        showPlaceholder('Remote stream dropped. Click Reconnect to try again.');
                    }
                    return;
                }

                reconnectAttempts += 1;
                const delayMs = Math.min(8000, 1000 * reconnectAttempts);
                setMeta(`${reason || 'stream lost'}; retrying in ${Math.round(delayMs / 1000)}s`);
                if (reconnectTimer) {
                    clearTimeout(reconnectTimer);
                }
                reconnectTimer = setTimeout(() => {
                    reconnectTimer = null;
                    bootstrap().catch(() => {
                    });
                }, delayMs);
            }

            async function postJson(url, body) {
                const requestInit = () => ({
                    method: 'POST',
                    headers: ajaxHeaders(true),
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        ...(body || {}),
                        token: adminToken || undefined,
                        _token: currentCsrfToken(),
                    }),
                });

                let response = await fetch(url, requestInit());
                await ensureJsonSessionResponse(response);
                let payload = await response.json().catch(() => ({}));
                if (response.status === 419) {
                    const refreshed = await refreshCsrfToken().catch(() => false);
                    if (refreshed) {
                        response = await fetch(url, requestInit());
                        await ensureJsonSessionResponse(response);
                        payload = await response.json().catch(() => ({}));
                    }
                }
                await ensureAuthorized(response, payload);
                return { response, payload };
            }

            async function bootstrap() {
                if (bootstrapInFlight) return;
                if (authBroken) return;
                if (!isOnline) {
                    showPlaceholder('Device is offline. Remote session unavailable.');
                    setMeta('offline');
                    return;
                }

                bootstrapInFlight = true;
                setReconnectDisabled(true);
                stopTimers();
                destroyPeer();
                signalSince = 0;
                inputQueue = [];
                video.srcObject = null;
                setMeta('Bootstrapping remote session...');
                try {
                    const { response, payload } = await postJson(bootstrapUrl, {});
                    if (!response.ok || payload?.ok !== true) {
                        showPlaceholder(payload?.message || 'Failed to bootstrap remote session.');
                        setMeta(payload?.message || 'bootstrap failed');
                        scheduleReconnect(payload?.message || 'bootstrap failed');
                        return;
                    }

                    reconnectAttempts = 0;
                    bootstrapState = payload;
                    adminToken = String(payload?.admin_token || '').trim();
                    if (payload?.admin_token_expires_at) {
                        const expires = new Date(payload.admin_token_expires_at);
                        if (!Number.isNaN(expires.getTime())) {
                            setTokenMeta(`Admin token expires ${expires.toLocaleString()}`);
                        } else {
                            setTokenMeta('');
                        }
                    } else {
                        setTokenMeta('');
                    }
                    mode = payload.mode || 'live_fallback';
                    setModeLabel(mode);

                    if (mode === 'webrtc') {
                        await startWebRtc(payload);
                        return;
                    }

                    await startFallback(payload);
                } catch (error) {
                    if (authBroken) {
                        return;
                    }
                    showPlaceholder('Failed to bootstrap remote session.');
                    setMeta(error instanceof Error && error.message ? error.message : 'bootstrap failed');
                    scheduleReconnect(error instanceof Error && error.message ? error.message : 'bootstrap failed');
                } finally {
                    bootstrapInFlight = false;
                    setReconnectDisabled(false);
                }
            }

            async function startWebRtc(payload) {
                setMeta('Starting WebRTC session...');
                showPlaceholder('Waiting for live stream...');
                webrtcConnected = false;
                peer = new RTCPeerConnection({
                    iceServers: Array.isArray(payload.ice_servers) ? payload.ice_servers : [],
                });

                peer.ontrack = (event) => {
                    const stream = event.streams && event.streams[0] ? event.streams[0] : new MediaStream([event.track]);
                    video.srcObject = stream;
                    showVideo();
                    setMeta('WebRTC stream connected');
                    reconnectAttempts = 0;
                    webrtcConnected = true;
                    if (webrtcTimeoutTimer) {
                        clearTimeout(webrtcTimeoutTimer);
                        webrtcTimeoutTimer = null;
                    }
                };

                peer.onconnectionstatechange = () => {
                    const state = String(peer?.connectionState || '');
                    if (state === 'failed' || state === 'disconnected' || state === 'closed') {
                        scheduleReconnect(`webrtc ${state}`);
                    }
                };

                peer.oniceconnectionstatechange = () => {
                    const state = String(peer?.iceConnectionState || '');
                    if (state === 'failed' || state === 'disconnected' || state === 'closed') {
                        scheduleReconnect(`ice ${state}`);
                    }
                };

                peer.onicecandidate = async (event) => {
                    if (!event.candidate) return;
                    await postJson(signalPushUrl, {
                        type: 'ice-candidate',
                        payload: {
                            candidate: {
                                candidate: event.candidate.candidate,
                                sdpMid: event.candidate.sdpMid,
                                sdpMLineIndex: event.candidate.sdpMLineIndex,
                            },
                        },
                    });
                };

                const pollMs = Number(payload.signal_poll_interval_ms || defaultPollMs);
                signalPollTimer = setInterval(pollSignals, Math.max(120, pollMs));
                inputFlushTimer = setInterval(flushInputs, Math.max(16, Number(payload.input_flush_interval_ms || inputFlushMs)));
                await pollSignals();

                if (webrtcTimeoutTimer) {
                    clearTimeout(webrtcTimeoutTimer);
                }
                webrtcTimeoutTimer = setTimeout(() => {
                    if (authBroken || paused) return;
                    if (mode !== 'webrtc') return;
                    if (webrtcConnected) return;
                    setMeta('WebRTC timed out; switching to live frames...');
                    startFallback();
                }, 12000);
            }

            async function pollSignals() {
                if (paused || !peer) return;
                if (authBroken) return;
                try {
                    const url = `${signalPullUrl}?since=${encodeURIComponent(signalSince)}${adminToken ? `&token=${encodeURIComponent(adminToken)}` : ''}`;
                    const response = await fetch(url, {
                        method: 'GET',
                        headers: ajaxHeaders(false),
                        credentials: 'same-origin',
                    });
                    await ensureJsonSessionResponse(response);
                    const payload = await response.json().catch(() => ({}));
                    await ensureAuthorized(response, payload);
                    if (!response.ok || payload?.ok !== true) {
                        setMeta(payload?.message || 'signal poll failed');
                        scheduleReconnect(payload?.message || 'signal poll failed');
                        return;
                    }

                    const events = Array.isArray(payload.events) ? payload.events : [];
                    for (const event of events) {
                        signalSince = Math.max(signalSince, Number(event.seq || 0));
                        await applySignalEvent(event);
                    }
                } catch (error) {
                    if (authBroken) {
                        return;
                    }
                    setMeta('signal poll failed');
                    scheduleReconnect('signal poll failed');
                }
            }

            async function applySignalEvent(event) {
                const type = String(event.type || '').toLowerCase();
                const payload = event.payload || {};

                if (type === 'offer') {
                    if (!payload.sdp) return;
                    try {
                        await peer.setRemoteDescription({ type: 'offer', sdp: payload.sdp });
                        const answer = await peer.createAnswer();
                        await peer.setLocalDescription(answer);
                        await postJson(signalPushUrl, {
                            type: 'answer',
                            payload: {
                                type: answer.type,
                                sdp: answer.sdp,
                            },
                        });
                        setMeta('Negotiating WebRTC stream...');
                    } catch (error) {
                        setMeta('webrtc negotiation failed');
                        scheduleReconnect('webrtc negotiation failed');
                    }
                    return;
                }

                if (type === 'ice-candidate') {
                    const candidateNode = payload.candidate || payload;
                    if (!candidateNode || !candidateNode.candidate) return;
                    try {
                        await peer.addIceCandidate(candidateNode);
                    } catch (error) {
                    }
                    return;
                }

                if (type === 'status') {
                    setMeta(payload.message || payload.phase || 'remote status update');
                    return;
                }

                if (type === 'error' || type === 'bye') {
                    setMeta(payload.message || 'WebRTC stream ended');
                    scheduleReconnect(payload.message || 'WebRTC stream ended');
                }
            }

            async function startFallback() {
                destroyPeer();
                stopTimers();
                mode = 'live_fallback';
                setModeLabel('live_fallback');
                setMeta('Using live-frame fallback...');
                await queueLiveCapture();
                fallbackPollTimer = setInterval(fetchFrame, 1000);
                await fetchFrame();
            }

            async function queueLiveCapture() {
                const { response, payload } = await postJson(captureUrl, { mode: 'live' });
                if (!response.ok || payload?.ok !== true) {
                    setMeta(payload?.message || 'unable to start live fallback');
                    scheduleReconnect(payload?.message || 'unable to start live fallback');
                }
            }

            async function fetchFrame() {
                if (paused) return;
                try {
                    const response = await fetch(frameUrl, {
                        method: 'GET',
                        headers: ajaxHeaders(false),
                        credentials: 'same-origin',
                    });
                    await ensureJsonSessionResponse(response);
                    const payload = await response.json().catch(() => ({}));
                    await ensureAuthorized(response, payload);
                    if (!response.ok || payload?.ok !== true || !payload?.frame?.image_base64) {
                        setMeta(payload?.message || 'waiting for first frame...');
                        return;
                    }
                    const mimeType = payload.frame.mime_type || 'image/jpeg';
                    showFrame(`data:${mimeType};base64,${payload.frame.image_base64}`);
                    setMeta(`${payload.frame.byte_size || 0} bytes`);
                    if (payload.frame.captured_at_iso) {
                        setLastFrame(new Date(payload.frame.captured_at_iso).toLocaleString());
                    }
                } catch (error) {
                    if (authBroken) {
                        return;
                    }
                    setMeta('frame refresh failed');
                    scheduleReconnect('frame refresh failed');
                }
            }

            function queueInput(type, payload) {
                if (paused || mode !== 'webrtc') return;
                inputQueue.push({ type, payload });
                if (inputQueue.length >= inputBatchMax) {
                    flushInputs();
                }
            }

            async function flushInputs() {
                if (paused || mode !== 'webrtc' || inputQueue.length === 0) return;
                if (authBroken) return;
                const batch = inputQueue.splice(0, inputBatchMax);
                try {
                    await postJson(inputPushUrl, { events: batch });
                } catch (error) {
                }
            }

            function normalizedPoint(event) {
                const rect = stage.getBoundingClientRect();
                const x = rect.width > 0 ? Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)) : 0;
                const y = rect.height > 0 ? Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height)) : 0;
                return { x, y };
            }

            stage.tabIndex = 0;
            stage.addEventListener('mousemove', (event) => {
                const point = normalizedPoint(event);
                queueInput('mouse_move', point);
            });
            stage.addEventListener('mousedown', (event) => {
                stage.focus();
                const point = normalizedPoint(event);
                queueInput('mouse_move', point);
                queueInput('mouse_down', { button: event.button });
                event.preventDefault();
            });
            stage.addEventListener('mouseup', (event) => {
                const point = normalizedPoint(event);
                queueInput('mouse_move', point);
                queueInput('mouse_up', { button: event.button });
                event.preventDefault();
            });
            stage.addEventListener('wheel', (event) => {
                queueInput('wheel', { delta_y: event.deltaY });
                event.preventDefault();
            }, { passive: false });
            stage.addEventListener('contextmenu', (event) => event.preventDefault());
            stage.addEventListener('keydown', (event) => {
                queueInput('key_down', { code: event.code, key: event.key });
                event.preventDefault();
            });
            stage.addEventListener('keyup', (event) => {
                queueInput('key_up', { code: event.code, key: event.key });
                event.preventDefault();
            });

            reconnectBtn?.addEventListener('click', () => {
                reconnectAttempts = 0;
                bootstrap().catch(() => {
                });
            });

            toggleBtn?.addEventListener('click', async () => {
                paused = !paused;
                toggleBtn.textContent = paused ? 'Resume Stream' : 'Pause Stream';
                if (authBroken) {
                    return;
                }
                if (paused) {
                    stopTimers();
                    setMeta('stream paused');
                    return;
                }

                if (mode === 'webrtc') {
                    signalPollTimer = setInterval(pollSignals, Math.max(120, Number(bootstrapState?.signal_poll_interval_ms || defaultPollMs)));
                    inputFlushTimer = setInterval(flushInputs, Math.max(16, Number(bootstrapState?.input_flush_interval_ms || inputFlushMs)));
                    setMeta('resuming WebRTC session...');
                    await pollSignals();
                    return;
                }

                fallbackPollTimer = setInterval(fetchFrame, 1000);
                setMeta('resuming live stream...');
                await queueLiveCapture();
                await fetchFrame();
            });

            if (isOnline) {
                bootstrap();
            }
        })();
    </script>
</x-admin-layout>
