<template>
    <AppLayout :title="domain.domain">
        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Link :href="route('admin.accounts.show', domain.account_id)" class="text-gray-500 hover:text-gray-300 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </Link>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold font-mono text-gray-100">{{ domain.domain }}</h2>
                        <span class="rounded-full bg-gray-800 px-2.5 py-0.5 text-xs text-gray-400">{{ domain.type }}</span>
                    </div>
                    <p class="text-sm text-gray-400">{{ domain.account?.username }} · {{ domain.node?.name }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    :href="route('admin.dns.show', domain.id)"
                    class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors"
                >
                    DNS
                </Link>
                <Link
                    :href="route('admin.email.domain', domain.id)"
                    class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors"
                >
                    Email
                </Link>
            </div>
            <ConfirmButton
                :href="route('admin.domains.destroy', domain.id)"
                method="delete"
                label="Delete Domain"
                :confirm-message="`Remove ${domain.domain} and its vhost?`"
                color="red"
            />
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            <!-- Domain info -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Configuration</h3>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500">Document Root</dt>
                        <dd class="mt-1 break-all text-gray-200 font-mono text-xs">{{ domain.document_root }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Web Server</dt>
                        <dd class="text-gray-200 font-mono">{{ effectiveWebServerLabel }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">PHP Version</dt>
                        <dd class="text-gray-200 font-mono">{{ domain.php_version ?? domain.account?.php_version }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">DKIM</dt>
                        <dd :class="domain.dkim_enabled ? 'text-emerald-400' : 'text-gray-500'">
                            {{ domain.dkim_enabled ? 'Enabled' : 'Not configured' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">SPF</dt>
                        <dd :class="domain.spf_enabled ? 'text-emerald-400' : 'text-gray-500'">
                            {{ domain.spf_enabled ? 'Enabled' : 'Not configured' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">DMARC</dt>
                        <dd :class="domain.dmarc_enabled ? 'text-emerald-400' : 'text-gray-500'">
                            {{ domain.dmarc_enabled ? 'Enabled' : 'Not configured' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- SSL -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">SSL Certificate</h3>
                <template v-if="domain.ssl_enabled">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                        <span class="text-sm text-emerald-400 font-medium">Active</span>
                        <span class="text-xs text-gray-500 ml-1">via {{ domain.ssl_provider }}</span>
                    </div>
                    <p v-if="domain.ssl_wildcard" class="mb-4 text-xs text-sky-400">
                        Covers {{ domain.domain }} and *.{{ domain.domain }}
                    </p>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Expires</dt>
                            <dd :class="expiresSoon ? 'text-amber-400' : 'text-gray-200'">
                                {{ domain.ssl_expires_at }}
                                <span v-if="expiresSoon" class="ml-1 text-xs">(renew soon)</span>
                            </dd>
                        </div>
                    </dl>
                </template>
                <template v-else>
                    <p class="text-sm text-gray-400 mb-4">No SSL certificate issued.</p>
                    <form @submit.prevent="issueSSL">
                        <label v-if="canIssueWildcardSsl" class="mb-4 flex items-start gap-3 rounded-lg border border-gray-800 bg-gray-950 px-3 py-3 text-sm text-gray-300">
                            <input v-model="wildcard" type="checkbox" class="mt-1 rounded border-gray-700 bg-gray-800 text-emerald-500" />
                            <span>
                                <span class="block font-semibold text-gray-200">Issue wildcard certificate</span>
                                <span class="mt-1 block text-xs text-gray-500">Use managed DNS validation to cover both {{ domain.domain }} and *.{{ domain.domain }}.</span>
                            </span>
                        </label>
                        <button
                            type="submit"
                            :disabled="issuingSSL"
                            class="rounded-lg bg-emerald-700/30 px-4 py-2 text-sm font-medium text-emerald-400 hover:bg-emerald-700/50 disabled:opacity-50 transition-colors"
                        >
                            <span v-if="issuingSSL">Issuing…</span>
                            <span v-else>{{ wildcard ? "Issue Let's Encrypt Wildcard" : "Issue Let's Encrypt Certificate" }}</span>
                        </button>
                        <p class="mt-2 text-xs text-gray-500">
                            {{ canIssueWildcardSsl
                                ? 'HTTP validation is used by default. Enable wildcard to use managed DNS validation.'
                                : 'DNS must point to this server before issuing.' }}
                        </p>
                    </form>
                </template>
            </div>
        </div>

        <DomainTrafficLogsPanel
            class="mt-6"
            :traffic-history="trafficHistory"
            :traffic-route="route('admin.domains.traffic', domain.id)"
            :logs-route="route('admin.domains.logs', domain.id)"
            :download-route="route('admin.domains.logs.download', domain.id)"
        />
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import DomainTrafficLogsPanel from '@/Components/DomainTrafficLogsPanel.vue';

const props = defineProps({ domain: Object, canIssueWildcardSsl: Boolean, trafficHistory: Object });

const issuingSSL = ref(false);
const wildcard = ref(false);
const effectiveWebServer = computed(() => props.domain.node?.web_server ?? props.domain.web_server ?? 'nginx');
const effectiveWebServerLabel = computed(() => effectiveWebServer.value === 'apache' ? 'apache' : 'nginx');

const expiresSoon = computed(() => {
    if (!props.domain.ssl_expires_at) return false;
    const days = (new Date(props.domain.ssl_expires_at) - Date.now()) / 86400000;
    return days < 14;
});

function issueSSL() {
    issuingSSL.value = true;
    router.post(route('admin.domains.ssl', props.domain.id), { wildcard: wildcard.value }, {
        onFinish: () => { issuingSSL.value = false; },
    });
}
</script>
