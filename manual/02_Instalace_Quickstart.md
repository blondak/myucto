# 2. Instalace - Quickstart

> Nejrychlejší cesta k běžící aplikaci: Docker s hotovým image z GHCR.
> Kapitola je technická, určená pro osobu, která systém nasazuje (IT
> administrátor, hostingový tým). Běžný uživatel ji může přeskočit.

## 2.1 Kdy to potřebujete

- Chcete mít MyÚčto v provozu za pár minut, bez instalace PHP, Node a databáze na hostiteli.
- Zkoušíte systém na vlastním počítači nebo na testovacím serveru.
- Nasazujete novou instalaci a vybíráte mezi Dockerem a nativním provozem.

## 2.2 Než začnete

Stačí **Git** a **Docker**. Pokud je ještě nemáte:

**Windows** (přes [winget](https://learn.microsoft.com/cs-cz/windows/package-manager/winget/), součást Windows 10/11):

```powershell
winget install --id Git.Git -e
winget install --id Docker.DockerDesktop -e
winget install --id Microsoft.PowerShell -e
```

> [!WARNING]
> **PowerShell 7 je povinný**, ne doporučený. Windows PowerShell 5.1, který
> je na Windows pořád výchozí, zapisuje soubory s BOM a nezná část syntaxe,
> takže by tiše vznikla rozbitá konfigurace. Skript to pozná a raději hned
> skončí s hláškou. Spouštějte ho příkazem `pwsh`, ne `powershell`.

**macOS** (přes [Homebrew](https://brew.sh/)):

```bash
brew install git
brew install --cask docker
```

Případně stáhněte ručně: **Git** na [git-scm.com/downloads](https://git-scm.com/downloads),
**Docker Desktop** na [docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop/).
Na Linuxu nainstalujte **Docker Engine + compose-plugin** z balíčkovacího systému distribuce.

> [!TIP]
> Po instalaci Docker Desktopu ho spusťte a počkejte, až naběhne (ikona v liště).
> Teprve pak fungují příkazy `docker ...`.

## 2.3 Krok za krokem: spuštění přes Docker

1. Stáhněte repozitář a přejděte do něj:

   ```bash
   git clone https://github.com/radekhulan/myucto.git myucto
   cd myucto
   ```

2. Spusťte instalační skript:

   ```bash
   # Linux / macOS
   cmd/docker-ghcr.sh

   # Windows - v PowerShellu 7 (pwsh), ne ve Windows PowerShellu
   pwsh -File .\cmd\docker-ghcr.ps1
   ```

   Skript vygeneruje náhodná hesla a soubor `cfg.docker.php`, stáhne image z GHCR,
   nastartuje stack a spustí migrace.

3. Otevřete v prohlížeči `http://localhost:8080` (plain HTTP, explicitní port `:8080`).
4. Dokončete průvodce prvním spuštěním, viz [7. První spuštění](07_Setup_wizard.md).

**Jak poznáte, že je hotovo:** v prohlížeči naskočí průvodce prvním spuštěním.

## 2.4 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Skript skončí hláškou o verzi PowerShellu | Spouštíte ho ve Windows PowerShellu 5.1. | Spusťte ho přes `pwsh`. |
| Příkazy `docker ...` selhávají | Docker Desktop ještě nenaběhl. | Spusťte Docker Desktop a počkejte na ikonu v liště. |
| Stránka na `localhost:8080` se nenačte | Chybí port nebo se používá HTTPS. | Použijte přesně `http://localhost:8080`. |

## 2.5 Podrobnosti a pravidla

### 2.5.1 Kudy dál

Tři možnosti podle prostředí:

<!-- cols: 20 40 40 -->
| Cesta | Kdy | Detail |
|---|---|---|
| **Docker** | nové instalace, nejrychlejší | [Instalace - Docker](03_Instalace_Docker.md) |
| **Nativní** | tradiční hosting (PHP + MariaDB + IIS/Apache) | [Instalace - Nativní](04_Instalace_Nativni.md) |
| **Po instalaci** | co dělat po prvním startu + CLI nástroje | [Po instalaci a CLI nástroje](05_Po_instalaci.md) |

> [!TIP]
> V produkci pinujte konkrétní verzi image a postavte před stack HTTPS reverse
> proxy, viz [§ 3.8 HTTPS / TLS terminace](03_Instalace_Docker.md#38-krok-za-krokem-https-pres-reverse-proxy).

## 2.6 Související kapitoly

- [3. Instalace - Docker](03_Instalace_Docker.md)
- [4. Instalace - Nativní](04_Instalace_Nativni.md)
- [5. Po instalaci a CLI nástroje](05_Po_instalaci.md)
- [7. První spuštění](07_Setup_wizard.md)
