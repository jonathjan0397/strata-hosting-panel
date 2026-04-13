<template>
    <div class="space-y-4">
        <CollapsiblePanel
            title="Traffic Overview"
            description="Stored 30-day traffic totals and live access-log summaries for this domain."
            :content-class="'p-4'"
        >
            <div class="grid gap-4 xl:grid-cols-[0.95fr,1.05fr]">
                <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">30-Day Stored Totals</p>
                            <p class="mt-1 text-xs text-gray-500">Daily snapshots aggregated from this domain's access logs.</p>
                        </div>
                        <button type="button" class="rounded-lg border border-gray-700 px-3 py-2 text-xs font-semibold text-gray-300 transition-colors hover:bg-gray-800" @click="downloadTrafficCsv">
                            Export CSV
                        </button>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg border border-gray-800 bg-gray-900/70 p-3">
                            <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">Requests</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-100">{{ trafficHistory.totals.requests.toLocaleString() }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-900/70 p-3">
                            <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">Bandwidth</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-100">{{ trafficHistory.totals.bandwidth_human }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-900/70 p-3">
                            <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">Errors</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-100">{{ trafficHistory.totals.errors.toLocaleString() }}</p>
                        </div>
                    </div>

                    <div class="mt-4 grid items-end gap-1" :style="{ gridTemplateColumns: `repeat(${trafficHistory.days.length || 30}, minmax(0, 1fr))` }">
                        <div
                            v-for="day in trafficHistory.days"
                            :key="day.date"
                            class="group flex min-h-28 flex-col justify-end gap-1"
                            :title="`${day.date}: ${day.requests} requests, ${day.bandwidth_human}`"
                        >
                            <div class="rounded-t bg-indigo-500/70 transition-colors group-hover:bg-indigo-400" :style="{ height: `${barHeight(day.requests)}px` }"></div>
                            <span class="truncate text-center text-[10px] text-gray-600">{{ shortDate(day.date) }}</span>
                        </div>
                    </div>

                    <div v-if="trafficHistory.totals.requests === 0" class="mt-4 rounded-lg border border-dashed border-gray-700 px-4 py-4 text-sm text-gray-500">
                        No stored traffic snapshots yet for this domain.
                    </div>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">Live Access Summary</p>
                            <p class="mt-1 text-xs text-gray-500">Summarized from the most recent access-log lines.</p>
                        </div>
                        <button type="button" class="btn-primary" :disabled="trafficLoading" @click="loadTraffic">
                            <span v-if="trafficLoading">Loading...</span>
                            <span v-else>Refresh Traffic</span>
                        </button>
                    </div>

                    <div v-if="trafficError" class="mt-4 rounded-lg border border-rose-800 bg-rose-900/20 px-3 py-2 text-sm text-rose-300">
                        {{ trafficError }}
                    </div>

                    <div v-if="traffic" class="mt-4 space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-lg border border-gray-800 bg-gray-900/70 p-3">
                                <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">Requests</p>
                                <p class="mt-1 text-2xl font-semibold text-gray-100">{{ traffic.requests.toLocaleString() }}</p>
                            </div>
                            <div class="rounded-lg border border-gray-800 bg-gray-900/70 p-3">
                                <p class="text-[11px] uppercase tracking-[0.18em] text-gray-500">Bandwidth</p>
                                <p class="mt-1 text-2xl font-semibold text-gray-100">{{ traffic.bandwidth_human }}</p>
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Status Codes</h4>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="(count, status) in traffic.status_counts" :key="status" class="rounded-full border border-gray-700 px-3 py-1 text-xs text-gray-300">
                                        {{ status }}: {{ count }}
                                    </span>
                                    <span v-if="Object.keys(traffic.status_counts ?? {}).length === 0" class="text-sm text-gray-500">No parsable status data yet.</span>
                                </div>
                            </div>
                            <div>
                                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Methods</h4>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="(count, method) in traffic.method_counts" :key="method" class="rounded-full border border-gray-700 px-3 py-1 text-xs text-gray-300">
                                        {{ method }}: {{ count }}
                                    </span>
                                    <span v-if="Object.keys(traffic.method_counts ?? {}).length === 0" class="text-sm text-gray-500">No parsable method data yet.</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Top Paths</h4>
                            <div class="space-y-2">
                                <div v-for="path in traffic.top_paths" :key="path.value" class="flex items-center justify-between gap-3 rounded-lg border border-gray-800 bg-gray-900/70 px-3 py-2">
                                    <span class="truncate font-mono text-xs text-gray-300">{{ path.value }}</span>
                                    <span class="text-xs font-semibold text-gray-500">{{ path.count }}</span>
                                </div>
                                <p v-if="traffic.top_paths.length === 0" class="text-sm text-gray-500">No top-path data available yet.</p>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="!trafficLoading" class="mt-4 rounded-lg border border-dashed border-gray-700 px-4 py-5 text-sm text-gray-500">
                        Refresh traffic to summarize recent requests for this domain.
                    </div>
                </div>
            </div>
        </CollapsiblePanel>

        <CollapsiblePanel
            title="Domain Logs"
            description="Access and error logs for this specific domain."
            :default-open="false"
            :content-class="'p-4'"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-400">Log Type</label>
                    <select v-model="selectedType" class="field w-full">
                        <option v-for="type in logTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-400">Lines</label>
                    <select v-model="selectedLines" class="field w-full">
                        <option :value="120">120 lines</option>
                        <option :value="200">200 lines</option>
                        <option :value="300">300 lines</option>
                    </select>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button type="button" class="btn-primary" :disabled="loading" @click="loadLog">
                    <span v-if="loading">Loading...</span>
                    <span v-else>Refresh Log</span>
                </button>
                <button type="button" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 transition-colors hover:bg-gray-800" @click="downloadLog">
                    Download Recent
                </button>
                <span v-if="logPath" class="truncate font-mono text-xs text-gray-500">{{ logPath }}</span>
            </div>

            <div v-if="error" class="mt-4 rounded-lg border border-rose-800 bg-rose-900/20 px-3 py-2 text-sm text-rose-300">
                {{ error }}
            </div>

            <pre class="mt-4 max-h-[28rem] overflow-auto rounded-lg border border-gray-800 bg-gray-950 p-4 text-xs leading-5 text-gray-300">{{ logContent || 'No log content yet.' }}</pre>
        </CollapsiblePanel>
    </div>
</template>

<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import CollapsiblePanel from '@/Components/CollapsiblePanel.vue';

const props = defineProps({
    trafficHistory: {
        type: Object,
        required: true,
    },
    trafficRoute: {
        type: String,
        required: true,
    },
    logsRoute: {
        type: String,
        required: true,
    },
    downloadRoute: {
        type: String,
        required: true,
    },
});

const logTypes = [
    { value: 'access', label: 'Access Log' },
    { value: 'error', label: 'Error Log' },
];

const selectedType = ref('access');
const selectedLines = ref(120);
const logContent = ref('');
const logPath = ref('');
const error = ref('');
const loading = ref(false);
const traffic = ref(null);
const trafficError = ref('');
const trafficLoading = ref(false);
const maxHistoryRequests = computed(() => Math.max(...(props.trafficHistory?.days ?? []).map((day) => day.requests), 1));

async function loadLog() {
    loading.value = true;
    error.value = '';

    try {
        const response = await axios.get(props.logsRoute, {
            params: {
                type: selectedType.value,
                lines: selectedLines.value,
            },
        });

        logContent.value = response.data.content ?? '';
        logPath.value = response.data.path ?? '';
    } catch (err) {
        error.value = err?.response?.data?.error ?? 'Unable to load logs.';
        logContent.value = '';
        logPath.value = '';
    } finally {
        loading.value = false;
    }
}

async function loadTraffic() {
    trafficLoading.value = true;
    trafficError.value = '';

    try {
        const response = await axios.get(props.trafficRoute, {
            params: {
                lines: 300,
            },
        });

        traffic.value = response.data;
    } catch (err) {
        trafficError.value = err?.response?.data?.error ?? 'Unable to load traffic summary.';
        traffic.value = null;
    } finally {
        trafficLoading.value = false;
    }
}

function downloadLog() {
    window.location = `${props.downloadRoute}?type=${encodeURIComponent(selectedType.value)}&lines=${selectedLines.value}`;
}

function downloadTrafficCsv() {
    window.location = props.trafficRoute.endsWith('/traffic')
        ? `${props.trafficRoute}/export`
        : `${props.trafficRoute}?export=1`;
}

function barHeight(requests) {
    if (requests <= 0) return 4;
    return Math.max(8, Math.round((requests / maxHistoryRequests.value) * 96));
}

function shortDate(date) {
    return date.slice(5);
}

onMounted(() => {
    loadTraffic();
    loadLog();
});
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
.btn-primary {
    @apply rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60;
}
</style>
