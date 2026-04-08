<template>
    <AppLayout title="My Apps">
        <div class="space-y-6 p-6">
        <PageHeader
            eyebrow="Apps"
            title="My Installed Apps"
            description="Manage installed applications, updates, auto-update state, and removal workflows."
        >
            <template #actions>
                <Link :href="route('my.apps.catalog')" class="btn-primary">Install App</Link>
            </template>
        </PageHeader>



        <div v-if="installations.length === 0"
            class="rounded-xl border border-gray-800 bg-gray-900 px-6 py-16 text-center">
            <p class="text-sm text-gray-500">No apps installed yet.</p>
            <Link :href="route('my.apps.catalog')" class="mt-3 inline-block text-sm text-indigo-400 hover:text-indigo-300">
                Browse the app catalog →
            </Link>
        </div>

        <div v-else class="space-y-3">
            <div v-for="inst in installations" :key="inst.id"
                class="rounded-xl border bg-gray-900 p-5"
                :class="borderClass(inst.status)">

                <div class="flex items-start justify-between gap-4">
                    <!-- App info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-semibold text-gray-100">{{ inst.app_name }}</span>
                            <StatusBadge :status="inst.status" />
                            <span v-if="inst.update_available && inst.status === 'active'"
                                class="rounded-full bg-amber-900/40 px-2 py-0.5 text-xs text-amber-300">
                                Update available
                            </span>
                            <span v-if="inst.migration_verification_required"
                                class="rounded-full bg-amber-900/40 px-2 py-0.5 text-xs font-semibold text-amber-200">
                                Verify after migration
                            </span>
                        </div>

                        <div class="mt-1 flex items-center gap-3 flex-wrap">
                            <a :href="inst.site_url" target="_blank"
                                class="text-xs text-indigo-400 hover:text-indigo-300 font-mono truncate">
                                {{ inst.site_url }}
                            </a>
                            <span v-if="inst.installed_version" class="text-xs text-gray-500">
                                v{{ inst.installed_version }}
                                <span v-if="inst.latest_version && inst.update_available"> → v{{ inst.latest_version }}</span>
                            </span>
                        </div>

                        <!-- Error message -->
                        <p v-if="inst.status === 'error' && inst.error_message"
                            class="mt-2 text-xs text-red-400 font-mono">
                            {{ inst.error_message }}
                        </p>

                        <!-- Assisted install prompt -->
                        <div v-if="inst.status === 'active' && inst.setup_url"
                            class="mt-2 rounded-lg bg-amber-900/20 border border-amber-700/40 px-3 py-2">
                            <p class="text-xs text-amber-300">
                                Complete your installation:
                                <a :href="inst.setup_url" target="_blank" class="underline font-medium">
                                    Open setup wizard →
                                </a>
                                <span class="block mt-1 text-amber-400/70">Remove the installer directory when done.</span>
                            </p>
                        </div>

                        <div v-if="inst.migration_verification_required"
                            class="mt-2 rounded-lg bg-amber-900/20 border border-amber-700/40 px-3 py-2">
                            <p class="text-xs text-amber-200">
                                This app was moved during account migration. Verify the site, database config, and admin login before source cleanup.
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-3 shrink-0">
                        <button v-if="inst.migration_verification_required"
                            @click="verifyMigration(inst)"
                            class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-600 transition-colors">
                            Mark verified
                        </button>

                        <!-- Auto-update toggle -->
                        <button v-if="inst.status === 'active'"
                            @click="toggleAutoUpdate(inst)"
                            class="flex items-center gap-1.5 text-xs transition-colors"
                            :class="inst.auto_update ? 'text-emerald-400 hover:text-emerald-300' : 'text-gray-500 hover:text-gray-400'"
                            :title="inst.auto_update ? 'Auto-update on — click to disable' : 'Auto-update off — click to enable'">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                            Auto
                        </button>

                        <!-- Manual update -->
                        <button v-if="inst.update_available && ['active','error'].includes(inst.status)"
                            @click="updateApp(inst)"
                            class="rounded-lg bg-amber-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600 transition-colors">
                            Update
                        </button>

                        <!-- Delete -->
                        <ConfirmButton
                            :href="route('my.apps.destroy', inst.id)"
                            method="delete"
                            label="Remove"
                            :confirm-message="`Remove ${inst.app_name} from ${inst.site_url}? App files and database will be deleted.`"
                            color="red"
                        />
                    </div>
                </div>

                <!-- Installing/updating progress indicator -->
                <div v-if="['queued','installing','updating'].includes(inst.status)"
                    class="mt-4 flex items-center gap-2 text-xs text-gray-400">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    {{ inst.status === 'queued' ? 'Waiting to start…' : inst.status === 'installing' ? 'Installing — this may take a few minutes…' : 'Updating…' }}
                    <span class="text-gray-600">(refresh to check progress)</span>
                </div>
            </div>
        </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({ installations: Array });

function borderClass(status) {
    const map = {
        active:     'border-gray-800',
        queued:     'border-gray-700',
        installing: 'border-indigo-800',
        updating:   'border-indigo-800',
        error:      'border-red-800/60',
    };
    return map[status] ?? 'border-gray-800';
}

function toggleAutoUpdate(inst) {
    router.patch(route('my.apps.auto-update', inst.id));
}

function updateApp(inst) {
    router.post(route('my.apps.update', inst.id));
}

function verifyMigration(inst) {
    if (!confirm(`Mark ${inst.app_name} as verified after migration? Confirm only after the site and database config are working on the target node.`)) return;
    router.patch(route('my.apps.verify-migration', inst.id));
}

// Status badge component inline
const StatusBadge = {
    props: ['status'],
    template: `<span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="cls">{{ label }}</span>`,
    computed: {
        cls() {
            const map = {
                active:     'bg-emerald-900/40 text-emerald-400',
                queued:     'bg-gray-800 text-gray-500',
                installing: 'bg-indigo-900/40 text-indigo-400',
                updating:   'bg-indigo-900/40 text-indigo-400',
                error:      'bg-red-900/40 text-red-400',
            };
            return map[this.status] ?? 'bg-gray-800 text-gray-500';
        },
        label() {
            const map = { active: 'Active', queued: 'Queued', installing: 'Installing', updating: 'Updating', error: 'Error' };
            return map[this.status] ?? this.status;
        },
    },
};
</script>
