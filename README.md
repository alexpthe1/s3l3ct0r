# s3l3ct0r

**s3l3ct0r** ist eine smarte Web-Applikation zur Entscheidungsfindung. Ob Team-Events, Restaurantwahl oder Aufgabenverteilung – s3l3ct0r hilft dir, eine faire oder zufällige Wahl zu treffen.

![s3l3ct0r Screenshot](https://img.shields.io/badge/PHP-8.x-777bb4?style=for-the-badge&logo=php)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-3.x-38bdf8?style=for-the-badge&logo=tailwind-css)

## ✨ Features

- **Verschiedene Auswahlmodi:**
  - **Zufall:** Jede Option hat die gleiche Chance.
  - **Gleichmäßige Verteilung:** Berücksichtigt die bisherige Historie (Optionen, die seltener gewählt wurden, kommen eher dran).
  - **Gewichtung:** Du kannst bestimmen, wie wahrscheinlich eine Option gewählt wird (z.B. Option A doppelt so oft wie Option B).
  - **Umfrage:** Erstelle ein Voting, bei dem Nutzer ihren Namen angeben und für Optionen abstimmen können. Inklusive Live-Ergebnissen und einstellbarer Mehrfachauswahl.
- **Session-basiert:** Jede Auswahlrunde hat eine eigene URL, die geteilt werden kann.
- **Passwortschutz:** Schütze den Admin-Bereich deiner Sessions vor unbefugtem Zugriff.
- **Modernes Design:** Responsive UI mit Tailwind CSS und Space Grotesk Typografie.
- **Dateibasiert:** Keine Datenbank erforderlich (speichert Daten als JSON).

## 🚀 Installation

Da s3l3ct0r ein reines PHP-Projekt ohne Datenbankabhängigkeit ist, ist die Installation extrem einfach:

1. **Repository klonen oder herunterladen:**
   ```bash
   git clone https://github.com/alexpthe1/s3l3ct0r.git
   ```
2. **Auf Webserver verschieben:** Kopiere alle Dateien in das Web-Verzeichnis deines Servers (z.B. `htdocs` bei XAMPP oder `/var/www/html`).
3. **Schreibrechte prüfen:** Der Ordner `data/` muss für den Webserver beschreibbar sein, da dort die Sessions als JSON-Dateien gespeichert werden.
4. **Aufrufen:** Öffne die `index.php` in deinem Browser (z.B. `http://localhost/s3l3ct0r`).

## 🛠 Verwendung

1. **Session erstellen:** Gib einen Titel ein und wähle die gewünschte Auswahlmethode. Optional kannst du ein Passwort festlegen.
2. **Optionen hinzufügen:** Füge die Begriffe hinzu, zwischen denen gewählt werden soll. Bei der Methode "Gewichtung" kannst du zusätzlich einen Wert (z.B. 1-100) angeben.
3. **Link teilen:** Kopiere die URL aus der Adresszeile oder nutze den "Kopieren"-Button, um andere zur Session einzuladen.
4. **Wählen:** Klicke auf "JETZT WÄHLEN", um das Ergebnis zu generieren.

## 🔒 Sicherheit

- Sessions werden im Ordner `data/` gespeichert.
- Die mitgelieferte `.htaccess` im `data/`-Ordner verhindert den direkten Zugriff auf die JSON-Dateien über den Browser.
- Passwörter werden sicher mit `password_hash()` verschlüsselt.

## 📄 Lizenz

Dieses Projekt ist unter der MIT-Lizenz veröffentlicht.
