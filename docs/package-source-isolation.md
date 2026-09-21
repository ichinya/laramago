# Package source isolation

The Laravel preset excludes `**/ichinya/laramago/tests/**` and
`**/ichinya/laramago/var/**` from Mago's source index. These directories contain
test doubles and local research snapshots, not application dependencies.

In a Composer path installation, the package directory can include development
files. Indexing a test's empty framework interface alongside Laravel's actual
interface can hide inherited methods and create false diagnostics in application
code. The same concern applies to distribution packages that include tests.

The exclusions are scoped to Laramago's package directory and work with custom
vendor directories. Application tests, Laravel's real contracts and Laramago's
production `src` directory remain indexed. No application configuration or
diagnostic code is suppressed. A project using its own preset should retain these
package-internal exclusions when indexing a development checkout as a dependency.

`php tests/package-source-isolation.php` checks the actual Mago index through
analysis with standard and space-containing dependency paths. It retains genuine
unknown-method diagnostics and verifies that application test support and package
implementation classes remain available.
