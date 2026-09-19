# Literal Mix manifest references

The optional `laramago-missing-mix-manifest-key` warning checks literal `mix()`
paths only when the application explicitly asserts how Laravel serves the
configured manifest. Add the four assertions to each file whose references you
want checked:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "mix-manifests": {
          "files": [
            {
              "directory": "",
              "path": "public/mix-manifest.json",
              "native-runtime": true,
              "effective-public-path": true,
              "hot-file-absent": true,
              "manifest-stable": true
            }
          ]
        }
      }
    }
  }
}
```

`native-runtime` asserts that the installed Laravel `mix()` helper resolves the
standard `Illuminate\Foundation\Mix` implementation through the native `app()`
container dispatch, without uncataloged runtime rebinding or replacement.
`effective-public-path` asserts that Laravel's `public_path()` resolves this
entry's `path` for the given `directory`. `hot-file-absent` asserts that Laravel
will not serve the hot file for this directory. `manifest-stable` asserts that
the manifest snapshot remains the one used by the request (including any
static in-process Mix manifest cache). File presence alone makes none of these
assertions. Set them only when the serving environment satisfies them.

The analyzer also checks that the catalog parsed a complete string-valued map,
the local hot file is absent, no cataloged Mix container binding overrides the
native one, and the installed helper and Mix implementation match the supported
Laravel source contract. The supported Mix body is from Laravel framework
commit `7c75fbf`; its source fingerprint accepts LF, CRLF and CR line endings.
Different bodies or namespaced functions shadowing its native calls defer.
Literal paths use Mix's leading-slash
rule and directory-specific maps, including named `path:` and
`manifestDirectory:` arguments. Dynamic expressions, incomplete or malformed
manifests, unsupported PHPDoc/dispatch changes, custom helpers, and unknown
directories defer to Mago.

Laravel throws `MixFileNotFoundException` for a missing key when `app.debug` is
true. Otherwise it reports the exception and returns the original path. This
warning identifies a missing manifest reference under the asserted serving
conditions; it does not assert which branch runs, refine the native
`HtmlString|string` return contract, or run the application.
