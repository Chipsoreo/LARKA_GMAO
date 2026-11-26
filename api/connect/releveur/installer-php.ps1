#Requires -Version 5.1
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
#
# This file is part of Larka, proprietary software by Mickaël Larcin.
# All rights reserved. Use is subject to the license terms; copying,
# distribution, modification or reverse-engineering without the author's
# prior written permission is prohibited. See the LICENSE file for details.
<#
.SYNOPSIS
  Installe PHP pour Larka, dans le dossier php\ de Larka.

.DESCRIPTION
  Appelé par Larka.cmd au premier lancement, et par « Larka.cmd php ».

  1. Télécharge la dernière version publiée de PHP <Version> pour Windows
     (NTS, x64) sur windows.php.net et vérifie son empreinte SHA-256 publiée,
     ou installe l'archive indiquée par -Archive (poste sans accès à Internet).
  2. Vérifie le runtime Visual C++ 2015-2022 (x64) que PHP exige ; s'il
     manque ou s'il est trop ancien, l'installe après accord (Windows demande
     alors l'autorisation d'un administrateur).
  3. Prépare les certificats racine (php\cacert.pem) : liste de Mozilla
     (curl.se) et magasin de Windows (autorités d'entreprise comprises).
  4. Écrit php\php.ini et vérifie que PHP démarre avec les extensions dont
     Larka a besoin.

  Rien n'est écrit hors du dossier de Larka, sauf le runtime Visual C++
  (composant partagé de Windows) s'il fallait l'installer. Le PHP déjà en
  place n'est remplacé qu'une fois le nouveau vérifié.

.PARAMETER Racine
  Dossier de Larka (par défaut : deux niveaux au-dessus de ce script).
.PARAMETER Version
  Branche de PHP (par défaut 8.3, celle avec laquelle Larka est éprouvé).
.PARAMETER Archive
  Archive PHP déjà téléchargée (php-8.3.x-nts-Win32-vs16-x64.zip).
.PARAMETER Forcer
  Recherche une version plus récente et l'installe (mise à jour).
#>
[CmdletBinding()]
param(
    [string]$Racine,
    [string]$Version = '8.3',
    [string]$Archive,
    [switch]$Forcer
)

$ErrorActionPreference = 'Stop'
$ProgressPreference    = 'SilentlyContinue'      # la barre de progression ralentit les téléchargements
$SurWindows = ($PSVersionTable.PSVersion.Major -lt 6) -or $IsWindows

# Extensions chargées par php.ini, et celles qu'on exige au contrôle final.
$ExtensionsIni    = @('curl', 'mbstring', 'exif', 'fileinfo', 'gd', 'intl', 'openssl',
                      'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'sodium', 'sqlite3', 'zip')
$ExtensionsExigees = @('curl', 'mbstring', 'exif', 'fileinfo', 'intl', 'openssl', 'pdo_sqlite', 'sodium', 'zip')

# Sources officielles de PHP pour Windows (la seconde est le miroir de php.net).
$Sources = @('https://windows.php.net/downloads/releases/', 'https://downloads.php.net/~windows/releases/')
if ($env:LARKA_PHP_SOURCE) { $Sources = @($env:LARKA_PHP_SOURCE.TrimEnd('/') + '/') + $Sources }

function Write-Etape([string]$m)  { Write-Host "  ► $m" -ForegroundColor Cyan }
function Write-Ok([string]$m)     { Write-Host "  √ $m" -ForegroundColor Green }
function Write-Alerte([string]$m) { Write-Host "  ! $m" -ForegroundColor Yellow }

# ── Réseau ───────────────────────────────────────────────────────────────────
try {
    # Windows PowerShell 5.1 propose encore TLS 1.0 par défaut ; php.net exige TLS 1.2.
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
} catch { }

function New-ClientWeb {
    $wc = New-Object System.Net.WebClient
    $wc.Headers['User-Agent'] = 'Larka-Windows (PowerShell ' + $PSVersionTable.PSVersion + ')'
    $wc.Encoding = [Text.Encoding]::UTF8
    try {
        # Proxy du système, avec l'identité Windows de l'utilisateur (proxys d'entreprise).
        $proxy = [Net.WebRequest]::GetSystemWebProxy()
        $proxy.Credentials = [Net.CredentialCache]::DefaultNetworkCredentials
        $wc.Proxy = $proxy
    } catch { }
    return $wc
}
function Get-Texte([string]$Url) {
    $wc = New-ClientWeb
    try { return $wc.DownloadString($Url) } finally { $wc.Dispose() }
}
function Save-Url([string]$Url, [string]$Fichier) {
    $wc = New-ClientWeb
    try { $wc.DownloadFile($Url, $Fichier) } finally { $wc.Dispose() }
}
function Get-Sha256([string]$Fichier) { return (Get-FileHash -Path $Fichier -Algorithm SHA256).Hash.ToLowerInvariant() }

# Exécutable externe, sans que sa sortie d'erreur interrompe le script.
function Invoke-Programme([string]$Exe, [string[]]$Arguments) {
    $avant = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $sortie = & $Exe @Arguments 2>&1 | ForEach-Object { "$_" }
        return [pscustomobject]@{ Code = $LASTEXITCODE; Sortie = (@($sortie) -join "`n") }
    } catch {
        return [pscustomobject]@{ Code = -1; Sortie = $_.Exception.Message }
    } finally {
        $ErrorActionPreference = $avant
    }
}

# ── PHP publié ───────────────────────────────────────────────────────────────
function Find-PublicationPhp([string]$Branche) {
    $erreurs = @()
    foreach ($base in $Sources) {
        try {
            $json = Get-Texte ($base + 'releases.json') | ConvertFrom-Json
        } catch {
            $erreurs += ($base + ' : ' + $_.Exception.Message)
            continue
        }
        $propriete = $json.PSObject.Properties[$Branche]
        if (-not $propriete) { $erreurs += ($base + " : branche $Branche absente"); continue }
        $publication = $propriete.Value
        $builds = @($publication.PSObject.Properties |
            Where-Object { $_.Name -match '^nts-vs(\d+)-x64$' } |
            Sort-Object { [int]($_.Name -replace '^nts-vs(\d+)-x64$', '$1') } -Descending)
        if ($builds.Count -eq 0) { $erreurs += ($base + ' : aucune version NTS x64'); continue }
        $zip = $builds[0].Value.zip
        if (-not $zip -or -not $zip.path -or -not $zip.sha256) { $erreurs += ($base + ' : description incomplète'); continue }
        return [pscustomobject]@{
            Version = [string]$publication.version
            Fichier = [string]$zip.path
            Sha256  = ([string]$zip.sha256).ToLowerInvariant()
            Base    = $base
        }
    }
    throw ("Impossible de connaître la dernière version de PHP $Branche :`n      " + ($erreurs -join "`n      "))
}

function Get-ArchivePhp($Publication, [string]$Dossier) {
    $dest = Join-Path $Dossier $Publication.Fichier
    $dernier = ''
    # Une version remplacée entre-temps part dans « archives/ ».
    foreach ($url in @(($Publication.Base + $Publication.Fichier), ($Publication.Base + 'archives/' + $Publication.Fichier))) {
        try {
            Write-Etape "Téléchargement : $url"
            Save-Url $url $dest
            break
        } catch {
            $dernier = $_.Exception.Message
            Remove-Item $dest -Force -ErrorAction SilentlyContinue
        }
    }
    if (-not (Test-Path $dest)) { throw "Téléchargement impossible : $dernier" }
    if ((Get-Sha256 $dest) -ne $Publication.Sha256) {
        Remove-Item $dest -Force
        throw ("Empreinte SHA-256 inattendue pour " + $Publication.Fichier + " : archive abîmée ou modifiée. Rien n'a été installé.")
    }
    Write-Ok 'Archive vérifiée (empreinte SHA-256 conforme à la publication de php.net)'
    return $dest
}

function Expand-Php([string]$Zip, [string]$Cible) {
    if (Test-Path $Cible) { Remove-Item $Cible -Recurse -Force }
    New-Item -ItemType Directory -Path $Cible | Out-Null
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::ExtractToDirectory($Zip, $Cible)
    # Archive rangée dans un sous-dossier : on en remonte le contenu.
    if (-not (Test-Path (Join-Path $Cible 'php.exe'))) {
        $sous = @(Get-ChildItem $Cible -Directory | Where-Object { Test-Path (Join-Path $_.FullName 'php.exe') })
        if ($sous.Count -eq 1) {
            Get-ChildItem $sous[0].FullName -Force | Move-Item -Destination $Cible
            Remove-Item $sous[0].FullName -Recurse -Force
        }
    }
    if (-not (Test-Path (Join-Path $Cible 'php.exe'))) {
        throw "Cette archive ne contient pas php.exe : est-ce bien PHP pour Windows (Zip « VS16 x64 Non Thread Safe ») ?"
    }
}

# ── Runtime Visual C++ ───────────────────────────────────────────────────────
function Get-VersionRuntimeVC {
    if (-not $SurWindows) { return $null }
    $systeme = [Environment]::SystemDirectory
    if ([Environment]::Is64BitOperatingSystem -and -not [Environment]::Is64BitProcess) { $systeme = Join-Path $env:windir 'Sysnative' }
    $dll = Join-Path $systeme 'vcruntime140.dll'
    if (-not (Test-Path $dll)) { return $null }
    $vi = (Get-Item $dll).VersionInfo
    return New-Object Version $vi.FileMajorPart, $vi.FileMinorPart, $vi.FileBuildPart
}

function Install-RuntimeVC {
    $exe = Join-Path ([IO.Path]::GetTempPath()) ('vc_redist.x64-' + [guid]::NewGuid().ToString('N') + '.exe')
    Write-Etape 'Téléchargement du runtime Visual C++ 2015-2022 (x64) depuis microsoft.com'
    Save-Url 'https://aka.ms/vs/17/release/vc_redist.x64.exe' $exe
    try {
        $signature = Get-AuthenticodeSignature $exe
        if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') {
            throw "Le programme téléchargé n'est pas signé par Microsoft : installation annulée."
        }
        Write-Etape "Installation — Windows va demander l'autorisation d'un administrateur…"
        try {
            $p = Start-Process -FilePath $exe -ArgumentList '/install', '/quiet', '/norestart' -Verb RunAs -Wait -PassThru
        } catch {
            throw ("Installation du runtime refusée ou impossible (" + $_.Exception.Message + ").")
        }
        if ($p.ExitCode -notin 0, 1638, 3010) { throw ("Le programme d'installation du runtime a échoué (code " + $p.ExitCode + ").") }
        if ($p.ExitCode -eq 3010) { Write-Alerte 'Windows demandera un redémarrage pour terminer l''installation du runtime.' }
        Write-Ok 'Runtime Visual C++ installé'
    } finally {
        Remove-Item $exe -Force -ErrorAction SilentlyContinue
    }
}

# PHP démarre-t-il ? (sinon : runtime absent ou trop ancien)
function Test-DemarragePhp([string]$Dossier) {
    $r = Invoke-Programme (Join-Path $Dossier 'php.exe') @('-n', '-r', 'echo PHP_VERSION;')
    $ok = ($r.Code -eq 0) -and ($r.Sortie -match '^\s*\d+\.\d+\.\d+') -and ($r.Sortie -notmatch 'not compatible')
    return [pscustomobject]@{ Ok = $ok; Sortie = $r.Sortie.Trim() }
}

function Confirm-RuntimeVC([string]$Dossier) {
    if ($SurWindows -and -not (Get-VersionRuntimeVC)) {
        # Sans la DLL, Windows afficherait une fenêtre d'erreur au lancement de PHP.
        Write-Alerte 'Le runtime Visual C++ 2015-2022 (x64), indispensable à PHP, est absent.'
        Install-RuntimeVC
    }
    $t = Test-DemarragePhp $Dossier
    if (-not $t.Ok -and $SurWindows) {
        Write-Alerte ("PHP ne démarre pas (" + $t.Sortie + ") : mise à jour du runtime Visual C++.")
        Install-RuntimeVC
        $t = Test-DemarragePhp $Dossier
    }
    if (-not $t.Ok) { throw ("PHP ne démarre pas : " + $t.Sortie) }
    return $t.Sortie
}

# ── Certificats racine ───────────────────────────────────────────────────────
function Write-Certificats([string]$Fichier) {
    $blocs = New-Object System.Collections.Generic.List[string]
    $nbMozilla = 0; $nbWindows = 0
    $tmp = Join-Path ([IO.Path]::GetTempPath()) ('cacert-' + [guid]::NewGuid().ToString('N') + '.pem')
    try {
        Save-Url 'https://curl.se/ca/cacert.pem' $tmp
        $reference = ((Get-Texte 'https://curl.se/ca/cacert.pem.sha256').Trim() -split '\s+')[0].ToLowerInvariant()
        if ((Get-Sha256 $tmp) -eq $reference) {
            $texte = [IO.File]::ReadAllText($tmp)
            $blocs.Add($texte.Trim())
            $nbMozilla = ([regex]::Matches($texte, 'BEGIN CERTIFICATE')).Count
        } else {
            Write-Alerte 'Liste de Mozilla ignorée : empreinte inattendue.'
        }
    } catch {
        Write-Alerte ('Liste de Mozilla indisponible (' + $_.Exception.Message + ') : magasin de Windows seul.')
    } finally {
        Remove-Item $tmp -Force -ErrorAction SilentlyContinue
    }
    if ($SurWindows) {
        foreach ($magasin in @('Cert:\LocalMachine\Root', 'Cert:\CurrentUser\Root')) {
            foreach ($c in @(Get-ChildItem $magasin -ErrorAction SilentlyContinue)) {
                if ($c.NotAfter -lt (Get-Date)) { continue }
                $b64 = [Convert]::ToBase64String($c.RawData)
                $lignes = for ($i = 0; $i -lt $b64.Length; $i += 64) { $b64.Substring($i, [Math]::Min(64, $b64.Length - $i)) }
                $blocs.Add("-----BEGIN CERTIFICATE-----`n" + ($lignes -join "`n") + "`n-----END CERTIFICATE-----")
                $nbWindows++
            }
        }
    }
    if ($blocs.Count -eq 0) {
        Write-Alerte 'Aucun certificat racine : les connexions HTTPS de Larka (mises à jour, OAuth…) échoueront.'
        return
    }
    [IO.File]::WriteAllText($Fichier, (($blocs -join "`n`n") + "`n"), (New-Object Text.UTF8Encoding $false))
    Write-Ok "Certificats racine : $nbMozilla (Mozilla) + $nbWindows (Windows)"
}

# ── php.ini ──────────────────────────────────────────────────────────────────
function Write-PhpIni([string]$Fichier, [bool]$AvecCertificats) {
    $extensions = ($ExtensionsIni | ForEach-Object { "extension=$_" }) -join "`r`n"
    # Chemins lus dans la variable LARKA_PHP_DIR, posée par Larka.cmd : le dossier
    # de Larka peut être déplacé, et un chemin contenant des parenthèses ou « & »
    # (« Documents (2026) ») n'est pas lu comme une expression par PHP.
    $chemins = @('extension_dir = "${LARKA_PHP_DIR}\ext"')
    if ($AvecCertificats) {
        $chemins += 'curl.cainfo = "${LARKA_PHP_DIR}\cacert.pem"'
        $chemins += 'openssl.cafile = "${LARKA_PHP_DIR}\cacert.pem"'
    }
    $chemins = $chemins -join "`r`n"
    $ini = @"
; Larka — réglages de PHP pour Windows, écrits par deploy\windows\installer-php.ps1.
; Lancez PHP par Larka.cmd : il pose LARKA_PHP_DIR (dossier de PHP), d'où ce
; fichier tire ses chemins.
; Valeurs alignées sur le pool PHP-FPM de production (deploy/php-fpm-gmao.conf).

[PHP]
$chemins
memory_limit = 512M
max_execution_time = 300
max_input_time = 300
post_max_size = 512M
upload_max_filesize = 500M
max_file_uploads = 50
max_input_vars = 5000
default_charset = "UTF-8"
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED
html_errors = Off
allow_url_include = Off
realpath_cache_size = 4096K
realpath_cache_ttl = 600
zend.assertions = -1

[Date]
date.timezone = "Europe/Paris"

[Session]
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.gc_maxlifetime = 86400

[Extensions]
$extensions

[opcache]
; Le serveur intégré compilerait sinon chaque script à chaque requête.
; enable_cli reste à 0 ici : le lanceur le passe à 1 pour le seul serveur.
; Deux PHP en ligne de commande partageant la même mémoire OPcache (le
; serveur et un « Larka.cmd etat », par exemple) peuvent se bloquer.
zend_extension=opcache
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
"@
    [IO.File]::WriteAllText($Fichier, ($ini -replace "`r?`n", "`r`n") + "`r`n", (New-Object Text.UTF8Encoding $false))
}

function Test-Extensions([string]$Dossier) {
    $env:LARKA_PHP_DIR = $Dossier
    $r = Invoke-Programme (Join-Path $Dossier 'php.exe') @('-c', (Join-Path $Dossier 'php.ini'), '-m')
    $chargees = @($r.Sortie -split "`n" | ForEach-Object { $_.Trim().ToLowerInvariant() })
    $manquantes = @($ExtensionsExigees | Where-Object { $chargees -notcontains $_ })
    if ($r.Sortie -match 'Unable to load dynamic library|Warning') {
        Write-Alerte ('PHP signale : ' + (($r.Sortie -split "`n" | Where-Object { $_ -match 'Warning|Unable' }) -join ' / '))
    }
    if ($manquantes.Count -gt 0) { throw ('Extension(s) PHP manquante(s) : ' + ($manquantes -join ', ')) }
    if ($chargees -notcontains 'zend opcache') { Write-Alerte 'OPcache indisponible : Larka fonctionnera, plus lentement.' }
}

# PHP de ce dossier en cours d'exécution ? (ses fichiers ne peuvent alors pas être remplacés)
function Test-PhpEnCours([string]$Dossier) {
    if (-not $SurWindows) { return $false }
    $exe = Join-Path $Dossier 'php.exe'
    foreach ($p in @(Get-Process -Name php -ErrorAction SilentlyContinue)) {
        try { if ($p.Path -and ($p.Path -ieq $exe)) { return $true } } catch { }
    }
    return $false
}

function Read-InfoPhp([string]$Dossier) {
    $f = Join-Path $Dossier 'larka-php.json'
    if (-not (Test-Path $f)) { return $null }
    try { return Get-Content $f -Raw | ConvertFrom-Json } catch { return $null }
}

function Write-InfoPhp([string]$Dossier, [string]$VersionPhp, [string]$Origine, [string]$Empreinte) {
    $info = [ordered]@{ version = $VersionPhp; origine = $Origine; sha256 = $Empreinte; installe_le = (Get-Date).ToString('s') }
    [IO.File]::WriteAllText((Join-Path $Dossier 'larka-php.json'), ($info | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))
}

# ═════════════════════════════════════════════════════════════════════════════
try {
    if (-not $Racine) { $Racine = Split-Path -Parent (Split-Path -Parent $PSScriptRoot) }
    $Racine  = [IO.Path]::GetFullPath($Racine)
    $Php     = Join-Path $Racine 'php'
    $Nouveau = Join-Path $Racine 'php.nouveau'
    $Ancien  = Join-Path $Racine 'php.ancien'

    if ($SurWindows -and -not [Environment]::Is64BitOperatingSystem) {
        throw 'Windows 32 bits n''est pas pris en charge : Larka utilise PHP 64 bits.'
    }

    Write-Host ''
    Write-Host '  Larka — PHP pour Windows' -ForegroundColor Cyan
    Write-Host ''

    $present = Test-Path (Join-Path $Php 'php.exe')

    # ── PHP déjà là, sans mise à jour demandée : seulement la configuration ──
    if ($present -and -not $Forcer -and -not $Archive) {
        Write-Etape ('PHP présent dans ' + $Php + ' : vérification de sa configuration')
        $v = Confirm-RuntimeVC $Php
        if (-not (Test-Path (Join-Path $Php 'cacert.pem'))) { Write-Certificats (Join-Path $Php 'cacert.pem') }
        if (-not (Test-Path (Join-Path $Php 'php.ini'))) {
            Write-PhpIni (Join-Path $Php 'php.ini') (Test-Path (Join-Path $Php 'cacert.pem'))
            Write-Ok 'php.ini écrit'
        }
        Test-Extensions $Php
        Write-Ok "PHP $v prêt"
        exit 0
    }

    $tmp = Join-Path ([IO.Path]::GetTempPath()) ('larka-php-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmp | Out-Null
    try {
        if ($Archive) {
            $Archive = [IO.Path]::GetFullPath($Archive)
            if (-not (Test-Path $Archive)) { throw "Archive introuvable : $Archive" }
            Write-Etape "Archive fournie : $Archive"
            $zip = $Archive
            $origine = $Archive
            $empreinte = Get-Sha256 $Archive
        } else {
            Write-Etape "Recherche de la dernière version de PHP $Version…"
            $pub = Find-PublicationPhp $Version
            $info = Read-InfoPhp $Php
            if ($present -and $info -and (([string]$info.version -replace '-.*$', '') -eq $pub.Version)) {
                Write-Ok ("PHP " + $pub.Version + " est déjà la dernière version publiée.")
                Write-Certificats (Join-Path $Php 'cacert.pem')
                Write-PhpIni (Join-Path $Php 'php.ini') (Test-Path (Join-Path $Php 'cacert.pem'))
                Test-Extensions $Php
                Write-Ok 'Configuration et certificats rafraîchis'
                exit 0
            }
            Write-Ok ("Dernière version : PHP " + $pub.Version)
            $zip = Get-ArchivePhp $pub $tmp
            $origine = $pub.Base + $pub.Fichier
            $empreinte = $pub.Sha256
        }

        Write-Etape 'Décompression…'
        Expand-Php $zip $Nouveau
        $v = Confirm-RuntimeVC $Nouveau
        Write-Certificats (Join-Path $Nouveau 'cacert.pem')
        Write-PhpIni (Join-Path $Nouveau 'php.ini') (Test-Path (Join-Path $Nouveau 'cacert.pem'))
        Test-Extensions $Nouveau
        Write-InfoPhp $Nouveau $v $origine $empreinte

        # Bascule : l'ancien PHP n'est retiré qu'une fois le nouveau vérifié.
        if (Test-Path $Php) {
            if (Test-PhpEnCours $Php) {
                throw "Larka (ou un autre programme) utilise encore $Php\php.exe : fermez la fenêtre de Larka, puis relancez."
            }
            if (Test-Path $Ancien) { Remove-Item $Ancien -Recurse -Force }
            Rename-Item $Php 'php.ancien'
        }
        Rename-Item $Nouveau 'php'
        Remove-Item $Ancien -Recurse -Force -ErrorAction SilentlyContinue
        Write-Ok "PHP $v installé dans $Php"
    } finally {
        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
        if (Test-Path $Nouveau) { Remove-Item $Nouveau -Recurse -Force -ErrorAction SilentlyContinue }
    }
    exit 0
} catch {
    Write-Host ''
    Write-Host ('  × ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
