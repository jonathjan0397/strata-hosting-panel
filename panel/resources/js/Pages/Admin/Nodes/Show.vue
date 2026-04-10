<template>
    <AppLayout :title="`${node.name} — Details`">
        <div class="max-w-2xl">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('admin.nodes.index')" class="text-gray-500 hover:text-gray-300 transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                        </svg>
                    </Link>
                    <h2 class="text-lg font-semibold text-gray-100">{{ node.name }}</h2>
                    <NodeStatusBadge :status="node.status" />
                    <span v-if="node.is_primary" class="rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-400">Primary</span>
                    <span v-if="node.hosts_dns" class="rounded-full bg-cyan-900/40 px-2 py-0.5 text-xs text-cyan-300">DNS</span>
                    <span v-if="versionState.showWarning" class="rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-semibold text-amber-300">!</span>
                    <span v-if="versionState.upgrading" class="rounded-full bg-sky-500/15 px-2 py-0.5 text-xs text-sky-300">Upgrade in progress</span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="versionState.canPushUpdate"
                        type="button"
                        class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-1.5 text-sm font-medium text-amber-200 hover:bg-amber-500/20 transition-colors"
                        :disabled="agentUpgradeForm.processing"
                        @click="pushAgentUpdate"
                    >
                        {{ agentUpgradeForm.processing ? 'Starting agent update...' : 'Push Agent Update' }}
                    </button>
                    <Link
                        :href="route('admin.nodes.shell', node.id)"
                        class="flex items-center gap-1.5 rounded-lg border border-gray-700 bg-gray-900 px-3 py-1.5 text-sm font-medium text-gray-300 hover:bg-gray-800 transition-colors"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6.75 7.5 3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        Shell
                    </Link>
                    <Link
                        :href="route('admin.nodes.status', node.id)"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
                    >
                        Live Status
                    </Link>
                </div>
            </div>

            <!-- Health check result -->
            <div v-if="health" class="mb-5 flex items-center gap-2 rounded-xl border border-emerald-700/50 bg-emerald-900/20 px-4 py-3 text-sm text-emerald-400">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                Agent reachable — {{ health.status }} · {{ health.time }}
            </div>
            <div v-else class="mb-5 flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-4 py-3 text-sm text-red-400">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                Agent unreachable. {{ healthError ?? 'Check that strata-agent is running on this node.' }}
            </div>

            <div
                v-if="versionState.upgrading || versionState.mismatch || versionState.unknown"
                class="mb-5 rounded-xl border px-4 py-3 text-sm"
                :class="versionState.upgrading ? 'border-sky-700/40 bg-sky-900/20 text-sky-200' : 'border-amber-700/40 bg-amber-900/20 text-amber-100'"
            >
                <div class="font-medium">{{ versionState.upgrading ? 'Agent upgrade is in progress.' : 'Agent version attention needed.' }}</div>
                <div class="mt-1">{{ versionState.message }}</div>
                <div v-if="!node.is_primary && versionState.canPushUpdate" class="mt-3">
                    <button
                        type="button"
                        class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-1.5 text-sm font-medium text-amber-100 hover:bg-amber-500/20 transition-colors"
                        :disabled="agentUpgradeForm.processing"
                        @click="pushAgentUpdate"
                    >
                        {{ agentUpgradeForm.processing ? 'Starting agent update...' : 'Push Agent Update' }}
                    </button>
                </div>
            </div>

            <!-- Agent certificate -->
            <div v-if="node.is_primary" class="mb-5 rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-200">Public HTTPS</h3>
                        <p class="mt-1 text-xs text-gray-400">
                            Repairs the panel certificate after install if Let&apos;s Encrypt was not ready yet. When the panel is on a subdomain, this also repairs the apex placeholder certificate.
                        </p>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Panel</p>
                                <p class="mt-1 font-mono text-sm text-gray-200">{{ publicTls?.panel_domain ?? 'Unknown' }}</p>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="publicTlsBadgeClass(publicTls?.panel)">
                                {{ publicTls?.panel?.status ?? 'unknown' }}
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-gray-400">{{ publicTls?.panel?.message ?? 'Certificate status unavailable.' }}</p>
                    </div>

                    <div v-if="publicTls?.apex_domain" class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">Apex Placeholder</p>
                                <p class="mt-1 font-mono text-sm text-gray-200">{{ publicTls.apex_domain }}</p>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="publicTlsBadgeClass(publicTls?.apex)">
                                {{ publicTls?.apex?.status ?? 'unknown' }}
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-gray-400">{{ publicTls?.apex?.message ?? 'Certificate status unavailable.' }}</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="btn-primary"
                        :disabled="publicTlsForm.processing"
                        @click="repairPublicHttps"
                    >
                        {{ publicTlsForm.processing ? 'Starting repair...' : 'Repair Public HTTPS' }}
                    </button>
                    <p class="text-xs text-gray-500">
                        This retries Let&apos;s Encrypt from the primary node using the managed webroots created during install.
                    </p>
                </div>
            </div>

            <div class="mb-5 rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-200">Agent TLS Certificate</h3>
                        <p class="mt-1 text-xs text-gray-400">
                            Used for secure panel-to-agent calls. If this certificate expires or does not match the hostname, remote operations can fail.
                        </p>
                    </div>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="certificateBadgeClass"
                    >
                        {{ certificate?.status ?? 'unknown' }}
                    </span>
                </div>

                <div
                    v-if="certificate?.status !== 'valid'"
                    class="mb-4 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100"
                >
                    {{ certificate?.message ?? 'Certificate status could not be verified.' }}
                </div>

                <dl class="grid gap-3 text-sm md:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Issuer</dt>
                        <dd class="mt-1 break-words text-gray-200">{{ certificate?.issuer ?? 'Unknown' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Expires</dt>
                        <dd class="mt-1 text-gray-200">{{ certificate?.expires_human ?? 'Unknown' }}</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Fingerprint</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-gray-400">{{ certificate?.fingerprint ?? 'Unavailable' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="btn-primary"
                        :disabled="certificateForm.processing"
                        @click="renewCertificate"
                    >
                        {{ certificateForm.processing ? 'Starting renewal...' : 'Renew / Repair Certificate' }}
                    </button>
                    <p class="text-xs text-gray-500">
                        This uses the node secret to start an agent-side Let&apos;s Encrypt renewal. No SSH or CLI is required.
                    </p>
                </div>
            </div>

            <!-- Node details -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 divide-y divide-gray-800 mb-5">
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Hostname</span>
                    <span class="col-span-2 font-mono text-gray-200">{{ node.hostname }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">IP Address</span>
                    <span class="col-span-2 font-mono text-gray-200">{{ node.ip_address }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Agent Port</span>
                    <span class="col-span-2 font-mono text-gray-200">{{ node.port }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Node ID</span>
                    <span class="col-span-2 font-mono text-xs text-gray-400">{{ node.node_id }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Agent Version</span>
                    <span class="col-span-2 flex items-center gap-2" :class="versionState.mismatch || versionState.unknown ? 'text-amber-300' : versionState.upgrading ? 'text-sky-300' : 'text-gray-200'">
                        <span>{{ node.agent_version ?? '—' }}</span>
                        <span v-if="versionState.showWarning" class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-amber-500/15 text-xs font-bold text-amber-300">!</span>
                    </span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Expected Version</span>
                    <span class="col-span-2 text-gray-200">{{ panelVersion || 'Unknown' }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Web Server</span>
                    <span class="col-span-2 font-mono text-gray-200 capitalize">{{ node.web_server ?? 'nginx' }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Accelerators</span>
                    <span class="col-span-2 text-gray-200">
                        <template v-if="node.accelerators?.length">
                            <span v-for="acc in node.accelerators" :key="acc"
                                class="mr-1.5 inline-flex rounded-full bg-gray-800 px-2 py-0.5 text-xs font-mono text-emerald-400">
                                {{ acc }}
                            </span>
                        </template>
                        <span v-else class="text-gray-500">None</span>
                    </span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">DNS Role</span>
                    <span class="col-span-2 text-gray-200">{{ node.hosts_dns ? 'Hosts authoritative DNS' : 'Not used for DNS' }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Last Seen</span>
                    <span class="col-span-2 text-gray-200">{{ node.last_seen_at ?? 'Never' }}</span>
                </div>
            </div>

            <!-- Agent install instructions -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5 mb-5">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Agent Install Command</h3>
                <p class="text-xs text-gray-400 mb-3">Run this on the target Debian server as root. It installs the node service stack plus the agent.</p>
                <pre class="rounded-lg bg-gray-950 px-4 py-3 text-xs font-mono text-emerald-400 overflow-x-auto whitespace-pre-wrap break-all">{{ installCommand }}</pre>
            </div>

            <!-- Danger zone -->
            <div v-if="!node.is_primary" class="rounded-xl border border-red-900/50 bg-red-950/20 p-5">
                <h3 class="text-sm font-semibold text-red-400 mb-3">Remove Node</h3>
                <p class="text-xs text-gray-400 mb-4">Removes this node from the panel. Does not uninstall the agent.</p>
                <ConfirmButton
                    :href="route('admin.nodes.destroy', node.id)"
                    method="delete"
                    label="Remove Node"
                    :confirm-message="`Remove node ${node.name}? This cannot be undone.`"
                    color="red"
                />
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NodeStatusBadge from '@/Components/NodeStatusBadge.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    node:   Object,
    health: Object,
    healthError: String,
    certificate: Object,
    publicTls: Object,
    installSecret: String,
    panelVersion: String,
});

const certificateForm = useForm({});
const publicTlsForm = useForm({});
const agentUpgradeForm = useForm({});

const certificateBadgeClass = computed(() => {
    if (props.certificate?.status === 'valid') {
        return 'bg-emerald-500/15 text-emerald-300';
    }
    if (props.certificate?.status === 'expires_soon') {
        return 'bg-amber-500/15 text-amber-300';
    }
    return 'bg-red-500/15 text-red-300';
});

function renewCertificate() {
    certificateForm.post(route('admin.nodes.certificate.renew', props.node.id), {
        preserveScroll: true,
    });
}

function repairPublicHttps() {
    publicTlsForm.post(route('admin.nodes.public-https.repair', props.node.id), {
        preserveScroll: true,
    });
}

function normalizedVersion(version) {
    const value = (version || '').trim();
    if (!value || value.toLowerCase() === 'dev') return '';
    return value;
}

const versionState = computed(() => {
    const panelVersion = normalizedVersion(props.panelVersion);
    const agentVersion = normalizedVersion(props.node.agent_version);
    const upgrading = props.node.status === 'upgrading';
    const mismatch = !!panelVersion && !!agentVersion && agentVersion !== panelVersion;
    const unknown = !agentVersion;

    return {
        upgrading,
        mismatch,
        unknown,
        showWarning: !upgrading && (mismatch || unknown),
        canPushUpdate: !props.node.is_primary && props.node.status === 'online' && !upgrading && !!panelVersion && agentVersion !== panelVersion,
        message: upgrading
            ? `Expected ${panelVersion || 'the current panel version'} once the node finishes upgrading.`
            : mismatch
                ? `Expected ${panelVersion}, but the node currently reports ${agentVersion}.`
                : 'The node did not report a release version. This usually means the agent binary was built without version metadata.',
    };
});

function pushAgentUpdate() {
    if (!confirm(`Push the current panel agent version to ${props.node.name} now?`)) return;

    agentUpgradeForm.post(route('admin.nodes.agent-upgrade', props.node.id), {
        preserveScroll: true,
        data: {
            source_type: 'version',
            source_value: props.panelVersion,
        },
    });
}

function publicTlsBadgeClass(certificate) {
    if (certificate?.status === 'valid') {
        return 'bg-emerald-500/15 text-emerald-300';
    }
    if (certificate?.status === 'expires_soon') {
        return 'bg-amber-500/15 text-amber-300';
    }
    return 'bg-red-500/15 text-red-300';
}

const installCommand = computed(() => {
    return `STRATA_HMAC_SECRET="${props.installSecret ?? '<secret>'}" \\
STRATA_NODE_ID="${props.node.node_id}" \\
STRATA_NODE_HOSTNAME="${props.node.hostname}" \\
STRATA_WEB_SERVER="${props.node.web_server ?? 'nginx'}" \\
STRATA_PORT="${props.node.port}" \\
bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-hosting-panel/main/installer/agent.sh)`;
});
</script>
