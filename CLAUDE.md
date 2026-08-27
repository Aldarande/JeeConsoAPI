# CLAUDE.md — Plugin Jeedom JeeConsoAPI

> Contexte de référence chargé automatiquement par Claude Code.
> Auteur : Aldarande | ID plugin : `jeeconsoapi` | Source de données : Conso API (conso.boris.sh)

---

## 1. IDENTITÉ DU PLUGIN

| Champ | Valeur |
|---|---|
| ID | `jeeconsoapi` (tout en minuscules, **jamais** `jeeConsoAPI`) |
| Nom | JeeConsoAPI |
| Auteur | Aldarande |
| Catégorie | energy |
| Jeedom min | 4.4.0 |
| Licence | AGPL v3 |
| Démon | **Non** — simple appel HTTP REST en PHP, déclenché par cron |
| Dépendances | **Aucune** |
| Canal de log | `jeeconsoapi` (= le nom du dossier, en minuscules) |

> ⚠️ **Piège de casse** : le fichier de log est nommé d'après la chaîne passée à `log::add()`,
> pas d'après le nom de la classe ni du dossier. Ici les trois coïncident volontairement
> (`jeeconsoapi`), contrairement à `jeeia` (dossier `jeeia`, log `jeeIA`). Ne jamais
> introduire de majuscule dans les appels à `log::add()`.

---

## 2. ARCHITECTURE DES FICHIERS

```
jeeconsoapi/
├── plugin_info/
│   ├── info.json              ← metadata, hasOwnDeamon:false, hasDependency:false
│   ├── install.php            ← création/suppression du cron jeeconsoapi::pull
│   ├── configuration.php      ← config globale (fenêtre horaire, plafond) + bloc de soutien
│   └── icon.png               ← 256×256, dégradé vert→cyan + éclair
│
├── core/
│   ├── class/jeeconsoapi.class.php  ← TOUT le métier : client HTTP, cron, cmd, backfill
│   ├── ajax/jeeconsoapi.ajax.php    ← testConnection, refresh, backfill, getScheduleInfo
│   ├── i18n/fr_FR.json
│   └── template/
│       ├── dashboard/jeeconsoapi.html  ← widget desktop (CSS embarqué, scopé .jca)
│       └── mobile/jeeconsoapi.html     ← widget mobile
│
├── desktop/
│   ├── php/jeeconsoapi.php    ← page équipements (liste + onglets Compteur / Commandes)
│   ├── js/jeeconsoapi.js      ← printEqLogic, boutons AJAX, addCmdToTable
│   └── css/jeeconsoapi.css    ← styles de la page de config UNIQUEMENT
│
├── docs/fr_FR/
│   ├── index.md
│   └── changelog.md
│
├── README.md
├── CHANGELOG.md
└── CLAUDE.md
```

---

## 3. L'API CONSO API

```
GET https://conso.boris.sh/api/{type}?prm={prm}&start={start}&end={end}
```

- `start` **inclusif**, `end` **exclusif**, format `YYYY-MM-DD`
- Types utilisés en v1 : `daily_consumption`, `consumption_max_power`
  (`consumption_load_curve` = hors périmètre, à ne pas ajouter sans accord explicite)
- En-têtes **obligatoires** : `Authorization: Bearer …`, `User-Agent: …` identifiable

### Format de réponse

La doc officielle ne publie pas le schéma. Le parser (`jeeconsoapi_extract()`) accepte
**les deux formes possibles** :

```json
{ "meter_reading": { "reading_type": {...}, "interval_reading": [...] } }
{ "reading_type": {...}, "interval_reading": [...] }
```

| Type | `reading_type.unit` | `interval_reading[].date` |
|---|---|---|
| `daily_consumption` | `Wh` | `YYYY-MM-DD` |
| `consumption_max_power` | `VA` | `YYYY-MM-DD HH:MM:SS` |

### Codes de retour

| Code | Traitement dans `handleHttpError()` |
|---|---|
| `200` + points | Succès, terminé pour la journée |
| `200` sans point | Données non publiées → `rescheduleAfterEmpty()` |
| `400` | `log error`, **aucun** nouvel essai |
| `401` | `log error` (remonte au centre de messages), **aucun** nouvel essai |
| `500` / autre | `log info`, nouvel essai différé ~1 h |

---

## 4. RÈGLES D'USAGE — NE JAMAIS CONTOURNER

- **Un seul cycle d'appel par jour et par compteur.** Un cycle = 2 requêtes
  (consommation + puissance max), car ce sont deux endpoints distincts.
- **Horaire tiré au hasard** entre 08:00:00 et 09:59:59 (`drawMorningSlot()`), jamais pile à
  l'heure ronde.
- **Nouvel essai différé** si les données J-1 ne sont pas publiées : une fois dans la
  matinée, une fois en début d'après-midi, puis abandon jusqu'au lendemain.
- **Plafond dur** `max_attempts` (défaut 3) par jour et par compteur.
- Quotas globaux Enedis **partagés entre tous les utilisateurs** de Conso API : 5 req/s,
  10 000 req/h. C'est précisément parce qu'ils sont mutualisés qu'il ne faut pas
  sur-solliciter le service.

Le cron tique toutes les 5 min entre 6h et 14h55 (`*/5 6-14 * * *`) mais **n'émet une requête
que si l'état persisté l'autorise**. Ne jamais confondre fréquence de tick et fréquence
d'appel.

### Deux plafonds, à ne jamais fusionner

| Compteur | Plafond | Portée |
|---|---|---|
| `state_attempts` | `max_attempts` (défaut 3, max 5) | cycles déclenchés par le **cron** |
| `state_manual_attempts` | `MANUAL_DAILY_CAP` (10) | boutons Tester / Actualiser / Importer et commande action `refresh` |

Ils sont **indépendants** : le cron ayant épuisé son quota ne doit pas empêcher un
diagnostic, et un diagnostic répété ne doit pas manger le budget du cron. Mais **aucun
chemin ne doit pouvoir contourner l'un des deux** — c'est ce qui garantit la promesse faite
au service. Toute nouvelle méthode qui émet une requête doit passer par
`consumeManualQuota()` (si manuelle) ou par le compteur du cron.

### État persisté par équipement (`eqLogic->configuration`)

| Clé | Rôle |
|---|---|
| `state_done_date` | dernière date de données (J-1) ingérée avec succès |
| `state_slot_date` | jour pour lequel le créneau aléatoire a été tiré |
| `state_next_ts` | timestamp du prochain appel autorisé |
| `state_attempts` | appels **automatiques** consommés pour la cible du jour |
| `state_manual_date` | jour de référence du compteur manuel |
| `state_manual_attempts` | actions **manuelles** consommées aujourd'hui |
| `state_last_error` | dernière erreur rencontrée, pour l'encart UI |

Écriture via `setConfiguration()` + **`parent::save(true)`** (`saveState()`). Le `true`
(`$_direct`) court-circuite `preSave`/`postSave` (cf. `DB::save`) : sans lui, chaque écriture
d'état relancerait `createCommands()`.

---

## 5. COMMANDES (logicalIds réels)

| logicalId | Nom | Type | Sous-type | Unité | Historisé | generic_type |
|---|---|---|---|---|---|---|
| `daily_consumption` | Consommation quotidienne | info | numeric | kWh | oui | `DAILY_CONSUMPTION` |
| `max_power` | Puissance maximale quotidienne | info | numeric | VA | oui | `POWER` |
| `data_date` | Date des données | info | string | — | non | — |
| `last_update` | Dernière mise à jour | info | string | — | non | — |
| `refresh` | Actualiser maintenant | action | other | — | — | — |

`DAILY_CONSUMPTION` et `POWER` existent bien dans le core
(`core/config/jeedom.config.php`, ~l.974 et l.988). Vérifier avant d'en ajouter d'autres.

Mise à jour datée : `checkAndUpdateCmd($logicalId, $value, "$target 23:59:00")` — le 3ᵉ
argument fait atterrir le point d'historique au bon jour, et non à l'heure de collecte.

---

## 6. SÉCURITÉ DU TOKEN

1. **Chiffré au repos** dans `preSave()` via `utils::encrypt()` (AES-256-CBC + HMAC-SHA256,
   clé système `data/jeedom_encryption.key`). Idempotent : `encrypt()` ne re-chiffre pas une
   valeur déjà préfixée `crypt:`, `decrypt()` laisse passer une valeur non préfixée.
2. **Lu** uniquement via `jeeconsoapi_token($eqLogic)`. `decrypt()` renvoie `null` si le HMAC
   ne correspond pas → traité comme « token absent », avec message clair à l'utilisateur.
3. **Champ vide = ne pas changer.** Le JS vide le champ après `printEqLogic()` ; `preSave()`
   restaure alors l'ancienne valeur via `eqLogic::byId($this->getId())`.
4. **Jamais journalisé.** Seul `jeeconsoapi_mask()` est autorisé en log. Le PRM passe par
   `jeeconsoapi_mask_prm()` (4 derniers chiffres visibles).
5. **Jamais renvoyé en AJAX**, même partiellement.
6. `ajax::init()` reçoit une **liste blanche** d'actions.

> Critère bloquant en validation : `grep -i "bearer\|eyJ" www/log/jeeconsoapi` doit ne rien
> retourner.

---

## 7. RÈGLES DE DÉVELOPPEMENT

1. **Classe cmd** : `jeeconsoapiCmd extends cmd` (jamais `jeeconsoapi_cmd`).
2. **⚠️ CRITIQUE — ne jamais redéfinir une méthode `eqLogic`/`cmd` avec une visibilité
   réduite.** `checkAndUpdateCmd()`, `postSave()`, `preSave()`, `toHtml()`, `execute()` sont
   **publiques** dans le core. Les passer en `private`/`protected` viole la covariance de
   visibilité PHP → fatale silencieuse à l'autoload qui rend **tout Jeedom inaccessible**
   (HTTP 500 corps vide, aucun log). Vérifier dans `core/class/eqLogic.class.php` et
   `core/class/cmd.class.php` avant d'ajouter une méthode.
3. **Jamais de `isConnect()` dans `*.class.php`** — uniquement dans les fichiers ajax et
   desktop.
4. **Chaînes affichées** — trois cas distincts, ne pas les confondre :
   - `desktop/php/*.php` et `plugin_info/configuration.php` → **`{{…}}`** (traduits par
     `include_file()`, cf. `core/php/utils.inc.php:85`).
   - `desktop/js/*.js` → **`{{…}}`** (traduits à la minification, `jeedom.class.php:41`).
   - `core/class/*.php` et `core/ajax/*.php` → **`__('…', __FILE__)`**.
   - ⚠️ **`core/template/*/jeeconsoapi.html` (widgets eqLogic) → texte en clair.**
     `translate::exec` n'est **jamais** appliqué sur ce chemin : `getTemplate()`
     (`core/php/utils.inc.php:107`) fait un simple `file_get_contents`, et
     `eqLogic::postToHtml()` est un no-op. Seuls les templates **cmd** sont traduits
     (`cmd.class.php:2265-2271`). Écrire `{{…}}` dans un widget eqLogic afficherait les
     accolades telles quelles à l'écran.
5. **Placeholders de widget** : `#history#`, `#background_color#` et `#hide_name#` ne sont
   **pas** fournis par `eqLogic::preToHtml()` — `#history#` n'est produit que par
   `cmd::toHtml()` (`cmd.class.php:2167`). Les utiliser dans un widget eqLogic laisserait la
   chaîne littérale dans le HTML. Écrire `history cursor` en dur, et ne se servir que des
   placeholders réellement définis par `toHtml()` dans `jeeconsoapi.class.php`.
6. **Racine du widget** : `class="eqLogic eqLogic-widget …"`. La classe `eqLogic` conditionne
   le Ctrl+clic qui superpose les courbes de l'équipement (`desktop/common/js/ui.js:268`).
   Le clic d'historique lui-même exige `.history[data-cmd_id]` (`ui.js:264`) ; en **mobile**,
   `data-type="info"` est en plus **obligatoire** (`mobile/js/application.js:111`).
7. **Widget self-contained** : le CSS du widget est embarqué dans son `.html` et scopé sous
   `.jca` / `.jca-m`. `desktop/css/jeeconsoapi.css` ne concerne QUE la page de configuration.
8. **Tableau des commandes** : pas de sélecteur de type / sous-type / `generic_type`. Les
   rendre modifiables permettrait d'écraser à la sauvegarde le `generic_type` posé par le
   plugin et de casser le widget.
9. **`eqLogicManager` n'existe pas** — utiliser `eqLogic::byType()`, `eqLogic::byId()`.
10. **Backfill** : écrire via `cmd::addHistoryValue($value, $datetime)` (publique,
    `cmd.class.php` ~l.2985), **jamais** `cmd::event()` — qui déclencherait `scenario::check()`
    sur chacun des ~1100 points. Idempotence via `history::byCmdIdDatetime()`.
11. **Logs** : `log::add('jeeconsoapi', 'info|debug|warning|error', $message)`. Les erreurs
    commencent par le contexte puis `[fichier:ligne]` (helper `jeeconsoapi_log()`).
    Rappel Jeedom, **vérifié dans le core** (`log.class.php:124-127`) : seul le niveau
    **400 (`error`)** remonte au centre de messages, et uniquement si
    `addMessageForErrorLog` est actif ; au-delà de 500 également. **`warning` (300) n'y va
    pas** — contrairement à ce qu'affirme le CLAUDE.md de `jeewhatsapp`. `warning` est donc
    utilisable pour signaler sans alerter.

---

## 8. ENVIRONNEMENT DE DÉVELOPPEMENT LOCAL

| Élément | Valeur |
|---|---|
| Conteneur | `jeedom-dev` (Jeedom 4.6.1) |
| URL | `http://localhost:9080` |
| Page du plugin | `http://localhost:9080/index.php?v=d&m=jeeconsoapi&p=jeeconsoapi` |
| Sources | `C:\Users\athie\Documents\Docker\Jeedom\www\plugins\jeeconsoapi\` |
| Logs | `C:\Users\athie\Documents\Docker\Jeedom\www\log\` |

> **`www/plugins` est bind-monté** sur `/var/www/html/plugins` : toute écriture depuis Windows
> est immédiatement visible par Apache. **Aucun `sync` n'est nécessaire** pour ce plugin.

### Boucle de développement

```bash
# 1. Lint (le bind mount rend la copie inutile)
docker exec jeedom-dev php -l /var/www/html/plugins/jeeconsoapi/core/class/jeeconsoapi.class.php

# 2. Auto-test de non-régression — 66 vérifications, AUCUN appel réseau
docker exec jeedom-dev php /var/www/html/plugins/jeeconsoapi/tools/selftest.php

# 3. Forcer un cycle complet sans attendre 6h du matin
docker exec jeedom-dev php -r "require_once '/var/www/html/core/php/core.inc.php'; jeeconsoapi::pull();"

# 4. Logs — LES DEUX, systématiquement
tail -n 80 "C:/Users/athie/Documents/Docker/Jeedom/www/log/jeeconsoapi"
tail -n 80 "C:/Users/athie/Documents/Docker/Jeedom/www/log/http.error"
```

> ⚠️ **Le niveau de log global de cette instance est 400 (ERROR)** : les `log::add(…,'info',…)`
> et `'warning'` **ne sont pas écrits** tant que le niveau du canal `jeeconsoapi` n'est pas
> abaissé. Un fichier `log/jeeconsoapi` vide ne signifie donc **pas** que le code n'a pas
> tourné. Pour diagnostiquer, abaisser le niveau, ou lire directement l'état persisté
> (`state_*`) via `getScheduleInfo()`.

### Visibilité du canal dans « Analyse → Logs »

`log::liste()` (`log.class.php:589`) énumère les **fichiers présents** dans `www/log/` :
tant qu'aucune ligne n'a été écrite, le canal n'existe pas et n'apparaît nulle part dans
l'UI. Combiné au niveau global à 400 alors que tout le fonctionnement normal du plugin est
en `info`, le canal serait resté invisible en permanence.

`jeeconsoapi_setup_log()` (`plugin_info/install.php`) règle les deux : il crée le fichier et
force le niveau du canal à **Info** — mais **une seule fois**, gardé par le drapeau
`config::byKey('log_level_initialized', 'jeeconsoapi')`. Un choix ultérieur de l'utilisateur
ne doit jamais être repris par une mise à jour.

> ⚠️ `log::getLogLevel()` lit un **cache statique peuplé une fois par processus**
> (`log.class.php:61`). Après un `config::save('log::level::…')` dans le même processus, elle
> renvoie encore l'ancienne valeur. Pour vérifier un changement, relire
> `config::byKey('log::level::jeeconsoapi')` ou relancer un processus.

### Où se règle le niveau de log d'un plugin

**Sur la page du plugin** (`Plugins → Gestion des plugins → JeeConsoAPI`), pas dans
`Réglages → Système → Configuration → Logs`. Cette dernière n'énumère qu'une liste **codée en
dur** de canaux du cœur (`desktop/php/administration.php:971` :
`array('scenario','plugin','market','api','connection','interact','tts','report','event')`) —
aucun plugin n'y figure. Les radios Aucun / Défaut / Debug / Info / Warning / Erreur d'un
plugin sont générées par `desktop/js/plugin.js:304-309`.

La clé de configuration `log::level::<pluginId>` est créée automatiquement à l'activation
(`plugin.class.php:1035`), avec `default:1` — donc héritage du niveau global. La résolution
se fait dans `log::getLogLevel()` (`log.class.php:71`).

En CLI, pour basculer un canal sans passer par l'UI :

```bash
docker exec jeedom-dev php -r "require_once '/var/www/html/core/php/core.inc.php'; config::save('log::level::jeeconsoapi', '{\"100\":\"0\",\"200\":\"1\",\"300\":\"0\",\"400\":\"0\",\"1000\":\"0\",\"default\":\"0\"}');"
```

Vérifié en conditions réelles : en Défaut seul `[ERROR]` est écrit ; en Info, `[INFO]`,
`[WARNING]` et `[ERROR]` le sont, `[DEBUG]` non. Le canal apparaît bien dans `log::liste()`,
donc dans le visualiseur `Analyse → Logs`.

> 🔴 **Piège connu** : le fichier d'erreurs PHP s'appelle **`http.error`**, pas `http`. Un
> `jeedom-dev.sh log:errors http` lit un fichier inexistant et affiche « aucune erreur
> trouvée » — ne jamais interpréter cela comme l'absence d'erreur.

> 🔴 Un `info.json` invalide fait **ignorer le plugin silencieusement** ; la trace est dans
> `www/log/plugin`.

---

## 9. SOURCES DE RÉFÉRENCE

### Plugins du même auteur (dans ce dépôt Docker)

| Plugin | Ce qu'il apporte |
|---|---|
| `pawjote` | **Référence principale** — plugin sans démon, crons déclarés en `install.php`, `createCommands()`, `addCmdToTable()`, widget self-contained |
| `jeewhatsapp` | Référence pour `ajax::init()` avec liste blanche, structure README/badges de soutien |

### Conso API

| Ressource | URL |
|---|---|
| Service | https://conso.boris.sh |
| Documentation | https://conso.boris.sh/documentation |
| Consentement / token | https://conso.boris.sh/api/auth |
| Code source | https://github.com/bokub/conso-api |
| Librairie Node de référence | https://github.com/bokub/linky |

### Jeedom

| Ressource | URL |
|---|---|
| phpdoc | https://doc.jeedom.com/dev/phpdoc/4.0/namespaces/default.html |
| Tutoriel plugin | https://doc.jeedom.com/fr_FR/dev/tutorial_plugin |
| Core (GitHub) | https://github.com/jeedom/core |

**Règle** : toujours vérifier le phpdoc ou le core avant d'appeler une méthode Jeedom.
Ne jamais inventer de méthode.
