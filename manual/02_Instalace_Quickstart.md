# 2. Instalace — Quickstart

> Tato a následující kapitoly jsou technické — určené pro osobu, která systém
> nasazuje (IT administrátor, hostingový tým). Běžný uživatel je může přeskočit.

Chceš být do pár minut v aplikaci? Nejrychlejší cesta je **Docker s pre-built
image z GHCR** — nepotřebuješ na hostiteli PHP, Node ani databázi.

## 2.1 Co budeš potřebovat

Stačí **Git** a **Docker**. Pokud je ještě nemáš:

**Windows** (přes [winget](https://learn.microsoft.com/cs-cz/windows/package-manager/winget/), součást Windows 10/11):

```powershell
winget install --id Git.Git -e
winget install --id Docker.DockerDesktop -e
winget install --id Microsoft.PowerShell -e
```

> ⚠️ **PowerShell 7 je povinný**, ne doporučený. Windows PowerShell 5.1, který
> je na Windows pořád výchozí, zapisuje soubory s BOM a nezná část syntaxe,
> takže by tiše vznikla rozbitá konfigurace. Skript to pozná a raději hned
> skončí s hláškou — spouštěj ho příkazem `pwsh`, ne `powershell`.

**macOS** (přes [Homebrew](https://brew.sh/)):

```bash
brew install git
brew install --cask docker
```

Případně staženo ručně: **Git** → [git-scm.com/downloads](https://git-scm.com/downloads),
**Docker Desktop** → [docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop/).
Na Linuxu nainstaluj **Docker Engine + compose-plugin** z balíčkovacího systému distribuce.

> 🛈 Po instalaci Docker Desktopu ho spusť a počkej, až naběhne (ikona v liště).
> Teprve pak fungují příkazy `docker …`.

## 2.2 Spuštění

```bash
git clone https://github.com/radekhulan/myucto.git myucto
cd myucto

# Linux / macOS
cmd/docker-ghcr.sh

# Windows — v PowerShellu 7 (pwsh), ne ve Windows PowerShellu
pwsh -File .\cmd\docker-ghcr.ps1
```

Skript vygeneruje náhodná hesla + `cfg.docker.php`, stáhne image z GHCR,
nastartuje stack a spustí migrace. Pak otevři **👉 http://localhost:8080**
(plain HTTP, explicitní port `:8080`) — naskočí setup wizard.

## 2.3 Kudy dál

Tři možnosti podle prostředí:

| Cesta | Kdy | Detail |
|---|---|---|
| **Docker** | nové instalace, nejrychlejší | [Instalace — Docker](03_Instalace_Docker.md) |
| **Nativní** | tradiční hosting (PHP + MariaDB + IIS/Apache) | [Instalace — Nativní](04_Instalace_Nativni.md) |
| **Po instalaci** | co dělat po prvním startu + CLI nástroje | [Po instalaci a CLI nástroje](05_Po_instalaci.md) |

> 💡 V produkci pinuj konkrétní verzi image a postav před stack HTTPS reverse
> proxy — viz [§ 3.8 HTTPS / TLS terminace](03_Instalace_Docker.md#38-https-tls-terminace).
