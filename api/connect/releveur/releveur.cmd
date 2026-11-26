@echo off
rem SPDX-FileCopyrightText: (c) 2025-2026 Mickael Larcin (Chipsoreo)
rem SPDX-License-Identifier: LicenseRef-Larka-Proprietary
rem ==========================================================================
rem  Larka - collecteur du PC releveur (appareils hors reseau : hotspot Wi-Fi ou cable direct)
rem
rem    Double-clic                : un releve maintenant (la premiere fois,
rem                                 verification de Larka). Rien ne se lance seul.
rem    releveur.cmd collecter     un releve maintenant
rem    releveur.cmd tester        lit et affiche, n'envoie rien
rem    releveur.cmd etat          ce qui est regle, ce qui attend
rem    releveur.cmd retirer       enleve les profils Wi-Fi (et les taches des anciennes versions)
rem ==========================================================================
setlocal EnableExtensions
chcp 65001 >nul
set "ICI=%~dp0"
set "PHPEXE="
if exist "%ICI%php\php.exe" set "PHPEXE=%ICI%php\php.exe"
if not defined PHPEXE (
  where php >nul 2>nul && set "PHPEXE=php"
)
if not defined PHPEXE goto :installer

:lancer
set "ARGS=%*"
if "%~1"=="" goto :assistant
call :php %ARGS%
exit /b %ERRORLEVEL%

:assistant
rem Double-clic : un releve maintenant. Rien ne se lance tout seul.
title Larka - releve des appareils hors reseau
if exist "%ICI%mission.json" goto :releve
echo.
echo  Premier lancement : verification de Larka et des profils Wi-Fi.
echo  Larka doit etre joignable maintenant (reseau habituel du PC).
echo.
call :php configurer
if errorlevel 1 goto :fin

:releve
echo.
call :php collecter
:fin
echo.
pause
exit /b %ERRORLEVEL%

:php
rem PHP du collecteur : son php.ini lit ses chemins (extensions, certificats) dans
rem LARKA_PHP_DIR. Sans elle, ni openssl (HTTPS) ni mbstring ne se chargeaient.
rem (Sauts plutot que blocs entre parentheses : %ERRORLEVEL% y serait lu trop tot.)
if exist "%ICI%php\php.exe" if exist "%ICI%php\php.ini" goto :php_local
if exist "%ICI%php\php.ini" goto :php_ini
"%PHPEXE%" "%ICI%releveur.php" %*
exit /b %ERRORLEVEL%

:php_local
set "LARKA_PHP_DIR=%ICI%php"
"%ICI%php\php.exe" -c "%ICI%php\php.ini" "%ICI%releveur.php" %*
exit /b %ERRORLEVEL%

:php_ini
"%PHPEXE%" -c "%ICI%php\php.ini" "%ICI%releveur.php" %*
exit /b %ERRORLEVEL%

:installer
echo.
echo  PHP n'est pas sur ce PC : installation dans le dossier du collecteur (une seule fois)...
powershell -NoProfile -ExecutionPolicy Bypass -File "%ICI%installer-php.ps1" -Racine "%ICI%."
if exist "%ICI%php\php.exe" (
  set "PHPEXE=%ICI%php\php.exe"
  goto :lancer
)
echo.
echo  Installation de PHP impossible (pas d'acces a Internet ?).
echo  Copiez le dossier php\ d'une installation de Larka dans ce dossier, puis relancez.
pause
exit /b 1
