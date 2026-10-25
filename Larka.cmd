@echo off
rem ===========================================================================
rem  Larka - lanceur Windows
rem  SPDX-FileCopyrightText: (c) 2025-2026 Mickael Larcin (Chipsoreo)
rem  SPDX-License-Identifier: LicenseRef-Larka-Proprietary
rem ---------------------------------------------------------------------------
rem  Double-cliquez sur ce fichier pour demarrer Larka.
rem
rem  Au premier lancement, il installe PHP (la version officielle de php.net)
rem  dans le dossier php\ ci-contre, puis prepare la configuration. Rien n'est
rem  installe ailleurs dans Windows (sauf, s'il manque, le runtime Visual C++
rem  de Microsoft dont PHP a besoin - Windows demande alors l'autorisation).
rem
rem    Larka.cmd                  demarre Larka et ouvre le navigateur
rem    Larka.cmd menu             toutes les actions
rem    Larka.cmd aide             liste des commandes
rem    Larka.cmd php [zip]        installe / met a jour PHP (zip local accepte)
rem
rem  Autre PHP que celui du dossier php\ : variable LARKA_PHP = chemin de php.exe
rem  Le travail est fait par deploy\windows\larka.php (et installer-php.ps1).
rem ===========================================================================
setlocal EnableExtensions DisableDelayedExpansion
title Larka
cd /d "%~dp0"

set "LARKA_DIR=%~dp0"
if "%LARKA_DIR:~-1%"=="\" set "LARKA_DIR=%LARKA_DIR:~0,-1%"
if "%LARKA_DIR:~2%"=="" set "LARKA_DIR=%LARKA_DIR%\."
set "PHP_DIR=%LARKA_DIR%\php"
set "LANCEUR=%LARKA_DIR%\deploy\windows\larka.php"

if not exist "%LANCEUR%" goto :incomplet
if /i "%~1"=="php" goto :maj_php
if defined LARKA_PHP goto :php_perso

if exist "%PHP_DIR%\php.exe" goto :php_present
echo.
echo  Premier lancement : installation de PHP pour Windows (environ 35 Mo).
echo.
call :installer_php
if errorlevel 1 goto :echec_php
if not exist "%PHP_DIR%\php.exe" goto :echec_php
goto :lancer

:php_present
if exist "%PHP_DIR%\php.ini" goto :lancer
call :installer_php
if errorlevel 1 goto :echec_php
if not exist "%PHP_DIR%\php.ini" goto :echec_php

:lancer
rem php.ini lit ses chemins dans LARKA_PHP_DIR : le dossier peut etre deplace,
rem et un chemin avec espaces, parentheses ou "&" ne trouble pas PHP.
set "LARKA_PHP_DIR=%PHP_DIR%"
"%PHP_DIR%\php.exe" -c "%PHP_DIR%\php.ini" "%LANCEUR%" %*
goto :apres

:php_perso
if exist "%LARKA_PHP%" goto :php_perso_ok
echo.
echo  La variable LARKA_PHP designe un fichier introuvable.
goto :fin_erreur
:php_perso_ok
"%LARKA_PHP%" "%LANCEUR%" %*

:apres
set "CODE=%ERRORLEVEL%"
if "%CODE%"=="10" goto :maj_php_menu
if "%CODE%"=="0" exit /b 0
if "%~1"=="" pause
exit /b %CODE%

rem --- Installation / mise a jour de PHP -------------------------------------
:maj_php
if "%~2"=="" goto :maj_php_web
call :installer_php -Forcer -Archive "%~2"
goto :maj_php_fin
:maj_php_web
call :installer_php -Forcer
:maj_php_fin
if errorlevel 1 goto :echec_php
if not exist "%PHP_DIR%\php.exe" goto :echec_php
exit /b 0

:maj_php_menu
call :installer_php -Forcer
if errorlevel 1 goto :echec_php
echo.
pause
exit /b 0

:installer_php
where powershell >nul 2>&1
if errorlevel 1 goto :sans_powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "%LARKA_DIR%\deploy\windows\installer-php.ps1" -Racine "%LARKA_DIR%" %*
exit /b %ERRORLEVEL%
:sans_powershell
echo  Windows PowerShell est introuvable sur ce poste.
exit /b 1

rem --- Erreurs ---------------------------------------------------------------
:echec_php
echo.
echo  PHP n'a pas pu etre installe automatiquement.
echo  Installation manuelle :
echo    1. Sur https://windows.php.net/download/ , telechargez PHP 8.3,
echo       version "VS16 x64 Non Thread Safe", fichier Zip.
echo    2. Lancez :   Larka.cmd php C:\chemin\vers\le\fichier.zip
echo  Derriere un proxy d'entreprise, faites autoriser windows.php.net.
goto :fin_erreur

:incomplet
echo.
echo  Fichiers de Larka incomplets : deploy\windows\larka.php est introuvable.
echo  Decompressez de nouveau l'archive de Larka (tout son contenu).
goto :fin_erreur

:fin_erreur
echo.
if "%~1"=="" pause
exit /b 1
