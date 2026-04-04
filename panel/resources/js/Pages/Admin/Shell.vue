<template>
    <AppLayout :title="`Shell — ${node.name}`">
        <div class="flex h-full flex-col gap-4 p-6">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold text-gray-100">
                        Shell — {{ node.name }}
                    </h1>
                    <p class="mt-0.5 text-sm text-gray-400">{{ node.ip_address }}</p>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Connection status badge -->
                    <span
                        :class="[
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
                            status === 'connected'    ? 'bg-green-900/40 text-green-400'  :
                            status === 'connecting'   ? 'bg-yellow-900/40 text-yellow-400' :
                            status === 'disconnected' ? 'bg-gray-800 text-gray-400'        :
                                                        'bg-red-900/40 text-red-400'
                        ]"
                    >
                        <span :class="['h-1.5 w-1.5 rounded-full', status === 'connected' ? 'bg-green-400 animate-pulse' : status === 'connecting' ? 'bg-yellow-400 animate-pulse' : 'bg-gray-500']"></span>
                        {{ statusLabel }}
                    </span>

                    <button
                        v-if="status === 'disconnected' || status === 'error'"
                        @click="connect"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 transition-colors"
                    >
                        Reconnect
                    </button>

                    <button
                        v-if="status === 'connected'"
                        @click="disconnect"
                        class="rounded-lg border border-gray-700 px-3 py-1.5 text-xs font-semibold text-gray-300 hover:bg-gray-800 transition-colors"
                    >
                        Disconnect
                    </button>
                </div>
            </div>

            <!-- Terminal container -->
            <div
                ref="termContainer"
                class="flex-1 rounded-xl border border-gray-800 bg-gray-950 overflow-hidden"
                style="min-height: 0;"
            ></div>

        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';

const props = defineProps({
    node:  { type: Object, required: true },
    wsUrl: { type: String, required: true },
});

const termContainer = ref(null);
const status = ref('disconnected'); // connecting | connected | disconnected | error

const statusLabel = computed(() => ({
    connecting:   'Connecting…',
    connected:    'Connected',
    disconnected: 'Disconnected',
    error:        'Error',
}[status.value] ?? 'Unknown'));

let term     = null;
let fitAddon = null;
let ws       = null;
let resizeObs = null;

function initTerminal() {
    if (term) {
        term.dispose();
    }

    term = new Terminal({
        theme: {
            background:  '#030712',
            foreground:  '#e5e7eb',
            cursor:      '#6366f1',
            selectionBackground: '#4f46e520',
            black:       '#111827',
            brightBlack: '#374151',
            red:         '#f87171',
            brightRed:   '#fca5a5',
            green:       '#4ade80',
            brightGreen: '#86efac',
            yellow:      '#facc15',
            brightYellow:'#fde047',
            blue:        '#60a5fa',
            brightBlue:  '#93c5fd',
            magenta:     '#c084fc',
            brightMagenta:'#d8b4fe',
            cyan:        '#22d3ee',
            brightCyan:  '#67e8f9',
            white:       '#d1d5db',
            brightWhite: '#f9fafb',
        },
        fontFamily: '"JetBrains Mono", "Fira Code", "Cascadia Code", ui-monospace, monospace',
        fontSize:   13,
        lineHeight: 1.35,
        cursorBlink: true,
        cursorStyle: 'block',
        scrollback: 5000,
        allowProposedApi: true,
    });

    fitAddon = new FitAddon();
    term.loadAddon(fitAddon);
    term.open(termContainer.value);
    fitAddon.fit();

    // Forward keyboard input to WebSocket
    term.onData((data) => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(new TextEncoder().encode(data));
        }
    });

    // Observe container resize and tell agent
    resizeObs = new ResizeObserver(() => {
        fitAddon.fit();
        sendResize();
    });
    resizeObs.observe(termContainer.value);
}

function sendResize() {
    if (ws && ws.readyState === WebSocket.OPEN && term) {
        ws.send(JSON.stringify({
            type: 'resize',
            cols: term.cols,
            rows: term.rows,
        }));
    }
}

function connect() {
    if (ws) {
        ws.close();
    }

    status.value = 'connecting';
    term?.writeln('\r\x1b[33mConnecting to ' + props.node.name + '…\x1b[0m');

    ws = new WebSocket(props.wsUrl);
    ws.binaryType = 'arraybuffer';

    ws.onopen = () => {
        status.value = 'connected';
        sendResize();
        term?.write('\x1b[2K\r'); // clear the "Connecting…" line
    };

    ws.onmessage = (e) => {
        if (!term) return;
        const data = e.data instanceof ArrayBuffer
            ? new Uint8Array(e.data)
            : e.data;
        term.write(data);
    };

    ws.onerror = () => {
        status.value = 'error';
        term?.writeln('\r\n\x1b[31mWebSocket error — check that the agent is reachable.\x1b[0m');
    };

    ws.onclose = (e) => {
        if (status.value !== 'error') {
            status.value = 'disconnected';
        }
        if (e.code !== 1000) {
            term?.writeln(`\r\n\x1b[33m[connection closed — code ${e.code}]\x1b[0m`);
        }
    };
}

function disconnect() {
    ws?.close(1000, 'user disconnect');
}

onMounted(() => {
    initTerminal();
    connect();
});

onBeforeUnmount(() => {
    resizeObs?.disconnect();
    ws?.close();
    term?.dispose();
});
</script>

<style>
/* xterm.js injects its own canvas — ensure it fills the container */
.xterm {
    height: 100%;
}
.xterm-viewport {
    overflow-y: auto !important;
}
</style>
