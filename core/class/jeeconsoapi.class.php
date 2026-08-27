<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Plugin JeeConsoAPI - Aldarande
 *
 * Source de données : Conso API (https://conso.boris.sh), passerelle open-source
 * vers les API Enedis « Token V3 » et « Metering Data V5 ».
 *
 * ---------------------------------------------------------------------------
 * RÈGLES D'USAGE DU SERVICE — à ne jamais contourner
 * ---------------------------------------------------------------------------
 * - Conso API demande explicitement UN SEUL CYCLE D'APPEL PAR JOUR et par
 *   compteur, dans la fenêtre 6h-10h, à un horaire « pas trop précis »
 *   (c'est-à-dire jamais pile à l'heure ronde).
 * - Un cycle = 2 requêtes HTTP, car la consommation quotidienne et la puissance
 *   maximale sont deux endpoints distincts. Il n'existe aucun moyen d'obtenir
 *   les deux en un seul appel. C'est le minimum incompressible pour alimenter
 *   les deux commandes du plugin.
 * - Les données de la veille sont publiées par Enedis vers 8h, parfois avec
 *   1 à 2 heures de retard. Si elles sont absentes, on retente une fois dans la
 *   matinée puis une fois en début d'après-midi, JAMAIS en boucle serrée.
 * - Quotas globaux Enedis, partagés entre TOUS les utilisateurs de Conso API :
 *   5 requêtes/seconde et 10 000 requêtes/heure. Un cycle quotidien est
 *   négligeable devant ces plafonds, mais c'est précisément parce que le quota
 *   est mutualisé qu'il ne faut pas sur-solliciter le service.
 * - Un User-Agent identifiable est OBLIGATOIRE sur chaque requête.
 *
 * Le cron tique toutes les 5 minutes entre 6h et 14h55, mais n'émet une requête
 * que lorsque l'état persisté de l'équipement l'autorise : voir pull() et
 * runCycle(). Un plafond dur (max_attempts, défaut 3) borne le nombre d'appels
 * effectifs par jour et par compteur, quoi qu'il arrive.
 *
 * ---------------------------------------------------------------------------
 * SÉCURITÉ DU TOKEN
 * ---------------------------------------------------------------------------
 * Le token Bearer personnel est chiffré au repos via utils::encrypt()
 * (AES-256-CBC + HMAC-SHA256, clé système Jeedom) dans preSave(), et déchiffré
 * uniquement au moment de construire l'en-tête Authorization.
 * Il n'est JAMAIS journalisé, JAMAIS renvoyé par un endpoint AJAX, et JAMAIS
 * réaffiché dans le formulaire. Seul jeeconsoapi_mask() peut apparaître en log.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

/* ===================================================================
   Helper log — préfixe contextuel + fichier:ligne à l'origine
=================================================================== */
function jeeconsoapi_log($_level, $_ctx, $_message, $_file = null, $_line = null) {
    $loc = ($_file && $_line) ? ' [' . basename($_file) . ':' . $_line . ']' : '';
    log::add('jeeconsoapi', $_level, '[' . $_ctx . ']' . $loc . ' ' . $_message);
}

/* ===================================================================
   Helper — masquage du token pour les logs
   C'est la SEULE forme sous laquelle un token peut apparaître en log.
=================================================================== */
function jeeconsoapi_mask($_token) {
    $_token = (string) $_token;
    if ($_token === '') {
        return '(vide)';
    }
    return substr($_token, 0, 6) . '…(' . strlen($_token) . ' car.)';
}

/* ===================================================================
   Helper — masquage du PRM pour les logs (donnée personnelle)
=================================================================== */
function jeeconsoapi_mask_prm($_prm) {
    $_prm = (string) $_prm;
    if (strlen($_prm) < 4) {
        return '(invalide)';
    }
    return str_repeat('•', strlen($_prm) - 4) . substr($_prm, -4);
}

/* ===================================================================
   Helper — expurge d'un texte tout ce qui ressemble à un PRM

   Indispensable avant de journaliser un extrait de corps de réponse ou
   un message d'erreur du service : l'enveloppe Enedis commence par
   « usage_point_id »: "<14 chiffres>", et un message d'erreur peut
   légitimement contenir le PRM en clair.
=================================================================== */
function jeeconsoapi_redact($_text) {
    return preg_replace_callback('/\d{14}/', function ($m) {
        return jeeconsoapi_mask_prm($m[0]);
    }, (string) $_text);
}

/* ===================================================================
   Helper — configuration globale du plugin, bornée
=================================================================== */
function jeeconsoapi_cfg($_key, $_default, $_min, $_max) {
    $val = config::byKey($_key, 'jeeconsoapi', $_default);
    if ($val === '' || $val === null || !is_numeric($val)) {
        $val = $_default;
    }
    return max($_min, min($_max, (int) $val));
}

/* ===================================================================
   Helper — User-Agent identifiable (exigé par Conso API)
=================================================================== */
function jeeconsoapi_user_agent() {
    static $ua = null;
    if ($ua === null) {
        $version = '1.0';
        $infoFile = dirname(__FILE__) . '/../../plugin_info/info.json';
        if (file_exists($infoFile)) {
            $info = json_decode(file_get_contents($infoFile), true);
            if (is_array($info) && !empty($info['pluginVersion'])) {
                $version = (string) $info['pluginVersion'];
            }
        }
        $ua = 'JeeConsoAPI/' . $version . ' (Jeedom plugin) - github.com/Aldarande/JeeConsoAPI';
    }
    return $ua;
}

/* ===================================================================
   Helper — token en clair pour un équipement
   Passe-plat si la valeur n'est pas chiffrée (compatibilité ascendante :
   utils::decrypt() renvoie l'entrée telle quelle sans le préfixe 'crypt:').
=================================================================== */
function jeeconsoapi_token($_eqLogic) {
    $raw = (string) $_eqLogic->getConfiguration('token', '');
    if ($raw === '') {
        return '';
    }
    $clear = utils::decrypt($raw);
    // decrypt() renvoie null si le HMAC ne correspond pas (clé système changée,
    // valeur corrompue) : dans ce cas le token est irrécupérable, on le signale.
    return ($clear === null) ? '' : trim((string) $clear);
}

/* ===================================================================
   Requête HTTP vers Conso API

   Retourne array('code' => int, 'body' => string, 'json' => array|null,
                  'error' => string)

   Le token ne transite QUE dans l'en-tête Authorization et n'est jamais
   journalisé ici ni ailleurs.
=================================================================== */
function jeeconsoapi_http_get($_type, $_prm, $_token, $_start, $_end) {
    $url = jeeconsoapi::API_BASE . '/' . rawurlencode($_type)
         . '?prm='   . rawurlencode($_prm)
         . '&start=' . rawurlencode($_start)
         . '&end='   . rawurlencode($_end);

    $headers = array(
        'Authorization: Bearer ' . $_token,
        'User-Agent: ' . jeeconsoapi_user_agent(),
        'Accept: application/json',
    );

    $code       = 0;
    $body       = '';
    $err        = '';
    $retryAfter = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $retryAfter = null;
        curl_setopt($ch, CURLOPT_HEADERFUNCTION,
            function ($ch, $header) use (&$retryAfter) {
                if (stripos($header, 'retry-after:') === 0) {
                    $retryAfter = trim(substr($header, 12));
                }
                return strlen($header);
            });
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            // FAILONERROR à false : on veut LIRE le code HTTP réel (400/401/500)
            // pour le traiter, pas recevoir une erreur curl générique.
            CURLOPT_FAILONERROR    => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $body = curl_exec($ch);
        if ($body === false) {
            $err  = curl_error($ch);
            $body = '';
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        // Repli sans curl (même approche que jeewhatsapp::sendToDaemon)
        $context = stream_context_create(array('http' => array(
            'method'        => 'GET',
            'header'        => implode("\r\n", $headers),
            'timeout'       => 30,
            'ignore_errors' => true, // sinon file_get_contents renvoie false sur 4xx/5xx
        )));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $body = '';
            $err  = 'file_get_contents a échoué';
        }
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                    $code = (int) $m[1];
                }
                if (stripos($header, 'retry-after:') === 0) {
                    $retryAfter = trim(substr($header, 12));
                }
            }
        }
    }

    $json = ($body !== '') ? json_decode($body, true) : null;

    return array(
        'code'        => $code,
        'body'        => $body,
        'json'        => is_array($json) ? $json : null,
        'error'       => $err,
        'retry_after' => isset($retryAfter) ? $retryAfter : null,
    );
}

/* ===================================================================
   Helper — interprète un en-tête Retry-After

   Le service peut répondre soit un nombre de secondes, soit une date HTTP.
   C'est sa manière explicite de dire « rappelle-moi à ce moment-là » :
   l'ignorer reviendrait à retomber dessus au pire moment, tout le parc
   installé en même temps.

   Retourne un timestamp, ou null si l'en-tête est absent ou illisible.
   Borné à 24 h pour qu'une valeur aberrante ne gèle pas l'équipement.
=================================================================== */
function jeeconsoapi_retry_after_ts($_header) {
    if ($_header === null || trim((string) $_header) === '') {
        return null;
    }
    $raw = trim((string) $_header);
    $ts  = ctype_digit($raw) ? (time() + (int) $raw) : strtotime($raw);
    if ($ts === false || $ts === null || $ts <= time()) {
        return null;
    }
    return min($ts, time() + 86400);
}

/* ===================================================================
   Helper — extrait le diagnostic réel d'une réponse d'erreur

   Forme OBSERVÉE EN PRODUCTION : Conso API enveloppe l'erreur Enedis, et
   le message utile est imbriqué sous `error`, pas à la racine :

     { "status": 400,
       "message": "The Enedis API returned an error",
       "error": { "error": "ADAM-DC-0007",
                  "error_description": "Le client n'est pas titulaire du point demandé." } }

   Ne lire que la racine renvoie « The Enedis API returned an error », qui
   n'apprend rien à l'utilisateur — alors que `error_description` lui dit
   exactement quoi corriger.

   Retourne array('code' => 'ADAM-DC-0007', 'text' => '…'), PRM expurgé.
=================================================================== */
function jeeconsoapi_api_error($_json) {
    $code = '';
    $text = '';
    if (!is_array($_json)) {
        return array('code' => $code, 'text' => $text);
    }

    // 1. Forme imbriquée (Enedis via Conso API)
    if (isset($_json['error']) && is_array($_json['error'])) {
        $inner = $_json['error'];
        if (!empty($inner['error']) && is_string($inner['error'])) {
            $code = $inner['error'];
        }
        foreach (array('error_description', 'message', 'detail') as $k) {
            if (!empty($inner[$k]) && is_string($inner[$k])) {
                $text = $inner[$k];
                break;
            }
        }
    }

    // 2. Formes plates, en repli
    if ($text === '') {
        foreach (array('error_description', 'error', 'message', 'detail') as $k) {
            if (!empty($_json[$k]) && is_string($_json[$k])) {
                $text = $_json[$k];
                break;
            }
        }
    }

    return array('code' => $code, 'text' => jeeconsoapi_redact($text));
}

/* ===================================================================
   Helper — conseil actionnable pour les codes Enedis connus
=================================================================== */
function jeeconsoapi_enedis_hint($_code) {
    switch ($_code) {
        case 'ADAM-DC-0007':
            return __("Enedis ne vous reconnaît pas comme titulaire de ce point de livraison. "
                    . "Le PRM et le token sont pourtant cohérents : c'est le consentement qui "
                    . "est en cause. Refaites-le sur conso.boris.sh en veillant à ce que le nom "
                    . "et l'adresse correspondent EXACTEMENT au titulaire du contrat "
                    . "d'électricité de ce compteur.", __FILE__);
        case 'ADAM-ERR0123':
        case 'ADAM-DC-0006':
            return __("Le consentement associé à ce token semble absent ou expiré. "
                    . "Refaites-le sur conso.boris.sh.", __FILE__);
        default:
            return '';
    }
}

/* ===================================================================
   Extraction des points de mesure

   La documentation de Conso API ne publie pas le schéma exact du corps.
   Deux formes sont possibles selon que le service ré-enveloppe ou aplatit
   la réponse brute Enedis Metering Data V5 :

     { "meter_reading": { "reading_type": {...}, "interval_reading": [...] } }
     { "reading_type": {...}, "interval_reading": [...] }

   On accepte les deux : deux lignes, et l'incertitude disparaît.

   Formats attendus par type :
     daily_consumption     → unit "Wh", date "YYYY-MM-DD"
     consumption_max_power → unit "VA", date "YYYY-MM-DD HH:MM:SS"
=================================================================== */
function jeeconsoapi_extract($_json) {
    if (!is_array($_json)) {
        return null;
    }
    $root = (isset($_json['meter_reading']) && is_array($_json['meter_reading']))
          ? $_json['meter_reading']
          : $_json;

    if (!isset($root['interval_reading']) || !is_array($root['interval_reading'])) {
        return null;
    }

    $unit = '';
    if (isset($root['reading_type']) && is_array($root['reading_type'])
        && isset($root['reading_type']['unit'])) {
        $unit = (string) $root['reading_type']['unit'];
    }

    $points = array();
    foreach ($root['interval_reading'] as $row) {
        if (!is_array($row) || !isset($row['date']) || !isset($row['value'])) {
            continue;
        }
        if (!is_numeric($row['value'])) {
            continue;
        }
        $points[] = array(
            'date'  => (string) $row['date'],
            'value' => (float) $row['value'],
        );
    }

    return array('unit' => $unit, 'points' => $points);
}

/* ===================================================================
   Normalisation d'une valeur selon l'unité retournée par l'API
   - consommation : Wh → kWh (2 décimales)
   - puissance    : VA conservé tel quel
=================================================================== */
function jeeconsoapi_to_kwh($_value, $_unit) {
    $unit = strtolower(trim((string) $_unit));
    if ($unit === 'kwh') {
        return round((float) $_value, 2);
    }
    // Wh par défaut (unité documentée pour daily_consumption)
    return round(((float) $_value) / 1000, 2);
}


class jeeconsoapi extends eqLogic {

    const API_BASE       = 'https://conso.boris.sh/api';
    const AUTH_URL       = 'https://conso.boris.sh/api/auth';
    const TYPE_DAILY     = 'daily_consumption';
    const TYPE_MAX_POWER = 'consumption_max_power';

    /* Plafond quotidien des actions manuelles (Tester / Actualiser / Importer),
       par compteur. Généreux pour ne pas gêner l'installation et le diagnostic,
       mais borné : sans lui, un clic répété contournerait la limite que le plugin
       s'engage à respecter envers un service gratuit et mutualisé. */
    const MANUAL_DAILY_CAP = 10;

    /* ---------------------------------------------------------------
       CRON — point d'entrée unique

       Appelé toutes les 5 minutes entre 6h et 14h55. Ne déclenche un
       appel réseau que si l'état persisté de l'équipement l'autorise.
    --------------------------------------------------------------- */
    public static function pull() {
        $eqLogics = eqLogic::byType('jeeconsoapi', true);
        if (count($eqLogics) === 0) {
            return;
        }
        foreach ($eqLogics as $eqLogic) {
            if (!$eqLogic->getIsEnable()) {
                continue;
            }
            try {
                $eqLogic->runCycle(false);
            } catch (Exception $e) {
                jeeconsoapi_log('error', $eqLogic->getName() . '#' . $eqLogic->getId(),
                    $e->getMessage() . ' — ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
    }

    /* ---------------------------------------------------------------
       Un cycle de récupération

       $_force = true : appel immédiat, hors planification (bouton
       « Actualiser maintenant » ou commande action refresh). Consomme
       quand même le compteur de tentatives du jour.

       Retourne un tableau d'état exploitable par l'AJAX.
    --------------------------------------------------------------- */
    public function runCycle($_force = false) {
        $ctx = $this->getName() . '#' . $this->getId();

        $prm   = trim((string) $this->getConfiguration('prm', ''));
        $token = jeeconsoapi_token($this);

        if (!preg_match('/^\d{14}$/', $prm)) {
            jeeconsoapi_log('error', $ctx,
                'PRM absent ou mal formé (14 chiffres attendus) — équipement ignoré', __FILE__, __LINE__);
            return array('status' => 'config_error',
                         'message' => __('PRM absent ou mal formé (14 chiffres attendus).', __FILE__));
        }
        if ($token === '') {
            jeeconsoapi_log('error', $ctx,
                'Token Bearer absent ou indéchiffrable — équipement ignoré', __FILE__, __LINE__);
            return array('status' => 'config_error',
                         'message' => __('Token Bearer absent, vide ou indéchiffrable. Ressaisissez-le.', __FILE__));
        }

        $target = date('Y-m-d', strtotime('-1 day')); // les données portent sur la veille
        $today  = date('Y-m-d');
        $now    = time();

        $maxAttempts = jeeconsoapi_cfg('max_attempts', 3, 1, 5);

        if ($_force) {
            // Action manuelle : elle a son propre quota (voir consumeManualQuota()),
            // distinct du plafond du cron. Sans cela, un clic répété sur
            // « Actualiser maintenant » contournerait entièrement la limite que le
            // plugin s'engage à respecter vis-à-vis du service.
            $denied = $this->consumeManualQuota();
            if ($denied !== null) {
                return $denied;
            }
        } else {
            if ((string) $this->getConfiguration('state_done_date', '') === $target) {
                return array('status' => 'already_done', 'date' => $target);
            }

            // Changement de jour : on tire un nouveau créneau matinal aléatoire
            if ((string) $this->getConfiguration('state_slot_date', '') !== $today) {
                $slot = $this->drawMorningSlot();
                $this->setConfiguration('state_slot_date', $today);
                $this->setConfiguration('state_next_ts', $slot);
                $this->setConfiguration('state_attempts', 0);
                $this->saveState();
                jeeconsoapi_log('info', $ctx,
                    'Créneau du jour tiré au hasard : ' . date('H:i:s', $slot), __FILE__, __LINE__);
            }

            if ($now < (int) $this->getConfiguration('state_next_ts', 0)) {
                return array('status' => 'waiting',
                             'next'   => date('Y-m-d H:i:s', (int) $this->getConfiguration('state_next_ts', 0)));
            }

            if ((int) $this->getConfiguration('state_attempts', 0) >= $maxAttempts) {
                return array('status' => 'quota_reached', 'attempts' => (int) $this->getConfiguration('state_attempts', 0));
            }

            // Le crédit est consommé AVANT l'appel : un échec réseau ne doit pas
            // permettre de reboucler indéfiniment.
            $this->setConfiguration('state_attempts', (int) $this->getConfiguration('state_attempts', 0) + 1);
            $this->saveState();
        }

        return $this->fetchAndApply($target, $ctx, $_force);
    }

    /* ---------------------------------------------------------------
       Quota des actions MANUELLES (bouton Tester / Actualiser / Importer)

       Distinct du plafond du cron, pour deux raisons :
       - une action manuelle est délibérée et rare (installation, diagnostic),
         la bloquer parce que le cron a déjà tourné rendrait la configuration
         pénible ;
       - mais elle doit rester bornée, sinon un clic répété contournerait
         entièrement l'engagement du plugin envers un service mutualisé.

       Retourne null si l'appel est autorisé (le crédit est alors consommé),
       ou un tableau d'état décrivant le refus.
    --------------------------------------------------------------- */
    public function consumeManualQuota() {
        $today = date('Y-m-d');
        if ((string) $this->getConfiguration('state_manual_date', '') !== $today) {
            $this->setConfiguration('state_manual_date', $today);
            $this->setConfiguration('state_manual_attempts', 0);
        }

        $used = (int) $this->getConfiguration('state_manual_attempts', 0);
        if ($used >= self::MANUAL_DAILY_CAP) {
            $msg = sprintf(
                __('Plafond des actions manuelles atteint pour aujourd\'hui (%1$s/%2$s). '
                 . 'Conso API est un service gratuit et mutualisé : réessayez demain, '
                 . 'ou laissez le cron quotidien faire son travail.', __FILE__),
                $used, self::MANUAL_DAILY_CAP);
            jeeconsoapi_log('warning', $this->getName() . '#' . $this->getId(), $msg, __FILE__, __LINE__);
            return array('status' => 'manual_quota_reached', 'attempts' => $used, 'message' => $msg);
        }

        $this->setConfiguration('state_manual_attempts', $used + 1);
        $this->saveState();
        return null;
    }

    /* ---------------------------------------------------------------
       Tirage du créneau matinal — jamais pile à l'heure ronde
    --------------------------------------------------------------- */
    public function drawMorningSlot() {
        $start = jeeconsoapi_cfg('morning_start', 8, 0, 23);
        $end   = jeeconsoapi_cfg('morning_end', 10, 1, 23);

        /* Plancher appris — appeler avant qu'Enedis n'ait publié les données
           est une requête garantie perdue, doublée d'un nouvel essai : deux
           appels pour rien. Multiplié par le parc installé, c'est la première
           source de charge inutile sur un service mutualisé.
           On mémorise donc, par PRM, l'heure à partir de laquelle les données
           sont réellement apparues, et on ne tire jamais en dessous. */
        $floor = (int) $this->getConfiguration('state_slot_floor', 0);
        if ($floor > $start) {
            $start = $floor;
        }
        if ($start >= $end) {
            $start = max(0, $end - 1);
        }

        $span = ($end - $start) * 3600;
        return mktime($start, 0, 0) + mt_rand(0, max(1, $span) - 1);
    }

    /* ---------------------------------------------------------------
       Apprentissage du plancher horaire

       $_dataPresent = false : à cette heure-ci les données n'étaient pas
       encore là → ne plus tirer avant l'heure suivante.
       $_dataPresent = true  : elles y étaient → le plancher peut redescendre,
       sinon une seule journée de retard d'Enedis le figerait pour toujours.
    --------------------------------------------------------------- */
    public function learnSlotFloor($_dataPresent) {
        $hour  = (int) date('G');
        $end   = jeeconsoapi_cfg('morning_end', 10, 1, 23);
        $floor = (int) $this->getConfiguration('state_slot_floor', 0);

        $new = $_dataPresent ? min($floor, $hour) : max($floor, $hour + 1);
        $new = max(0, min($end - 1, $new));

        if ($new !== $floor) {
            $this->setConfiguration('state_slot_floor', $new);
            // Persistance immédiate : ne pas dépendre d'un saveState() ultérieur,
            // qui n'existe pas sur tous les chemins d'appel.
            $this->saveState();
            jeeconsoapi_log('info', $this->getName() . '#' . $this->getId(),
                'Plancher horaire ajusté à ' . $new . 'h '
                . ($_dataPresent ? '(données présentes)' : '(données absentes)'), __FILE__, __LINE__);
        }
    }

    /* ---------------------------------------------------------------
       Appel effectif + mise à jour des commandes
    --------------------------------------------------------------- */
    public function fetchAndApply($_target, $_ctx, $_force = false) {
        $prm      = trim((string) $this->getConfiguration('prm', ''));
        $token    = jeeconsoapi_token($this);
        $endBound = date('Y-m-d', strtotime($_target . ' +1 day')); // borne de fin EXCLUSIVE

        jeeconsoapi_log('info', $_ctx,
            'Appel Conso API — PRM ' . jeeconsoapi_mask_prm($prm)
            . ', données du ' . $_target . ($_force ? ' (forcé)' : ''), __FILE__, __LINE__);

        /* --- 1. Consommation quotidienne --- */
        $res = jeeconsoapi_http_get(self::TYPE_DAILY, $prm, $token, $_target, $endBound);

        if ($res['code'] !== 200) {
            return $this->handleHttpError($res, $_ctx, self::TYPE_DAILY);
        }

        $data = jeeconsoapi_extract($res['json']);
        if ($data === null) {
            jeeconsoapi_log('error', $_ctx,
                'Réponse 200 mais corps inexploitable (ni meter_reading.interval_reading ni interval_reading). '
                . 'Début du corps : ' . jeeconsoapi_redact(substr($res['body'], 0, 200)), __FILE__, __LINE__);
            return array('status' => 'parse_error',
                         'message' => __('Réponse du service illisible. Voir les logs.', __FILE__));
        }

        if (count($data['points']) === 0) {
            // Données J-1 pas encore publiées par Enedis : c'est un cas NORMAL le matin.
            $this->learnSlotFloor(false);
            return $this->rescheduleAfterEmpty($_ctx);
        }

        $this->learnSlotFloor(true);

        $last  = end($data['points']);
        $kwh   = jeeconsoapi_to_kwh($last['value'], $data['unit']);
        $stamp = $_target . ' 23:59:00';

        $this->checkAndUpdateCmd('daily_consumption', $kwh, $stamp);
        $this->checkAndUpdateCmd('data_date', $_target);

        jeeconsoapi_log('info', $_ctx,
            'Consommation du ' . $_target . ' : ' . $kwh . ' kWh (source : '
            . $last['value'] . ' ' . ($data['unit'] ?: 'Wh') . ')', __FILE__, __LINE__);

        /* --- 2. Puissance maximale quotidienne --- */
        $resPower = jeeconsoapi_http_get(self::TYPE_MAX_POWER, $prm, $token, $_target, $endBound);

        if ($resPower['code'] === 200) {
            $dataPower = jeeconsoapi_extract($resPower['json']);
            if ($dataPower !== null && count($dataPower['points']) > 0) {
                $lastPower = end($dataPower['points']);
                $unitPower = $dataPower['unit'] ?: 'VA';
                if (strtoupper(trim($unitPower)) !== 'VA') {
                    jeeconsoapi_log('warning', $_ctx,
                        'Puissance max retournée en « ' . $unitPower . " » et non en VA — "
                        . 'valeur stockée telle quelle, unité de la commande à vérifier', __FILE__, __LINE__);
                }
                $this->checkAndUpdateCmd('max_power', round((float) $lastPower['value'], 0), $stamp);
                jeeconsoapi_log('info', $_ctx,
                    'Puissance max du ' . $_target . ' : ' . round((float) $lastPower['value'], 0)
                    . ' ' . $unitPower, __FILE__, __LINE__);
            } else {
                jeeconsoapi_log('info', $_ctx,
                    'Puissance max non disponible pour le ' . $_target . ' (aucun point)', __FILE__, __LINE__);
            }
        } else {
            // Non bloquant : la consommation est déjà acquise, on ne perd pas la journée.
            jeeconsoapi_log('warning', $_ctx,
                'Puissance max : HTTP ' . $resPower['code'] . ' — consommation quotidienne conservée',
                __FILE__, __LINE__);
        }

        /* --- Succès : plus aucun appel jusqu'à demain --- */
        $this->checkAndUpdateCmd('last_update', date('Y-m-d H:i:s'));
        $this->setConfiguration('state_done_date', $_target);
        $this->setConfiguration('state_last_error', '');
        $this->saveState();

        jeeconsoapi_log('info', $_ctx,
            'Cycle terminé avec succès — prochain appel demain', __FILE__, __LINE__);

        return array('status' => 'ok', 'date' => $_target, 'consumption' => $kwh);
    }

    /* ---------------------------------------------------------------
       Codes HTTP non-200

       400 → requête invalide, ne JAMAIS réessayer tel quel
       401 → token invalide ou n'autorisant pas ce PRM → erreur visible
       500 → panne côté Enedis/Conso API → nouvel essai différé, silencieux
    --------------------------------------------------------------- */
    public function handleHttpError($_res, $_ctx, $_type) {
        $code = (int) $_res['code'];
        $api  = jeeconsoapi_api_error($_res['json']);
        $hint = '';
        if ($api['code'] !== '' || $api['text'] !== '') {
            $hint = ' — ' . trim(($api['code'] !== '' ? '[' . $api['code'] . '] ' : '') . $api['text']);
        }

        switch ($code) {
            case 401:
                $msg = __("Token refusé (401) : il est invalide, expiré, ou n'autorise pas ce PRM. "
                        . "Régénérez-le sur conso.boris.sh puis ressaisissez-le.", __FILE__);
                if ($api['text'] !== '') { $msg .= ' — ' . $api['text']; }
                jeeconsoapi_log('error', $_ctx, $msg . $hint, __FILE__, __LINE__);
                $this->setConfiguration('state_last_error', '401 ' . __('token refusé', __FILE__));
                // Aucun nouvel essai aujourd'hui : réessayer ne corrigera rien.
                $this->setConfiguration('state_next_ts', strtotime('tomorrow') + 6 * 3600);
                $this->saveState();
                return array('status' => 'auth_error', 'code' => 401, 'message' => $msg);

            case 400:
                /* Un 400 de Conso API est le plus souvent un refus ENEDIS relayé, pas une
                   requête malformée : le message utile vient de l'API amont. Le taire et
                   afficher « vérifiez le PRM » envoie l'utilisateur sur une fausse piste
                   quand son PRM est parfaitement correct. */
                $advice = jeeconsoapi_enedis_hint($api['code']);
                if ($api['text'] !== '') {
                    $msg = sprintf(__('Requête refusée (400)%1$s : %2$s', __FILE__),
                                   ($api['code'] !== '' ? ' [' . $api['code'] . ']' : ''),
                                   $api['text']);
                    if ($advice !== '') { $msg .= ' ' . $advice; }
                } else {
                    $msg = __('Requête refusée (400) : paramètres invalides. Vérifiez le PRM.', __FILE__);
                }
                jeeconsoapi_log('error', $_ctx, $msg . $hint, __FILE__, __LINE__);
                $this->setConfiguration('state_last_error', '400 ' . __('requête invalide', __FILE__));
                $this->setConfiguration('state_next_ts', strtotime('tomorrow') + 6 * 3600);
                $this->saveState();
                return array('status' => 'bad_request', 'code' => 400, 'message' => $msg);

            case 403:
            case 429:
                // 429 = « tu m'en demandes trop », 403 = accès refusé. Réessayer le
                // jour même serait exactement le contraire de ce que le service
                // demande. On s'aligne donc sur 400/401 : abandon jusqu'à demain.
                $msg = ($code === 429)
                     ? __('Trop de requêtes (429) : le service demande de lever le pied. '
                        . 'Aucun nouvel essai avant demain.', __FILE__)
                     : __('Accès refusé (403). Aucun nouvel essai avant demain.', __FILE__);
                jeeconsoapi_log('warning', $_ctx, $msg . $hint, __FILE__, __LINE__);
                $this->setConfiguration('state_last_error', 'HTTP ' . $code);

                /* Si le service indique lui-même quand revenir, on le suit :
                   c'est le seul signal fiable de saturation réelle, et le
                   respecter est ce qui évite que tout le parc revienne
                   frapper au même moment. Sinon, abandon jusqu'à demain. */
                $ra = jeeconsoapi_retry_after_ts($_res['retry_after']);
                if ($ra !== null) {
                    // Jitter : sans lui, tous les clients ayant reçu le même
                    // Retry-After reviendraient à la seconde près ensemble.
                    $ra += mt_rand(0, 1800);
                    jeeconsoapi_log('info', $_ctx,
                        'Retry-After respecté : nouvel essai le ' . date('Y-m-d H:i', $ra),
                        __FILE__, __LINE__);
                }
                $this->setConfiguration('state_next_ts',
                    ($ra !== null) ? $ra : strtotime('tomorrow') + 6 * 3600);
                $this->saveState();
                return array('status' => 'rate_limited', 'code' => $code, 'message' => $msg);

            default:
                // 500 et tout le reste (timeout réseau, code 0) : réessai différé silencieux.
                $next = time() + 3600 + mt_rand(0, 900);
                jeeconsoapi_log('info', $_ctx,
                    'HTTP ' . $code . ' sur ' . $_type . $hint
                    . ($_res['error'] !== '' ? ' (' . $_res['error'] . ')' : '')
                    . ' — nouvel essai vers ' . date('H:i', $next), __FILE__, __LINE__);
                $this->setConfiguration('state_last_error', 'HTTP ' . $code);
                $this->setConfiguration('state_next_ts', $next);
                $this->saveState();
                return array('status' => 'retry_later', 'code' => $code,
                             'message' => __('Service momentanément indisponible, nouvel essai programmé.', __FILE__));
        }
    }

    /* ---------------------------------------------------------------
       200 mais aucun point : les données J-1 ne sont pas encore publiées
    --------------------------------------------------------------- */
    public function rescheduleAfterEmpty($_ctx) {
        $hour       = (int) date('G');
        $morningEnd = jeeconsoapi_cfg('morning_end', 10, 1, 23);
        $afternoon  = jeeconsoapi_cfg('afternoon_retry', 14, 10, 23);

        if ($hour < $morningEnd) {
            $next   = time() + 45 * 60 + mt_rand(0, 900);   // ~45 à 60 min
            $reason = __('nouvel essai dans la matinée', __FILE__);
        } elseif ($hour < $afternoon) {
            /* Étalement large et non 30 minutes : un retard de publication chez
               Enedis est un événement CORRÉLÉ — il frappe tout le parc installé
               le même matin. Si tout le monde retente dans la même demi-heure,
               on fabrique exactement la pointe qu'on cherche à éviter. */
            $spread = jeeconsoapi_cfg('afternoon_spread', 3, 1, 8) * 3600;
            $next   = mktime($afternoon, 0, 0) + mt_rand(0, $spread - 1);
            $reason = __("nouvel essai dans l'après-midi", __FILE__);
        } else {
            $next   = strtotime('tomorrow') + 6 * 3600;
            $reason = __("toujours indisponible, abandon jusqu'à demain", __FILE__);
        }

        $this->setConfiguration('state_next_ts', $next);
        $this->saveState();

        jeeconsoapi_log('info', $_ctx,
            'Données de la veille pas encore publiées par Enedis — ' . $reason
            . ' (' . date('Y-m-d H:i', $next) . ')', __FILE__, __LINE__);

        return array('status'  => 'no_data_yet',
                     'next'    => date('Y-m-d H:i:s', $next),
                     'message' => __('Données de la veille pas encore publiées par Enedis. ', __FILE__) . $reason . '.');
    }

    /* ---------------------------------------------------------------
       Persistance de l'état

       parent::save(true) : $_direct = true court-circuite preSave/postSave
       (cf. DB::save), donc on n'enchaîne pas une recréation de commandes
       à chaque écriture d'état.
    --------------------------------------------------------------- */
    public function saveState() {
        parent::save(true);
    }

    /* ---------------------------------------------------------------
       Test de connexion (bouton UI)

       Consomme une requête. Fenêtre volontairement courte (2 jours) pour
       rester léger. Ne retourne JAMAIS le token.
    --------------------------------------------------------------- */
    public function testConnection() {
        $ctx   = $this->getName() . '#' . $this->getId() . ':test';
        $prm   = trim((string) $this->getConfiguration('prm', ''));
        $token = jeeconsoapi_token($this);

        if (!preg_match('/^\d{14}$/', $prm)) {
            throw new Exception(__('PRM absent ou mal formé : 14 chiffres attendus.', __FILE__));
        }
        if ($token === '') {
            throw new Exception(__('Token Bearer absent, vide ou indéchiffrable. Ressaisissez-le puis sauvegardez.', __FILE__));
        }

        $denied = $this->consumeManualQuota();
        if ($denied !== null) {
            throw new Exception($denied['message']);
        }

        $start = date('Y-m-d', strtotime('-2 day'));
        $end   = date('Y-m-d');

        jeeconsoapi_log('info', $ctx,
            'Test de connexion — PRM ' . jeeconsoapi_mask_prm($prm)
            . ', token ' . jeeconsoapi_mask($token), __FILE__, __LINE__);

        $res  = jeeconsoapi_http_get(self::TYPE_DAILY, $prm, $token, $start, $end);
        $data = jeeconsoapi_extract($res['json']);
        $api  = jeeconsoapi_api_error($res['json']);

        $result = array(
            'code'     => $res['code'],
            'points'   => ($data !== null) ? count($data['points']) : 0,
            'unit'     => ($data !== null) ? $data['unit'] : '',
            'envelope' => (is_array($res['json']) && isset($res['json']['meter_reading']))
                          ? 'meter_reading' : 'plat',
        );

        if ($res['code'] === 200) {
            $result['ok'] = true;
            $result['message'] = sprintf(
                __('Connexion réussie : %1$s point(s) reçu(s), unité « %2$s ».', __FILE__),
                $result['points'], $result['unit'] ?: '?');
        } elseif ($res['code'] === 401) {
            $result['ok'] = false;
            $result['message'] = __("Token refusé (401) : invalide, expiré, ou n'autorisant pas ce PRM.", __FILE__);
        } elseif ($res['code'] === 400) {
            $result['ok'] = false;
            /* Remonter le diagnostic Enedis tel quel : c'est lui qui dit à
               l'utilisateur quoi corriger. Un « vérifiez le PRM » générique
               l'enverrait sur une fausse piste quand son PRM est correct. */
            $advice = jeeconsoapi_enedis_hint($api['code']);
            if ($api['text'] !== '') {
                $result['message'] = sprintf(__('Requête refusée (400)%1$s : %2$s', __FILE__),
                                             ($api['code'] !== '' ? ' [' . $api['code'] . ']' : ''),
                                             $api['text'])
                                   . ($advice !== '' ? ' ' . $advice : '');
            } else {
                $result['message'] = __('Requête refusée (400) : vérifiez le PRM.', __FILE__);
            }
        } else {
            $result['ok'] = false;
            $result['message'] = sprintf(__('Échec : HTTP %s.', __FILE__), $res['code'])
                               . ($api['text'] !== '' ? ' — ' . $api['text'] : '');
        }
        $result['api_code'] = $api['code'];

        jeeconsoapi_log('info', $ctx,
            'Résultat du test : HTTP ' . $res['code'] . ', ' . $result['points']
            . ' point(s), enveloppe « ' . $result['envelope'] . ' »', __FILE__, __LINE__);

        return $result;
    }

    /* ---------------------------------------------------------------
       Import de l'historique passé (bouton UI, jamais automatique)

       UNE requête par type sur toute la plage demandée, puis écriture
       directe dans l'historique Jeedom via cmd::addHistoryValue(), qui
       n'a aucun effet de bord (contrairement à cmd::event(), qui
       déclencherait scenario::check() sur chacun des ~1100 points).
    --------------------------------------------------------------- */
    public function backfillHistory($_months) {
        $ctx    = $this->getName() . '#' . $this->getId() . ':backfill';
        $months = max(1, min(36, (int) $_months));

        $prm   = trim((string) $this->getConfiguration('prm', ''));
        $token = jeeconsoapi_token($this);

        if (!preg_match('/^\d{14}$/', $prm)) {
            throw new Exception(__('PRM absent ou mal formé : 14 chiffres attendus.', __FILE__));
        }
        if ($token === '') {
            throw new Exception(__('Token Bearer absent, vide ou indéchiffrable. Ressaisissez-le puis sauvegardez.', __FILE__));
        }

        $denied = $this->consumeManualQuota();
        if ($denied !== null) {
            throw new Exception($denied['message']);
        }

        $start = date('Y-m-d', strtotime('-' . $months . ' months'));
        $end   = date('Y-m-d'); // borne exclusive : s'arrête donc à la veille

        jeeconsoapi_log('info', $ctx,
            'Import de l\'historique du ' . $start . ' au ' . $end . ' (exclu)', __FILE__, __LINE__);

        $report = array('months' => $months, 'start' => $start, 'end' => $end,
                        'consumption' => 0, 'max_power' => 0, 'skipped' => 0, 'warnings' => array());

        /* --- Consommation quotidienne --- */
        $res = jeeconsoapi_http_get(self::TYPE_DAILY, $prm, $token, $start, $end);
        if ($res['code'] !== 200) {
            throw new Exception(sprintf(
                __('Import interrompu : le service a répondu HTTP %s sur la consommation quotidienne.', __FILE__),
                $res['code']));
        }
        $data = jeeconsoapi_extract($res['json']);
        if ($data === null) {
            throw new Exception(__('Import interrompu : réponse du service illisible.', __FILE__));
        }

        $cmdConso = $this->getCmd('info', 'daily_consumption');
        if (is_object($cmdConso)) {
            foreach ($data['points'] as $point) {
                $datetime = substr($point['date'], 0, 10) . ' 23:59:00';
                if (self::historyExists($cmdConso->getId(), $datetime)) {
                    $report['skipped']++;
                    continue;
                }
                $cmdConso->addHistoryValue(jeeconsoapi_to_kwh($point['value'], $data['unit']), $datetime);
                $report['consumption']++;
            }
        } else {
            $report['warnings'][] = __('Commande « Consommation quotidienne » introuvable — sauvegardez l\'équipement.', __FILE__);
        }

        /* --- Puissance maximale quotidienne --- */
        $resPower = jeeconsoapi_http_get(self::TYPE_MAX_POWER, $prm, $token, $start, $end);
        if ($resPower['code'] === 200) {
            $dataPower = jeeconsoapi_extract($resPower['json']);
            $cmdPower  = $this->getCmd('info', 'max_power');
            if ($dataPower !== null && is_object($cmdPower)) {
                foreach ($dataPower['points'] as $point) {
                    $datetime = substr($point['date'], 0, 10) . ' 23:59:00';
                    if (self::historyExists($cmdPower->getId(), $datetime)) {
                        $report['skipped']++;
                        continue;
                    }
                    $cmdPower->addHistoryValue(round((float) $point['value'], 0), $datetime);
                    $report['max_power']++;
                }
            }
        } else {
            $report['warnings'][] = sprintf(
                __('Puissance maximale non importée : HTTP %s.', __FILE__), $resPower['code']);
        }

        jeeconsoapi_log('info', $ctx,
            'Import terminé — ' . $report['consumption'] . ' point(s) de consommation, '
            . $report['max_power'] . ' point(s) de puissance max, '
            . $report['skipped'] . ' déjà présent(s)', __FILE__, __LINE__);

        return $report;
    }

    /* ---------------------------------------------------------------
       Idempotence de l'import : le point existe-t-il déjà ?
    --------------------------------------------------------------- */
    public static function historyExists($_cmdId, $_datetime) {
        $existing = history::byCmdIdDatetime($_cmdId, $_datetime);
        return is_object($existing);
    }

    /* ---------------------------------------------------------------
       État de planification, pour l'encart de la page de configuration
    --------------------------------------------------------------- */
    public function getScheduleInfo() {
        $nextTs = (int) $this->getConfiguration('state_next_ts', 0);
        $manual = ((string) $this->getConfiguration('state_manual_date', '') === date('Y-m-d'))
                ? (int) $this->getConfiguration('state_manual_attempts', 0)
                : 0;
        return array(
            'done_date'   => (string) $this->getConfiguration('state_done_date', ''),
            'next'        => ($nextTs > 0) ? date('Y-m-d H:i:s', $nextTs) : '',
            'attempts'    => (int) $this->getConfiguration('state_attempts', 0),
            'max'         => jeeconsoapi_cfg('max_attempts', 3, 1, 5),
            'manual'      => $manual,
            'manual_max'  => self::MANUAL_DAILY_CAP,
            'last_error'  => (string) $this->getConfiguration('state_last_error', ''),
            'has_token'   => ($this->getConfiguration('token', '') !== ''),
        );
    }

    /* ---------------------------------------------------------------
       Cycle de vie
    --------------------------------------------------------------- */
    public function preSave() {
        // --- PRM : on ne garde que les chiffres, puis on valide strictement
        $prm = preg_replace('/\D/', '', (string) $this->getConfiguration('prm', ''));
        $this->setConfiguration('prm', $prm);
        if ($prm !== '' && !preg_match('/^\d{14}$/', $prm)) {
            throw new Exception(__('Le PRM doit comporter exactement 14 chiffres.', __FILE__));
        }

        // --- Token : un champ laissé vide signifie « ne pas changer »
        $submitted = trim((string) $this->getConfiguration('token', ''));
        if ($submitted === '') {
            $old = ($this->getId() != '') ? eqLogic::byId($this->getId()) : null;
            $this->setConfiguration('token', is_object($old) ? $old->getConfiguration('token', '') : '');
        } elseif (strpos($submitted, 'crypt:') !== 0) {
            // Nouveau token saisi en clair → chiffré au repos (AES-256-CBC + HMAC)
            $this->setConfiguration('token', utils::encrypt($submitted));
        }
    }

    public function postSave() {
        $this->createCommands();
    }

    /* ---------------------------------------------------------------
       Création des commandes — jamais de recréation si elles existent
    --------------------------------------------------------------- */
    public function createCommands() {
        $cmds = array(
            // logicalId          => nom, type, sousType, unité, historisé, visible, generic_type
            'daily_consumption'   => array(__('Consommation quotidienne', __FILE__),      'info',   'numeric', 'kWh', 1, 1, 'DAILY_CONSUMPTION'),
            'max_power'           => array(__('Puissance maximale quotidienne', __FILE__), 'info',   'numeric', 'VA',  1, 1, 'POWER'),
            'data_date'           => array(__('Date des données', __FILE__),               'info',   'string',  '',    0, 1, ''),
            'last_update'         => array(__('Dernière mise à jour', __FILE__),           'info',   'string',  '',    0, 1, ''),
            'refresh'             => array(__('Actualiser maintenant', __FILE__),          'action', 'other',   '',    0, 1, ''),
        );

        foreach ($cmds as $logicalId => $def) {
            list($name, $type, $subType, $unit, $historized, $visible, $generic) = $def;

            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new jeeconsoapiCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($name);
            }
            $cmd->setType($type);
            $cmd->setSubType($subType);
            if ($unit !== '') {
                $cmd->setUnite($unit);
            }
            if ($generic !== '') {
                $cmd->setGeneric_type($generic);
            }
            $cmd->setIsHistorized($historized);
            $cmd->setIsVisible($visible);
            $cmd->save();
        }
    }

    /* ---------------------------------------------------------------
       Widget dashboard
    --------------------------------------------------------------- */
    public function toHtml($_version = 'dashboard') {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);

        $replace['#eqLogic_id#']   = $this->getId();
        $replace['#eqLogic_name#'] = $this->getName();

        $conso = $this->getCmd('info', 'daily_consumption');
        $power = $this->getCmd('info', 'max_power');
        $date  = $this->getCmd('info', 'data_date');

        $replace['#consumption#']    = is_object($conso) ? $conso->execCmd() : '';
        $replace['#consumption_id#'] = is_object($conso) ? $conso->getId() : '';
        $replace['#max_power#']      = is_object($power) ? $power->execCmd() : '';
        $replace['#max_power_id#']   = is_object($power) ? $power->getId() : '';
        $replace['#data_date#']      = is_object($date)  ? $date->execCmd() : '';

        if ($replace['#consumption#'] === '' || $replace['#consumption#'] === null) {
            $replace['#consumption#'] = '--';
        }
        if ($replace['#max_power#'] === '' || $replace['#max_power#'] === null) {
            $replace['#max_power#'] = '--';
        }
        if ($replace['#data_date#'] === '' || $replace['#data_date#'] === null) {
            $replace['#data_date#'] = __('en attente de données', __FILE__);
        }

        return $this->postToHtml($_version,
            template_replace($replace, getTemplate('core', $version, 'jeeconsoapi', 'jeeconsoapi')));
    }
}


/* ===================================================================
   Classe commandes
=================================================================== */
class jeeconsoapiCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return null;
        }

        if ($this->getLogicalId() === 'refresh') {
            // Appel hors planification : il consomme le quota du jour.
            jeeconsoapi_log('warning', $eqLogic->getName() . '#' . $eqLogic->getId(),
                'Actualisation manuelle demandée — cet appel consomme une des tentatives '
                . 'quotidiennes autorisées pour ce compteur', __FILE__, __LINE__);
            set_time_limit(90);
            $eqLogic->runCycle(true);
            return null;
        }

        jeeconsoapi_log('warning', $eqLogic->getName() . '#' . $eqLogic->getId(),
            'Commande inconnue : ' . $this->getLogicalId(), __FILE__, __LINE__);
        return null;
    }
}
