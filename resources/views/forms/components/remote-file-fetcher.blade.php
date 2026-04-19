<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $key = $getKey();
        $statePath = $getStatePath();
        $storedPath = $getState();
        $updateAttachmentFieldStatePath = $field->getUpdateAttachmentFieldStatePath();
    @endphp

    <div
        x-data="{
            url: '',
            filename: '',
            status: null,
            message: '',
            async fetch() {
                if (!this.url || !this.filename) {
                    this.status = 'error';
                    this.message = 'URL and filename are required.';
                    return;
                }
                this.status = 'loading';
                this.message = '';
                const result = await $wire.callSchemaComponentMethod(
                    @js($key),
                    'fetchFile',
                    { url: this.url, filename: this.filename }
                );
                if (result.success) {
                    this.status = 'success';
                    this.message = result.path;
                    $wire.set(@js($statePath), result.path);
                    @if($updateAttachmentFieldStatePath)
                    $wire.set(@js($updateAttachmentFieldStatePath), result.attachment_id);
                    @endif
                } else {
                    this.status = 'error';
                    this.message = result.error;
                }
            }
        }"
        class="space-y-3"
    >
        <div class="space-y-3">
            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Remote URL</span>
                </label>
                <div class="mt-1">
                    <input
                        type="url"
                        x-model="url"
                        placeholder="https://example.com/image.jpg"
                        class="fi-input block w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-950 shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary-600 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                    />
                </div>
            </div>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                File will be saved to: <span class="font-mono font-bold text-xs">{{ $field->getFolder() }}/</span>
            </p>

            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Local Filename</span>
                </label>
                <div class="mt-1">
                    <input
                        type="text"
                        x-model="filename"
                        placeholder="image.jpg"
                        class="fi-input block w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-950 shadow-sm outline-none transition duration-75 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary-600 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                    />
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button
                type="button"
                x-on:click="fetch"
                :disabled="status === 'loading'"
                class="fi-btn fi-btn-size-md fi-btn-color-primary fi-color-custom fi-color-primary rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 disabled:cursor-not-allowed disabled:opacity-70"
            >
                <span x-show="status !== 'loading'">Fetch File</span>
                <span x-show="status === 'loading'" x-cloak>Fetching&hellip;</span>
            </button>

            <p
                x-show="status === 'success'"
                x-cloak
                class="text-sm text-success-600 dark:text-success-400"
            >
                <span x-text="'Stored at: ' + message"></span>
            </p>

            <p
                x-show="status === 'error'"
                x-cloak
                class="text-sm text-danger-600 dark:text-danger-400"
                x-text="message"
            ></p>
        </div>
    </div>
</x-dynamic-component>
