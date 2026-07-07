<?php

namespace AwtTechnology\FilamentAttachmentLibrary\Tests\Fixtures;

use AwtTechnology\FilamentAttachmentLibrary\Forms\Components\AttachmentField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * Minimal Filament form hosting an AttachmentField, mirroring the README's
 * "store attachment ID in a model column" usage.
 */
class EditPostForm extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    public TestPost $post;

    public bool $storeAsUrl = false;

    public function mount(TestPost $post, bool $storeAsUrl = false): void
    {
        $this->post = $post;
        $this->storeAsUrl = $storeAsUrl;
        $this->form->fill($this->post->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        $field = AttachmentField::make('featured_image_id');

        if ($this->storeAsUrl) {
            $field->storeAsUrl();
        }

        return $schema->components([$field])
            ->statePath('data')
            ->model($this->post);
    }

    public function save(): void
    {
        $this->post->update($this->form->getState());
    }

    public function render()
    {
        return view('edit-post-form');
    }
}
