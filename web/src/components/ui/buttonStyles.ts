/**
 * Sdílené styly akčních tlačítek — jednotný koncept UI (viz AGENTS.md §Frontend).
 *
 * Každá akce má ikonu + sémantickou barvu dle smyslu:
 *   primary = hlavní krok stránky · success = potvrzení/úhrada/vystavení
 *   warning = upomínka/admin zásah · danger = destrukce · neutral = utility
 *   accent  = doplňkové zvýraznění
 *
 * ActionBar (detailové stránky) i samostatná tlačítka mimo něj čerpají odsud —
 * žádná ad-hoc tlačítka bez ikony a sémantické barvy. Plná (FILLED) je typicky
 * jen 1 primární akce stránky; ostatní OUTLINE; utility/destrukce v „…" menu.
 */
export type ActionVariant = 'primary' | 'success' | 'warning' | 'danger' | 'neutral' | 'accent'

/*
 * `transition-all` + `active:translate-y-px`: tlačítko musí na stisk reagovat,
 * jinak působí jako obrázek. Posun o pixel dolů je nejlevnější „tactile" signál
 * a nepotřebuje žádný stav v JS.
 */
export const BTN_BASE =
  'cursor-pointer px-3 h-9 text-sm font-medium rounded-md inline-flex items-center gap-1.5 whitespace-nowrap ' +
  'transition-all duration-150 active:translate-y-px disabled:opacity-50 disabled:cursor-not-allowed ' +
  'disabled:active:translate-y-0 disabled:shadow-none'

/*
 * Plná tlačítka nesou stín: bez něj splývají s plochou karty a hlavní akce
 * stránky nemá žádnou převahu nad ostatními. Stín je v brand indigu (viz
 * --shadow-* v main.css), takže sedí do palety a nešpiní barvu tlačítka.
 */
export const FILLED: Record<ActionVariant, string> = {
  primary: 'bg-primary-600 hover:bg-primary-700 text-white shadow-sm hover:shadow-md',
  success: 'bg-success-600 hover:bg-success-700 text-white shadow-sm hover:shadow-md',
  warning: 'bg-warning-500 hover:bg-warning-600 text-white shadow-sm hover:shadow-md',
  danger:  'bg-danger-600 hover:bg-danger-700 text-white shadow-sm hover:shadow-md',
  neutral: 'bg-neutral-700 hover:bg-neutral-800 text-white shadow-sm hover:shadow-md',
  accent:  'bg-accent-600 hover:bg-accent-700 text-white shadow-sm hover:shadow-md',
}

/*
 * Outline varianty dostávají na hover sytější okraj — samotná změna pozadí
 * na -50 tintu je v dark módu skoro neviditelná a tlačítko působilo mrtvě.
 */
export const OUTLINE: Record<ActionVariant, string> = {
  primary: 'border border-primary-500/40 text-primary-700 hover:bg-primary-50 hover:border-primary-500/70',
  success: 'border border-success-500/50 text-success-600 hover:bg-success-50 hover:border-success-500/80',
  warning: 'border border-warning-500/50 text-warning-600 hover:bg-warning-50 hover:border-warning-500/80',
  danger:  'border border-danger-500/50 text-danger-500 hover:bg-danger-50 hover:border-danger-500/80',
  neutral: 'border border-neutral-300 text-neutral-700 hover:bg-neutral-50 hover:border-neutral-400',
  accent:  'border border-accent-500/40 text-accent-700 hover:bg-accent-50 hover:border-accent-500/70',
}

export const MENU_ICON: Record<ActionVariant, string> = {
  primary: 'text-primary-600',
  success: 'text-success-600',
  warning: 'text-warning-600',
  danger:  'text-danger-600',
  neutral: 'text-neutral-400',
  accent:  'text-accent-600',
}

/** Hotové kompletní class stringy pro samostatná tlačítka mimo ActionBar. */
export function btnFilled(variant: ActionVariant = 'primary'): string {
  return `${BTN_BASE} ${FILLED[variant]}`
}
export function btnOutline(variant: ActionVariant = 'neutral'): string {
  return `${BTN_BASE} ${OUTLINE[variant]}`
}

// Kompaktní varianta (h-7, text-xs) pro husté řádky tabulek — akce v řádku výpisu
// transakcí apod., kde plná výška h-9 zabere moc místa.
export const BTN_SM_BASE =
  'cursor-pointer px-2 h-7 text-xs font-medium rounded-md inline-flex items-center gap-1 whitespace-nowrap ' +
  'transition-all duration-150 active:translate-y-px disabled:opacity-50 disabled:cursor-not-allowed ' +
  'disabled:active:translate-y-0 disabled:shadow-none'

export function btnFilledSm(variant: ActionVariant = 'primary'): string {
  return `${BTN_SM_BASE} ${FILLED[variant]}`
}
export function btnOutlineSm(variant: ActionVariant = 'neutral'): string {
  return `${BTN_SM_BASE} ${OUTLINE[variant]}`
}

// Čtvercová ikona bez popisku pro akce ve sloupci tabulky. Textová varianta
// (`btnOutlineSm`) je v úzkém sloupci širší než zbytek řádku a akce se zalomí
// pod sebe — řádek pak přeroste ostatní. Popisek nese `title`/`aria-label`,
// takže se význam neztratí. Používat JEN tam, kde akci jistí potvrzení nebo je
// vratná; nevratná akce bez popisku je past.
export const BTN_ICON_SM_BASE =
  'cursor-pointer w-7 h-7 rounded-md inline-flex items-center justify-center shrink-0 ' +
  'transition-all duration-150 active:translate-y-px disabled:opacity-50 disabled:cursor-not-allowed ' +
  'disabled:active:translate-y-0'

export function btnIconSm(variant: ActionVariant = 'neutral'): string {
  return `${BTN_ICON_SM_BASE} ${OUTLINE[variant]}`
}

// ─── ikony (stroke, viewBox 24) — sjednocené z původních toolbarů ───
export const ICONS = {
  edit:      'M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
  send:      'M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z',
  check:     'M5 13l4 4L19 7',
  chart:     'M9 17v-6m3 6v-4m3 4v-2M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z',
  trash:     'M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3',
  doc:       'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  checkCircle: 'M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  coin:      'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  bell:      'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z',
  copy:      'M8 16H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2m-6 12h8a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z',
  download:  'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
  upload:    'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-8l-4-4m0 0l-4 4m4-4v12',
  qr:        'M4 4h6v6H4V4zm0 10h6v6H4v-6zM14 4h6v6h-6V4zm2 10h2m2 0v2m-4 2v2m4 0h2m-2-6h.01M14 18h.01',
  inbox:     'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2',
  uturn:     'M3 10h10a8 8 0 0 1 8 8v2M3 10l6 6m-6-6l6-6',
  x:         'M6 18L18 6M6 6l12 12',
  badgeCheck: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  link:      'M13.828 10.172a4 4 0 0 1 0 5.656l-3 3a4 4 0 0 1-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 0 1 0-5.656l3-3a4 4 0 0 1 5.656 5.656l-1.5 1.5',
  archive:   'M5 8h14M5 8a2 2 0 1 1 0-4h14a2 2 0 1 1 0 4M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8m-9 4h4',
  user:      'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z',
  play:      'M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  pause:     'M10 9v6m4-6v6m7-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  plus:      'M12 6v6m0 0v6m0-6h6m-6 0H6',
  cycle:     'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06',
  // Sklad (Epic SKLAD)
  box:       'M20 7.5l-8-4-8 4m16 0l-8 4m8-4v9l-8 4m0-9L4 7.5m8 4v9M4 7.5v9l8 4',
  warehouse: 'M3 21h18M4 21V8l8-5 8 5v13M9 21v-6h6v6M9 11h.01M15 11h.01M12 8h.01',
  swap:      'M7 8h13m0 0l-4-4m4 4l-4 4M17 16H4m0 0l4 4m-4-4l4-4',
  clipboardCheck: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-7 9l2 2 4-4',
  stock_items:      'M20 7.5l-8-4-8 4m16 0l-8 4m8-4v9l-8 4m0-9L4 7.5m8 4v9M4 7.5v9l8 4',
  stock_documents:  'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  stock_takes:      'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-7 9l2 2 4-4',
  stock_warehouses: 'M3 21h18M4 21V8l8-5 8 5v13M9 21v-6h6v6M9 11h.01M15 11h.01M12 8h.01',
  factory:          'M3 21h18M3 10l5 3V10l5 3V10l5 3v8H3V10z',
  tag:              'M9.5 9.5h.01M21 11.5l-9-9H4a2 2 0 0 0-2 2v8l9 9a2 2 0 0 0 2.8 0l7.2-7.2a2 2 0 0 0 0-2.8z',
  folderOpen:       'M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-6l-2-2H5a2 2 0 0 0-2 2z M3 9h18',
  colorSwatch:      'M12 21a9 9 0 1 0-9-9c0 1.488 1.053 2.734 2.455 3.03l.22.047a2.122 2.122 0 0 1 1.705 1.705l.047.22A3.076 3.076 0 0 0 12 21zm-3.5-12a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0zm5-2a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0zm4 5a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0z',
  lock:             'M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zM8 11V7a4 4 0 1 1 8 0v4',
} as const

export type ActionIcon = keyof typeof ICONS
