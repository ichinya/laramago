# Guarded request header arguments

Laramago accepts a string argument produced by a current Laravel request header when an earlier `is_string()` guard protects that exact header read. The rule checks the physical caller, receiving declaration, request hierarchy, header implementation and native metadata. Unknown bindings, aliases, references, rebinding and effects between the guard and argument retain Mago's diagnostic.

The supported hierarchy includes native `ArrayAccess` and `Stringable` interfaces and current physical user interfaces. A physically checked write-only `HeaderBag` setter does not alter header reads. Get hooks, changed setter bodies and stronger return or parameter declarations retain priority. Earlier completed ordinary request reads do not invalidate the later guarded lifetime.

The native regression matrix verifies six genuine positive Errors, twenty-five negative owners, 231 checks, seventy-one actual SDK cache mutations and three physical setter changes. Each mutation must veto the selected correction and restore it after the original metadata or source returns. Complete residual diagnostics are equal with one and three extension workers; the application and fixture bodies are never executed.

Run `php -d opcache.enable_cli=0 tests/guarded-header.php` to verify the rule.
