# Mix manifest parse diagnostics

`MixManifestParseHook` warns at a literal native `mix()` call when the selected
explicit `mix-manifests.files` entry points to malformed JSON or to a JSON value
that the static catalog cannot index as a string map. The warnings are
`laramago-mix-manifest-invalid-json` and
`laramago-mix-manifest-invalid-shape`. Their messages name the configured
project-relative manifest path, while the primary annotation stays on the
analyzed PHP call. The shape warning means that static indexing is unsupported;
native Mix can sometimes coerce a value or return the unversioned path.

The hook requires a verified native Laravel `mix()` helper and `Mix` dispatch,
a literal requested path and manifest directory, an explicitly configured
manifest file, and an observed absent hot file. It defers for custom helpers or
Mix bindings, dynamic arguments, unconfigured directories, present or unknown
hot files, missing or unreadable manifests, and invalid catalog configuration.
The catalog is a filesystem snapshot. These warnings do not claim that Laravel
will throw at runtime or that the analyzed call reaches the file in every
environment.
