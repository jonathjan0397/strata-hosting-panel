<template>
    <AppLayout title="File Manager">
        <div class="flex flex-col gap-4">

            <!-- Toolbar -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Breadcrumb -->
                <nav class="flex flex-1 items-center gap-1 font-mono text-sm text-gray-400 min-w-0 overflow-x-auto">
                    <button @click="navigate('/')" class="hover:text-gray-200 shrink-0">~</button>
                    <template v-for="(crumb, i) in breadcrumbs" :key="crumb.path">
                        <span class="text-gray-600">/</span>
                        <button
                            @click="navigate(crumb.path)"
                            :class="i === breadcrumbs.length - 1 ? 'text-gray-200' : 'hover:text-gray-200'"
                            class="shrink-0"
                        >{{ crumb.name }}</button>
                    </template>
                </nav>

                <!-- Actions -->
                <div class="flex items-center gap-1.5">
                    <button @click="reload" class="tool-btn" title="Refresh">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                    </button>
                    <button @click="promptMkdir" class="tool-btn" title="New folder">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                    </button>
                    <button @click="promptNewFile" class="tool-btn" title="New file">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    </button>
                    <label class="tool-btn cursor-pointer" title="Upload files">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                        <input type="file" multiple class="hidden" @change="uploadFiles" />
                    </label>
                    <template v-if="selected.size > 0">
                        <button @click="promptCompress" class="tool-btn" title="Compress selected">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
                        </button>
                        <button @click="deleteSelected" class="tool-btn text-red-400 hover:text-red-300 hover:bg-red-900/20" title="Delete selected">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                        </button>
                    </template>
                </div>
            </div>

            <!-- Error banner -->
            <div v-if="error" class="rounded-xl border border-red-700 bg-red-900/20 px-4 py-2.5 text-sm text-red-300 flex items-center justify-between">
                {{ error }}
                <button @click="error = null" class="text-red-500 hover:text-red-300">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- File listing -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                <!-- Loading -->
                <div v-if="loading" class="flex items-center justify-center py-16 text-gray-500 text-sm gap-2">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    Loading…
                </div>

                <template v-else>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-800">
                                <th class="w-8 px-3 py-2.5">
                                    <input type="checkbox" :checked="allSelected" @change="toggleAll" class="rounded border-gray-600 bg-gray-800 text-indigo-500 focus:ring-indigo-500" />
                                </th>
                                <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-400">Name</th>
                                <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-400 w-24">Size</th>
                                <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-400 w-16">Mode</th>
                                <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-400 w-36">Modified</th>
                                <th class="w-28 px-3 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <!-- Up directory -->
                            <tr v-if="currentPath !== '/'" class="hover:bg-gray-800/40 transition-colors">
                                <td></td>
                                <td class="px-3 py-2.5" colspan="4">
                                    <button @click="goUp" class="flex items-center gap-2 text-gray-400 hover:text-gray-200">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg>
                                        ..
                                    </button>
                                </td>
                                <td></td>
                            </tr>

                            <tr
                                v-for="entry in entries"
                                :key="entry.path"
                                class="hover:bg-gray-800/40 transition-colors group"
                                @dblclick="entry.is_dir ? navigate(entry.path) : openEditor(entry)"
                            >
                                <td class="px-3 py-2">
                                    <input
                                        type="checkbox"
                                        :checked="selected.has(entry.path)"
                                        @change="toggleSelect(entry.path)"
                                        class="rounded border-gray-600 bg-gray-800 text-indigo-500 focus:ring-indigo-500"
                                    />
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <!-- Icon -->
                                        <svg v-if="entry.is_dir" class="h-4 w-4 text-indigo-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
                                        <svg v-else class="h-4 w-4 text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                        <button
                                            v-if="entry.is_dir"
                                            @click="navigate(entry.path)"
                                            class="text-gray-200 hover:text-indigo-300 font-medium truncate max-w-xs"
                                        >{{ entry.name }}</button>
                                        <span v-else class="text-gray-300 truncate max-w-xs">{{ entry.name }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-xs text-gray-400">
                                    {{ entry.is_dir ? '—' : formatBytes(entry.size) }}
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ entry.mode }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ formatDate(entry.mod_time) }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button v-if="!entry.is_dir" @click="openEditor(entry)" class="row-btn" title="Edit">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                                        </button>
                                        <button v-if="isArchive(entry)" @click="extractEntry(entry)" class="row-btn" title="Extract">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" /></svg>
                                        </button>
                                        <button v-if="!entry.is_dir" @click="downloadEntry(entry)" class="row-btn" title="Download">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                        </button>
                                        <button @click="promptRename(entry)" class="row-btn" title="Rename">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 0 0 3.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0 1 20.25 6v1.5m0 9V18A2.25 2.25 0 0 1 18 20.25h-1.5m-9 0H6A2.25 2.25 0 0 1 3.75 18v-1.5M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                        </button>
                                        <button @click="promptChmod(entry)" class="row-btn" title="Permissions">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                        </button>
                                        <button @click="confirmDelete(entry)" class="row-btn text-red-400 hover:text-red-300 hover:bg-red-900/20" title="Delete">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!entries.length">
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">This directory is empty.</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </div>
        </div>

        <!-- ── Code editor modal ───────────────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="editor.open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
                <div class="flex flex-col w-full max-w-4xl bg-gray-900 rounded-2xl border border-gray-700 max-h-[90vh]">
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                        <span class="font-mono text-sm text-gray-300">{{ editor.path }}</span>
                        <div class="flex gap-2">
                            <button @click="saveFile" :disabled="editor.saving" class="btn-primary text-xs py-1.5 px-3">
                                {{ editor.saving ? 'Saving…' : 'Save' }}
                            </button>
                            <button @click="editor.open = false" class="btn-secondary text-xs py-1.5 px-3">Close</button>
                        </div>
                    </div>
                    <textarea
                        v-model="editor.content"
                        class="flex-1 min-h-0 font-mono text-sm bg-gray-950 text-gray-100 p-4 resize-none focus:outline-none"
                        spellcheck="false"
                        autocomplete="off"
                    ></textarea>
                </div>
            </div>

            <!-- ── Rename modal ──────────────────────────────────────────────── -->
            <div v-if="renameModal.open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
                <div class="w-full max-w-sm bg-gray-900 rounded-2xl border border-gray-700 p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-gray-200">Rename</h3>
                    <input
                        v-model="renameModal.value"
                        @keyup.enter="doRename"
                        class="field w-full"
                        autofocus
                    />
                    <div class="flex gap-2 justify-end">
                        <button @click="doRename" class="btn-primary text-xs">Rename</button>
                        <button @click="renameModal.open = false" class="btn-secondary text-xs">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- ── Mkdir modal ───────────────────────────────────────────────── -->
            <div v-if="mkdirModal.open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
                <div class="w-full max-w-sm bg-gray-900 rounded-2xl border border-gray-700 p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-gray-200">{{ mkdirModal.isFile ? 'New File' : 'New Folder' }}</h3>
                    <input
                        v-model="mkdirModal.value"
                        @keyup.enter="doMkdir"
                        :placeholder="mkdirModal.isFile ? 'filename.txt' : 'folder-name'"
                        class="field w-full"
                        autofocus
                    />
                    <div class="flex gap-2 justify-end">
                        <button @click="doMkdir" class="btn-primary text-xs">Create</button>
                        <button @click="mkdirModal.open = false" class="btn-secondary text-xs">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- ── Chmod modal ───────────────────────────────────────────────── -->
            <div v-if="chmodModal.open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
                <div class="w-full max-w-sm bg-gray-900 rounded-2xl border border-gray-700 p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-gray-200">Permissions — <span class="font-mono">{{ chmodModal.name }}</span></h3>
                    <input
                        v-model="chmodModal.value"
                        @keyup.enter="doChmod"
                        placeholder="0644"
                        maxlength="4"
                        class="field w-full font-mono text-center text-xl tracking-widest"
                        autofocus
                    />
                    <p class="text-xs text-gray-500">Enter a 4-digit octal mode, e.g. 0644 or 0755.</p>
                    <div class="flex gap-2 justify-end">
                        <button @click="doChmod" class="btn-primary text-xs">Apply</button>
                        <button @click="chmodModal.open = false" class="btn-secondary text-xs">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- ── Compress modal ────────────────────────────────────────────── -->
            <div v-if="compressModal.open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
                <div class="w-full max-w-sm bg-gray-900 rounded-2xl border border-gray-700 p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-gray-200">Compress {{ selected.size }} item(s)</h3>
                    <input v-model="compressModal.dest" placeholder="archive.zip" class="field w-full font-mono" autofocus />
                    <div class="flex gap-3">
                        <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                            <input type="radio" v-model="compressModal.format" value="zip" class="text-indigo-500" /> .zip
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                            <input type="radio" v-model="compressModal.format" value="tar.gz" class="text-indigo-500" /> .tar.gz
                        </label>
                    </div>
                    <div class="flex gap-2 justify-end">
                        <button @click="doCompress" class="btn-primary text-xs">Compress</button>
                        <button @click="compressModal.open = false" class="btn-secondary text-xs">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- ── Upload progress ───────────────────────────────────────────── -->
            <div v-if="uploading" class="fixed bottom-6 right-6 z-50 rounded-xl border border-gray-700 bg-gray-900 px-5 py-3.5 text-sm text-gray-300 flex items-center gap-3 shadow-xl">
                <svg class="h-4 w-4 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Uploading…
            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const props = defineProps({ accountId: Number });

const currentPath = ref('/');
const entries     = ref([]);
const loading     = ref(false);
const error       = ref(null);
const selected    = ref(new Set());
const uploading   = ref(false);

const editor = ref({ open: false, path: '', content: '', saving: false });
const renameModal  = ref({ open: false, entry: null, value: '' });
const mkdirModal   = ref({ open: false, value: '', isFile: false });
const chmodModal   = ref({ open: false, entry: null, name: '', value: '' });
const compressModal = ref({ open: false, dest: 'archive.zip', format: 'zip' });

const allSelected = computed(() =>
    entries.value.length > 0 && entries.value.every(e => selected.value.has(e.path))
);

const breadcrumbs = computed(() => {
    if (currentPath.value === '/') return [];
    return currentPath.value
        .split('/')
        .filter(Boolean)
        .reduce((acc, name, i, arr) => {
            acc.push({ name, path: '/' + arr.slice(0, i + 1).join('/') });
            return acc;
        }, []);
});

onMounted(() => load('/'));

async function load(path) {
    loading.value = true;
    error.value   = null;
    selected.value = new Set();
    try {
        const { data } = await axios.get(route('my.files.list'), { params: { path } });
        entries.value   = data.entries;
        currentPath.value = data.path;
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    } finally {
        loading.value = false;
    }
}

function navigate(path) { load(path); }
function reload() { load(currentPath.value); }
function goUp() {
    const parts = currentPath.value.split('/').filter(Boolean);
    parts.pop();
    load(parts.length ? '/' + parts.join('/') : '/');
}

function toggleSelect(path) {
    const s = new Set(selected.value);
    s.has(path) ? s.delete(path) : s.add(path);
    selected.value = s;
}
function toggleAll() {
    if (allSelected.value) {
        selected.value = new Set();
    } else {
        selected.value = new Set(entries.value.map(e => e.path));
    }
}

// ── Editor ─────────────────────────────────────────────────────────────────

async function openEditor(entry) {
    error.value = null;
    try {
        const { data } = await axios.get(route('my.files.read'), { params: { path: entry.path } });
        editor.value = { open: true, path: entry.path, content: data.content, saving: false };
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    }
}

async function saveFile() {
    editor.value.saving = true;
    try {
        await axios.post(route('my.files.write'), { path: editor.value.path, content: editor.value.content });
        editor.value.open = false;
        reload();
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    } finally {
        editor.value.saving = false;
    }
}

// ── New folder / file ──────────────────────────────────────────────────────

function promptMkdir() { mkdirModal.value = { open: true, value: '', isFile: false }; }
function promptNewFile() { mkdirModal.value = { open: true, value: '', isFile: true }; }

async function doMkdir() {
    if (!mkdirModal.value.value.trim()) return;
    const path = joinPath(currentPath.value, mkdirModal.value.value.trim());
    mkdirModal.value.open = false;
    try {
        if (mkdirModal.value.isFile) {
            await axios.post(route('my.files.write'), { path, content: '' });
        } else {
            await axios.post(route('my.files.mkdir'), { path });
        }
        reload();
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    }
}

// ── Rename ─────────────────────────────────────────────────────────────────

function promptRename(entry) {
    renameModal.value = { open: true, entry, value: entry.name };
}

async function doRename() {
    const { entry, value } = renameModal.value;
    if (!value.trim() || value === entry.name) { renameModal.value.open = false; return; }
    const to = joinPath(entry.path.substring(0, entry.path.lastIndexOf('/')), value.trim());
    renameModal.value.open = false;
    try {
        await axios.post(route('my.files.rename'), { from: entry.path, to });
        reload();
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    }
}

// ── Delete ─────────────────────────────────────────────────────────────────

async function confirmDelete(entry) {
    if (!confirm(`Delete "${entry.name}"?`)) return;
    try {
        await axios.delete(route('my.files.delete'), { params: { path: entry.path } });
        reload();
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    }
}

async function deleteSelected() {
    if (!selected.value.size) return;
    if (!confirm(`Delete ${selected.value.size} selected item(s)?`)) return;
    try {
        await Promise.all(
            [...selected.value].map(path => axios.delete(route('my.files.delete'), { params: { path } }))
        );
        reload();
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    }
}

// ── Chmod ──────────────────────────────────────────────────────────────────

function promptChmod(entry) {
    chmodModal.value = { open: true, entry, name: entry.name, value: entry.mode };
}

async function doChmod() {
    const { entry, value } = chmodModal.value;
    chmodModal.value.open = false;
    try {
        await axios.post(route('my.files.chmod'), { path: entry.path, mode: value });
        reload();
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    }
}

// ── Download ───────────────────────────────────────────────────────────────

function downloadEntry(entry) {
    window.location = route('my.files.download') + '?path=' + encodeURIComponent(entry.path);
}

// ── Compress ───────────────────────────────────────────────────────────────

function promptCompress() {
    compressModal.value = { open: true, dest: 'archive.zip', format: 'zip' };
}

async function doCompress() {
    const { dest, format } = compressModal.value;
    compressModal.value.open = false;
    const destPath = joinPath(currentPath.value, dest);
    try {
        await axios.post(route('my.files.compress'), {
            paths: [...selected.value],
            dest: destPath,
            format,
        });
        selected.value = new Set();
        reload();
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    }
}

// ── Extract ────────────────────────────────────────────────────────────────

async function extractEntry(entry) {
    try {
        await axios.post(route('my.files.extract'), {
            path: entry.path,
            dest: entry.path.substring(0, entry.path.lastIndexOf('/')),
        });
        reload();
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    }
}

// ── Upload ─────────────────────────────────────────────────────────────────

async function uploadFiles(event) {
    const fileList = event.target.files;
    if (!fileList.length) return;

    uploading.value = true;
    error.value = null;

    const formData = new FormData();
    formData.append('path', currentPath.value);
    for (const f of fileList) {
        formData.append('files[]', f);
    }

    try {
        await axios.post(route('my.files.upload'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        reload();
    } catch (e) {
        error.value = e.response?.data?.error ?? e.message;
    } finally {
        uploading.value = false;
        event.target.value = '';
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────

function joinPath(dir, name) {
    return (dir === '/' ? '' : dir) + '/' + name;
}

function isArchive(entry) {
    return /\.(zip|tar\.gz|tgz)$/i.test(entry.name);
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
}

function formatDate(iso) {
    return new Date(iso).toLocaleDateString('en-CA');
}
</script>

<style scoped>
@reference "tailwindcss";
.field        { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
.btn-primary  { @apply rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors; }
.btn-secondary { @apply rounded-lg border border-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-60 transition-colors; }
.tool-btn     { @apply rounded-lg p-1.5 text-gray-400 hover:text-gray-200 hover:bg-gray-800 transition-colors; }
.row-btn      { @apply rounded p-1 text-gray-500 hover:text-gray-300 hover:bg-gray-700/50 transition-colors; }
</style>
