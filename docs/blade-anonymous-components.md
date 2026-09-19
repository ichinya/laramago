# Blade anonymous component files

`BladeAnonymousComponentCatalog` enumerates `.blade.php` files under explicitly
selected project-relative directories. It reads directory entries only; it does
not compile Blade, evaluate `@php`, load application code or boot Laravel. Add
roots to `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "blade-anonymous-components": {
        "roots": [
          { "path": "resources/views/components" },
          { "path": "resources/views/vendor/widgets", "prefix": "widgets" }
        ]
      }
    }
  }
}
```

`components()` returns a source snapshot or `null` if the configuration is
missing, invalid or unreadable. Each `BladeAnonymousComponent` has a
project-relative `path`, configured `rootPath`, optional `prefix`, exact dotted
`relativeName`, and `candidateNames`. For example, `forms/input.blade.php`
contributes `forms.input`; `panel/index.blade.php` contributes both
`panel.index` and `panel`; `notice/notice.blade.php` contributes both
`notice.notice` and `notice`. Candidate names retain filename case and are
local to the root. A later tag resolver must establish effective registration,
namespace, precedence and view-finder behavior independently. A configured
root or a matching file does not establish that the application registered an
anonymous component path, namespace or tag.

Each entry also has nullable `props` metadata. A `BladePropsMetadata` snapshot
records whether a single top-level literal `@props([...])` was found, its byte
offset, and ordered `BladePropDeclaration` names with `hasDefault` flags. An
unkeyed string such as `'title'` declares a name without a default; a string
key such as `'tone' => 'quiet'` declares a default. Default expressions are
never evaluated or typed. `null` means that the source could not be read or
parsed confidently, including dynamic arrays, unpacking, duplicate names,
multiple directives, earlier control directives, or PHP code. Blade comments,
verbatim blocks and PHP strings do not contribute declarations. HTML comments
and quoted inline text containing `@props` remain unknown because the native
Blade statement compiler may still see directives there.
This is syntax metadata, not a required-prop or valid-attribute diagnostic.
Laravel's `compileProps` assigns keyed defaults with null coalescing, while
`ComponentAttributeBag::extractPropNames` also accepts kebab-case aliases;
future consumers must respect those behaviors rather than interpreting an
unkeyed entry as a framework-enforced requirement.

The catalog skips filenames outside its conservative ASCII name grammar and
returns unknown for unsafe roots, links, unreadable entries, more than 128
configured roots, over 4,096 visited entries in a root, or a directory at the
depth limit of 32. It does not claim required properties, valid tags,
absence of unlisted components, or runtime view resolution. Laravel's component
compiler also checks class aliases/classes, namespaces, custom paths and view
factory existence; those branches need separate explicit contracts before any
missing-name diagnostic is justified.
