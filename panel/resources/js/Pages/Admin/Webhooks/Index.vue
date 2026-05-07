<template>
    <AppLayout title="Webhooks">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Platform API"
                title="Webhooks"
                description="Send audit-backed lifecycle events to billing, automation, or monitoring systems."
            />

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h2 class="text-sm font-semibold text-gray-100">Create Endpoint</h2>
                <form @submit.prevent="create" class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-gray-400">Name</label>
                        <input v-model="form.name" type="text" class="field w-full" placeholder="Billing platform" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-gray-400">Endpoint URL</label>
                        <input v-model="form.url" type="url" class="field w-full" placeholder="https://example.com/strata/webhooks" />
                        <p v-if="form.errors.url" class="mt-1 text-xs text-red-400">{{ form.errors.url }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-gray-400">Signing Secret</label>
                        <input v-model="form.secret" type="password" class="field w-full" placeholder="Optional HMAC secret" />
                        <p v-if="form.errors.secret" class="mt-1 text-xs text-red-400">{{ form.errors.secret }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-gray-400">Events</label>
                        <input v-model="form.events" type="text" class="field w-full" placeholder="account.created, account.suspended, *" />
                        <p class="mt-1 text-xs text-gray-500">Leave blank for all events. Separate with commas or spaces.</p>
                        <p v-if="form.errors.events" class="mt-1 text-xs text-red-400">{{ form.errors.events }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input v-model="form.active" type="checkbox" class="rounded border-gray-700 bg-gray-800 text-indigo-600" />
                        Active
                    </label>
                    <div class="lg:col-span-2">
                        <button type="submit" :disabled="form.processing" class="btn-primary">
                            {{ form.processing ? 'Creating...' : 'Create Webhook' }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table v-if="endpoints.length" class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Endpoint</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Events</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Last Delivery</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="endpoint in endpoints" :key="endpoint.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5">
                                <p class="text-sm font-semibold text-gray-100">{{ endpoint.name }}</p>
                                <p class="mt-1 max-w-md truncate font-mono text-xs text-gray-500">{{ endpoint.url }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-gray-400">
                                {{ endpoint.events?.length ? endpoint.events.join(', ') : 'All events' }}
                            </td>
                            <td class="px-5 py-3.5 text-xs text-gray-400">{{ endpoint.last_delivery_at ?? 'Never' }}</td>
                            <td class="px-5 py-3.5">
                                <span :class="endpoint.active ? 'bg-emerald-900/40 text-emerald-300' : 'bg-gray-800 text-gray-400'" class="rounded-full px-2 py-0.5 text-xs font-semibold">
                                    {{ endpoint.active ? 'Active' : 'Paused' }}
                                </span>
                                <p v-if="endpoint.last_status" class="mt-1 text-xs text-gray-500">HTTP {{ endpoint.last_status }}</p>
                                <p v-if="endpoint.last_error" class="mt-1 max-w-xs truncate text-xs text-red-400" :title="endpoint.last_error">{{ endpoint.last_error }}</p>
                            </td>
                            <td class="space-x-3 px-5 py-3.5 text-right">
                                <button type="button" class="text-xs text-indigo-400 hover:text-indigo-300" @click="toggle(endpoint)">
                                    {{ endpoint.active ? 'Pause' : 'Activate' }}
                                </button>
                                <button type="button" class="text-xs text-red-400 hover:text-red-300" @click="remove(endpoint)">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-else
                    title="No webhooks configured"
                    description="Create an endpoint to start sending Strata lifecycle events to external systems."
                />
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    endpoints: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    url: '',
    secret: '',
    events: '',
    active: true,
});

function create() {
    form.post(route('admin.webhooks.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function toggle(endpoint) {
    router.put(route('admin.webhooks.update', endpoint.id), {
        active: !endpoint.active,
        secret_action: 'keep',
        secret: '',
    }, { preserveScroll: true });
}

function remove(endpoint) {
    if (!confirm(`Delete webhook "${endpoint.name}"?`)) return;
    router.delete(route('admin.webhooks.destroy', endpoint.id), { preserveScroll: true });
}
</script>
