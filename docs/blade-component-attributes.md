# Blade component opening attributes

BladeComponentAttributeParser::parse($tag) reads one isolated Blade component
opening tag. It does not search a document, resolve the component, compile
Blade, evaluate PHP, or render a view. A closing or ordinary HTML tag returns
null. A recognized tag returns BladeComponentOpeningTag.

The result records the component tag name, whether the tag closes itself, an
explicit complete flag, and ordered attributes. Each attribute retains its
original name, normalized name, kind, value text, and zero-based byte spans
within the supplied tag. End offsets are exclusive. Quoted value spans exclude
the quotes. Short binding syntax such as :$theme records a generated $theme
expression and has no value span because those bytes are not present as a value
in the source.

The supported kinds follow Laravel's ComponentTagCompiler: title="text" is a
literal string, :title="$value" is a bound expression, :$title is a short bound
expression, disabled is a boolean, and ::x-on:click="..." is an escaped Alpine
attribute whose effective name retains one colon. attributeNames() returns only
names that may match constructor parameters and excludes escaped Alpine and
punctuated bag-only names. A caller can pass this list and complete to
BladeClassRequiredProps::missingExplicit() after independently proving a class
target and supplying an explicit required-name contract.

The parser returns complete = false when it sees a dynamic attribute bag,
Blade directive or interpolation, embedded PHP, malformed syntax, or an
unsupported attribute form. It also defers tags over 65,536 bytes or with more
than 256 attributes. Known entries before uncertain syntax remain available
as source metadata, but an incomplete result cannot prove a missing prop.
The parser handles one isolated opening tag; document traversal, tag
resolution, constructor type checks, and diagnostics are separate stages.
