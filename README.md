# VWE Auto Manager Plugin

Een WordPress plugin voor het beheren en weergeven van auto's van VWE (Voertuig Web Export). De plugin haalt XML-data en afbeeldingen op van een FTP-server en toont deze in een moderne, filterbare interface.

## 🚗 Functies

- **Automatische data synchronisatie** via FTP
- **Moderne car listing** met filters en sortering
- **Responsive design** voor alle apparaten
- **SEO-vriendelijke URLs** voor individuele auto's
- **Mini plugins** voor specifieke use cases
- **Automatische afbeelding optimalisatie**
- **Cronjob ondersteuning** voor dagelijkse updates

## 📦 Installatie

### 1. Plugin installeren

1. Upload de `VWE-auto-manager` map naar `/wp-content/plugins/`
2. Activeer de plugin via WordPress Admin → Plugins
3. Configureer de FTP-instellingen (zie configuratie)

### 2. Mini plugins installeren

De plugin bevat twee mini plugins:

#### VWE Latest Cars Mini Plugin
- **Bestand**: `3-latest-cars-mini-plugin/VWE-auto-manager.php`
- **Doel**: Toont de 3 meest recente auto's
- **Shortcode**: `[vwe_latest_cars]`

#### VWE Cheapest Cars Mini Plugin
- **Bestand**: `VWE-cheapest-cars-mini-plugin/vwe-cheapest-cars.php`
- **Doel**: Toont de goedkoopste auto's
- **Shortcode**: `[vwe_cheapest_cars]`

## ⚙️ Configuratie

### FTP Instellingen

Open `VWE-auto-manager.php` en pas de volgende constanten aan:

```php
define('FTP_SERVER', '91.184.31.234');
define('FTP_USER', 'anmvs-auto');
define('FTP_PASS', 'f6t23U~8t');
define('REMOTE_IMAGES_PATH', '/staging.mvsautomotive.nl/wp-content/plugins/VWE-auto-manager/xml/images/');
define('REMOTE_IMAGE_HTTP', 'https://staging.mvsautomotive.nl/wp-content/plugins/VWE-auto-manager/xml/images/');
```

### Update Interval

Standaard wordt de data elke 24 uur bijgewerkt. Dit kan aangepast worden:

```php
define('UPDATE_INTERVAL', 86400); // 24 uur in seconden
```

## 🎯 Gebruik

### Hoofdplugin

#### Shortcode
```php
[vwe_auto_listing]
```

#### Template functie
```php
<?php display_car_listing(); ?>
```

### Mini Plugins

#### Latest Cars
```php
[vwe_latest_cars]
```

#### Cheapest Cars
```php
[vwe_cheapest_cars]
```

## 🔧 Functies

### Filters
- **Merk**: Filter op automerk
- **Model**: Filter op model (dynamisch op basis van gekozen merk)
- **Brandstof**: Benzine, Diesel, Elektrisch, Hybride
- **Bouwjaar**: Jaarranges (2020-2024, 2015-2019, etc.)
- **Prijsbereik**: Min/max prijs
- **Kilometerstand**: Min/max kilometers
- **Transmissie**: Automatisch/Handgeschakeld
- **Carrosserie**: Hatchback, Sedan, SUV, etc.
- **Aantal deuren**: 2-5 deuren
- **Aantal zitplaatsen**: 2-7 zitplaatsen
- **Vermogen**: Min/max PK
- **Status**: Beschikbaar, Gereserveerd, Verkocht

### Sortering
- Prijs (laag-hoog / hoog-laag)
- Kilometerstand (laag-hoog / hoog-laag)
- Bouwjaar (nieuwste eerst / oudste eerst)

### Paginering
- Configureerbaar aantal auto's per pagina (12, 24, 50, 100)
- Navigatie tussen pagina's

## 📁 Bestandsstructuur

```
VWE-auto-manager/
├── VWE-auto-manager.php          # Hoofdplugin
├── styling.css                   # Hoofdstijlen
├── js/
│   └── scripts.js               # JavaScript functionaliteit
├── assets/
│   └── js/
│       ├── vwe-auto-manager.js  # Core JavaScript
│       └── vwe-listing.js       # Listing functionaliteit
├── templates/
│   ├── occasion-archive.php     # Archive template
│   ├── occasion-card.php        # Card template
│   ├── occasion-detail.php      # Detail template
│   └── occasion-detail.css      # Detail stijlen
├── 3-latest-cars-mini-plugin/
│   ├── VWE-auto-manager.php     # Latest cars plugin
│   ├── vwe-latest-cars.php      # Latest cars functionaliteit
│   └── vwe-latest-cars.css      # Latest cars stijlen
└── VWE-cheapest-cars-mini-plugin/
    ├── vwe-cheapest-cars.php    # Cheapest cars functionaliteit
    └── vwe-cheapest-cars.css    # Cheapest cars stijlen
```

## 🔄 Data Synchronisatie

### Automatische Updates
- **Cronjob**: Dagelijkse updates via WordPress cron
- **FTP Download**: XML en afbeeldingen worden automatisch gedownload
- **Afbeelding Optimalisatie**: Automatische compressie en WebP conversie
- **Cleanup**: Ongebruikte afbeeldingen worden automatisch verwijderd

### Handmatige Updates
```php
// Trigger handmatige update
update_all_data();
```

## 🎨 Customization

### CSS Aanpassingen
Alle stijlen zijn modulair opgebouwd:
- `styling.css`: Hoofdstijlen
- `occasion-detail.css`: Detail pagina stijlen
- Mini plugin specifieke CSS bestanden

### Template Aanpassingen
Templates bevinden zich in de `templates/` map en kunnen aangepast worden:
- `occasion-archive.php`: Archive pagina layout
- `occasion-card.php`: Individuele auto kaart
- `occasion-detail.php`: Detail pagina layout

## 🐛 Troubleshooting

### Veelvoorkomende Problemen

#### 1. FTP Verbinding Fout
```
Error: Could not connect to FTP server
```
**Oplossing**: Controleer FTP instellingen en server bereikbaarheid

#### 2. XML Parse Error
```
Error: Error parsing XML content
```
**Oplossing**: Controleer XML bestand op geldigheid en encoding

#### 3. Afbeeldingen Niet Zichtbaar
```
Error: Failed to download file
```
**Oplossing**: Controleer afbeelding paden en FTP rechten

#### 4. Performance Problemen
**Oplossingen**:
- Verhoog PHP memory limit
- Controleer afbeelding optimalisatie instellingen
- Gebruik caching plugin

### Debug Mode
Activeer debug mode voor uitgebreide logging:

```php
define('DEBUG_MODE', true);
```

## 📊 Performance Optimalisatie

### Aanbevolen Instellingen
```php
// PHP Instellingen
set_time_limit(300);        // 5 minuten
ini_set('memory_limit', '256M');

// WordPress Instellingen
define('WP_MEMORY_LIMIT', '256M');
```

### Caching
- Gebruik een caching plugin (WP Rocket, W3 Total Cache)
- Cache afbeeldingen lokaal
- Gebruik CDN voor afbeeldingen

## 🔒 Beveiliging

### Best Practices
- Gebruik sterke FTP wachtwoorden
- Beperk FTP toegang tot specifieke IP's
- Regelmatig wachtwoorden wijzigen
- Monitor log bestanden

### WordPress Integratie
- Alle output wordt geëscaped
- Nonce verificatie voor forms
- Capability checks voor admin functies

## 📈 SEO Optimalisatie

### Automatische SEO Features
- SEO-vriendelijke URLs voor auto's
- Meta descriptions en titles
- Structured data markup
- Image alt tags
- Sitemap integratie

### URL Structuur
```
/occasions/merk-model-jaar-transmissie-brandstof/
```

## 🤝 Support

### Documentatie
- Lees deze README volledig
- Controleer WordPress error logs
- Gebruik debug mode voor troubleshooting

### Contact
Voor support en vragen:
- Controleer eerst de troubleshooting sectie
- Bekijk de WordPress error logs
- Test in een staging omgeving

## 📝 Changelog

### Versie 1.0.0
- Initiële release
- FTP data synchronisatie
- Moderne car listing interface
- Mini plugins support
- SEO optimalisatie
- Responsive design

## 📄 Licentie

Deze plugin is ontwikkeld voor VWE Auto Manager. Alle rechten voorbehouden.

---

**Let op**: Zorg ervoor dat je een backup hebt van je website voordat je wijzigingen maakt aan de plugin configuratie.