<template>
    <AppLayout title="Email Accounts">
        <div class="space-y-6">
            <PageHeader
                eyebrow="Email"
                title="Email Accounts"
                description="Manage mailboxes across the domains you can access, and copy the secure client settings users need."
            />

            <section class="rounded-2xl border border-sky-700/50 bg-sky-950/30 p-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-300">Mail Client Configuration Guide</p>
                        <h3 class="mt-1 text-lg font-semibold text-gray-100">Use encrypted connections whenever available</h3>
                        <p class="mt-2 max-w-3xl text-sm text-sky-100/80">
                            Use the full mailbox address as the username. For password, use the mailbox password set in this panel.
                            Prefer SSL/TLS or STARTTLS; the basic ports are shown only for compatibility troubleshooting.
                        </p>
                    </div>
                    <div class="rounded-xl border border-sky-700/50 bg-black/20 px-4 py-3 text-xs text-sky-100">
                        <p class="font-semibold">Server hostname</p>
                        <p class="mt-1 font-mono">{{ primaryMailServer }}</p>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <ConfigCard title="IMAP secure" port="993" security="SSL/TLS" note="Recommended for incoming mail" />
                    <ConfigCard title="POP3 secure" port="995" security="SSL/TLS" note="Use only if clients need POP3" />
                    <ConfigCard title="SMTP submission" port="587" security="STARTTLS" note="Recommended for outgoing mail" />
                    <ConfigCard title="SMTP SSL" port="465" security="SSL/TLS" note="Alternative outgoing mail port" />
                    <ConfigCard title="IMAP basic" port="143" security="Disabled by default" note="Use secure IMAP 993 instead" muted />
                    <ConfigCard title="POP3 basic" port="110" security="Disabled by default" note="Use secure POP3 995 instead" muted />
                    <ConfigCard title="SMTP basic" port="25" security="Server-to-server only" note="Do not use for client submission" muted />
                    <ConfigCard title="SMTP secure" port="465/587" security="Authenticated only" note="Unauthenticated relaying is rejected" muted />
                </div>
            </section>

            <section class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200">Create Mailbox</h3>
                <form class="mt-4 grid gap-3 lg:grid-cols-[minmax(14rem,1fr)_minmax(10rem,1fr)_minmax(12rem,1fr)_8rem_auto]" @submit.prevent="createMailbox">
                    <select v-model="createForm.domain_id" class="field" required>
                        <option value="">Choose domain</option>
                        <option v-for="domain in enabledDomains" :key="domain.id" :value="domain.id">
                            {{ domain.domain }}{{ domain.account?.username ? ` (${domain.account.username})` : '' }}
                        </option>
                    </select>
                    <input v-model="createForm.local_part" class="field" placeholder="local part" required />
                    <input v-model="createForm.password" type="password" class="field" placeholder="password" required />
                    <input v-model.number="createForm.quota_mb" type="number" min="0" class="field" placeholder="quota MB" />
                    <button type="submit" class="btn-primary" :disabled="createForm.processing">
                        {{ createForm.processing ? 'Creating...' : 'Create' }}
                    </button>
                </form>
                <p v-if="enabledDomains.length === 0" class="mt-3 text-sm text-amber-300">No mail-enabled domains are available for this account scope.</p>
            </section>

            <section class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 px-5 py-4">
                    <h3 class="text-sm font-semibold text-gray-200">Mailboxes</h3>
                </div>
                <table v-if="mailboxes.length" class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Email</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Domain</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Account</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Quota</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="mailbox in mailboxes" :key="mailbox.id" class="hover:bg-gray-800/40">
                            <td class="px-5 py-3 text-sm font-mono text-gray-100">{{ mailbox.email }}</td>
                            <td class="px-5 py-3 text-sm text-gray-400">{{ mailbox.domain?.domain }}</td>
                            <td class="px-5 py-3 text-sm text-gray-400">{{ mailbox.account?.username ?? '-' }}</td>
                            <td class="px-5 py-3 text-sm text-gray-400">{{ quotaLabel(mailbox) }}</td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="/webmail/" class="text-xs text-indigo-400 hover:text-indigo-300">Webmail</a>
                                    <button type="button" class="text-xs text-sky-400 hover:text-sky-300" @click="openPassword(mailbox)">Password</button>
                                    <ConfirmButton
                                        :href="route('email-accounts.destroy', mailbox.id)"
                                        method="delete"
                                        label="Delete"
                                        color="red"
                                        :confirm-message="`Delete mailbox ${mailbox.email}?`"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-else
                    title="No mailboxes"
                    description="Create the first mailbox from a mail-enabled domain."
                />
            </section>
        </div>

        <Teleport to="body">
            <div v-if="passwordModal.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="passwordModal.show = false">
                <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                    <h3 class="text-base font-semibold text-gray-100">Change Mailbox Password</h3>
                    <p class="mt-1 font-mono text-sm text-gray-400">{{ passwordModal.email }}</p>
                    <form class="mt-4 space-y-4" @submit.prevent="changePassword">
                        <input v-model="passwordForm.password" type="password" class="field w-full" placeholder="New password" required />
                        <div class="flex justify-end gap-3">
                            <button type="button" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800" @click="passwordModal.show = false">
                                Cancel
                            </button>
                            <button type="submit" class="btn-primary" :disabled="passwordForm.processing">
                                {{ passwordForm.processing ? 'Updating...' : 'Update Password' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { computed, reactive } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    domains: { type: Array, default: () => [] },
    mailboxes: { type: Array, default: () => [] },
    forwarders: { type: Array, default: () => [] },
    role: String,
});

const enabledDomains = computed(() => props.domains.filter((domain) => domain.mail_enabled));
const primaryMailServer = computed(() => enabledDomains.value[0]?.node?.hostname ?? 'mail.your-domain.example');

const createForm = useForm({
    domain_id: '',
    local_part: '',
    password: '',
    quota_mb: 0,
});

const passwordModal = reactive({
    show: false,
    mailboxId: null,
    email: '',
});

const passwordForm = useForm({
    password: '',
});

function createMailbox() {
    createForm.post(route('email-accounts.store'), {
        preserveScroll: true,
        onSuccess: () => createForm.reset('local_part', 'password'),
    });
}

function openPassword(mailbox) {
    passwordModal.show = true;
    passwordModal.mailboxId = mailbox.id;
    passwordModal.email = mailbox.email;
    passwordForm.reset();
}

function changePassword() {
    passwordForm.put(route('email-accounts.password', passwordModal.mailboxId), {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
            passwordModal.show = false;
        },
    });
}

function quotaLabel(mailbox) {
    if (!mailbox.quota_mb || mailbox.quota_mb <= 0) return 'Unlimited';
    return `${mailbox.used_mb ?? 0} / ${mailbox.quota_mb} MB`;
}
</script>

<script>
export default {
    components: {
        ConfigCard: {
            props: {
                title: String,
                port: String,
                security: String,
                note: String,
                muted: Boolean,
            },
            template: `
                <div :class="[
                    'rounded-xl border p-4',
                    muted ? 'border-gray-700 bg-gray-950/40' : 'border-sky-700/60 bg-sky-900/20'
                ]">
                    <p :class="['text-xs font-semibold uppercase tracking-wide', muted ? 'text-gray-500' : 'text-sky-300']">{{ title }}</p>
                    <p class="mt-2 text-2xl font-bold text-gray-100">{{ port }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-300">{{ security }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ note }}</p>
                </div>
            `,
        },
    },
};
</script>
