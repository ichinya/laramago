# Literal validation regex parameters

`LiteralValidationRegex::from($expression)` accepts a literal string or a
literal list of rule strings. `fromLiteral($literal)` accepts the corresponding
PHP value. The result lists `rule`, `pattern`, `body`, `delimiter`, and
`modifiers`; null means extraction is uncertain or lexically malformed.

Scalar strings follow Laravel's `explode('|')`; array strings remain individual
rules. The raw rule name determines whether Laravel preserves the parameter
or parses CSV before normalizing the name. Ambiguous spellings such as
`not-regex` and ` regex` remain unknown. The original pattern is retained.

The parser follows PHP 8.2's delimiter scanning, including escapes, paired
delimiters, and unescaped delimiter bytes inside character classes. It never
compiles or matches a pattern, proves PCRE body validity, or refines validated
value types. Newer unsupported modifiers and dynamic expressions defer.

The boundaries follow the [Laravel rule parser](https://github.com/laravel/framework/blob/7c75fbf93f91fa077d3df1c820cc14f4e59a9774/src/Illuminate/Validation/ValidationRuleParser.php)
and [PHP 8.2 PCRE implementation](https://github.com/php/php-src/blob/PHP-8.2/ext/pcre/php_pcre.c).
