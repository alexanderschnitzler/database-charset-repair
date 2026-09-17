<?php

/*
 * This file is part of the "Database Charset Repair" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2026-2026 Alexander Schnitzler <git@alexanderschnitzler.de>, Schnitzler Softwarelösungen
 */

/**
 * @phpstan-var array<string, mixed> $EM_CONF
 * @phpstan-var string $_EXTKEY
 */
$EM_CONF[$_EXTKEY] = [
    'title' => 'Database Charset Repair',
    'description' => 'A TYPO3 CLI command that repairs fake latin1, genuine cp1252 and double-encoded content column by column and moves the schema to utf8mb4',
    'category' => 'misc',
    'author' => 'Alexander Schnitzler',
    'author_email' => 'git@alexanderschnitzler.de',
    'author_company' => 'Schnitzler Softwarelösungen',
    'state' => 'stable',
    'version' => '1.1.0',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.3.99',
        ],
    ],
];
