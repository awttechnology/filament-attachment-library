@php
    use Filament\Support\Facades\FilamentAsset;
    use Illuminate\Support\Js;

    $statePath = $getStatePath();
    $hash = md5($statePath);
    $cropSourceUrl = $getCropSourceUrl();

    $mouseWheelZoom = $getMouseWheelZoom();
    $mouseWheelZoomJs = $mouseWheelZoom === 'ctrl' ? "'ctrl'" : ($mouseWheelZoom ? 'true' : 'false');

    $cssHref = FilamentAsset::getStyleHref('croppie', package: 'awttechnology/filament-attachment-library');
    $jsSrc = FilamentAsset::getScriptSrc('croppie', package: 'awttechnology/filament-attachment-library');
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
    x-data="{ state: $wire.entangle('{{ $statePath }}').live }"
>
    <div
        {{-- Dynamic (md5-suffixed) event names live on a plain div: blade
             components mangle dynamic attribute names. --}}
        x-on:attachment-removed="state = null"
        x-on:attachments-selected-{{ $hash }}.window="state = $event.detail.selected"
        x-on:set-cropped-attachment-{{ $hash }}.window="
            state = $event.detail.attachmentId;
            $dispatch('close-modal', { id: 'croppie-{{ $hash }}' });
        "
    >
        <x-filament-attachment-library::items.field
            :attachments="$getAttachments()"
            :statePath="$statePath"
            :reorderable="false"
        />

        <div class="mt-2 flex flex-wrap gap-2">
            <x-filament::button
                x-on:click="$dispatch('open-attachment-modal', {
                    mime: {{ json_encode($getMime()) }},
                    selected: state,
                    multiple: false,
                    statePath: {{ json_encode($statePath) }},
                    disableMimeFilter: {{ json_encode($getMime() !== null) }},
                    directory: {{ json_encode($getDirectory()) }},
                }); $dispatch('open-modal', { id: 'attachment-modal' })"
                icon="heroicon-o-document"
                :disabled="$isDisabled()"
                @class(['opacity-50 pointer-events-none' => $isDisabled()])
            >
                {{ __('filament-attachment-library::views.field.pick') }}
            </x-filament::button>

            @if ($cropSourceUrl)
                <x-filament::button
                    color="gray"
                    icon="heroicon-o-scissors"
                    x-on:click="$dispatch('open-modal', { id: 'croppie-{{ $hash }}', url: {{ Js::from($cropSourceUrl) }}, sourceId: state })"
                    :disabled="$isDisabled()"
                    @class(['opacity-50 pointer-events-none' => $isDisabled()])
                >
                    {{ __('filament-attachment-library::views.field.crop.button') }}
                </x-filament::button>
            @endif
        </div>

        <x-filament::modal
            id="croppie-{{ $hash }}"
            :width="$getModalSize()"
            icon="heroicon-o-scissors"
            :close-by-clicking-away="false"
        >
            <x-slot name="heading">
                {{ $getModalTitle() }}
            </x-slot>

            @if ($getModalDescription())
                <x-slot name="description">
                    {{ $getModalDescription() }}
                </x-slot>
            @endif

            {{-- Self-contained Croppie island: everything it needs lives in its own
                 x-data + window events, so it is immune to the modal's Alpine scope
                 boundary. Croppie is initialised on `x-modal-opened` (i.e. once the
                 container is actually visible/sized) — initialising a hidden element
                 yields a broken, empty cropper. --}}
            <div
                wire:ignore
                x-data="{
                    croppie: null,
                    isUploading: false,
                    sourceUrl: null,
                    sourceId: null,
                    cssHref: {{ Js::from($cssHref) }},
                    jsSrc: {{ Js::from($jsSrc) }},

                    async ensureCroppie() {
                        if (! document.getElementById('fal-croppie-css')) {
                            const link = document.createElement('link');
                            link.id = 'fal-croppie-css';
                            link.rel = 'stylesheet';
                            link.href = this.cssHref;
                            document.head.appendChild(link);
                        }
                        if (window.Croppie) return;
                        if (! window.__falCroppieLoading) {
                            window.__falCroppieLoading = new Promise((resolve, reject) => {
                                const script = document.createElement('script');
                                script.src = this.jsSrc;
                                script.onload = () => resolve();
                                script.onerror = () => reject(new Error('Failed to load Croppie'));
                                document.head.appendChild(script);
                            });
                        }
                        await window.__falCroppieLoading;
                    },

                    async initCroppie() {
                        if (! this.sourceUrl) return;
                        try {
                            await this.ensureCroppie();
                        } catch (e) {
                            console.error(e);
                            return;
                        }
                        {{-- rAF: let the modal's display:block + layout settle so the
                             boundary has real dimensions before Croppie measures it. --}}
                        requestAnimationFrame(() => {
                            const el = this.$refs.cropper;
                            if (! el) return;
                            if (this.croppie) {
                                try { this.croppie.destroy(); } catch (e) {}
                                this.croppie = null;
                            }
                            el.innerHTML = '';
                            this.croppie = new Croppie(el, {
                                viewport: { width: {{ $getViewportWidth() }}, height: {{ $getViewportHeight() }}, type: '{{ $getViewportType() }}' },
                                boundary: { width: {{ $getBoundaryWidth() }}, height: {{ $getBoundaryHeight() }} },
                                showZoomer: {{ $getShowZoomer() ? 'true' : 'false' }},
                                enableResize: {{ $getEnableResize() ? 'true' : 'false' }},
                                enableZoom: {{ $getEnableZoom() ? 'true' : 'false' }},
                                enableOrientation: {{ $getEnableOrientation() ? 'true' : 'false' }},
                                mouseWheelZoom: {{ $mouseWheelZoomJs }},
                            });
                            this.croppie.bind({ url: this.sourceUrl });
                        });
                    },

                    teardown() {
                        this.isUploading = false;
                        if (this.croppie) {
                            try { this.croppie.destroy(); } catch (e) {}
                            this.croppie = null;
                        }
                    },

                    async save() {
                        if (! this.croppie) return;
                        this.isUploading = true;
                        try {
                            const base64 = await this.croppie.result({
                                type: 'base64',
                                size: '{{ $getImageSize() }}',
                                format: '{{ $getImageFormat() }}',
                                circle: {{ $getForceCircleResult() ? 'true' : 'false' }},
                            });
                            this.$dispatch('upload-cropped-attachment', {
                                base64Image: base64,
                                statePath: '{{ $hash }}',
                                disk: {{ Js::from($getDiskName()) }},
                                directory: {{ Js::from($getDirectory()) }},
                                imageFormat: '{{ $getImageFormat() }}',
                                sourceAttachmentId: this.sourceId,
                                imageName: {{ Js::from($getImageName()) }},
                            });
                        } catch (e) {
                            console.error(e);
                            this.isUploading = false;
                        }
                    },
                }"
                x-on:open-modal.window="
                    if ($event.detail.id === 'croppie-{{ $hash }}') {
                        sourceUrl = $event.detail.url;
                        sourceId = $event.detail.sourceId;
                    }
                "
                x-on:x-modal-opened.window="if ($event.detail.id === 'croppie-{{ $hash }}') initCroppie()"
                x-on:close-modal.window="if ($event.detail.id === 'croppie-{{ $hash }}') teardown()"
            >
                <div class="flex w-full justify-center">
                    <div x-ref="cropper"></div>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <x-filament::button
                        color="gray"
                        x-on:click="$dispatch('close-modal', { id: 'croppie-{{ $hash }}' })"
                    >
                        {{ __('filament-attachment-library::views.field.crop.cancel') }}
                    </x-filament::button>

                    <x-filament::button
                        x-on:click="save()"
                        x-bind:disabled="isUploading"
                    >
                        <span class="flex items-center gap-2">
                            <x-filament::loading-indicator class="h-4 w-4" x-show="isUploading" x-cloak />
                            <span x-text="isUploading
                                ? {{ Js::from(__('filament-attachment-library::views.field.crop.saving')) }}
                                : {{ Js::from(__('filament-attachment-library::views.field.crop.save')) }}"></span>
                        </span>
                    </x-filament::button>
                </div>
            </div>
        </x-filament::modal>

        @livewire('attachment-cropper', ['statePath' => $statePath], key('attachment-cropper-' . $hash))
    </div>
</x-dynamic-component>
