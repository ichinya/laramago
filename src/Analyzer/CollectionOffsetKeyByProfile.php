<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

/** Current public framework declaration profile, captured by a genuine native fixture. */
final class CollectionOffsetKeyByProfile
{
    public const REFERENCE=array (
  'symbol' => 'Illuminate\\Support\\Collection::keyBy',
  'kind' => 'Method',
  'location' => 
  array (
    'file' => 'vendor/laravel/framework/src/Illuminate/Collections/Collection.php',
    'span' => 
    array (
      'start' => 15787,
      'end' => 16464,
    ),
  ),
  'flags' => 4194368,
  'parameterNames' => 
  array (
    0 => '$keyBy',
  ),
  'declaredReturn' => NULL,
  'effectiveReturn' => 'Illuminate\\Support\\Enumerable',
  'parameters' => 
  array (
    0 => 
    array (
      'objectClass' => 'Mago\\Sdk\\Analyzer\\Metadata\\ParameterMetadata',
      'name' => '$keyBy',
      'location' => 
      array (
        'objectClass' => 'Mago\\Sdk\\SourceLocation',
        'file' => 'vendor/laravel/framework/src/Illuminate/Collections/Collection.php',
        'span' => 
        array (
          'objectClass' => 'Mago\\Sdk\\Span',
          'start' => 15826,
          'end' => 15832,
        ),
      ),
      'nameLocation' => 
      array (
        'objectClass' => 'Mago\\Sdk\\SourceLocation',
        'file' => 'vendor/laravel/framework/src/Illuminate/Collections/Collection.php',
        'span' => 
        array (
          'objectClass' => 'Mago\\Sdk\\Span',
          'start' => 15826,
          'end' => 15832,
        ),
      ),
      'declaredType' => NULL,
      'type' => 
      array (
        'objectClass' => 'Mago\\Sdk\\Analyzer\\Metadata\\TypeMetadata',
        'location' => 
        array (
          'objectClass' => 'Mago\\Sdk\\SourceLocation',
          'file' => 'vendor/laravel/framework/src/Illuminate/Collections/Enumerable.php',
          'span' => 
          array (
            'objectClass' => 'Mago\\Sdk\\Span',
            'start' => 15807,
            'end' => 15853,
          ),
        ),
        'type' => 
        array (
          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
          'atomicTypes' => 
          array (
            0 => 
            array (
              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\KeyedArrayType',
              'knownItems' => NULL,
              'keyType' => 
              array (
                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                'atomicTypes' => 
                array (
                  0 => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
                    'kind' => 
                    array (
                      'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                      'name' => 'ArrayKey',
                    ),
                    'refinement' => NULL,
                  ),
                ),
                'flags' => 
                array (
                  'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                  'hadTemplate' => false,
                  'byReference' => false,
                  'referenceFree' => false,
                  'possiblyUndefinedFromTry' => false,
                  'possiblyUndefined' => false,
                  'ignoreNullableIssues' => false,
                  'ignoreFalsableIssues' => false,
                  'fromTemplateDefault' => false,
                  'populated' => false,
                  'nullsafeNull' => false,
                  'fromUnspecifiedTemplate' => false,
                ),
              ),
              'valueType' => 
              array (
                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                'atomicTypes' => 
                array (
                  0 => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\MixedType',
                    'issetFromLoop' => false,
                    'nonNull' => false,
                    'empty' => false,
                    'truthiness' => 
                    array (
                      'enum' => 'Mago\\Sdk\\Analyzer\\Type\\MixedTruthiness',
                      'name' => 'Undetermined',
                    ),
                  ),
                ),
                'flags' => 
                array (
                  'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                  'hadTemplate' => false,
                  'byReference' => false,
                  'referenceFree' => false,
                  'possiblyUndefinedFromTry' => false,
                  'possiblyUndefined' => false,
                  'ignoreNullableIssues' => false,
                  'ignoreFalsableIssues' => false,
                  'fromTemplateDefault' => false,
                  'populated' => false,
                  'nullsafeNull' => false,
                  'fromUnspecifiedTemplate' => false,
                ),
              ),
              'nonEmpty' => false,
            ),
            1 => 
            array (
              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\CallableType',
              'signature' => 
              array (
                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\CallableSignature',
                'pure' => false,
                'closure' => false,
                'parameters' => 
                array (
                  0 => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\CallableParameter',
                    'name' => NULL,
                    'type' => 
                    array (
                      'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                      'atomicTypes' => 
                      array (
                        0 => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParameterType',
                          'name' => 'TValue',
                          'constraint' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                            'atomicTypes' => 
                            array (
                              0 => 
                              array (
                                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\MixedType',
                                'issetFromLoop' => false,
                                'nonNull' => false,
                                'empty' => false,
                                'truthiness' => 
                                array (
                                  'enum' => 'Mago\\Sdk\\Analyzer\\Type\\MixedTruthiness',
                                  'name' => 'Undetermined',
                                ),
                              ),
                            ),
                            'flags' => 
                            array (
                              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                              'hadTemplate' => false,
                              'byReference' => false,
                              'referenceFree' => false,
                              'possiblyUndefinedFromTry' => false,
                              'possiblyUndefined' => false,
                              'ignoreNullableIssues' => false,
                              'ignoreFalsableIssues' => false,
                              'fromTemplateDefault' => false,
                              'populated' => true,
                              'nullsafeNull' => false,
                              'fromUnspecifiedTemplate' => false,
                            ),
                          ),
                          'definingEntity' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParent',
                            'kind' => 
                            array (
                              'enum' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParentKind',
                              'name' => 'ClassLike',
                            ),
                            'name' => 'illuminate\\support\\collection',
                            'member' => NULL,
                          ),
                          'intersections' => NULL,
                        ),
                      ),
                      'flags' => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                        'hadTemplate' => false,
                        'byReference' => false,
                        'referenceFree' => false,
                        'possiblyUndefinedFromTry' => false,
                        'possiblyUndefined' => false,
                        'ignoreNullableIssues' => false,
                        'ignoreFalsableIssues' => false,
                        'fromTemplateDefault' => false,
                        'populated' => true,
                        'nullsafeNull' => false,
                        'fromUnspecifiedTemplate' => false,
                      ),
                    ),
                    'closureThisType' => NULL,
                    'byReference' => false,
                    'variadic' => false,
                    'hasDefault' => false,
                  ),
                  1 => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\CallableParameter',
                    'name' => NULL,
                    'type' => 
                    array (
                      'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                      'atomicTypes' => 
                      array (
                        0 => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParameterType',
                          'name' => 'TKey',
                          'constraint' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                            'atomicTypes' => 
                            array (
                              0 => 
                              array (
                                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
                                'kind' => 
                                array (
                                  'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                                  'name' => 'ArrayKey',
                                ),
                                'refinement' => NULL,
                              ),
                            ),
                            'flags' => 
                            array (
                              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                              'hadTemplate' => false,
                              'byReference' => false,
                              'referenceFree' => false,
                              'possiblyUndefinedFromTry' => false,
                              'possiblyUndefined' => false,
                              'ignoreNullableIssues' => false,
                              'ignoreFalsableIssues' => false,
                              'fromTemplateDefault' => false,
                              'populated' => true,
                              'nullsafeNull' => false,
                              'fromUnspecifiedTemplate' => false,
                            ),
                          ),
                          'definingEntity' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParent',
                            'kind' => 
                            array (
                              'enum' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParentKind',
                              'name' => 'ClassLike',
                            ),
                            'name' => 'illuminate\\support\\collection',
                            'member' => NULL,
                          ),
                          'intersections' => NULL,
                        ),
                      ),
                      'flags' => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                        'hadTemplate' => false,
                        'byReference' => false,
                        'referenceFree' => false,
                        'possiblyUndefinedFromTry' => false,
                        'possiblyUndefined' => false,
                        'ignoreNullableIssues' => false,
                        'ignoreFalsableIssues' => false,
                        'fromTemplateDefault' => false,
                        'populated' => true,
                        'nullsafeNull' => false,
                        'fromUnspecifiedTemplate' => false,
                      ),
                    ),
                    'closureThisType' => NULL,
                    'byReference' => false,
                    'variadic' => false,
                    'hasDefault' => false,
                  ),
                ),
                'returnType' => 
                array (
                  'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                  'atomicTypes' => 
                  array (
                    0 => 
                    array (
                      'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParameterType',
                      'name' => 'TNewKey',
                      'constraint' => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                        'atomicTypes' => 
                        array (
                          0 => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
                            'kind' => 
                            array (
                              'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                              'name' => 'ArrayKey',
                            ),
                            'refinement' => NULL,
                          ),
                          1 => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\NamedObjectType',
                            'name' => 'UnitEnum',
                            'parameters' => NULL,
                            'variances' => NULL,
                            'static' => false,
                            'isThis' => false,
                            'intersections' => NULL,
                            'remappedParameters' => false,
                          ),
                        ),
                        'flags' => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                          'hadTemplate' => false,
                          'byReference' => false,
                          'referenceFree' => false,
                          'possiblyUndefinedFromTry' => false,
                          'possiblyUndefined' => false,
                          'ignoreNullableIssues' => false,
                          'ignoreFalsableIssues' => false,
                          'fromTemplateDefault' => false,
                          'populated' => true,
                          'nullsafeNull' => false,
                          'fromUnspecifiedTemplate' => false,
                        ),
                      ),
                      'definingEntity' => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParent',
                        'kind' => 
                        array (
                          'enum' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParentKind',
                          'name' => 'FunctionLike',
                        ),
                        'name' => 'illuminate\\support\\enumerable',
                        'member' => 'keyby',
                      ),
                      'intersections' => NULL,
                    ),
                  ),
                  'flags' => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                    'hadTemplate' => false,
                    'byReference' => false,
                    'referenceFree' => false,
                    'possiblyUndefinedFromTry' => false,
                    'possiblyUndefined' => false,
                    'ignoreNullableIssues' => false,
                    'ignoreFalsableIssues' => false,
                    'fromTemplateDefault' => false,
                    'populated' => true,
                    'nullsafeNull' => false,
                    'fromUnspecifiedTemplate' => false,
                  ),
                ),
                'source' => NULL,
                'constraints' => 
                array (
                ),
              ),
              'alias' => NULL,
            ),
            2 => 
            array (
              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
              'kind' => 
              array (
                'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                'name' => 'String',
              ),
              'refinement' => 
              array (
                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\StringType',
                'literalKind' => 
                array (
                  'enum' => 'Mago\\Sdk\\Analyzer\\Type\\StringLiteralKind',
                  'name' => 'General',
                ),
                'literalValue' => NULL,
                'numeric' => false,
                'truthy' => false,
                'nonEmpty' => false,
                'callable' => false,
                'casing' => 
                array (
                  'enum' => 'Mago\\Sdk\\Analyzer\\Type\\StringCasing',
                  'name' => 'Unspecified',
                ),
              ),
            ),
          ),
          'flags' => 
          array (
            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
            'hadTemplate' => false,
            'byReference' => false,
            'referenceFree' => false,
            'possiblyUndefinedFromTry' => false,
            'possiblyUndefined' => false,
            'ignoreNullableIssues' => false,
            'ignoreFalsableIssues' => false,
            'fromTemplateDefault' => false,
            'populated' => true,
            'nullsafeNull' => false,
            'fromUnspecifiedTemplate' => false,
          ),
        ),
        'fromDocblock' => true,
        'inferred' => true,
      ),
      'outType' => NULL,
      'closureThisType' => NULL,
      'defaultType' => NULL,
      'attributes' => 
      array (
      ),
      'flags' => 
      array (
        'objectClass' => 'Mago\\Sdk\\Analyzer\\Metadata\\MetadataFlags',
        'bits' => 0,
      ),
    ),
  ),
  'declaredReturnProjection' => NULL,
  'effectiveReturnProjection' => 
  array (
    'objectClass' => 'Mago\\Sdk\\Analyzer\\Metadata\\TypeMetadata',
    'location' => 
    array (
      'objectClass' => 'Mago\\Sdk\\SourceLocation',
      'file' => 'vendor/laravel/framework/src/Illuminate/Collections/Enumerable.php',
      'span' => 
      array (
        'objectClass' => 'Mago\\Sdk\\Span',
        'start' => 15877,
        'end' => 15978,
      ),
    ),
    'type' => 
    array (
      'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
      'atomicTypes' => 
      array (
        0 => 
        array (
          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\NamedObjectType',
          'name' => 'Illuminate\\Support\\Enumerable',
          'parameters' => 
          array (
            0 => 
            array (
              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
              'atomicTypes' => 
              array (
                0 => 
                array (
                  'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ConditionalType',
                  'subject' => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                    'atomicTypes' => 
                    array (
                      0 => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\VariableType',
                        'name' => '$keyBy',
                      ),
                    ),
                    'flags' => 
                    array (
                      'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                      'hadTemplate' => false,
                      'byReference' => false,
                      'referenceFree' => false,
                      'possiblyUndefinedFromTry' => false,
                      'possiblyUndefined' => false,
                      'ignoreNullableIssues' => false,
                      'ignoreFalsableIssues' => false,
                      'fromTemplateDefault' => false,
                      'populated' => false,
                      'nullsafeNull' => false,
                      'fromUnspecifiedTemplate' => false,
                    ),
                  ),
                  'target' => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                    'atomicTypes' => 
                    array (
                      0 => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\KeyedArrayType',
                        'knownItems' => NULL,
                        'keyType' => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                          'atomicTypes' => 
                          array (
                            0 => 
                            array (
                              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
                              'kind' => 
                              array (
                                'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                                'name' => 'ArrayKey',
                              ),
                              'refinement' => NULL,
                            ),
                          ),
                          'flags' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                            'hadTemplate' => false,
                            'byReference' => false,
                            'referenceFree' => false,
                            'possiblyUndefinedFromTry' => false,
                            'possiblyUndefined' => false,
                            'ignoreNullableIssues' => false,
                            'ignoreFalsableIssues' => false,
                            'fromTemplateDefault' => false,
                            'populated' => false,
                            'nullsafeNull' => false,
                            'fromUnspecifiedTemplate' => false,
                          ),
                        ),
                        'valueType' => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                          'atomicTypes' => 
                          array (
                            0 => 
                            array (
                              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\MixedType',
                              'issetFromLoop' => false,
                              'nonNull' => false,
                              'empty' => false,
                              'truthiness' => 
                              array (
                                'enum' => 'Mago\\Sdk\\Analyzer\\Type\\MixedTruthiness',
                                'name' => 'Undetermined',
                              ),
                            ),
                          ),
                          'flags' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                            'hadTemplate' => false,
                            'byReference' => false,
                            'referenceFree' => false,
                            'possiblyUndefinedFromTry' => false,
                            'possiblyUndefined' => false,
                            'ignoreNullableIssues' => false,
                            'ignoreFalsableIssues' => false,
                            'fromTemplateDefault' => false,
                            'populated' => false,
                            'nullsafeNull' => false,
                            'fromUnspecifiedTemplate' => false,
                          ),
                        ),
                        'nonEmpty' => false,
                      ),
                      1 => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
                        'kind' => 
                        array (
                          'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                          'name' => 'String',
                        ),
                        'refinement' => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\StringType',
                          'literalKind' => 
                          array (
                            'enum' => 'Mago\\Sdk\\Analyzer\\Type\\StringLiteralKind',
                            'name' => 'General',
                          ),
                          'literalValue' => NULL,
                          'numeric' => false,
                          'truthy' => false,
                          'nonEmpty' => false,
                          'callable' => false,
                          'casing' => 
                          array (
                            'enum' => 'Mago\\Sdk\\Analyzer\\Type\\StringCasing',
                            'name' => 'Unspecified',
                          ),
                        ),
                      ),
                    ),
                    'flags' => 
                    array (
                      'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                      'hadTemplate' => false,
                      'byReference' => false,
                      'referenceFree' => false,
                      'possiblyUndefinedFromTry' => false,
                      'possiblyUndefined' => false,
                      'ignoreNullableIssues' => false,
                      'ignoreFalsableIssues' => false,
                      'fromTemplateDefault' => false,
                      'populated' => false,
                      'nullsafeNull' => false,
                      'fromUnspecifiedTemplate' => false,
                    ),
                  ),
                  'then' => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                    'atomicTypes' => 
                    array (
                      0 => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
                        'kind' => 
                        array (
                          'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                          'name' => 'ArrayKey',
                        ),
                        'refinement' => NULL,
                      ),
                    ),
                    'flags' => 
                    array (
                      'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                      'hadTemplate' => false,
                      'byReference' => false,
                      'referenceFree' => false,
                      'possiblyUndefinedFromTry' => false,
                      'possiblyUndefined' => false,
                      'ignoreNullableIssues' => false,
                      'ignoreFalsableIssues' => false,
                      'fromTemplateDefault' => false,
                      'populated' => false,
                      'nullsafeNull' => false,
                      'fromUnspecifiedTemplate' => false,
                    ),
                  ),
                  'otherwise' => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                    'atomicTypes' => 
                    array (
                      0 => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ConditionalType',
                        'subject' => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                          'atomicTypes' => 
                          array (
                            0 => 
                            array (
                              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParameterType',
                              'name' => 'TNewKey',
                              'constraint' => 
                              array (
                                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                                'atomicTypes' => 
                                array (
                                  0 => 
                                  array (
                                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
                                    'kind' => 
                                    array (
                                      'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                                      'name' => 'ArrayKey',
                                    ),
                                    'refinement' => NULL,
                                  ),
                                  1 => 
                                  array (
                                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\NamedObjectType',
                                    'name' => 'UnitEnum',
                                    'parameters' => NULL,
                                    'variances' => NULL,
                                    'static' => false,
                                    'isThis' => false,
                                    'intersections' => NULL,
                                    'remappedParameters' => false,
                                  ),
                                ),
                                'flags' => 
                                array (
                                  'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                                  'hadTemplate' => false,
                                  'byReference' => false,
                                  'referenceFree' => false,
                                  'possiblyUndefinedFromTry' => false,
                                  'possiblyUndefined' => false,
                                  'ignoreNullableIssues' => false,
                                  'ignoreFalsableIssues' => false,
                                  'fromTemplateDefault' => false,
                                  'populated' => true,
                                  'nullsafeNull' => false,
                                  'fromUnspecifiedTemplate' => false,
                                ),
                              ),
                              'definingEntity' => 
                              array (
                                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParent',
                                'kind' => 
                                array (
                                  'enum' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParentKind',
                                  'name' => 'FunctionLike',
                                ),
                                'name' => 'illuminate\\support\\enumerable',
                                'member' => 'keyby',
                              ),
                              'intersections' => NULL,
                            ),
                          ),
                          'flags' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                            'hadTemplate' => false,
                            'byReference' => false,
                            'referenceFree' => false,
                            'possiblyUndefinedFromTry' => false,
                            'possiblyUndefined' => false,
                            'ignoreNullableIssues' => false,
                            'ignoreFalsableIssues' => false,
                            'fromTemplateDefault' => false,
                            'populated' => true,
                            'nullsafeNull' => false,
                            'fromUnspecifiedTemplate' => false,
                          ),
                        ),
                        'target' => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                          'atomicTypes' => 
                          array (
                            0 => 
                            array (
                              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\NamedObjectType',
                              'name' => 'UnitEnum',
                              'parameters' => NULL,
                              'variances' => NULL,
                              'static' => false,
                              'isThis' => false,
                              'intersections' => NULL,
                              'remappedParameters' => false,
                            ),
                          ),
                          'flags' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                            'hadTemplate' => false,
                            'byReference' => false,
                            'referenceFree' => false,
                            'possiblyUndefinedFromTry' => false,
                            'possiblyUndefined' => false,
                            'ignoreNullableIssues' => false,
                            'ignoreFalsableIssues' => false,
                            'fromTemplateDefault' => false,
                            'populated' => true,
                            'nullsafeNull' => false,
                            'fromUnspecifiedTemplate' => false,
                          ),
                        ),
                        'then' => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                          'atomicTypes' => 
                          array (
                            0 => 
                            array (
                              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
                              'kind' => 
                              array (
                                'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                                'name' => 'ArrayKey',
                              ),
                              'refinement' => NULL,
                            ),
                          ),
                          'flags' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                            'hadTemplate' => false,
                            'byReference' => false,
                            'referenceFree' => false,
                            'possiblyUndefinedFromTry' => false,
                            'possiblyUndefined' => false,
                            'ignoreNullableIssues' => false,
                            'ignoreFalsableIssues' => false,
                            'fromTemplateDefault' => false,
                            'populated' => false,
                            'nullsafeNull' => false,
                            'fromUnspecifiedTemplate' => false,
                          ),
                        ),
                        'otherwise' => 
                        array (
                          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                          'atomicTypes' => 
                          array (
                            0 => 
                            array (
                              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParameterType',
                              'name' => 'TNewKey',
                              'constraint' => 
                              array (
                                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                                'atomicTypes' => 
                                array (
                                  0 => 
                                  array (
                                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
                                    'kind' => 
                                    array (
                                      'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
                                      'name' => 'ArrayKey',
                                    ),
                                    'refinement' => NULL,
                                  ),
                                  1 => 
                                  array (
                                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\NamedObjectType',
                                    'name' => 'UnitEnum',
                                    'parameters' => NULL,
                                    'variances' => NULL,
                                    'static' => false,
                                    'isThis' => false,
                                    'intersections' => NULL,
                                    'remappedParameters' => false,
                                  ),
                                ),
                                'flags' => 
                                array (
                                  'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                                  'hadTemplate' => false,
                                  'byReference' => false,
                                  'referenceFree' => false,
                                  'possiblyUndefinedFromTry' => false,
                                  'possiblyUndefined' => false,
                                  'ignoreNullableIssues' => false,
                                  'ignoreFalsableIssues' => false,
                                  'fromTemplateDefault' => false,
                                  'populated' => true,
                                  'nullsafeNull' => false,
                                  'fromUnspecifiedTemplate' => false,
                                ),
                              ),
                              'definingEntity' => 
                              array (
                                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParent',
                                'kind' => 
                                array (
                                  'enum' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParentKind',
                                  'name' => 'FunctionLike',
                                ),
                                'name' => 'illuminate\\support\\enumerable',
                                'member' => 'keyby',
                              ),
                              'intersections' => NULL,
                            ),
                          ),
                          'flags' => 
                          array (
                            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                            'hadTemplate' => false,
                            'byReference' => false,
                            'referenceFree' => false,
                            'possiblyUndefinedFromTry' => false,
                            'possiblyUndefined' => false,
                            'ignoreNullableIssues' => false,
                            'ignoreFalsableIssues' => false,
                            'fromTemplateDefault' => false,
                            'populated' => true,
                            'nullsafeNull' => false,
                            'fromUnspecifiedTemplate' => false,
                          ),
                        ),
                        'negated' => false,
                      ),
                    ),
                    'flags' => 
                    array (
                      'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                      'hadTemplate' => false,
                      'byReference' => false,
                      'referenceFree' => false,
                      'possiblyUndefinedFromTry' => false,
                      'possiblyUndefined' => false,
                      'ignoreNullableIssues' => false,
                      'ignoreFalsableIssues' => false,
                      'fromTemplateDefault' => false,
                      'populated' => true,
                      'nullsafeNull' => false,
                      'fromUnspecifiedTemplate' => false,
                    ),
                  ),
                  'negated' => false,
                ),
              ),
              'flags' => 
              array (
                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                'hadTemplate' => false,
                'byReference' => false,
                'referenceFree' => false,
                'possiblyUndefinedFromTry' => false,
                'possiblyUndefined' => false,
                'ignoreNullableIssues' => false,
                'ignoreFalsableIssues' => false,
                'fromTemplateDefault' => false,
                'populated' => true,
                'nullsafeNull' => false,
                'fromUnspecifiedTemplate' => false,
              ),
            ),
            1 => 
            array (
              'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
              'atomicTypes' => 
              array (
                0 => 
                array (
                  'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParameterType',
                  'name' => 'TValue',
                  'constraint' => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
                    'atomicTypes' => 
                    array (
                      0 => 
                      array (
                        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\MixedType',
                        'issetFromLoop' => false,
                        'nonNull' => false,
                        'empty' => false,
                        'truthiness' => 
                        array (
                          'enum' => 'Mago\\Sdk\\Analyzer\\Type\\MixedTruthiness',
                          'name' => 'Undetermined',
                        ),
                      ),
                    ),
                    'flags' => 
                    array (
                      'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                      'hadTemplate' => false,
                      'byReference' => false,
                      'referenceFree' => false,
                      'possiblyUndefinedFromTry' => false,
                      'possiblyUndefined' => false,
                      'ignoreNullableIssues' => false,
                      'ignoreFalsableIssues' => false,
                      'fromTemplateDefault' => false,
                      'populated' => true,
                      'nullsafeNull' => false,
                      'fromUnspecifiedTemplate' => false,
                    ),
                  ),
                  'definingEntity' => 
                  array (
                    'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParent',
                    'kind' => 
                    array (
                      'enum' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParentKind',
                      'name' => 'ClassLike',
                    ),
                    'name' => 'illuminate\\support\\collection',
                    'member' => NULL,
                  ),
                  'intersections' => NULL,
                ),
              ),
              'flags' => 
              array (
                'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
                'hadTemplate' => false,
                'byReference' => false,
                'referenceFree' => false,
                'possiblyUndefinedFromTry' => false,
                'possiblyUndefined' => false,
                'ignoreNullableIssues' => false,
                'ignoreFalsableIssues' => false,
                'fromTemplateDefault' => false,
                'populated' => true,
                'nullsafeNull' => false,
                'fromUnspecifiedTemplate' => false,
              ),
            ),
          ),
          'variances' => NULL,
          'static' => true,
          'isThis' => false,
          'intersections' => NULL,
          'remappedParameters' => false,
        ),
      ),
      'flags' => 
      array (
        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
        'hadTemplate' => false,
        'byReference' => false,
        'referenceFree' => false,
        'possiblyUndefinedFromTry' => false,
        'possiblyUndefined' => false,
        'ignoreNullableIssues' => false,
        'ignoreFalsableIssues' => false,
        'fromTemplateDefault' => false,
        'populated' => true,
        'nullsafeNull' => false,
        'fromUnspecifiedTemplate' => false,
      ),
    ),
    'fromDocblock' => true,
    'inferred' => true,
  ),
  'ifTrueAssertions' => 
  array (
  ),
  'templates' => 
  array (
    0 => 
    array (
      'objectClass' => 'Mago\\Sdk\\Analyzer\\Metadata\\TemplateMetadata',
      'name' => 'TNewKey',
      'definingEntity' => 
      array (
        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParent',
        'kind' => 
        array (
          'enum' => 'Mago\\Sdk\\Analyzer\\Type\\GenericParentKind',
          'name' => 'FunctionLike',
        ),
        'name' => 'illuminate\\support\\enumerable',
        'member' => 'keyby',
      ),
      'constraint' => 
      array (
        'objectClass' => 'Mago\\Sdk\\Analyzer\\Type',
        'atomicTypes' => 
        array (
          0 => 
          array (
            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarType',
            'kind' => 
            array (
              'enum' => 'Mago\\Sdk\\Analyzer\\Type\\ScalarTypeKind',
              'name' => 'ArrayKey',
            ),
            'refinement' => NULL,
          ),
          1 => 
          array (
            'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\NamedObjectType',
            'name' => 'UnitEnum',
            'parameters' => NULL,
            'variances' => NULL,
            'static' => false,
            'isThis' => false,
            'intersections' => NULL,
            'remappedParameters' => false,
          ),
        ),
        'flags' => 
        array (
          'objectClass' => 'Mago\\Sdk\\Analyzer\\Type\\TypeFlags',
          'hadTemplate' => false,
          'byReference' => false,
          'referenceFree' => false,
          'possiblyUndefinedFromTry' => false,
          'possiblyUndefined' => false,
          'ignoreNullableIssues' => false,
          'ignoreFalsableIssues' => false,
          'fromTemplateDefault' => false,
          'populated' => true,
          'nullsafeNull' => false,
          'fromUnspecifiedTemplate' => false,
        ),
      ),
      'default' => NULL,
      'variance' => 
      array (
        'enum' => 'Mago\\Sdk\\Analyzer\\Type\\Variance',
        'name' => 'Invariant',
      ),
      'readonly' => false,
    ),
  ),
);
}
