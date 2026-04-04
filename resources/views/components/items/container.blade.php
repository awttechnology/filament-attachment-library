@php
    use AwtTechnology\FilamentAttachmentLibrary\Enums\Layout;
    use AwtTechnology\FilamentAttachmentLibrary\ViewModels\AttachmentViewModel;
    use AwtTechnology\FilamentAttachmentLibrary\ViewModels\DirectoryViewModel;

   /**
    * @var \Illuminate\Support\Collection<\VanOns\LaravelAttachmentLibrary\Models\Attachment> $attachments
    * @var Layout $layout
    * @var array $selected
    */
@endphp

@props([ 'layout' ])

<div
    @class([
        'grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-4' => $layout === Layout::GRID,
        'grid gap-4' => $layout === Layout::LIST,
    ])
>
    {{ $slot }}
</div>
