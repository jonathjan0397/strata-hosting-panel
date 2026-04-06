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
import { Link, useForm } from '@inertiajs/vue3';
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
});

const mbForm = useForm({ local_part: '', password: '' });
const fwdForm = useForm({ source: '', destination: '' });
const mailboxImportForm = useForm({ csv: '' });
const forwarderImportForm = useForm({ csv: '' });

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
</script>
