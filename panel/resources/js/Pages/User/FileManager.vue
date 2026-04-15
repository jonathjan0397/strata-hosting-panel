<template>
    <AppLayout title="File Manager">
        <div class="flex flex-col gap-4 p-6">
            <PageHeader eyebrow="Files" title="File Manager" :description="`Browse and manage files in ${currentPath}.`" />

            <div class="flex flex-wrap items-center gap-2">
                <nav class="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto font-mono text-sm text-gray-400">
                    <button @click="navigate('/')" class="shrink-0 hover:text-gray-200">~</button>
                    <template v-for="crumb in breadcrumbs" :key="crumb.path">
                        <span class="text-gray-600">/</span>
                        <button @click="navigate(crumb.path)" class="shrink-0 hover:text-gray-200">{{ crumb.name }}</button>
                    </template>
                </nav>

                <div class="flex flex-wrap items-center gap-1.5">
                    <button @click="reload" class="tool-btn">Refresh</button>
                    <button @click="promptMkdir" class="tool-btn">New Folder</button>
                    <button @click="promptNewFile" class="tool-btn">New File</button>
                    <label class="tool-btn cursor-pointer">Upload<input type="file" multiple class="hidden" @change="uploadFiles" /></label>
                    <button v-if="hasClipboard" @click="pasteClipboard" class="tool-btn" :disabled="pasting">Paste</button>
                    <template v-if="selectedCount > 0">
                        <button v-if="selectedCount === 1" @click="promptRename(selectedEntries[0])" class="tool-btn">Rename</button>
                        <button v-if="selectedCount === 1" @click="promptChmod(selectedEntries[0])" class="tool-btn">Permissions</button>
                        <button @click="copySelected" class="tool-btn">Copy</button>
                        <button @click="cutSelected" class="tool-btn">Cut</button>
                        <button @click="promptCompress" class="tool-btn">Compress</button>
                        <button v-if="selectedArchives.length > 0" @click="promptExtract()" class="tool-btn">Extract</button>
                        <button @click="deleteSelected" class="tool-btn text-red-400 hover:bg-red-900/20 hover:text-red-300">Delete</button>
                    </template>
                </div>
            </div>

            <div v-if="hasClipboard" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-800 bg-indigo-950/30 px-4 py-3 text-sm text-indigo-200">
                <div>{{ clipboard.mode }} {{ clipboard.paths.length }} item(s) from <span class="font-mono">{{ clipboard.sourcePath }}</span></div>
                <div class="flex items-center gap-2">
                    <button @click="pasteClipboard" class="btn-primary px-3 py-1.5 text-xs" :disabled="pasting">{{ pasting ? 'Pasting…' : 'Paste Here' }}</button>
                    <button @click="clearClipboard" class="btn-secondary px-3 py-1.5 text-xs">Clear</button>
                </div>
            </div>

            <div v-if="error" class="rounded-xl border border-red-700 bg-red-900/20 px-4 py-2.5 text-sm text-red-300">{{ error }}</div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading…</div>
                <template v-else>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-800">
                                <th class="w-8 px-3 py-2.5"><input type="checkbox" :checked="allSelected" @change="toggleAll" class="rounded border-gray-600 bg-gray-800 text-indigo-500" /></th>
                                <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-400">Name</th>
                                <th class="w-24 px-3 py-2.5 text-right text-xs font-semibold text-gray-400">Size</th>
                                <th class="w-16 px-3 py-2.5 text-left text-xs font-semibold text-gray-400">Mode</th>
                                <th class="w-36 px-3 py-2.5 text-left text-xs font-semibold text-gray-400">Modified</th>
                                <th class="w-64 px-3 py-2.5 text-right text-xs font-semibold text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-if="currentPath !== '/'">
                                <td></td>
                                <td class="px-3 py-2.5" colspan="4"><button @click="goUp" class="text-gray-400 hover:text-gray-200">..</button></td>
                                <td></td>
                            </tr>
                            <tr v-for="entry in entries" :key="entry.path" class="group hover:bg-gray-800/40" @dblclick="entry.is_dir ? navigate(entry.path) : openEditor(entry)">
                                <td class="px-3 py-2"><input type="checkbox" :checked="selected.has(entry.path)" @change="toggleSelect(entry.path)" class="rounded border-gray-600 bg-gray-800 text-indigo-500" /></td>
                                <td class="px-3 py-2">
                                    <button v-if="entry.is_dir" @click="navigate(entry.path)" class="max-w-xs truncate text-left font-medium text-gray-200 hover:text-indigo-300">{{ entry.name }}</button>
                                    <span v-else class="max-w-xs truncate text-gray-300">{{ entry.name }}</span>
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-xs text-gray-400">{{ entry.is_dir ? '—' : formatBytes(entry.size) }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ entry.mode }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ formatDate(entry.mod_time) }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap justify-end gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                                        <button v-if="!entry.is_dir" @click="openEditor(entry)" class="row-btn">Edit</button>
                                        <button @click="copySingle(entry)" class="row-btn">Copy</button>
                                        <button v-if="isArchive(entry)" @click="promptExtract(entry)" class="row-btn">Extract</button>
                                        <button v-if="!entry.is_dir" @click="downloadEntry(entry)" class="row-btn">Download</button>
                                        <button @click="promptRename(entry)" class="row-btn">Rename</button>
                                        <button @click="promptChmod(entry)" class="row-btn">Permissions</button>
                                        <button @click="confirmDelete(entry)" class="row-btn text-red-400 hover:bg-red-900/20 hover:text-red-300">Delete</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!entries.length"><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">This directory is empty.</td></tr>
                        </tbody>
                    </table>
                </template>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="editor.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                <div class="flex max-h-[90vh] w-full max-w-4xl flex-col rounded-2xl border border-gray-700 bg-gray-900">
                    <div class="flex items-center justify-between border-b border-gray-800 px-5 py-3.5">
                        <span class="font-mono text-sm text-gray-300">{{ editor.path }}</span>
                        <div class="flex gap-2">
                            <button @click="saveFile" :disabled="editor.saving" class="btn-primary px-3 py-1.5 text-xs">{{ editor.saving ? 'Saving…' : 'Save' }}</button>
                            <button @click="editor.open = false" class="btn-secondary px-3 py-1.5 text-xs">Close</button>
                        </div>
                    </div>
                    <textarea v-model="editor.content" class="min-h-0 flex-1 resize-none bg-gray-950 p-4 font-mono text-sm text-gray-100 focus:outline-none"></textarea>
                </div>
            </div>

            <div v-if="renameModal.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                <div class="w-full max-w-sm space-y-4 rounded-2xl border border-gray-700 bg-gray-900 p-6">
                    <h3 class="text-sm font-semibold text-gray-200">Rename</h3>
                    <input v-model="renameModal.value" @keyup.enter="doRename" class="field w-full" autofocus />
                    <div class="flex justify-end gap-2"><button @click="doRename" class="btn-primary px-4 py-2 text-xs">Rename</button><button @click="renameModal.open = false" class="btn-secondary px-4 py-2 text-xs">Cancel</button></div>
                </div>
            </div>

            <div v-if="mkdirModal.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                <div class="w-full max-w-sm space-y-4 rounded-2xl border border-gray-700 bg-gray-900 p-6">
                    <h3 class="text-sm font-semibold text-gray-200">{{ mkdirModal.isFile ? 'New File' : 'New Folder' }}</h3>
                    <input v-model="mkdirModal.value" @keyup.enter="doMkdir" class="field w-full" autofocus />
                    <div class="flex justify-end gap-2"><button @click="doMkdir" class="btn-primary px-4 py-2 text-xs">Create</button><button @click="mkdirModal.open = false" class="btn-secondary px-4 py-2 text-xs">Cancel</button></div>
                </div>
            </div>

            <div v-if="chmodModal.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                <div class="w-full max-w-lg space-y-4 rounded-2xl border border-gray-700 bg-gray-900 p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div><h3 class="text-sm font-semibold text-gray-200">Permissions</h3><p class="mt-1 font-mono text-xs text-gray-400">{{ chmodModal.name }}</p></div>
                        <input v-model="chmodModal.value" @input="syncPermsFromMode" maxlength="4" class="field w-28 text-center font-mono text-lg tracking-widest" />
                    </div>
                    <table class="w-full overflow-hidden rounded-xl border border-gray-800 text-sm">
                        <thead class="bg-gray-950/70"><tr><th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Scope</th><th class="px-4 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Read</th><th class="px-4 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Write</th><th class="px-4 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Execute</th></tr></thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="scope in permissionScopes" :key="scope.key">
                                <td class="px-4 py-3 text-gray-300">{{ scope.label }}</td>
                                <td class="px-4 py-3 text-center"><input type="checkbox" :checked="chmodModal.perms[scope.key].read" @change="togglePermission(scope.key, 'read')" class="rounded border-gray-600 bg-gray-800 text-indigo-500" /></td>
                                <td class="px-4 py-3 text-center"><input type="checkbox" :checked="chmodModal.perms[scope.key].write" @change="togglePermission(scope.key, 'write')" class="rounded border-gray-600 bg-gray-800 text-indigo-500" /></td>
                                <td class="px-4 py-3 text-center"><input type="checkbox" :checked="chmodModal.perms[scope.key].execute" @change="togglePermission(scope.key, 'execute')" class="rounded border-gray-600 bg-gray-800 text-indigo-500" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="flex flex-wrap gap-2"><button @click="applyPermissionPreset('0644')" class="btn-secondary px-3 py-1.5 text-xs">0644 file</button><button @click="applyPermissionPreset('0755')" class="btn-secondary px-3 py-1.5 text-xs">0755 folder</button><button @click="applyPermissionPreset('0600')" class="btn-secondary px-3 py-1.5 text-xs">0600 private</button></div>
                    <div class="flex justify-end gap-2"><button @click="doChmod" class="btn-primary px-4 py-2 text-xs">Apply</button><button @click="chmodModal.open = false" class="btn-secondary px-4 py-2 text-xs">Cancel</button></div>
                </div>
            </div>

            <div v-if="compressModal.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                <div class="w-full max-w-sm space-y-4 rounded-2xl border border-gray-700 bg-gray-900 p-6">
                    <h3 class="text-sm font-semibold text-gray-200">Compress {{ selectedCount }} item(s)</h3>
                    <input v-model="compressModal.dest" class="field w-full font-mono" autofocus />
                    <div class="flex gap-3"><label class="flex items-center gap-2 text-sm text-gray-300"><input type="radio" v-model="compressModal.format" value="zip" class="text-indigo-500" /> .zip</label><label class="flex items-center gap-2 text-sm text-gray-300"><input type="radio" v-model="compressModal.format" value="tar.gz" class="text-indigo-500" /> .tar.gz</label></div>
                    <div class="flex justify-end gap-2"><button @click="doCompress" class="btn-primary px-4 py-2 text-xs">Compress</button><button @click="compressModal.open = false" class="btn-secondary px-4 py-2 text-xs">Cancel</button></div>
                </div>
            </div>

            <div v-if="extractModal.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                <div class="w-full max-w-md space-y-4 rounded-2xl border border-gray-700 bg-gray-900 p-6">
                    <h3 class="text-sm font-semibold text-gray-200">Extract {{ extractModal.paths.length }} archive<span v-if="extractModal.paths.length !== 1">s</span></h3>
                    <div class="rounded-lg border border-gray-800 bg-gray-950/70 px-3 py-2 text-xs text-gray-400"><div class="uppercase tracking-wide text-gray-500">Source</div><div class="mt-1 break-all font-mono text-gray-300">{{ extractModal.paths.join(', ') }}</div></div>
                    <input v-model="extractModal.dest" @keyup.enter="doExtract" class="field w-full font-mono" autofocus />
                    <div class="flex justify-end gap-2"><button @click="doExtract" class="btn-primary px-4 py-2 text-xs">Extract</button><button @click="extractModal.open = false" class="btn-secondary px-4 py-2 text-xs">Cancel</button></div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const clipboardStorageKey = 'strata-file-manager-clipboard';
const currentPath = ref('/'); const entries = ref([]); const loading = ref(false); const error = ref(null);
const selected = ref(new Set()); const uploading = ref(false); const pasting = ref(false);
const clipboard = ref({ mode: null, paths: [], sourcePath: '/' });
const editor = ref({ open: false, path: '', content: '', saving: false });
const renameModal = ref({ open: false, entry: null, value: '' });
const mkdirModal = ref({ open: false, value: '', isFile: false });
const chmodModal = ref({ open: false, entry: null, name: '', value: '0644', perms: defaultPerms() });
const compressModal = ref({ open: false, dest: 'archive.zip', format: 'zip' });
const extractModal = ref({ open: false, paths: [], dest: '/' });
const permissionScopes = [{ key: 'owner', label: 'Owner' }, { key: 'group', label: 'Group' }, { key: 'world', label: 'Public' }];

const breadcrumbs = computed(() => currentPath.value === '/' ? [] : currentPath.value.split('/').filter(Boolean).reduce((acc, name, index, parts) => { acc.push({ name, path: '/' + parts.slice(0, index + 1).join('/') }); return acc; }, []));
const allSelected = computed(() => entries.value.length > 0 && entries.value.every((entry) => selected.value.has(entry.path)));
const selectedEntries = computed(() => entries.value.filter((entry) => selected.value.has(entry.path)));
const selectedCount = computed(() => selected.value.size);
const selectedArchives = computed(() => selectedEntries.value.filter((entry) => isArchive(entry)));
const hasClipboard = computed(() => clipboard.value.paths.length > 0 && !!clipboard.value.mode);

onMounted(() => { hydrateClipboard(); load('/'); });

async function load(path) { loading.value = true; error.value = null; selected.value = new Set(); try { const { data } = await axios.get(route('my.files.list'), { params: { path } }); entries.value = data.entries; currentPath.value = data.path; } catch (e) { error.value = e.response?.data?.error ?? e.message; } finally { loading.value = false; } }
const navigate = (path) => load(path); const reload = () => load(currentPath.value);
function goUp() { const parts = currentPath.value.split('/').filter(Boolean); parts.pop(); load(parts.length ? '/' + parts.join('/') : '/'); }
function toggleSelect(path) { const next = new Set(selected.value); next.has(path) ? next.delete(path) : next.add(path); selected.value = next; }
function toggleAll() { selected.value = allSelected.value ? new Set() : new Set(entries.value.map((entry) => entry.path)); }

async function openEditor(entry) { try { const { data } = await axios.get(route('my.files.read'), { params: { path: entry.path } }); editor.value = { open: true, path: entry.path, content: data.content, saving: false }; } catch (e) { error.value = e.response?.data?.error ?? e.message; } }
async function saveFile() { editor.value.saving = true; try { await axios.post(route('my.files.write'), { path: editor.value.path, content: editor.value.content }); editor.value.open = false; reload(); } catch (e) { error.value = e.response?.data?.error ?? e.message; } finally { editor.value.saving = false; } }

const promptMkdir = () => { mkdirModal.value = { open: true, value: '', isFile: false }; };
const promptNewFile = () => { mkdirModal.value = { open: true, value: '', isFile: true }; };
async function doMkdir() { const name = mkdirModal.value.value.trim(); if (!name) return; const path = joinPath(currentPath.value, name); mkdirModal.value.open = false; try { if (mkdirModal.value.isFile) { await axios.post(route('my.files.write'), { path, content: '' }); } else { await axios.post(route('my.files.mkdir'), { path }); } reload(); } catch (e) { error.value = e.response?.data?.error ?? e.message; } }

function promptRename(entry) { renameModal.value = { open: true, entry, value: entry.name }; }
async function doRename() { const { entry, value } = renameModal.value; renameModal.value.open = false; const nextName = value.trim(); if (!entry || !nextName || nextName === entry.name) return; try { await axios.post(route('my.files.rename'), { from: entry.path, to: joinPath(parentPath(entry.path), nextName) }); reload(); } catch (e) { error.value = e.response?.data?.error ?? e.message; } }

function promptChmod(entry) { const mode = normalizeMode(entry.mode); chmodModal.value = { open: true, entry, name: entry.name, value: mode, perms: permsFromMode(mode) }; }
function syncPermsFromMode() { chmodModal.value.value = normalizeMode(chmodModal.value.value); chmodModal.value.perms = permsFromMode(chmodModal.value.value); }
function togglePermission(scope, bit) { const next = clonePerms(chmodModal.value.perms); next[scope][bit] = !next[scope][bit]; chmodModal.value.perms = next; chmodModal.value.value = modeFromPerms(next); }
function applyPermissionPreset(mode) { chmodModal.value.value = normalizeMode(mode); chmodModal.value.perms = permsFromMode(chmodModal.value.value); }
async function doChmod() { const { entry } = chmodModal.value; const mode = normalizeMode(chmodModal.value.value); chmodModal.value.open = false; if (!entry) return; try { await axios.post(route('my.files.chmod'), { path: entry.path, mode }); reload(); } catch (e) { error.value = e.response?.data?.error ?? e.message; } }

function setClipboard(mode, paths) { clipboard.value = { mode, paths: [...new Set(paths)], sourcePath: currentPath.value }; persistClipboard(); }
const copySelected = () => setClipboard('copy', [...selected.value]); const cutSelected = () => setClipboard('cut', [...selected.value]); const copySingle = (entry) => setClipboard('copy', [entry.path]);
function clearClipboard() { clipboard.value = { mode: null, paths: [], sourcePath: '/' }; persistClipboard(); }
async function pasteClipboard() {
    if (!hasClipboard.value || pasting.value) return;
    pasting.value = true; error.value = null;
    try {
        const usedNames = new Set(entries.value.map((entry) => entry.name));
        for (const source of clipboard.value.paths) {
            const destination = destinationForPaste(source, clipboard.value.mode, usedNames);
            if (!destination) continue;
            if (clipboard.value.mode === 'copy') await axios.post(route('my.files.copy'), { from: source, to: destination });
            else await axios.post(route('my.files.rename'), { from: source, to: destination });
            usedNames.add(baseName(destination));
        }
        if (clipboard.value.mode === 'cut') clearClipboard();
        selected.value = new Set(); reload();
    } catch (e) { error.value = e.response?.data?.error ?? e.message; } finally { pasting.value = false; }
}

async function confirmDelete(entry) { if (!confirm(`Delete "${entry.name}"?`)) return; try { await axios.delete(route('my.files.delete'), { params: { path: entry.path } }); reload(); } catch (e) { error.value = e.response?.data?.error ?? e.message; } }
async function deleteSelected() { if (!selectedCount.value || !confirm(`Delete ${selectedCount.value} selected item(s)?`)) return; try { await Promise.all([...selected.value].map((path) => axios.delete(route('my.files.delete'), { params: { path } }))); reload(); } catch (e) { error.value = e.response?.data?.error ?? e.message; } }
const downloadEntry = (entry) => { window.location = route('my.files.download') + '?path=' + encodeURIComponent(entry.path); };

const promptCompress = () => { compressModal.value = { open: true, dest: 'archive.zip', format: 'zip' }; };
async function doCompress() { const { dest, format } = compressModal.value; compressModal.value.open = false; try { await axios.post(route('my.files.compress'), { paths: [...selected.value], dest: joinPath(currentPath.value, dest), format }); selected.value = new Set(); reload(); } catch (e) { error.value = e.response?.data?.error ?? e.message; } }
function promptExtract(entry = null) { const paths = entry ? [entry.path] : selectedArchives.value.map((item) => item.path); if (!paths.length) return; extractModal.value = { open: true, paths, dest: currentPath.value }; }
async function doExtract() { const { paths, dest } = extractModal.value; extractModal.value.open = false; try { await Promise.all(paths.map((path) => axios.post(route('my.files.extract'), { path, dest }))); selected.value = new Set(); reload(); } catch (e) { error.value = e.response?.data?.error ?? e.message; } }

async function uploadFiles(event) { const fileList = event.target.files; if (!fileList.length) return; uploading.value = true; const formData = new FormData(); formData.append('path', currentPath.value); for (const file of fileList) formData.append('files[]', file); try { await axios.post(route('my.files.upload'), formData, { headers: { 'Content-Type': 'multipart/form-data' } }); reload(); } catch (e) { error.value = e.response?.data?.error ?? e.message; } finally { uploading.value = false; event.target.value = ''; } }

function hydrateClipboard() { try { const raw = window.localStorage.getItem(clipboardStorageKey); if (!raw) return; const parsed = JSON.parse(raw); if (Array.isArray(parsed.paths) && typeof parsed.mode === 'string') clipboard.value = { mode: parsed.mode, paths: parsed.paths, sourcePath: typeof parsed.sourcePath === 'string' ? parsed.sourcePath : '/' }; } catch { clearClipboard(); } }
function persistClipboard() { window.localStorage.setItem(clipboardStorageKey, JSON.stringify(clipboard.value)); }

const joinPath = (dir, name) => (dir === '/' ? '' : dir) + '/' + String(name).replace(/^\/+/, '');
function parentPath(path) { const parts = path.split('/').filter(Boolean); parts.pop(); return parts.length ? '/' + parts.join('/') : '/'; }
const baseName = (path) => path.split('/').filter(Boolean).pop() ?? '';
function destinationForPaste(sourcePath, mode, usedNames) { const sourceName = baseName(sourcePath); const sourceParent = parentPath(sourcePath); if (mode === 'cut' && sourceParent === currentPath.value) return null; const targetName = mode === 'copy' ? uniqueCopyName(sourceName, usedNames) : uniqueMoveName(sourceName, usedNames); return joinPath(currentPath.value, targetName); }
function uniqueMoveName(name, usedNames) { return usedNames.has(name) ? uniqueCopyName(name, usedNames) : name; }
function uniqueCopyName(name, usedNames) { const { base, ext } = splitName(name); let attempt = 0; let candidate = name; while (usedNames.has(candidate)) { attempt += 1; candidate = `${base}-copy${attempt > 1 ? '-' + attempt : ''}${ext}`; } return candidate; }
function splitName(name) { if (name.toLowerCase().endsWith('.tar.gz')) return { base: name.slice(0, -7), ext: '.tar.gz' }; const index = name.lastIndexOf('.'); return index <= 0 ? { base: name, ext: '' } : { base: name.slice(0, index), ext: name.slice(index) }; }
const isArchive = (entry) => /\.(zip|tar\.gz|tgz)$/i.test(entry.name);
function formatBytes(bytes) { if (!bytes) return '0 B'; const units = ['B', 'KB', 'MB', 'GB', 'TB']; const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1); return `${(bytes / Math.pow(1024, index)).toFixed(index > 0 ? 1 : 0)} ${units[index]}`; }
const formatDate = (iso) => new Date(iso).toLocaleDateString('en-CA');
function defaultPerms() { return { owner: { read: true, write: true, execute: false }, group: { read: true, write: false, execute: false }, world: { read: true, write: false, execute: false } }; }
const clonePerms = (perms) => JSON.parse(JSON.stringify(perms));
const normalizeMode = (value) => String(value ?? '').replace(/[^0-7]/g, '').slice(-4).padStart(4, '0');
function permsFromMode(mode) { const digits = normalizeMode(mode).slice(-3).split('').map((digit) => Number.parseInt(digit, 10)); const scopes = ['owner', 'group', 'world']; const next = defaultPerms(); scopes.forEach((scope, index) => { const value = digits[index] ?? 0; next[scope] = { read: (value & 4) !== 0, write: (value & 2) !== 0, execute: (value & 1) !== 0 }; }); return next; }
function modeFromPerms(perms) { const scopes = ['owner', 'group', 'world']; return `0${scopes.map((scope) => `${(perms[scope].read ? 4 : 0) + (perms[scope].write ? 2 : 0) + (perms[scope].execute ? 1 : 0)}`).join('')}`; }
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
.btn-primary { @apply rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60; }
.btn-secondary { @apply rounded-lg border border-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-300 transition-colors hover:bg-gray-800 disabled:opacity-60; }
.tool-btn { @apply rounded-lg border border-gray-700 px-3 py-1.5 text-sm text-gray-300 transition-colors hover:bg-gray-800 hover:text-gray-100 disabled:cursor-not-allowed disabled:opacity-40; }
.row-btn { @apply rounded border border-gray-700 px-2 py-1 text-xs text-gray-400 transition-colors hover:bg-gray-800 hover:text-gray-200; }
</style>
