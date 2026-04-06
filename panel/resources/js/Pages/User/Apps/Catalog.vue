<template>
    <AppLayout title="App Installer">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-100">App Installer</h2>
                <p class="text-sm text-gray-400 mt-0.5">Install popular apps with one click. Auto-updates keep them current and secure.</p>
            </div>
            <Link :href="route('my.apps.installed')" class="text-sm text-indigo-400 hover:text-indigo-300 transition-colors">
                My Installed Apps →
            </Link>
        </div>

        <!-- Category filter -->
        <div class="mb-5 flex gap-2 flex-wrap">
            <button v-for="cat in categories" :key="cat.value"
                @click="activeCategory = cat.value"
                class="rounded-full px-3 py-1 text-xs font-medium transition-colors"
                :class="activeCategory === cat.value
                    ? 'bg-indigo-600 text-white'
                    : 'bg-gray-800 text-gray-400 hover:text-gray-200'">
                {{ cat.label }}
            </button>
        </div>

        <!-- App grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="(app, slug) in filteredApps" :key="slug"
                class="rounded-xl border border-gray-800 bg-gray-900 p-5 flex flex-col gap-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" :class="iconBg(app.color)">
                        <span class="text-lg font-bold" :class="iconText(app.color)">{{ app.name[0] }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-semibold text-gray-100">{{ app.name }}</h3>
                            <span v-if="app.automated" class="rounded-full bg-emerald-900/40 px-2 py-0.5 text-xs text-emerald-400">
                                Auto-install
                            </span>
                        </div>
                        <p class="text-xs text-gray-400 mt-0.5 leading-relaxed">{{ app.tagline }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-1">
                    <span v-for="f in app.features" :key="f"
                        class="rounded bg-gray-800 px-2 py-0.5 text-xs text-gray-400">{{ f }}</span>
                </div>

                <div class="mt-auto">
                    <button @click="openInstall(slug, app)"
                        class="w-full rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors">
                        Install {{ app.name }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Install modal -->
        <div v-if="installing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
            <div class="w-full max-w-md rounded-xl border border-gray-700 bg-gray-900 shadow-2xl">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-100">Install {{ installing.name }}</h3>
                    <button @click="installing = null" class="text-gray-500 hover:text-gray-300">✕</button>
                </div>
                <form @submit.prevent="submitInstall" class="px-6 py-5 space-y-4">
                    <div v-if="!installing.automated" class="rounded-lg bg-amber-900/20 border border-amber-700/40 px-3 py-2.5">
                        <p class="text-xs text-amber-300">
                            After installation, you'll be shown a link to complete setup in your browser.
                            The database will be pre-created for you.
                        </p>
                    </div>

                    <div>
                        <label class="label">Domain</label>
                        <select v-model.number="form.domain_id" class="field" required>
                            <option value="" disabled>Select domain</option>
                            <option v-for="d in domains" :key="d.id" :value="d.id">{{ d.domain }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="label">Install path</label>
                        <input v-model="form.install_path" type="text" class="field font-mono text-xs"
                            placeholder="/ (root) or /blog" required />
                        <p class="mt-1 text-xs text-gray-500">Use <code>/</code> to install at the domain root, or <code>/blog</code> for a subdirectory.</p>
                    </div>

                    <div>
                        <label class="label">Site title</label>
                        <input v-model="form.site_title" type="text" class="field" placeholder="My Website" required />
                    </div>

                    <div>
                        <label class="label">Admin email</label>
                        <input v-model="form.admin_email" type="email" class="field" required />
                        <p v-if="installing.automated" class="mt-1 text-xs text-gray-500">
                            A temporary admin password will be emailed here after install. Change it on first login.
                        </p>
                    </div>

                    <div class="flex items-center gap-3 rounded-lg bg-gray-800/50 px-3 py-2.5">
                        <input v-model="form.auto_update" type="checkbox" id="auto_update" class="rounded border-gray-600 bg-gray-700 text-indigo-500" />
                        <div>
                            <label for="auto_update" class="text-xs font-medium text-gray-200 cursor-pointer">Enable auto-updates</label>
                            <p class="text-xs text-gray-500">Core, plugins, and themes updated automatically when new versions are released.</p>
                        </div>
                    </div>

                    <div class="flex gap-3 pt-1">
                        <button type="submit" :disabled="form.processing"
                            class="flex-1 rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors disabled:opacity-50">
                            {{ form.processing ? 'Starting…' : 'Install ' + installing.name }}
                        </button>
                        <button type="button" @click="installing = null" class="text-sm text-gray-500 hover:text-gray-300">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ catalog: Object, domains: Array });

const categories = [
    { value: 'all', label: 'All' },
    { value: 'cms', label: 'CMS' },
    { value: 'blog', label: 'Blog' },
    { value: 'gallery', label: 'Gallery' },
    { value: 'forum', label: 'Forum' },
];

const activeCategory = ref('all');
const installing = ref(null);

const filteredApps = computed(() => {
    if (activeCategory.value === 'all') return props.catalog;
    return Object.fromEntries(
        Object.entries(props.catalog).filter(([, app]) => app.category === activeCategory.value)
    );
});

const form = useForm({
    app_slug: '',
    domain_id: '',
    install_path: '/',
    site_title: '',
    admin_email: '',
    auto_update: true,
});

function openInstall(slug, app) {
    installing.value = { slug, ...app };
    form.reset();
    form.app_slug = slug;
    form.install_path = '/';
    form.auto_update = true;
}

function submitInstall() {
    form.post(route('user.apps.install'), {
        onSuccess: () => { installing.value = null; },
    });
}

function iconBg(color) {
    const map = { blue: 'bg-blue-900/50', orange: 'bg-orange-900/50', indigo: 'bg-indigo-900/50', pink: 'bg-pink-900/50', emerald: 'bg-emerald-900/50' };
    return map[color] ?? 'bg-gray-800';
}
function iconText(color) {
    const map = { blue: 'text-blue-300', orange: 'text-orange-300', indigo: 'text-indigo-300', pink: 'text-pink-300', emerald: 'text-emerald-300' };
    return map[color] ?? 'text-gray-300';
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none; }
.label { @apply block text-xs font-medium text-gray-400 mb-1; }
</style>
