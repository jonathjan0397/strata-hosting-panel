<template>
  <AppLayout title="Updates">
    <div class="space-y-6 p-6">
      <div>
        <h1 class="text-lg font-semibold text-gray-100">Updates</h1>
        <p class="mt-0.5 text-sm text-gray-400">Manage OS package updates and Strata upgrades from one place.</p>
      </div>

      <div class="grid gap-6 xl:grid-cols-[1.05fr_1fr]">
        <section class="space-y-4 rounded-2xl border border-gray-800 bg-gray-900/70 p-5 backdrop-blur">
          <div>
            <h2 class="text-base font-semibold text-gray-100">Panel Updates</h2>
            <p class="mt-1 text-sm text-gray-400">Upgrade the primary panel in place using the same fail-safe utility available over SSH.</p>
          </div>

          <div class="grid gap-3 md:grid-cols-2">
            <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
              <div class="text-xs uppercase tracking-wide text-gray-500">Current Version</div>
              <div class="mt-1 font-mono text-sm text-gray-100">{{ panel.version || 'dev' }}</div>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
              <div class="text-xs uppercase tracking-wide text-gray-500">Latest Published Release</div>
              <div class="mt-1 font-mono text-sm text-gray-100">{{ panel.latest_release?.tag_name || 'Unavailable' }}</div>
              <div v-if="panel.latest_release?.published_at" class="mt-1 text-xs text-gray-500">Published {{ formatDate(panel.latest_release.published_at) }}</div>
              <a v-if="panel.latest_release?.html_url" :href="panel.latest_release.html_url" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex text-xs text-indigo-300 hover:text-indigo-200">View release notes</a>
            </div>
          </div>

          <div class="grid gap-3 md:grid-cols-2">
            <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
              <div class="text-xs uppercase tracking-wide text-gray-500">Upgrade Utility</div>
              <div class="mt-1 text-sm" :class="panel.upgrade_script ? 'text-emerald-400' : 'text-red-400'">{{ panel.upgrade_script ? 'Installed' : 'Missing' }}</div>
            </div>
            <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
              <div class="text-xs uppercase tracking-wide text-gray-500">Upgrade Target</div>
              <div class="mt-1 text-sm text-gray-100">{{ panelForm.version || panel.latest_release?.tag_name || 'Enter a release tag' }}</div>
              <div class="mt-1 text-xs text-gray-500">Release tags are the normal path. Custom branch upgrades remain below for manual testing only.</div>
            </div>
          </div>

          <div class="rounded-xl border border-blue-700/30 bg-blue-900/20 p-4 text-sm text-blue-200">Panel upgrades restart services and may briefly interrupt the admin session.</div>

          <div v-if="panelMessage" class="rounded-xl border px-4 py-3 text-sm" :class="panelMessage.status === 'error' ? 'border-red-700/40 bg-red-900/20 text-red-300' : 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300'">
            {{ panelMessage.message }}
            <div v-if="panelMessage.log_path" class="mt-1 font-mono text-xs text-gray-300">{{ panelMessage.log_path }}</div>
          </div>

          <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4 space-y-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
              <div>
                <div class="text-sm font-medium text-gray-100">Live Upgrade Activity</div>
                <div class="mt-1 text-xs text-gray-400">Polls active upgrade jobs and tails the most recent log output.</div>
              </div>
              <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 text-xs text-gray-400">
                  <input v-model="autoScrollLogs" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-indigo-500" />
                  Auto-scroll
                </label>
                <button type="button" @click="refreshActivity" :disabled="activityLoading" class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50">{{ activityLoading ? 'Refreshing...' : 'Refresh Activity' }}</button>
              </div>
            </div>

            <div v-if="activityError" class="rounded-xl border border-red-700/40 bg-red-900/20 px-4 py-3 text-sm text-red-300">{{ activityError }}</div>

            <div class="flex flex-wrap gap-2">
              <button v-for="activity in activityEntries" :key="activity.key" type="button" @click="selectedActivityKey = activity.key" class="rounded-full border px-3 py-1.5 text-xs transition-colors" :class="selectedActivityKey === activity.key ? 'border-indigo-500 bg-indigo-500/15 text-indigo-200' : 'border-gray-700 text-gray-300 hover:bg-gray-800'">{{ activity.label }}</button>
            </div>

            <template v-if="currentActivity">
              <div class="grid gap-3 md:grid-cols-[auto_1fr_auto] md:items-center">
                <div class="rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-wide" :class="statusClasses(currentActivity.status)">{{ formatStatus(currentActivity.status) }}</div>
                <div>
                  <div class="flex items-center justify-between gap-3 text-xs text-gray-400">
                    <span>{{ currentActivity.stage || 'Idle' }}</span>
                    <span>{{ currentActivity.progress }}%</span>
                  </div>
                  <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-800">
                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 via-sky-500 to-cyan-400 transition-all duration-500" :style="{ width: `${currentActivity.progress}%` }" />
                  </div>
                </div>
                <div class="text-right text-xs text-gray-500">
                  <div v-if="currentActivity.last_modified_at">Updated {{ formatDate(currentActivity.last_modified_at) }}</div>
                  <div v-if="currentActivity.process_count">{{ currentActivity.process_count }} process{{ currentActivity.process_count > 1 ? 'es' : '' }} active</div>
                </div>
              </div>

              <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-xl border border-gray-800 bg-gray-900/70 p-3">
                  <div class="text-xs uppercase tracking-wide text-gray-500">Log Path</div>
                  <div class="mt-1 break-all font-mono text-xs text-gray-300">{{ currentActivity.log_path }}</div>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900/70 p-3">
                  <div class="text-xs uppercase tracking-wide text-gray-500">Latest Line</div>
                  <div class="mt-1 text-xs text-gray-300">{{ currentActivity.last_line || 'No log output yet.' }}</div>
                </div>
              </div>

              <div class="rounded-xl border border-gray-800 bg-[#08111f]">
                <div class="flex items-center justify-between border-b border-gray-800 px-4 py-3">
                  <div class="text-xs uppercase tracking-wide text-gray-500">{{ currentActivity.label }} Log</div>
                  <div class="flex items-center gap-3">
                    <div class="text-xs text-gray-500">{{ currentActivity.lines?.length || 0 }} lines shown</div>
                    <button type="button" @click="openLogPopup" class="rounded-lg border border-gray-700 px-2.5 py-1.5 text-xs text-gray-300 hover:bg-gray-800 transition-colors">Open Pop-up</button>
                    <button type="button" @click="exportCurrentLog" class="rounded-lg border border-gray-700 px-2.5 py-1.5 text-xs text-gray-300 hover:bg-gray-800 transition-colors">Export Log</button>
                  </div>
                </div>
                <pre ref="logScroller" class="max-h-96 overflow-y-auto px-4 py-4 font-mono text-xs leading-6 text-sky-100 whitespace-pre-wrap">{{ currentActivity.lines?.length ? currentActivity.lines.join('\n') : 'No log output yet.' }}</pre>
              </div>
            </template>
          </div>

          <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
            <label class="flex items-start gap-3">
              <input v-model="panelSettings.auto_remote_agents" type="checkbox" class="mt-1 rounded border-gray-600 bg-gray-800 text-indigo-500" />
              <div>
                <div class="text-sm font-medium text-gray-100">Automatically upgrade remote node agents with panel upgrades</div>
                <div class="mt-1 text-xs text-gray-400">Turn this off if you want panel upgrades to stay manual and run remote node agent upgrades separately.</div>
              </div>
            </label>
            <div class="mt-3 flex items-center gap-3">
              <button @click="savePanelSettings" :disabled="panelSettingsSaving" class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50">{{ panelSettingsSaving ? 'Saving...' : 'Save Upgrade Preference' }}</button>
              <span class="text-xs text-gray-500">{{ panelSettings.auto_remote_agents ? 'Remote node agent upgrades will auto-queue during panel upgrades.' : 'Remote node agent upgrades will stay manual.' }}</span>
            </div>
          </div>

          <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4 space-y-4">
            <div>
              <div class="text-sm font-medium text-gray-100">Release Upgrade</div>
              <div class="mt-1 text-xs text-gray-400">Upgrade the panel to a specific published release tag. This is the normal release path.</div>
            </div>
            <div class="grid gap-3 md:grid-cols-[1fr_auto]">
              <input v-model="panelForm.version" type="text" class="field" :placeholder="panel.latest_release?.tag_name || '1.0.0-BETA-3'" />
              <button @click="useLatestRelease" type="button" class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors">Use Latest</button>
            </div>
            <div class="flex justify-end">
              <button @click="startPanelUpgrade" :disabled="panelApplying || !panel.upgrade_script || !resolvedPanelSourceValue" class="btn-primary disabled:opacity-60">{{ panelApplying ? 'Starting...' : 'Start Panel Upgrade' }}</button>
            </div>
          </div>

          <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4 space-y-4">
            <div>
              <div class="text-sm font-medium text-gray-100">Advanced Source</div>
              <div class="mt-1 text-xs text-gray-400">Only use a custom branch when testing unreleased work. Leave this blank for normal release upgrades.</div>
            </div>
            <input v-model="panelForm.branch" type="text" class="field" placeholder="main" />
            <div v-if="panel.available_branches?.length" class="space-y-2">
              <div class="text-xs uppercase tracking-wide text-gray-500">Available Branches</div>
              <div class="flex flex-wrap gap-2">
                <button v-for="branch in panel.available_branches" :key="branch.name" type="button" @click="panelForm.branch = branch.name" class="rounded-full border border-gray-700 px-3 py-1.5 text-xs text-gray-300 transition-colors hover:bg-gray-800">{{ branch.name }}</button>
              </div>
              <div class="space-y-1">
                <div v-for="branch in panel.available_branches" :key="`${branch.name}-description`" class="text-xs text-gray-500">
                  <span class="font-mono text-gray-300">{{ branch.name }}</span>
                  <span class="ml-2">{{ branch.description }}</span>
                </div>
              </div>
            </div>
          </div>

          <div class="rounded-xl border border-amber-700/30 bg-amber-900/15 p-4 space-y-4">
            <div>
              <div class="text-sm font-medium text-amber-200">Rollback Options</div>
              <div class="mt-1 text-xs text-amber-100/80">Use a previous release tag above for tagged rollbacks, or restore the exact pre-upgrade backup below.</div>
            </div>
            <div class="grid gap-3 md:grid-cols-[1fr_auto]">
              <select v-model="rollbackBackupName" class="field" :disabled="!(panel.rollback_backups || []).length">
                <option value="">- Select a rollback backup -</option>
                <option v-for="backup in panel.rollback_backups || []" :key="backup.name" :value="backup.name">{{ backupLabel(backup) }}</option>
              </select>
              <button @click="startBackupRollback" :disabled="panelApplying || !panel.upgrade_script || !rollbackBackupName" class="rounded-lg border border-amber-600/50 px-3 py-2 text-sm text-amber-100 hover:bg-amber-700/20 transition-colors disabled:opacity-50">Rollback To Backup</button>
            </div>
            <div v-if="!(panel.rollback_backups || []).length" class="text-xs text-amber-100/70">No rollback backups were found yet. A backup is created automatically before each panel upgrade.</div>
          </div>

          <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
              <div>
                <div class="text-sm font-medium text-gray-100">Manual Remote Node Agent Upgrade</div>
                <div class="mt-1 text-xs text-gray-400">Use this when automatic remote agent upgrades are disabled or when you want to upgrade agents separately.</div>
              </div>
              <button @click="startRemoteAgentsUpgrade" :disabled="panelApplying" class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50">Start Remote Agent Upgrade</button>
            </div>
          </div>

          <div class="rounded-xl border border-gray-800 bg-gray-950/60 p-4 space-y-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
              <div>
                <div class="text-sm font-medium text-gray-100">Storage Migration</div>
                <div class="mt-1 text-xs text-gray-400">Migrate one storage item at a time onto a new root. The selected item can be launched from here or via the generated SSH command during a maintenance window.</div>
              </div>
              <div class="rounded-lg border px-3 py-2 text-xs" :class="storageMigration.available ? 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300' : 'border-red-700/40 bg-red-900/20 text-red-300'">
                {{ storageMigration.available ? 'Utility installed on primary server' : 'Utility not installed on this server yet' }}
              </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
              <div class="rounded-xl border border-gray-800 bg-gray-900/70 p-3">
                <div class="text-xs uppercase tracking-wide text-gray-500">Migration Command Path</div>
                <div class="mt-1 break-all font-mono text-xs text-gray-300">{{ storageMigration.migrate_bin || '/usr/sbin/strata-storage-migrate' }}</div>
              </div>
              <div class="rounded-xl border border-gray-800 bg-gray-900/70 p-3">
                <div class="text-xs uppercase tracking-wide text-gray-500">Rollback Command Path</div>
                <div class="mt-1 break-all font-mono text-xs text-gray-300">{{ storageMigration.rollback_bin || '/usr/sbin/strata-storage-migrate-rollback' }}</div>
              </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900/70 overflow-hidden">
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b border-gray-800 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <th class="px-4 py-3">Select</th>
                    <th class="px-4 py-3">Path</th>
                    <th class="px-4 py-3">Runtime Mount</th>
                    <th class="px-4 py-3">Current Root</th>
                    <th class="px-4 py-3">New Root</th>
                    <th class="px-4 py-3">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                  <tr v-for="root in storageMigration.current_roots || []" :key="root.key">
                    <td class="px-4 py-3">
                      <input v-model="selectedStorageKey" :value="root.key" type="radio" class="border-gray-600 bg-gray-800 text-sky-500" />
                    </td>
                    <td class="px-4 py-3 text-gray-200">{{ root.label }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-400">{{ root.runtime_path }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-400">{{ root.current_root }}</td>
                    <td class="px-4 py-3">
                      <input v-model="storageForm[root.key]" type="text" class="field w-full font-mono text-xs" />
                    </td>
                    <td class="px-4 py-3">
                      <span class="rounded-full border px-2.5 py-1 text-[11px]" :class="storageItemStatusClasses(root.key)">{{ storageItemStatusLabel(root.key) }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="rounded-xl border border-blue-700/30 bg-blue-900/20 p-4 text-xs text-blue-100">
              The migration utility stops services, performs a final rsync, swaps bind mounts, and writes a rollback env file under <span class="font-mono text-blue-50">/root/strata-storage-migration-YYYYMMDD-HHMMSS.env</span>.
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900/70 p-3 text-xs text-gray-300">
              <div class="uppercase tracking-wide text-gray-500">Selected Item</div>
              <div class="mt-1 font-mono text-sm text-gray-100">{{ selectedStorageRoot?.label || 'None selected' }}</div>
              <div v-if="selectedStorageRoot" class="mt-1 text-gray-400">Target: <span class="font-mono">{{ storageForm[selectedStorageRoot.key] }}</span></div>
            </div>

            <div class="flex justify-end">
              <button @click="startStorageMigration" :disabled="panelApplying || !storageMigration.available || !selectedStorageRoot" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500 disabled:opacity-60 transition-colors">
                {{ panelApplying ? 'Starting...' : `Start ${selectedStorageRoot?.label || 'Storage'} Migration` }}
              </button>
            </div>

            <div class="rounded-xl border border-gray-800 bg-[#08111f]">
              <div class="flex items-center justify-between border-b border-gray-800 px-4 py-3">
                <div class="text-xs uppercase tracking-wide text-gray-500">Primary Server Migration Command</div>
                <button type="button" @click="copyText(storageCommand)" class="rounded-lg border border-gray-700 px-2.5 py-1.5 text-xs text-gray-300 hover:bg-gray-800 transition-colors">Copy Command</button>
              </div>
              <pre class="overflow-x-auto px-4 py-4 font-mono text-xs leading-6 text-sky-100 whitespace-pre-wrap">{{ storageCommand }}</pre>
            </div>

            <div class="rounded-xl border border-gray-800 bg-[#08111f]">
              <div class="flex items-center justify-between border-b border-gray-800 px-4 py-3">
                <div class="text-xs uppercase tracking-wide text-gray-500">Rollback Command</div>
                <button type="button" @click="copyText(storageRollbackCommand)" class="rounded-lg border border-gray-700 px-2.5 py-1.5 text-xs text-gray-300 hover:bg-gray-800 transition-colors">Copy Rollback</button>
              </div>
              <pre class="overflow-x-auto px-4 py-4 font-mono text-xs leading-6 text-sky-100 whitespace-pre-wrap">{{ storageRollbackCommand }}</pre>
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
            <button v-if="selectedNode" @click="checkUpdates" :disabled="checking" class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors disabled:opacity-50">{{ checking ? 'Checking...' : 'Refresh' }}</button>
          </div>

          <template v-if="selectedNode">
            <div v-if="loadError" class="rounded-xl border border-red-700/40 bg-red-900/20 px-4 py-2.5 text-sm text-red-300">{{ loadError }}</div>
            <div v-if="packages !== null" class="rounded-xl border px-4 py-3 flex items-center justify-between" :class="packages.length ? 'border-yellow-700/40 bg-yellow-900/20 text-yellow-300' : 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300'">
              <span class="text-sm font-medium">{{ packages.length ? `${packages.length} package${packages.length > 1 ? 's' : ''} available` : 'System is up to date.' }}</span>
              <button v-if="packages.length" @click="applyUpdates" :disabled="applying" class="rounded-lg bg-yellow-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-yellow-500 disabled:opacity-60 transition-colors">{{ applying ? 'Upgrading...' : 'Apply Updates' }}</button>
            </div>

            <div v-if="applyResult" class="rounded-xl border border-gray-700 bg-gray-900 p-4">
              <p class="mb-2 text-xs font-semibold" :class="applyResult.status === 'upgraded' ? 'text-emerald-400' : 'text-red-400'">{{ applyResult.status === 'upgraded' ? 'Upgrade complete.' : 'Upgrade failed.' }}</p>
              <pre class="max-h-64 overflow-y-auto whitespace-pre-wrap text-xs text-gray-400">{{ applyResult.output }}</pre>
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
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ nodes: { type: Array, default: () => [] }, panel: { type: Object, default: () => ({}) } });
const selectedNode = ref(''); const packages = ref(null); const checking = ref(false); const applying = ref(false);
const loadError = ref(''); const applyResult = ref(null); const panelApplying = ref(false); const panelMessage = ref(null);
const panelSettingsSaving = ref(false); const panelActivity = ref(props.panel?.activity || { current_log_key: 'panel_upgrade', activities: [] });
const selectedActivityKey = ref(props.panel?.activity?.current_log_key || 'panel_upgrade'); const activityLoading = ref(false);
const activityError = ref(''); const autoScrollLogs = ref(true); const logScroller = ref(null); let activityPollHandle = null;
const panelForm = ref({ version: props.panel?.default_source_type === 'version' ? (props.panel?.default_source_value || '') : '', branch: props.panel?.default_source_type === 'branch' ? (props.panel?.default_source_value || '') : '' });
const rollbackBackupName = ref(props.panel?.rollback_backups?.[0]?.name || ''); const panelSettings = ref({ auto_remote_agents: !!props.panel?.auto_remote_agents });
const storageMigration = computed(() => props.panel?.storage_migration || { current_roots: [] });
const storageForm = ref(buildStorageForm(storageMigration.value.current_roots || []));
const resolvedPanelSourceValue = computed(() => resolvedPanelPayload().source_value);
const activityEntries = computed(() => panelActivity.value?.activities || []);
const currentActivity = computed(() => activityEntries.value.find((entry) => entry.key === selectedActivityKey.value) || activityEntries.value[0] || null);
const selectedStorageKey = ref(storageMigration.value.current_roots?.[0]?.key || null);
const storageMigrationActivity = computed(() => activityEntries.value.find((entry) => entry.key === 'storage_migration') || null);
const selectedStorageRoot = computed(() => (storageMigration.value.current_roots || []).find((root) => root.key === selectedStorageKey.value) || null);
const storageCommand = computed(() => buildStorageCommand());
const storageRollbackCommand = computed(() => `${storageMigration.value.rollback_bin || '/usr/sbin/strata-storage-migrate-rollback'} /root/strata-storage-migration-YYYYMMDD-HHMMSS.env`);

function formatDate(value) { return value ? new Date(value).toLocaleString() : ''; }
function suggestedStorageRoot(root) { if (!root?.runtime_path) return root?.current_root || ''; if (root.current_root && root.current_root !== root.runtime_path) return root.current_root; const suffixMap = { hosting: 'www', backups: 'backups', mail: 'mail', mysql: 'mysql', postgresql: 'postgresql' }; return `/srv/strata/${suffixMap[root.key] || root.key}`; }
function buildStorageForm(roots) { return Object.fromEntries((roots || []).map((root) => [root.key, suggestedStorageRoot(root)])); }
function shellEscape(value) { return `'${String(value ?? '').replace(/'/g, `'\"'\"'`)}'`; }
function buildStorageCommand() { const path = storageMigration.value.migrate_bin || '/usr/sbin/strata-storage-migrate'; const argumentsList = ['--detach', `--log-path ${shellEscape('/opt/strata-panel/panel/storage/logs/strata-storage-migration.log')}`]; if (selectedStorageKey.value) argumentsList.push(`--item ${shellEscape(selectedStorageKey.value)}`); argumentsList.push(`--hosting-target ${shellEscape(storageForm.value.hosting || '/var/www')}`); argumentsList.push(`--backups-target ${shellEscape(storageForm.value.backups || '/var/backups/strata')}`); argumentsList.push(`--mail-target ${shellEscape(storageForm.value.mail || '/var/mail')}`); argumentsList.push(`--mysql-target ${shellEscape(storageForm.value.mysql || '/var/lib/mysql')}`); argumentsList.push(`--postgresql-target ${shellEscape(storageForm.value.postgresql || '/var/lib/postgresql')}`); return `${path} ${argumentsList.join(' \\\n')}`; }
async function copyText(value) { try { await navigator.clipboard.writeText(value); panelMessage.value = { status: 'saved', message: 'Command copied to clipboard.' }; } catch { panelMessage.value = { status: 'error', message: 'Failed to copy command to clipboard.' }; } }
function currentStorageMigrationItem() { const lines = storageMigrationActivity.value?.lines || []; for (const line of [...lines].reverse()) { const match = line.match(/started storage migration for ([a-z]+)/i); if (match) return match[1].toLowerCase(); const complete = line.match(/Migration complete for item: ([a-z]+)/i); if (complete) return complete[1].toLowerCase(); } return null; }
function storageItemStatusLabel(key) { const activity = storageMigrationActivity.value; const currentItem = currentStorageMigrationItem(); if (!activity || currentItem !== key) return key === selectedStorageKey.value ? 'Selected' : 'Idle'; if (activity.status === 'running') return 'Running'; if (activity.status === 'completed') return 'Completed'; if (activity.status === 'failed') return 'Failed'; return 'Idle'; }
function storageItemStatusClasses(key) { const label = storageItemStatusLabel(key); if (label === 'Running') return 'border-blue-700/40 bg-blue-900/20 text-blue-300'; if (label === 'Completed') return 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300'; if (label === 'Failed') return 'border-red-700/40 bg-red-900/20 text-red-300'; if (label === 'Selected') return 'border-sky-700/40 bg-sky-900/20 text-sky-300'; return 'border-gray-700 bg-gray-900 text-gray-400'; }
function useLatestRelease() { if (props.panel?.latest_release?.tag_name) panelForm.value.version = props.panel.latest_release.tag_name; }
function resolvedPanelPayload() { const version = panelForm.value.version?.trim(); const branch = panelForm.value.branch?.trim(); if (version) return { source_type: 'version', source_value: version }; if (branch) return { source_type: 'branch', source_value: branch }; return { source_type: 'version', source_value: '' }; }
function currentLogUrl(name) { if (!currentActivity.value?.key) return null; return route(name, { key: currentActivity.value.key }); }
function scheduleLogScroll() { nextTick(() => { if (autoScrollLogs.value && logScroller.value) logScroller.value.scrollTop = logScroller.value.scrollHeight; }); }
function statusClasses(status) { if (status === 'running') return 'border-blue-700/40 bg-blue-900/20 text-blue-300'; if (status === 'completed') return 'border-emerald-700/40 bg-emerald-900/20 text-emerald-300'; if (status === 'failed') return 'border-red-700/40 bg-red-900/20 text-red-300'; return 'border-gray-700 bg-gray-900 text-gray-300'; }
function formatStatus(status) { return (status || 'idle').replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase()); }
function openLogPopup() { const url = currentLogUrl('admin.updates.logs.popup'); if (!url) return; window.open(url, '_blank', 'popup=yes,width=1100,height=780,resizable=yes,scrollbars=yes'); }
function exportCurrentLog() { const url = currentLogUrl('admin.updates.logs.export'); if (!url) return; window.location.assign(url); }
async function refreshActivity() { activityLoading.value = true; activityError.value = ''; try { const res = await fetch(route('admin.updates.activity')); const data = await res.json(); panelActivity.value = data; if (!selectedActivityKey.value || !data.activities?.some((entry) => entry.key === selectedActivityKey.value)) selectedActivityKey.value = data.current_log_key || data.activities?.[0]?.key || 'panel_upgrade'; scheduleLogScroll(); } catch { activityError.value = 'Failed to refresh upgrade activity.'; } finally { activityLoading.value = false; } }
function startActivityPolling() { stopActivityPolling(); activityPollHandle = window.setInterval(refreshActivity, 4000); }
function stopActivityPolling() { if (activityPollHandle) { window.clearInterval(activityPollHandle); activityPollHandle = null; } }
async function checkUpdates() { if (!selectedNode.value) return; checking.value = true; loadError.value = ''; applyResult.value = null; try { const res = await fetch(route('admin.updates.available') + '?node_id=' + selectedNode.value); const data = await res.json(); if (data.error) { loadError.value = data.error; packages.value = []; } else { packages.value = data.packages ?? []; } } catch { loadError.value = 'Failed to check updates.'; } finally { checking.value = false; } }
async function applyUpdates() { if (!confirm('Apply all pending updates on this node? The upgrade runs in the background and may take a few minutes.')) return; applying.value = true; applyResult.value = null; try { const res = await fetch(route('admin.updates.apply'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ node_id: selectedNode.value }) }); applyResult.value = await res.json(); if (applyResult.value.status === 'upgraded') await checkUpdates(); } catch { applyResult.value = { status: 'error', output: 'Request failed.' }; } finally { applying.value = false; } }
async function startPanelUpgrade() { if (!confirm('Start the Strata panel upgrade now? The panel may be briefly unavailable while services restart.')) return; panelApplying.value = true; panelMessage.value = null; try { const res = await fetch(route('admin.updates.panel-upgrade'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(resolvedPanelPayload()) }); panelMessage.value = await res.json(); if (!res.ok && !panelMessage.value?.message) panelMessage.value = { status: 'error', message: 'Failed to start panel upgrade.' }; await refreshActivity(); } catch { panelMessage.value = { status: 'error', message: 'Failed to start panel upgrade.' }; } finally { panelApplying.value = false; } }
async function startStorageMigration() { if (!selectedStorageRoot.value) return; if (!confirm(`Start the ${selectedStorageRoot.value.label} migration now? This will stop services during cutover and may make the panel briefly unavailable.`)) return; panelApplying.value = true; panelMessage.value = null; try { const payload = { item: selectedStorageRoot.value.key, roots: { hosting: storageForm.value.hosting, backups: storageForm.value.backups, mail: storageForm.value.mail, mysql: storageForm.value.mysql, postgresql: storageForm.value.postgresql } }; const res = await fetch(route('admin.updates.storage-migration'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(payload) }); panelMessage.value = await res.json(); if (!res.ok && !panelMessage.value?.message) panelMessage.value = { status: 'error', message: 'Failed to start storage migration.' }; await refreshActivity(); } catch { panelMessage.value = { status: 'error', message: 'Failed to start storage migration.' }; } finally { panelApplying.value = false; } }
async function savePanelSettings() { panelSettingsSaving.value = true; panelMessage.value = null; try { const res = await fetch(route('admin.updates.settings'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(panelSettings.value) }); panelMessage.value = await res.json(); if (!res.ok && !panelMessage.value?.message) panelMessage.value = { status: 'error', message: 'Failed to save upgrade preference.' }; } catch { panelMessage.value = { status: 'error', message: 'Failed to save upgrade preference.' }; } finally { panelSettingsSaving.value = false; } }
async function startRemoteAgentsUpgrade() { if (!confirm('Start the remote node agent upgrade now?')) return; panelApplying.value = true; panelMessage.value = null; try { const res = await fetch(route('admin.updates.remote-agents'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(resolvedPanelPayload()) }); panelMessage.value = await res.json(); if (!res.ok && !panelMessage.value?.message) panelMessage.value = { status: 'error', message: 'Failed to start remote node agent upgrade.' }; await refreshActivity(); } catch { panelMessage.value = { status: 'error', message: 'Failed to start remote node agent upgrade.' }; } finally { panelApplying.value = false; } }
async function startBackupRollback() { if (!rollbackBackupName.value) return; if (!confirm(`Roll back the panel to backup "${rollbackBackupName.value}" now? This restores the previously installed release state captured in that backup and may briefly interrupt the panel.`)) return; panelApplying.value = true; panelMessage.value = null; try { const res = await fetch(route('admin.updates.panel-rollback-backup'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ backup_name: rollbackBackupName.value }) }); panelMessage.value = await res.json(); if (!res.ok && !panelMessage.value?.message) panelMessage.value = { status: 'error', message: 'Failed to start backup rollback.' }; await refreshActivity(); } catch { panelMessage.value = { status: 'error', message: 'Failed to start backup rollback.' }; } finally { panelApplying.value = false; } }
function backupLabel(backup) { const parts = [backup.name]; if (backup.installed_version) parts.push(`installed ${backup.installed_version}`); if (backup.created_at) parts.push(backup.created_at); return parts.join(' | '); }
watch(() => currentActivity.value?.lines?.length, () => scheduleLogScroll());
onMounted(() => { scheduleLogScroll(); startActivityPolling(); });
onBeforeUnmount(() => stopActivityPolling());
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
