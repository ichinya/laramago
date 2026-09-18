# Polymorphic relationship properties

A native `MorphTo` relationship returned directly from `$this->morphTo()` exposes a nullable `Illuminate\Database\Eloquent\Model` property. An explicit `MorphTo<Photo|Article>` contract retains that model union. `instanceof` checks can refine the base model; unknown concrete attributes and misspelled properties remain diagnostics.

Only verified native model dispatch is supported. Overrides of the relation factory, its constructors, or relationship result dispatch defer to Mago, as do custom relation classes and unsupported call chains. Native/default PHPDoc properties retain priority. Invalid explicit generic contracts are not replaced by the base Model fallback.

`withDefault(true)` and nonempty literal arrays remove null; `false` and empty arrays keep null. Repeated calls follow the final argument. Callback or dynamic defaults remain unresolved because a callback may replace the returned model. The implementation never infers concrete targets from prose, morph maps, or database contents.

Run `php tests/morph-properties.php` for positive, negative, and disabled-plugin checks against the real Mago CLI.
