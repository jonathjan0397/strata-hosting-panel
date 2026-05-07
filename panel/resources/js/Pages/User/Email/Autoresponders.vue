<template>
    <AppLayout :title="`Autoresponders - ${domain.domain}`">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Email"
                :title="`Autoresponders for ${domain.domain}`"
                description="Configure mailbox auto-replies for vacations, support confirmations, and temporary out-of-office responses."
            >
                <template #actions>
                    <Link :href="route('my.email.domain', domain.id)" class="text-sm font-medium text-indigo-400 transition-colors hover:text-indigo-300">
                        Back to Mail
                    </Link>
                </template>
            </PageHeader>

            <div class="grid gap-4 md:grid-cols-3">
                <StatCard label="Mailboxes" :value="mailboxes.length" color="indigo" />
                <StatCard label="Active Replies" :value="activeCount" color="emerald" />
                <StatCard label="Inactive" :value="inactiveCount" color="gray" />
            </div>

            <div v-if="$page.props.flash?.success" class="rounded-lg border border-emerald-800 bg-emerald-900/30 px-4 py-3 text-sm text-emerald-400">{{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="rounded-lg border border-red-800 bg-red-900/30 px-4 py-3 text-sm text-red-400">{{ $page.props.flash.error }}</div>

            <div class="space-y-3">
                <div v-for="mailbox in mailboxes" :key="mailbox.id" class="rounded-xl border border-gray-800 bg-gray-900">
                    <div class="flex items-center justify-between border-b border-gray-800 px-5 py-3.5">
                        <div>
                            <p class="text-sm font-medium text-gray-200">{{ mailbox.email }}</p>
                            <p v-if="mailbox.autoresponder" class="mt-0.5 text-xs text-emerald-400">Autoresponder active</p>
                            <p v-else class="mt-0.5 text-xs text-gray-500">No autoresponder</p>
                        </div>
                        <button @click="toggleEdit(mailbox.id)" class="text-xs text-indigo-400 transition-colors hover:text-indigo-300">
                            {{ editing === mailbox.id ? 'Cancel' : (mailbox.autoresponder ? 'Edit' : 'Set up') }}
                        </button>
                    </div>

                    <div v-if="editing === mailbox.id" class="px-5 py-4">
                        <form @submit.prevent="saveAutoresponder(mailbox)" class="space-y-3">
                            <input v-model="forms[mailbox.id].subject" type="text" placeholder="Subject (e.g. Out of Office)" class="field w-full" required />
                            <textarea v-model="forms[mailbox.id].body" rows="4" placeholder="Auto-reply message..." class="field w-full" required></textarea>
                            <div class="flex items-center gap-4">
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-300">
                                    <input type="checkbox" v-model="forms[mailbox.id].active" class="rounded border-gray-600 bg-gray-800 text-indigo-600" />
                                    Active
                                </label>
                                <button type="submit" class="btn-primary px-3 py-1.5 text-xs">Save</button>
                                <ConfirmButton
                                    v-if="mailbox.autoresponder"
                                    :href="route('my.email.autoresponder.destroy', mailbox.id)"
                                    method="delete"
                                    label="Remove"
                                    confirm-message="Remove autoresponder for this mailbox?"
                                    color="red"
                                />
                            </div>
                        </form>
                    </div>
                </div>

                <EmptyState
                    v-if="mailboxes.length === 0"
                    title="No mailboxes on this domain"
                    description="Create a mailbox before configuring an autoresponder."
                >
                    <template #actions>
                        <Link :href="route('my.email.domain', domain.id)" class="btn-primary">Open Mailboxes</Link>
                    </template>
                </EmptyState>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    domain: Object,
    mailboxes: Array,
});

const activeCount = computed(() => props.mailboxes.filter((mailbox) => mailbox.autoresponder?.active).length);
const inactiveCount = computed(() => props.mailboxes.length - activeCount.value);
const editing = ref(null);
const forms = reactive({});

props.mailboxes.forEach((mailbox) => {
    forms[mailbox.id] = {
        subject: mailbox.autoresponder?.subject ?? '',
        body: mailbox.autoresponder?.body ?? '',
        active: mailbox.autoresponder?.active ?? true,
    };
});

function toggleEdit(id) {
    editing.value = editing.value === id ? null : id;
}

function saveAutoresponder(mailbox) {
    router.post(route('my.email.autoresponder.store', mailbox.id), forms[mailbox.id], {
        onSuccess: () => { editing.value = null; },
    });
}
</script>
