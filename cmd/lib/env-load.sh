#!/usr/bin/env bash
# MyUcto.cz — bezpečný loader `.env` pro shell skripty (Linux / WSL / macOS / git-bash).
#
# PROČ TENHLE SOUBOR EXISTUJE
# ---------------------------
# Skripty dřív dělaly `set -a; . ./.env; set +a`, tedy `.env` **spouštěly jako
# shell kód**. To má dvě vady:
#
#   1. Hodnota s mezerou bez uvozovek rozbije update.
#        MYINVOICE_SMTP_FROM_NAME=Jan Novak
#      → shell nastaví `MYINVOICE_SMTP_FROM_NAME=Jan` a `Novak` se pokusí
#        spustit jako příkaz → `Novak: command not found` → pod `set -e`
#        celý updater skončí hned na druhém řádku (issue #14).
#   2. Je to spuštění cizího kódu — `FOO=$(rm -rf /)` nebo `FOO=x; curl …`
#      by se v `.env` doslova provedlo.
#
# Loader níže proto `.env` jen **parsuje** — žádný `eval`, žádný `source`,
# žádná expanze `$…` ani backticků. Hodnota je celý zbytek řádku za prvním
# `=`; uvozovky (jednoduché i dvojité) se korektně sundají; CRLF a UTF-8 BOM
# nevadí; řádek bez `=` se ignoruje (dřív se spustil jako příkaz).
#
# Sémantika hodnot (záměrně shodná s `docker compose` dotenv parserem tam,
# kde to jde, a s dřívějším chováním `source` u uvozovek):
#   FOO=bar baz            → `bar baz`            (mezery jsou součástí hodnoty)
#   FOO="bar baz"          → `bar baz`
#   FOO='bar baz'          → `bar baz`
#   FOO=bar   # komentář   → `bar`                (inline komentář = mezera + '#')
#   FOO="a#b"              → `a#b`                (v uvozovkách '#' komentář není)
#   FOO="a\"b"             → `a"b`                (v dvojitých uvozovkách jen \" a \\)
#   FOO='a\nb'             → `a\nb`               (jednoduché uvozovky = doslova)
#   FOO=$HOME              → `$HOME`              (BEZ expanze — je to data, ne kód)
#   export FOO=bar         → `bar`
#   # komentář / prázdný / řádek bez '=' → ignoruje se
#
# API (skript se **sourcuje**):
#   dotenv_load  [soubor]   — naparsuje a vyexportuje proměnné (default `.env`)
#   dotenv_print [soubor]   — vypíše `KLÍČ=HODNOTA` na stdout (pro testy/diagnostiku;
#                             newline uvnitř hodnoty se vypíše jako `\n`)
#
# Přímé spuštění (používá test suite):
#   cmd/lib/env-load.sh --print <soubor>
#
# Návratový kód != 0 = chyba (chybějící soubor) → volající skript musí skončit,
# nikdy nesmí pokračovat s poloprázdnou konfigurací.
#
# Windows ekvivalent: cmd/lib/env-load.ps1 (drž obojí v synchronizaci — AGENTS.md).

# Proměnné, které z `.env` NIKDY nepřebíráme — přepsání by rozbilo běžící skript
# (PATH) nebo umožnilo podstrčit kód (BASH_ENV/ENV se spouští při startu shellu).
DOTENV_PROTECTED_KEYS="${DOTENV_PROTECTED_KEYS:-PATH IFS ENV BASH_ENV SHELLOPTS BASHOPTS CDPATH PS1 PS4 LD_PRELOAD LD_LIBRARY_PATH DYLD_INSERT_LIBRARIES}"

# Ořízne bílé znaky zleva.
_dotenv_ltrim() {
  local s="$1"
  printf '%s' "${s#"${s%%[![:space:]]*}"}"
}

# Ořízne bílé znaky zprava.
_dotenv_rtrim() {
  local s="$1"
  printf '%s' "${s%"${s##*[![:space:]]}"}"
}

# Přečte hodnotu v uvozovkách ze zbytku řádku.
#   $1 = znak uvozovky, $2 = zbytek řádku ZA úvodní uvozovkou
# Výstup do globálů: _dv_value (hodnota), _dv_closed (1 = uvozovka uzavřena).
_dotenv_scan_quoted() {
  local quote="$1" rest="$2" ch nxt
  _dv_value=''
  _dv_closed=0
  while [ -n "$rest" ]; do
    ch="${rest%"${rest#?}"}"
    rest="${rest#?}"
    if [ "$quote" = '"' ] && [ "$ch" = '\' ] && [ -n "$rest" ]; then
      nxt="${rest%"${rest#?}"}"
      case "$nxt" in
        '"'|'\')
          _dv_value="${_dv_value}${nxt}"
          rest="${rest#?}"
          continue
          ;;
      esac
      _dv_value="${_dv_value}${ch}"
      continue
    fi
    if [ "$ch" = "$quote" ]; then
      _dv_closed=1
      break
    fi
    _dv_value="${_dv_value}${ch}"
  done
}

# Projde soubor a pro každý platný pár zavolá callback: `$2 KLÍČ HODNOTA`.
_dotenv_each() {
  local file="$1" cb="$2"
  local line key value quote rest cont first=1

  if [ ! -f "$file" ]; then
    echo "ERROR: .env soubor '$file' neexistuje" >&2
    return 1
  fi

  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    if [ "$first" = "1" ]; then
      first=0
      line="${line#$'\xEF\xBB\xBF'}"
    fi
    line="$(_dotenv_ltrim "$line")"

    case "$line" in
      ''|'#'*) continue ;;
    esac
    case "$line" in
      export[[:space:]]*)
        line="${line#export}"
        line="$(_dotenv_ltrim "$line")"
        ;;
    esac
    # Řádek bez '=' není přiřazení. `source` by ho spustil jako příkaz (přesně
    # ten pád z issue #14) — my ho jen přeskočíme.
    case "$line" in
      *=*) ;;
      *) continue ;;
    esac

    key="$(_dotenv_rtrim "${line%%=*}")"
    value="${line#*=}"

    case "$key" in
      ''|[0-9]*|*[!A-Za-z0-9_]*)
        echo "WARN: .env: přeskakuji nevalidní název proměnné '${key}'" >&2
        continue
        ;;
    esac

    value="$(_dotenv_ltrim "$value")"
    case "$value" in
      \"*) quote='"' ;;
      \'*) quote="'" ;;
      *)   quote='' ;;
    esac

    if [ -n "$quote" ]; then
      rest="${value#?}"
      _dotenv_scan_quoted "$quote" "$rest"
      value="$_dv_value"
      # Víceřádková hodnota v uvozovkách — dočti další řádky (stejné chování
      # jako `source` i jako docker compose).
      while [ "$_dv_closed" = "0" ]; do
        if IFS= read -r cont; then
          cont="${cont%$'\r'}"
          _dotenv_scan_quoted "$quote" "$cont"
          value="${value}
${_dv_value}"
        else
          echo "WARN: .env: neuzavřená uvozovka u '${key}' — beru zbytek souboru" >&2
          break
        fi
      done
    else
      # Inline komentář jen po bílém znaku ('a#b' zůstává 'a#b').
      case "$value" in
        *[[:space:]]#*) value="${value%%[[:space:]]#*}" ;;
      esac
      value="$(_dotenv_rtrim "$value")"
    fi

    "$cb" "$key" "$value"
  done < "$file"
}

_dotenv_export_pair() {
  case " $DOTENV_PROTECTED_KEYS " in
    *" $1 "*)
      echo "WARN: .env: ignoruji '$1' — chráněná proměnná shellu" >&2
      return 0
      ;;
  esac
  export "$1=$2"
}

_dotenv_print_pair() {
  local v="$2"
  v="${v//\\/\\\\}"
  v="${v//$'\n'/\\n}"
  printf '%s=%s\n' "$1" "$v"
}

dotenv_load() {
  _dotenv_each "${1:-.env}" _dotenv_export_pair
}

dotenv_print() {
  _dotenv_each "${1:-.env}" _dotenv_print_pair
}

# Přímé spuštění (test suite / ruční kontrola, co skript z `.env` opravdu vyčte).
if [ "${BASH_SOURCE[0]:-$0}" = "$0" ]; then
  set -uo pipefail
  case "${1:-}" in
    --print)
      shift
      dotenv_print "${1:-.env}"
      exit $?
      ;;
    *)
      echo "usage: $0 --print <soubor>" >&2
      exit 2
      ;;
  esac
fi
