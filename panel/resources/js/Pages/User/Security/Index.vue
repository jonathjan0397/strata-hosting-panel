<template>
    <AppLayout title="SSH Keys">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Security"
                title="SSH Keys"
                description="Manage command-line access keys for this hosting account."
            >
                <template #actions>
                    <button @click="showAdd = !showAdd" class="btn-primary">
                        {{ showAdd ? 'Cancel' : 'Add Key' }}
                    </button>
                </template>
            </PageHeader>

            <div class="grid gap-4 md:grid-cols-2">
                <StatCard label="Authorized Keys" :value="keys.length" color="indigo" />
                <StatCard label="Access Type" value="SSH" color="gray" />
            </div>

            <div v-if="$page.props.flash?.success" class="rounded-lg border border-emerald-800 bg-emerald-900/30 px-4 py-3 text-sm text-emerald-400">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.error" class="rounded-lg border border-red-800 bg-red-900/30 px-4 py-3 text-sm text-red-400">
                {{ $page.props.flash.error }}
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-800 px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-gray-200">Authorized Keys</h3>
                    <span class="text-xs text-gray-500">{{ keys.length }} total</span>
                </div>

                <div v-if="showAdd" class="border-b border-gray-800 bg-gray-800/30 px-5 py-4">
                    <form @submit.prevent="submitKey" class="space-y-3">
                        <input v-model="form.name" type="text" placeholder="Key name (e.g. My Laptop)" class="field w-full" required />
                        <textarea v-model="form.public_key" rows="4" placeholder="Paste public key (ssh-rsa AAAA... or ssh-ed25519 AAAA...)" class="field w-full font-mono text-xs" required></textarea>
                        <div class="flex gap-2">
                            <button type="submit" class="btn-primary px-3 py-1.5 text-xs">Add Key</button>
                            <button type="button" @click="showAdd = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                        </div>
                    </form>
                </div>

                <div class="divide-y divide-gray-800">
                    <div v-for="key in keys" :key="key.id" class="flex items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-200">{{ key.name }}</p>
                            <p class="truncate text-xs font-mono text-gray-500">{{ key.fingerprint }}</p>
                        </div>
                        <ConfirmButton
                            :href="route('my.ssh-keys.destroy', key.id)"
                            method="delete"
                            label="Remove"
                            :confirm-message="`Remove key '${key.name}'?`"
                            color="red"
                        />
                    </div>
                    <div v-if="keys.length === 0" class="px-5 py-8">
                        <EmptyState
                            title="No SSH keys added"
                            description="Add a public key when you need command-line access to this hosting account."
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

defineProps({ keys: Array });

const showAdd = ref(false);
const form = ref({ name: '', public_key: '' });

function submitKey() {
    router.post(route('my.ssh-keys.store'), form.value, {
        onSuccess: () => { form.value = { name: '', public_key: '' }; showAdd.value = false; },
    });
}
</script>
