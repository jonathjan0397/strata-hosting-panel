<template>
    <AppLayout title="Database Tools">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Databases"
                title="Database Tools"
                description="Open the right tool for each database and use the saved credentials for that specific database."
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
                            {{ tool.available ? 'Installed' : 'Not installed' }}
                        </span>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-gray-400">{{ tool.login }}</p>
                    <p class="mt-4 break-all font-mono text-xs text-gray-500">{{ tool.url }}</p>
                    <p v-if="!tool.available" class="mt-3 text-xs leading-5 text-amber-200">
                        Ask the server administrator to install and expose {{ tool.name }} on this panel host.
                    </p>
                </a>

                <div class="rounded-xl border border-indigo-800 bg-indigo-950/30 p-5">
                    <h3 class="text-sm font-semibold text-indigo-200">Per-Database Access</h3>
                    <p class="mt-2 text-sm leading-6 text-indigo-100/80">
                        Every database below links to its recommended tool, shows the matching username, and lets you reveal or copy the saved password without leaving this page.
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200">Connection Settings</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Use <span class="font-mono text-gray-300">localhost</span> for local applications on this node. Use the node hostname only for remote clients that have been granted access explicitly.
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

            <div v-if="databases.length" class="grid gap-4 xl:grid-cols-2">
                <section
                    v-for="database in databases"
                    :key="database.id"
                    class="rounded-xl border border-gray-800 bg-gray-900 p-5"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ engineLabel(database.engine) }}</p>
                            <h3 class="mt-1 break-all font-mono text-lg font-semibold text-gray-100">{{ database.db_name }}</h3>
                        </div>
                        <a
                            :href="toolHref(database)"
                            :target="toolAvailable(database.engine) ? '_blank' : undefined"
                            rel="noopener noreferrer"
                            :class="toolAvailable(database.engine) ? 'border-indigo-700 bg-indigo-950 text-indigo-200 hover:bg-indigo-900' : 'border-amber-700 bg-amber-950 text-amber-200 opacity-70'"
                            class="shrink-0 rounded-lg border px-3 py-2 text-xs font-semibold transition-colors"
                        >
                            {{ toolAvailable(database.engine) ? `Open ${database.tool_name}` : `${database.tool_name} unavailable` }}
                        </a>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Username</div>
                            <div class="mt-1 break-all font-mono text-sm text-gray-100">{{ database.db_user }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Host</div>
                            <div class="mt-1 break-all font-mono text-sm text-gray-100">{{ connection.localHost }}</div>
                        </div>
                    </div>

                    <div class="mt-3 rounded-lg border border-gray-800 bg-gray-950 px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Password</div>
                            <div class="flex items-center gap-2">
                                <button
                                    v-if="database.password_available"
                                    type="button"
                                    class="text-xs text-gray-400 transition-colors hover:text-gray-200"
                                    @click="togglePassword(database.id)"
                                >
                                    {{ revealed[database.id] ? 'Hide' : 'Reveal' }}
                                </button>
                                <button
                                    v-if="database.password_available"
                                    type="button"
                                    class="text-xs text-indigo-300 transition-colors hover:text-indigo-200"
                                    @click="copyValue(database.password, `Password copied for ${database.db_name}.`)"
                                >
                                    Copy
                                </button>
                            </div>
                        </div>
                        <div
                            v-if="database.password_available"
                            class="mt-2 break-all font-mono text-sm text-gray-100"
                        >
                            {{ revealed[database.id] ? database.password : maskedPassword(database.password) }}
                        </div>
                        <div v-else class="mt-2 text-sm text-amber-200">
                            This database does not have a saved password yet. Reset it from the Databases page to store a new one for tool access.
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-700 px-3 py-2 text-xs font-semibold text-gray-200 transition-colors hover:bg-gray-800"
                            @click="copyValue(database.db_user, `Username copied for ${database.db_name}.`)"
                        >
                            Copy Username
                        </button>
                        <button
                            v-if="database.password_available"
                            type="button"
                            class="rounded-lg border border-gray-700 px-3 py-2 text-xs font-semibold text-gray-200 transition-colors hover:bg-gray-800"
                            @click="copyValue(database.password, `Password copied for ${database.db_name}.`)"
                        >
                            Copy Password
                        </button>
                        <Link
                            :href="route('my.databases.index')"
                            class="rounded-lg border border-gray-700 px-3 py-2 text-xs font-semibold text-gray-200 transition-colors hover:bg-gray-800"
                        >
                            Reset Password
                        </Link>
                    </div>
                </section>
            </div>

            <EmptyState
                v-else
                title="No databases yet"
                description="Create a database first, then open its matching tool with the saved username and password."
            >
                <template #actions>
                    <Link :href="route('my.databases.index')" class="btn-primary">Create Database</Link>
                </template>
            </EmptyState>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200">Admin Setup Note</h3>
                <p class="mt-2 text-sm leading-6 text-gray-500">
                    The launch targets expect phpMyAdmin at <span class="font-mono text-gray-300">/phpmyadmin/</span> and phpPgAdmin at <span class="font-mono text-gray-300">/phppgadmin/</span> on the panel hostname. Database launch links include the selected database name so users land closer to the right place once authenticated.
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    account: Object,
    connection: Object,
    databases: { type: Array, default: () => [] },
    tools: { type: Array, default: () => [] },
});

const revealed = reactive({});

function engineLabel(engine) {
    return engine === 'postgresql' ? 'PostgreSQL' : 'MySQL / MariaDB';
}

function toolAvailable(engine) {
    return props.tools.find((tool) => tool.engine === engine)?.available ?? false;
}

function toolHref(database) {
    return toolAvailable(database.engine) ? database.tool_url : '#';
}

function maskedPassword(password) {
    return '•'.repeat(Math.max((password ?? '').length, 12));
}

function togglePassword(id) {
    revealed[id] = !revealed[id];
}

async function copyValue(value, message) {
    try {
        await navigator.clipboard.writeText(value ?? '');
        window.alert(message);
    } catch {
        window.alert('Clipboard copy failed. Copy the value manually.');
    }
}
</script>
