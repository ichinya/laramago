# Laravel LSP adaptation queue

This tracks the 197 individually numbered research items. Queued entries are proposals, not promises of supported behavior. Each item receives independent review; existing coverage and SDK limitations must be evidenced. Offline analysis and native contract priority apply throughout.

Baseline: a67d3ee; previous package validation passed 3393 checks. No application bootstrap or database is required.

| Item | Scope | Status |
| --- | --- | --- |
| 1 | Model table mapping | already-covered; Verified ModelReflection::table and tests/properties.php. |
| 2 | Migration column types and nullability | already-covered; Verified SchemaIndex and migration locals, literals and contracts tests. |
| 3 | Native property and PHPDoc priority | already-covered; Verified EloquentPropertyProvider native and PHPDoc priority with properties tests. |
| 4 | Built-in attribute casts | already-covered; Verified AttributeTypes built-in casts and casts, advanced-casts, cast-contracts, carbon-properties tests. |
| 5 | Typed accessors and mutators | already-covered; Verified typed legacy and Attribute accessors in EloquentPropertyProvider and properties tests. |
| 6 | Separate attribute read and write types | already-covered; Verified independent read/write contracts in AttributeTypes, CustomCastTypes and focused tests. |
| 7 | Relation properties and typed collections | already-covered; Verified relation properties, nullable models and typed collections with five focused suites. |
| 8 | Legacy and attributed local scopes | already-covered; Verified legacy and attributed scope resolution; 43 focused scenarios passed. |
| 9 | Scope parameters and simple fluent bodies | already-covered; Verified scope signatures and bounded fluent body inference; native and disabled regressions passed. |
| 10 | Custom builders and collections | already-covered; Verified custom builder and collection resolution with eight focused suites. |
| 11 | Fillable name validation | implemented; Explicit exact-model field catalogs validate literal fillable names; native dispatch and disabled regressions passed. |
| 12 | Guarded name validation | implemented; Guarded names reuse model catalogs with case-insensitive and wildcard semantics; focused and disabled checks passed. |
| 13 | Hidden name validation | implemented; Hidden names use complete field and pre-serialization key catalogs; focused native/custom/disabled cases passed. |
| 14 | Visible name validation | implemented; Visible names reuse complete serialization catalogs with native empty-list and case semantics; focused tests passed. |
| 15 | Appended accessor name validation | implemented; Literal appends entries checked against an independent explicit complete appendable-key catalog; native serialization guards and real-Mago enabled/disabled regressions pass. |
| 16 | Model attribute declarations for field lists | implemented; Native Fillable/Guarded/Hidden/Visible/Appends attributes use exact catalogs and installed lifecycle guards; effective Guarded precedence, custom overrides and older-framework regressions pass. |
| 17 | Physical columns versus computed accessors | already-covered; Verified physical-column selection versus accessors, raw pluck keys and SQL alias boundaries; three suites passed. |
| 18 | Literal mass-assignment keys | implemented; Native forceFill literal keys use complete exact-model catalogs with native/PHPDoc priority; 190 key and 135 write-contract checks pass. Ordinary fill/create remain semantically deferred. |
| 19 | Mass-assignment value types | implemented; Native forceFill checks disjoint structured values against independent explicit write contracts. 135 real-Mago case/mode checks pass; fill/create, scalar coercions and schema-derived input types remain deferred. |
| 20 | Model update versus builder update contracts | already-covered; Native Model instance update returns bool; Builder update returns int; static Model update retains invalid-static-method-access. Enabled/disabled real-Mago audit preserves custom overrides and arity errors; input validation remains deferred. |
| 21 | Literal query column references | queued |
| 22 | Both operands of whereColumn | queued |
| 23 | Search versus creation attribute arrays | queued |
| 24 | Suggestions for proven field typos | queued |
| 25 | Additional relation-name call sites | implemented; Additional non-morph existence methods and loadMissing reuse native relation-name checks; three focused suites passed. |
| 26 | Nested relation paths | already-covered; Verified nested relation traversal, callback typing and complete-name checks with focused suites. |
| 27 | Mixed eager-load declaration arrays | implemented; Mixed eager-load arrays honor effective keys and Laravel numeric-string semantics; dynamic/negative uncertainty defers. |
| 28 | Eager-load colon projection syntax | implemented; Eager-loading methods including withWhereHas accept colon projections; pure existence queries preserve full names. Real-Mago tests cover builder/static forwarding and native fallback. |
| 29 | Related model projection columns | deferred; Missing projection columns require complete effective-query metadata or an explicit query-source contract; schema alone cannot exclude scopes, joins, from changes, and aliases. No diagnostic added. |
| 30 | Eager-loading callback parameter types | implemented; Direct Builder::with literal-path callbacks receive concrete Relation types under native broad contracts. Real-Mago/native comparisons pass; nested callback arrays remain SDK-deferred. |
| 31 | Morph callbacks with explicit model classes | implemented; Four native Morph existence callbacks refine explicit fully qualified model targets under per-class runtime identity assertions; 45 morph and native trait callback regressions pass. Unknown morph maps/imports and custom dispatch defer. |
| 32 | Relation aggregate references | queued |
| 33 | Relation aggregate alias syntax | queued |
| 34 | Query-local aggregate result properties | queued |
| 35 | Literal configuration result types | already-covered; Verified literal config and native Config::get types with configuration and configuration-index suites. |
| 36 | Additional configuration call sites | already-covered; Native config and Config::get literal results verified in configuration/framework-contract tests. Arbitrary Repository instances cannot inherit the application index without producer provenance. |
| 37 | Complete configuration key diagnostics | implemented; Complete runtime assertions plus source-complete parents enable native helper/facade key diagnostics. Custom app/repository dispatch, bindings, dynamic branches and native errors retain priority. |
| 38 | Configuration getMany key validation | implemented; Native Config getMany validates literal list/default keys under existing complete catalogs; numeric semantics, custom dispatch, duplicate/reference/negative/overflow deferrals and native errors pass real-Mago checks. |
| 39 | Typed configuration getters | queued |
| 40 | Config injection attribute references | queued |
| 41 | Configuration push and prepend targets | queued |
| 42 | Configuration declaration locations | queued |
| 43 | Explicit environment name catalogs | queued |
| 44 | Environment helper key references | queued |
| 45 | Env get key references | queued |
| 46 | Environment template interpolation references | queued |
| 47 | Duplicate environment template declarations | queued |
| 48 | Vite environment name references | queued |
| 49 | Existing complete named-route contracts | already-covered; Verified complete named-route catalogs; 19 real-Mago scenarios and source analysis passed. |
| 50 | Route and to_route helpers | implemented; Native route/to_route literal names use shared complete catalogs, helper/method provenance and service-binding guards. Seven real-Mago modes verify native/custom contracts and literal spans. |
| 51 | Named-route facade calls | implemented; Native URL/Redirect route references use complete catalogs. Shared facade proof preserves concrete methods across full ancestry; eleven facade modes and Config regressions pass. |
| 52 | Signed and temporary signed routes | implemented; Signed and temporary signed route names use complete catalogs on native facades/exact receivers; forwarding-chain signature guards, changed defaults and twelve real-Mago modes preserve custom/native errors. |
| 53 | Response route redirects | queued |
| 54 | RedirectToRoute attribute references | queued |
| 55 | Route-name predicate semantics | queued |
| 56 | Static literal route registration catalogs | queued |
| 57 | Conflicting active route names | queued |
| 58 | Required named-route parameters | queued |
| 59 | Domain optional and default route parameters | queued |
| 60 | Route declaration diagnostic locations | queued |
| 61 | Controller class existence in string actions | implemented; tests/controller-action-classes.php; absolute literal actions only |
| 62 | Controller method existence in string actions | implemented; tests/controller-action-classes.php; standard dispatch and binding guards |
| 63 | Controller action visibility | implemented; Controller action visibility follows native dispatch scope; 36 real-Mago scenarios passed. |
| 64 | Array controller action references | implemented; Literal controller action arrays resolve PHP names and preserve native diagnostics; 58 real-Mago scenarios passed. |
| 65 | Invokable controller actions | implemented; Absolute invokable strings require actual invoke declarations; namespace uncertainty defers; 77 scenarios passed. |
| 66 | Controller route group context | queued |
| 67 | Additional route registration methods | queued |
| 68 | Controller parameter route and DI contracts | queued |
| 69 | Static middleware alias catalog | queued |
| 70 | Middleware groups and legacy kernel catalog | queued |
| 71 | Middleware name references | queued |
| 72 | Middleware parameter string parsing | queued |
| 73 | Middleware class-string references | queued |
| 74 | Middleware handle contracts | queued |
| 75 | Middleware attributes and arrays | queued |
| 76 | Middleware parameter arity | queued |
| 77 | Middleware group cycles | queued |
| 78 | withoutMiddleware semantics | queued |
| 79 | Static Gate define catalog | queued |
| 80 | Explicit policy mapping catalog | queued |
| 81 | Declarative model-policy attributes | queued |
| 82 | Policy class references | queued |
| 83 | Policy method availability | queued |
| 84 | Gate ability name references | queued |
| 85 | Gate ability arrays | queued |
| 86 | Route can and Authorize attributes | queued |
| 87 | Policy model argument compatibility | queued |
| 88 | Additional policy arguments | queued |
| 89 | Class-level policy abilities | queued |
| 90 | Policy discovery resolver boundaries | queued |
| 91 | Existing explicit container binding types | already-covered; Verified explicit container bindings, aliases and contract guards; focused tests and source analysis passed. |
| 92 | Existing framework aliases and facade roots | already-covered; Verified literal framework aliases and facade roots; focused enabled/disabled suites passed. |
| 93 | Additional literal binding registrations | implemented; Literal unconditional scoped registrations reuse binding inference; direct/named, duplicate/runtime deferral and core-service replacement regressions pass. Conditional registration APIs remain deferred. |
| 94 | Container alias chains and uncertainty | queued |
| 95 | Container injection attributes | queued |
| 96 | Container alias cycles | queued |
| 97 | Binding interface compatibility | queued |
| 98 | Explicit factory required arguments | implemented; Literal container factories accept zero to two untyped required by-value parameters supplied by Laravel. Real-Mago checks retain return/body and uncertain-signature boundaries. |
| 99 | Complete service ID catalog references | queued |
| 100 | Contextual binding resolution | queued |
| 101 | Existing complete view helper contracts | already-covered; Verified complete view helper catalogs; seven reference-catalog modes and source analysis passed. |
| 102 | View facade and factory references | queued |
| 103 | Response view references | queued |
| 104 | Route view references | queued |
| 105 | MailMessage view and markdown references | queued |
| 106 | Declarative mail content references | queued |
| 107 | assertViewIs references | queued |
| 108 | Pagination view references | queued |
| 109 | View first fallback lists | queued |
| 110 | Conditional and iterative view rendering | queued |
| 111 | Package view namespaces | queued |
| 112 | View suggestions and declaration locations | queued |
| 113 | Existing literal translation types | already-covered; Verified literal translation string refinement with translation-strings and reference-catalogs suites. |
| 114 | Existing explicit-locale reference contracts | already-covered; Verified explicit locale/fallback reference catalogs; reference-catalogs and translation-strings suites passed. |
| 115 | Translator and Lang call sites | queued |
| 116 | trans_choice references | queued |
| 117 | JSON phrase translation references | queued |
| 118 | Package translation namespaces | queued |
| 119 | Translation replacement placeholders | queued |
| 120 | Suspicious replacement names | queued |
| 121 | Translation string leaves versus groups | queued |
| 122 | Cross-locale placeholder consistency | queued |
| 123 | Pluralization syntax contracts | queued |
| 124 | Translation fallback provenance | queued |
| 125 | Static filesystem disk catalog | implemented; tests/configuration-index.php; source-only completeness |
| 126 | Storage disk references | implemented; Guarded native Storage::disk diagnostics; 27 real-Mago scenarios passed. |
| 127 | Storage injection attribute references | implemented; Native Storage injection attribute diagnostics with provenance and custom-handler guards; focused suites passed. |
| 128 | Storage fake and forget semantics | queued |
| 129 | Unknown default disk configuration | queued |
| 130 | Concrete filesystem adapter types | queued |
| 131 | Explicit public asset catalogs | queued |
| 132 | Asset literal references | queued |
| 133 | Mix manifest parsing | queued |
| 134 | Mix manifest key references | queued |
| 135 | Malformed Mix manifest diagnostics | queued |
| 136 | Static path helper resolution | queued |
| 137 | Path existence in required-file contexts | queued |
| 138 | Vite manifest adaptation | queued |
| 139 | Explicit Inertia page catalog | implemented; tests/inertia-page-catalog.php; symlink creation test skipped on host |
| 140 | Inertia render page references | implemented; tests/inertia-page-references.php; opt-in complete catalog |
| 141 | Inertia helper and route references | queued |
| 142 | Optional Inertia integration calls | queued |
| 143 | Ambiguous Inertia page names | queued |
| 144 | Simple Vue defineProps names | queued |
| 145 | Required Inertia props contracts | queued |
| 146 | PHP and frontend prop type contracts | queued |
| 147 | Inertia assertion page contracts | queued |
| 148 | Static Blade class component catalog | queued |
| 149 | Static Blade anonymous component catalog | queued |
| 150 | Explicit Blade component aliases | queued |
| 151 | Blade component tag resolution | queued |
| 152 | Required Blade constructor props | queued |
| 153 | Literal Blade props declarations | queued |
| 154 | Blade HTML attributes versus PHP bindings | queued |
| 155 | Blade prop argument types | queued |
| 156 | Blade attribute bag semantics | queued |
| 157 | Blade view and translation references | queued |
| 158 | Static custom Blade directive catalogs | queued |
| 159 | Complete Blade directive reference checks | queued |
| 160 | Blade diagnostic source mapping | queued |
| 161 | Explicit Livewire component catalog | queued |
| 162 | Conventional Livewire class and view mapping | queued |
| 163 | PHP Livewire component references | queued |
| 164 | Blade Livewire component references | queued |
| 165 | Volt route component references | queued |
| 166 | Explicit Livewire mount contracts | queued |
| 167 | Livewire event and listener contracts | queued |
| 168 | Existing validated field shapes | already-covered; Verified validated field shapes, nested rules and dynamic/custom contract deferrals with focused suites. |
| 169 | Additional validation declaration contexts | queued |
| 170 | Versioned built-in validation rule catalog | queued |
| 171 | Validation rule name diagnostics | queued |
| 172 | Validation rule parameter contracts | queued |
| 173 | Validation regex delimiter parsing | queued |
| 174 | Validation rule object contracts | queued |
| 175 | Literal validation table and column references | queued |
| 176 | Cross-field validation reference hints | queued |
| 177 | Static Pest uses and path declarations | queued |
| 178 | Pest trait and file mapping | queued |
| 179 | Static Pest expectation extensions | queued |
| 180 | Pest closure this typing | queued |
| 181 | Pest expectation method forwarding | queued |
| 182 | Conflicting test context diagnostics | queued |
| 183 | Shared static metadata indexes | queued |
| 184 | Shared Laravel reference call registry | queued |
| 185 | Shared import and class identity resolution | queued |
| 186 | Shared literal string argument handling | queued |
| 187 | Precise literal element diagnostic spans | queued |
| 188 | Cross-platform and custom vendor paths | already-covered; Installer and real-worker path tests pass on Windows, including spaces and custom vendor configuration. Linux/macOS execution and combined generated custom-vendor installation remain unverified. |
| 189 | Index dependency invalidation | already-covered; Fresh analyzer runs reread changed migrations, configuration and source; properties/configuration regressions pass. Same-worker incremental/watch invalidation is not claimed. |
| 190 | Lazy integration index loading | implemented; Configuration/storage/Inertia diagnostic catalogs load on demand and reset per analysis generation; eager warning scans retained. Behavioral lazy lookup and four focused suites pass. |
| 191 | Metadata provenance locations | queued |
| 192 | Explicit metadata confidence states | queued |
| 193 | Upstream scenario regression adaptation | queued |
| 194 | Version-aware Laravel API capabilities | queued |
| 195 | Static metadata export | queued |
| 196 | Diagnostic name fixes and navigation | queued |
| 197 | Long-running index watch mode | queued |
