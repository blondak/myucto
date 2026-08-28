<?php

return [
    'source_dir' => __DIR__,
    'output_dir' => __DIR__ . '/pdf',
    'glob' => 'payroll-official-source-monitor.md',
    'lead_blockquote' => 'keep',
    'chapter_page_break' => false,
    'toc_levels' => [2, 3],
    'renderer' => 'chrome',
    'theme' => 'serious',
    'brand' => 'MyÚčto.cz',
    'doc_kind' => 'Technická dokumentace',
    'date_format' => 'j. n. Y',
    'mermaid' => ['enabled' => false],
];
