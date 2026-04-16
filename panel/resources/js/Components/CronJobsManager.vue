<template>
    <div class="space-y-6 p-6">
        <PageHeader :eyebrow="eyebrow" :title="title" :description="description">
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <button @click="syncJobs" class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 transition-colors hover:bg-gray-800">
                        Reapply to Node
                    </button>
                    <button @click="openCreate" class="btn-primary">
                        Add Cron Job
                    </button>
                </div>
            </template>
        </PageHeader>

        <div class="grid gap-4 md:grid-cols-3">
            <StatCard label="Cron Jobs" :value="jobs.length" color="indigo" />
            <StatCard label="Enabled" :value="enabledJobs" color="emerald" />
            <StatCard label="Disabled" :value="disabledJobs" color="amber" />
        </div>

        <div class="rounded-xl border border-gray-800 bg-gray-900/70 p-5">
            <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-200">How cron scheduling works</h3>
                    <p class="mt-1 text-sm text-gray-400">
                        Jobs run as <span class="font-mono text-gray-200">{{ account.username }}</span> on the assigned node{{ account.node?.name ? ` (${account.node.name})` : '' }}.
                        The first five fields control when the command runs. The command field is the actual shell command that executes.
                    </p>
                </div>
                <div class="rounded-lg border border-gray-800 bg-gray-950/60 px-3 py-2 text-xs text-gray-400">
                    Standard cron format only: <span class="font-mono text-gray-300">* * * * *</span>
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-5">
                <div v-for="item in fieldHelp" :key="item.label" class="rounded-lg border border-gray-800 bg-gray-950/60 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-300">{{ item.label }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ item.range }}</p>
                    <p class="mt-2 text-xs leading-relaxed text-gray-400">{{ item.description }}</p>
                </div>
            </div>
        </div>

        <div v-if="$page.props.flash?.success" class="rounded-lg border border-emerald-800 bg-emerald-900/30 px-4 py-3 text-sm text-emerald-300">
            {{ $page.props.flash.success }}
        </div>
        <div v-if="$page.props.flash?.error" class="rounded-lg border border-red-800 bg-red-900/30 px-4 py-3 text-sm text-red-300">
            {{ $page.props.flash.error }}
        </div>
        <div v-if="parseError" class="rounded-lg border border-amber-800 bg-amber-900/20 px-4 py-3 text-sm text-amber-300">
            {{ parseError }}
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-800 px-5 py-3.5">
                <div>
                    <h3 class="text-sm font-semibold text-gray-200">Scheduled Jobs</h3>
                    <p class="mt-1 text-xs text-gray-500">Each save reapplies the managed cron block for this hosting account.</p>
                </div>
                <span class="text-xs text-gray-500">{{ jobs.length }} total</span>
            </div>

            <div v-if="jobs.length" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Label</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Schedule</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Command</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="job in jobs" :key="job.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 text-sm text-gray-200">
                                <div class="flex flex-col gap-1">
                                    <span>{{ job.name || 'Unnamed job' }}</span>
                                    <span class="text-xs font-mono text-gray-500">{{ job.cron_line }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-xs font-mono text-gray-400">{{ job.expression }}</td>
                            <td class="max-w-xl px-5 py-3.5 text-xs font-mono text-gray-400">
                                <span class="break-all">{{ job.command }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-xs">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 font-semibold uppercase tracking-wide"
                                    :class="job.is_enabled ? 'bg-emerald-900/30 text-emerald-300' : 'bg-amber-900/30 text-amber-300'"
                                >
                                    {{ job.is_enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button @click="openEdit(job)" class="text-xs text-gray-400 transition-colors hover:text-gray-200">
                                        Edit
                                    </button>
                                    <button @click="destroyJob(job)" class="text-xs text-red-400 transition-colors hover:text-red-300">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="px-5 py-8">
                <EmptyState
                    title="No cron jobs yet"
                    description="Create your first scheduled task for recurring scripts, maintenance commands, or application jobs."
                />
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
            <div class="max-h-[90vh] w-full max-w-5xl overflow-y-auto rounded-2xl border border-gray-800 bg-gray-900 shadow-2xl">
                <div class="flex items-center justify-between border-b border-gray-800 px-6 py-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-100">{{ editingJob ? 'Edit Cron Job' : 'Add Cron Job' }}</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Use either the raw cron line input or the individual schedule fields below. The preview line is what gets written for this account.
                        </p>
                    </div>
                    <button @click="closeModal" class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 transition-colors hover:bg-gray-800">
                        Close
                    </button>
                </div>

                <form @submit.prevent="submit" class="space-y-6 px-6 py-5">
                    <div class="grid gap-4 lg:grid-cols-[1.3fr,1fr]">
                        <div class="space-y-4">
                            <FormField label="Job label" :error="form.errors.name">
                                <input v-model="form.name" type="text" class="field w-full" placeholder="Nightly cache warmup" />
                            </FormField>

                            <FormField label="Cron line" :error="form.errors.cron_line || form.errors.expression">
                                <input
                                    v-model="form.cron_line"
                                    type="text"
                                    class="field w-full font-mono"
                                    placeholder="*/5 * * * * php /var/www/account/public_html/artisan schedule:run"
                                    @blur="ingestCronLine()"
                                />
                                <p class="mt-2 text-xs leading-relaxed text-gray-500">
                                    Paste the full line exactly as you would in a shell cron editor. The first five tokens are the schedule; everything after that is the command.
                                </p>
                            </FormField>

                            <FormField label="Command" :error="form.errors.command">
                                <textarea v-model="form.command" rows="4" class="field w-full font-mono text-sm" placeholder="php /var/www/account/public_html/artisan schedule:run"></textarea>
                                <p class="mt-2 text-xs leading-relaxed text-gray-500">
                                    This is the command line cron runs as the hosting account user. Keep it to a single line. Use absolute paths wherever possible.
                                </p>
                            </FormField>
                        </div>

                        <div class="rounded-xl border border-gray-800 bg-gray-950/50 p-4">
                            <h4 class="text-sm font-semibold text-gray-200">Schedule Builder</h4>
                            <p class="mt-1 text-xs leading-relaxed text-gray-500">
                                These five fields define when the command runs. Use values like <span class="font-mono text-gray-300">*</span>, <span class="font-mono text-gray-300">*/5</span>, <span class="font-mono text-gray-300">1,15</span>, or <span class="font-mono text-gray-300">1-5</span>.
                            </p>

                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <FormField label="Minute">
                                    <input v-model="form.minute" type="text" class="field w-full font-mono" placeholder="0" />
                                </FormField>
                                <FormField label="Hour">
                                    <input v-model="form.hour" type="text" class="field w-full font-mono" placeholder="2" />
                                </FormField>
                                <FormField label="Day of month">
                                    <input v-model="form.day_of_month" type="text" class="field w-full font-mono" placeholder="*" />
                                </FormField>
                                <FormField label="Month">
                                    <input v-model="form.month" type="text" class="field w-full font-mono" placeholder="*" />
                                </FormField>
                                <FormField label="Day of week">
                                    <input v-model="form.day_of_week" type="text" class="field w-full font-mono" placeholder="*" />
                                </FormField>
                                <label class="flex items-center gap-2 rounded-lg border border-gray-800 bg-gray-900 px-3 py-3 text-sm text-gray-300">
                                    <input v-model="form.is_enabled" type="checkbox" class="rounded border-gray-700 bg-gray-800 text-indigo-600" />
                                    Enable this job
                                </label>
                            </div>

                            <div class="mt-4 rounded-lg border border-gray-800 bg-gray-900 px-3 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Preview line</p>
                                <p class="mt-2 break-all font-mono text-sm text-gray-200">{{ previewLine || 'Complete the schedule and command to generate a preview.' }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-950/50 p-4 text-xs leading-relaxed text-gray-500">
                        <p>Minute controls the minute within the hour.</p>
                        <p>Hour controls the hour of the day in 24-hour time.</p>
                        <p>Day of month controls the calendar day.</p>
                        <p>Month controls the month number.</p>
                        <p>Day of week controls the weekday, where <span class="font-mono text-gray-300">0</span> or <span class="font-mono text-gray-300">7</span> is Sunday.</p>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <button type="button" @click="closeModal" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 transition-colors hover:bg-gray-800">
                            Cancel
                        </button>
                        <button type="submit" :disabled="form.processing" class="btn-primary">
                            {{ form.processing ? 'Saving...' : editingJob ? 'Save Changes' : 'Create Job' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    account: { type: Object, required: true },
    jobs: { type: Array, required: true },
    eyebrow: { type: String, default: 'Developer Tools' },
    title: { type: String, default: 'Cron Jobs' },
    description: { type: String, default: '' },
    storeUrl: { type: String, required: true },
    syncUrl: { type: String, required: true },
    updateRouteName: { type: String, required: true },
    destroyRouteName: { type: String, required: true },
});

const fieldHelp = [
    { label: 'Minute', range: '0-59', description: 'Which minute inside the hour the command should run.' },
    { label: 'Hour', range: '0-23', description: 'Which hour of the day the command should run, using 24-hour time.' },
    { label: 'Day of Month', range: '1-31', description: 'Which calendar day of the month should match.' },
    { label: 'Month', range: '1-12', description: 'Which month should match. Use numbers unless you know your cron supports names.' },
    { label: 'Day of Week', range: '0-7', description: 'Which weekday should match. Sunday is 0 or 7.' },
];

const enabledJobs = computed(() => props.jobs.filter((job) => job.is_enabled).length);
const disabledJobs = computed(() => props.jobs.filter((job) => !job.is_enabled).length);

const showModal = ref(false);
const editingJob = ref(null);
const parseError = ref('');

const form = useForm({
    name: '',
    cron_line: '',
    minute: '*',
    hour: '*',
    day_of_month: '*',
    month: '*',
    day_of_week: '*',
    command: '',
    is_enabled: true,
});

const expression = computed(() => {
    return [
        form.minute?.trim() || '*',
        form.hour?.trim() || '*',
        form.day_of_month?.trim() || '*',
        form.month?.trim() || '*',
        form.day_of_week?.trim() || '*',
    ].join(' ');
});

const previewLine = computed(() => {
    const command = form.command?.trim();
    if (!command) {
        return '';
    }

    return `${expression.value} ${command}`;
});

function resetForm() {
    parseError.value = '';
    form.reset();
    form.name = '';
    form.cron_line = '';
    form.minute = '*';
    form.hour = '*';
    form.day_of_month = '*';
    form.month = '*';
    form.day_of_week = '*';
    form.command = '';
    form.is_enabled = true;
    form.clearErrors();
}

function openCreate() {
    editingJob.value = null;
    resetForm();
    showModal.value = true;
}

function openEdit(job) {
    editingJob.value = job;
    parseError.value = '';
    form.clearErrors();
    form.name = job.name ?? '';
    form.cron_line = job.cron_line ?? '';
    form.command = job.command ?? '';
    form.is_enabled = Boolean(job.is_enabled);
    applyCronExpression(job.expression ?? '* * * * *');
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingJob.value = null;
    resetForm();
}

function applyCronExpression(value) {
    const parts = String(value).trim().split(/\s+/);
    if (parts.length !== 5) {
        return false;
    }

    [form.minute, form.hour, form.day_of_month, form.month, form.day_of_week] = parts;
    return true;
}

function ingestCronLine() {
    parseError.value = '';

    const line = String(form.cron_line || '').trim();
    if (!line) {
        return true;
    }

    const parts = line.split(/\s+/, 6);
    if (parts.length < 6) {
        parseError.value = 'Cron line must include five schedule fields followed by a command.';
        return false;
    }

    if (!applyCronExpression(parts.slice(0, 5).join(' '))) {
        parseError.value = 'Cron schedule could not be parsed.';
        return false;
    }

    form.command = parts[5];
    return true;
}

function submit() {
    if (!ingestCronLine()) {
        return;
    }

    const payload = {
        name: form.name,
        cron_line: previewLine.value,
        expression: expression.value,
        command: form.command,
        is_enabled: form.is_enabled,
    };

    if (editingJob.value) {
        form.transform(() => payload).put(route(props.updateRouteName, editingJob.value.id), {
            onSuccess: closeModal,
        });
        return;
    }

    form.transform(() => payload).post(props.storeUrl, {
        onSuccess: closeModal,
    });
}

function destroyJob(job) {
    if (!window.confirm(`Delete cron job${job.name ? ` "${job.name}"` : ''}?`)) {
        return;
    }

    router.delete(route(props.destroyRouteName, job.id));
}

function syncJobs() {
    router.post(props.syncUrl);
}
</script>
