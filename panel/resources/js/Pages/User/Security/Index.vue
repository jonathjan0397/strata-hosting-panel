<template>
    <AppLayout title="SSH Keys">
        <div class="mb-6">
            <h2 class="text-lg font-semibold text-gray-100">SSH Keys</h2>
            <p class="text-sm text-gray-400 mt-1">SSH keys grant command-line access to your hosting account.</p>
        </div>

        <!-- Flash messages -->
        <div v-if="$page.props.flash?.success" class="mb-4 rounded-lg bg-emerald-900/30 border border-emerald-800 px-4 py-3 text-sm text-emerald-400">
            {{ $page.props.flash.success }}
        </div>
        <div v-if="$page.props.flash?.error" class="mb-4 rounded-lg bg-red-900/30 border border-red-800 px-4 py-3 text-sm text-red-400">
            {{ $page.props.flash.error }}
        </div>

        <!-- Keys list -->
        <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden mb-6">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                <h3 class="text-sm font-semibold text-gray-200">Authorized Keys <span class="ml-2 text-xs font-normal text-gray-500">{{ keys.length }}</span></h3>
                <button @click="showAdd = !showAdd" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">+ Add Key</button>
            </div>

            <!-- Add key form -->
            <div v-if="showAdd" class="border-b border-gray-800 px-5 py-4 bg-gray-800/30">
                <form @submit.prevent="submitKey" class="space-y-3">
                    <input v-model="form.name" type="text" placeholder="Key name (e.g. My Laptop)" class="field" required />
                    <textarea v-model="form.public_key" rows="4" placeholder="Paste public key (ssh-rsa AAAA... or ssh-ed25519 AAAA...)" class="field font-mono text-xs" required></textarea>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors">Add Key</button>
                        <button type="button" @click="showAdd = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="divide-y divide-gray-800">
                <div v-for="key in keys" :key="key.id" class="flex items-center gap-3 px-5 py-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-200">{{ key.name }}</p>
                        <p class="text-xs font-mono text-gray-500 truncate">{{ key.fingerprint }}</p>
                    </div>
                    <ConfirmButton
                        :href="route('my.ssh-keys.destroy', key.id)"
                        method="delete"
                        label="Remove"
                        :confirm-message="`Remove key '${key.name}'?`"
                        color="red"
                    />
                </div>
                <div v-if="keys.length === 0" class="px-5 py-8 text-center text-sm text-gray-500">No SSH keys added yet.</div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

defineProps({ keys: Array });

const showAdd = ref(false);
const form = ref({ name: '', public_key: '' });

function submitKey() {
    router.post(route('my.ssh-keys.store'), form.value, {
        onSuccess: () => { form.value = { name: '', public_key: '' }; showAdd.value = false; },
    });
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none; }
</style>
