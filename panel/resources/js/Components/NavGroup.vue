<template>
    <section class="nav-group pt-4">
        <button
            type="button"
            class="nav-group-toggle"
            :aria-expanded="(!collapsed).toString()"
            @click="toggle"
        >
            <span class="nav-group-label">{{ label }}</span>
            <svg
                class="h-4 w-4 transition-transform duration-200"
                :class="{ 'rotate-180': !collapsed }"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.7"
                stroke="currentColor"
            >
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </button>
        <div v-show="!collapsed" class="mt-2 space-y-0.5">
            <slot />
        </div>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
});

const storageKey = computed(() => {
    const normalized = props.label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    return `strata_nav_group:${normalized}`;
});

const collapsed = ref(readInitialState());

function readInitialState() {
    if (typeof localStorage === 'undefined') {
        return false;
    }

    return localStorage.getItem(storageKey.value) === '1';
}

function toggle() {
    collapsed.value = !collapsed.value;

    if (typeof localStorage !== 'undefined') {
        localStorage.setItem(storageKey.value, collapsed.value ? '1' : '0');
    }
}
</script>

<style scoped>
.nav-group-toggle {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.7rem 0.85rem;
    border-radius: 0.95rem;
    border: 1px solid color-mix(in srgb, var(--glass-line) 88%, transparent);
    background: color-mix(in srgb, var(--glass-panel-soft) 86%, transparent);
    color: var(--glass-text-soft);
    text-align: left;
    transition: 150ms ease;
}

.nav-group-toggle:hover {
    color: var(--glass-text);
    border-color: color-mix(in srgb, var(--glass-accent) 32%, var(--glass-line));
    background: color-mix(in srgb, var(--glass-accent-soft) 52%, var(--glass-panel-soft));
}

.nav-group-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
}
</style>
