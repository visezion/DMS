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

        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
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
                <div id="remote-meta">Initializing remote session...</div>
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
            const bootstrapUrl = @json(route('admin.remote-support.realtime.bootstrap', $session->id, false));
            const signalPushUrl = @json(route('admin.remote-support.realtime.signal.push', $session->id, false));
            const signalPullUrl = @json(route('admin.remote-support.realtime.signal.pull', $session->id, false));
            const inputPushUrl = @json(route('admin.remote-support.realtime.input.push', $session->id, false));
            const captureUrl = @json(route('admin.remote-support.capture', $device->id, false));
            const frameUrl = @json(route('admin.remote-support.frame', $device->id, false));
            const csrf = @json(csrf_token());
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

            function setMeta(text) {
                if (meta) meta.textContent = text;
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
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                });
                const payload = await response.json().catch(() => ({}));
                return { response, payload };
            }

            async function bootstrap() {
                if (bootstrapInFlight) return;
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
                    mode = payload.mode || 'live_fallback';
                    setModeLabel(mode);

                    if (mode === 'webrtc') {
                        await startWebRtc(payload);
                        return;
                    }

                    await startFallback(payload);
                } catch (error) {
                    showPlaceholder('Failed to bootstrap remote session.');
                    setMeta('bootstrap failed');
                    scheduleReconnect('bootstrap failed');
                } finally {
                    bootstrapInFlight = false;
                    setReconnectDisabled(false);
                }
            }

            async function startWebRtc(payload) {
                setMeta('Starting WebRTC session...');
                showPlaceholder('Waiting for live stream...');
                peer = new RTCPeerConnection({
                    iceServers: Array.isArray(payload.ice_servers) ? payload.ice_servers : [],
                });

                peer.ontrack = (event) => {
                    const stream = event.streams && event.streams[0] ? event.streams[0] : new MediaStream([event.track]);
                    video.srcObject = stream;
                    showVideo();
                    setMeta('WebRTC stream connected');
                    reconnectAttempts = 0;
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
            }

            async function pollSignals() {
                if (paused || !peer) return;
                try {
                    const url = `${signalPullUrl}?since=${encodeURIComponent(signalSince)}`;
                    const response = await fetch(url, {
                        method: 'GET',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json().catch(() => ({}));
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
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json().catch(() => ({}));
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
