# Livewire event source contracts

`LivewireEventContracts` reads a selected Livewire 3 or 4 component PHP file
through `PhpSource`, without loading the class or bootstrapping Laravel. Its
`inspect($file, $class)` result contains source-positioned `listeners` and
`dispatches`, plus `unresolved`. These are declaration-site candidates;
duplicate property keys may be shadowed by later entries. The caller must establish that the selected
class is a Livewire component and that the installed framework keeps the
standard event implementation.

The listener list contains literal `#[Livewire\Attributes\On]` method and class
attributes, including repeatable attributes and flat literal lists, and direct
literal `$listeners` property entries. Class attributes map to `$refresh`.
Dispatch sites are literal `$this->dispatch('event')` calls inside directly
declared methods, including conditional or nested calls. They are syntax
references: a call may never execute. A listener declaration may be replaced
by `getListeners()`, inherited code, or runtime behavior. The API never claims
that a declared listener is effective or that a dispatch has a receiver.

Interpolated, placeholder, wildcard, Echo, dynamic, and malformed names are
excluded from exact matches and set `unresolved`. So do `getListeners()`
overrides, traits, application parent classes, and dynamic dispatch arguments.
`unresolved: false` means only that this direct source scan encountered no
unsupported syntax; it never proves complete effective listeners. Browser, Alpine, JavaScript, Blade,
global, and external listeners are outside this PHP source snapshot. Absence
from this list must not produce a missing-event warning. Native and PHPDoc
contracts retain priority for call and payload typing.

The boundary follows the pinned Livewire 3 and 4 `HandlesEvents::dispatch`,
`SupportEvents::getComponentListeners`, and `On` attribute implementations:
both versions collect dispatches, merge `getListeners()` with attribute
listeners, and resolve dynamic placeholders during component operation.
