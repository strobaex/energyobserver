## energyobserver
EnergyObserver ist eine Weblösung zum Monitoren von Stromverbräuchen von Shelly Devices (https://shelly.cloud) mit integriertem Strommesser.

![grafik](https://user-images.githubusercontent.com/81878929/113571640-0b201c00-9617-11eb-9aba-9c274376ee96.png)

In der Konfiguration können verschiedene Shelly-Devices angelegt und auch wieder gelöscht werden. Wichtig: Das Löschen eines Devices führt auch dazu, das die entsprechende Datendatei gelöscht wird!

![grafik](https://user-images.githubusercontent.com/81878929/113571716-2c810800-9617-11eb-9b07-b567c7e97969.png)

Hinweis:
* Bei mir sind nicht alle Shelly-Devices im Einsatz. Daher sind einzelne Devices nicht getestet und die Verbrauchsauslesung funktioniert möglicherweise nicht richtig.

### Installation
- Bitte installieren Sie das Paket php-curl.
- Bitte legen Sie die beiden Dateien in ein vom Web-Server erreichbares Verzeichnis ab.
- Bitte die Datei index.php als cronjob in die crontab eintragen. Diese Datei liest periodisch die aktuelle Leistungsaufnahme der konfigurierten Shellys aus und speichert diese in je einer Datei je Shelly. Ein möglicher Eintrag kann wie folgt aussehen:<br>
*/15 * * * * /usr/bin/curl http://Ihr_Webserver/Verzeichnis_mit_den_Dateien/index.php
- Rufen Sie EnergyObserver auf http://Ihr_Webserver/Verzeichnis_mit_den_Dateien/report.php und konfigurieren Sie Ihre Shelly PM-Devices
