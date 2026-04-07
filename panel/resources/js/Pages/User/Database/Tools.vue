<template>
    <AppLayout title="Database Tools">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Databases"
                title="Database Tools"
                description="Open database administration tools and use the database credentials you created in Strata."
            >
                <template #actions>
                    <Link :href="route('my.databases.index')" class="btn-primary">Manage Databases</Link>
                </template>
            </PageHeader>

            <div class="grid gap-5 lg:grid-cols-3">
                <a
                    v-for="tool in tools"
                    :key="tool.name"
                    :href="tool.available ? tool.url : undefined"
                    :target="tool.available ? '_blank' : undefined"
                    rel="noopener noreferrer"
                    :class="[
                        'rounded-xl border border-gray-800 bg-gray-900 p-5 transition',
                        tool.available ? 'hover:border-indigo-500/60 hover:bg-gray-800/70' : 'cursor-not-allowed opacity-70'
                    ]"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tool.label }}</p>
                            <h3 class="mt-1 text-lg font-semibold text-gray-100">{{ tool.name }}</h3>
                        </div>
                        <span
                            :class="tool.available ? 'border-indigo-700 bg-indigo-950 text-indigo-200' : 'border-amber-700 bg-amber-950 text-amber-200'"
                            class="rounded-full border px-3 py-1 text-xs font-semibold"
                        >
                            {{ tool.available ? 'Open' : 'Not installed' }}
                        </span>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-gray-400">{{ tool.login }}</p>
                    <p class="mt-4 break-all font-mono text-xs text-gray-500">{{ tool.url }}</p>
                    <p v-if="!tool.available" class="mt-3 text-xs leading-5 text-amber-200">
                        Ask the server administrator to install and expose {{ tool.name }} on this panel host.
                        Strata does not proxy credentials or bypass tool authentication.
                    </p>
                </a>

                <div class="rounded-xl border border-amber-800 bg-amber-950/30 p-5">
                    <h3 class="text-sm font-semibold text-amber-200">Credential Policy</h3>
                    <p class="mt-2 text-sm leading-6 text-amber-100/80">
                        Strata does not store or replay database passwords for tool SSO. Sign in with the database username and password you created or rotated from the Databases page.
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200">Connection Settings</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Use local connections for applications hosted on this node. Use the node hostname only when you have granted remote access to that user/host.
                </p>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Local Host</dt>
                        <dd class="mt-1 font-mono text-sm text-gray-200">{{ connection.localHost }}</dd>
                    </div>
                    <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Node Hostname</dt>
                        <dd class="mt-1 break-all font-mono text-sm text-gray-200">{{ connection.host }}</dd>
                    </div>
                    <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">MySQL / MariaDB Port</dt>
                        <dd class="mt-1 font-mono text-sm text-gray-200">3306</dd>
                    </div>
                    <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">PostgreSQL Port</dt>
                        <dd class="mt-1 font-mono text-sm text-gray-200">5432</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 px-5 py-4">
                    <h3 class="text-sm font-semibold text-gray-200">Existing Databases</h3>
                </div>
                <table v-if="databases.length" class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Database</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Engine</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">User</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Recommended Tool</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="database in databases" :key="database.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-100">{{ database.db_name }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ engineLabel(database.engine) }}</td>
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-400">{{ database.db_user }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-300">{{ toolLabel(database.engine) }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-else
                    title="No databases yet"
                    description="Create a database first, then use phpMyAdmin or phpPgAdmin with its database username and password."
                >
                    <template #actions>
                        <Link :href="route('my.databases.index')" class="btn-primary">Create Database</Link>
                    </template>
                </EmptyState>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200">Admin Setup Note</h3>
                <p class="mt-2 text-sm leading-6 text-gray-500">
                    The launch targets expect phpMyAdmin at <span class="font-mono text-gray-300">/phpmyadmin/</span> and phpPgAdmin at <span class="font-mono text-gray-300">/phppgadmin/</span> on the panel hostname. If those tools are not installed, Strata shows them as unavailable instead of sending users to a Laravel/nginx 404.
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    account: Object,
    connection: Object,
    databases: { type: Array, default: () => [] },
    tools: { type: Array, default: () => [] },
});

function engineLabel(engine) {
    return engine === 'postgresql' ? 'PostgreSQL' : 'MySQL / MariaDB';
}

function toolLabel(engine) {
    return engine === 'postgresql' ? 'phpPgAdmin' : 'phpMyAdmin';
}
</script>
