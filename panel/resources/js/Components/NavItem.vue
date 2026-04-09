<template>
    <Link
        :href="href"
        class="nav-item group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors"
        :class="active
            ? 'nav-item-active'
            : 'nav-item-idle'"
    >
        <span class="nav-item-icon shrink-0" :class="active ? 'nav-item-icon-active' : 'nav-item-icon-idle'">
            <slot name="icon" />
        </span>
        <slot />
    </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: String,
    active: Boolean,
});
</script>

<style scoped>
.nav-item {
    border: 1px solid transparent;
}

.nav-item-active {
    color: var(--glass-text);
    border-color: color-mix(in srgb, var(--glass-accent) 28%, var(--glass-line));
    background: linear-gradient(
        135deg,
        color-mix(in srgb, var(--glass-accent-soft) 90%, transparent),
        color-mix(in srgb, var(--glass-panel-soft) 94%, transparent)
    );
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
}

.nav-item-idle {
    color: var(--glass-text-soft);
}

.nav-item-idle:hover {
    color: var(--glass-text);
    border-color: color-mix(in srgb, var(--glass-line) 90%, transparent);
    background: color-mix(in srgb, var(--glass-panel-soft) 88%, transparent);
}

.nav-item-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.9rem;
    height: 1.9rem;
    border-radius: 0.8rem;
    transition: 150ms ease;
}

.nav-item-icon-active {
    color: color-mix(in srgb, var(--glass-accent) 88%, white);
    background: color-mix(in srgb, var(--glass-accent-soft) 92%, transparent);
}

.nav-item-icon-idle {
    color: var(--glass-muted);
    background: color-mix(in srgb, var(--glass-panel-soft) 72%, transparent);
}

.group:hover .nav-item-icon-idle {
    color: var(--glass-text-soft);
    background: color-mix(in srgb, var(--glass-accent-soft) 48%, transparent);
}
</style>
