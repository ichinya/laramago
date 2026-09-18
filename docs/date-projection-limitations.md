# Date properties after a query projection

Mutable Eloquent date casts retain `Carbon\CarbonInterface`. Laravel's Date factory can select a class, callable, or factory, so choosing a concrete mutable Carbon class would discard valid custom implementations. Carbon declares properties such as `month` in the interface PHPDoc; Mago 1.48.1 already reads those declarations. Native declared properties and custom PHPDoc remain authoritative, including read/write restrictions and misspelling diagnostics.

A failure on `findOrFail($ids[0])->created_at->month` can originate earlier:

```php
$ids = Entry::query()->where('active', true)->orderBy('id')->pluck('id')->all();
$entry = Entry::query()->findOrFail($ids[0]);
```

The installed Builder `pluck` contract returns `Collection<array-key, mixed>`. An element of its result can therefore be an array; `findOrFail` must retain its collection branch. A subsequent model property access fails on that branch before Carbon property typing is relevant.

Direct model projections such as `Entry::pluck('id')` can use the existing fresh-query provider. Arbitrary `Builder<Entry>` values cannot use that shortcut: `from`, joins, selected aliases, global scopes, and after-query callbacks can change the result. Laravel applies `applyAfterQueryCallbacks` to both branches of its `pluck` implementation, so a blanket scalar result is not justified either.

The SDK 1.48.1 return-provider context supplies receiver types, arguments, and a byte span, but no source file, receiver expression, or builder mutation history. Node analysis has source information only after file analysis. Safe support for longer fresh query chains requires additional call-site/state information; guessing from the model generic would suppress real errors.

Regression coverage: `tests/carbon-properties.php` checks native Carbon interface properties, inferred date casts, custom contracts, read/write failures, typos, and disabled-plugin controls. `tests/query-projections.php` checks direct typed ids versus the unresolved Builder projection and its collection lookup branch. These tests record the current limitation; they do not claim the longer chain is fixed.
