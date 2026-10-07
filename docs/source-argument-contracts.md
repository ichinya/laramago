# Source argument contracts

The adapter handles two explicit argument contracts. A standalone `usleep((int) $value * $factor)` call is accepted only after an exit guard requires a string of decimal digits and caps its cast value. The source proves the multiplication stays nonnegative and within a 32-bit integer range. References, changed values, unknown intervening calls, wrong variables, and shadowed predicate functions remain unsupported.

For `proc_open`, PHPStan's bundled native signature declares parameter 4 as `?string`, while Mago refines it to `non-empty-string|null`. The adapter accepts a direct read of a physically declared promoted readonly string property in a final class against the declared receiving contract. It binds the caller, property, and builtin to genuine SDK metadata and current source locations. This compatibility policy does not claim the directory is nonempty or that starting a process will succeed.

Both rules preserve native return types and argument types. They recompute each decision independently of extension workers and use no after-file evidence. The regression gate requires genuine native positive Errors, negative Error witnesses, an AlwaysKeep observer, meaningful selected SDK cache mutations with restoration, and exact whole-issue differences with one and three extension workers.
