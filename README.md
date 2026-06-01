# s3l3ct0r

**s3l3ct0r** ist eine smarte Web-Applikation zur Entscheidungsfindung. Ob Team-Events, Restaurantwahl oder Aufgabenverteilung – s3l3ct0r hilft dir, eine faire oder zufällige Wahl zu treffen.

![s3l3ct0r Screenshot](https://img.shields.io/badge/PHP-8.x-777bb4?style=for-the-badge&logo=php)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-3.x-38bdf8?style=for-the-badge&logo=tailwind-css)

## ✨ Features

- **Verschiedene Auswahlmodi:**
  - **Zufall:** Jede Option hat die gleiche Chance.
  - **Gleichmäßige Verteilung:** Berücksichtigt die Historie der Session.
  - **Gewichtung:** Bestimme Wahrscheinlichkeiten (z.B. 80% vs 20%).
  - **Umfrage:** Votings mit Namen und Live-Ergebnissen.
  - **Brainstorm:** Offene Ideensammlung für Teams.
- **Master Dashboard:** Zentrale Verwaltung aller Sessions und globale Konfiguration.
- **Modernes Design:** Volle Kontrolle über Farben, Fonts und Hintergründe.
- **Dateibasiert:** Keine Datenbank erforderlich (JSON/YAML).

## 🚀 Installation

1. **Repository klonen:** `git clone https://github.com/alexpthe1/s3l3ct0r.git`
2. **Auf Webserver verschieben:** Kopiere alle Dateien in dein Web-Verzeichnis.
3. **Schreibrechte:** Der Ordner `data/` muss für den Webserver beschreibbar sein.
4. **Aufrufen:** Öffne `index.php` (Nutzer-Seite) oder `master.php` (Verwaltung).

## ⚙️ Konfiguration

Die globalen Einstellungen werden in der `config.yaml` vorgenommen (manuell oder via Master Dashboard).

| Parameter | Beschreibung | Beispiel |
| :--- | :--- | :--- |
| `app_name` | Name der Instanz | `"Mein Team-Tool"` |
| `primary_color` | Hauptfarbe (HEX) | `"#06b6d4"` |
| `secondary_color` | Akzentfarbe (HEX) | `"#3b82f6"` |
| `font_family` | Google Font Name | `"Bungee Spice"` |
| `background_style` | CSS Hintergrund | `radial-gradient(...)` |
| `custom_css` | Eigener CSS Code | siehe unten |
| `use_x_real_ip` | Proxy Support | `true` / `false` |
| `auto_cleanup_days` | Auto-Löschung | `30` (Tage) |

## 🎨 Design & Customization

Hier sind einige Beispiele, wie du s3l3ct0r optisch anpassen kannst:

### 1. Farben & Gradients
Setze `primary_color` auf `#ff4e50` und `secondary_color` auf `#f9d423` für einen warmen Sonnenuntergangs-Look. Die Buttons und Header-Verläufe passen sich automatisch an.

### 2. Hintergrund-Styles (`background_style`)
- **Deep Space (Standard):** `radial-gradient(circle at top right, #1a1a2e, #16213e, #0f3460)`
- **Clean Slate:** `#0f172a`
- **Image:** `url('https://source.unsplash.com/random/1920x1080?dark') center/cover no-repeat`

### 3. Custom CSS: Die "Punkte-Matrix"
Kopiere diesen Code in das `custom_css` Feld für einen modernen Tech-Look:
```css
.bg-gradient::before {
    content: ""; position: fixed; width: 200vmax; height: 200vmax;
    top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(120deg);
    background-image: radial-gradient(var(--secondary) 1.5px, transparent 0);
    background-size: 40px 40px; opacity: 0.6;
    mask-image: radial-gradient(circle at center, transparent 10vmax, black 60vmax);
    -webkit-mask-image: radial-gradient(circle at center, transparent 10vmax, black 60vmax);
    pointer-events: none; z-index: -1;
}
```

## 👑 Master Dashboard

Unter `master.php` kannst du:
- Alle Sessions sehen und nach letzter Aktivität sortieren.
- Sessions **duplizieren (Kopie)** oder im **Roh-Format (JSON)** bearbeiten.
- Alte Sessions automatisch bereinigen lassen.
- Das Design und Sicherheits-Parameter live anpassen.

*Sicherheit: Schütze den Zugang mit einem starken `master_password`. Fehlversuche werden in `data/security.log` protokolliert.*

## 📄 Lizenz

Dieses Projekt ist unter der MIT-Lizenz veröffentlicht.
