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
            'filename' => 'Katalog kontrol MH(public)_1.4.2.7.xlsx',
            'version' => '1.4.2.7',
            'url' => 'https://developers.mpsv.cz/assets/documents/'
                . '5ef0b0d3-e7b2-4788-8fd1-1075d44a27f5/Katalog kontrol MH(public)_1.4.2.7.xlsx',
            'sha256' => 'fbc87a3aab479af1c58bd44aa710e43f5a522d5ebca5de6eec9bbb690ad8a440',
            'byte_length' => 200374,
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
    ],
    'catalogs' => [
        'dictionary-1.4.1.6/manifest.json' => [
            'schema_version' => 'jmhz-spec-package.v1',
            'identity_key' => 'package_key',
            'identity' => 'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.7_manifest-v1',
            'manifest_sha256' => 'f449e605be6f1ee293f3ac359ab4921604c5fc9a225d71fee51b4f94584a0a6b',
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
            'manifest_sha256' => '851a6405fd05840743f521e3d1cee250db26b8573653e3b51b34391d79e68c4b',
            'counts' => [
                'codebooks' => 2,
                'municipalities' => 6254,
                'countries' => 250,
                'entries' => 6504,
            ],
            'external_reference_codebooks' => [],
            'base_manifest_sha256' => 'f449e605be6f1ee293f3ac359ab4921604c5fc9a225d71fee51b4f94584a0a6b',
        ],
    ],
];
