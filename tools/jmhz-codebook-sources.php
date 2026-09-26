<?php

declare(strict_types=1);

/**
 * Připnuté zdroje číselníků a datového slovníku JMHZ.
 *
 * `sources` popisuje soubory, ze kterých se katalogy staví; `catalogs` připíná výsledné
 * manifesty včetně počtů položek a číselníků, které musí zůstat prázdnou externí referencí.
 * Zdroj bez `url` se obnovuje ručně — downloader ho nikdy nestahuje, jen ověřuje bajty.
 *
 * @return array{
 *     sources:array<string,array{
 *         target:string,
 *         filename:string,
 *         version:string,
 *         url:string|null,
 *         sha256:string,
 *         byte_length:int,
 *         content_types:list<string>,
 *         signature:string
 *     }>,
 *     catalogs:array<string,array{
 *         schema_version:string,
 *         identity_key:string,
 *         identity:string,
 *         manifest_sha256:string,
 *         counts:array<string,int>,
 *         external_reference_codebooks:list<string>,
 *         base_manifest_sha256:string|null
 *     }>
 * }
 */
return [
    'sources' => [
        'dictionary' => [
            'target' => 'dictionary-1.4.1.6',
            'filename' => 'datovy_slovnik_1.4.1.6.xlsx',
            'version' => '1.4.1.6',
            'url' => 'https://developers.mpsv.cz/assets/documents/'
                . 'f389e547-8bc0-4470-9531-f8319ff4d11e/datovy_slovnik_1.4.1.6.xlsx',
            'sha256' => 'e794a56d3baa48dd876ad45a0deb5b1bb77c17a0cb44a3511e8ef4028be69743',
            'byte_length' => 348845,
            'content_types' => [
                'application/octet-stream',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'signature' => 'zip',
        ],
        'control-catalog' => [
            'target' => 'dictionary-1.4.1.6',
            'filename' => 'Katalog kontrol MH(public)_1.4.2.10.xlsx',
            'version' => '1.4.2.10',
            'url' => 'https://developers.mpsv.cz/assets/documents/'
                . 'c5905c65-8fe0-4a60-826d-e8f0237bb6c8/Katalog kontrol MH(public)_1.4.2.10.xlsx',
            'sha256' => '38a8eaf2d399feaeb92616b5244c0133b9d4acc0c087fa095dda4d75bc83f7b2',
            'byte_length' => 201343,
            'content_types' => [
                'application/octet-stream',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'signature' => 'zip',
        ],
        'scenario-matrix' => [
            'target' => 'dictionary-1.4.1.6',
            'filename' => 'datove_scenare_interakce_povinnosti_MH_1.4.0.2.xlsx',
            'version' => '1.4.0.2',
            'url' => 'https://developers.mpsv.cz/assets/documents/'
                . '9fad6021-73d0-4914-80c8-609716b5697d/datove_scenare_interakce_povinnosti_MH_1.4.0.2.xlsx',
            'sha256' => 'cc282115d58a3744348b500a2dcc6eec4a5899b12753ec756f01fe261fd7ff37',
            'byte_length' => 1404300,
            'content_types' => [
                'application/octet-stream',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'signature' => 'zip',
        ],
        'cisob' => [
            'target' => 'external-codebooks-2026-08-13',
            'filename' => 'sb-2025-511-priloha-2-fragment-1093782.ttl',
            'version' => '2026-01-01',
            'url' => null,
            'sha256' => 'b4f130984c94904d083306b19e47f146e6e703847d315219daf97589a7526d44',
            'byte_length' => 4485761,
            'content_types' => [],
            'signature' => 'utf8-text',
        ],
        'czemalfa' => [
            'target' => 'external-codebooks-2026-08-13',
            'filename' => 'CIS1186_CS_2026-08-13.csv',
            'version' => '2026-08-13',
            'url' => null,
            'sha256' => '940d3ebef6d42294da79c7611654a59aef5beead3a48ffbdffdac9d0f1c58886',
            'byte_length' => 24645,
            'content_types' => [],
            'signature' => 'utf8-text',
        ],
        'cisob-511-legal-coverage' => [
            'target' => 'external-codebooks-2026-08-31',
            'filename' => 'sb-2025-511-priloha-2-fragment-1093782.ttl',
            'version' => '2026-01-01-2026-08-31',
            'url' => null,
            'sha256' => 'b4f130984c94904d083306b19e47f146e6e703847d315219daf97589a7526d44',
            'byte_length' => 4485761,
            'content_types' => [],
            'signature' => 'utf8-text',
        ],
        'czemalfa-august-coverage' => [
            'target' => 'external-codebooks-2026-08-31',
            'filename' => 'CIS1186_CS_2026-08-13.csv',
            'version' => '2026-08-13',
            'url' => null,
            'sha256' => '940d3ebef6d42294da79c7611654a59aef5beead3a48ffbdffdac9d0f1c58886',
            'byte_length' => 24645,
            'content_types' => [],
            'signature' => 'utf8-text',
        ],
        'cisob-145-2026' => [
            'target' => 'external-codebooks-2026-09-01',
            'filename' => 'sb-2026-145-priloha-2-fragment-1836642.ttl',
            'version' => '2026-09-01',
            'url' => null,
            'sha256' => '2263cd58c4dc589e42bc48f13f30db464ffce16e611762364c73f6a1c5bbc003',
            'byte_length' => 3003386,
            'content_types' => [],
            'signature' => 'utf8-text',
        ],
        'czemalfa-2026-08-26' => [
            'target' => 'external-codebooks-2026-09-01',
            'filename' => 'CIS1186_CS_2026-08-26.csv',
            'version' => '2026-08-26',
            'url' => null,
            'sha256' => '940d3ebef6d42294da79c7611654a59aef5beead3a48ffbdffdac9d0f1c58886',
            'byte_length' => 24645,
            'content_types' => [],
            'signature' => 'utf8-text',
        ],
    ],
    'catalogs' => [
        'dictionary-1.4.1.6/manifest.json' => [
            'schema_version' => 'jmhz-spec-package.v1',
            'identity_key' => 'package_key',
            'identity' => 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.10_manifest-v1',
            'manifest_sha256' => 'de478274906eac47d5d51c3a5837a8278ade1fa0dba3fc5dcde6c86d5d05113d',
            'counts' => [
                'attributes' => 442,
                'monthly_attributes' => 234,
                'codebooks' => 46,
                'embedded_codebooks' => 40,
                'external_reference_codebooks' => 6,
                'codebook_entries' => 783,
            ],
            'external_reference_codebooks' => [
                'klasifikace_ekonomickych_ci',
                'klasifikace_v_zamestnani',
                'kody_bank',
                'obce',
                'stat',
                'zdravotni_pojistovny',
            ],
            'base_manifest_sha256' => null,
        ],
        'external-codebooks-2026-08-13/manifest.json' => [
            'schema_version' => 'jmhz-external-codebook-overlay.v1',
            'identity_key' => 'overlay_key',
            'identity' => 'jmhz-external-codebooks-cisob-2026_czemalfa-2026-08-13-v1',
            'manifest_sha256' => '9135eb3396c7ee35268de74b7c3c0b0e330f44e8c918e3fd2f1540d3bff45f13',
            'counts' => [
                'codebooks' => 2,
                'municipalities' => 6254,
                'countries' => 250,
                'entries' => 6504,
            ],
            'external_reference_codebooks' => [],
            'base_manifest_sha256' => 'de478274906eac47d5d51c3a5837a8278ade1fa0dba3fc5dcde6c86d5d05113d',
        ],
        'external-codebooks-2026-08-31/manifest.json' => [
            'schema_version' => 'jmhz-external-codebook-overlay.v1',
            'identity_key' => 'overlay_key',
            'identity' => 'jmhz-external-codebooks-cisob-511-2025-through-2026-08-31_czemalfa-2026-08-13-v1',
            'manifest_sha256' => 'ccacac5e10f7059349c62ee680e70dfa3f96b4a1251661ba2fb6166cd537032c',
            'counts' => [
                'codebooks' => 2,
                'municipalities' => 6254,
                'countries' => 250,
                'entries' => 6504,
            ],
            'external_reference_codebooks' => [],
            'base_manifest_sha256' => 'de478274906eac47d5d51c3a5837a8278ade1fa0dba3fc5dcde6c86d5d05113d',
        ],
        'external-codebooks-2026-09-01/manifest.json' => [
            'schema_version' => 'jmhz-external-codebook-overlay.v1',
            'identity_key' => 'overlay_key',
            'identity' => 'jmhz-external-codebooks-cisob-145-2026_czemalfa-2026-08-26-v1',
            'manifest_sha256' => '66b2481ef5724006e8f64541330d541cd9acec42e914077ed722154c10e220bf',
            'counts' => [
                'codebooks' => 2,
                'municipalities' => 6254,
                'countries' => 250,
                'entries' => 6504,
            ],
            'external_reference_codebooks' => [],
            'base_manifest_sha256' => 'de478274906eac47d5d51c3a5837a8278ade1fa0dba3fc5dcde6c86d5d05113d',
        ],
    ],
];
