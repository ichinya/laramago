# Literal validation database references

The `laramago-unknown-validation-table` and `laramago-unknown-validation-column` warnings check literal `exists` and `unique` rules passed to the verified native Laravel validator factory or validator methods. They require an explicit catalog of the effective presence verifier's connections, tables, and columns in `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "validation-database": {
        "native-rule-semantics": true,
        "connections": {
          "default": {
            "complete": true,
            "tables": {
              "users": {"complete": true, "columns": ["id", "email"]}
            }
          },
          "archive": {
            "complete": false,
            "tables": {
              "entries": {"complete": true, "columns": ["code"]}
            }
          }
        }
      }
    }
  }
}
```

`native-rule-semantics: true` asserts that the application's effective validator and presence verifier retain Laravel's native database-rule meaning, without a custom resolver changing table/column interpretation. The installed Laravel method bodies must also match the pinned source contract. `default` denotes Laravel's effective default connection; a rule such as `exists:archive.entries,code` uses the named `archive` catalog. A connection's `complete: true` asserts that its table list is exhaustive. Each table's `complete: true` independently asserts that its column list is exhaustive. Only those assertions permit absent-name warnings. Malformed entries invalidate the affected connection's table completeness, and malformed columns invalidate their table entry. Case-only differences remain unknown because database identifier case sensitivity varies. This setting is independent of `validation-rule-names`.

Supported rule strings include `exists:users,email`, `unique:users,email`, inferred field columns, the exact `NULL` column placeholder, and a non-null `unique` ignore value followed by its ID column. Database diagnostics require the installed Laravel validator and rule-parser methods to match the pinned native source contract.

Migrations provide positive source evidence for default-connection columns and can prevent a conflicting warning, but they never prove a database table or column absent. Unknown connections, dynamic arguments, model class strings, `Rule::exists()` / `Rule::unique()` objects, wildcard fields, regex strings with pipes, custom presence verifiers, runtime schema changes, and changed native Laravel methods remain unknown. A `unique` rule with an ignored ID and no explicit ID column leaves that default ID-column check to Laravel. These warnings compare against an asserted static catalog; they do not connect to a database or establish runtime database state.
