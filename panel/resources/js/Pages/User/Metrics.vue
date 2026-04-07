<template>
    <AppLayout title="Metrics">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Metrics"
                title="Account Metrics"
                description="Review account resource usage, hosted domains, and recent web/PHP log activity."
            />

            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Domains" :value="summary.domains" color="indigo" />
                <StatCard label="Databases" :value="summary.databases" color="emerald" />
                <StatCard label="Mailboxes" :value="summary.mailboxes" color="amber" />
                <StatCard label="FTP Accounts" :value="summary.ftp_accounts" color="gray" />
            </div>

            <div class="grid gap-5 xl:grid-cols-2">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="mb-4 text-sm font-semibold text-gray-300">Resource Usage</h3>
                    <div class="space-y-4">
                        <ResourceBar label="Disk" :used="account.disk_used_mb" :limit="account.disk_limit_mb" unit="MB" />
                        <ResourceBar label="Bandwidth" :used="account.bandwidth_used_mb" :limit="account.bandwidth_limit_mb" unit="MB" />
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Account</p>
                            <p class="mt-1 font-mono text-gray-200">{{ account.username }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Package</p>
                            <p class="mt-1 text-gray-200">{{ account.hosting_package?.name ?? 'Custom' }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-300">Recent Traffic</h3>
                            <p class="mt-1 text-xs text-gray-500">Summarized from the latest access-log lines for the selected domain.</p>
                        </div>
                        <button type="button" class="btn-primary" :disabled="trafficLoading || !selectedDomainId" @click="loadTraffic">
                            <span v-if="trafficLoading">Loading...</span>
                            <span v-else>Refresh Traffic</span>
                        </button>
                    </div>

                    <div v-if="trafficError" class="mt-4 rounded-lg border border-rose-800 bg-rose-900/20 px-3 py-2 text-sm text-rose-300">
                        {{ trafficError }}
                    </div>

                    <div v-if="traffic" class="mt-5 space-y-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-lg border border-gray-800 bg-gray-950 p-4">
                                <p class="text-xs uppercase tracking-wide text-gray-500">Requests</p>
                                <p class="mt-2 text-2xl font-semibold text-gray-100">{{ traffic.requests }}</p>
                            </div>
                            <div class="rounded-lg border border-gray-800 bg-gray-950 p-4">
                                <p class="text-xs uppercase tracking-wide text-gray-500">Bandwidth</p>
                                <p class="mt-2 text-2xl font-semibold text-gray-100">{{ traffic.bandwidth_human }}</p>
                            </div>
                        </div>

                        <div class="grid gap-5 lg:grid-cols-2">
                            <div>
                                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Status Codes</h4>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="(count, status) in traffic.status_counts" :key="status" class="rounded-full border border-gray-700 px-3 py-1 text-xs text-gray-300">
                                        {{ status }}: {{ count }}
                                    </span>
                                    <span v-if="Object.keys(traffic.status_counts ?? {}).length === 0" class="text-sm text-gray-500">No parsable status data yet.</span>
                                </div>
                            </div>
                            <div>
                                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Methods</h4>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="(count, method) in traffic.method_counts" :key="method" class="rounded-full border border-gray-700 px-3 py-1 text-xs text-gray-300">
                                        {{ method }}: {{ count }}
                                    </span>
                                    <span v-if="Object.keys(traffic.method_counts ?? {}).length === 0" class="text-sm text-gray-500">No parsable method data yet.</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Top Paths</h4>
                            <div class="space-y-2">
                                <div v-for="path in traffic.top_paths" :key="path.value" class="flex items-center justify-between gap-3 rounded-lg border border-gray-800 bg-gray-950 px-3 py-2">
                                    <span class="truncate font-mono text-xs text-gray-300">{{ path.value }}</span>
                                    <span class="text-xs font-semibold text-gray-500">{{ path.count }}</span>
                                </div>
                                <p v-if="traffic.top_paths.length === 0" class="text-sm text-gray-500">No top-path data available yet.</p>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="!trafficLoading" class="mt-5 rounded-lg border border-dashed border-gray-700 px-4 py-6 text-sm text-gray-500">
                        Select a domain and refresh traffic to summarize recent requests.
                    </div>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="mb-4 text-sm font-semibold text-gray-300">Log Viewer</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-400">Log Type</label>
                            <select v-model="selectedType" class="field w-full">
                                <option v-for="type in logTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-400">Domain</label>
                            <select v-model="selectedDomainId" class="field w-full" :disabled="selectedType === 'php'">
                                <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.domain }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-3">
                        <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60" :disabled="loading" @click="loadLog">
                            <span v-if="loading">Loading...</span>
                            <span v-else>Refresh Log</span>
                        </button>
                        <span v-if="logPath" class="truncate font-mono text-xs text-gray-500">{{ logPath }}</span>
                    </div>

                    <div v-if="error" class="mt-4 rounded-lg border border-rose-800 bg-rose-900/20 px-3 py-2 text-sm text-rose-300">
                        {{ error }}
                    </div>

                    <pre class="mt-4 max-h-[28rem] overflow-auto rounded-lg border border-gray-800 bg-gray-950 p-4 text-xs leading-5 text-gray-300">{{ logContent || 'No log content yet.' }}</pre>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 px-5 py-4">
                    <h3 class="text-sm font-semibold text-gray-200">Hosted Domains</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Domain</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Type</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">SSL</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="domains.length === 0">
                            <td colspan="3" class="px-5 py-8 text-center text-sm text-gray-500">No hosted domains yet.</td>
                        </tr>
                        <tr v-for="domain in domains" :key="domain.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-100">{{ domain.domain }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ domain.type }}</td>
                            <td class="px-5 py-3.5 text-sm">
                                <span v-if="domain.ssl_enabled" class="text-emerald-400">Active</span>
                                <span v-else class="text-gray-500">None</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ResourceBar from '@/Components/ResourceBar.vue';
import StatCard from '@/Components/StatCard.vue';
import axios from 'axios';
import { onMounted, ref } from 'vue';

const props = defineProps({
    account: Object,
    summary: Object,
    domains: Array,
    logTypes: Array,
});

const selectedType = ref(props.logTypes[0]?.value ?? 'access');
const selectedDomainId = ref(props.domains[0]?.id ?? null);
const logContent = ref('');
const logPath = ref('');
const error = ref('');
const loading = ref(false);
const traffic = ref(null);
const trafficError = ref('');
const trafficLoading = ref(false);

async function loadLog() {
    loading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('my.metrics.logs'), {
            params: {
                type: selectedType.value,
                domain_id: selectedType.value === 'php' ? null : selectedDomainId.value,
                lines: 120,
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
    if (!selectedDomainId.value) {
        traffic.value = null;
        trafficError.value = 'Select a domain to load traffic summaries.';
        return;
    }

    trafficLoading.value = true;
    trafficError.value = '';

    try {
        const response = await axios.get(route('my.metrics.traffic'), {
            params: {
                domain_id: selectedDomainId.value,
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

onMounted(() => {
    if (selectedType.value === 'php' || selectedDomainId.value) {
        loadLog();
    }
    if (selectedDomainId.value) {
        loadTraffic();
    }
});
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
