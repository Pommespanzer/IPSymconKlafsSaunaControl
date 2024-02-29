# IPSymconKlafsSaunaControl

[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguartion)
6. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

Steuerung von Saunen der Firma [KLAFS](https://www.klafs.de/) über die [Sauna-App](https://sauna-app-19.klafs.com/) von Klafs. Zur Steuerung ist ein KLAFS-Konto erforderlich sowie die Sonderausstattung "KLAFS Sauna App". Das KNX-Modul ist nicht erforderlich.  

Derzeit lassen sich Saunen mit oder ohne SANARIUM®-Funktion steuern. In der SANARIUM®-Funktion lässt sich die Luftfeuchtigkeit in 10 Stufen regulieren.  

Folgende Messwerte können erfasst werden: Aktuelle Temperatur, Aktuelle Luftfeuchtigkeit (nur SANARIUM®).

### Funktionsumfang

 - Sauna aus/einschalten
 - Vorwahlbetrieb
 - Gewünschte Badezeit
 - Gewünschte Temperatur
   - Sauna: 10-100°C
   - SANARIUM®: 40-75°C
 - Gewünschte Luftfeuchtigkeit (SANARIUM® Stufe 1-10)
 - Auswahl des Betriebsmodus (Sauna oder SANARIUM®)
 - Anpassen der gewünschten Temperatur während des Betriebs
 - Unterstützung mehrerer Saunen

## 2. Voraussetzungen

1. IP-Symcon ab Version 6
2. Ein eingerichtetes Konto in der [KLAFS Sauna-App](https://sauna-app-19.klafs.com/)
3. Eine Sauna mit der Sonderausstattung KLAFS Sauna App
4. Die Sauna selbst muss zuvor mit dem [Sauna-App-Konto](https://sauna-app-19.klafs.com/) verknüpft werden

## 3. Installation

### Modul installieren

1. IP-Symcon Webconsole öffnen
2. Das Modul ModulControl öffnen
3. Das Repository hinzufügen: _https://github.com/Pommespanzer/IPSymconKlafsSaunaControl_

### Einrichtung in IP-Symcon

1. Neue Instanz unter I/O-Instanzen hinzufügen: KlafsSaunaIO
2. Instanz konfigurieren
   1. Benutzername & Passwort eingeben
   2. Speichern, mit dem Button "TestAccess" die korrekte Anmeldung sicherstellen
3. Neue Instanz unter Konfigurator hinzufügen: KlafsSaunaKonfigurator
   1. Instanz öffnen, dort tauchen nun alle in der App eingerichteten Saunen auf
   2. Sauna hinzufügen
4. Die erstellte Sauna (KlafsSaunaDevice) öffnen und konfigurieren
   1. Typ der Sauna auswählen (Sauna oder Sauna mit SANARIUM®)
   2. PIN-Code eingeben (muss vorher an in Steuerung der Sauna eingestellt werden)
   3. Update Interval eingeben. Empfehlung: 60 Sekunden

### Wichtige Anmerkung

**Der Benutzername und das Passwort müssen zwingend korrekt sein.**  

**KLAFS sperrt das Benutzerkonto nach 3 fehlgeschlagenen Anmeldeversuchen.**


Das Modul sperrt den automatischen Login nach dem ersten fehlgeschlagenen Anmeldeversuch.  
Die automatische Anmeldung muss dann erst wieder über den Button "RESET LOGIN FAILURES" aktiviert werden.

## 4. Funktionsreferenz

`Klafs_SetUpdateIntervall(integer $InstanceID, int $Seconds)`<br>
Ändert das Aktualisierungsintervall

`Klafs_SendUpdate(integer $InstanceID)`<br>
Sendet alle geänderten Einstellungen über die Sauna-App an die Sauna.  
Bis KLAFS die geänderten Einstellungen an die Sauna übertragen hat, können einige Sekunden vergehen.

`Klafs_SetMode(integer $InstanceID, int $Mode)`<br>
Setzt den gewünschten Bademodus: 1 = Sauna, 2 = SANARIUM®

`Klafs_SetStartingTime(integer $InstanceID, int $hour, int $Minute)`<br>
Setzt den gewünschten Badebeginn. Ein Wert in der Zukunft gilt als Vorwahlbetrieb.

`Klafs_SetSaunaTemperature(integer $InstanceID, int $Value)`<br>
Setzt die gewünschte Temperatur für die Sauna (nicht SANARIUM®)
Einstellbar zwischen 10°C und 100°C

`Klafs_SetSanariumTemperature(integer $InstanceID, int $Value)`<br>
Setzt die gewünschte Temperatur für das SANARIUM® (nicht Sauna).  
Einstellbar zwischen 40°C und 75°C

`Klafs_SetSanariumHumidity(integer $InstanceID, int $Value)`<br>
Setzt die gewünschte Luftfeuchtig für das SANARIUM®.  
Einstellbar zwischen Stufe 1 und 10.

`Klafs_PowerOn(integer $InstanceID)`<br>
Übermittelt alle Einstellungen und schaltet die Sauna ein. Ein PIN-Code muss zwingend konfiguriert sein. Die Türkontrolle muss vorher durchgeführt worden sein.

`Klafs_PowerOff(integer $InstanceID)`<br>
Schaltet die Sauna aus.

`Klafs_IsConnected(integer $InstanceID)`<br>
Ob die Sauna mit der Sauna-App verbunden ist und generell gesteuert werden kann.

`Klafs_IsReadyForUse(integer $InstanceID)`<br>
Ob die Sauna fertig aufgeheizt/badebereit ist.

`Klafs_IsPoweredOn(integer $InstanceID)`<br>
Ob die Sauna angeschaltet ist.

## 5. Konfiguration

### Variablen (KlafsSaunaIO)

| Eigenschaft | Typ     | Standardwert | Funktion          |
|:------------| :------ | :----------- |:------------------|
| username    | string  |              | Benutzername      |
| password    | string  |              | Passwort          |

### Variablen (KlafsSaunaDevice)

| Eigenschaft    | Typ     | Standardwert | Funktion                    |
|:---------------|:--------|:-------------|:----------------------------|
| type           | integer |              | Sauna-Typ                   |
| pin            | string  |              | PIN-Code                    |
| UpdateInterval | integer | 30           | Update Interval in Sekunden |

## 6. Versions-Historie

- 0.3 @ 29.02.2024  
  - Sauna-App URL geändert  
  - Support für Infrarot-Saunen eingestellt  
  
- 0.2 @ 04.11.2023  
  - Übersetzungen hinzugefügt  

- 0.1 @ 02.11.2023  
  - Initiale Version (Beta)  