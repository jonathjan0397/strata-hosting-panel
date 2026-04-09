<template>
    <AppLayout :title="`Email - ${domain.domain}`">
        <PageHeader
            eyebrow="Email"
            :title="`Mail for ${domain.domain}`"
            description="Create mailboxes, route forwarders, inspect delivery logs, and review spam activity for this domain."
        >
            <template #actions>
                <Link :href="route('my.domains.show', domain.id)" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">
                    Back to Domain
                </Link>
            </template>
        </PageHeader>

        <div v-if="!domain.mail_enabled" class="mb-6 rounded-xl border border-amber-700 bg-amber-900/20 px-4 py-3 text-sm text-amber-300">
            Mail is not enabled for this domain. Contact your administrator to enable it.
        </div>

        <div v-if="domain.mail_enabled" class="mb-6 grid gap-6 xl:grid-cols-2">
            <section class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Domain Key Manager</p>
                        <h3 class="mt-1 text-sm font-semibold text-gray-200">DKIM signing key</h3>
                        <p class="mt-1 text-sm text-gray-400">Regenerate the OpenDKIM key and publish the TXT record for this domain.</p>
                    </div>
                    <span :class="statusClass(emailDns.dkim.published)">
                        {{ emailDns.dkim.published ? 'Managed DNS published' : 'Needs DNS publish' }}
                    </span>
                </div>
                <p class="mb-4 text-xs" :class="emailDns.managed_dns ? 'text-emerald-300/80' : 'text-amber-200/80'">
                    {{ emailDns.managed_dns ? 'Managed DNS is attached to this domain. Use these values to confirm the zone is publishing what Strata expects.' : 'Managed DNS is not attached. Copy these records into the external DNS provider for this domain.' }}
                </p>

                <DnsValue label="Host" :value="emailDns.dkim.fqdn" />
                <DnsValue label="Type" :value="emailDns.dkim.type" />
                <DnsValue label="Value" :value="emailDns.dkim.value || 'No DKIM key stored yet.'" multiline />

                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800" @click="copy(emailDns.dkim.value)">
                        Copy DKIM
                    </button>
                    <button type="button" :disabled="domainKeyForm.processing" class="btn-primary" @click="regenerateDomainKey">
                        {{ domainKeyForm.processing ? 'Regenerating...' : 'Regenerate Domain Key' }}
                    </button>
                </div>
                <p class="mt-3 text-xs text-gray-500">External DNS users should publish this TXT record at <span class="font-mono text-gray-300">{{ emailDns.dkim.fqdn }}</span>.</p>
            </section>

            <section class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">SPF Manager</p>
                        <h3 class="mt-1 text-sm font-semibold text-gray-200">Sender policy record</h3>
                        <p class="mt-1 text-sm text-gray-400">Edit, validate, or restore the recommended SPF TXT record for this domain.</p>
                    </div>
                    <span :class="statusClass(emailDns.spf.published)">
                        {{ emailDns.spf.published ? 'Managed DNS published' : 'Needs DNS publish' }}
                    </span>
                </div>
                <p class="mb-4 text-xs text-gray-500">Recommended host: <span class="font-mono text-gray-300">{{ emailDns.spf.fqdn }}</span></p>

                <form @submit.prevent="updateSpfRecord" class="space-y-3">
                    <DnsValue label="Host" :value="emailDns.spf.host" />
                    <DnsValue label="Type" :value="emailDns.spf.type" />
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">SPF Value</span>
                        <textarea v-model="spfForm.spf_record" rows="4" class="field w-full font-mono text-xs"></textarea>
                    </label>
                    <p v-if="spfForm.errors.spf_record" class="text-xs text-red-400">{{ spfForm.errors.spf_record }}</p>
                    <p class="text-xs text-gray-500">Recommended: <span class="font-mono text-gray-300">{{ emailDns.spf.recommended }}</span></p>

                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800" @click="copy(spfForm.spf_record)">
                            Copy SPF
                        </button>
                        <button type="button" :disabled="spfRestoreForm.processing" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-60" @click="restoreSpfRecord">
                            {{ spfRestoreForm.processing ? 'Restoring...' : 'Restore Recommended' }}
                        </button>
                        <button type="submit" :disabled="spfForm.processing" class="btn-primary">
                            {{ spfForm.processing ? 'Saving...' : 'Save SPF' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <div v-if="domain.mail_enabled" class="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">DMARC Policy</p>
                    <h3 class="mt-1 text-sm font-semibold text-gray-200">Domain-based reporting and policy</h3>
                    <p class="mt-1 text-sm text-gray-400">Use this record to tell receiving mail servers how to handle messages that fail SPF and DKIM checks.</p>
                </div>
                <span :class="statusClass(emailDns.dmarc.published)">
                    {{ emailDns.dmarc.published ? 'Managed DNS published' : 'Needs DNS publish' }}
                </span>
            </div>

                <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto]">
                    <div>
                        <DnsValue label="Host" :value="emailDns.dmarc.fqdn" />
                        <DnsValue label="Type" :value="emailDns.dmarc.type" />
                        <DnsValue label="Value" :value="emailDns.dmarc.value || 'No DMARC record stored yet.'" multiline />
                    </div>
                    <div class="flex flex-col gap-3 lg:items-end">
                        <button type="button" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800" @click="copy(emailDns.dmarc.value)">
                            Copy DMARC
                        </button>
                        <button type="button" :disabled="dmarcRestoreForm.processing" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-60" @click="restoreDmarcRecord">
                            {{ dmarcRestoreForm.processing ? 'Restoring...' : 'Restore Recommended' }}
                        </button>
                        <Link :href="route('my.troubleshooting.index')" class="text-sm font-semibold text-sky-400 hover:text-sky-300">
                            Open troubleshooting
                        </Link>
                    </div>
                </div>
        </div>

        <form v-if="domain.mail_enabled" @submit.prevent="submitDomainSpamPolicy" class="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Domain Spam Policy</p>
                    <h3 class="mt-1 text-sm font-semibold text-gray-200">Default handling for {{ domain.domain }}</h3>
                    <p class="mt-1 text-sm text-gray-400">This default is used for new mailboxes. You can also apply it to all current mailboxes on this domain.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_auto] lg:min-w-[28rem]">
                    <select v-model="domainSpamPolicyForm.spam_action" class="field">
                        <option v-for="option in spamActionOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <button type="submit" :disabled="domainSpamPolicyForm.processing" class="btn-primary">
                        {{ domainSpamPolicyForm.processing ? 'Updating...' : 'Update Policy' }}
                    </button>
                    <label class="flex items-center gap-2 text-xs text-gray-400 sm:col-span-2">
                        <input v-model="domainSpamPolicyForm.apply_existing" type="checkbox" class="rounded border-gray-700 bg-gray-800 text-indigo-500 focus:ring-indigo-500" />
                        Apply to existing mailboxes
                    </label>
                    <p v-if="domainSpamPolicyForm.errors.spam_action" class="text-xs text-red-400 sm:col-span-2">{{ domainSpamPolicyForm.errors.spam_action }}</p>
                </div>
            </div>
        </form>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Mailboxes" :value="mailboxes.length" color="indigo" />
            <StatCard label="Forwarders" :value="forwarders.length" color="emerald" />
            <ActionCard
                :href="route('my.email.delivery')"
                title="Delivery Tracking"
                description="Search recent Postfix and Dovecot delivery activity."
                cta="Trace mail"
            />
            <ActionCard
                :href="route('my.email.spam')"
                title="Spam Overview"
                description="Review Rspamd scanning and action summaries."
                cta="Open spam tools"
            />
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-300">Mailboxes ({{ mailboxes.length }})</h3>
                </div>

                <div v-if="domain.mail_enabled" class="mb-4 rounded-xl border border-gray-800 bg-gray-900 p-4">
                    <p class="mb-3 text-xs font-medium text-gray-400">New mailbox</p>
                    <form @submit.prevent="submitMailbox" class="space-y-3">
                        <div class="flex gap-2">
                            <input v-model="mbForm.local_part" type="text" placeholder="user" class="field flex-1" />
                            <span class="flex items-center text-sm text-gray-500">@{{ domain.domain }}</span>
                        </div>
                        <p v-if="mbForm.errors.local_part" class="text-xs text-red-400">{{ mbForm.errors.local_part }}</p>
                        <input v-model="mbForm.password" type="password" placeholder="Password (min. 8 chars)" class="field w-full" />
                        <p v-if="mbForm.errors.password" class="text-xs text-red-400">{{ mbForm.errors.password }}</p>
                        <button type="submit" :disabled="mbForm.processing" class="btn-primary w-full">
                            {{ mbForm.processing ? 'Creating...' : 'Create Mailbox' }}
                        </button>
                    </form>
                </div>

                <div v-if="domain.mail_enabled" class="mb-4 rounded-xl border border-dashed border-gray-700 bg-gray-900/60 p-4">
                    <div class="mb-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Bulk mailbox import</p>
                        <p class="mt-1 text-xs text-gray-400">CSV columns: <span class="font-mono text-gray-300">local_part,password,quota_mb</span>. Header row is optional.</p>
                    </div>
                    <form @submit.prevent="submitMailboxImport" class="space-y-3">
                        <textarea
                            v-model="mailboxImportForm.csv"
                            rows="5"
                            class="field w-full font-mono text-xs"
                            placeholder="sales,Use-A-Strong-Password,1024&#10;support,Use-A-Strong-Password,2048"
                        ></textarea>
                        <p v-if="mailboxImportForm.errors.csv" class="text-xs text-red-400">{{ mailboxImportForm.errors.csv }}</p>
                        <button type="submit" :disabled="mailboxImportForm.processing" class="btn-primary w-full">
                            {{ mailboxImportForm.processing ? 'Importing...' : 'Import Mailboxes' }}
                        </button>
                    </form>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                    <table v-if="mailboxes.length" class="min-w-full divide-y divide-gray-800">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Email</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="mb in mailboxes" :key="mb.id" class="hover:bg-gray-800/40">
                                <td class="px-4 py-3 text-sm font-mono text-gray-200">{{ mb.email }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-4">
                                        <a href="/webmail/" class="text-xs text-indigo-400 transition-colors hover:text-indigo-300">Open Webmail</a>
                                        <Link :href="route('my.email.filters.index', mb.id)" class="text-xs text-gray-400 transition-colors hover:text-gray-200">Filters</Link>
                                        <ConfirmButton
                                            :href="route('my.email.mailbox.destroy', mb.id)"
                                            method="delete"
                                            label="Delete"
                                            color="red"
                                            :confirm-message="`Delete mailbox ${mb.email}?`"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <EmptyState
                        v-else
                        title="No mailboxes"
                        description="Create the first mailbox for this domain to start receiving mail."
                    />
                </div>
            </div>

            <div>
                <div class="mb-3">
                    <h3 class="text-sm font-semibold text-gray-300">Forwarders ({{ forwarders.length }})</h3>
                </div>

                <div v-if="domain.mail_enabled" class="mb-4 rounded-xl border border-gray-800 bg-gray-900 p-4">
                    <p class="mb-3 text-xs font-medium text-gray-400">New forwarder</p>
                    <form @submit.prevent="submitForwarder" class="space-y-3">
                        <input v-model="fwdForm.source" type="email" :placeholder="`from@${domain.domain}`" class="field w-full" />
                        <p v-if="fwdForm.errors.source" class="text-xs text-red-400">{{ fwdForm.errors.source }}</p>
                        <input v-model="fwdForm.destination" type="email" placeholder="to@example.com" class="field w-full" />
                        <p v-if="fwdForm.errors.destination" class="text-xs text-red-400">{{ fwdForm.errors.destination }}</p>
                        <button type="submit" :disabled="fwdForm.processing" class="btn-primary w-full">
                            {{ fwdForm.processing ? 'Creating...' : 'Create Forwarder' }}
                        </button>
                    </form>
                </div>

                <div v-if="domain.mail_enabled" class="mb-4 rounded-xl border border-dashed border-gray-700 bg-gray-900/60 p-4">
                    <div class="mb-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Bulk forwarder import</p>
                        <p class="mt-1 text-xs text-gray-400">CSV columns: <span class="font-mono text-gray-300">source,destination</span>. Source can be a local part or full address on this domain.</p>
                    </div>
                    <form @submit.prevent="submitForwarderImport" class="space-y-3">
                        <textarea
                            v-model="forwarderImportForm.csv"
                            rows="5"
                            class="field w-full font-mono text-xs"
                            placeholder="sales,owner@example.com&#10;support@your-domain.com,helpdesk@example.com"
                        ></textarea>
                        <p v-if="forwarderImportForm.errors.csv" class="text-xs text-red-400">{{ forwarderImportForm.errors.csv }}</p>
                        <button type="submit" :disabled="forwarderImportForm.processing" class="btn-primary w-full">
                            {{ forwarderImportForm.processing ? 'Importing...' : 'Import Forwarders' }}
                        </button>
                    </form>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                    <table v-if="forwarders.length" class="min-w-full divide-y divide-gray-800">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Source -> Destination</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="fwd in forwarders" :key="fwd.id" class="hover:bg-gray-800/40">
                                <td class="px-4 py-3 text-sm text-gray-300">
                                    <span class="font-mono text-gray-200">{{ fwd.source }}</span>
                                    <span class="mx-2 text-gray-600">-></span>
                                    <span class="font-mono text-gray-400">{{ fwd.destination }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <ConfirmButton
                                        :href="route('my.email.forwarder.destroy', fwd.id)"
                                        method="delete"
                                        label="Delete"
                                        color="red"
                                        :confirm-message="`Delete forwarder ${fwd.source}?`"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <EmptyState
                        v-else
                        title="No forwarders"
                        description="Forwarders route mail from this domain to another address."
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionCard from '@/Components/ActionCard.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    domain: Object,
    mailboxes: Array,
    forwarders: Array,
    spamActionOptions: Array,
    emailDns: Object,
});

const mbForm = useForm({ local_part: '', password: '' });
const fwdForm = useForm({ source: '', destination: '' });
const mailboxImportForm = useForm({ csv: '' });
const forwarderImportForm = useForm({ csv: '' });
const domainSpamPolicyForm = useForm({
    spam_action: props.domain.mail_spam_action ?? 'inbox',
    apply_existing: false,
});
const domainKeyForm = useForm({});
const spfForm = useForm({
    spf_record: props.emailDns.spf.value ?? props.emailDns.spf.recommended,
});
const spfRestoreForm = useForm({});
const dmarcRestoreForm = useForm({});

function submitMailbox() {
    mbForm.post(route('my.email.mailbox.store', props.domain.id), {
        onSuccess: () => mbForm.reset(),
    });
}

function submitForwarder() {
    fwdForm.post(route('my.email.forwarder.store', props.domain.id), {
        onSuccess: () => fwdForm.reset(),
    });
}

function submitMailboxImport() {
    mailboxImportForm.post(route('my.email.mailbox.import', props.domain.id), {
        onSuccess: () => mailboxImportForm.reset(),
    });
}

function submitForwarderImport() {
    forwarderImportForm.post(route('my.email.forwarder.import', props.domain.id), {
        onSuccess: () => forwarderImportForm.reset(),
    });
}

function submitDomainSpamPolicy() {
    domainSpamPolicyForm.put(route('my.email.domain-spam-policy.update', props.domain.id), {
        preserveScroll: true,
        onSuccess: () => {
            domainSpamPolicyForm.apply_existing = false;
        },
    });
}

function regenerateDomainKey() {
    domainKeyForm.post(route('my.email.domain-key.regenerate', props.domain.id), {
        preserveScroll: true,
        onSuccess: refreshMailDnsState,
    });
}

function updateSpfRecord() {
    spfForm.put(route('my.email.spf.update', props.domain.id), {
        preserveScroll: true,
    });
}

function restoreSpfRecord() {
    spfRestoreForm.post(route('my.email.spf.restore', props.domain.id), {
        preserveScroll: true,
        onSuccess: () => {
            refreshMailDnsState();
        },
    });
}

function restoreDmarcRecord() {
    dmarcRestoreForm.post(route('my.email.dmarc.restore', props.domain.id), {
        preserveScroll: true,
        onSuccess: refreshMailDnsState,
    });
}

function refreshMailDnsState() {
    router.reload({
        only: ['domain', 'emailDns'],
        preserveScroll: true,
    });
}

function statusClass(published) {
    return [
        'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
        published ? 'bg-emerald-900/40 text-emerald-300' : 'bg-amber-900/40 text-amber-300',
    ];
}

function copy(value) {
    if (!value || !navigator.clipboard) {
        return;
    }

    navigator.clipboard.writeText(value);
}
</script>

<script>
export default {
    components: {
        DnsValue: {
            props: {
                label: String,
                value: String,
                multiline: Boolean,
            },
            template: `
                <div class="mb-3">
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ label }}</p>
                    <pre v-if="multiline" class="max-h-40 overflow-auto whitespace-pre-wrap break-all rounded-lg border border-gray-800 bg-gray-950 p-3 text-xs text-gray-300">{{ value }}</pre>
                    <p v-else class="font-mono text-sm text-gray-300">{{ value }}</p>
                </div>
            `,
        },
    },
};
</script>
