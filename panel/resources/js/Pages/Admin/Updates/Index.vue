<template>
    <AppLayout title="Updates">
        <div class="space-y-6 p-6">
            <div>
                <h1 class="text-lg font-semibold text-gray-100">Updates</h1>
                <p class="mt-0.5 text-sm text-gray-400">Manage both OS package updates and Strata Hosting Panel upgrades from one place.</p>
            </div>

            <div class="grid gap-6 xl:grid-cols-[1.05fr_1fr]">
                <section class="space-y-4 rounded-2xl border border-gray-800 bg-gray-900/70 p-5 backdrop-blur">
                    <div>
                        <h2 class="text-base font-semibold text-gray-100">Panel Updates</h2>
                        <p class="mt-1 text-sm text-gray-400">Upgrade the primary panel in place using the same fail-safe upgrade utility available over SSH.</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Current Version</div>
                            <div class="mt-1 font-mono text-sm text-gray-100">{{ panel.version || 'dev' }}</div>
                        </div>
                        <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Latest Published Release</div>
                            <div class="mt-1 font-mono text-sm text-gray-100">
                                {{ panel.latest_release?.tag_name || 'Unavailable' }}
                            </div>
                            <div v-if="panel.latest_release?.published_at" class="mt-1 text-xs text-gray-500">
                                Published {{ formatDate(panel.latest_release.published_at) }}
                            </div>
                            <a
                                v-if="panel.latest_release?.html_url"
                                :href="panel.latest_release.html_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-2 inline-flex text-xs text-indigo-300 hover:text-indigo-200"
                            >View release notes</a>
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Upgrade Utility</div>
                            <div class="mt-1 text-sm" :class="panel.upgrade_script ? 'text-emerald-400' : 'text-red-400'">
                                {{ panel.upgrade_script ? 'Installed' : 'Missing' }}
                            </div>
                        </div>
                        <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Upgrade Target</div>
                            <div class="mt-1 text-sm text-gray-100">
                                {{ panelForm.version || panel.latest_release?.tag_name || 'Enter a release tag' }}
                            </div>
                            <div class="mt-1 text-xs text-gray-500">
                                Panel upgrades now default to explicit release tags. Custom branch upgrades are available below only for manual testing.
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-blue-700/30 bg-blue-900/20 p-4 text-sm text-blue-200">
                        Panel upgrades restart services and may briefly interrupt the admin session. Automatic remote node agent upgrades can be turned on or off below.
                    </div>

                    <div v-if="panelMessage" class="rounded-xl border px-4 py-3 text-sm"
                        :class="panelMessage.status === 'error'
                            ? 'border-red-700/40 bg-red-900/20 text-red-300'
                            : 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300'">
                        {{ panelMessage.message }}
                        <div v-if="panelMessage.log_path" class="mt-1 font-mono text-xs text-gray-300">{{ panelMessage.log_path }}</div>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                        <label class="flex items-start gap-3">
                            <input v-model="panelSettings.auto_remote_agents" type="checkbox" class="mt-1 rounded border-gray-600 bg-gray-800 text-indigo-500" />
                            <div>
                                <div class="text-sm font-medium text-gray-100">Automatically upgrade remote node agents with panel upgrades</div>
                                <div class="mt-1 text-xs text-gray-400">
                                    Turn this off if you want panel upgrades to stay manual and run remote node agent upgrades separately only when you choose.
                                </div>
                            </div>
                        </label>
                        <div class="mt-3 flex items-center gap-3">
                            <button
                                @click="savePanelSettings"
                                :disabled="panelSettingsSaving"
                                class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50"
                            >{{ panelSettingsSaving ? 'Saving...' : 'Save Upgrade Preference' }}</button>
                            <span class="text-xs text-gray-500">
                                {{ panelSettings.auto_remote_agents ? 'Remote node agent upgrades will auto-queue during panel upgrades.' : 'Remote node agent upgrades will stay manual.' }}
                            </span>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4 space-y-4">
                        <div>
                            <div class="text-sm font-medium text-gray-100">Release Upgrade</div>
                            <div class="mt-1 text-xs text-gray-400">
                                Upgrade the panel to a specific published release tag. This is the normal release path.
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-[1fr_auto]">
                            <input
                                v-model="panelForm.version"
                                type="text"
                                class="field"
                                :placeholder="panel.latest_release?.tag_name || '1.0.0-BETA-3'"
                            />
                            <button
                                @click="useLatestRelease"
                                type="button"
                                class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors"
                            >Use Latest</button>
                        </div>

                        <div class="flex justify-end">
                            <button
                                @click="startPanelUpgrade"
                                :disabled="panelApplying || !panel.upgrade_script || !resolvedPanelSourceValue"
                                class="btn-primary disabled:opacity-60"
                            >{{ panelApplying ? 'Starting...' : 'Start Panel Upgrade' }}</button>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4 space-y-4">
                        <div>
                            <div class="text-sm font-medium text-gray-100">Advanced Source</div>
                            <div class="mt-1 text-xs text-gray-400">
                                Only use a custom branch when testing unreleased work. Leave this blank for normal release upgrades.
                            </div>
                        </div>
                        <input
                            v-model="panelForm.branch"
                            type="text"
                            class="field"
                            placeholder="main"
                        />
                        <div v-if="panel.available_branches?.length" class="space-y-2">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Available Branches</div>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="branch in panel.available_branches"
                                    :key="branch.name"
                                    type="button"
                                    @click="panelForm.branch = branch.name"
                                    class="rounded-full border border-gray-700 px-3 py-1.5 text-xs text-gray-300 transition-colors hover:bg-gray-800"
                                >{{ branch.name }}</button>
                            </div>
                            <div class="space-y-1">
                                <div
                                    v-for="branch in panel.available_branches"
                                    :key="`${branch.name}-description`"
                                    class="text-xs text-gray-500"
                                >
                                    <span class="font-mono text-gray-300">{{ branch.name }}</span>
                                    <span class="ml-2">{{ branch.description }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-amber-700/30 bg-amber-900/15 p-4 space-y-4">
                        <div>
                            <div class="text-sm font-medium text-amber-200">Rollback Options</div>
                            <div class="mt-1 text-xs text-amber-100/80">
                                To roll back to an older tagged release, enter the previous release tag in the release upgrade field above.
                                To restore the exact files and runtime state captured before a previous upgrade, use a stored rollback backup below.
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-[1fr_auto]">
                            <select v-model="rollbackBackupName" class="field" :disabled="!(panel.rollback_backups || []).length">
                                <option value="">- Select a rollback backup -</option>
                                <option v-for="backup in panel.rollback_backups || []" :key="backup.name" :value="backup.name">
                                    {{ backupLabel(backup) }}
                                </option>
                            </select>
                            <button
                                @click="startBackupRollback"
                                :disabled="panelApplying || !panel.upgrade_script || !rollbackBackupName"
                                class="rounded-lg border border-amber-600/50 px-3 py-2 text-sm text-amber-100 hover:bg-amber-700/20 transition-colors disabled:opacity-50"
                            >Rollback To Backup</button>
                        </div>

                        <div v-if="!(panel.rollback_backups || []).length" class="text-xs text-amber-100/70">
                            No rollback backups were found yet. A backup is created automatically before each panel upgrade.
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div class="text-sm font-medium text-gray-100">Manual Remote Node Agent Upgrade</div>
                                <div class="mt-1 text-xs text-gray-400">Use this when automatic remote agent upgrades are disabled or when you want to upgrade agents separately.</div>
                            </div>
                            <button
                                @click="startRemoteAgentsUpgrade"
                                :disabled="panelApplying"
                                class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50"
                            >Start Remote Agent Upgrade</button>
                        </div>
                    </div>
                </section>

                <section class="space-y-4 rounded-2xl border border-gray-800 bg-gray-900/70 p-5 backdrop-blur">
                    <div>
                        <h2 class="text-base font-semibold text-gray-100">OS Updates</h2>
                        <p class="mt-1 text-sm text-gray-400">Check and apply in-place package upgrades per node. This does not run a dist-upgrade or install new packages.</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <select v-model="selectedNode" @change="checkUpdates" class="field">
                            <option value="">- Select a node -</option>
                            <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }} ({{ n.hostname }})</option>
                        </select>
                        <button
                            v-if="selectedNode"
                            @click="checkUpdates"
                            :disabled="checking"
                            class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50"
                        >{{ checking ? 'Checking...' : 'Refresh' }}</button>
                    </div>

                    <template v-if="selectedNode">
                        <div v-if="loadError" class="rounded-xl border border-red-700/40 bg-red-900/20 px-4 py-2.5 text-sm text-red-300">{{ loadError }}</div>

                        <div v-if="packages !== null" :class="packages.length ? 'border-yellow-700/40 bg-yellow-900/20 text-yellow-300' : 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300'"
                            class="rounded-xl border px-4 py-3 flex items-center justify-between">
                            <span class="text-sm font-medium">
                                {{ packages.length ? `${packages.length} package${packages.length > 1 ? 's' : ''} available` : 'System is up to date.' }}
                            </span>
                            <button
                                v-if="packages.length"
                                @click="applyUpdates"
                                :disabled="applying"
                                class="rounded-lg bg-yellow-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-yellow-500 disabled:opacity-60 transition-colors"
                            >{{ applying ? 'Upgrading...' : 'Apply Updates' }}</button>
                        </div>

                        <div v-if="applyResult" class="rounded-xl border border-gray-700 bg-gray-900 p-4">
                            <p class="text-xs font-semibold mb-2" :class="applyResult.status === 'upgraded' ? 'text-emerald-400' : 'text-red-400'">
                                {{ applyResult.status === 'upgraded' ? 'Upgrade complete.' : 'Upgrade failed.' }}
                            </p>
                            <pre class="text-xs text-gray-400 whitespace-pre-wrap max-h-64 overflow-y-auto">{{ applyResult.output }}</pre>
                        </div>

                        <div v-if="packages && packages.length" class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-800 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        <th class="px-5 py-3">Package</th>
                                        <th class="px-5 py-3">Current</th>
                                        <th class="px-5 py-3">New</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-800">
                                    <tr v-for="pkg in packages" :key="pkg.name" class="hover:bg-gray-800/40">
                                        <td class="px-5 py-3 font-mono text-gray-200">{{ pkg.name }}</td>
                                        <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ pkg.old_version || '-' }}</td>
                                        <td class="px-5 py-3 font-mono text-xs text-emerald-400">{{ pkg.new_version || '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    nodes: { type: Array, default: () => [] },
    panel: { type: Object, default: () => ({}) },
});

const selectedNode = ref('');
const packages = ref(null);
const checking = ref(false);
const applying = ref(false);
const loadError = ref('');
const applyResult = ref(null);
const panelApplying = ref(false);
const panelMessage = ref(null);
const panelSettingsSaving = ref(false);
const panelForm = ref({
    version: props.panel?.default_source_type === 'version' ? (props.panel?.default_source_value || '') : '',
    branch: props.panel?.default_source_type === 'branch' ? (props.panel?.default_source_value || '') : '',
});
const rollbackBackupName = ref(props.panel?.rollback_backups?.[0]?.name || '');
const panelSettings = ref({
    auto_remote_agents: !!props.panel?.auto_remote_agents,
});

function formatDate(value) {
    if (!value) return '';

    return new Date(value).toLocaleString();
}

function useLatestRelease() {
    if (props.panel?.latest_release?.tag_name) {
        panelForm.value.version = props.panel.latest_release.tag_name;
    }
}

function resolvedPanelPayload() {
    const version = panelForm.value.version?.trim();
    const branch = panelForm.value.branch?.trim();

    if (version) {
        return { source_type: 'version', source_value: version };
    }

    if (branch) {
        return { source_type: 'branch', source_value: branch };
    }

    return { source_type: 'version', source_value: '' };
}

const resolvedPanelSourceValue = computed(() => resolvedPanelPayload().source_value);

async function checkUpdates() {
    if (!selectedNode.value) return;
    checking.value = true;
    loadError.value = '';
    applyResult.value = null;
    try {
        const res = await fetch(route('admin.updates.available') + '?node_id=' + selectedNode.value);
        const data = await res.json();
        if (data.error) { loadError.value = data.error; packages.value = []; }
        else { packages.value = data.packages ?? []; }
    } catch (e) {
        loadError.value = 'Failed to check updates.';
    } finally {
        checking.value = false;
    }
}

async function applyUpdates() {
    if (!confirm('Apply all pending updates on this node? The upgrade runs in the background and may take a few minutes.')) return;
    applying.value = true;
    applyResult.value = null;
    try {
        const res = await fetch(route('admin.updates.apply'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ node_id: selectedNode.value }),
        });
        applyResult.value = await res.json();
        if (applyResult.value.status === 'upgraded') await checkUpdates();
    } catch (e) {
        applyResult.value = { status: 'error', output: 'Request failed.' };
    } finally {
        applying.value = false;
    }
}

async function startPanelUpgrade() {
    if (!confirm('Start the Strata panel upgrade now? The panel may be briefly unavailable while services restart.')) return;
    panelApplying.value = true;
    panelMessage.value = null;
    try {
        const payload = resolvedPanelPayload();
        const res = await fetch(route('admin.updates.panel-upgrade'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify(payload),
        });
        panelMessage.value = await res.json();
        if (!res.ok && !panelMessage.value?.message) {
            panelMessage.value = { status: 'error', message: 'Failed to start panel upgrade.' };
        }
    } catch (e) {
        panelMessage.value = { status: 'error', message: 'Failed to start panel upgrade.' };
    } finally {
        panelApplying.value = false;
    }
}

async function savePanelSettings() {
    panelSettingsSaving.value = true;
    panelMessage.value = null;
    try {
        const res = await fetch(route('admin.updates.settings'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify(panelSettings.value),
        });
        panelMessage.value = await res.json();
        if (!res.ok && !panelMessage.value?.message) {
            panelMessage.value = { status: 'error', message: 'Failed to save upgrade preference.' };
        }
    } catch (e) {
        panelMessage.value = { status: 'error', message: 'Failed to save upgrade preference.' };
    } finally {
        panelSettingsSaving.value = false;
    }
}

async function startRemoteAgentsUpgrade() {
    if (!confirm('Start the remote node agent upgrade now?')) return;
    panelApplying.value = true;
    panelMessage.value = null;
    try {
        const payload = resolvedPanelPayload();
        const res = await fetch(route('admin.updates.remote-agents'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify(payload),
        });
        panelMessage.value = await res.json();
        if (!res.ok && !panelMessage.value?.message) {
            panelMessage.value = { status: 'error', message: 'Failed to start remote node agent upgrade.' };
        }
    } catch (e) {
        panelMessage.value = { status: 'error', message: 'Failed to start remote node agent upgrade.' };
    } finally {
        panelApplying.value = false;
    }
}

async function startBackupRollback() {
    if (!rollbackBackupName.value) return;
    if (!confirm(`Roll back the panel to backup "${rollbackBackupName.value}" now? This restores the previously installed release state captured in that backup and may briefly interrupt the panel.`)) return;
    panelApplying.value = true;
    panelMessage.value = null;
    try {
        const res = await fetch(route('admin.updates.panel-rollback-backup'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({ backup_name: rollbackBackupName.value }),
        });
        panelMessage.value = await res.json();
        if (!res.ok && !panelMessage.value?.message) {
            panelMessage.value = { status: 'error', message: 'Failed to start backup rollback.' };
        }
    } catch (e) {
        panelMessage.value = { status: 'error', message: 'Failed to start backup rollback.' };
    } finally {
        panelApplying.value = false;
    }
}

function backupLabel(backup) {
    const parts = [backup.name];
    if (backup.installed_version) {
        parts.push(`installed ${backup.installed_version}`);
    }
    if (backup.created_at) {
        parts.push(backup.created_at);
    }
    return parts.join(' · ');
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
