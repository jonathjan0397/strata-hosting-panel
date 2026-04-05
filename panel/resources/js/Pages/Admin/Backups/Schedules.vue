<template>
    <AppLayout title="Backup Schedules">
        <div class="mb-6">
            <h2 class="text-lg font-semibold text-gray-100">Backup Schedules</h2>
            <p class="text-sm text-gray-400 mt-0.5">Configure per-account backup frequency and time. Backups run hourly and pick up accounts whose scheduled hour matches.</p>
        </div>

        <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-800 text-left">
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Account</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Node</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Frequency</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Time (server)</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Day</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="acc in accounts" :key="acc.id" class="group">
                        <td class="px-4 py-3 font-mono text-xs text-gray-200">{{ acc.username }}</td>
                        <td class="px-4 py-3 text-xs text-gray-400">{{ acc.node ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <select v-model="forms[acc.id].backup_schedule" class="field-sm">
                                <option value="disabled">Disabled</option>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                            </select>
                        </td>
                        <td class="px-4 py-3">
                            <input
                                v-model="forms[acc.id].backup_time"
                                type="time"
                                :disabled="forms[acc.id].backup_schedule === 'disabled'"
                                class="field-sm w-28"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <select
                                v-model.number="forms[acc.id].backup_day"
                                :disabled="forms[acc.id].backup_schedule !== 'weekly'"
                                class="field-sm"
                            >
                                <option :value="0">Sunday</option>
                                <option :value="1">Monday</option>
                                <option :value="2">Tuesday</option>
                                <option :value="3">Wednesday</option>
                                <option :value="4">Thursday</option>
                                <option :value="5">Friday</option>
                                <option :value="6">Saturday</option>
                            </select>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                @click="save(acc.id)"
                                class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors"
                            >
                                Save
                            </button>
                        </td>
                    </tr>
                    <tr v-if="accounts.length === 0">
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No accounts found.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ accounts: Array });

// Build a reactive form per account
const forms = reactive(
    Object.fromEntries(
        props.accounts.map(a => [a.id, {
            backup_schedule: a.backup_schedule,
            backup_time:     a.backup_time,
            backup_day:      a.backup_day,
        }])
    )
);

function save(accountId) {
    router.put(route('admin.backups.schedules.update', accountId), forms[accountId], {
        preserveScroll: true,
    });
}
</script>

<style scoped>
@reference "tailwindcss";
.field-sm {
    @apply block rounded-lg border border-gray-700 bg-gray-800 px-2.5 py-1.5 text-xs text-gray-100
           placeholder-gray-500 focus:border-indigo-500 focus:outline-none
           disabled:opacity-40 disabled:cursor-not-allowed;
}
</style>
