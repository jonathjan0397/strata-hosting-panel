<template>
    <AppLayout title="Troubleshooting">
        <div class="mx-auto max-w-6xl space-y-6">
            <PageHeader
                eyebrow="Troubleshooting"
                title="DNS, Mail, and Certificate Diagnostics"
                :description="`Run scoped checks for the domains your ${scopeLabel.toLowerCase()} can control. Review DNS health, mail reachability, TLS certificate status, and DKIM publishing in one place.`"
            />

            <section class="rounded-2xl border border-gray-800 bg-gray-900 p-5">
                <div class="grid gap-4 lg:grid-cols-[minmax(16rem,24rem)_auto] lg:items-end">
                    <label class="block">
                        <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-gray-500">Domain</span>
                        <select v-model="selectedDomainId" class="field w-full">
                            <option value="">Select a domain</option>
                            <option v-for="domain in domains" :key="domain.id" :value="String(domain.id)">
                                {{ domain.domain }}{{ domain.account?.username ? ` (${domain.account.username})` : '' }}
                            </option>
                        </select>
                    </label>
                    <button
                        type="button"
                        class="btn-primary"
                        :disabled="loading || !selectedDomainId"
                        @click="runCheck"
                    >
                        {{ loading ? 'Running checks...' : 'Run Troubleshooter' }}
                    </button>
                </div>

                <p v-if="!domains.length" class="mt-4 text-sm text-amber-300">No domains are available in this scope yet.</p>
                <p v-else class="mt-3 text-xs text-gray-500">
                    This page covers DNS issues, mail server issues, and certificate issues. Managed Let's Encrypt certificates should renew automatically before expiration while DNS and validation paths remain correct.
                </p>
            </section>

            <div v-if="error" class="rounded-xl border border-red-700 bg-red-900/20 px-4 py-3 text-sm text-red-300">
                {{ error }}
            </div>
            <div v-if="actionMessage" class="rounded-xl border border-emerald-700 bg-emerald-900/20 px-4 py-3 text-sm text-emerald-300">
                {{ actionMessage }}
            </div>

            <template v-if="results">
                <section class="grid gap-4 xl:grid-cols-3">
                    <article class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Domain</p>
                        <h2 class="mt-2 text-lg font-semibold text-gray-100">{{ results.domain.domain }}</h2>
                        <p class="mt-2 text-sm text-gray-400">
                            {{ results.domain.mail_enabled ? 'Mail is enabled.' : 'Mail is not enabled.' }}
                            {{ results.domain.managed_dns ? 'Managed DNS is attached.' : 'External DNS or no managed zone.' }}
                        </p>
                    </article>
                    <article class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">DKIM</p>
                        <h3 class="mt-2 text-sm font-semibold text-gray-200">{{ results.email_dns.dkim.fqdn }}</h3>
                        <p class="mt-2 break-all font-mono text-xs text-gray-400">{{ results.email_dns.dkim.value || 'No DKIM key stored yet.' }}</p>
                        <p class="mt-3 text-xs" :class="results.email_dns.dkim.published ? 'text-emerald-400' : 'text-amber-300'">
                            {{ results.email_dns.dkim.published ? 'Managed DNS already includes this DKIM record.' : 'Publish this DKIM TXT record in DNS.' }}
                        </p>
                    </article>
                    <article class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Record Shortcuts</p>
                        <div class="mt-3 space-y-3 text-xs">
                            <div>
                                <p class="font-semibold text-gray-300">SPF</p>
                                <p class="mt-1 break-all font-mono text-gray-400">{{ results.email_dns.spf.value || 'No SPF record saved yet.' }}</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-300">DMARC</p>
                                <p class="mt-1 break-all font-mono text-gray-400">{{ results.email_dns.dmarc.value || 'No DMARC record saved yet.' }}</p>
                            </div>
                        </div>
                    </article>
                </section>

                <section class="rounded-2xl border border-gray-800 bg-gray-900 p-5">
                    <div class="mb-4 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Next Steps</p>
                            <h3 class="mt-1 text-base font-semibold text-gray-100">Open the right screen for this domain</h3>
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <component
                            :is="(action.method ?? 'get').toLowerCase() === 'post' ? 'button' : Link"
                            v-for="action in actionLinks"
                            :key="`${action.method ?? 'get'}:${action.href}`"
                            :href="(action.method ?? 'get').toLowerCase() === 'post' ? undefined : action.href"
                            type="button"
                            class="rounded-xl border border-gray-800 bg-black/20 p-4 text-left transition-colors hover:border-gray-700 hover:bg-black/30"
                            :disabled="actionLoadingKey === action.href"
                            @click="(action.method ?? 'get').toLowerCase() === 'post' ? runAction(action) : null"
                        >
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ action.eyebrow }}</p>
                            <h4 class="mt-2 text-sm font-semibold text-gray-100">{{ action.label }}</h4>
                            <p class="mt-2 text-sm text-gray-400">{{ action.description }}</p>
                        </component>
                    </div>
                </section>

                <section
                    v-for="section in sectionOrder"
                    :key="section.key"
                    class="rounded-2xl border border-gray-800 bg-gray-900 p-5"
                >
                    <div class="mb-4 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ section.label }}</p>
                            <h3 class="mt-1 text-base font-semibold text-gray-100">{{ section.title }}</h3>
                        </div>
                        <div class="text-xs text-gray-500">
                            {{ sectionSummary(results.sections[section.key]) }}
                        </div>
                    </div>

                    <div class="space-y-3">
                        <article
                            v-for="check in results.sections[section.key]"
                            :key="check.key"
                            class="rounded-xl border p-4"
                            :class="statusContainer(check.status)"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex h-2.5 w-2.5 rounded-full" :class="statusDot(check.status)"></span>
                                        <h4 class="text-sm font-semibold text-gray-100">{{ check.label }}</h4>
                                    </div>
                                    <p class="mt-2 text-sm text-gray-300">{{ check.detail }}</p>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-[0.7rem] font-semibold uppercase tracking-wide" :class="statusBadge(check.status)">
                                    {{ check.status }}
                                </span>
                            </div>

                            <div v-if="check.data?.length" class="mt-4 rounded-lg bg-black/20 p-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Details</p>
                                <div class="mt-2 space-y-1">
                                    <p v-for="line in check.data" :key="line" class="break-all font-mono text-xs text-gray-400">{{ line }}</p>
                                </div>
                            </div>

                            <div v-if="check.fix" class="mt-4 rounded-lg border border-amber-700/40 bg-amber-900/10 p-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-amber-300">Recommended Fix</p>
                                <p class="mt-2 whitespace-pre-wrap text-sm text-amber-100/80">{{ check.fix }}</p>
                            </div>

                            <div v-if="check.action" class="mt-4 flex justify-end">
                                <button
                                    type="button"
                                    class="rounded-lg border border-sky-700/60 bg-sky-900/20 px-4 py-2 text-sm font-semibold text-sky-300 transition-colors hover:border-sky-600 hover:bg-sky-900/30"
                                    :disabled="actionLoadingKey === check.action.href"
                                    @click="runAction(check.action)"
                                >
                                    {{ actionLoadingKey === check.action.href ? 'Working...' : check.action.label }}
                                </button>
                            </div>
                        </article>
                    </div>
                </section>
            </template>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    domains: { type: Array, default: () => [] },
    scope: { type: String, required: true },
    scopeLabel: { type: String, default: 'Account' },
});

const selectedDomainId = ref(props.domains[0] ? String(props.domains[0].id) : '');
const loading = ref(false);
const error = ref('');
const results = ref(null);
const actionMessage = ref('');
const actionLoadingKey = ref('');
const page = usePage();

const sectionOrder = [
    { key: 'dns', label: 'Section 1', title: 'DNS Issues' },
    { key: 'mail', label: 'Section 2', title: 'Mail Server Issues' },
    { key: 'certificates', label: 'Section 3', title: 'Certificate Issues' },
];

const checkUrl = computed(() => {
    if (props.scope === 'admin') return route('admin.troubleshooting.check');
    if (props.scope === 'reseller') return route('reseller.troubleshooting.check');

    return route('my.troubleshooting.check');
});

const actionLinks = computed(() => {
    if (!results.value) return [];

    const domainId = results.value.domain.id;
    const links = [];

    if (props.scope === 'admin') {
        links.push(
            {
                eyebrow: 'DNS',
                label: 'Open DNS Zone',
                description: 'Review or publish DNS records for this domain.',
                href: route('admin.dns.show', domainId),
            },
            {
                eyebrow: 'Mail',
                label: 'Open Domain Email',
                description: 'Manage DKIM, SPF, DMARC, mailboxes, and forwarders.',
                href: route('admin.email.domain', domainId),
            },
            {
                eyebrow: 'Delivery',
                label: 'Open Deliverability',
                description: 'Run deeper mail delivery and authentication diagnostics.',
                href: route('admin.deliverability.index'),
            },
            {
                eyebrow: 'Domains',
                label: 'Open Domains List',
                description: 'Review the full domain inventory and hosting state.',
                href: route('admin.domains.index'),
            },
        );

        return links;
    }

    if (props.scope === 'reseller') {
        links.push(
            {
                eyebrow: 'Client',
                label: 'Open Client Details',
                description: 'Review the owning client account and its limits.',
                href: route('reseller.clients.show', results.value.domain.account_id),
            },
            {
                eyebrow: 'Access',
                label: 'Access Client Panel',
                description: 'Impersonate the client to use the same DNS and mail tools they can access.',
                href: route('reseller.accounts.impersonate', results.value.domain.account_id),
                method: 'post',
            },
            {
                eyebrow: 'Reseller',
                label: 'Open Dashboard',
                description: 'Return to the reseller workspace overview.',
                href: route('reseller.dashboard'),
            },
        );

        return links;
    }

    links.push(
        {
            eyebrow: 'DNS',
            label: 'Open DNS Zone',
            description: 'Review records and publish missing DNS entries for this domain.',
            href: route('my.dns.show', domainId),
        },
        {
            eyebrow: 'Mail',
            label: 'Open Domain Email',
            description: 'Manage DKIM, SPF, DMARC, mailboxes, and forwarders.',
            href: route('my.email.domain', domainId),
        },
        {
            eyebrow: 'Delivery',
            label: 'Open Deliverability',
            description: 'Run deeper mail delivery and authentication diagnostics.',
            href: route('my.deliverability.index'),
        },
        {
            eyebrow: 'Domain',
            label: 'Open Domain Details',
            description: 'Review website, SSL, and other hosting settings for this domain.',
            href: route('my.domains.show', domainId),
        },
    );

    return links;
});

async function runCheck() {
    if (!selectedDomainId.value) return;

    loading.value = true;
    error.value = '';
    actionMessage.value = '';
    if (!results.value) {
        results.value = null;
    }

    try {
        const { data } = await axios.post(checkUrl.value, {
            domain_id: Number(selectedDomainId.value),
        });

        results.value = data;
    } catch (err) {
        error.value = err.response?.data?.message ?? 'The troubleshooter could not complete the checks.';
    } finally {
        loading.value = false;
    }
}

function runAction(action) {
    if (!action?.href) return;

    if ((action.method ?? 'get').toLowerCase() === 'post') {
        error.value = '';
        actionMessage.value = '';
        actionLoadingKey.value = action.href;

        router.post(action.href, {}, {
            preserveScroll: true,
            preserveState: true,
            async onSuccess() {
                actionMessage.value = page.props.flash?.success ?? `${action.label} completed.`;
                await runCheck();
            },
            onError(errors) {
                const firstError = Object.values(errors ?? {})[0];
                error.value = Array.isArray(firstError) ? firstError[0] : firstError || `The action "${action.label}" failed.`;
            },
            onFinish() {
                actionLoadingKey.value = '';
            },
        });
        return;
    }

    router.visit(action.href);
}

function sectionSummary(checks = []) {
    const pass = checks.filter((check) => check.status === 'pass').length;
    const warning = checks.filter((check) => check.status === 'warning').length;
    const fail = checks.filter((check) => check.status === 'fail').length;

    return `${pass} pass, ${warning} warning, ${fail} fail`;
}

function statusContainer(status) {
    return {
        'border-emerald-800/60 bg-emerald-950/10': status === 'pass',
        'border-amber-800/60 bg-amber-950/10': status === 'warning',
        'border-red-800/60 bg-red-950/10': status === 'fail',
    };
}

function statusBadge(status) {
    return {
        'bg-emerald-900/40 text-emerald-300': status === 'pass',
        'bg-amber-900/40 text-amber-300': status === 'warning',
        'bg-red-900/40 text-red-300': status === 'fail',
    };
}

function statusDot(status) {
    return {
        'bg-emerald-400': status === 'pass',
        'bg-amber-400': status === 'warning',
        'bg-red-400': status === 'fail',
    };
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
.btn-primary { @apply rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-50; }
</style>
