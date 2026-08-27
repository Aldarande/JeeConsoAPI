# JeeConsoAPI

Remonte la consommation électrique de votre compteur **Linky** dans Jeedom, via
[Conso API](https://conso.boris.sh) — une passerelle gratuite et open-source vers les API
Enedis « Token V3 » et « Metering Data V5 ».

Conso API ne stocke aucune donnée : le service est *stateless*. C'est Jeedom qui conserve
votre historique.

---

## Prérequis

- Jeedom **4.4** ou supérieur
- Un compteur **Linky** et son numéro de **PRM** (14 chiffres)
- Un **token Bearer personnel**, obtenu une seule fois via le consentement Enedis

Le plugin n'a **aucune dépendance** à installer et **aucun démon** : il se contente d'un
appel HTTP en PHP, déclenché une fois par jour.

---

## Installation

1. Installez le plugin depuis le Market, ou copiez le dossier `jeeconsoapi` dans le
   répertoire `plugins/` de votre Jeedom.
2. Dans **Plugins → Gestion des plugins**, activez **JeeConsoAPI**.
   L'activation crée automatiquement la tâche cron quotidienne.

---

## Obtenir son token Enedis

1. Ouvrez **<https://conso.boris.sh/api/auth>** (le bouton **« Obtenir mon token »** de la
   page du compteur y mène directement).
2. Suivez le parcours de consentement Enedis et sélectionnez le point de livraison concerné.
3. Copiez le token obtenu, de la forme `xxx.yyy.zzz`.

Ce token est personnel. Il est chiffré avant d'être stocké par le plugin et n'est jamais
réaffiché ensuite.

---

## Trouver son PRM

Le **PRM** (Point Référence Mesure) est un identifiant à **14 chiffres**. Vous le trouvez :

- sur votre **facture d'électricité** ;
- sur l'**écran du compteur Linky** : appuyez sur la touche **+** jusqu'à faire apparaître
  « Numéro de PRM ».

---

## Créer un compteur

**Plugins → Énergie → JeeConsoAPI → Ajouter.**

| Champ | Description |
|---|---|
| Nom du compteur | Libre, par exemple « Maison » |
| Objet parent | L'objet Jeedom auquel rattacher le compteur |
| PRM | Exactement 14 chiffres, sans espace |
| Token Bearer | Le token obtenu ci-dessus |

Sauvegardez, puis cliquez sur **« Tester la connexion »** : le plugin effectue une requête
réelle et vous indique le code HTTP obtenu ainsi que le nombre de points reçus.

> Le champ **Token** reste vide à chaque réouverture de la page : c'est voulu. Un champ vide
> signifie « conserver le token actuel ». N'y saisissez quelque chose que pour le remplacer.

---

## Commandes créées

| Nom | Type | Unité | Historisée |
|---|---|---|---|
| Consommation quotidienne | info / numeric | kWh | oui |
| Puissance maximale quotidienne | info / numeric | VA | oui |
| Date des données | info / string | — | non |
| Dernière mise à jour | info / string | — | non |
| Actualiser maintenant | action | — | — |

Les deux commandes numériques sont historisées : le **graphique d'historique natif de
Jeedom** fonctionne sans configuration supplémentaire.

---

## Importer l'historique passé

Par défaut, le plugin ne remonte que les données de la veille, jour après jour. Pour disposer
immédiatement d'un historique :

1. Ouvrez la page du compteur.
2. Section **« Importer l'historique »**, choisissez la profondeur : **1, 6, 12 ou 36 mois**.
3. Cliquez sur **« Importer l'historique »** et confirmez.

L'import ne coûte que **deux requêtes au total**, quelle que soit la profondeur choisie. Il
est **idempotent** : le relancer n'écrit jamais de doublon, les points déjà présents sont
simplement ignorés.

---

## Comment fonctionne la récupération quotidienne

Conso API est gratuit, et les quotas Enedis (5 requêtes/seconde, 10 000 requêtes/heure) sont
**partagés entre tous ses utilisateurs**. Le service demande donc un seul cycle d'appel par
jour et par compteur, à un horaire non rond. Le plugin applique cette règle strictement.

- La tâche cron s'exécute toutes les 5 minutes entre 6h et 14h55, mais **n'émet une requête
  que lorsque l'état du compteur l'autorise**. Le rythme du cron n'a rien à voir avec le
  rythme des appels.
- Chaque jour, un **horaire est tiré au hasard entre 08:00:00 et 09:59:59**.
- Enedis publie les données de la veille vers 8h, parfois avec 1 à 2 heures de retard. Si
  elles sont absentes, le plugin retente **une fois** dans la matinée, puis **une fois** en
  début d'après-midi, et abandonne jusqu'au lendemain.
- Un **plafond dur de 3 appels par jour et par compteur** borne le tout.

L'encart **« Planification »** de la page du compteur affiche à tout moment l'état réel :
dernières données obtenues, prochain appel autorisé, appels déjà consommés dans la journée.

### Configuration globale

**Plugins → Gestion des plugins → JeeConsoAPI → Configuration** permet d'ajuster :

| Paramètre | Défaut | Rôle |
|---|---|---|
| Début de la fenêtre du matin | 8 | Heure la plus tôt à laquelle un appel peut avoir lieu |
| Fin de la fenêtre du matin | 10 | Heure limite de la fenêtre matinale (exclue) |
| Heure du nouvel essai de l'après-midi | 14 | Heure à partir de laquelle le plugin retente |
| Appels maximum par jour | 3 | Plafond dur, par compteur |

---

## Pourquoi le plugin est aussi prudent : le quota est partagé

C'est la contrainte structurante de ce plugin, et elle mérite d'être comprise.

Les quotas Enedis (5 requêtes/seconde, 10 000 requêtes/heure) ne sont **pas par utilisateur**.
Ils sont **globaux et partagés** entre tous les clients de Conso API : ce plugin, l'intégration
Home Assistant, la CLI `linky`, et tous les autres. Le risque n'est donc pas qu'un utilisateur
abuse — un compteur, c'est 2 requêtes par jour — mais que **l'ensemble du parc installé se
présente au même moment**.

Quatre mécanismes répondent à ce problème.

### 1. Un horaire tiré au hasard, jamais rond

Chaque installation tire son propre instant d'appel dans la fenêtre du matin. Deux
installations n'appellent donc jamais au même moment par construction, et le service n'encaisse
pas de pointe à 8h00'00 pile.

### 2. Ne jamais appeler avant que les données existent

C'est le levier le plus efficace, et le moins évident. Enedis publie les données de la veille
vers 8h. Appeler à 6h renvoie une réponse vide, ce qui déclenche un nouvel essai : **deux
appels pour rien**. Sur un parc entier, cela représentait la moitié du trafic généré par le
plugin.

La fenêtre par défaut commence donc à **8h**, et non à 6h. Mieux : le plugin **apprend**.
Si vos données arrivent habituellement à 9h, il relève tout seul son plancher horaire et cesse
d'appeler avant. Ce plancher redescend dès que les données réapparaissent plus tôt, pour qu'un
seul jour de retard ne le fige pas définitivement.

### 3. Étaler les nouveaux essais, pas seulement les premiers appels

Un retard de publication chez Enedis est un événement **corrélé** : il frappe tout le parc le
même matin. Si toutes les installations retentaient dans la même demi-heure, on fabriquerait
exactement la pointe qu'on cherche à éviter. Le rattrapage de l'après-midi est donc étalé sur
plusieurs heures, lui aussi au hasard.

### 4. Écouter le service quand il dit stop

Si Conso API répond `429 Too Many Requests` ou `403`, le plugin abandonne pour la journée au
lieu de réessayer. Et s'il renvoie un en-tête `Retry-After`, le plugin le respecte — en y
ajoutant un décalage aléatoire, sans quoi tous les clients ayant reçu la même consigne
reviendraient frapper à la seconde près ensemble.

### La limite honnête

Aucun de ces mécanismes ne supprime les collisions ponctuelles : une installation ne sait pas
combien d'autres existent, ni quand elles appellent. Élargir la fenêtre n'y change quasiment
rien, les collisions étant statistiques et non liées à sa largeur.

Ce qui est maîtrisé, c'est le **volume total** — 2 requêtes par jour et par compteur, sans
gaspillage — et l'**absence d'effet de horde** lors des incidents. Si le plugin devait atteindre
plusieurs milliers d'installations, la bonne réponse ne serait plus technique côté client mais
une coordination avec le mainteneur de Conso API.

---

## Utilisation dans un scénario

La commande action **« Actualiser maintenant »** peut être appelée depuis un scénario. Elle
force un cycle immédiat, **hors planification**.

> Cet appel consomme une des tentatives quotidiennes autorisées. Ne le déclenchez pas de
> façon répétée : le service est mutualisé.

---

## Sécurité du token

- **Chiffré au repos** avec `utils::encrypt()` (AES-256-CBC + HMAC-SHA256, clé système
  Jeedom). Il n'est déchiffré qu'au moment de construire l'en-tête `Authorization`.
- **Jamais réaffiché** dans le formulaire.
- **Jamais journalisé** : seule une forme masquée peut apparaître dans les logs. Le PRM y est
  également masqué, à l'exception de ses 4 derniers chiffres.
- **Jamais renvoyé** par un endpoint AJAX.

> Comme pour tout plugin Jeedom, un administrateur du système ou le détenteur de la clé API
> Jeedom peut lire la configuration des équipements. Le chiffrement protège la valeur en base
> et dans les sauvegardes, pas contre un accès administrateur légitime. Ne partagez pas votre
> clé API Jeedom.

---

## Dépannage

| Symptôme | Que faire |
|---|---|
| Aucune donnée juste après l'installation | Normal : le premier appel a lieu le lendemain matin. Utilisez **« Actualiser maintenant »** pour ne pas attendre. |
| `401` dans les logs | Le token est invalide, expiré, ou n'autorise pas ce PRM. Régénérez-le sur conso.boris.sh puis ressaisissez-le. |
| `400` dans les logs | Vérifiez le PRM : exactement 14 chiffres, et celui associé au consentement. |
| « Données de la veille pas encore publiées » | Enedis a du retard. Le plugin retentera de lui-même. |
| `500` dans les logs | Panne côté Enedis ou Conso API. Un nouvel essai est programmé automatiquement. |
| Le widget affiche `--` | Aucune valeur n'a encore été collectée. Consultez l'encart « Planification ». |

Les logs se consultent dans **Analyse → Logs → jeeconsoapi**.

> **Important** — par défaut, Jeedom ne consigne que les messages de niveau **Erreur**. Les
> messages qui expliquent le déroulement normal (« créneau du jour tiré à 08:14:22 »,
> « données de la veille pas encore publiées », « cycle terminé avec succès ») sont émis en
> niveau **Info** et n'apparaîtront donc pas tant que vous n'aurez pas abaissé le niveau.
>
> Pour tout voir : **Réglages → Système → Configuration → Logs**, puis passez le niveau du
> plugin `jeeconsoapi` à **Info**. Les erreurs bloquantes (token refusé, PRM invalide)
> restent visibles quoi qu'il arrive, et remontent aussi au centre de messages.

L'encart **« Planification »** de la page du compteur affiche l'essentiel de cet état sans
avoir à toucher aux niveaux de log.

---

## Limites d'appel côté plugin

Deux compteurs distincts, tous deux visibles dans l'encart « Planification » :

| Compteur | Plafond | Ce qu'il borne |
|---|---|---|
| **Appels automatiques** | 3 / jour (configurable, max 5) | Les cycles déclenchés par le cron |
| **Actions manuelles** | 10 / jour | Les boutons « Tester la connexion », « Actualiser maintenant » et « Importer l'historique », ainsi que la commande action `refresh` appelée depuis un scénario |

Les deux sont indépendants : le cron qui a épuisé son quota ne vous empêche pas de
diagnostiquer votre configuration, et inversement un diagnostic répété ne consomme pas le
budget du cron. Mais aucun des deux n'est contournable — c'est ce qui garantit que le plugin
tient sa promesse envers un service gratuit et mutualisé.

**Combien de requêtes cela fait-il réellement ?** Un cycle vaut 2 requêtes, la consommation
et la puissance maximale étant deux endpoints distincts. En usage normal, le plugin émet
donc **2 requêtes par jour et par compteur**. Le pire cas théorique — toutes les tentatives
échouent *et* vous cliquez sur tous les boutons jusqu'au plafond — atteint 26 requêtes.
Ce n'est pas un fonctionnement nominal, mais autant que le chiffre soit énoncé.

---

## Limites connues

- La **courbe de charge** par pas de 30 minutes n'est pas encore gérée.
- La **production photovoltaïque**, **Tempo** et **Ecowatt** sont hors périmètre.
- L'obtention du token n'est **pas automatisée** : pas de flux OAuth intégré, la saisie est
  manuelle et se fait une seule fois.

---

## Soutenir le développement

Ce plugin est gratuit et open-source. Un don aide à financer le développement, les tests et
les mises à jour.

[![Ko-fi](https://img.shields.io/badge/don-Ko--fi-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/aldarande)
[![GitHub Sponsors](https://img.shields.io/badge/don-GitHub%20Sponsors-ea4aaa?logo=github&logoColor=white)](https://github.com/sponsors/Aldarande)
[![Liberapay](https://img.shields.io/badge/don-Liberapay-F6C915?logo=liberapay&logoColor=black)](https://liberapay.com/Aldarande/donate)

| Plateforme | Type | Lien |
|---|---|---|
| ☕ Ko-fi | Don ponctuel | [ko-fi.com/aldarande](https://ko-fi.com/aldarande) |
| 💙 GitHub Sponsors | Mensuel | [github.com/sponsors/Aldarande](https://github.com/sponsors/Aldarande) |
| 💛 Liberapay | Récurrent, anonyme possible | [liberapay.com/Aldarande](https://liberapay.com/Aldarande/donate) |

Merci également à **[Boris K.](https://github.com/bokub)** pour Conso API, sans qui ce plugin
n'existerait pas.

---

## Licence

**AGPL v3** — <https://www.gnu.org/licenses/agpl-3.0.html>

Ce plugin n'est ni affilié ni soutenu par Enedis.
