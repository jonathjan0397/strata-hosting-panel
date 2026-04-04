<template>
    <div class="space-y-6">

        <!-- Input -->
        <div class="flex gap-3">
            <div class="flex-1">
                <slot name="domain-input" :domain="domain" :set-domain="d => domain = d">
                    <input
                        v-model="domain"
                        type="text"
                        placeholder="example.com"
                        class="field w-full"
                        @keydown.enter="run"
                    />
                </slot>
            </div>
            <button
                @click="run"
                :disabled="loading || !domain"
                class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors"
            >
                <span v-if="loading" class="flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                    Checking…
                </span>
                <span v-else>Run Check</span>
            </button>
        </div>

        <!-- Server IP note -->
        <p v-if="serverIp" class="text-xs text-gray-500">
            Checks are run against server IP: <span class="font-mono text-gray-400">{{ serverIp }}</span>
        </p>

        <!-- Error -->
        <div v-if="error" class="rounded-lg border border-red-700/50 bg-red-900/20 px-4 py-3 text-sm text-red-400">
            {{ error }}
        </div>

        <!-- Results -->
        <template v-if="results">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-300">
                    Results for <span class="font-mono text-indigo-400">{{ results.domain }}</span>
                </h3>
                <div class="flex items-center gap-3 text-xs text-gray-500">
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-green-500"></span> Pass</span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-yellow-500"></span> Warning</span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-red-500"></span> Fail</span>
                </div>
            </div>

            <div class="space-y-3">
                <div
                    v-for="(check, key) in results.checks"
                    :key="key"
                    class="rounded-xl border bg-gray-900 overflow-hidden"
                    :class="{
                        'border-green-700/40':  check.status === 'pass',
                        'border-yellow-700/40': check.status === 'warning',
                        'border-red-700/40':    check.status === 'fail',
                    }"
                >
                    <!-- Check header -->
                    <button
                        class="flex w-full items-center justify-between px-4 py-3 text-left"
                        @click="toggle(key)"
                    >
                        <div class="flex items-center gap-3">
                            <!-- Status icon -->
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                :class="{
                                    'bg-green-900/50 text-green-400':  check.status === 'pass',
                                    'bg-yellow-900/50 text-yellow-400': check.status === 'warning',
                                    'bg-red-900/50 text-red-400':      check.status === 'fail',
                                }"
                            >
                                <svg v-if="check.status === 'pass'" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                                <svg v-else-if="check.status === 'warning'" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                </svg>
                                <svg v-else class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </span>

                            <div>
                                <p class="text-sm font-medium text-gray-200">{{ check.label }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ check.detail }}</p>
                            </div>
                        </div>

                        <!-- Expand chevron -->
                        <svg
                            class="h-4 w-4 shrink-0 text-gray-600 transition-transform"
                            :class="{ 'rotate-180': expanded[key] }"
                            fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    <!-- Expanded detail -->
                    <div v-if="expanded[key]" class="border-t border-gray-800 px-4 py-4 space-y-4">

                        <!-- Raw DNS data -->
                        <div v-if="check.data && check.data.length">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">DNS Data</p>
                            <div class="rounded-lg bg-gray-950 px-3 py-2.5 space-y-1">
                                <p
                                    v-for="(line, i) in check.data"
                                    :key="i"
                                    class="text-xs font-mono text-gray-300 break-all"
                                >{{ line }}</p>
                            </div>
                        </div>

                        <!-- Fix instructions -->
                        <div v-if="check.fix">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">How to Fix</p>
                            <div class="rounded-lg border border-yellow-700/30 bg-yellow-900/10 px-3 py-2.5">
                                <pre class="text-xs text-yellow-200 whitespace-pre-wrap font-mono leading-relaxed">{{ check.fix }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Score summary -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 px-5 py-4 flex items-center justify-between">
                <div>
                    <p class="text-sm font-semibold text-gray-200">Overall Score</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ passCount }} passed · {{ warnCount }} warning{{ warnCount !== 1 ? 's' : '' }} · {{ failCount }} failed</p>
                </div>
                <div
                    class="text-2xl font-bold"
                    :class="{
                        'text-green-400':  score === 100,
                        'text-yellow-400': score >= 60 && score < 100,
                        'text-red-400':    score < 60,
                    }"
                >{{ score }}%</div>
            </div>
        </template>

    </div>
</template>

<script setup>
import { ref, computed, reactive } from 'vue';
import axios from 'axios';

const props = defineProps({
    checkUrl: { type: String, required: true },
    serverIp: { type: String, default: '' },
    initialDomain: { type: String, default: '' },
});

const domain  = ref(props.initialDomain);
const loading = ref(false);
const error   = ref(null);
const results = ref(null);
const expanded = reactive({});

async function run() {
    if (!domain.value) return;
    loading.value = true;
    error.value   = null;
    results.value = null;

    try {
        const { data } = await axios.post(props.checkUrl, { domain: domain.value });
        results.value  = data;

        // Auto-expand failed/warning checks
        for (const [key, check] of Object.entries(data.checks)) {
            expanded[key] = check.status !== 'pass';
        }
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Check failed — server error.';
    } finally {
        loading.value = false;
    }
}

function toggle(key) {
    expanded[key] = !expanded[key];
}

const checkList = computed(() => results.value ? Object.values(results.value.checks) : []);
const passCount = computed(() => checkList.value.filter(c => c.status === 'pass').length);
const warnCount = computed(() => checkList.value.filter(c => c.status === 'warning').length);
const failCount = computed(() => checkList.value.filter(c => c.status === 'fail').length);
const total     = computed(() => checkList.value.length);
const score     = computed(() => {
    if (!total.value) return 0;
    // pass=1, warning=0.5, fail=0
    const pts = passCount.value + warnCount.value * 0.5;
    return Math.round((pts / total.value) * 100);
});
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
