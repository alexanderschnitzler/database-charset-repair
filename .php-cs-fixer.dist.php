<?php

declare(strict_types=1);

/*
 * This file is part of the "Database Charset Repair" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2026-2026 Alexander Schnitzler <git@alexanderschnitzler.de>, Schnitzler Softwarelösungen
 */

require_once __DIR__ . '/vendor/autoload.php';

$headerComment = <<<COMMENT
    This file is part of the "Database Charset Repair" Extension for TYPO3 CMS.

    For the full copyright and license information, please read the
    LICENSE.txt file that was distributed with this source code.

     (c) 2026-2026 Alexander Schnitzler <git@alexanderschnitzler.de>, Schnitzler Softwarelösungen
    COMMENT;

$finder = (new Symfony\Component\Finder\Finder())
    ->in(__DIR__)
    ->ignoreDotFiles(false)
    ->ignoreVCS(true)
    ->exclude([
        'public',
        'var',
        'vendor',
    ])
    ->name('/\.php$/')
;

$revertedSymfonyRules = [
    'cast_spaces' => [ // revert @Symfony
        'space' => 'none',
    ],
    'concat_space' => [ // revert @Symfony
        'spacing' => 'one',
    ],
    'increment_style' => false, // revert @Symfony
    'phpdoc_align' => false, // revert @Symfony
    'phpdoc_to_comment' => false, // revert @Symfony
    'single_line_comment_style' => true, // revert @Symfony
    'single_line_throw' => false, // revert @Symfony
    'yoda_style' => false, // revert @Symfony
];

$revertedPHP81Rules = [
    'octal_notation' => false,
];

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRiskyAllowed(false)
    ->setCacheFile(__DIR__ . '/var/cache/.php-cs-fixer.cache')
    ->setRules(array_merge_recursive([
        '@DoctrineAnnotation' => true,
        '@PHP8x2Migration' => true,
        '@PHP8x3Migration' => true,
        '@Symfony' => true,
        '@PER-CS3x0' => true,
        'multiline_whitespace_before_semicolons' => [ // @PhpCsFixer
            'strategy' => 'new_line_for_chained_calls',
        ],
        'no_superfluous_elseif' => true, // @PhpCsFixer
        'no_useless_else' => true, // @PhpCsFixer
        'phpdoc_no_empty_return' => true, // @PhpCsFixer
        'nullable_type_declaration' => [
            'syntax' => 'union',
        ],
        'ordered_types' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'none',
        ],
        'header_comment' => [
            'header' => $headerComment,
            'comment_type' => 'comment',
            'separate' => 'both',
            'location' => 'after_declare_strict',
        ],
    ], $revertedPHP81Rules, $revertedSymfonyRules))
    ->setFinder($finder)
;
