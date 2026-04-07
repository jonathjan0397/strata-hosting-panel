<template>
    <AppLayout :title="`${node.name} — Status`">

        <!-- Header row -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <Link :href="route('admin.nodes.index')" class="text-gray-500 hover:text-gray-300 transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                        </svg>
                    </Link>
                    <h2 class="text-lg font-semibold text-gray-100">{{ node.name }}</h2>
                    <NodeStatusBadge :status="nodeStatus" />
                    <span v-if="node.is_primary" class="rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-400">Primary</span>
                </div>
                <p class="mt-1 text-sm text-gray-400 font-mono">{{ node.hostname }} · {{ node.ip_address }}:{{ node.port }}</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-500">Refreshes every 30s</span>
                <button
                    @click="fetchData"
                    :disabled="loading"
                    class="flex items-center gap-1.5 rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-700 disabled:opacity-50 transition-colors"
                >
                    <svg class="h-4 w-4" :class="{ 'animate-spin': loading }" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Refresh
                </button>
            </div>
        </div>

        <!-- Error banner -->
        <div v-if="error" class="mb-5 rounded-xl border border-red-700 bg-red-900/20 px-4 py-3 text-sm text-red-400">
            {{ error }}
        </div>

        <!-- Usage Stats -->
        <div v-if="info" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
            <!-- CPU / Load -->
            <StatCard label="Load Average" :value="`${info.load['1m'].toFixed(2)}`" color="indigo">
                <template #sub>
                    5m: {{ info.load['5m'].toFixed(2) }} · 15m: {{ info.load['15m'].toFixed(2) }}
                    · {{ info.cpu.cores }} core{{ info.cpu.cores !== 1 ? 's' : '' }}
                </template>
            </StatCard>

            <!-- RAM -->
            <StatCard label="Memory" :value="`${info.memory.used_pct.toFixed(1)}%`" :color="info.memory.used_pct > 85 ? 'red' : info.memory.used_pct > 70 ? 'amber' : 'emerald'">
                <template #sub>{{ info.memory.used_mb.toLocaleString() }} / {{ info.memory.total_mb.toLocaleString() }} MB used</template>
            </StatCard>

            <!-- Disk (first mount) -->
            <template v-if="info.disks?.length">
                <StatCard
                    v-for="disk in info.disks.slice(0, 2)"
                    :key="disk.path"
                    :label="`Disk ${disk.path}`"
                    :value="`${disk.used_pct.toFixed(1)}%`"
                    :color="disk.used_pct > 85 ? 'red' : disk.used_pct > 70 ? 'amber' : 'emerald'"
                >
                    <template #sub>{{ disk.used_gb }} / {{ disk.total_gb }} GB used</template>
                </StatCard>
            </template>

            <!-- Uptime -->
            <StatCard label="Uptime" :value="formatUptime(info.uptime_seconds)" color="gray">
                <template #sub>Agent v{{ node.agent_version ?? 'unknown' }}</template>
            </StatCard>
        </div>

        <!-- Skeleton stats while loading -->
        <div v-else-if="loading && !error" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
            <div v-for="i in 4" :key="i" class="rounded-xl border border-gray-800 bg-gray-900 px-5 py-4 animate-pulse">
                <div class="h-3 w-24 bg-gray-800 rounded mb-3"></div>
                <div class="h-8 w-16 bg-gray-800 rounded"></div>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            <!-- Services panel -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-200">Services</h3>
                </div>
                <div v-if="services.length" class="divide-y divide-gray-800">
                    <div
                        v-for="svc in services"
                        :key="svc.name"
                        class="flex items-center justify-between px-5 py-3"
                    >
                        <div class="flex items-center gap-2.5">
                            <span
                                class="h-2 w-2 rounded-full"
                                :class="svc.active ? 'bg-emerald-400' : 'bg-red-500'"
                            ></span>
                            <span class="text-sm font-mono text-gray-200">{{ svc.name }}</span>
                            <span v-if="!svc.enabled" class="text-xs text-gray-500">(disabled)</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <ServiceActionBtn
                                v-if="!svc.active"
                                label="Start"
                                action="start"
                                :service="svc.name"
                                :node-id="node.id"
                                color="emerald"
                                @done="fetchData"
                            />
                            <ServiceActionBtn
                                v-if="svc.active"
                                label="Restart"
                                action="restart"
                                :service="svc.name"
                                :node-id="node.id"
                                color="amber"
                                @done="fetchData"
                            />
                            <ServiceActionBtn
                                v-if="svc.active && canReload(svc.name)"
                                label="Reload"
                                action="reload"
                                :service="svc.name"
                                :node-id="node.id"
                                color="indigo"
                                @done="fetchData"
                            />
                            <ServiceActionBtn
                                v-if="svc.active"
                                label="Stop"
                                action="stop"
                                :service="svc.name"
                                :node-id="node.id"
                                color="red"
                                confirm
                                @done="fetchData"
                            />
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-sm text-gray-500">
                    {{ loading ? 'Loading services…' : 'No service data' }}
                </div>
            </div>

            <!-- Log viewer -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden flex flex-col">
                <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-200">Logs</h3>
                    <div class="flex items-center gap-2">
                        <select
                            v-model="selectedLog"
                            @change="fetchLog"
                            class="rounded-md border border-gray-700 bg-gray-800 px-2.5 py-1 text-xs text-gray-200 focus:border-indigo-500 focus:outline-none"
                        >
                            <option v-for="l in availableLogs" :key="l" :value="l">{{ l }}</option>
                        </select>
                        <select
                            v-model="logLines"
                            @change="fetchLog"
                            class="rounded-md border border-gray-700 bg-gray-800 px-2 py-1 text-xs text-gray-200 focus:border-indigo-500 focus:outline-none"
                        >
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                            <option :value="200">200</option>
                            <option :value="500">500</option>
                        </select>
                        <button
                            @click="fetchLog"
                            :disabled="logLoading"
                            class="text-gray-500 hover:text-gray-300 transition-colors disabled:opacity-40"
                            title="Refresh log"
                        >
                            <svg class="h-4 w-4" :class="{ 'animate-spin': logLoading }" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div
                    ref="logContainer"
                    class="flex-1 overflow-y-auto bg-gray-950 font-mono text-xs text-gray-400 p-4 max-h-96"
                >
                    <template v-if="logEntries.length">
                        <div
                            v-for="(line, i) in logEntries"
                            :key="i"
                            class="leading-5 whitespace-pre-wrap break-all"
                            :class="lineClass(line)"
                        >{{ line }}</div>
                    </template>
                    <div v-else-if="logLoading" class="text-gray-600">Loading…</div>
                    <div v-else class="text-gray-600">No log entries.</div>
                </div>
            </div>
        </div>

    </AppLayout>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import NodeStatusBadge from '@/Components/NodeStatusBadge.vue';
import ServiceActionBtn from '@/Components/ServiceActionBtn.vue';

const props = defineProps({
    node: Object,
});

const info     = ref(null);
const services = ref([]);
const loading  = ref(false);
const error    = ref(null);
const nodeStatus = ref(props.node.status);

const logEntries    = ref([]);
const selectedLog   = ref('nginx');
const logLines      = ref(100);
const logLoading    = ref(false);
const logContainer  = ref(null);

const availableLogs = [
    'nginx', 'nginx-access', 'php8.1-fpm', 'php8.2-fpm', 'php8.3-fpm',
    'postfix', 'dovecot', 'rspamd', 'mysql', 'postgresql', 'syslog', 'auth', 'fail2ban', 'clamav-daemon', 'clamav-freshclam',
];

async function fetchData() {
    loading.value = true;
    error.value = null;
    try {
        const res = await fetch(route('admin.nodes.api.info', props.node.id), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        if (data.error) {
            error.value = data.error;
            nodeStatus.value = 'offline';
        } else {
            info.value     = data.info;
            services.value = data.services;
            nodeStatus.value = 'online';
        }
    } catch (e) {
        error.value = 'Failed to reach panel API';
        nodeStatus.value = 'offline';
    } finally {
        loading.value = false;
    }
}

async function fetchLog() {
    logLoading.value = true;
    try {
        const url = route('admin.nodes.api.logs', {
            node: props.node.id,
            service: selectedLog.value,
        }) + `?lines=${logLines.value}`;
        const res  = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        logEntries.value = data.entries ?? [];
        // scroll to bottom
        setTimeout(() => {
            if (logContainer.value) {
                logContainer.value.scrollTop = logContainer.value.scrollHeight;
            }
        }, 50);
    } catch (e) {
        logEntries.value = ['Error fetching log.'];
    } finally {
        logLoading.value = false;
    }
}

function lineClass(line) {
    const l = line.toLowerCase();
    if (l.includes('error') || l.includes('crit') || l.includes('emerg') || l.includes('alert')) {
        return 'text-red-400';
    }
    if (l.includes('warn')) return 'text-amber-400';
    return '';
}

function canReload(name) {
    return ['nginx', 'apache2', 'php8.1-fpm', 'php8.2-fpm', 'php8.3-fpm', 'postfix'].includes(name);
}

function formatUptime(seconds) {
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (d > 0) return `${d}d ${h}h`;
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m`;
}

let timer;
onMounted(() => {
    fetchData();
    fetchLog();
    timer = setInterval(fetchData, 30_000);
});
onUnmounted(() => clearInterval(timer));
</script>
