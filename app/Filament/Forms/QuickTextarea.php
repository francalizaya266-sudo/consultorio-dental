<?php

namespace App\Filament\Forms;

use App\Enums\SnippetField;
use App\Enums\SnippetStatus;
use App\Models\TextSnippet;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Renderless;

/**
 * Texto de la historia con escritura rápida: opciones del campo (las más usadas primero)
 * que se agregan con un toque, atajos tipo /limp que se expanden al escribir un espacio,
 * y "Guardar como opción" para reutilizar lo escrito.
 */
class QuickTextarea extends Textarea
{
    /** Opciones visibles como botones; el resto aparece con "Más". */
    public const VISIBLE_OPTIONS = 6;

    protected SnippetField|Closure|null $snippetField = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autosize();
        $this->rows(3);

        $this->extraFieldWrapperAttributes(fn (QuickTextarea $component) => $component->isDisabled() ? [] : [
            'x-data' => $component->alpineData(),
            'x-on:keydown' => 'expand($event)',
        ]);

        $this->belowContent(fn (QuickTextarea $component) => $component->isDisabled() ? null : $component->optionsHtml());

        $this->hintAction(fn (QuickTextarea $component) => $component->saveAsOptionAction());
    }

    public function snippets(SnippetField|Closure $field): static
    {
        $this->snippetField = $field;

        return $this;
    }

    public function getSnippetField(): SnippetField
    {
        return $this->evaluate($this->snippetField) ?? SnippetField::Any;
    }

    /** Se llama desde el navegador cada vez que se usa una opción o un atajo. */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function recordSnippetUse(int $id): void
    {
        $this->available()->firstWhere('id', $id)?->recordUse(auth()->user());
    }

    /** @return Collection<int, TextSnippet> */
    public function available(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return TextSnippet::query()->availableFor($user, $this->getSnippetField())->get();
    }

    /** Atajos de todos los campos (los atajos sirven en cualquier parte de la historia). */
    public function shortcuts(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return TextSnippet::query()
            ->where('status', SnippetStatus::Active)
            ->whereNotNull('shortcut')
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $user->getKey()))
            ->orderByRaw('user_id is null')
            ->get()
            ->unique('shortcut')
            ->mapWithKeys(fn (TextSnippet $snippet) => [mb_strtolower($snippet->shortcut) => ['id' => $snippet->getKey(), 'body' => $snippet->body]])
            ->all();
    }

    public function alpineData(): string
    {
        $key = Js::from($this->getKey());
        $shortcuts = Js::from($this->shortcuts());

        return <<<JS
            {
                shortcuts: {$shortcuts},
                more: false,
                used(id) {
                    \$wire.callSchemaComponentMethod({$key}, 'recordSnippetUse', { id: id })
                },
                write(textarea, start, end, text) {
                    textarea.focus()
                    textarea.setRangeText(text, start, end, 'end')
                    textarea.dispatchEvent(new Event('input', { bubbles: true }))
                },
                insert(button, text, id) {
                    const textarea = button.closest('[data-field-wrapper]').querySelector('textarea')
                    const value = textarea.value
                    const start = document.activeElement === textarea ? textarea.selectionStart : value.length
                    const before = value.slice(0, start)
                    const glue = before === '' || /\\s$/.test(before) ? '' : (/[.!?]$/.test(before.trim()) ? ' ' : '. ')
                    this.write(textarea, start, document.activeElement === textarea ? textarea.selectionEnd : value.length, glue + text)
                    this.used(id)
                },
                expand(event) {
                    if (! [' ', 'Enter', 'Tab'].includes(event.key) || event.target.tagName !== 'TEXTAREA') return
                    const textarea = event.target
                    const before = textarea.value.slice(0, textarea.selectionStart)
                    const match = before.match(/(^|\\s)\\/([\\wáéíóúñ-]+)$/i)
                    const snippet = match && this.shortcuts[match[2].toLowerCase()]
                    if (! snippet) return
                    event.preventDefault()
                    const start = textarea.selectionStart - match[2].length - 1
                    this.write(textarea, start, textarea.selectionStart, snippet.body + (event.key === 'Enter' ? '\\n' : ' '))
                    this.used(snippet.id)
                },
            }
            JS;
    }

    public function optionsHtml(): ?HtmlString
    {
        $snippets = $this->available();
        $shortcuts = $snippets->whereNotNull('shortcut')->map(fn (TextSnippet $s) => '/'.$s->shortcut)->take(4)->implode(' ');

        if ($snippets->isEmpty()) {
            return null;
        }

        $buttons = $snippets->values()->map(function (TextSnippet $snippet, int $i) {
            $hidden = $i >= self::VISIBLE_OPTIONS ? ' x-cloak x-show="more"' : '';
            $title = e($snippet->body);
            $label = e($snippet->label).($snippet->isShared() ? '' : ' <span style="opacity:.6">· mía</span>');
            $click = e('insert($el, '.Js::from($snippet->body).', '.$snippet->getKey().')');

            return "<button type=\"button\" class=\"qt-chip\" title=\"{$title}\" x-on:click=\"{$click}\"{$hidden}>{$label}</button>";
        })->implode('');

        $extra = $snippets->count() - self::VISIBLE_OPTIONS;
        $toggle = $extra > 0
            ? "<button type=\"button\" class=\"qt-chip qt-more\" x-on:click=\"more = ! more\" x-text=\"more ? 'Menos' : 'Más ({$extra})'\">Más ({$extra})</button>"
            : '';
        $hint = $shortcuts ? '<span class="qt-hint">Atajos: '.e($shortcuts).'</span>' : '';

        return new HtmlString(<<<HTML
            <div class="qt-options">{$buttons}{$toggle}{$hint}</div>
            <style>
                .qt-options { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; }
                .qt-chip { border: 1px dashed var(--gray-300); border-radius: 9999px; padding: .1rem .6rem; font-size: .75rem; color: var(--gray-700); background: transparent; cursor: pointer; }
                .qt-chip:hover { border-color: var(--primary-500); color: var(--primary-700); background: var(--primary-50); }
                .dark .qt-chip { color: var(--gray-300); border-color: rgb(255 255 255 / .2); }
                .qt-more { border-style: solid; }
                .qt-hint { font-size: .72rem; color: var(--gray-500); margin-left: .25rem; }
            </style>
            HTML);
    }

    public function saveAsOptionAction(): Action
    {
        return Action::make('saveAsOption')
            ->label('Guardar como opción')
            ->icon(Heroicon::OutlinedBookmark)
            ->visible(fn (QuickTextarea $component) => ! $component->isDisabled())
            ->modalHeading('Guardar como opción')
            ->modalDescription('Queda como opción tuya en este campo. Si le pones un atajo, al escribir /atajo y un espacio se completa solo.')
            ->modalSubmitActionLabel('Guardar opción')
            ->fillForm(fn (?string $state) => ['body' => trim((string) $state)])
            ->schema([
                TextInput::make('label')
                    ->label('Nombre corto')
                    ->placeholder('Ej.: Profilaxis completa')
                    ->required()
                    ->maxLength(80),
                TextInput::make('shortcut')
                    ->label('Atajo (opcional)')
                    ->prefix('/')
                    ->placeholder('limp')
                    ->alphaDash()
                    ->maxLength(20)
                    ->rule(fn () => Rule::unique('text_snippets', 'shortcut')->where('user_id', auth()->id())),
                Textarea::make('body')
                    ->label('Texto')
                    ->required()
                    ->rows(4),
            ])
            ->action(function (array $data, QuickTextarea $component) {
                TextSnippet::create([
                    'user_id' => auth()->id(),
                    'field' => $component->getSnippetField(),
                    'label' => $data['label'],
                    'body' => $data['body'],
                    'shortcut' => filled($data['shortcut'] ?? null) ? mb_strtolower($data['shortcut']) : null,
                    'status' => SnippetStatus::Active,
                ]);

                Notification::make()->success()->title('Opción guardada')->send();
            });
    }
}
